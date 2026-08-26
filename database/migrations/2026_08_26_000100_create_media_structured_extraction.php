<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0019 § D2. Structured extraction is a new content type, not new columns on
 * `media_extracted_texts`.
 *
 * Revision identity lives on the owning row per ADR-0019 Amendment v1.1:
 * regions and tables carry `processing_version` / `source_fingerprint` / `status`;
 * cells inherit all three from their parent table and cannot be archived alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createRegions();
        $this->createTables();
        $this->createCells();
        $this->addMariaDbChecks();
    }

    private function createRegions(): void
    {
        Schema::create('media_extracted_regions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->unsignedBigInteger('processing_job_id')->nullable();
            $table->string('locale', 20);
            $table->string('locator_type', 20);
            $table->string('locator_value', 50);
            $table->unsignedInteger('page');
            $table->unsignedInteger('ordinal');
            $table->unsignedInteger('reading_order');
            $table->string('role', 30);
            $table->decimal('bbox_x', 9, 6)->nullable();
            $table->decimal('bbox_y', 9, 6)->nullable();
            $table->decimal('bbox_width', 9, 6)->nullable();
            $table->decimal('bbox_height', 9, 6)->nullable();
            $table->longText('text')->nullable();
            $table->unsignedInteger('char_count')->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('extraction_method', 50);
            $table->string('provider', 100)->nullable();
            $table->string('processing_version', 100);
            $table->char('source_fingerprint', 64);
            $table->string('status', 50)->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamps(6);

            $table->unique(['customer_id', 'media_file_id', 'locale', 'locator_type', 'locator_value', 'processing_version'], 'uk_mer_revision_locator');
            $table->unique(['id', 'customer_id'], 'uk_mer_tenant_identity');
            // Scope key: lets media_extracted_tables reference a region by the whole
            // revision scope, not merely by tenant. See ADR-0019 review finding F-3.
            $table->unique(['id', 'customer_id', 'media_file_id', 'locale', 'processing_version'], 'uk_mer_scope_identity');
            $table->unique(['customer_id', 'media_file_id', 'locale', 'processing_version', 'reading_order'], 'uk_mer_reading_order');
            $table->index(['customer_id', 'media_file_id', 'locale', 'page', 'ordinal'], 'idx_mer_page_order');
            $table->index(['customer_id', 'media_file_id', 'status'], 'idx_mer_file_status');
            $table->index(['customer_id', 'source_fingerprint'], 'idx_mer_fingerprint');
            $table->index(['customer_id', 'processing_job_id'], 'idx_mer_job');
            $table->index(['customer_id', 'media_file_id', 'role'], 'idx_mer_role');
            $table->foreign(['media_file_id', 'customer_id'], 'fk_mer_media_tenant')->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
            $table->foreign(['processing_job_id', 'customer_id'], 'fk_mer_job_tenant')->references(['id', 'customer_id'])->on('media_processing_jobs')->restrictOnDelete();
        });
    }

    private function createTables(): void
    {
        Schema::create('media_extracted_tables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->unsignedBigInteger('processing_job_id')->nullable();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->string('locale', 20);
            $table->string('locator_type', 20);
            $table->string('locator_value', 50);
            $table->unsignedInteger('sequence');
            $table->text('title')->nullable();
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('column_count');
            $table->boolean('has_header')->default(false);
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('extraction_method', 50);
            $table->string('provider', 100)->nullable();
            $table->string('processing_version', 100);
            $table->char('source_fingerprint', 64);
            $table->string('status', 50)->default('pending');
            $table->json('metadata')->nullable();
            $table->timestamps(6);

            $table->unique(['customer_id', 'media_file_id', 'locale', 'locator_type', 'locator_value', 'processing_version'], 'uk_met_tbl_revision_locator');
            $table->unique(['id', 'customer_id'], 'uk_met_tbl_tenant_identity');
            $table->unique(['customer_id', 'media_file_id', 'locale', 'processing_version', 'sequence'], 'uk_met_tbl_sequence');
            $table->index(['customer_id', 'media_file_id', 'locale', 'sequence'], 'idx_met_tbl_read_order');
            $table->index(['customer_id', 'media_file_id', 'status'], 'idx_met_tbl_file_status');
            $table->index(['customer_id', 'source_fingerprint'], 'idx_met_tbl_fingerprint');
            $table->index(['customer_id', 'processing_job_id'], 'idx_met_tbl_job');
            $table->index(['customer_id', 'region_id'], 'idx_met_tbl_region');
            $table->foreign(['media_file_id', 'customer_id'], 'fk_met_tbl_media_tenant')->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
            $table->foreign(['processing_job_id', 'customer_id'], 'fk_met_tbl_job_tenant')->references(['id', 'customer_id'])->on('media_processing_jobs')->restrictOnDelete();
            $table->foreign(['region_id', 'customer_id', 'media_file_id', 'locale', 'processing_version'], 'fk_met_tbl_region_scope')
                ->references(['id', 'customer_id', 'media_file_id', 'locale', 'processing_version'])
                ->on('media_extracted_regions')->restrictOnDelete();
        });
    }

    private function createCells(): void
    {
        Schema::create('media_table_cells', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('extracted_table_id');
            $table->unsignedInteger('row_index');
            $table->unsignedInteger('column_index');
            $table->unsignedInteger('row_span')->default(1);
            $table->unsignedInteger('column_span')->default(1);
            $table->boolean('is_header')->default(false);
            $table->longText('text')->nullable();
            $table->unsignedInteger('char_count')->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);

            $table->unique(['customer_id', 'extracted_table_id', 'row_index', 'column_index'], 'uk_mtc_coordinate');
            $table->unique(['id', 'customer_id'], 'uk_mtc_tenant_identity');
            $table->index(['customer_id', 'extracted_table_id', 'row_index'], 'idx_mtc_row');
            $table->index(['customer_id', 'extracted_table_id', 'is_header'], 'idx_mtc_header');
            // CASCADE is the deliberate exception to the domain's RESTRICT default:
            // a cell has no identity apart from its parent, and ADR-0018 requires a
            // retention purge to be atomic rather than a multi-step manual sweep.
            $table->foreign(['extracted_table_id', 'customer_id'], 'fk_mtc_table_tenant')
                ->references(['id', 'customer_id'])->on('media_extracted_tables')->cascadeOnDelete();
        });
    }

    private function addMariaDbChecks(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach ([
            'ALTER TABLE media_extracted_regions'
                ." ADD CONSTRAINT chk_mer_status CHECK (status IN ('pending','processing','ready','failed','archived')),"
                ." ADD CONSTRAINT chk_mer_locator CHECK (locator_type = 'region'),"
                ." ADD CONSTRAINT chk_mer_role CHECK (role IN ('paragraph','heading','list','table','figure','caption','header','footer','other')),"
                ." ADD CONSTRAINT chk_mer_method CHECK (extraction_method IN ('ocr','embedded_text')),"
                .' ADD CONSTRAINT chk_mer_page CHECK (page >= 1),'
                .' ADD CONSTRAINT chk_mer_ordinal CHECK (ordinal >= 1),'
                .' ADD CONSTRAINT chk_mer_reading_order CHECK (reading_order >= 1),'
                .' ADD CONSTRAINT chk_mer_confidence CHECK (confidence_score IS NULL OR confidence_score BETWEEN 0 AND 100),'
                ." ADD CONSTRAINT chk_mer_ocr_provider CHECK (extraction_method <> 'ocr' OR provider IS NOT NULL),"
                ." ADD CONSTRAINT chk_mer_figure_text CHECK (role <> 'figure' OR text IS NULL),"
                .' ADD CONSTRAINT chk_mer_bbox CHECK ('
                .'(bbox_x IS NULL AND bbox_y IS NULL AND bbox_width IS NULL AND bbox_height IS NULL)'
                .' OR (bbox_x IS NOT NULL AND bbox_y IS NOT NULL AND bbox_width IS NOT NULL AND bbox_height IS NOT NULL'
                .' AND bbox_x >= 0 AND bbox_y >= 0 AND bbox_width > 0 AND bbox_height > 0'
                .' AND bbox_x + bbox_width <= 1 AND bbox_y + bbox_height <= 1))',
            'ALTER TABLE media_extracted_tables'
                ." ADD CONSTRAINT chk_mtt_status CHECK (status IN ('pending','processing','ready','failed','archived')),"
                ." ADD CONSTRAINT chk_mtt_locator CHECK (locator_type IN ('region','sheet')),"
                ." ADD CONSTRAINT chk_mtt_method CHECK (extraction_method IN ('ocr','embedded_text','spreadsheet_cells')),"
                .' ADD CONSTRAINT chk_mtt_rows CHECK (row_count >= 1),'
                .' ADD CONSTRAINT chk_mtt_columns CHECK (column_count >= 1),'
                .' ADD CONSTRAINT chk_mtt_sequence CHECK (sequence >= 1),'
                .' ADD CONSTRAINT chk_mtt_confidence CHECK (confidence_score IS NULL OR confidence_score BETWEEN 0 AND 100),'
                .' ADD CONSTRAINT chk_mtt_anchor CHECK ('
                ."(locator_type = 'region' AND region_id IS NOT NULL)"
                ." OR (locator_type = 'sheet' AND region_id IS NULL))",
            'ALTER TABLE media_table_cells'
                .' ADD CONSTRAINT chk_mtc_row CHECK (row_index >= 1),'
                .' ADD CONSTRAINT chk_mtc_column CHECK (column_index >= 1),'
                .' ADD CONSTRAINT chk_mtc_row_span CHECK (row_span >= 1),'
                .' ADD CONSTRAINT chk_mtc_column_span CHECK (column_span >= 1),'
                .' ADD CONSTRAINT chk_mtc_confidence CHECK (confidence_score IS NULL OR confidence_score BETWEEN 0 AND 100)',
        ] as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        foreach (['media_table_cells', 'media_extracted_tables', 'media_extracted_regions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
