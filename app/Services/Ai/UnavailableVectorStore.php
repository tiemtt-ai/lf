<?php

namespace App\Services\Ai;

use App\Contracts\Ai\VectorStore;
use App\Support\Ai\VectorPoint;
use RuntimeException;

/**
 * The bound default. A deployment that has not configured a vector store gets
 * this one, so "no store" can never be mistaken for "an empty store".
 *
 * `delete()` returns false rather than throwing: a purge worker must make no
 * progress here. Throwing and returning true are both wrong — one turns a
 * routine unconfigured state into an error storm, the other would let a row
 * reach `deleted` while its point, if any, still exists.
 */
final class UnavailableVectorStore implements VectorStore
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function collectionFor(string $model): string
    {
        throw new RuntimeException('LF_VECTOR_STORE_UNAVAILABLE');
    }

    public function upsert(VectorPoint $point): void
    {
        throw new RuntimeException('LF_VECTOR_STORE_UNAVAILABLE');
    }

    public function delete(int $customerId, string $collection, string $vectorKey): bool
    {
        return false;
    }

    public function exists(int $customerId, string $collection, string $vectorKey): bool
    {
        // Not "absent" — unknown. A caller deciding whether a crashed write
        // landed must not read this as proof that it did not.
        throw new RuntimeException('LF_VECTOR_STORE_UNAVAILABLE');
    }

    public function search(int $customerId, string $collection, array $vector, int $limit): array
    {
        throw new RuntimeException('LF_VECTOR_STORE_UNAVAILABLE');
    }
}
