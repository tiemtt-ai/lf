<?php

namespace App\Services\Ai;

use App\Exceptions\CourseAuthoringContextException;
use App\Exceptions\MediaReadException;
use App\Services\CourseAuthoringContextService;
use App\Services\MediaDerivedRetrievalAudit;
use App\Services\MediaReadService;
use App\Support\Ai\AuthoringPromptContract;
use App\Support\Ai\CanonicalJson;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared Step 7 record access, integrity and freshness rules, so generation,
 * review and owner handoff apply exactly one definition of "intact", "fresh"
 * and "stale". Reads and writes only `ai_authoring_*`; Course, Learning and
 * Media are reached through their owner services.
 */
final class AuthoringProposalRecords
{
    public const SOURCE_PAIRS = [
        ['document', 'extracted_text'],
        ['audio', 'transcript'],
        ['video', 'transcript'],
        ['video', 'video_frame_text'],
    ];

    /** Per-unit and total text offered to a provider; sources beyond this are not offered. */
    public const MAX_UNIT_CHARS = 4000;

    public const MAX_TOTAL_CHARS = 60000;

    public const MAX_SOURCES = 200;

    public function __construct(
        private readonly MediaReadService $mediaRead,
        private readonly CourseAuthoringContextService $course,
        private readonly AuthoringPromptContract $prompt,
        private readonly MediaDerivedRetrievalAudit $audit,
    ) {}

    public function auditDisclosure(int $actorId, object $proposal, ?object $revision, string $operation, ?string $denial = null): void
    {
        $uuid = (string) Str::uuid();
        foreach ($this->sources($proposal) as $source) {
            $this->audit->appendAuthoring($actorId, $proposal, $revision, $source,
                $denial === null ? 'allowed' : 'denied', $uuid, $operation, $denial);
        }
    }

    public function proposal(string $proposalUuid, bool $lock = false): ?object
    {
        $customerId = TenantContext::customerId();

        return $customerId === null || ! Str::isUuid($proposalUuid) ? null : DB::table('ai_authoring_proposals')
            ->where('customer_id', $customerId)->where('proposal_uuid', strtolower($proposalUuid))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    public function lockProposal(object $proposal): ?object
    {
        return DB::table('ai_authoring_proposals')->where('customer_id', $proposal->customer_id)
            ->where('id', $proposal->id)->lockForUpdate()->first();
    }

    public function request(string $requestUuid, bool $lock = false): ?object
    {
        return DB::table('ai_authoring_generation_requests')
            ->where('customer_id', TenantContext::customerId())->where('request_uuid', strtolower($requestUuid))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    public function currentRevision(object $proposal): ?object
    {
        return DB::table('ai_authoring_proposal_revisions')->where('customer_id', $proposal->customer_id)
            ->where('proposal_id', $proposal->id)->orderByDesc('revision_no')->first();
    }

    /** @return Collection<int,object> */
    public function sources(object $proposal): Collection
    {
        return DB::table('ai_authoring_proposal_sources')->where('customer_id', $proposal->customer_id)
            ->where('proposal_id', $proposal->id)->orderBy('source_ordinal')->get();
    }

    /** Sealed, with a revision, and the seal matches the stored anchors exactly. */
    public function intact(object $proposal, Collection $sources, ?object $revision): bool
    {
        return $proposal->sources_sealed_at !== null
            && $revision !== null
            && $sources->count() === (int) $proposal->source_count
            && hash_equals((string) $proposal->source_set_hash, CanonicalJson::hash($sources->pluck('anchor_hash')->values()->all()));
    }

    /** The accept decision that made this proposal accepted, pinned to its exact revision. */
    public function acceptedRevision(object $proposal): ?object
    {
        return DB::table('ai_authoring_proposal_reviews as r')
            ->join('ai_authoring_proposal_revisions as v', function ($join): void {
                $join->on('v.id', '=', 'r.revision_id')->on('v.customer_id', '=', 'r.customer_id');
            })
            ->where('r.customer_id', $proposal->customer_id)->where('r.proposal_id', $proposal->id)
            ->where('r.action', 'accept')->orderByDesc('r.id')
            ->first(['v.id', 'v.revision_no', 'v.payload', 'v.payload_hash', 'v.erased_at']);
    }

    /**
     * Source, Course-context and prompt freshness for one proposal.
     *
     * sources: current | drift (Media answered with another revision) | denied
     * (access lost; `denied` carries Media Read's code). Access loss denies
     * content but is not recorded as staleness by itself.
     *
     * @param  array<string,mixed>  $course
     * @return array{sources:string,context:string,prompt:string,denied?:string}
     */
    public function freshness(int $actorId, object $proposal, array $course, Collection $sources): array
    {
        $result = ['sources' => 'current', 'context' => 'current', 'prompt' => 'current'];

        foreach ($sources->groupBy(fn (object $s): string => $s->usage_type.'|'.$s->content_type.'|'.($s->locale ?? '')) as $group) {
            $first = $group->first();
            try {
                $revisions = $this->mediaRead->currentRevision(
                    $actorId, 'course_activity', (int) $proposal->activity_id, $first->usage_type, $first->content_type, $first->locale,
                );
            } catch (MediaReadException $exception) {
                return ['sources' => 'denied', 'context' => $result['context'], 'prompt' => $result['prompt'], 'denied' => $exception->errorCode];
            }
            foreach ($group as $source) {
                $matches = collect($revisions)->contains(fn (array $r): bool => $r['media_file_id'] === (int) $source->media_file_id
                    && $r['locale'] === $source->locale
                    && hash_equals((string) $r['source_fingerprint'], (string) $source->source_fingerprint)
                    && hash_equals((string) $r['processing_version'], (string) $source->processing_version));
                if (! $matches) {
                    $result['sources'] = 'drift';
                }
            }
        }

        if (! hash_equals($proposal->course_context_hash, $course['course_context_hash'])) {
            $result['context'] = 'changed';
        }
        if ($proposal->creation_mode === 'generated' && $proposal->status === 'pending_review') {
            $request = $this->request($proposal->generation_request_uuid);
            if ($request === null || $request->prompt_contract_id !== $this->prompt->id()
                || (int) $request->prompt_version !== $this->prompt->version()
                || ! hash_equals((string) $request->prompt_hash, $this->prompt->hash())) {
                $result['prompt'] = 'changed';
            }
        }

        return $result;
    }

    /**
     * Pending work goes stale on source drift, context drift or (generated
     * only) prompt drift; accepted work goes stale on source drift only —
     * accepted context drift is a reconfirmation matter, not staleness.
     *
     * @param  array{sources:string,context:string,prompt:string}  $freshness
     */
    public function applyStaleness(int $actorId, object $proposal, array $freshness): ?object
    {
        $pendingStale = $proposal->status === 'pending_review'
            && ($freshness['sources'] === 'drift' || $freshness['context'] === 'changed' || $freshness['prompt'] === 'changed');
        $acceptedStale = $proposal->status === 'accepted' && $freshness['sources'] === 'drift';
        if (! $pendingStale && ! $acceptedStale) {
            return null;
        }

        try {
            DB::transaction(function () use ($actorId, $proposal): void {
                $this->course->proposalContext($actorId, (int) $proposal->activity_id, true);
                $locked = $this->lockProposal($proposal);
                if ($locked !== null && $locked->status === $proposal->status && (int) $locked->lock_version === (int) $proposal->lock_version) {
                    $this->bump($locked, 'stale', $this->now());
                }
            });
        } catch (CourseAuthoringContextException) {
            return null;
        }

        return $this->proposal($proposal->proposal_uuid);
    }

    /**
     * Authorized Media units of the Activity as anchors plus bounded text, in
     * a stable order. Returns null when Media Read refuses the actor outright.
     *
     * @return array<int,array{ordinal:int,anchor:array<string,mixed>,text:string}>|null
     */
    public function collectSources(int $actorId, int $activityId, string $operation): ?array
    {
        $units = [];
        $seen = [];
        $budget = self::MAX_TOTAL_CHARS;
        foreach (self::SOURCE_PAIRS as [$usageType, $contentType]) {
            try {
                $read = $this->mediaRead->read(
                    $actorId, 'course_activity', $activityId, $usageType, $contentType, null, null, null,
                    'ai', ['operation' => $operation],
                );
            } catch (MediaReadException $exception) {
                if ($exception->errorCode === 'unauthorized') {
                    return null;
                }

                continue;
            }
            foreach ($read as $unit) {
                $text = is_string($unit['text'] ?? null) ? trim($unit['text']) : '';
                $locator = $unit['locator'] ?? null;
                if ($text === '' || ! is_array($locator) || count($units) >= self::MAX_SOURCES || $budget <= 0) {
                    continue;
                }
                $anchor = [
                    'media_file_id' => (int) $unit['media_file_id'],
                    'usage_type' => $usageType,
                    'content_type' => $contentType,
                    'locale' => $unit['locale'] ?? null,
                    'source_fingerprint' => (string) $unit['source_fingerprint'],
                    'processing_version' => (string) $unit['processing_version'],
                    'locator' => ['type' => (string) $locator['type'], 'value' => (string) $locator['value']],
                ];
                $hash = CanonicalJson::hash($anchor);
                if (isset($seen[$hash])) {
                    continue;
                }
                $seen[$hash] = true;
                $text = mb_substr($text, 0, min(self::MAX_UNIT_CHARS, $budget));
                $budget -= mb_strlen($text);
                $units[] = ['ordinal' => count($units) + 1, 'anchor' => $anchor, 'text' => $text];
            }
        }

        return $units;
    }

    /** Current successor anchors, NOT provider text: no character budget or silent truncation. */
    public function successorAnchors(int $actorId, int $activityId): ?array
    {
        $anchors = [];
        foreach (self::SOURCE_PAIRS as [$usage, $content]) {
            try {
                $units = $this->mediaRead->read($actorId, 'course_activity', $activityId, $usage, $content,
                    null, null, null, 'ai', ['operation' => 'authoring_source_selection']);
            } catch (MediaReadException $exception) {
                if ($exception->errorCode === 'unauthorized') {
                    return null;
                }

                continue;
            }
            foreach ($units as $unit) {
                if (! is_array($unit['locator'] ?? null) || trim((string) ($unit['text'] ?? '')) === '') {
                    continue;
                }
                $anchor = [
                    'media_file_id' => (int) $unit['media_file_id'], 'usage_type' => $usage, 'content_type' => $content,
                    'locale' => $unit['locale'] ?? null, 'source_fingerprint' => (string) $unit['source_fingerprint'],
                    'processing_version' => (string) $unit['processing_version'],
                    'locator' => ['type' => (string) $unit['locator']['type'], 'value' => (string) $unit['locator']['value']],
                ];
                $anchors[CanonicalJson::hash($anchor)] = $anchor;
            }
        }
        ksort($anchors, SORT_STRING);

        return $anchors;
    }

    /**
     * Insert anchors for a proposal and seal it. Caller holds the transaction.
     *
     * @param  array<int,array<string,mixed>>  $anchors
     */
    public function sealSources(object $proposal, array $anchors, string $now): void
    {
        $hashes = [];
        foreach (array_values($anchors) as $position => $anchor) {
            $hashes[] = $hash = CanonicalJson::hash($anchor);
            DB::table('ai_authoring_proposal_sources')->insert([
                'customer_id' => $proposal->customer_id, 'proposal_id' => $proposal->id, 'media_file_id' => $anchor['media_file_id'],
                'source_ordinal' => $position + 1, 'usage_type' => $anchor['usage_type'], 'content_type' => $anchor['content_type'],
                'locale' => $anchor['locale'], 'source_fingerprint' => $anchor['source_fingerprint'],
                'processing_version' => $anchor['processing_version'], 'locator' => CanonicalJson::encode($anchor['locator']),
                'anchor_hash' => $hash, 'excerpt' => null, 'created_at' => $now,
            ]);
        }
        DB::table('ai_authoring_proposals')->where('id', $proposal->id)->update([
            'sources_sealed_at' => $now, 'source_count' => count($hashes),
            'source_set_hash' => CanonicalJson::hash($hashes), 'updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $extra */
    public function insertReview(object $proposal, int $revisionId, int $actorId, string $requestUuid, string $commandHash, string $action, string $from, string $to, string $now, array $extra = []): int
    {
        return (int) DB::table('ai_authoring_proposal_reviews')->insertGetId($extra + [
            'customer_id' => $proposal->customer_id, 'proposal_id' => $proposal->id, 'revision_id' => $revisionId,
            'actor_id' => $actorId, 'request_uuid' => strtolower($requestUuid), 'command_hash' => $commandHash,
            'action' => $action, 'from_status' => $from, 'to_status' => $to, 'created_at' => $now,
        ]);
    }

    public function bump(object $proposal, string $status, string $now): void
    {
        DB::table('ai_authoring_proposals')->where('customer_id', $proposal->customer_id)->where('id', $proposal->id)
            ->update(['status' => $status, 'lock_version' => (int) $proposal->lock_version + 1, 'updated_at' => $now]);
    }

    public function review(object $proposal, string $requestUuid): ?object
    {
        return DB::table('ai_authoring_proposal_reviews')->where('customer_id', $proposal->customer_id)
            ->where('request_uuid', strtolower($requestUuid))->first();
    }

    public function now(): string
    {
        return now()->utc()->format('Y-m-d H:i:s.u');
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    public function outcome(?string $errorCode, array $extra = []): array
    {
        return $extra + ['error_code' => $errorCode];
    }
}
