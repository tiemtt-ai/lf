<?php

namespace Tests\Feature;

use App\Exceptions\AiKnowledgeIngestionException;
use App\Services\AiKnowledgeIngestionService;
use App\Services\MediaReadService;
use App\Support\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class AiKnowledgeIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    public function test_it_ingests_media_units_with_exact_provenance_and_is_idempotent(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('base');
        $units = [
            $this->unit($mediaId, '15#1', 'Động từ bất quy tắc ㅂ', [
                'structure' => [
                    'role' => 'paragraph',
                    'reading_order' => 41,
                    'text_quality' => 'normal',
                    'languages' => [
                        ['script' => 'Latn', 'locale' => 'vi', 'char_count' => 19],
                        ['script' => 'Hang', 'locale' => 'ko', 'char_count' => 1],
                    ],
                    'bbox' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.04],
                ],
            ]),
            $this->unit($mediaId, '15#2', '한국어 문법'),
        ];
        $service = $this->serviceReturning($units, 2);

        $first = $service->ingestMedia($userId, 'course_activity', 99, 'document', 'region', 'vi', 'Bài 15', ['vi', 'ko']);
        $second = $service->ingestMedia($userId, 'course_activity', 99, 'document', 'region', 'vi', 'Bài 15', ['vi', 'ko']);

        $this->assertFalse($first['reused']);
        $this->assertTrue($second['reused']);
        $this->assertSame($first['source_uuid'], $second['source_uuid']);
        $this->assertSame(1, DB::table('ai_knowledge_sources')->where('customer_id', $customerId)->count());
        $this->assertSame(2, DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)->count());
        $source = DB::table('ai_knowledge_sources')->where('id', $first['source_id'])->first();
        $chunk = DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $source->id)
            ->where('locator_start', '15#1')->first();
        $this->assertSame($mediaId, (int) $source->media_file_id);
        $this->assertSame(str_repeat('a', 64), rtrim($source->source_fingerprint));
        $this->assertSame('docling-v1', $source->processing_version);
        $this->assertSame('15#1', $chunk->locator_end);
        $this->assertSame(41, (int) $chunk->reading_order);
        $this->assertSame('normal', $chunk->source_text_quality);
        $this->assertSame('Hang', json_decode($chunk->language_evidence, true)[1]['script']);
    }

    public function test_oversized_unit_is_split_deterministically_without_overlap(): void
    {
        [, $userId, $mediaId] = $this->tenant('split');
        $text = str_repeat('가', 3990).'. '.str_repeat('나', 220);
        $service = $this->serviceReturning([$this->unit($mediaId, '7#1', $text)], 2);

        $first = $service->ingestMedia($userId, 'course_activity', 7, 'document', 'region', 'vi', 'Split');
        $before = DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $first['source_id'])
            ->orderBy('sequence_no')->get(['chunk_uuid', 'part_index', 'char_start', 'char_end', 'content_hash']);
        $service->ingestMedia($userId, 'course_activity', 7, 'document', 'region', 'vi', 'Split');
        $after = DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $first['source_id'])
            ->orderBy('sequence_no')->get(['chunk_uuid', 'part_index', 'char_start', 'char_end', 'content_hash']);

        $this->assertCount(2, $before);
        $this->assertEquals($before, $after);
        $this->assertSame(0, (int) $before[0]->char_start);
        $this->assertSame((int) $before[0]->char_end, (int) $before[1]->char_start);
        $this->assertSame(mb_strlen($text), (int) $before[1]->char_end);
    }

    public function test_new_revision_creates_a_new_source_and_stales_the_old_revision(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('revision');
        $reader = Mockery::mock(MediaReadService::class);
        $reader->shouldReceive('read')->once()->andReturn([$this->unit($mediaId, '1', 'Bản cũ')]);
        $reader->shouldReceive('read')->once()->andReturn([
            $this->unit($mediaId, '1', 'Bản mới', ['source_fingerprint' => str_repeat('b', 64), 'processing_version' => 'docling-v2']),
        ]);
        $service = new AiKnowledgeIngestionService($reader);

        $old = $service->ingestMedia($userId, 'course_activity', 8, 'document', 'region', 'vi', 'Revision');
        $oldChunkId = (int) DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $old['source_id'])->value('id');
        $oldEmbeddingId = $this->embedding($customerId, $oldChunkId, $this->modelRun($customerId));
        $new = $service->ingestMedia($userId, 'course_activity', 8, 'document', 'region', 'vi', 'Revision');

        $this->assertNotSame($old['source_id'], $new['source_id']);
        $this->assertSame('stale', DB::table('ai_knowledge_sources')->where('id', $old['source_id'])->value('status'));
        $this->assertSame('stale', DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $old['source_id'])->value('status'));
        $this->assertSame('stale', DB::table('ai_embeddings')->where('id', $oldEmbeddingId)->value('status'));
        $this->assertSame('active', DB::table('ai_knowledge_sources')->where('id', $new['source_id'])->value('status'));
        $this->assertSame(2, DB::table('ai_knowledge_sources')->where('customer_id', $customerId)->count());
    }

    public function test_mixed_revision_payload_fails_atomically(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('mixed');
        $service = $this->serviceReturning([
            $this->unit($mediaId, '1', 'A'),
            $this->unit($mediaId, '2', 'B', ['processing_version' => 'docling-v2']),
        ]);

        try {
            $service->ingestMedia($userId, 'course_activity', 9, 'document', 'region', 'vi', 'Mixed');
            $this->fail('Mixed revisions must be rejected.');
        } catch (AiKnowledgeIngestionException $exception) {
            $this->assertSame('mixed_revision', $exception->errorCode);
        }
        $this->assertSame(0, DB::table('ai_knowledge_sources')->where('customer_id', $customerId)->count());
    }

    public function test_tenants_with_identical_content_receive_isolated_sources_and_chunks(): void
    {
        [$customerA, $userA, $mediaA] = $this->tenant('tenant-a');
        $serviceA = $this->serviceReturning([$this->unit($mediaA, '1', 'Nội dung giống nhau')]);
        $a = $serviceA->ingestMedia($userA, 'course_activity', 11, 'document', 'region', 'vi', 'A');

        [$customerB, $userB, $mediaB] = $this->tenant('tenant-b');
        $serviceB = $this->serviceReturning([$this->unit($mediaB, '1', 'Nội dung giống nhau')]);
        $b = $serviceB->ingestMedia($userB, 'course_activity', 11, 'document', 'region', 'vi', 'B');

        $this->assertNotSame($a['source_uuid'], $b['source_uuid']);
        $this->assertSame(1, DB::table('ai_knowledge_sources')->where('customer_id', $customerA)->count());
        $this->assertSame(1, DB::table('ai_knowledge_sources')->where('customer_id', $customerB)->count());
        TenantContext::set((object) ['id' => $customerA]);
        try {
            $serviceA->requestSourceDeletion($b['source_id']);
            $this->fail('Tenant A must not mutate tenant B source.');
        } catch (AiKnowledgeIngestionException $exception) {
            $this->assertSame('source_not_found', $exception->errorCode);
        }
    }

    public function test_delete_barrier_requires_all_embeddings_to_be_deleted_before_tombstoning(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('delete');
        $service = $this->serviceReturning([$this->unit($mediaId, '1', 'Cần xoá')]);
        $result = $service->ingestMedia($userId, 'course_activity', 12, 'document', 'region', 'vi', 'Delete');
        $chunkId = (int) DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $result['source_id'])->value('id');
        $runId = $this->modelRun($customerId);
        $embeddingId = $this->embedding($customerId, $chunkId, $runId);

        $service->requestSourceDeletion($result['source_id']);
        $this->assertSame('deletion_pending', DB::table('ai_knowledge_sources')->where('id', $result['source_id'])->value('status'));
        $this->assertSame('deletion_pending', DB::table('ai_knowledge_chunks')->where('id', $chunkId)->value('status'));
        $this->assertSame('deletion_pending', DB::table('ai_embeddings')->where('id', $embeddingId)->value('status'));

        try {
            $service->finalizeSourceDeletion($result['source_id']);
            $this->fail('A non-deleted embedding must hold the delete barrier.');
        } catch (AiKnowledgeIngestionException $exception) {
            $this->assertSame('embedding_delete_barrier', $exception->errorCode);
        }

        DB::table('ai_embeddings')->where('id', $embeddingId)->update(['status' => 'deleted', 'deleted_at' => now()]);
        $service->finalizeSourceDeletion($result['source_id']);
        $this->assertSame('deleted', DB::table('ai_knowledge_sources')->where('id', $result['source_id'])->value('status'));
        $chunk = DB::table('ai_knowledge_chunks')->where('id', $chunkId)->first();
        $this->assertSame('deleted', $chunk->status);
        $this->assertNull($chunk->content);
        $this->assertSame(1, DB::table('ai_knowledge_sources')->where('id', $result['source_id'])->count());
    }

    public function test_ingestion_creates_no_model_run_embedding_or_vector_side_effect(): void
    {
        [, $userId, $mediaId] = $this->tenant('no-provider');
        $service = $this->serviceReturning([$this->unit($mediaId, '1', 'Chỉ ingestion')]);

        $service->ingestMedia($userId, 'course_activity', 13, 'document', 'region', 'vi', 'No provider');

        $this->assertSame(0, DB::table('ai_model_runs')->count());
        $this->assertSame(0, DB::table('ai_embeddings')->count());
    }

    public function test_deleted_registration_is_not_revived_and_reingest_uses_next_generation(): void
    {
        [, $userId, $mediaId] = $this->tenant('generation');
        $service = $this->serviceReturning([$this->unit($mediaId, '1', 'Cùng revision')], 2);
        $first = $service->ingestMedia($userId, 'course_activity', 14, 'document', 'region', 'vi', 'Generation');
        $service->requestSourceDeletion($first['source_id']);
        $service->finalizeSourceDeletion($first['source_id']);

        $second = $service->ingestMedia($userId, 'course_activity', 14, 'document', 'region', 'vi', 'Generation');

        $this->assertNotSame($first['source_id'], $second['source_id']);
        $this->assertSame(2, $second['generation']);
        $this->assertSame('deleted', DB::table('ai_knowledge_sources')->where('id', $first['source_id'])->value('status'));
        $this->assertSame('active', DB::table('ai_knowledge_sources')->where('id', $second['source_id'])->value('status'));
    }

    public function test_same_revision_with_changed_content_fails_instead_of_mutating_snapshot(): void
    {
        [, $userId, $mediaId] = $this->tenant('immutable');
        $reader = Mockery::mock(MediaReadService::class);
        $reader->shouldReceive('read')->once()->andReturn([$this->unit($mediaId, '1', 'Nội dung ban đầu')]);
        $reader->shouldReceive('read')->once()->andReturn([$this->unit($mediaId, '1', 'Nội dung bị đổi')]);
        $service = new AiKnowledgeIngestionService($reader);
        $source = $service->ingestMedia($userId, 'course_activity', 15, 'document', 'region', 'vi', 'Immutable');

        try {
            $service->ingestMedia($userId, 'course_activity', 15, 'document', 'region', 'vi', 'Immutable');
            $this->fail('The same revision cannot mutate its content snapshot.');
        } catch (AiKnowledgeIngestionException $exception) {
            $this->assertSame('non_deterministic_rebuild', $exception->errorCode);
        }

        $this->assertSame(
            'Nội dung ban đầu',
            DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $source['source_id'])->value('content')
        );
    }

    public function test_same_revision_rebuild_rejects_sequence_drift(): void
    {
        [, $userId, $mediaId] = $this->tenant('sequence-drift');
        $service = $this->serviceReturning([$this->unit($mediaId, '1', 'Ổn định')], 2);
        $source = $service->ingestMedia($userId, 'course_activity', 18, 'document', 'region', 'vi', 'Sequence');
        DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $source['source_id'])
            ->update(['sequence_no' => 2]);

        try {
            $service->ingestMedia($userId, 'course_activity', 18, 'document', 'region', 'vi', 'Sequence');
            $this->fail('Sequence drift must not be silently accepted.');
        } catch (AiKnowledgeIngestionException $exception) {
            $this->assertSame('non_deterministic_rebuild', $exception->errorCode);
        }

        $this->assertSame(2, (int) DB::table('ai_knowledge_chunks')
            ->where('knowledge_source_id', $source['source_id'])->value('sequence_no'));
    }

    public function test_table_cell_text_is_serialized_by_row_and_column_into_one_unit_chunk(): void
    {
        [, $userId, $mediaId] = $this->tenant('table');
        $unit = $this->unit($mediaId, '3#table-1', '', [
            'content_type' => 'table',
            'locator' => ['type' => 'region', 'value' => '3#table-1'],
            'structure' => [
                'quality_status' => 'complete',
                'cells' => [
                    ['row' => 2, 'column' => 1, 'text' => '한국어'],
                    ['row' => 1, 'column' => 2, 'text' => 'Nghĩa'],
                    ['row' => 1, 'column' => 1, 'text' => 'Từ'],
                ],
            ],
        ]);
        $service = $this->serviceReturning([$unit]);

        $result = $service->ingestMedia($userId, 'course_activity', 16, 'document', 'table', 'vi', 'Table');
        $chunk = DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $result['source_id'])->first();

        $this->assertSame("Từ\tNghĩa\n한국어", $chunk->content);
        $this->assertSame('complete', $chunk->source_quality_status);
        $this->assertSame('3#table-1', $chunk->locator_start);
        $this->assertSame('3#table-1', $chunk->locator_end);
    }

    public function test_delete_barrier_uses_a_real_for_update_lock_on_mariadb(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('FOR UPDATE SQL evidence requires MariaDB.');
        }

        [$customerId, $userId, $mediaId] = $this->tenant('delete-lock');
        $service = $this->serviceReturning([$this->unit($mediaId, '1', 'Lock evidence')]);
        $result = $service->ingestMedia($userId, 'course_activity', 17, 'document', 'region', 'vi', 'Lock');
        $chunkId = (int) DB::table('ai_knowledge_chunks')->where('knowledge_source_id', $result['source_id'])->value('id');
        $this->embedding($customerId, $chunkId, $this->modelRun($customerId));
        $service->requestSourceDeletion($result['source_id']);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        try {
            $service->finalizeSourceDeletion($result['source_id']);
            $this->fail('The embedding must hold the delete barrier.');
        } catch (AiKnowledgeIngestionException $exception) {
            $this->assertSame('embedding_delete_barrier', $exception->errorCode);
        }

        $this->assertTrue(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'ai_embeddings') && str_contains($sql, 'for update')
        ), 'Embedding barrier query must acquire a row lock on MariaDB.');
    }

    /** @param array<int,array<string,mixed>> $units */
    private function serviceReturning(array $units, int $calls = 1): AiKnowledgeIngestionService
    {
        $reader = Mockery::mock(MediaReadService::class);
        $reader->shouldReceive('read')->times($calls)->andReturn($units);

        return new AiKnowledgeIngestionService($reader);
    }

    /** @return array<string,mixed> */
    private function unit(int $mediaId, string $locator, string $text, array $overrides = []): array
    {
        return array_replace_recursive([
            'media_file_id' => $mediaId,
            'source_fingerprint' => str_repeat('a', 64),
            'processing_version' => 'docling-v1',
            'content_type' => 'region',
            'locale' => 'vi',
            'language_profile' => ['vi', 'ko'],
            'locator' => ['type' => 'region', 'value' => $locator],
            'text' => $text,
            'status' => 'ready',
            'structure' => [
                'role' => 'paragraph',
                'reading_order' => 1,
                'text_quality' => 'normal',
                'languages' => [],
                'bbox' => null,
            ],
        ], $overrides);
    }

    /** @return array{int,int,int} */
    private function tenant(string $slug): array
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => "AI Ingestion {$slug}",
            'slug' => "ai-ingestion-{$slug}",
            'subdomain' => "ai-ingestion-{$slug}",
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'customer_id' => $customerId,
            'name' => "AI Ingestion {$slug}",
            'email' => "ai-ingestion-{$slug}@example.test",
            'password' => bcrypt('password'),
            'role' => 'customer_admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mediaId = DB::table('media_files')->insertGetId([
            'customer_id' => $customerId,
            'uploaded_by' => $userId,
            'file_type' => 'document',
            'mime_type' => 'application/pdf',
            'original_name' => "{$slug}.pdf",
            'display_name' => $slug,
            'extension' => 'pdf',
            'storage_disk' => 'media_local',
            'storage_bucket' => 'test-media',
            'storage_key' => "ai-ingestion/{$slug}.pdf",
            'checksum' => 'sha256:'.$slug,
            'file_size_bytes' => 1,
            'visibility' => 'private',
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        TenantContext::set((object) ['id' => $customerId]);

        return [$customerId, $userId, $mediaId];
    }

    private function modelRun(int $customerId): int
    {
        return DB::table('ai_model_runs')->insertGetId([
            'customer_id' => $customerId,
            'run_uuid' => $this->uuid('run-'.$customerId),
            'prompt_hash' => 'sha256:fixture',
            'purpose' => 'knowledge_embedding',
            'provider' => 'fixture-provider',
            'model' => 'fixture-model',
            'status' => 'completed',
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function embedding(int $customerId, int $chunkId, int $runId): int
    {
        return DB::table('ai_embeddings')->insertGetId([
            'customer_id' => $customerId,
            'knowledge_chunk_id' => $chunkId,
            'model_run_id' => $runId,
            'provider' => 'fixture-provider',
            'model' => 'fixture-model',
            'dimensions' => 3,
            'vector_store' => 'qdrant',
            'vector_index' => 'fixture',
            'vector_key' => $this->uuid('embedding-'.$chunkId),
            'embedding_hash' => 'sha256:fixture',
            'status' => 'ready',
            'embedded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function uuid(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
