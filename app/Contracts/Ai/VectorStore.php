<?php

namespace App\Contracts\Ai;

use App\Support\Ai\VectorPoint;

/**
 * The derived vector index. Never a Source Of Truth: relational state decides
 * whether a candidate is still eligible, and a hit here grants nothing on its
 * own (ADR-0006 v1.0.2).
 *
 * Every method takes `customerId` because the collection is shared and the
 * tenant filter is the isolation boundary. An implementation that omits the
 * filter on any operation — including delete — is a cross-tenant defect, not a
 * performance choice.
 *
 * Every method also takes the collection explicitly rather than deriving it
 * from configuration. The collection depends on the embedding model, so a
 * deployment that switches models would otherwise lose the ability to address —
 * and therefore to delete — every point it had already written.
 */
interface VectorStore
{
    /** Whether the store is configured and reachable enough to be used. */
    public function isConfigured(): bool;

    /**
     * Collection a *new* point for this model belongs in. Callers persist the
     * returned name alongside the row so later deletes address the collection
     * the point actually went to, not the one configured today.
     */
    public function collectionFor(string $model): string;

    /**
     * Write one point. Must be idempotent on `(collection, vectorKey)`: a retry
     * after an unacknowledged write has to converge, not duplicate.
     */
    public function upsert(VectorPoint $point): void;

    /**
     * Delete one point by exact key, scoped to the tenant.
     *
     * Returns true only on positive acknowledgement. A caller may move the
     * relational row to `deleted` on true and on nothing else — reporting a
     * delete that did not happen is how an index keeps serving data the
     * relational side believes is gone.
     */
    public function delete(int $customerId, string $collection, string $vectorKey): bool;

    /** Whether a point exists — used to recover a crash between write and commit. */
    public function exists(int $customerId, string $collection, string $vectorKey): bool;

    /**
     * Nearest neighbours for a query vector, tenant-filtered.
     *
     * @param  array<int,float>  $vector
     * @return array<int,string> Candidate vector keys, best first.
     */
    public function search(int $customerId, string $collection, array $vector, int $limit): array;
}
