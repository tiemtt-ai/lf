<?php

namespace App\Services;

use App\Exceptions\AiKnowledgeIngestionException;
use App\Exceptions\MediaReadException;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Keeps Knowledge Source/Chunk in step with Media: create → stale/archived →
 * delete (Knowledge Sync Contract). Reconciliation is the source of truth;
 * events only shorten the delay.
 *
 * Never calls a model, never writes a vector and never reads Media or Course
 * tables itself: eligibility, revisions and content come from Media Read's
 * system principal, deletion facts from MediaService, labels from Course.
 */
final class AiKnowledgeSyncService
{
    /** Corpus per usage (Owner Q2, D1): `table` only when a locale has no `region`. */
    private const CONTENT_TYPES = [
        'document' => ['region', 'table'],
        'audio' => ['transcript'],
        'video' => ['transcript', 'video_frame_text'],
    ];

    private const DELETABLE = ['pending', 'active', 'failed', 'stale', 'archived'];

    private const ARCHIVABLE = ['pending', 'active', 'failed', 'stale'];

    /** Outcomes that repeat for the same revision; retried only after backoff (D6). */
    private const PERMANENT_ERRORS = [
        'empty_revision', 'incomplete_revision', 'invalid_revision', 'invalid_locator',
        'invalid_table_provenance', 'mixed_revision', 'non_deterministic_rebuild',
        'revision_identity_conflict', 'unsupported_source', 'ambiguous_source',
        'language_profile_unavailable', 'structure_unavailable',
    ];

    public function __construct(
        private readonly AiKnowledgeIngestionService $ingestion,
        private readonly MediaReadService $mediaRead,
        private readonly MediaService $media,
        private readonly CourseVersionActivityTitleService $titles,
    ) {}

    /**
     * One pass for the current tenant, deletion first. Ingest is skipped for a
     * tenant that is not active; erasure never is.
     *
     * @return array<string,int>
     */
    public function reconcileTenant(bool $ingest = true): array
    {
        $counts = $this->counts();
        $this->requestDeletionForDeletedMedia(null, $counts);
        $this->purgeVectors($counts);
        $this->finalizeDeletions(null, $counts);
        $this->archiveIneligible($counts);
        if ($ingest) {
            $this->ingestEligible(null, $counts);
        }
        $counts['stuck_deletions'] = DB::table('ai_knowledge_sources')
            ->where('customer_id', $this->customerId())->where('status', 'deletion_pending')
            ->where('deletion_requested_at', '<', now()->subHours((int) config('ai.knowledge_sync.stuck_deletion_hours', 24)))
            ->count();

        return $counts;
    }

    /**
     * `--dry-run`: what one pass would do, with no write of any kind — no
     * status change, cursor, backoff, audit row or Media content read. Unlike a
     * pass it walks every owner and source instead of one bounded batch.
     *
     * @return array<string,int>
     */
    public function planTenant(bool $ingest = true): array
    {
        $customerId = $this->customerId();
        $plan = array_fill_keys([
            'would_request_deletion', 'embeddings_awaiting_purge', 'would_finalize',
            'held_by_embedding_barrier', 'would_archive', 'would_ingest', 'unchanged', 'candidate_errors',
        ], 0);

        $sources = DB::table('ai_knowledge_sources')->where('customer_id', $customerId)
            ->whereIn('status', self::DELETABLE)->whereNotNull('media_file_id')
            ->get(['id', 'status', 'source_type', 'source_id', 'usage_type', 'media_file_id']);
        $deleted = array_flip($this->media->deletedMediaFileIds($sources->pluck('media_file_id')->all()));
        $held = [];
        foreach ($sources as $source) {
            if (isset($deleted[(int) $source->media_file_id])) {
                $plan['would_request_deletion']++;

                continue;
            }
            if (in_array($source->status, self::ARCHIVABLE, true)
                && in_array($source->source_type, ['course_activity', 'course_version_activity'], true)) {
                $key = implode('|', [$source->source_type, $source->source_id, $source->usage_type, $source->media_file_id]);
                $held[$key] ??= $this->mediaRead->knowledgeOwnerHoldsMedia(
                    (string) $source->source_type, (int) $source->source_id,
                    (string) $source->usage_type, (int) $source->media_file_id,
                );
                $plan['would_archive'] += $held[$key] ? 0 : 1;
            }
        }

        $plan['embeddings_awaiting_purge'] = DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->where('status', 'deletion_pending')->count();
        $pending = DB::table('ai_knowledge_sources as s')->where('s.customer_id', $customerId)->where('s.status', 'deletion_pending');
        $plan['held_by_embedding_barrier'] = (clone $pending)->whereExists($this->blockingEmbeddings(...))->count();
        $plan['would_finalize'] = (clone $pending)->whereNotExists($this->blockingEmbeddings(...))->count();

        if ($ingest) {
            $after = 0;
            $limit = (int) config('ai.knowledge_sync.owner_limit', 200);
            do {
                $owners = $this->mediaRead->knowledgeSyncOwners($after, $limit);
                // Advance by the whole batch before inspecting it, as a real pass
                // does: an owner that fails to resolve must not pin the cursor,
                // or a batch made only of such owners is re-read forever.
                $after = $owners === [] ? $after : end($owners)['usage_id'];
                foreach ($owners as $owner) {
                    try {
                        $listing = $this->mediaRead->revisionsForKnowledgeSync(
                            $owner['owner_type'], $owner['owner_id'], $owner['usage_type'],
                            self::CONTENT_TYPES[$owner['usage_type']],
                        );
                    } catch (MediaReadException) {
                        $plan['candidate_errors']++;

                        continue;
                    }
                    $plan['candidate_errors'] += count($listing['candidate_errors']);
                    foreach ($this->corpus($owner['usage_type'], $listing['revisions']) as $revision) {
                        $plan[$this->alreadyActive($customerId, $owner, $revision) ? 'unchanged' : 'would_ingest']++;
                    }
                }
            } while (count($owners) === $limit);
        }

        return $plan;
    }

    /** @return array<string,int> */
    public function eraseForDeletedMedia(int $mediaFileId): array
    {
        $counts = $this->counts();
        if ($this->media->deletedMediaFileIds([$mediaFileId]) === []) {
            return $counts;
        }
        $this->requestDeletionForDeletedMedia($mediaFileId, $counts);
        $this->purgeVectors($counts);
        $this->finalizeDeletions($mediaFileId, $counts);

        return $counts;
    }

    /** @return array<string,int> */
    public function syncForMedia(int $mediaFileId): array
    {
        $counts = $this->counts();
        $this->ingestEligible($mediaFileId, $counts);

        return $counts;
    }

    /** @param array<string,int> $counts */
    private function requestDeletionForDeletedMedia(?int $mediaFileId, array &$counts): void
    {
        $customerId = $this->customerId();
        $mediaIds = DB::table('ai_knowledge_sources')->where('customer_id', $customerId)
            ->whereIn('status', self::DELETABLE)->whereNotNull('media_file_id')
            ->when($mediaFileId !== null, fn ($q) => $q->where('media_file_id', $mediaFileId))
            ->distinct()->orderBy('media_file_id')->pluck('media_file_id')->map(fn ($id): int => (int) $id)->all();

        foreach (array_chunk($mediaIds, 500) as $chunk) {
            $deleted = $this->media->deletedMediaFileIds($chunk);
            if ($deleted === []) {
                continue;
            }
            $sourceIds = DB::table('ai_knowledge_sources')->where('customer_id', $customerId)
                ->whereIn('media_file_id', $deleted)->whereIn('status', self::DELETABLE)
                ->orderBy('id')->pluck('id');
            foreach ($sourceIds as $sourceId) {
                $this->ingestion->requestSourceDeletion((int) $sourceId);
                $counts['deletion_requested']++;
            }
        }
    }

    /** @param array<string,int> $counts */
    private function purgeVectors(array &$counts): void
    {
        $pending = DB::table('ai_embeddings')->where('customer_id', $this->customerId())
            ->where('status', 'deletion_pending')->exists();
        if (! $pending) {
            return;
        }
        try {
            // Resolved only when needed: without embeddings there is no vector
            // store to reach, and a tenant without one must still be erased.
            $outcome = app(AiEmbeddingService::class)
                ->purgeDeletionPending((int) config('ai.knowledge_sync.deletion_limit', 500));
            $counts['vectors_deleted'] += (int) ($outcome['deleted'] ?? 0);
        } catch (Throwable $exception) {
            // Rows stay deletion_pending and the barrier keeps chunk content;
            // the next pass retries. Only the class is logged.
            $counts['vector_purge_errors']++;
            Log::warning('ai_knowledge_sync_vector_purge_failed', [
                'customer_id' => $this->customerId(), 'exception_class' => $exception::class,
            ]);
        }
    }

    /** @param array<string,int> $counts */
    private function finalizeDeletions(?int $mediaFileId, array &$counts): void
    {
        $pending = fn () => DB::table('ai_knowledge_sources as s')
            ->where('s.customer_id', $this->customerId())->where('s.status', 'deletion_pending')
            ->when($mediaFileId !== null, fn ($q) => $q->where('s.media_file_id', $mediaFileId));
        // A source whose vectors are not all deleted cannot finalize yet. It is
        // filtered out BEFORE the limit: selecting by id alone let a batch of
        // held sources occupy every slot on every pass, so sources behind them
        // that were already free of embeddings were never erased. The barrier
        // itself is still re-checked under lock in finalizeSourceDeletion().
        $counts['held_by_embedding_barrier'] += $pending()->whereExists($this->blockingEmbeddings(...))->count();
        $sourceIds = $pending()->whereNotExists($this->blockingEmbeddings(...))->orderBy('s.id')
            ->limit((int) config('ai.knowledge_sync.deletion_limit', 500))->pluck('s.id');
        foreach ($sourceIds as $sourceId) {
            try {
                $this->ingestion->finalizeSourceDeletion((int) $sourceId);
                $counts['deleted']++;
            } catch (AiKnowledgeIngestionException $exception) {
                // An embedding registered or un-deleted since the selection is
                // the barrier doing its job; anything else (a concurrent
                // finalize) is retried on the next pass.
                $counts[$exception->errorCode === 'embedding_delete_barrier'
                    ? 'held_by_embedding_barrier' : 'deletion_errors']++;
            }
        }
    }

    /** Correlated on `s`: a child embedding of the source that is not yet deleted. */
    private function blockingEmbeddings(Builder $query): void
    {
        $query->selectRaw('1')->from('ai_knowledge_chunks as c')
            ->join('ai_embeddings as e', fn ($join) => $join->on('e.knowledge_chunk_id', '=', 'c.id')
                ->on('e.customer_id', '=', 'c.customer_id'))
            ->whereColumn('c.knowledge_source_id', 's.id')->whereColumn('c.customer_id', 's.customer_id')
            ->where('e.status', '<>', 'deleted');
    }

    /** @param array<string,int> $counts */
    private function archiveIneligible(array &$counts): void
    {
        $customerId = $this->customerId();
        $cursorKey = 'ai_knowledge_sync:archive_cursor:'.$customerId;
        $after = (int) Cache::get($cursorKey, 0);
        $limit = (int) config('ai.knowledge_sync.owner_limit', 200);

        $sources = DB::table('ai_knowledge_sources')->where('customer_id', $customerId)
            ->whereIn('status', self::ARCHIVABLE)->whereNotNull('media_file_id')
            ->whereIn('source_type', ['course_activity', 'course_version_activity'])
            ->where('id', '>', $after)->orderBy('id')->limit($limit)
            ->get(['id', 'source_type', 'source_id', 'usage_type', 'media_file_id']);
        Cache::put($cursorKey, $sources->count() < $limit ? 0 : (int) $sources->last()->id, now()->addDay());

        $held = [];
        foreach ($sources as $source) {
            $key = implode('|', [$source->source_type, $source->source_id, $source->usage_type, $source->media_file_id]);
            $held[$key] ??= $this->mediaRead->knowledgeOwnerHoldsMedia(
                (string) $source->source_type, (int) $source->source_id,
                (string) $source->usage_type, (int) $source->media_file_id,
            );
            if (! $held[$key] && $this->ingestion->archiveSource((int) $source->id)) {
                $counts['archived']++;
            }
        }
    }

    /** @param array<string,int> $counts */
    private function ingestEligible(?int $mediaFileId, array &$counts): void
    {
        $customerId = $this->customerId();
        $cursorKey = 'ai_knowledge_sync:owner_cursor:'.$customerId;
        $after = $mediaFileId === null ? (int) Cache::get($cursorKey, 0) : 0;
        $limit = (int) config('ai.knowledge_sync.owner_limit', 200);

        $owners = $this->mediaRead->knowledgeSyncOwners($after, $limit, $mediaFileId);
        if ($mediaFileId === null) {
            Cache::put($cursorKey, count($owners) < $limit ? 0 : end($owners)['usage_id'], now()->addDay());
        }
        $titles = $this->titles->titles(array_column($owners, 'owner_id'));

        foreach ($owners as $owner) {
            try {
                $listing = $this->mediaRead->revisionsForKnowledgeSync(
                    $owner['owner_type'], $owner['owner_id'], $owner['usage_type'],
                    self::CONTENT_TYPES[$owner['usage_type']],
                );
            } catch (MediaReadException $exception) {
                $counts['failed']++;
                $this->logFailure($owner, null, $exception->errorCode);

                continue;
            }
            $revisions = $listing['revisions'];
            foreach ($listing['candidate_errors'] as $error) {
                // Counted on every pass so the operator sees it; logged once per
                // backoff window so a persistent defect does not flood the log.
                $counts['failed']++;
                $key = 'ai_knowledge_sync:candidate:'.hash('sha256', json_encode([$customerId, $owner, $error]));
                if (! Cache::has($key)) {
                    $this->logFailure($owner, $error['content_type'], $error['error_code']);
                    $this->backoff($key);
                }
            }

            foreach ($this->corpus($owner['usage_type'], $revisions) as $revision) {
                if ($this->alreadyActive($customerId, $owner, $revision)) {
                    $counts['unchanged']++;

                    continue;
                }
                $backoffKey = $this->backoffKey($customerId, $owner, $revision);
                if (Cache::has($backoffKey)) {
                    $counts['backoff']++;

                    continue;
                }
                $title = trim($titles[$owner['owner_id']] ?? '');
                try {
                    $this->ingestion->ingestForSync(
                        $owner['owner_type'], $owner['owner_id'], $owner['usage_type'],
                        $revision['content_type'], $revision['locale'],
                        mb_substr($title !== '' ? $title : 'Course Version Activity '.$owner['owner_id'], 0, 255),
                        $revision['language_profile'],
                    );
                    $counts['ingested']++;
                    // A later failure after recovery is a new incident to see.
                    Cache::forget($backoffKey.':database_write_failed');
                    Cache::forget($backoffKey.':database_write_failed:attempts');
                } catch (AiKnowledgeIngestionException|MediaReadException $exception) {
                    $counts['failed']++;
                    $this->logFailure($owner, $revision['content_type'], $exception->errorCode);
                    if (in_array($exception->errorCode, self::PERMANENT_ERRORS, true)) {
                        $this->backoff($backoffKey);
                    }
                } catch (QueryException $exception) {
                    // Registration rolls back its transaction. Only a failure
                    // this revision's own data causes may let the pass go on to
                    // the next owner; anything else is the database, not the
                    // revision, and stops the tenant (K3-R2).
                    if (! $this->isRevisionLocal($exception)) {
                        // Only scalars cross into the frame that builds the new
                        // exception: a frame argument is kept in its trace
                        // (K3-R7), a local variable of this frame is not.
                        throw $this->systemicFailure(
                            (string) ($exception->errorInfo[0] ?? ''), (int) ($exception->errorInfo[1] ?? 0),
                        );
                    }
                    // Never log SQL or bindings: they can contain Media text.
                    // Retried on every pass (no backoff), but a failure that
                    // repeats — a CHECK the revision can never satisfy — is
                    // logged once per window, as candidate errors are. The
                    // window is best-effort: two workers racing on one key may
                    // both log.
                    $counts['failed']++;
                    $logKey = $backoffKey.':database_write_failed';
                    if (! Cache::has($logKey)) {
                        $this->logFailure($owner, $revision['content_type'], 'database_write_failed');
                        $this->backoff($logKey);
                    }
                }
            }
        }
    }

    /**
     * D1: region text already contains the text of PDF/DOCX tables, so a
     * `table` source is kept only for a locale/profile without regions
     * (spreadsheets, where tables are anchored to sheets).
     *
     * @param  array<int,array<string,mixed>>  $revisions
     * @return array<int,array<string,mixed>>
     */
    private function corpus(string $usageType, array $revisions): array
    {
        if ($usageType !== 'document') {
            return $revisions;
        }
        $withRegion = [];
        foreach ($revisions as $revision) {
            if ($revision['content_type'] === 'region') {
                $withRegion[json_encode([$revision['locale'], $revision['language_profile']])] = true;
            }
        }

        return array_values(array_filter($revisions, fn (array $revision): bool => $revision['content_type'] !== 'table'
            || ! isset($withRegion[json_encode([$revision['locale'], $revision['language_profile']])])));
    }

    /**
     * @param  array<string,mixed>  $owner
     * @param  array<string,mixed>  $revision
     */
    private function alreadyActive(int $customerId, array $owner, array $revision): bool
    {
        return DB::table('ai_knowledge_sources')->where('customer_id', $customerId)
            ->where('source_type', $owner['owner_type'])->where('source_id', $owner['owner_id'])
            ->where('usage_type', $owner['usage_type'])->where('content_type', $revision['content_type'])
            ->where('media_file_id', $revision['media_file_id'])
            ->when($revision['locale'] === null, fn ($q) => $q->whereNull('locale'), fn ($q) => $q->where('locale', $revision['locale']))
            ->where('source_fingerprint', $revision['source_fingerprint'])
            ->where('processing_version', $revision['processing_version'])
            ->where('status', 'active')->exists();
    }

    /**
     * @param  array<string,mixed>  $owner
     * @param  array<string,mixed>  $revision
     */
    private function backoffKey(int $customerId, array $owner, array $revision): string
    {
        return 'ai_knowledge_sync:backoff:'.hash('sha256', json_encode([
            $customerId, $owner['owner_type'], $owner['owner_id'], $owner['usage_type'],
            $revision['content_type'], $revision['locale'], $revision['language_profile'],
            $revision['source_fingerprint'], $revision['processing_version'],
        ]));
    }

    private function backoff(string $key): void
    {
        [$first, $cap] = config('ai.knowledge_sync.backoff_minutes', [60, 1440]);
        $attempts = (int) Cache::get($key.':attempts', 0) + 1;
        $minutes = min((int) $cap, (int) $first * (2 ** ($attempts - 1)));
        Cache::put($key.':attempts', $attempts, now()->addMinutes((int) $cap * 2));
        Cache::put($key, true, now()->addMinutes($minutes));
    }

    /**
     * A data or constraint error of this revision's own rows: SQLSTATE class 22
     * (data) or 23 (integrity — CHECK, unique, NOT NULL), plus driver code
     * 1366, an invalid string value: MariaDB 11.4 reports it as 22007, MySQL
     * and older servers under the generic HY000. Permission, connection,
     * lock-wait and exhausted deadlock retries are not.
     */
    private function isRevisionLocal(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');

        return str_starts_with($sqlState, '22') || str_starts_with($sqlState, '23')
            || (int) ($exception->errorInfo[1] ?? 0) === 1366;
    }

    /**
     * The tenant-failure path carries only SQLSTATE and driver code. The query
     * exception is neither chained nor passed in: its message holds SQL and
     * bindings, which can be Media text; a queued listener persists the
     * exception in `failed_jobs`, and with `zend.exception_ignore_args` off a
     * reporter can serialize the arguments recorded in the trace.
     */
    private function systemicFailure(string $sqlState, int $driverCode): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Knowledge sync stopped on a database failure (SQLSTATE %s, driver code %s).',
            preg_replace('/[^0-9A-Z]/', '', $sqlState) ?: 'unknown',
            $driverCode,
        ));
    }

    /** @param array<string,mixed> $owner */
    private function logFailure(array $owner, ?string $contentType, string $errorCode): void
    {
        // Identifiers and the stable code only: never Media text or messages.
        Log::warning('ai_knowledge_sync_ingest_failed', [
            'customer_id' => $this->customerId(), 'owner_type' => $owner['owner_type'],
            'owner_id' => $owner['owner_id'], 'usage_type' => $owner['usage_type'],
            'content_type' => $contentType, 'error_code' => $errorCode,
        ]);
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        return array_fill_keys([
            'ingested', 'unchanged', 'failed', 'backoff', 'archived', 'deletion_requested',
            'vectors_deleted', 'vector_purge_errors', 'deleted', 'held_by_embedding_barrier', 'deletion_errors',
            'stuck_deletions',
        ], 0);
    }

    private function customerId(): int
    {
        return TenantContext::customerId() ?? throw new AiKnowledgeIngestionException('unauthorized');
    }
}
