<?php

namespace Tests\Feature;

use App\Services\Ai\QdrantVectorStore;
use App\Support\Ai\VectorPoint;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class QdrantVectorStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.vector_store.host' => 'http://qdrant.test', 'ai.vector_store.port' => 6333]);
        Http::preventStrayRequests();
    }

    public function test_upsert_requires_completed_acknowledgment(): void
    {
        $sequence = Http::sequence();
        foreach ([[], ['status' => 'ok'], ['status' => 'ok', 'result' => ['status' => 'acknowledged']]] as $body) {
            $sequence->push($body);
        }
        Http::fake(['*' => $sequence]);
        foreach ([[], ['status' => 'ok'], ['status' => 'ok', 'result' => ['status' => 'acknowledged']]] as $body) {
            $error = null;
            try {
                (new QdrantVectorStore)->upsert($this->point());
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
            $this->assertNotNull($error, 'Unconfirmed write must not make an embedding ready.');
            $this->assertStringStartsWith('LF_VECTOR_STORE_', $error);
        }
    }

    public function test_upsert_accepts_completed_and_sends_no_raw_text(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok', 'result' => ['status' => 'completed']])]);
        (new QdrantVectorStore)->upsert($this->point());
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request['points'][0]['payload']['customer_id'] === 11
            && ! array_key_exists('text', $request['points'][0]['payload']));
    }

    public function test_missing_scroll_result_is_unknown_not_absent(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'])]);
        $this->expectException(RuntimeException::class);
        (new QdrantVectorStore)->exists(11, 'test', $this->point()->vectorKey);
    }

    public function test_explicit_empty_scroll_is_absent_and_filters_tenant_and_key(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok', 'result' => ['points' => []]])]);
        $this->assertFalse((new QdrantVectorStore)->exists(11, 'test', $this->point()->vectorKey));
        Http::assertSent(fn ($request) => $request['filter']['must'] === [
            ['has_id' => [$this->point()->vectorKey]],
            ['key' => 'customer_id', 'match' => ['value' => 11]],
        ]);
    }

    public function test_malformed_search_is_not_an_empty_success(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok', 'result' => [['score' => 0.9]]])]);
        $this->expectException(RuntimeException::class);
        (new QdrantVectorStore)->search(11, 'test', [0.2, 0.4], 2);
    }

    public function test_search_preserves_rank_and_tenant_filter(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok', 'result' => [['id' => 'first'], ['id' => 'second']]])]);
        $this->assertSame(['first', 'second'], (new QdrantVectorStore)->search(11, 'test', [0.2, 0.4], 2));
        Http::assertSent(fn ($request) => $request['filter']['must'] === [
            ['key' => 'customer_id', 'match' => ['value' => 11]],
        ]);
    }

    public function test_delete_requires_completed_and_filters_tenant_and_key(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['status' => 'ok', 'result' => ['status' => 'acknowledged']])
            ->push(['status' => 'ok', 'result' => ['status' => 'completed']])]);
        $this->assertFalse((new QdrantVectorStore)->delete(11, 'test', $this->point()->vectorKey));
        $this->assertTrue((new QdrantVectorStore)->delete(11, 'test', $this->point()->vectorKey));
        Http::assertSent(fn ($request) => $request['filter']['must'] === [
            ['has_id' => [$this->point()->vectorKey]],
            ['key' => 'customer_id', 'match' => ['value' => 11]],
        ]);
    }

    private function point(): VectorPoint
    {
        return new VectorPoint('test', '01910000-0000-7000-8000-000000000020', [0.2, 0.4], 11, 1, 1, 'fingerprint', 'version');
    }
}
