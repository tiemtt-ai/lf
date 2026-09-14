<?php

namespace Tests\Integration;

use App\Services\Ai\QdrantVectorStore;
use App\Support\Ai\VectorPoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QdrantVectorStoreIntegrationTest extends TestCase
{
    public function test_real_store_indexes_tenants_and_isolates_search_and_delete(): void
    {
        $url = getenv('LF_QDRANT_TEST_URL');
        if (! $url) {
            $this->markTestSkipped('Dedicated local Qdrant test endpoint required.');
        }
        $parts = parse_url($url);
        $this->assertSame('http', $parts['scheme']);
        $this->assertContains($parts['host'], ['127.0.0.1', 'localhost']);
        config(['ai.vector_store.host' => 'http://'.$parts['host'],
            'ai.vector_store.port' => $parts['port'] ?? 6333]);
        $collection = 'lf_step5_test_'.str_replace('-', '', (string) Str::uuid());
        $base = rtrim($url, '/').'/collections/'.$collection;
        $store = new QdrantVectorStore;
        $first = new VectorPoint($collection, (string) Str::uuid(), [1.0, 0.0, 0.0], 11, 1, 1, 'synthetic', 'test-v1');
        $other = new VectorPoint($collection, (string) Str::uuid(), [1.0, 0.0, 0.0], 22, 2, 2, 'synthetic', 'test-v1');
        Http::put($base, ['vectors' => ['size' => 3, 'distance' => 'Cosine']])->throw();
        try {
            Http::put($base.'/index?wait=true', [
                'field_name' => 'customer_id', 'field_schema' => ['type' => 'keyword', 'is_tenant' => true],
            ])->throw();
            $store->upsert($first);
            $store->upsert($other);
            $this->assertTrue($store->exists(11, $collection, $first->vectorKey));
            $this->assertFalse($store->exists(22, $collection, $first->vectorKey));
            $this->assertSame([$first->vectorKey], $store->search(11, $collection, [1.0, 0.0, 0.0], 5));
            // Wrong tenant deletion may acknowledge a no-op; the point survives.
            $this->assertTrue($store->delete(22, $collection, $first->vectorKey));
            $this->assertTrue($store->exists(11, $collection, $first->vectorKey));
            $schema = Http::get($base)->throw()->json('result.payload_schema.customer_id');
            $this->assertSame('keyword', $schema['data_type']);
            $this->assertTrue($schema['params']['is_tenant']);
            $this->assertSame(2, $schema['points'], 'Both tenants must actually enter the keyword index.');
            $this->assertTrue($store->delete(11, $collection, $first->vectorKey));
            $this->assertFalse($store->exists(11, $collection, $first->vectorKey));
            $this->assertTrue($store->exists(22, $collection, $other->vectorKey));
        } finally {
            Http::delete($base)->throw();
        }
    }
}
