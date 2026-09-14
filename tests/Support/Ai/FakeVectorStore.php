<?php

namespace Tests\Support\Ai;

use App\Contracts\Ai\VectorStore;
use App\Support\Ai\VectorPoint;
use RuntimeException;

/**
 * In-memory index keyed exactly the way the real one is: by collection, tenant
 * and point id together. Keying by point id alone would make a cross-tenant
 * leak in the service invisible here.
 */
final class FakeVectorStore implements VectorStore
{
    /** @var array<string,array<string,mixed>> */
    public array $points = [];

    public int $deleteCalls = 0;

    public bool $configured = true;

    public bool $acknowledgeDeletes = true;

    public ?RuntimeException $failure = null;

    /** @var list<string> Point-specific outages; other points remain usable. */
    public array $failingKeys = [];

    public ?\Closure $beforeExists = null;

    /** Fail the upsert at this 1-based position, to model a partial batch. */
    public ?int $failUpsertAt = null;

    private int $upserts = 0;

    /** @var array<int,string> */
    public array $searchResult = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function collectionFor(string $model): string
    {
        return 'lf_text_'.strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $model));
    }

    public function upsert(VectorPoint $point): void
    {
        $this->upserts++;

        if ($this->failUpsertAt !== null && $this->upserts === $this->failUpsertAt) {
            throw new RuntimeException('LF_VECTOR_STORE_REQUEST_FAILED_500');
        }

        $this->points[$this->key($point->customerId, $point->collection, $point->vectorKey)] = [
            'vector' => $point->vector,
            'payload' => $point->payload(),
        ];
    }

    public function delete(int $customerId, string $collection, string $vectorKey): bool
    {
        $this->deleteCalls++;

        if (in_array($vectorKey, $this->failingKeys, true)) {
            throw new RuntimeException('LF_VECTOR_STORE_REQUEST_FAILED_503');
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }
        if (! $this->acknowledgeDeletes) {
            return false;
        }

        unset($this->points[$this->key($customerId, $collection, $vectorKey)]);

        return true;
    }

    public function exists(int $customerId, string $collection, string $vectorKey): bool
    {
        if (in_array($vectorKey, $this->failingKeys, true)) {
            throw new RuntimeException('LF_VECTOR_STORE_REQUEST_FAILED_503');
        }
        if ($this->beforeExists !== null) {
            ($this->beforeExists)();
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array_key_exists($this->key($customerId, $collection, $vectorKey), $this->points);
    }

    public function search(int $customerId, string $collection, array $vector, int $limit): array
    {
        return array_slice($this->searchResult, 0, $limit);
    }

    private function key(int $customerId, string $collection, string $vectorKey): string
    {
        return $customerId.'|'.$collection.'|'.$vectorKey;
    }
}
