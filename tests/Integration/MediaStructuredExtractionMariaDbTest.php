<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Database test plan § F of LF-Media-Structured-Extraction-Architecture-Review.
 *
 * Covers the groups that are enforced by the database itself: F.1 CHECK
 * vocabulary, F.2 scoped foreign key, F.4.5 unique coordinate, F.4.7 CASCADE,
 * and F.6 migration. F.3, F.4.1–F.4.4 and F.5 assert readiness and resource
 * behaviour that lives in an extraction runtime which does not exist yet; they
 * are deliberately absent rather than written hollow.
 */
class MediaStructuredExtractionMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private const LOCATOR_MIGRATION = 'database/migrations/2026_08_26_000000_open_extracted_text_sheet_locator.php';

    private const JOB_MIGRATION = 'database/migrations/2026_08_26_000200_open_media_processing_job_structured_identity.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Structured extraction physical constraints require MariaDB.');
        }
    }

    // ---------------------------------------------------------------- F.1 ---

    public function test_f1_3_extracted_text_locator_vocabulary_is_closed(): void
    {
        [, , $mediaId] = $this->fixture();

        $this->assertTrue($this->textInsertSucceeds($mediaId, ['locator_type' => 'page']));
        $this->assertTrue($this->textInsertSucceeds($mediaId, ['locator_type' => 'sheet', 'locator_value' => '2']));

        $this->expectException(QueryException::class);
        $this->textInsertSucceeds($mediaId, ['locator_type' => 'region', 'locator_value' => '1#1']);
    }

    public function test_f1_5_extraction_method_vocabulary_is_closed(): void
    {
        [, , $mediaId] = $this->fixture();

        foreach (['ocr', 'embedded_text', 'spreadsheet_cells'] as $index => $method) {
            $this->assertTrue($this->textInsertSucceeds($mediaId, [
                'locator_value' => (string) ($index + 1),
                'extraction_method' => $method,
                'provider' => 'local_document',
            ]));
        }

        $this->expectException(QueryException::class);
        $this->textInsertSucceeds($mediaId, ['locator_value' => '9', 'extraction_method' => 'spreadsheet']);
    }

    public function test_f1_6_region_locator_type_is_pinned_to_region(): void
    {
        [, , $mediaId] = $this->fixture();

        $this->expectException(QueryException::class);
        $this->insertRegion($mediaId, ['locator_type' => 'page']);
    }

    public function test_f1_7_and_f1_8_bbox_accepts_all_null_and_all_present(): void
    {
        [, , $mediaId] = $this->fixture();

        $this->insertRegion($mediaId, ['locator_value' => '1#1', 'ordinal' => 1, 'reading_order' => 1]);
        $this->insertRegion($mediaId, [
            'locator_value' => '1#2', 'ordinal' => 2, 'reading_order' => 2,
            'bbox_x' => 0.081, 'bbox_y' => 0.412, 'bbox_width' => 0.838, 'bbox_height' => 0.221,
        ]);

        $this->assertSame(2, DB::table('media_extracted_regions')->count());
    }

    /**
     * F.1.9 — the regression the old `bbox_x IS NULL OR (...)` form let through:
     * a half-filled coordinate set evaluates to UNKNOWN, and a CHECK only fails
     * on FALSE.
     */
    public function test_f1_9_partial_bbox_is_rejected(): void
    {
        [, , $mediaId] = $this->fixture();

        $this->expectException(QueryException::class);
        $this->insertRegion($mediaId, ['bbox_x' => 0.1, 'bbox_y' => null, 'bbox_width' => 0.2, 'bbox_height' => 0.2]);
    }

    public function test_f1_10_to_f1_12_bbox_bounds_are_enforced(): void
    {
        [, , $mediaId] = $this->fixture();
        $cases = [
            'overflow' => ['bbox_x' => 0.9, 'bbox_y' => 0.1, 'bbox_width' => 0.2, 'bbox_height' => 0.1],
            'zero width' => ['bbox_x' => 0.1, 'bbox_y' => 0.1, 'bbox_width' => 0, 'bbox_height' => 0.1],
            'negative origin' => ['bbox_x' => -0.1, 'bbox_y' => 0.1, 'bbox_width' => 0.2, 'bbox_height' => 0.1],
        ];

        foreach ($cases as $label => $bbox) {
            try {
                $this->insertRegion($mediaId, $bbox + ['locator_value' => '1#'.random_int(100, 999)]);
                $this->fail("bbox case rejected by contract was accepted: {$label}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_f1_13_and_f1_14_provider_and_figure_rules(): void
    {
        [, , $mediaId] = $this->fixture();

        try {
            $this->insertRegion($mediaId, ['extraction_method' => 'ocr', 'provider' => null]);
            $this->fail('ocr region without a provider was accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(QueryException::class);
        $this->insertRegion($mediaId, ['role' => 'figure', 'text' => 'a figure carries no text']);
    }

    // ---------------------------------------------------------------- F.2 ---

    public function test_f2_scoped_foreign_key_accepts_matching_scope_and_rejects_every_drift(): void
    {
        [$customerId, , $mediaId] = $this->fixture();
        $regionId = $this->insertRegion($mediaId, ['role' => 'table']);
        $otherMediaId = $this->insertMediaFile($customerId, 'other.pdf');

        $this->assertTrue($this->tableInsertSucceeds($mediaId, $regionId, []), 'matching scope must be accepted');

        $drifts = [
            'different media file' => ['media_file_id' => $otherMediaId],
            'different locale' => ['locale' => 'ko'],
            'different processing version' => ['processing_version' => 'local-document-v9'],
        ];

        foreach ($drifts as $label => $override) {
            try {
                $this->tableInsertSucceeds($mediaId, $regionId, $override + ['locator_value' => '1#'.random_int(100, 999)]);
                $this->fail("scoped FK accepted a mismatched reference: {$label}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_f2_5_scoped_foreign_key_rejects_cross_tenant_region(): void
    {
        [, , $mediaId] = $this->fixture();
        $regionId = $this->insertRegion($mediaId, ['role' => 'table']);
        [$otherCustomerId, , $otherMediaId] = $this->fixture('other');

        $this->expectException(QueryException::class);
        $this->tableInsertSucceeds($otherMediaId, $regionId, [
            'customer_id' => $otherCustomerId,
            'locator_value' => '1#cross-tenant',
        ]);
    }

    public function test_f2_6_and_f2_7_anchor_check_keeps_region_and_sheet_apart(): void
    {
        [, , $mediaId] = $this->fixture();
        $regionId = $this->insertRegion($mediaId, ['role' => 'table']);

        try {
            $this->tableInsertSucceeds($mediaId, $regionId, ['locator_type' => 'sheet', 'locator_value' => '1']);
            $this->fail('a sheet-anchored table carrying a region_id was accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(QueryException::class);
        $this->tableInsertSucceeds($mediaId, null, ['locator_type' => 'region', 'locator_value' => '1#5']);
    }

    // -------------------------------------------------------------- F.4.5 ---

    public function test_f4_5_duplicate_cell_coordinate_is_rejected(): void
    {
        [$customerId, , $mediaId] = $this->fixture();
        $tableId = $this->insertTableWithRegion($mediaId);

        $this->insertCell($customerId, $tableId, 1, 1);

        $this->expectException(QueryException::class);
        $this->insertCell($customerId, $tableId, 1, 1);
    }

    // -------------------------------------------------------------- F.4.7 ---

    public function test_f4_7_deleting_a_table_cascades_its_cells_atomically(): void
    {
        [$customerId, , $mediaId] = $this->fixture();
        $tableId = $this->insertTableWithRegion($mediaId);
        foreach ([[1, 1], [1, 2], [2, 1]] as [$row, $column]) {
            $this->insertCell($customerId, $tableId, $row, $column);
        }
        $this->assertSame(3, DB::table('media_table_cells')->count());

        DB::table('media_extracted_tables')->where('id', $tableId)->delete();

        $this->assertSame(0, DB::table('media_table_cells')->count(), 'cells must not survive their parent table');
    }

    // ---------------------------------------------------------------- F.6 ---

    /**
     * F.6.1 — inspect the physical constraint, not the migration inventory. A
     * migration that altered `locator_type` and forgot `extraction_method` would
     * still pass an inventory check and fail on the first spreadsheet row.
     */
    public function test_f6_1_both_checks_are_physically_present_after_migration(): void
    {
        $clauses = $this->checkClauses();

        $this->assertArrayHasKey('chk_met_locator', $clauses);
        $this->assertArrayHasKey('chk_met_method', $clauses);
        $this->assertStringContainsString('sheet', $clauses['chk_met_locator']);
        $this->assertStringContainsString('spreadsheet_cells', $clauses['chk_met_method']);
    }

    public function test_f6_2_pre_migration_revisions_remain_readable(): void
    {
        [, , $mediaId] = $this->fixture();
        $this->textInsertSucceeds($mediaId, ['locator_type' => 'page', 'extraction_method' => 'embedded_text']);

        $row = DB::table('media_extracted_texts')->where('media_file_id', $mediaId)->first();

        $this->assertSame('page', $row->locator_type);
        $this->assertSame('embedded_text', $row->extraction_method);
    }

    public function test_f6_3_rollback_restores_the_narrow_checks_when_nothing_blocks(): void
    {
        [, , $mediaId] = $this->fixture();

        $this->locatorMigration()->down();

        $clauses = $this->checkClauses();
        $this->assertStringNotContainsString('sheet', $clauses['chk_met_locator']);
        $this->assertStringNotContainsString('spreadsheet_cells', $clauses['chk_met_method']);

        $this->expectException(QueryException::class);
        $this->textInsertSucceeds($mediaId, ['locator_type' => 'sheet', 'locator_value' => '1']);
    }

    /**
     * F.6.4 — the case that damages data if it is ever made to "succeed".
     * After the refusal we assert four things, not just the exception.
     */
    public function test_f6_4_rollback_is_fail_closed_and_changes_nothing(): void
    {
        [, , $mediaId] = $this->fixture();
        $this->textInsertSucceeds($mediaId, [
            'locator_type' => 'sheet', 'locator_value' => '2', 'extraction_method' => 'spreadsheet_cells',
            'provider' => 'local_document',
        ]);
        $ledgerBefore = DB::table('migrations')->count();

        try {
            $this->locatorMigration()->down();
            $this->fail('rollback succeeded while sheet rows existed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rollback refused', $exception->getMessage());
        }

        // 1. the blocking rows are untouched
        $row = DB::table('media_extracted_texts')->where('media_file_id', $mediaId)->first();
        $this->assertSame('sheet', $row->locator_type);
        $this->assertSame('spreadsheet_cells', $row->extraction_method);
        $this->assertSame('2', $row->locator_value);

        // 2. the widened CHECKs are still in force
        $clauses = $this->checkClauses();
        $this->assertStringContainsString('sheet', $clauses['chk_met_locator']);
        $this->assertStringContainsString('spreadsheet_cells', $clauses['chk_met_method']);

        // 3. the migration ledger was not lowered
        $this->assertSame($ledgerBefore, DB::table('migrations')->count());

        // 4. no partial DDL: a sheet row still inserts, an invalid one still fails
        $this->assertTrue($this->textInsertSucceeds($mediaId, ['locator_type' => 'sheet', 'locator_value' => '3']));
        try {
            $this->textInsertSucceeds($mediaId, ['locator_type' => 'slide', 'locator_value' => '4']);
            $this->fail('an invalid locator_type was accepted after the refused rollback.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_f6_5_migrate_rollback_and_migrate_again_preserves_existing_data(): void
    {
        [, , $mediaId] = $this->fixture();
        $this->textInsertSucceeds($mediaId, [
            'locator_type' => 'page',
            'locator_value' => '7',
            'extraction_method' => 'embedded_text',
        ]);

        $migration = $this->locatorMigration();
        $migration->down();
        $migration->up();

        $row = DB::table('media_extracted_texts')
            ->where('media_file_id', $mediaId)
            ->where('locator_value', '7')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('page', $row->locator_type);
        $this->assertSame('embedded_text', $row->extraction_method);

        $clauses = $this->checkClauses();
        $this->assertStringContainsString('sheet', $clauses['chk_met_locator']);
        $this->assertStringContainsString('spreadsheet_cells', $clauses['chk_met_method']);
    }

    // ---------------------------------------------------------------- F.8 ---

    public function test_f8_structured_job_and_output_vocabularies_are_physically_closed(): void
    {
        [$customerId, $userId, $mediaId] = $this->fixture();

        foreach (['extracted_region', 'extracted_table'] as $index => $outputType) {
            $this->insertProcessingJob($customerId, $userId, $mediaId, [
                'idempotency_key' => 'structured-'.$index,
                'output_profile_hash' => str_repeat((string) ($index + 1), 64),
                'output_type' => $outputType,
                'output_id' => $index + 1,
            ]);
        }
        $this->assertSame(2, DB::table('media_processing_jobs')->where('job_type', 'structured_extraction')->count());

        try {
            $this->insertProcessingJob($customerId, $userId, $mediaId, [
                'idempotency_key' => 'structured-typo', 'job_type' => 'structured_extractions',
                'output_profile_hash' => str_repeat('3', 64),
            ]);
            $this->fail('invalid structured job vocabulary was accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(QueryException::class);
        $this->insertProcessingJob($customerId, $userId, $mediaId, [
            'idempotency_key' => 'output-typo', 'output_type' => 'extracted_regions', 'output_id' => 9,
            'output_profile_hash' => str_repeat('4', 64),
        ]);
    }

    public function test_f8_all_four_drifted_checks_exist_in_information_schema(): void
    {
        $clauses = collect(DB::select(
            'SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['media_processing_jobs']
        ))->pluck('CHECK_CLAUSE', 'CONSTRAINT_NAME')->all();

        foreach (['chk_mpj_output_type', 'chk_mpj_virus_output', 'chk_mpj_completed_order', 'chk_mpj_billable_pair'] as $name) {
            $this->assertArrayHasKey($name, $clauses);
        }
        $this->assertStringContainsString('structured_extraction', $clauses['chk_mpj_job_type']);
        $this->assertStringContainsString('extracted_region', $clauses['chk_mpj_output_type']);
        $this->assertStringContainsString('extracted_table', $clauses['chk_mpj_output_type']);
    }

    public function test_f8_rollback_is_fail_closed_when_structured_history_exists(): void
    {
        [$customerId, $userId, $mediaId] = $this->fixture();
        $jobId = $this->insertProcessingJob($customerId, $userId, $mediaId, [
            'idempotency_key' => 'structured-rollback', 'output_type' => 'extracted_region', 'output_id' => 1,
        ]);

        try {
            $this->jobMigration()->down();
            $this->fail('job identity rollback succeeded with structured history.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rollback refused', $exception->getMessage());
        }

        $this->assertSame('structured_extraction', DB::table('media_processing_jobs')->where('id', $jobId)->value('job_type'));
        $this->assertArrayHasKey('chk_mpj_output_type', collect(DB::select(
            'SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['media_processing_jobs']
        ))->pluck('CHECK_CLAUSE', 'CONSTRAINT_NAME')->all());
    }

    // ------------------------------------------------------------ helpers ---

    private function locatorMigration(): object
    {
        return require base_path(self::LOCATOR_MIGRATION);
    }

    private function jobMigration(): object
    {
        return require base_path(self::JOB_MIGRATION);
    }

    /** @param array<string, mixed> $overrides */
    private function insertProcessingJob(int $customerId, int $userId, int $mediaId, array $overrides = []): int
    {
        return DB::table('media_processing_jobs')->insertGetId(array_merge([
            'customer_id' => $customerId,
            'media_file_id' => $mediaId,
            'job_type' => 'structured_extraction',
            'status' => 'ready',
            'attempt' => 1,
            'idempotency_key' => 'structured-'.random_int(1000, 9999),
            'correlation_id' => 'structured-test',
            'source_fingerprint' => str_repeat('b', 64),
            'processing_version' => 'structured-v1',
            'output_profile' => 'locale=vi;structure=layout',
            'output_profile_hash' => str_repeat('c', 64),
            'provider' => 'fake',
            'output_type' => 'extracted_region',
            'output_id' => 1,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** @return array<string, string> */
    private function checkClauses(): array
    {
        return collect(DB::select(
            'SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['media_extracted_texts']
        ))->pluck('CHECK_CLAUSE', 'CONSTRAINT_NAME')->all();
    }

    /** @param array<string, mixed> $overrides */
    private function textInsertSucceeds(int $mediaId, array $overrides = []): bool
    {
        DB::table('media_extracted_texts')->insert(array_merge([
            'customer_id' => DB::table('media_files')->where('id', $mediaId)->value('customer_id'),
            'media_file_id' => $mediaId,
            'locale' => 'vi',
            'locator_type' => 'page',
            'locator_value' => (string) random_int(1000, 9999),
            'sequence' => random_int(1, 90),
            'text' => 'unit',
            'extraction_method' => 'embedded_text',
            'processing_version' => 'local-document-v2',
            'source_fingerprint' => str_repeat('a', 64),
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return true;
    }

    /** @param array<string, mixed> $overrides */
    private function insertRegion(int $mediaId, array $overrides = []): int
    {
        return DB::table('media_extracted_regions')->insertGetId(array_merge([
            'customer_id' => DB::table('media_files')->where('id', $mediaId)->value('customer_id'),
            'media_file_id' => $mediaId,
            'locale' => 'vi',
            'locator_type' => 'region',
            'locator_value' => '1#'.random_int(1, 999),
            'page' => 1,
            'ordinal' => random_int(1, 999),
            'reading_order' => random_int(1, 9999),
            'role' => 'paragraph',
            'extraction_method' => 'embedded_text',
            'processing_version' => 'local-document-v2',
            'source_fingerprint' => str_repeat('a', 64),
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function tableInsertSucceeds(int $mediaId, ?int $regionId, array $overrides): bool
    {
        DB::table('media_extracted_tables')->insert(array_merge([
            'customer_id' => DB::table('media_files')->where('id', $mediaId)->value('customer_id'),
            'media_file_id' => $mediaId,
            'region_id' => $regionId,
            'locale' => 'vi',
            'locator_type' => 'region',
            'locator_value' => '1#'.random_int(1, 999),
            'sequence' => random_int(1, 9999),
            'row_count' => 3,
            'column_count' => 2,
            'has_header' => 1,
            'extraction_method' => 'embedded_text',
            'processing_version' => 'local-document-v2',
            'source_fingerprint' => str_repeat('a', 64),
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return true;
    }

    private function insertTableWithRegion(int $mediaId): int
    {
        $regionId = $this->insertRegion($mediaId, ['role' => 'table']);
        $this->tableInsertSucceeds($mediaId, $regionId, []);

        return (int) DB::table('media_extracted_tables')->where('region_id', $regionId)->value('id');
    }

    private function insertCell(int $customerId, int $tableId, int $row, int $column): void
    {
        DB::table('media_table_cells')->insert([
            'customer_id' => $customerId,
            'extracted_table_id' => $tableId,
            'row_index' => $row,
            'column_index' => $column,
            'row_span' => 1,
            'column_span' => 1,
            'is_header' => 0,
            'text' => 'cell',
            'char_count' => 4,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMediaFile(int $customerId, string $name): int
    {
        return DB::table('media_files')->insertGetId([
            'customer_id' => $customerId,
            'uploaded_by' => DB::table('users')->where('customer_id', $customerId)->value('id'),
            'file_type' => 'document',
            'mime_type' => 'application/pdf',
            'original_name' => $name,
            'display_name' => $name,
            'extension' => 'pdf',
            'storage_disk' => 'media_local',
            'storage_bucket' => 'test-media',
            'storage_key' => 'structured/'.$name,
            'checksum' => 'sha256:'.$name,
            'file_size_bytes' => 1,
            'visibility' => 'private',
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function fixture(string $suffix = 'primary'): array
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Structured Extraction Tenant '.$suffix,
            'slug' => 'structured-extraction-tenant-'.$suffix,
            'subdomain' => 'structured-extraction-tenant-'.$suffix,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userId = DB::table('users')->insertGetId([
            'customer_id' => $customerId,
            'name' => 'Structured Extraction Admin',
            'email' => 'structured-extraction-'.$suffix.'@example.test',
            'password' => bcrypt('password'),
            'role' => 'customer_admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$customerId, $userId, $this->insertMediaFile($customerId, 'structured.pdf')];
    }
}
