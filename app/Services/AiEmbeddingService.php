<?php

namespace App\Services;

use App\Contracts\Ai\EmbeddingProvider;
use App\Contracts\Ai\VectorStore;
use App\Exceptions\AiEmbeddingException;
use App\Exceptions\AiProviderGateException;
use App\Services\Ai\EmbeddingProviderAdapter;
use App\Support\Ai\EmbeddingWorkItem;
use App\Support\Ai\ProviderGateRequest;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owns the `ai_embeddings` lifecycle and nothing else.
 *
 * Two rules shape every method here:
 *
 *  1. `model_run_id` is NOT NULL, so an embedding row cannot exist before the
 *     gate has authorized an attempt. The order is therefore fixed: authorize,
 *     then write `pending` rows against that run, then execute. There is no
 *     arrangement in which a row exists without an audit trail explaining it.
 *
 *  2. The relational row is the claim about the index; the index is not the
 *     claim about itself. A row reaches `ready` only after the store
 *     acknowledged the write, and reaches `deleted` only after the store
 *     acknowledged the delete. Everything in between stays visible and
 *     retryable rather than being assumed.
 *
 * `processing` is deliberately absent from the vocabulary. In-flight work is
 * expressed as `pending` plus a live `ai_model_runs` row — the audit record is
 * already the lease, and a second status would let the two disagree.
 */
final class AiEmbeddingService
{
    /**
     * A `pending` row is claimed by whatever run it points at, for as long as
     * that run could still be executing.
     */
    private const LIVE_RUN_STATUSES = ['queued', 'running'];

    /**
     * Runs whose attempt provably ended without indexing anything, so their
     * `pending` rows are free to be claimed by a new attempt.
     */
    private const ABANDONED_RUN_STATUSES = ['failed', 'cancelled'];

    /**
     * Statuses that pin an identity with no documented way forward.
     *
     * `stale` and `deleted` both hold `uk_aem_chunk_model` permanently, so an
     * active chunk whose identity landed in either can never be indexed again.
     * That is the residue of OD-3 in the Step 5 review artifact, and it is
     * counted rather than hidden.
     *
     * Deliberately excluded: `ready` is complete, not blocked;
     * `deletion_pending` is a deletion still in progress; `pending` under a
     * live run is someone else's work in flight.
     */
    private const BLOCKED_IDENTITY_STATUSES = ['stale', 'deleted'];

    public function __construct(
        private readonly AiProviderExecutionGate $gate,
        private readonly EmbeddingProvider $provider,
        private readonly VectorStore $store,
    ) {}

    /**
     * Embed one pass of eligible chunks.
     *
     * @return array<string,mixed>
     */
    public function embedPending(?int $sourceId = null): array
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiEmbeddingException('unauthorized');

        $this->releaseAbandonedRuns($customerId);

        $model = (string) config('ai.embedding.model', '');
        $provider = (string) config('ai.embedding.provider', '');
        $dimensions = (int) config('ai.embedding.dimensions', 0);

        // An unconfigured worker stops here, before the gate and before any
        // row is written. Guessing a model would be guessing which allow-list
        // entry applies, which is guessing what was approved.
        if ($provider === '' || $model === '' || $dimensions < 1) {
            return $this->outcome('LF_EMBEDDING_NOT_CONFIGURED');
        }
        if (! $this->store->isConfigured()) {
            return $this->outcome('LF_VECTOR_STORE_UNAVAILABLE');
        }

        $collection = $this->store->collectionFor($model);
        ['candidates' => $candidates, 'stranded' => $stranded] =
            $this->candidates($customerId, $sourceId, $provider, $model, $dimensions, $collection);

        // Nothing claimable: return before the gate. This is the ordinary case
        // once a source is fully indexed, and it is also what keeps a blocked
        // identity cheap — a pass that authorized anyway would mint an
        // `ai_model_runs` row and take a quota hold on every tick, for work
        // that cannot proceed. An audit trail of a worker talking to itself.
        if ($candidates === []) {
            return $this->outcome(null, ['stranded' => $stranded]);
        }

        $request = new ProviderGateRequest(
            provider: $provider,
            model: $model,
            purpose: 'knowledge_embedding',
            dataClasses: (array) config('ai.embedding.data_classes', []),
            executionRegion: (string) config('ai.embedding.execution_region'),
            retentionClass: (string) config('ai.embedding.retention_class'),
            // A bare UUID: `ai_model_runs.correlation_id` is CHAR(36), so any
            // prefix silently costs characters off the other end on MariaDB and
            // is rejected outright under strict mode. The purpose column
            // already says these runs are embeddings.
            //
            // Fresh per pass. A retry of a failed batch is a new attempt with
            // its own audit row: reusing the identity would ask a terminal run
            // to move backwards, and the first failure is evidence in its own
            // right, not something to overwrite.
            correlationId: Str::uuid()->toString(),
            quotaQuantity: (float) count($candidates),
            quotaUnit: 'call',
            usageType: 'provider_call',
        );

        $decision = $this->gate->authorize($request);

        // Fail-closed: no run to execute, no rows written, nothing sent.
        if (! $decision->allowed) {
            return $this->outcome($decision->errorCode, ['blocked_at' => $decision->blockedStep]);
        }

        $execution = $decision->execution;
        // The lock-based check runs again inside claim(): the pre-filter above
        // is unlocked, so it narrows the batch but does not decide it.
        $items = $this->claim($customerId, $execution->modelRunId, $candidates);

        try {
            // Zero items is a legitimate outcome: another worker claimed them
            // between the read and the lock. It still goes through execute()
            // so the run and its hold settle at a true quantity of zero
            // instead of being abandoned to lease expiry.
            $adapter = new EmbeddingProviderAdapter($this->provider, $this->store, $items, $dimensions);
            $this->gate->execute($request, static fn (): EmbeddingProviderAdapter => $adapter);
        } catch (AiProviderGateException $exception) {
            return $this->outcome(
                $exception->errorCode,
                ['stranded' => $stranded]
                    + $this->settleFailure($customerId, $items, $adapter->indexedItems(), $exception->errorCode),
            );
        }

        return $this->outcome(null, [
            'embedded' => $this->markReady($customerId, $adapter->indexedItems()),
            'stranded' => $stranded,
        ]);
    }

    /**
     * Supersede embeddings for chunks whose content or source revision moved
     * on. Their points stay in the index until purge acknowledges the delete —
     * `stale` says "no longer eligible", not "no longer present".
     *
     * @param  array<int,int>  $chunkIds
     */
    public function markStale(array $chunkIds): int
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiEmbeddingException('unauthorized');

        if ($chunkIds === []) {
            return 0;
        }

        $now = now();

        return DB::table('ai_embeddings')
            ->where('customer_id', $customerId)
            ->whereIn('knowledge_chunk_id', $chunkIds)
            // Enumerated, not "everything except the terminal ones". A
            // `pending` row belongs to a run that may still be mid-flight, and
            // a row already heading for deletion must not be pulled back into
            // the retrievable lifecycle.
            ->whereIn('status', ['ready', 'failed'])
            ->update(['status' => 'stale', 'updated_at' => $now]);
    }

    /**
     * Request deletion of every embedding for the given chunks. Idempotent:
     * rows already on the deletion path keep their original request time.
     *
     * @param  array<int,int>  $chunkIds
     */
    public function requestDeletion(array $chunkIds): int
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiEmbeddingException('unauthorized');

        if ($chunkIds === []) {
            return 0;
        }

        $now = now();

        return DB::table('ai_embeddings')
            ->where('customer_id', $customerId)
            ->whereIn('knowledge_chunk_id', $chunkIds)
            ->whereIn('status', ['pending', 'ready', 'failed', 'stale'])
            ->update([
                'status' => 'deletion_pending',
                'deletion_requested_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * Remove points for rows awaiting deletion, tombstoning only what the store
     * acknowledged.
     *
     * This is what eventually lets Knowledge Source deletion past the barrier
     * in AiKnowledgeIngestionService::finalizeSourceDeletion(): that barrier
     * refuses while any embedding is not `deleted`, and refusing is correct
     * until the index has actually let go.
     *
     * @return array<string,int>
     */
    public function purgeDeletionPending(int $limit = 100): array
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiEmbeddingException('unauthorized');

        $rows = DB::table('ai_embeddings')
            ->where('customer_id', $customerId)
            ->where('status', 'deletion_pending')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'vector_index', 'vector_key']);

        $outcome = ['deleted' => 0, 'retained' => 0];

        foreach ($rows as $row) {
            try {
                $acknowledged = $this->store->delete($customerId, $row->vector_index, $row->vector_key);
                $errorCode = $acknowledged ? null : 'LF_VECTOR_DELETE_UNACKNOWLEDGED';
            } catch (Throwable $exception) {
                $acknowledged = false;
                $errorCode = $this->safeCode($exception);
            }

            $now = now();

            if (! $acknowledged) {
                // The row stays exactly where it is. A failed purge that
                // advanced the status would report a point as gone while it is
                // still answering queries.
                DB::table('ai_embeddings')->where('customer_id', $customerId)->where('id', $row->id)
                    ->update([
                        'deletion_attempts' => DB::raw('deletion_attempts + 1'),
                        'last_error_code' => $errorCode,
                        'updated_at' => $now,
                    ]);
                $outcome['retained']++;

                continue;
            }

            DB::table('ai_embeddings')->where('customer_id', $customerId)->where('id', $row->id)
                ->update([
                    'status' => 'deleted',
                    'deleted_at' => $now,
                    'deletion_attempts' => DB::raw('deletion_attempts + 1'),
                    'last_error_code' => null,
                    'updated_at' => $now,
                ]);
            $outcome['deleted']++;
        }

        return $outcome;
    }

    /**
     * Resolve rows left `pending` by a process that died between the store
     * write and the status update.
     *
     * Only runs that already reached `completed` are considered: the provider
     * was paid and the adapter finished, so the store is the authority on
     * whether the point landed. Rows under a live run are someone else's work,
     * and rows under a `running` run belong to provider-aware reconciliation,
     * which is the only thing that can know whether the call happened.
     *
     * @return array<string,int>
     */
    public function reconcilePending(int $limit = 100): array
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiEmbeddingException('unauthorized');

        $rows = DB::table('ai_embeddings as e')
            ->join('ai_model_runs as r', function ($join): void {
                $join->on('r.id', '=', 'e.model_run_id')->on('r.customer_id', '=', 'e.customer_id');
            })
            ->where('e.customer_id', $customerId)
            ->where('e.status', 'pending')
            ->where('r.status', 'completed')
            ->orderBy('e.id')
            ->limit($limit)
            ->get(['e.id', 'e.vector_index', 'e.vector_key']);

        $outcome = ['ready' => 0, 'failed' => 0, 'undetermined' => 0];

        foreach ($rows as $row) {
            try {
                $present = $this->store->exists($customerId, $row->vector_index, $row->vector_key);
            } catch (Throwable $exception) {
                // Unknown is not absent. The row stays `pending` for the next
                // pass rather than being declared failed on a store outage.
                DB::table('ai_embeddings')->where('customer_id', $customerId)->where('id', $row->id)
                    ->update(['last_error_code' => $this->safeCode($exception), 'updated_at' => now()]);
                $outcome['undetermined']++;

                continue;
            }

            $now = now();

            if ($present) {
                DB::table('ai_embeddings')->where('customer_id', $customerId)->where('id', $row->id)
                    ->update(['status' => 'ready', 'embedded_at' => $now, 'last_error_code' => null, 'updated_at' => $now]);
                $outcome['ready']++;

                continue;
            }

            DB::table('ai_embeddings')->where('customer_id', $customerId)->where('id', $row->id)
                ->update([
                    'status' => 'failed',
                    'last_error_code' => 'LF_EMBEDDING_POINT_MISSING',
                    'updated_at' => $now,
                ]);
            $outcome['failed']++;
        }

        return $outcome;
    }

    /**
     * Cancel runs abandoned in `queued`, freeing the `pending` rows they hold.
     *
     * Safe by construction, and only for `queued`: the gate moves a run to
     * `running` before it builds an adapter, so a run still `queued` never
     * reached a provider. The update is conditional on that status, so a worker
     * claiming the same run at the same moment either wins the claim and leaves
     * this update matching nothing, or loses it and makes no provider call.
     *
     * `cancelled` is used rather than `failed` because `failed` requires an
     * error code, and no approved code says "the process went away". Cancelled
     * states the fact without inventing vocabulary.
     */
    public function releaseAbandonedRuns(?int $customerId = null): int
    {
        $customerId ??= TenantContext::customerId()
            ?? throw new AiEmbeddingException('unauthorized');

        $cutoff = now()->subMinutes((int) config('ai.embedding.abandoned_run_minutes', 30));

        return DB::table('ai_model_runs')
            ->where('customer_id', $customerId)
            ->where('purpose', 'knowledge_embedding')
            ->where('status', 'queued')
            ->where('updated_at', '<', $cutoff)
            ->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Chunks eligible for embedding, with the identity each one would get.
     *
     * Rows that already hold the identity are read here without a lock, purely
     * to keep unusable work out of the gate. claim() repeats the check under
     * `lockForUpdate()`, which is what actually decides ownership.
     *
     * @return array{candidates:array<int,array<string,mixed>>,stranded:int}
     */
    private function candidates(
        int $customerId,
        ?int $sourceId,
        string $provider,
        string $model,
        int $dimensions,
        string $collection,
    ): array {
        $query = DB::table('ai_knowledge_chunks as c')
            ->join('ai_knowledge_sources as s', function ($join): void {
                $join->on('s.id', '=', 'c.knowledge_source_id')->on('s.customer_id', '=', 'c.customer_id');
            })
            ->where('c.customer_id', $customerId)
            // Only an active chunk of an active source. Everything else is
            // either not ready to be indexed or on its way out of the index.
            ->where('c.status', 'active')
            ->where('s.status', 'active')
            ->whereNotNull('c.content');

        if ($sourceId !== null) {
            $query->where('c.knowledge_source_id', $sourceId);
        }

        $chunks = $query->orderBy('c.id')
            ->limit((int) config('ai.embedding.chunk_batch', 50))
            ->get([
                'c.id', 'c.knowledge_source_id', 'c.content', 'c.content_hash',
                's.identity_fingerprint', 's.identity_version',
            ]);

        $candidates = [];
        $stranded = 0;
        $held = $this->existingIdentities($customerId, $chunks->pluck('id')->all(), $provider, $model);

        foreach ($chunks as $chunk) {
            $hash = $this->embeddingHash($chunk, $provider, $model, $dimensions);
            $existing = $held[$chunk->id.'|'.$hash] ?? null;

            if ($existing !== null && ! $this->reclaimable($customerId, $existing)) {
                if (in_array($existing->status, self::BLOCKED_IDENTITY_STATUSES, true)) {
                    $stranded++;
                }

                continue;
            }

            $candidates[] = [
                'chunk_id' => (int) $chunk->id,
                'source_id' => (int) $chunk->knowledge_source_id,
                'text' => (string) $chunk->content,
                'embedding_hash' => $hash,
                'collection' => $collection,
                'vector_key' => $this->stableUuid("ai-embedding|{$customerId}|{$chunk->id}|{$hash}"),
                'source_fingerprint' => (string) $chunk->identity_fingerprint,
                'processing_version' => (string) $chunk->identity_version,
                'dimensions' => $dimensions,
                'provider' => $provider,
                'model' => $model,
            ];
        }

        return ['candidates' => $candidates, 'stranded' => $stranded];
    }

    /**
     * Existing embedding rows for these chunks under this provider/model,
     * keyed by chunk and identity.
     *
     * @param  array<int,int>  $chunkIds
     * @return array<string,object>
     */
    private function existingIdentities(int $customerId, array $chunkIds, string $provider, string $model): array
    {
        if ($chunkIds === []) {
            return [];
        }

        $rows = DB::table('ai_embeddings')
            ->where('customer_id', $customerId)
            ->whereIn('knowledge_chunk_id', $chunkIds)
            ->where('provider', $provider)
            ->where('model', $model)
            ->get(['id', 'knowledge_chunk_id', 'embedding_hash', 'status', 'model_run_id']);

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row->knowledge_chunk_id.'|'.$row->embedding_hash] = $row;
        }

        return $keyed;
    }

    /**
     * Take ownership of each candidate under lock, writing the `pending` row
     * that binds it to this run.
     *
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,EmbeddingWorkItem>
     */
    private function claim(int $customerId, int $modelRunId, array $candidates): array
    {
        return DB::transaction(function () use ($customerId, $modelRunId, $candidates): array {
            $items = [];
            $now = now();

            foreach ($candidates as $candidate) {
                $existing = DB::table('ai_embeddings')
                    ->where('customer_id', $customerId)
                    ->where('knowledge_chunk_id', $candidate['chunk_id'])
                    ->where('provider', $candidate['provider'])
                    ->where('model', $candidate['model'])
                    ->where('embedding_hash', $candidate['embedding_hash'])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null && ! $this->reclaimable($customerId, $existing)) {
                    continue;
                }

                if ($existing === null) {
                    $embeddingId = (int) DB::table('ai_embeddings')->insertGetId([
                        'customer_id' => $customerId,
                        'knowledge_chunk_id' => $candidate['chunk_id'],
                        'model_run_id' => $modelRunId,
                        'provider' => $candidate['provider'],
                        'model' => $candidate['model'],
                        'dimensions' => $candidate['dimensions'],
                        'vector_store' => (string) config('ai.vector_store.driver'),
                        'vector_index' => $candidate['collection'],
                        'vector_key' => $candidate['vector_key'],
                        'embedding_hash' => $candidate['embedding_hash'],
                        'status' => 'pending',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $embeddingId = (int) $existing->id;
                    DB::table('ai_embeddings')->where('customer_id', $customerId)->where('id', $embeddingId)
                        ->update([
                            'model_run_id' => $modelRunId,
                            'status' => 'pending',
                            'last_error_code' => null,
                            'updated_at' => $now,
                        ]);
                }

                $items[] = new EmbeddingWorkItem(
                    $embeddingId,
                    $candidate['chunk_id'],
                    $candidate['source_id'],
                    $candidate['collection'],
                    $candidate['vector_key'],
                    $candidate['text'],
                    $candidate['source_fingerprint'],
                    $candidate['processing_version'],
                );
            }

            return $items;
        }, 3);
    }

    /**
     * Whether an existing row may be re-attempted by a new run.
     *
     * Every status is answered by name, never by grouping. The canonical
     * transitions in `docs/database/ai/ai_embeddings.md` (Amendment
     * 2026-09-10) are `pending → ready|failed`, `failed → pending`,
     * `ready|failed → stale` and
     * `pending|ready|failed|stale → deletion_pending → deleted`.
     *
     * `failed` re-enters `pending` directly: that is the retry path, and the
     * failure it is leaving behind is not lost, because every attempt owns its
     * own immutable `ai_model_runs` row.
     *
     * `pending` depends on the run holding it. Re-pointing such a row at a new
     * run is not a status change at all — only a re-claim of work never done.
     *
     * `ready` is finished; `stale`, `deletion_pending` and `deleted` are on or
     * past the way out and have no documented path back.
     */
    private function reclaimable(int $customerId, object $existing): bool
    {
        if ($existing->status === 'failed') {
            return true;
        }

        if ($existing->status !== 'pending') {
            return false;
        }

        $runStatus = (string) DB::table('ai_model_runs')
            ->where('customer_id', $customerId)
            ->where('id', $existing->model_run_id)
            ->value('status');

        if (in_array($runStatus, self::LIVE_RUN_STATUSES, true)) {
            return false;   // another worker owns it
        }

        // `completed` means the provider was paid and the adapter finished, so
        // whether the point landed is a question for reconcilePending() and the
        // store — not for a second provider call.
        return in_array($runStatus, self::ABANDONED_RUN_STATUSES, true);
    }

    /** @param array<int,EmbeddingWorkItem> $items */
    private function markReady(int $customerId, array $items): int
    {
        if ($items === []) {
            return 0;
        }

        $now = now();

        return DB::table('ai_embeddings')
            ->where('customer_id', $customerId)
            ->whereIn('id', array_map(static fn (EmbeddingWorkItem $i): int => $i->embeddingId, $items))
            ->where('status', 'pending')
            ->update(['status' => 'ready', 'embedded_at' => $now, 'last_error_code' => null, 'updated_at' => $now]);
    }

    /**
     * Settle a batch the gate refused to complete.
     *
     * Points that did land are queued for purge rather than kept: their run is
     * `failed`, and a `ready` embedding whose only audit record says the
     * attempt failed is evidence that contradicts itself. The next pass
     * re-embeds them under a run that succeeded.
     *
     * @param  array<int,EmbeddingWorkItem>  $items
     * @param  array<int,EmbeddingWorkItem>  $indexed
     * @return array<string,int>
     */
    private function settleFailure(int $customerId, array $items, array $indexed, string $errorCode): array
    {
        $indexedIds = array_map(static fn (EmbeddingWorkItem $i): int => $i->embeddingId, $indexed);
        $pendingIds = array_values(array_diff(
            array_map(static fn (EmbeddingWorkItem $i): int => $i->embeddingId, $items),
            $indexedIds,
        ));

        $now = now();

        if ($indexedIds !== []) {
            DB::table('ai_embeddings')->where('customer_id', $customerId)
                ->whereIn('id', $indexedIds)->where('status', 'pending')
                ->update([
                    'status' => 'deletion_pending',
                    'deletion_requested_at' => $now,
                    'last_error_code' => $errorCode,
                    'updated_at' => $now,
                ]);
        }

        if ($pendingIds !== []) {
            DB::table('ai_embeddings')->where('customer_id', $customerId)
                ->whereIn('id', $pendingIds)->where('status', 'pending')
                ->update(['status' => 'failed', 'last_error_code' => $errorCode, 'updated_at' => $now]);
        }

        return ['orphaned' => count($indexedIds), 'failed' => count($pendingIds)];
    }

    /**
     * Revision identity of one embedding.
     *
     * Content hash and source revision are both in it: the same text under a
     * new Media revision is a different citation, and reusing the old vector
     * would let a retrieval cite a revision the text no longer comes from.
     */
    private function embeddingHash(object $chunk, string $provider, string $model, int $dimensions): string
    {
        return 'sha256:'.hash('sha256', implode('|', [
            (string) $chunk->content_hash,
            (string) $chunk->identity_fingerprint,
            (string) $chunk->identity_version,
            $provider,
            $model,
            (string) $dimensions,
        ]));
    }

    private function stableUuid(string $identity): string
    {
        $hex = substr(hash('sha256', $identity), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    /**
     * Adapter and store failures are reduced to a code before they are stored.
     * A driver message can carry the request it failed on, and that request
     * carried tenant text.
     */
    private function safeCode(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return preg_match('/^LF_[A-Z0-9_]+$/', $message) === 1
            ? $message
            : 'LF_VECTOR_STORE_ERROR';
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function outcome(?string $errorCode, array $extra = []): array
    {
        // `$extra` first: array union keeps the left operand's keys, so the
        // defaults must be the ones that lose.
        return $extra + ['embedded' => 0, 'error_code' => $errorCode];
    }
}
