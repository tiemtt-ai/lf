<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Physical proof for the AI Foundation Media-consumer packet.
 *
 * SQLite silently ignores CHECK constraints and does not build STORED
 * generated columns the same way, so none of these invariants can be proven
 * on the default test connection. This suite runs only against MariaDB.
 */
class AiFoundationKnowledgePacketMariaDbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('AI Foundation physical constraints require MariaDB.');
        }
    }

    public function test_null_safe_identity_blocks_a_duplicate_registration_of_the_same_revision(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('identity');

        $this->source($customerId, $userId, $mediaId, 'src-identity-1');

        // Same owner, same usage slot, same revision. Without the STORED
        // sentinel columns the NULL locale would make MariaDB treat this as a
        // distinct key and duplicate every chunk and embedding below it.
        $this->expectException(QueryException::class);
        $this->source($customerId, $userId, $mediaId, 'src-identity-2');
    }

    public function test_a_different_source_fingerprint_is_a_new_registration_not_an_overwrite(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('revision');

        $this->source($customerId, $userId, $mediaId, 'src-rev-1', ['source_fingerprint' => str_repeat('a', 64)]);
        $this->source($customerId, $userId, $mediaId, 'src-rev-2', ['source_fingerprint' => str_repeat('b', 64)]);

        $this->assertSame(2, DB::table('ai_knowledge_sources')->where('customer_id', $customerId)->count());
    }

    public function test_transcript_from_audio_and_video_slots_are_separate_registrations(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('usage');

        $this->source($customerId, $userId, $mediaId, 'src-audio', ['content_type' => 'transcript', 'usage_type' => 'audio']);
        $this->source($customerId, $userId, $mediaId, 'src-video', ['content_type' => 'transcript', 'usage_type' => 'video']);

        $this->assertSame(2, DB::table('ai_knowledge_sources')->where('customer_id', $customerId)->count());
    }

    public function test_media_source_without_media_binding_is_rejected(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('binding');

        $this->expectException(QueryException::class);
        $this->source($customerId, $userId, $mediaId, 'src-binding', ['media_file_id' => null]);
    }

    public function test_media_source_outside_read_contract_owner_context_is_rejected(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('owner');

        $this->expectException(QueryException::class);
        $this->source($customerId, $userId, $mediaId, 'src-owner', ['source_type' => 'course_version']);
    }

    public function test_a_content_type_cannot_be_registered_against_the_wrong_usage_slot(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('usage-pair');

        // Read Contract § 3 freezes the mapping. Each of these is a source the
        // Read Service could never resolve, so it must never be storable.
        $unreadable = [
            'formula-audio' => ['content_type' => 'formula', 'usage_type' => 'audio'],
            'frame-document' => ['content_type' => 'video_frame_text', 'usage_type' => 'document'],
            'region-video' => ['content_type' => 'region', 'usage_type' => 'video'],
            'text-video' => ['content_type' => 'extracted_text', 'usage_type' => 'video'],
            'transcript-document' => ['content_type' => 'transcript', 'usage_type' => 'document'],
        ];

        foreach ($unreadable as $seed => $overrides) {
            try {
                $this->source($customerId, $userId, $mediaId, "src-{$seed}", $overrides);
                $this->fail("Unreadable pair {$seed} must be rejected by the database.");
            } catch (QueryException) {
                // expected
            }
        }

        $this->assertSame(0, DB::table('ai_knowledge_sources')->where('customer_id', $customerId)->count());
    }

    public function test_every_readable_content_type_and_usage_pair_is_accepted(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('usage-pair-ok');

        $readable = [
            ['extracted_text', 'document'],
            ['region', 'document'],
            ['table', 'document'],
            ['formula', 'document'],
            ['transcript', 'audio'],
            ['transcript', 'video'],
            ['video_frame_text', 'video'],
        ];

        foreach ($readable as [$contentType, $usageType]) {
            $this->source($customerId, $userId, $mediaId, "src-ok-{$contentType}-{$usageType}", [
                'content_type' => $contentType,
                'usage_type' => $usageType,
            ]);
        }

        $this->assertSame(7, DB::table('ai_knowledge_sources')->where('customer_id', $customerId)->count());
    }

    public function test_the_same_revision_can_be_registered_again_after_its_tombstone(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('generation');

        $first = $this->source($customerId, $userId, $mediaId, 'src-gen-1');

        // Owner detaches the Media, AI retires the registration.
        DB::table('ai_knowledge_sources')->where('id', $first)->update([
            'status' => 'deleted',
            'deletion_requested_at' => now(),
            'deleted_at' => now(),
        ]);

        // Owner re-attaches exactly the same Media revision. Generation 1 is a
        // terminal tombstone and is never revived, so this must land as a new
        // registration rather than collide.
        try {
            $this->source($customerId, $userId, $mediaId, 'src-gen-collide');
            $this->fail('Re-registering at the same generation must still collide.');
        } catch (QueryException) {
            // expected
        }

        $second = $this->source($customerId, $userId, $mediaId, 'src-gen-2', ['generation' => 2]);

        $this->assertNotSame($first, $second);
        $this->assertSame('deleted', DB::table('ai_knowledge_sources')->where('id', $first)->value('status'));
        $this->assertSame('active', DB::table('ai_knowledge_sources')->where('id', $second)->value('status'));
        $this->assertSame(2, DB::table('ai_knowledge_sources')->where('customer_id', $customerId)->count());
    }

    public function test_media_provenance_cannot_point_at_a_file_that_does_not_exist(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('ghost-media');

        $this->expectException(QueryException::class);
        $this->source($customerId, $userId, $mediaId, 'src-ghost', ['media_file_id' => $mediaId + 90210]);
    }

    public function test_media_provenance_cannot_point_at_another_tenants_file(): void
    {
        [$customerA, $userA, $mediaA] = $this->tenant('media-cross-a');
        [, , $mediaB] = $this->tenant('media-cross-b');

        // Authorization still re-enters Media Read through owner context, but a
        // citation naming another tenant's file is not a citation at all.
        $this->expectException(QueryException::class);
        $this->source($customerA, $userA, $mediaA, 'src-media-cross', ['media_file_id' => $mediaB]);
    }

    public function test_media_file_cannot_be_hard_deleted_while_a_registration_cites_it(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('media-restrict');
        $this->source($customerId, $userId, $mediaId, 'src-media-restrict');

        $this->expectException(QueryException::class);
        DB::table('media_files')->where('id', $mediaId)->delete();
    }

    public function test_chunk_cannot_reference_a_source_owned_by_another_tenant(): void
    {
        [$customerA, $userA, $mediaA] = $this->tenant('cross-a');
        [$customerB, , $mediaB] = $this->tenant('cross-b');
        $sourceId = $this->source($customerA, $userA, $mediaA, 'src-cross');

        $this->expectException(QueryException::class);
        $this->chunk($customerB, $sourceId, 'chunk-cross');
    }

    public function test_a_second_active_chunk_cannot_reuse_a_sequence_number(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('sequence');
        $sourceId = $this->source($customerId, $userId, $mediaId, 'src-sequence');

        $this->chunk($customerId, $sourceId, 'chunk-seq-1', ['content' => 'first']);

        // The old key included content_hash, so a rebuild with a different
        // chunker left two live rows at sequence_no = 1.
        $this->expectException(QueryException::class);
        $this->chunk($customerId, $sourceId, 'chunk-seq-2', ['content' => 'second', 'content_hash' => 'sha256:second']);
    }

    public function test_split_parts_of_one_unit_coexist_but_a_part_index_cannot_repeat(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('split');
        $sourceId = $this->source($customerId, $userId, $mediaId, 'src-split');

        $this->chunk($customerId, $sourceId, 'chunk-part-1', ['sequence_no' => 1, 'part_index' => 1, 'char_start' => 0, 'char_end' => 100]);
        $this->chunk($customerId, $sourceId, 'chunk-part-2', ['sequence_no' => 2, 'part_index' => 2, 'char_start' => 100, 'char_end' => 180]);
        $this->assertSame(2, DB::table('ai_knowledge_chunks')->where('customer_id', $customerId)->count());

        $this->expectException(QueryException::class);
        $this->chunk($customerId, $sourceId, 'chunk-part-3', ['sequence_no' => 3, 'part_index' => 2, 'char_start' => 100, 'char_end' => 180]);
    }

    public function test_chunk_content_may_only_be_erased_once_the_row_is_a_deleted_tombstone(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('tombstone');
        $sourceId = $this->source($customerId, $userId, $mediaId, 'src-tombstone');
        $chunkId = $this->chunk($customerId, $sourceId, 'chunk-tombstone');

        try {
            DB::table('ai_knowledge_chunks')->where('id', $chunkId)->update(['content' => null]);
            $this->fail('Erasing chunk text outside the deleted tombstone state must be rejected.');
        } catch (QueryException) {
            // expected
        }

        DB::table('ai_knowledge_chunks')->where('id', $chunkId)->update([
            'status' => 'deleted',
            'deletion_requested_at' => now(),
            'deleted_at' => now(),
            'content' => null,
        ]);

        $this->assertNull(DB::table('ai_knowledge_chunks')->where('id', $chunkId)->value('content'));
    }

    public function test_embedding_status_vocabulary_and_readiness_evidence_are_enforced(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('embedding');
        $sourceId = $this->source($customerId, $userId, $mediaId, 'src-embedding');
        $chunkId = $this->chunk($customerId, $sourceId, 'chunk-embedding');
        $runId = $this->modelRun($customerId);

        try {
            $this->embedding($customerId, $chunkId, $runId, 'emb-processing', ['status' => 'processing']);
            $this->fail("The retired 'processing' status must not be accepted.");
        } catch (QueryException) {
            // expected
        }

        try {
            $this->embedding($customerId, $chunkId, $runId, 'emb-ready', ['status' => 'ready', 'embedded_at' => null]);
            $this->fail('A ready embedding without embedded_at must be rejected.');
        } catch (QueryException) {
            // expected
        }

        $this->embedding($customerId, $chunkId, $runId, 'emb-ok', ['status' => 'ready', 'embedded_at' => now()]);
        $this->assertSame(1, DB::table('ai_embeddings')->where('customer_id', $customerId)->count());
    }

    public function test_a_deleted_embedding_still_blocks_hard_deleting_its_parents(): void
    {
        [$customerId, $userId, $mediaId] = $this->tenant('barrier');
        $sourceId = $this->source($customerId, $userId, $mediaId, 'src-barrier');
        $chunkId = $this->chunk($customerId, $sourceId, 'chunk-barrier');
        $runId = $this->modelRun($customerId);
        $this->embedding($customerId, $chunkId, $runId, 'emb-barrier', [
            'status' => 'deleted',
            'deletion_requested_at' => now(),
            'deleted_at' => now(),
        ]);

        try {
            DB::table('ai_knowledge_chunks')->where('id', $chunkId)->delete();
            $this->fail('RESTRICT must keep the embedding tombstone attached to its chunk.');
        } catch (QueryException) {
            // expected
        }

        try {
            DB::table('ai_knowledge_sources')->where('id', $sourceId)->delete();
            $this->fail('RESTRICT must keep the chunk attached to its source.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(1, DB::table('ai_embeddings')->where('id', '>', 0)->where('customer_id', $customerId)->count());
    }

    public function test_blocked_and_prompt_scope_provenance_are_enforced_on_model_runs(): void
    {
        [$customerId, , $mediaId] = $this->tenant('run');

        try {
            $this->modelRun($customerId, ['run_uuid' => $this->uuid('run-blocked'), 'status' => 'blocked', 'error_code' => null]);
            $this->fail('A blocked run without a named error code must be rejected.');
        } catch (QueryException) {
            // expected
        }

        // Round 3 finding N-3: the documented form passed here because
        // `NULL IN (...)` is UNKNOWN, leaving the deferred prompt foreign key
        // with nothing to bind against.
        try {
            $this->modelRun($customerId, [
                'run_uuid' => $this->uuid('run-scope'),
                'prompt_template_id' => 900,
                'prompt_scope_customer_id' => null,
            ]);
            $this->fail('A prompt reference without an explicit scope must be rejected.');
        } catch (QueryException) {
            // expected
        }

        try {
            $this->modelRun($customerId, [
                'run_uuid' => $this->uuid('run-foreign'),
                'prompt_template_id' => 900,
                'prompt_scope_customer_id' => $customerId + 4242,
            ]);
            $this->fail('A prompt scoped to another tenant must be rejected.');
        } catch (QueryException) {
            // expected
        }

        $this->modelRun($customerId, ['run_uuid' => $this->uuid('run-global'), 'prompt_template_id' => 900, 'prompt_scope_customer_id' => 0]);
        $this->modelRun($customerId, ['run_uuid' => $this->uuid('run-tenant'), 'prompt_template_id' => 901, 'prompt_scope_customer_id' => $customerId]);
        $this->assertSame(2, DB::table('ai_model_runs')->where('customer_id', $customerId)->count());
    }

    public function test_rollback_is_refused_while_any_packet_row_survives(): void
    {
        [$customerId, , $mediaId] = $this->tenant('rollback');
        $this->modelRun($customerId);

        $migration = require database_path('migrations/2026_09_08_000100_create_ai_foundation_knowledge_tables.php');

        try {
            $migration->down();
            $this->fail('Rollback must refuse while audit-bearing rows exist.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rollback refused', $exception->getMessage());
            $this->assertStringContainsString('ai_model_runs=1', $exception->getMessage());
        }

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('ai_model_runs'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('ai_embeddings'));
    }

    // ---- fixtures -------------------------------------------------------

    private function tenant(string $slug): array
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => "AI Packet {$slug}",
            'slug' => "ai-packet-{$slug}",
            'subdomain' => "ai-packet-{$slug}",
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'customer_id' => $customerId,
            'name' => "AI Packet {$slug} Admin",
            'email' => "ai-packet-{$slug}@example.test",
            'password' => bcrypt('password'),
            'role' => 'customer_admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mediaFileId = $this->mediaFile($customerId, $userId, $slug);

        return [$customerId, $userId, $mediaFileId];
    }

    private function mediaFile(int $customerId, int $userId, string $slug): int
    {
        return DB::table('media_files')->insertGetId([
            'customer_id' => $customerId,
            'uploaded_by' => $userId,
            'file_type' => 'document',
            'mime_type' => 'application/pdf',
            'original_name' => "{$slug}.pdf",
            'display_name' => $slug,
            'extension' => 'pdf',
            'storage_disk' => 'media_local',
            'storage_bucket' => 'test-media',
            'storage_key' => "ai-packet/{$slug}.pdf",
            'checksum' => 'sha256:'.$slug,
            'file_size_bytes' => 1,
            'visibility' => 'private',
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function source(int $customerId, int $userId, int $mediaFileId, string $seed, array $overrides = []): int
    {
        return DB::table('ai_knowledge_sources')->insertGetId(array_merge([
            'customer_id' => $customerId,
            'source_uuid' => $this->uuid($seed),
            'source_type' => 'course_activity',
            'source_id' => 99,
            'media_file_id' => $mediaFileId,
            'usage_type' => 'document',
            'content_type' => 'region',
            'title' => 'Packet fixture',
            'locale' => null,
            'source_fingerprint' => str_repeat('c', 64),
            'processing_version' => 'docling-9+abc123',
            'status' => 'active',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function chunk(int $customerId, int $sourceId, string $seed, array $overrides = []): int
    {
        return DB::table('ai_knowledge_chunks')->insertGetId(array_merge([
            'customer_id' => $customerId,
            'knowledge_source_id' => $sourceId,
            'chunk_uuid' => $this->uuid($seed),
            'sequence_no' => 1,
            'content' => 'chunk text',
            'content_hash' => 'sha256:chunk',
            'part_index' => 1,
            'char_start' => 0,
            'char_end' => 10,
            'locator_type' => 'region',
            'locator_start' => '15#1',
            'locator_end' => '15#1',
            'source_role' => 'paragraph',
            'reading_order' => 41,
            'source_text_quality' => 'normal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function modelRun(int $customerId, array $overrides = []): int
    {
        return DB::table('ai_model_runs')->insertGetId(array_merge([
            'customer_id' => $customerId,
            'run_uuid' => $this->uuid('run-'.$customerId),
            'prompt_hash' => 'sha256:prompt',
            'purpose' => 'knowledge_embedding',
            'provider' => 'approved-provider',
            'model' => 'approved-model',
            'status' => 'completed',
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function embedding(int $customerId, int $chunkId, int $runId, string $seed, array $overrides = []): int
    {
        return DB::table('ai_embeddings')->insertGetId(array_merge([
            'customer_id' => $customerId,
            'knowledge_chunk_id' => $chunkId,
            'model_run_id' => $runId,
            'provider' => 'approved-provider',
            'model' => 'approved-model',
            'dimensions' => 1536,
            'vector_store' => 'qdrant',
            'vector_index' => 'lf_text_approved_model_v1',
            'vector_key' => $this->uuid($seed),
            'embedding_hash' => 'sha256:'.$seed,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function uuid(string $seed): string
    {
        $hex = substr(hash('sha256', $seed), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
