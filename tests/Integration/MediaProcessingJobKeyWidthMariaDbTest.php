<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gate M evidence cho migration `2026_08_30_000000_widen_processing_job_idempotency_key`.
 *
 * `idempotency_key` = job_type : media_file_id : source_fingerprint :
 * processing_version : output_profile_hash : attempt.
 *
 * Amendment Record 2.19 § 1 buoc `processing_version` cua video STT chua ca
 * canonical ffmpeg extraction profile. Key vi the dai 225 ky tu — vuot
 * VARCHAR(191) va lam moi video STT job that bai o buoc insert.
 *
 * Test doc do rong VAT LY tu `information_schema`, khong doc danh sach migration.
 */
class MediaProcessingJobKeyWidthMariaDbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Column width is a physical MariaDB constraint.');
        }
    }

    /**
     * Kiem BIEN cua schema, khong kiem mot key dang co.
     *
     * Ban truoc cua test nay chi khang dinh key E2E dai 225 lot vao 255 — do mot
     * truong hop roi goi la bang chung. Bien that duoc tinh tu do rong cot.
     */
    public function test_the_column_covers_the_longest_key_the_schema_allows(): void
    {
        $columns = collect(DB::select(
            'SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH AS len, NUMERIC_PRECISION AS digits'
            .' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['media_processing_jobs']
        ))->keyBy('COLUMN_NAME');

        $upperBound = (int) $columns['job_type']->len
            + strlen('18446744073709551615')        // media_file_id bigint unsigned
            + (int) $columns['source_fingerprint']->len
            + (int) $columns['processing_version']->len
            + (int) $columns['output_profile_hash']->len
            + strlen((string) 4294967295)           // attempt int unsigned
            + 5;                                    // dau phan cach

        $this->assertLessThanOrEqual(
            (int) $columns['idempotency_key']->len,
            $upperBound,
            "Key dai nhat schema cho phep la {$upperBound} ky tu; cot phai chua duoc."
        );
    }

    public function test_a_maximum_length_key_can_actually_be_stored(): void
    {
        $length = (int) DB::selectOne(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['media_processing_jobs', 'idempotency_key']
        )->len;

        [$customerId, $mediaId] = $this->fixture();

        // Do rong cot khong chung minh duoc gi neu chua that su ghi mot key dai
        // toi bien vao do.
        DB::table('media_processing_jobs')->insert([
            'customer_id' => $customerId, 'media_file_id' => $mediaId, 'job_type' => 'speech_to_text',
            'status' => 'pending', 'attempt' => 1, 'provider' => 'faster_whisper_local',
            'idempotency_key' => str_repeat('k', $length),
            'correlation_id' => 'max-key-1',
            'source_fingerprint' => str_repeat('a', 64),
            'processing_version' => str_repeat('v', 100),
            'output_profile' => 'diarization=off;locale=vi',
            'output_profile_hash' => str_repeat('b', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame($length, strlen(
            (string) DB::table('media_processing_jobs')->where('correlation_id', 'max-key-1')->value('idempotency_key')
        ), 'Key dai toi bien phai duoc luu nguyen ven, khong bi cat cut.');
    }

    public function test_the_unique_index_still_covers_the_whole_key(): void
    {
        // Neu index bi rut ngan thanh prefix, hai chain khac nhau chi khac o duoi
        // se trung khoa — te hon la vuot do rong.
        $row = DB::selectOne(
            'SELECT SUB_PART FROM information_schema.STATISTICS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            .' AND INDEX_NAME = ?',
            ['media_processing_jobs', 'idempotency_key', 'uk_mpj_idempotency']
        );

        $this->assertNotNull($row, 'UNIQUE index tren idempotency_key phai ton tai.');
        $this->assertNull($row->SUB_PART, 'Index phai phu ca cot, khong phai prefix.');
    }

    /** @return array{0: int, 1: int} */
    private function fixture(): array
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Key Width Tenant', 'slug' => 'key-width', 'subdomain' => 'key-width',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'customer_id' => $customerId, 'name' => 'Key Width Admin',
            'email' => 'key-width@example.test', 'password' => bcrypt('password'),
            'role' => 'customer_admin', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mediaId = DB::table('media_files')->insertGetId([
            'customer_id' => $customerId, 'uploaded_by' => $userId,
            'file_type' => 'video', 'mime_type' => 'video/mp4',
            'original_name' => 'lesson.mp4', 'display_name' => 'lesson.mp4', 'extension' => 'mp4',
            'storage_disk' => 'media_local', 'storage_bucket' => 'test-media',
            'storage_key' => 'key-width/lesson.mp4', 'checksum' => 'sha256:key-width',
            'file_size_bytes' => 1, 'visibility' => 'private', 'status' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$customerId, $mediaId];
    }
}
