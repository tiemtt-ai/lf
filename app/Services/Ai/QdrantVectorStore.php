<?php

namespace App\Services\Ai;

use App\Contracts\Ai\VectorStore;
use App\Support\Ai\VectorPoint;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Qdrant self-hosted adapter — ADR-0006 v1.0.2.
 *
 * Collections are shared and partitioned by an indexed `customer_id` payload,
 * so every request here carries a tenant filter. `delete()` filters by tenant
 * *and* exact key even though the key alone is unique: a key is derived data,
 * and a bug that produced a colliding key must not be able to reach into
 * another tenant's points.
 *
 * `host` ships empty. An unconfigured store refuses rather than guessing an
 * endpoint, so a misconfigured deployment fails visibly instead of quietly
 * indexing nothing.
 */
final class QdrantVectorStore implements VectorStore
{
    public function isConfigured(): bool
    {
        $host = config('ai.vector_store.host');

        return is_string($host) && $host !== '';
    }

    public function collectionFor(string $model): string
    {
        // The model is part of the name because vectors from different models
        // are not comparable: mixing them in one collection would return
        // neighbours computed in a space the query never lived in.
        return config('ai.vector_store.collection_prefix').'_'
            .strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $model));
    }

    public function upsert(VectorPoint $point): void
    {
        $response = $this->request('put', "/collections/{$point->collection}/points?wait=true", [
            'points' => [[
                'id' => $point->vectorKey,
                'vector' => $point->vector,
                'payload' => $point->payload(),
            ]],
        ]);

        if (($response['result']['status'] ?? null) !== 'completed') {
            throw new RuntimeException('LF_VECTOR_STORE_WRITE_UNCONFIRMED');
        }
    }

    public function delete(int $customerId, string $collection, string $vectorKey): bool
    {
        $response = $this->request('post', "/collections/{$collection}/points/delete?wait=true", [
            'filter' => $this->tenantFilter($customerId, $vectorKey),
        ]);

        // Only an explicit acknowledgement counts. Anything else leaves the
        // relational row in `deletion_pending` to be retried.
        return ($response['result']['status'] ?? null) === 'completed';
    }

    public function exists(int $customerId, string $collection, string $vectorKey): bool
    {
        $response = $this->request('post', "/collections/{$collection}/points/scroll", [
            'limit' => 1,
            'with_payload' => false,
            'with_vector' => false,
            'filter' => $this->tenantFilter($customerId, $vectorKey),
        ]);

        $points = $response['result']['points'] ?? null;
        $this->validateHits($points);

        return $points !== [];
    }

    public function search(int $customerId, string $collection, array $vector, int $limit): array
    {
        $response = $this->request('post', "/collections/{$collection}/points/search", [
            'vector' => $vector,
            'limit' => $limit,
            'with_payload' => false,
            'filter' => ['must' => [['key' => 'customer_id', 'match' => ['value' => $customerId]]]],
        ]);

        $hits = $response['result'] ?? null;
        $this->validateHits($hits);

        return array_values(array_map(
            static fn (array $hit): string => (string) $hit['id'],
            $hits
        ));
    }

    private function validateHits(mixed $hits): void
    {
        if (! is_array($hits) || ! array_is_list($hits)) {
            throw new RuntimeException('LF_VECTOR_STORE_INVALID_RESPONSE');
        }

        foreach ($hits as $hit) {
            if (! is_array($hit) || ! isset($hit['id'])
                || (! is_string($hit['id']) && ! is_int($hit['id']))
                || (string) $hit['id'] === '') {
                throw new RuntimeException('LF_VECTOR_STORE_INVALID_RESPONSE');
            }
        }
    }

    /** @return array<string,mixed> */
    private function tenantFilter(int $customerId, string $vectorKey): array
    {
        return ['must' => [
            ['has_id' => [$vectorKey]],
            ['key' => 'customer_id', 'match' => ['value' => $customerId]],
        ]];
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $body): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('LF_VECTOR_STORE_UNAVAILABLE');
        }

        $base = rtrim((string) config('ai.vector_store.host'), '/').':'.config('ai.vector_store.port');

        $response = Http::timeout((int) config('ai.vector_store.timeout_seconds'))
            ->acceptJson()
            ->{$method}($base.$path, $body);

        if (! $response->successful()) {
            // Only the status travels. A store error body routinely echoes the
            // request it rejected, and that request carried tenant vectors.
            throw new RuntimeException('LF_VECTOR_STORE_REQUEST_FAILED_'.$response->status());
        }

        $body = $response->json();
        if (! is_array($body) || ($body['status'] ?? null) !== 'ok') {
            throw new RuntimeException('LF_VECTOR_STORE_INVALID_RESPONSE');
        }

        return $body;
    }
}
