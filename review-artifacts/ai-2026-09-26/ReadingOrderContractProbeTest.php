<?php

use Illuminate\Support\Facades\DB;
use Tests\Feature\AiKnowledgeRetrievalServiceTest;

final class ReadingOrderContractProbeTest extends AiKnowledgeRetrievalServiceTest
{
    public function test_review_probe_media_delete_requests_knowledge_cleanup(): void
    {
        config(['filesystems.disks.media_local.root' => '/private/tmp/lf-ai-review-0926.CuoKrt/probe-media']);
        $parent = new ReflectionClass(AiKnowledgeRetrievalServiceTest::class);
        [$tenant, $actor, $chunks, $sourceId] = $parent->getMethod('indexed')->invoke($this, 'review-delete-chain');
        $mediaId = (int) DB::table('ai_knowledge_sources')->where('id', $sourceId)->value('media_file_id');
        DB::table('media_file_usages')->where('customer_id', $tenant)->where('media_file_id', $mediaId)->update(['status' => 'detached']);
        // Commit the synthetic fixture so after-commit consumers actually run.
        // This probe uses an in-memory SQLite DB, never an application database.
        DB::commit();
        $deleted = app(\App\Services\MediaService::class)->deleteMedia($mediaId);
        $this->assertSame('deleted', $deleted->status);
        $store = $parent->getProperty('store')->getValue($this);
        $store->searchResult = $parent->getMethod('keysFor')->invoke($this, $tenant);
        $reader = $parent->getMethod('retrieval')->invoke($this, true);
        $this->assertSame([], $reader->retrieve($actor, [0.1, 0.2, 0.3]));
        $this->assertNotNull(DB::table('ai_knowledge_chunks')->where('id', $chunks[0])->value('content'));
        $status = DB::table('ai_knowledge_sources')->where('id', $sourceId)->value('status');
        $this->assertContains($status, ['deletion_pending', 'deleted'], 'Media deletion must initiate Knowledge cleanup.');
    }

    public function test_review_probe_retrieval_preserves_reading_order(): void
    {
        $parent = new ReflectionClass(AiKnowledgeRetrievalServiceTest::class);
        [$tenant, $actor, $chunks] = $parent->getMethod('indexed')->invoke($this, 'review-reading-order');
        DB::table('ai_knowledge_chunks')->where('id', $chunks[0])->update(['reading_order' => 17]);
        $store = $parent->getProperty('store')->getValue($this);
        $store->searchResult = $parent->getMethod('keysFor')->invoke($this, $tenant);
        $reader = $parent->getMethod('retrieval')->invoke($this, true);
        $hits = $reader->retrieve($actor, [0.1, 0.2, 0.3]);
        $this->assertNotEmpty($hits);
        $hit = collect($hits)->firstWhere('knowledge_chunk_id', $chunks[0]);
        $this->assertNotNull($hit);
        $this->assertArrayHasKey('reading_order', $hit, 'LF-AI.md requires reading order on every retrieval unit.');
        $this->assertSame(17, $hit['reading_order']);
    }
}
