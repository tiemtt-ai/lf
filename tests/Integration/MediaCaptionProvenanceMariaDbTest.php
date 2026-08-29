<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gate M evidence cho migration `2026_08_29_000000_add_caption_transcript_provenance`.
 *
 * Owner quyet dinh 2026-08-29 (DOC-CONFLICT-0024): caption duoc dung TU transcript.
 * Cot `transcript_processing_version` ghi transcript revision nguon, vi
 * `source_fingerprint` la van tay cua binary goc nen khong doi khi transcript len
 * revision moi.
 *
 * Test doc CHECK VAT LY tu `information_schema.CHECK_CONSTRAINTS`, khong doc danh
 * sach migration: mot migration quen ADD CONSTRAINT van qua duoc phep kiem
 * inventory roi hong o row dau tien.
 */
class MediaCaptionProvenanceMariaDbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Caption provenance constraints are physical MariaDB constraints.');
        }
    }

    public function test_the_provenance_check_is_physically_present_after_migration(): void
    {
        $clauses = collect(DB::select(
            'SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['media_captions']
        ))->pluck('CHECK_CLAUSE', 'CONSTRAINT_NAME')->all();

        $this->assertArrayHasKey('chk_mc_transcript_provenance', $clauses);
        $this->assertStringContainsString('transcript_processing_version', $clauses['chk_mc_transcript_provenance']);
    }

    public function test_the_column_is_nullable_varchar_100(): void
    {
        $column = DB::selectOne(
            'SELECT DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['media_captions', 'transcript_processing_version']
        );

        $this->assertNotNull($column, 'Cot provenance phai ton tai sau migration.');
        $this->assertSame('varchar', $column->DATA_TYPE);
        $this->assertSame('YES', $column->IS_NULLABLE);
        $this->assertSame(100, (int) $column->CHARACTER_MAXIMUM_LENGTH);
    }

    public function test_a_job_produced_caption_must_declare_its_transcript_revision(): void
    {
        $mediaId = $this->fixture();
        $jobId = $this->insertJob($mediaId);

        $this->expectException(QueryException::class);
        $this->insertCaption($mediaId, ['processing_job_id' => $jobId, 'transcript_processing_version' => null]);
    }

    public function test_a_job_produced_caption_with_a_declared_revision_is_accepted(): void
    {
        $mediaId = $this->fixture();
        $jobId = $this->insertJob($mediaId);

        $this->insertCaption($mediaId, [
            'processing_job_id' => $jobId,
            'transcript_processing_version' => 'stt-v1',
        ]);

        $this->assertSame('stt-v1', DB::table('media_captions')
            ->where('media_file_id', $mediaId)->value('transcript_processing_version'));
    }

    /**
     * `processing_job_id` nullable, nen bang nay chua duoc ca caption KHONG do job
     * sinh ra. Rang buoc neo vao viec co job chu khong vao `status`, neu khong se
     * chan luon truong hop do.
     */
    public function test_a_caption_without_a_job_may_omit_the_transcript_revision(): void
    {
        $mediaId = $this->fixture();

        $this->insertCaption($mediaId, ['processing_job_id' => null, 'transcript_processing_version' => null]);

        $this->assertNull(DB::table('media_captions')
            ->where('media_file_id', $mediaId)->value('transcript_processing_version'));
    }

    private function fixture(): int
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Caption Provenance Tenant', 'slug' => 'caption-provenance',
            'subdomain' => 'caption-provenance', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'customer_id' => $customerId, 'name' => 'Caption Admin',
            'email' => 'caption-provenance@example.test', 'password' => bcrypt('password'),
            'role' => 'customer_admin', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('media_files')->insertGetId([
            'customer_id' => $customerId, 'uploaded_by' => $userId,
            'file_type' => 'video', 'mime_type' => 'video/mp4',
            'original_name' => 'lesson.mp4', 'display_name' => 'lesson.mp4', 'extension' => 'mp4',
            'storage_disk' => 'media_local', 'storage_bucket' => 'test-media',
            'storage_key' => 'caption/lesson.mp4', 'checksum' => 'sha256:caption',
            'file_size_bytes' => 1, 'visibility' => 'private', 'status' => 'ready',
            'processing_locale' => 'vi', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertJob(int $mediaId): int
    {
        $customerId = (int) DB::table('media_files')->where('id', $mediaId)->value('customer_id');

        return DB::table('media_processing_jobs')->insertGetId([
            'customer_id' => $customerId, 'media_file_id' => $mediaId,
            'job_type' => 'caption', 'status' => 'ready', 'provider' => 'fake',
            'processing_version' => 'caption-v1', 'source_fingerprint' => str_repeat('a', 64),
            'output_profile' => 'format=vtt;locale=vi', 'output_profile_hash' => str_repeat('b', 64),
            'attempt' => 1, 'idempotency_key' => 'caption-prov-1', 'correlation_id' => 'caption-prov-1',
            'completed_at' => now(), 'output_type' => 'caption', 'output_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function insertCaption(int $mediaId, array $overrides): void
    {
        $customerId = (int) DB::table('media_files')->where('id', $mediaId)->value('customer_id');

        DB::table('media_captions')->insert(array_merge([
            'customer_id' => $customerId, 'media_file_id' => $mediaId,
            'locale' => 'vi', 'caption_type' => 'vtt',
            'storage_key' => 'tenants/'.$customerId.'/captions/lesson-vi.vtt', 'status' => 'ready',
            'processing_version' => 'caption-v1', 'source_fingerprint' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }
}
