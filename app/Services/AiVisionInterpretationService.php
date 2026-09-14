<?php

namespace App\Services;

use App\Contracts\Ai\VisionInterpretationProvider;
use App\Exceptions\AiProviderGateException;
use App\Exceptions\AiVisionInterpretationException;
use App\Exceptions\MediaReadException;
use App\Services\Ai\VisionInterpretationAdapter;
use App\Support\Ai\ProviderGateRequest;
use App\Support\Ai\VisionImageInput;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AI Vision Interpretation of Media document regions — ADR-0020,
 * database/ai/ai_vision_interpretations.md v1.1.
 *
 * Media observes, AI interprets. This service reads Media only through
 * MediaReadService and writes only `ai_vision_interpretations`; no `media_*`
 * table is ever written from here (ADR-0020 D1).
 *
 * Three rules shape the flow:
 *
 *  1. A row exists only for a `completed` run. It is written after the gate's
 *     execute() returns allowed, never before, so there is no `pending` or
 *     `failed` interpretation to reconcile (doc v1.1 F5).
 *  2. The anchor is copied exactly from the Media Read unit, with `page` taken
 *     from the page selector Media Read was asked with — Media Read does not
 *     return a region's page, and reading `media_extracted_regions` directly
 *     would bypass it.
 *  3. One `ready` row per unit slot (F6): stale the current one, then insert,
 *     in one transaction; the unique `active_slot` is the final arbiter and a
 *     violation is retried exactly once.
 */
final class AiVisionInterpretationService
{
    private const OWNER_TYPES = ['course_activity', 'course_version_activity'];

    public function __construct(
        private readonly AiProviderExecutionGate $gate,
        private readonly VisionInterpretationProvider $provider,
        private readonly MediaReadService $mediaRead,
        private readonly MediaDerivedRetrievalAudit $audit,
        private readonly MediaService $media,
    ) {}

    /**
     * Interpret one document region.
     *
     * Returns an outcome instead of throwing for every expected refusal, so a
     * caller can always tell "blocked", "failed" and "nothing to do" apart.
     *
     * @return array<string,mixed>
     */
    public function interpret(
        int $actorId,
        string $ownerType,
        int $ownerId,
        int $page,
        string $locator,
        ?string $locale = null,
        bool $refresh = false,
    ): array {
        $customerId = TenantContext::customerId()
            ?? throw new AiVisionInterpretationException('unauthorized');

        $provider = (string) config('ai.vision.provider', '');
        $model = (string) config('ai.vision.model', '');

        // Stop before Media Read and before the gate: an unconfigured deployment
        // neither reads crops nor mints runs.
        if ($provider === '' || $model === '') {
            return $this->outcome('LF_VISION_NOT_CONFIGURED');
        }
        if (! in_array($ownerType, self::OWNER_TYPES, true)) {
            return $this->outcome('LF_VISION_UNSUPPORTED_OWNER');
        }
        if ($page < 1) {
            return $this->outcome('LF_VISION_INVALID_PAGE');
        }

        try {
            // `page` is a Media Read selector: the regions returned are exactly
            // those on this page. `includeCrop` asks Media Read to sign the crop;
            // Media Read audits this read itself.
            $units = $this->mediaRead->read(
                $actorId, $ownerType, $ownerId, 'document', 'region', $locale,
                null, null, 'ai', ['operation' => 'vision_interpretation'], $page, true,
            );
        } catch (MediaReadException $exception) {
            return $this->outcome($exception->errorCode);
        }

        $unit = null;
        foreach ($units as $candidate) {
            if (($candidate['locator']['type'] ?? null) === 'region' && ($candidate['locator']['value'] ?? null) === $locator) {
                $unit = $candidate;
                break;
            }
        }
        if ($unit === null) {
            return $this->outcome('LF_VISION_REGION_NOT_FOUND');
        }

        $crop = $unit['structure']['crop'] ?? null;
        if (! is_array($crop) || ! is_string($crop['delivery_url'] ?? null) || $crop['delivery_url'] === '') {
            return $this->outcome('LF_VISION_IMAGE_UNAVAILABLE');
        }

        $anchor = $this->anchor($ownerType, $ownerId, $page, $unit);
        if ($anchor === null) {
            // Refuse before paying: a row the schema would reject after the
            // provider call is quota spent on nothing.
            return $this->outcome('LF_VISION_ANCHOR_INVALID');
        }

        if (! $refresh) {
            $existing = $this->readyForSlot($customerId, $anchor, $model);
            if ($existing !== null) {
                return $this->outcome(null, [
                    'interpretation_uuid' => $existing->interpretation_uuid,
                    'run_uuid' => $existing->run_uuid,
                    'reused' => true,
                ]);
            }
        }

        $request = new ProviderGateRequest(
            provider: $provider,
            model: $model,
            purpose: 'vision_interpretation',
            dataClasses: (array) config('ai.vision.data_classes', []),
            executionRegion: (string) config('ai.vision.execution_region'),
            retentionClass: (string) config('ai.vision.retention_class'),
            correlationId: Str::uuid()->toString(),
            userId: $actorId,
            quotaQuantity: 1.0,
            quotaUnit: 'call',
            usageType: 'provider_call',
        );

        $decision = $this->gate->authorize($request);
        if (! $decision->allowed) {
            return $this->outcome($decision->errorCode, ['blocked_at' => $decision->blockedStep]);
        }

        $execution = $decision->execution;
        $adapter = new VisionInterpretationAdapter(
            $this->provider,
            new VisionImageInput(
                $crop['delivery_url'],
                (int) ($crop['width'] ?? 0),
                (int) ($crop['height'] ?? 0),
                (int) ($crop['bytes'] ?? 0),
                $unit['structure']['role'] ?? null,
                $unit['locale'] ?? null,
            ),
            max(1, (int) config('ai.vision.max_interpretation_chars', 20000)),
        );

        try {
            $executed = $this->gate->execute($request, static fn (): VisionInterpretationAdapter => $adapter, $execution);
        } catch (AiProviderGateException $exception) {
            return $this->outcome($exception->errorCode);
        }

        // execute() authorizes again before it claims the run, and a refusal
        // there is RETURNED, not thrown. Reading it as success is the defect
        // already fixed once in the embedding worker; nothing is written here.
        if (! $executed->allowed) {
            return $this->outcome($executed->errorCode, ['blocked_at' => $executed->blockedStep]);
        }

        $text = $adapter->interpretation();
        if ($text === null) {
            return $this->outcome('LF_VISION_OUTPUT_MISSING');
        }

        $uuid = $this->store($customerId, $anchor, $execution->modelRunId, $text, [
            'output_format' => 'text',
            'output_validator' => 'vision-text-v1',
            'region_role' => $unit['structure']['role'] ?? null,
            'crop' => ['width' => (int) ($crop['width'] ?? 0), 'height' => (int) ($crop['height'] ?? 0), 'bytes' => (int) ($crop['bytes'] ?? 0)],
        ]);

        // The Media File may have been deleted while the provider was answering.
        // Its deletion event could have run before this row existed and found
        // nothing to erase. Checking the tombstone after the insert committed
        // closes that window: either this read sees `deleted`, or the deletion
        // committed later and its listener sees this row.
        if ($this->media->deletedMediaFileIds([(int) $anchor['media_file_id']]) !== []) {
            $this->purgeForDeletedMediaFile((int) $anchor['media_file_id']);

            return $this->outcome('LF_VISION_MEDIA_DELETED', ['run_uuid' => $execution->runUuid]);
        }

        return $this->outcome(null, [
            'interpretation_uuid' => $uuid,
            'run_uuid' => $execution->runUuid,
            'reused' => false,
        ]);
    }

    /**
     * Current interpretations of one owner's document regions, revalidated.
     *
     * Every row must still pass Media Read for this actor — owner authorization,
     * an active and unambiguous usage bound to the same file, Media not deleted,
     * and the current revision equal to the row's anchor. Passing owner
     * authorization alone is not enough. Each decision is audited through the
     * Media-owned service; an audit failure aborts before anything is returned.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forOwner(int $actorId, string $ownerType, int $ownerId): array
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiVisionInterpretationException('unauthorized');

        $rows = DB::table('ai_vision_interpretations as i')
            ->join('ai_model_runs as r', function ($join): void {
                $join->on('r.id', '=', 'i.model_run_id')->on('r.customer_id', '=', 'i.customer_id');
            })
            ->where('i.customer_id', $customerId)
            ->where('i.source_type', $ownerType)
            ->where('i.source_id', $ownerId)
            ->where('i.status', 'ready')
            ->orderBy('i.page')->orderBy('i.id')
            ->get([
                'i.*', 'r.run_uuid', 'r.provider as run_provider', 'r.model as run_model',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $retrievalUuid = Str::uuid()->toString();
        $revisionByLocale = [];
        $allowed = [];

        foreach ($rows as $row) {
            $localeKey = $row->locale ?? "\0";
            if (! array_key_exists($localeKey, $revisionByLocale)) {
                try {
                    $revisionByLocale[$localeKey] = $this->mediaRead->currentRevision(
                        $actorId, $ownerType, $ownerId, 'document', 'region', $row->locale,
                    );
                } catch (MediaReadException $exception) {
                    $revisionByLocale[$localeKey] = $exception->errorCode;
                }
            }

            $revisions = $revisionByLocale[$localeKey];
            $denial = is_string($revisions) ? $revisions : $this->revisionDenial($row, $revisions);

            if ($denial !== null) {
                $this->audit->appendVisionInterpretation($actorId, $row, 'denied', $retrievalUuid, $denial);

                continue;
            }

            $allowed[] = $row;
        }

        // Audit `allowed` only for what is actually returned, and before it is
        // returned: if evidence cannot be persisted, nothing is disclosed.
        $results = [];
        foreach ($allowed as $row) {
            $this->audit->appendVisionInterpretation($actorId, $row, 'allowed', $retrievalUuid);
            $results[] = [
                'interpretation_uuid' => $row->interpretation_uuid,
                'interpretation' => $row->interpretation,
                'run_uuid' => $row->run_uuid,
                'provider' => $row->run_provider,
                'model' => $row->run_model,
                'media_file_id' => (int) $row->media_file_id,
                'source_fingerprint' => $row->source_fingerprint,
                'processing_version' => $row->processing_version,
                'locale' => $row->locale,
                'locator' => ['type' => $row->locator_type, 'start' => $row->locator_start],
                'page' => (int) $row->page,
                'bbox' => $row->bbox_x === null ? null : [
                    'x' => (float) $row->bbox_x, 'y' => (float) $row->bbox_y,
                    'width' => (float) $row->bbox_width, 'height' => (float) $row->bbox_height,
                ],
            ];
        }

        return $results;
    }

    /**
     * Queue every interpretation built from one Media File for deletion
     * (ADR-0020 D5). Idempotent: only `ready` and `stale` enter
     * `deletion_pending`, named rather than "everything but deleted", so a
     * repeated request keeps the original `deletion_requested_at`.
     *
     * Reached from purgeForDeletedMediaFile(), which the MediaFileDeleted
     * listener and the reconciliation command call; Media never writes this table.
     */
    public function requestDeletionForMediaFile(int $mediaFileId): int
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiVisionInterpretationException('unauthorized');

        $now = now();

        return DB::table('ai_vision_interpretations')
            ->where('customer_id', $customerId)
            ->where('media_file_id', $mediaFileId)
            ->whereIn('status', ['ready', 'stale'])
            ->update(['status' => 'deletion_pending', 'deletion_requested_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Tombstone rows awaiting deletion: erase the interpretation text, keep the
     * provenance and hash. There is no remote copy to wait for.
     */
    public function finalizeDeletion(int $limit = 100, ?int $mediaFileId = null): int
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiVisionInterpretationException('unauthorized');

        $ids = DB::table('ai_vision_interpretations')
            ->where('customer_id', $customerId)
            ->when($mediaFileId !== null, fn ($query) => $query->where('media_file_id', $mediaFileId))
            ->where('status', 'deletion_pending')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $now = now();

        return DB::table('ai_vision_interpretations')
            ->where('customer_id', $customerId)
            ->whereIn('id', $ids)
            ->where('status', 'deletion_pending')
            ->update(['status' => 'deleted', 'interpretation' => null, 'deleted_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Erase every interpretation of a Media File that Media has deleted.
     *
     * Media erases its own derived content in the deletion transaction, so the
     * AI copy is erased now too rather than left for a later window. Fail-closed
     * against a spurious caller: nothing happens unless MediaService confirms
     * the tombstone for this tenant. Idempotent.
     *
     * @return int rows tombstoned by this call
     */
    public function purgeForDeletedMediaFile(int $mediaFileId): int
    {
        if ($this->media->deletedMediaFileIds([$mediaFileId]) === []) {
            return 0;
        }

        $this->requestDeletionForMediaFile($mediaFileId);

        $deleted = 0;
        do {
            $batch = $this->finalizeDeletion(500, $mediaFileId);
            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }

    /**
     * Backstop for a missed or failed deletion event: purge interpretations of
     * Media Files that are already deleted, then finish every row left in
     * `deletion_pending`.
     *
     * Walks all candidate Media Files by key instead of taking the first N, so
     * a long run of files that still exist cannot starve a deleted one.
     *
     * @return array{media_files:int,deleted:int}
     */
    public function reconcileDeletedMedia(int $chunk = 500): array
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiVisionInterpretationException('unauthorized');

        $mediaFiles = 0;
        $deleted = 0;
        $after = 0;
        do {
            $candidates = DB::table('ai_vision_interpretations')
                ->where('customer_id', $customerId)
                ->whereIn('status', ['ready', 'stale'])
                ->where('media_file_id', '>', $after)
                ->distinct()
                ->orderBy('media_file_id')
                ->limit(max(1, $chunk))
                ->pluck('media_file_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            foreach ($this->media->deletedMediaFileIds($candidates) as $mediaFileId) {
                $mediaFiles++;
                $deleted += $this->purgeForDeletedMediaFile($mediaFileId);
            }

            $after = $candidates === [] ? $after : end($candidates);
        } while (count($candidates) === max(1, $chunk));

        do {
            $batch = $this->finalizeDeletion(500);
            $deleted += $batch;
        } while ($batch > 0);

        return ['media_files' => $mediaFiles, 'deleted' => $deleted];
    }

    /**
     * The row anchor, copied from the Media Read unit. Returns null when a value
     * would not fit the schema, so the refusal happens before any provider call.
     *
     * @param  array<string,mixed>  $unit
     * @return array<string,mixed>|null
     */
    private function anchor(string $ownerType, int $ownerId, int $page, array $unit): ?array
    {
        $fingerprint = $unit['source_fingerprint'] ?? null;
        $version = $unit['processing_version'] ?? null;
        $locator = $unit['locator']['value'] ?? null;
        $bbox = $unit['structure']['bbox'] ?? null;
        $mediaFileId = $unit['media_file_id'] ?? null;

        if (! is_string($fingerprint) || strlen($fingerprint) !== 64
            || ! is_string($version) || $version === '' || mb_strlen($version) > 100
            || ! is_string($locator) || $locator === '' || mb_strlen($locator) > 50
            || ! is_int($mediaFileId)) {
            return null;
        }
        if ($bbox !== null) {
            foreach (['x', 'y', 'width', 'height'] as $key) {
                if (! is_numeric($bbox[$key] ?? null)) {
                    return null;
                }
            }
        }

        return [
            'source_type' => $ownerType,
            'source_id' => $ownerId,
            'media_file_id' => $mediaFileId,
            'usage_type' => 'document',
            'content_type' => 'region',
            'locale' => $unit['locale'] ?? null,
            'source_fingerprint' => $fingerprint,
            'processing_version' => $version,
            'locator_type' => 'region',
            'locator_start' => $locator,
            'page' => $page,
            'bbox_x' => $bbox === null ? null : (float) $bbox['x'],
            'bbox_y' => $bbox === null ? null : (float) $bbox['y'],
            'bbox_width' => $bbox === null ? null : (float) $bbox['width'],
            'bbox_height' => $bbox === null ? null : (float) $bbox['height'],
        ];
    }

    /** @param array<string,mixed> $anchor */
    private function readyForSlot(int $customerId, array $anchor, string $model): ?object
    {
        return DB::table('ai_vision_interpretations as i')
            ->join('ai_model_runs as r', function ($join): void {
                $join->on('r.id', '=', 'i.model_run_id')->on('r.customer_id', '=', 'i.customer_id');
            })
            ->where('i.customer_id', $customerId)
            ->where($this->slotWhere($anchor, 'i.'))
            ->where('i.status', 'ready')
            ->where('r.model', $model)
            ->first(['i.interpretation_uuid', 'r.run_uuid']);
    }

    /**
     * Stale-then-insert under the unique slot, retried once on a unique
     * violation (a concurrent writer won between our stale and our insert).
     *
     * @param  array<string,mixed>  $anchor
     * @param  array<string,mixed>  $metadata
     */
    private function store(int $customerId, array $anchor, int $modelRunId, string $text, array $metadata): string
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($customerId, $anchor, $modelRunId, $text, $metadata): string {
                    $now = now();

                    // The slot's current `ready` row, if any, is superseded.
                    DB::table('ai_vision_interpretations')
                        ->where('customer_id', $customerId)
                        ->where($this->slotWhere($anchor))
                        ->where('status', 'ready')
                        ->update(['status' => 'stale', 'updated_at' => $now]);

                    // Interpreting the current revision supersedes `ready` rows
                    // of any other revision of the same owner document regions.
                    DB::table('ai_vision_interpretations')
                        ->where('customer_id', $customerId)
                        ->where('source_type', $anchor['source_type'])
                        ->where('source_id', $anchor['source_id'])
                        ->where('usage_type', $anchor['usage_type'])
                        ->where('content_type', $anchor['content_type'])
                        ->where('status', 'ready')
                        ->where(function ($other) use ($anchor): void {
                            $other->where('source_fingerprint', '<>', $anchor['source_fingerprint'])
                                ->orWhere('processing_version', '<>', $anchor['processing_version']);
                        })
                        ->update(['status' => 'stale', 'updated_at' => $now]);

                    $uuid = Str::uuid()->toString();
                    DB::table('ai_vision_interpretations')->insert($anchor + [
                        'customer_id' => $customerId,
                        'interpretation_uuid' => $uuid,
                        'model_run_id' => $modelRunId,
                        'interpretation' => $text,
                        'interpretation_hash' => hash('sha256', $text),
                        'status' => 'ready',
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    return $uuid;
                }, 1);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= 2) {
                    // The run completed and its usage is recorded; only the row
                    // could not be placed. Report it rather than hide it.
                    throw new AiVisionInterpretationException('LF_VISION_SLOT_CONFLICT');
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $anchor
     * @return \Closure(Builder):void
     */
    private function slotWhere(array $anchor, string $prefix = ''): \Closure
    {
        return static function ($query) use ($anchor, $prefix): void {
            foreach (['source_type', 'source_id', 'usage_type', 'content_type', 'source_fingerprint', 'processing_version', 'locator_type', 'locator_start'] as $column) {
                $query->where($prefix.$column, $anchor[$column]);
            }
        };
    }

    /** @param array<int,array<string,mixed>> $revisions */
    private function revisionDenial(object $row, array $revisions): ?string
    {
        $revision = count($revisions) === 1 ? $revisions[0] : null;

        return $revision !== null
            && $revision['media_file_id'] === (int) $row->media_file_id
            && $revision['locale'] === $row->locale
            && hash_equals((string) $row->source_fingerprint, (string) $revision['source_fingerprint'])
            && hash_equals((string) $row->processing_version, (string) $revision['processing_version'])
                ? null
                : 'revision_mismatch';
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function outcome(?string $errorCode, array $extra = []): array
    {
        return $extra + ['error_code' => $errorCode];
    }
}
