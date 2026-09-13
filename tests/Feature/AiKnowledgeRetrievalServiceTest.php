<?php

namespace Tests\Feature;

use App\Contracts\Ai\CommercialEntitlements;
use App\Contracts\Ai\EmbeddingProvider;
use App\Contracts\Ai\ExternalProcessingApprovals;
use App\Contracts\Ai\TenantSettingSource;
use App\Contracts\Ai\UsageQuotaReserver;
use App\Contracts\Ai\VectorStore;
use App\Exceptions\AiEmbeddingException;
use App\Services\Ai\SettingBackedExternalProcessingApprovals;
use App\Services\AiEmbeddingService;
use App\Services\AiKnowledgeRetrievalService;
use App\Services\CourseMediaOwnerContextAuthorizer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\Ai\FakeCommercialEntitlements;
use Tests\Support\Ai\FakeEmbeddingProvider;
use Tests\Support\Ai\FakeTenantSettings;
use Tests\Support\Ai\FakeUsageQuotaReserver;
use Tests\Support\Ai\FakeVectorStore;
use Tests\TestCase;

/**
 * The index is a candidate generator with no authority. Every test here asks
 * the same question from a different angle: the store returned a hit — what
 * still has to be true before it may be cited?
 */
class AiKnowledgeRetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeTenantSettings $settings;

    private FakeCommercialEntitlements $entitlements;

    private FakeVectorStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.providers', [
            'approved-provider' => [
                'managed' => false,
                'models' => ['approved-model'],
                'purposes' => ['knowledge_embedding'],
                'regions' => ['lf_managed'],
                'retention_classes' => ['none', 'transient'],
                'data_classes' => ['derived_text'],
            ],
        ]);
        config()->set('ai.embedding.provider', 'approved-provider');
        config()->set('ai.embedding.model', 'approved-model');
        config()->set('ai.embedding.dimensions', 3);
        config()->set('ai.embedding.retention_class', 'transient');
        config()->set('ai.vector_store.host', 'http://qdrant.test');

        $this->settings = new FakeTenantSettings;
        $this->entitlements = new FakeCommercialEntitlements;
        $this->store = new FakeVectorStore;

        $this->app->instance(TenantSettingSource::class, $this->settings);
        $this->app->bind(ExternalProcessingApprovals::class, SettingBackedExternalProcessingApprovals::class);
        $this->app->instance(CommercialEntitlements::class, $this->entitlements);
        $this->app->instance(UsageQuotaReserver::class, new FakeUsageQuotaReserver(100.0));
        $this->app->instance(VectorStore::class, $this->store);
        $this->app->instance(EmbeddingProvider::class, new FakeEmbeddingProvider);
    }

    public function test_authorized_hits_are_returned_in_store_rank_order(): void
    {
        [$customerId, $userId, $chunkIds] = $this->indexed('ranked');
        $keys = $this->keysFor($customerId);
        // Deliberately not the insertion order: the relational join will not
        // preserve the store's ranking, so the service has to restore it.
        $this->store->searchResult = [$keys[2], $keys[0], $keys[1]];

        $hits = $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]);

        $this->assertSame(
            [$chunkIds[2], $chunkIds[0], $chunkIds[1]],
            array_column($hits, 'knowledge_chunk_id'),
        );
        $this->assertArrayHasKey('locator', $hits[0]);
        $this->assertSame('page', $hits[0]['locator']['type']);
    }

    public function test_unauthorized_actor_gets_nothing_even_though_the_index_matched(): void
    {
        [$customerId, $userId] = $this->indexed('unauthorized');
        $this->store->searchResult = $this->keysFor($customerId);

        $hits = $this->retrieval(false)->retrieve($userId, [0.1, 0.2, 0.3]);

        // The vector matched. Media authorization is re-entered per query, so
        // the match grants nothing on its own.
        $this->assertSame([], $hits);
    }

    public function test_archived_chunk_is_dropped(): void
    {
        [$customerId, $userId, $chunkIds] = $this->indexed('archived');
        $this->store->searchResult = $this->keysFor($customerId);
        DB::table('ai_knowledge_chunks')->where('id', $chunkIds[0])->update(['status' => 'archived']);

        $hits = $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]);

        $this->assertNotContains($chunkIds[0], array_column($hits, 'knowledge_chunk_id'));
        $this->assertCount(count($chunkIds) - 1, $hits);
    }

    public function test_stale_and_deletion_pending_embeddings_are_dropped(): void
    {
        [$customerId, $userId, $chunkIds] = $this->indexed('superseded');
        $this->store->searchResult = $this->keysFor($customerId);
        $service = $this->app->make(AiEmbeddingService::class);
        $service->markStale([$chunkIds[0]]);
        $service->requestDeletion([$chunkIds[1]]);

        $hits = $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]);

        // Both points are still in the index — that is exactly the window this
        // filter exists for.
        $this->assertCount(3, $this->store->points);
        $this->assertSame([$chunkIds[2]], array_column($hits, 'knowledge_chunk_id'));
    }

    public function test_a_revision_that_moved_on_is_dropped_even_if_nothing_marked_it_stale(): void
    {
        [$customerId, $userId, $chunkIds] = $this->indexed('revision');
        $this->store->searchResult = $this->keysFor($customerId);
        // The text changed underneath a `ready` embedding. Nothing in the index
        // knows; the recomputed identity does.
        DB::table('ai_knowledge_chunks')->where('id', $chunkIds[0])
            ->update(['content' => 'Noi dung da thay doi', 'content_hash' => 'sha256:moved-on']);

        $hits = $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]);

        $this->assertNotContains($chunkIds[0], array_column($hits, 'knowledge_chunk_id'));
    }

    public function test_a_source_pending_deletion_is_dropped(): void
    {
        [$customerId, $userId, $chunkIds, $sourceId] = $this->indexed('source-deleting');
        $this->store->searchResult = $this->keysFor($customerId);
        DB::table('ai_knowledge_sources')->where('id', $sourceId)
            ->update(['deletion_requested_at' => now()]);

        $this->assertSame([], $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]));
    }

    public function test_hits_belonging_to_another_tenant_are_dropped(): void
    {
        [$otherId] = $this->indexed('tenant-a');
        $otherKeys = $this->keysFor($otherId);

        [$customerId, $userId] = $this->indexed('tenant-b');
        // Model a store filter that failed open and returned foreign keys.
        $this->store->searchResult = $otherKeys;

        $hits = $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]);

        // The relational scope is an independent second boundary, so a broken
        // payload filter still leaks nothing.
        $this->assertSame([], $hits);
        $this->assertNotSame($customerId, $otherId);
    }

    public function test_limit_is_applied_after_filtering_not_before(): void
    {
        [$customerId, $userId, $chunkIds] = $this->indexed('limit');
        $this->store->searchResult = $this->keysFor($customerId);
        DB::table('ai_knowledge_chunks')->where('id', $chunkIds[0])->update(['status' => 'archived']);

        $hits = $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3], 2);

        // Over-fetching is what makes this possible: two survivors are still
        // returned after one candidate was dropped.
        $this->assertCount(2, $hits);
    }

    public function test_a_query_vector_of_the_wrong_width_never_reaches_the_store(): void
    {
        [$customerId, $userId] = $this->indexed('width');
        $this->store->searchResult = $this->keysFor($customerId);

        try {
            $this->retrieval(true)->retrieve($userId, [0.1, 0.2]);
            $this->fail('A mis-sized query vector must be refused.');
        } catch (AiEmbeddingException $exception) {
            $this->assertSame('LF_EMBEDDING_DIMENSION_MISMATCH', $exception->errorCode);
        }
    }

    public function test_unconfigured_store_returns_nothing(): void
    {
        [$customerId, $userId] = $this->indexed('offline');
        $this->store->searchResult = $this->keysFor($customerId);
        $this->store->configured = false;

        $this->assertSame([], $this->retrieval(true)->retrieve($userId, [0.1, 0.2, 0.3]));
    }

    private function retrieval(bool $authorized): AiKnowledgeRetrievalService
    {
        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->andReturn($authorized);

        return new AiKnowledgeRetrievalService($this->store, $authorizer);
    }

    /** @return array<int,string> */
    private function keysFor(int $customerId): array
    {
        return DB::table('ai_embeddings')->where('customer_id', $customerId)
            ->orderBy('knowledge_chunk_id')->pluck('vector_key')->all();
    }

    /**
     * A tenant whose chunks have actually been through the embedding worker,
     * so the rows and the index agree the way they would in production.
     *
     * @return array{0:int,1:int,2:array<int,int>,3:int}
     */
    private function indexed(string $slug): array
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => "Retrieve {$slug}", 'slug' => "retrieve-{$slug}", 'subdomain' => "retrieve-{$slug}",
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'customer_id' => $customerId, 'name' => "Retrieve {$slug}", 'email' => "retrieve-{$slug}@example.test",
            'password' => bcrypt('password'), 'role' => 'customer_admin', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mediaId = DB::table('media_files')->insertGetId([
            'customer_id' => $customerId, 'uploaded_by' => $userId, 'file_type' => 'document',
            'mime_type' => 'application/pdf', 'original_name' => "{$slug}.pdf", 'display_name' => $slug,
            'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_bucket' => 'test-media',
            'storage_key' => "retrieve/{$slug}.pdf", 'checksum' => 'sha256:'.$slug, 'file_size_bytes' => 1,
            'visibility' => 'private', 'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        TenantContext::set((object) ['id' => $customerId]);

        $sourceId = (int) DB::table('ai_knowledge_sources')->insertGetId([
            'customer_id' => $customerId, 'source_uuid' => $this->uuid("rsource-{$slug}"),
            'source_type' => 'course_activity', 'source_id' => 909, 'media_file_id' => $mediaId,
            'usage_type' => 'document', 'content_type' => 'extracted_text', 'title' => "Source {$slug}",
            'locale' => 'vi', 'content_hash' => 'sha256:rcontent-'.$slug,
            'source_fingerprint' => str_pad('rfp'.$slug, 64, '0'), 'processing_version' => 'docling@1',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $chunkIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $chunkIds[] = (int) DB::table('ai_knowledge_chunks')->insertGetId([
                'customer_id' => $customerId, 'knowledge_source_id' => $sourceId,
                'chunk_uuid' => $this->uuid("rchunk-{$slug}-{$i}"), 'sequence_no' => $i,
                'content' => "Khoi {$i} cua {$slug}", 'content_hash' => 'sha256:rchunk-'.$slug.'-'.$i,
                'char_start' => 0, 'char_end' => 30, 'locator_type' => 'page',
                'locator_start' => (string) $i, 'locator_end' => (string) $i,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->settings->approve($customerId, 'ai.external_processing.approved-provider.knowledge_embedding', [
            'approved' => true,
            'data_classes' => ['derived_text'],
            'execution_regions' => ['lf_managed'],
            'retention_classes' => ['transient'],
        ]);
        $this->entitlements->grant($customerId, 'ai_knowledge_embedding');
        $this->app->make(AiEmbeddingService::class)->embedPending();

        return [$customerId, $userId, $chunkIds, $sourceId];
    }

    private function uuid(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
