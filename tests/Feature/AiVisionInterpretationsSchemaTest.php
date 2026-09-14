<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Physical proof for ai_vision_interpretations (database doc v1.1).
 *
 * The one-`ready`-per-slot arbiter is a generated column plus a UNIQUE index on
 * both drivers, so those tests run everywhere. CHECK constraints and foreign
 * keys exist only on MariaDB — SQLite ignores them — so those tests skip there
 * and are only evidence when this file runs against MariaDB.
 */
class AiVisionInterpretationsSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $tenant;

    private int $otherTenant;

    private int $media;

    private int $otherMedia;

    private int $run;

    private int $otherRun;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->media, $this->run] = $this->tenantFixture('vision-a');
        [$this->otherTenant, $this->otherMedia, $this->otherRun] = $this->tenantFixture('vision-b');
    }

    public function test_a_valid_document_region_interpretation_is_stored(): void
    {
        $id = $this->insert();

        $row = DB::table('ai_vision_interpretations')->find($id);
        $this->assertSame('ready', $row->status);
        $this->assertNotNull($row->active_slot);
    }

    public function test_only_one_ready_row_may_hold_a_unit_slot(): void
    {
        $this->insert();

        $this->assertRejected(fn () => $this->insert(['model_run_id' => $this->secondRun()]), 'uk_avi_active_slot');
    }

    public function test_non_ready_rows_do_not_occupy_the_slot(): void
    {
        // stale, deletion_pending and deleted leave active_slot NULL, and NULLs
        // never collide — so history accumulates while one row stays current.
        $this->insert(['status' => 'stale']);
        $this->insert(['status' => 'stale', 'model_run_id' => $this->secondRun()]);
        $this->insert(['status' => 'deletion_pending', 'deletion_requested_at' => now(), 'model_run_id' => $this->secondRun()]);
        $this->insert(['model_run_id' => $this->secondRun()]);

        $this->assertSame(1, DB::table('ai_vision_interpretations')->where('status', 'ready')->count());
        $this->assertSame(4, DB::table('ai_vision_interpretations')->count());
    }

    public function test_superseding_moves_the_old_row_to_stale_then_inserts(): void
    {
        $old = $this->insert();

        DB::transaction(function () use ($old): void {
            DB::table('ai_vision_interpretations')->where('id', $old)->update(['status' => 'stale']);
            $this->insert(['model_run_id' => $this->secondRun()]);
        });

        $this->assertSame('stale', DB::table('ai_vision_interpretations')->where('id', $old)->value('status'));
        $this->assertSame(1, DB::table('ai_vision_interpretations')->where('status', 'ready')->count());
    }

    public function test_distinct_slots_with_separator_characters_never_collide(): void
    {
        // A bare `|` join maps both of these to the same string. The length
        // prefix is what keeps two different regions from blocking each other.
        $this->insert(['processing_version' => 'v1|region|a', 'locator_start' => 'b']);
        $this->insert(['processing_version' => 'v1', 'locator_start' => 'a|region|b', 'model_run_id' => $this->secondRun()]);

        $this->assertSame(2, DB::table('ai_vision_interpretations')->where('status', 'ready')->count());
    }

    public function test_the_same_slot_in_another_tenant_is_independent(): void
    {
        $this->insert();
        $this->insert([
            'customer_id' => $this->otherTenant,
            'media_file_id' => $this->otherMedia,
            'model_run_id' => $this->otherRun,
        ]);

        $this->assertSame(2, DB::table('ai_vision_interpretations')->where('status', 'ready')->count());
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function checkViolations(): array
    {
        return [
            'video usage (F4 scope)' => [['usage_type' => 'video'], 'chk_avi_scope'],
            'frame text content (F4 scope)' => [['content_type' => 'video_frame_text'], 'chk_avi_scope'],
            'page locator (F3: Media regions are region)' => [['locator_type' => 'page'], 'chk_avi_scope'],
            'page zero' => [['page' => 0], 'chk_avi_page'],
            'failed status (F5 removed)' => [['status' => 'failed'], 'chk_avi_status'],
            'unknown owner type' => [['source_type' => 'course_version'], 'chk_avi_source_type'],
            'partial bbox' => [['bbox_width' => null], 'chk_avi_bbox'],
            'bbox outside the frame' => [['bbox_x' => '0.900000', 'bbox_width' => '0.200000'], 'chk_avi_bbox'],
            'ready without text' => [['interpretation' => null], 'chk_avi_content_retained'],
            'deleted still holding text' => [['status' => 'deleted', 'deleted_at' => '2026-09-14 00:00:00'], 'chk_avi_content_erased'],
            'deleted without completion time' => [['status' => 'deleted', 'interpretation' => null], 'chk_avi_deleted'],
            'deletion pending without request time' => [['status' => 'deletion_pending'], 'chk_avi_deletion_pending'],
        ];
    }

    /** @param array<string,mixed> $overrides */
    #[DataProvider('checkViolations')]
    public function test_check_constraints_refuse_invalid_rows(array $overrides, string $constraint): void
    {
        $this->requireMariaDb();

        $this->assertRejected(fn () => $this->insert($overrides), $constraint);
    }

    public function test_bbox_may_be_absent_when_the_source_region_has_none(): void
    {
        $this->insert(['bbox_x' => null, 'bbox_y' => null, 'bbox_width' => null, 'bbox_height' => null]);

        $this->assertSame(1, DB::table('ai_vision_interpretations')->count());
    }

    public function test_a_deleted_tombstone_erases_text_but_keeps_provenance(): void
    {
        $this->insert(['status' => 'deleted', 'interpretation' => null, 'deleted_at' => now(), 'deletion_requested_at' => now()]);

        $row = DB::table('ai_vision_interpretations')->first();
        $this->assertNull($row->interpretation);
        $this->assertNotNull($row->interpretation_hash);
        $this->assertNull($row->active_slot);
    }

    public function test_a_row_cannot_cite_another_tenants_media_file(): void
    {
        $this->requireMariaDb();

        $this->assertRejected(fn () => $this->insert(['media_file_id' => $this->otherMedia]), 'fk_avi_media_tenant');
    }

    public function test_a_row_cannot_reference_another_tenants_model_run(): void
    {
        $this->requireMariaDb();

        $this->assertRejected(fn () => $this->insert(['model_run_id' => $this->otherRun]), 'fk_avi_run_tenant');
    }

    public function test_rollback_refuses_while_rows_exist_before_any_ddl(): void
    {
        $this->insert();
        $migration = require base_path('database/migrations/2026_09_14_000100_create_ai_vision_interpretations.php');

        try {
            $migration->down();
            $this->fail('Rollback must refuse while interpretation rows exist.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('LF_AI_VISION_ROLLBACK_REFUSED', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('ai_vision_interpretations')->count());
    }

    /** @param array<string,mixed> $overrides */
    private function insert(array $overrides = []): int
    {
        return (int) DB::table('ai_vision_interpretations')->insertGetId(array_replace([
            'customer_id' => $this->tenant,
            'interpretation_uuid' => (string) Str::uuid(),
            'model_run_id' => $this->run,
            'source_type' => 'course_activity',
            'source_id' => 4242,
            'media_file_id' => $this->media,
            'usage_type' => 'document',
            'content_type' => 'region',
            'locale' => 'vi',
            'source_fingerprint' => str_repeat('f', 64),
            'processing_version' => 'docling@1',
            'locator_type' => 'region',
            'locator_start' => 'p1-r3',
            'page' => 1,
            'bbox_x' => '0.100000',
            'bbox_y' => '0.200000',
            'bbox_width' => '0.300000',
            'bbox_height' => '0.400000',
            'interpretation' => 'Biểu đồ cột cho thấy số học viên tăng qua ba quý.',
            'interpretation_hash' => hash('sha256', 'fixture'),
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function secondRun(): int
    {
        return $this->modelRun($this->tenant);
    }

    private function assertRejected(callable $write, string $constraint): void
    {
        try {
            $write();
        } catch (QueryException $exception) {
            // The rejection itself is the assertion on every driver. On MariaDB
            // the message also names the constraint that fired; SQLite names the
            // indexed columns instead, so the name is only checked there.
            $this->addToAssertionCount(1);
            if (DB::getDriverName() === 'mysql') {
                $this->assertStringContainsString($constraint, $exception->getMessage());
            }

            return;
        }

        $this->fail("Expected {$constraint} to reject the write.");
    }

    private function requireMariaDb(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraints and foreign keys are only enforced on MariaDB.');
        }
    }

    /** @return array{0:int,1:int,2:int} */
    private function tenantFixture(string $slug): array
    {
        $customer = DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = DB::table('users')->insertGetId([
            'customer_id' => $customer, 'name' => $slug, 'email' => "{$slug}@example.test",
            'password' => bcrypt('password'), 'role' => 'customer_admin', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $media = DB::table('media_files')->insertGetId([
            'customer_id' => $customer, 'uploaded_by' => $user, 'file_type' => 'document',
            'mime_type' => 'application/pdf', 'original_name' => "{$slug}.pdf", 'display_name' => $slug,
            'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_bucket' => 'test-media',
            'storage_key' => "vision/{$slug}.pdf", 'checksum' => 'sha256:'.$slug, 'file_size_bytes' => 1,
            'visibility' => 'private', 'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$customer, $media, $this->modelRun($customer)];
    }

    private function modelRun(int $customer): int
    {
        return (int) DB::table('ai_model_runs')->insertGetId([
            'customer_id' => $customer, 'run_uuid' => (string) Str::uuid(),
            'prompt_hash' => 'sha256:fixture', 'purpose' => 'vision_interpretation',
            'provider' => 'fixture-provider', 'model' => 'fixture-model',
            'status' => 'completed', 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
