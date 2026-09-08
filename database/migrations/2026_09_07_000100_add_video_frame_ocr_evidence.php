<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_video_frame_texts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->unsignedBigInteger('processing_job_id');
            $table->string('locale', 20);
            $table->string('detected_locale', 20)->nullable();
            $table->char('script', 4)->nullable();
            $table->string('locator_type', 20)->default('timespan');
            $table->string('locator_value', 50);
            $table->unsignedInteger('reading_order');
            $table->decimal('bbox_x', 9, 6);
            $table->decimal('bbox_y', 9, 6);
            $table->decimal('bbox_width', 9, 6);
            $table->decimal('bbox_height', 9, 6);
            $table->unsignedInteger('frame_width');
            $table->unsignedInteger('frame_height');
            $table->longText('text');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('provider', 100)->nullable();
            $table->string('processing_version', 100);
            $table->char('source_fingerprint', 64);
            $table->string('status', 50)->default('ready');
            $table->json('metadata')->nullable();
            $table->timestamps(6);

            $table->unique(['id', 'customer_id'], 'uk_mvft_tenant_identity');
            $table->unique(['customer_id', 'media_file_id', 'locator_value', 'reading_order', 'processing_version'], 'uk_mvft_revision_locator');
            $table->index(['customer_id', 'media_file_id', 'status'], 'idx_mvft_file_status');
            $table->index(['customer_id', 'processing_job_id'], 'idx_mvft_job');
            $table->index(['customer_id', 'source_fingerprint'], 'idx_mvft_fingerprint');
            $table->foreign(['media_file_id', 'customer_id'], 'fk_mvft_media_tenant')
                ->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
            $table->foreign(['processing_job_id', 'customer_id'], 'fk_mvft_job_tenant')
                ->references(['id', 'customer_id'])->on('media_processing_jobs')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE media_video_frame_texts ADD CONSTRAINT chk_mvft_status CHECK (status IN ('ready','archived')), ADD CONSTRAINT chk_mvft_locator CHECK (locator_type = 'timespan'), ADD CONSTRAINT chk_mvft_confidence CHECK (confidence_score IS NULL OR confidence_score BETWEEN 0 AND 100), ADD CONSTRAINT chk_mvft_bbox CHECK (bbox_x >= 0 AND bbox_y >= 0 AND bbox_width > 0 AND bbox_height > 0 AND bbox_x + bbox_width <= 1 AND bbox_y + bbox_height <= 1), ADD CONSTRAINT chk_mvft_frame CHECK (frame_width > 0 AND frame_height > 0), ADD CONSTRAINT chk_mvft_text CHECK (CHAR_LENGTH(TRIM(text)) > 0)");
            DB::statement('ALTER TABLE media_processing_jobs DROP CONSTRAINT chk_mpj_job_type');
            DB::statement("ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_job_type CHECK (job_type IN ('transcode','thumbnail','ocr','speech_to_text','caption','virus_scan','compress','structured_extraction','frame_ocr'))");
            DB::statement('ALTER TABLE media_processing_jobs DROP CONSTRAINT chk_mpj_output_type');
            DB::statement("ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_output_type CHECK (output_type IS NULL OR output_type IN ('transcript','caption','extracted_text','variant','extracted_region','extracted_table','video_frame_text'))");
        }
    }

    public function down(): void
    {
        $evidence = DB::table('media_video_frame_texts')->count();

        if ($evidence > 0) {
            throw new RuntimeException(
                "Rollback refused: {$evidence} video frame OCR evidence row(s) must be retained. "
                .'Delete the owning Media revisions deliberately through the canonical Media lifecycle '
                .'before rolling back; archived evidence remains citation-bearing historical data.'
            );
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE media_processing_jobs DROP CONSTRAINT chk_mpj_output_type');
            DB::statement("ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_output_type CHECK (output_type IS NULL OR output_type IN ('transcript','caption','extracted_text','variant','extracted_region','extracted_table'))");
            DB::statement('ALTER TABLE media_processing_jobs DROP CONSTRAINT chk_mpj_job_type');
            DB::statement("ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_job_type CHECK (job_type IN ('transcode','thumbnail','ocr','speech_to_text','caption','virus_scan','compress','structured_extraction'))");
        }
        Schema::dropIfExists('media_video_frame_texts');
    }
};
