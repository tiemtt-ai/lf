<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MediaProcessingSubstrateMariaDbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Media Processing physical constraints require MariaDB.');
        }
    }

    public function test_job_check_constraints_reject_invalid_physical_state(): void
    {
        [$customerId, $userId, $mediaId] = $this->fixture();

        $this->expectException(QueryException::class);
        DB::table('media_processing_jobs')->insert([
            'customer_id' => $customerId,
            'media_file_id' => $mediaId,
            'job_type' => 'not_a_capability',
            'status' => 'pending',
            'attempt' => 1,
            'idempotency_key' => 'invalid-check-state',
            'correlation_id' => '11111111-1111-4111-8111-111111111111',
            'source_fingerprint' => str_repeat('a', 64),
            'processing_version' => 'fake-v1',
            'output_profile' => '',
            'output_profile_hash' => hash('sha256', ''),
            'provider' => 'fake',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_access_log_update_and_delete_are_physically_blocked(): void
    {
        [$customerId, $userId, $mediaId] = $this->fixture();
        $logId = DB::table('media_access_logs')->insertGetId([
            'customer_id' => $customerId,
            'media_file_id' => $mediaId,
            'user_id' => $userId,
            'action' => 'read_derived',
            'source_type' => 'ai',
            'accessed_at' => now(),
        ]);

        try {
            DB::table('media_access_logs')->where('id', $logId)->update(['action' => 'view']);
            $this->fail('MariaDB accepted an update to the append-only audit log.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        try {
            DB::table('media_access_logs')->where('id', $logId)->delete();
            $this->fail('MariaDB accepted a delete from the append-only audit log.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    /** @return array{int, int, int} */
    private function fixture(): array
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Media Constraint Tenant',
            'slug' => 'media-constraint-tenant',
            'subdomain' => 'media-constraint-tenant',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'customer_id' => $customerId,
            'name' => 'Media Constraint Admin',
            'email' => 'media-constraint@example.test',
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
            'original_name' => 'constraint.pdf',
            'display_name' => 'Constraint',
            'extension' => 'pdf',
            'storage_disk' => 'media_local',
            'storage_bucket' => 'test-media',
            'storage_key' => 'constraints/constraint.pdf',
            'checksum' => 'sha256:constraint',
            'file_size_bytes' => 1,
            'visibility' => 'private',
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$customerId, $userId, $mediaId];
    }
}
