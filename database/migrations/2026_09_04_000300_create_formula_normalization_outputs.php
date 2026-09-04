<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_extracted_formulas', function (Blueprint $table): void {
            $table->unique(['id', 'customer_id', 'media_file_id', 'processing_job_id'], 'uk_mef_normalization_scope');
        });

        Schema::create('media_formula_normalizations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->unsignedBigInteger('processing_job_id');
            $table->unsignedBigInteger('formula_id');
            $table->unsignedBigInteger('source_processing_job_id');
            $table->string('normalized_format', 20);
            $table->longText('normalized_value');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->string('provider', 100);
            $table->string('processing_version', 100);
            $table->char('source_fingerprint', 64);
            $table->json('metadata')->nullable();
            $table->timestamps(6);
            $table->unique(['id', 'customer_id'], 'uk_mfn_tenant_identity');
            $table->unique(['customer_id', 'processing_job_id', 'formula_id'], 'uk_mfn_job_formula');
            $table->index(['customer_id', 'formula_id', 'processing_job_id'], 'idx_mfn_current');
            $table->foreign(['processing_job_id', 'customer_id'], 'fk_mfn_job_tenant')
                ->references(['id', 'customer_id'])->on('media_processing_jobs')->restrictOnDelete();
            $table->foreign(['formula_id', 'customer_id', 'media_file_id', 'source_processing_job_id'], 'fk_mfn_formula_scope')
                ->references(['id', 'customer_id', 'media_file_id', 'processing_job_id'])
                ->on('media_extracted_formulas')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE media_formula_normalizations ADD CONSTRAINT chk_mfn_format CHECK (normalized_format IN ('latex','mathml'))");
            DB::statement("ALTER TABLE media_formula_normalizations ADD CONSTRAINT chk_mfn_value CHECK (CHAR_LENGTH(TRIM(normalized_value)) > 0)");
            DB::statement('ALTER TABLE media_formula_normalizations ADD CONSTRAINT chk_mfn_confidence CHECK (confidence_score IS NULL OR confidence_score BETWEEN 0 AND 100)');
            DB::statement('ALTER TABLE media_processing_jobs DROP CONSTRAINT chk_mpj_job_type');
            DB::statement('ALTER TABLE media_processing_jobs DROP CONSTRAINT chk_mpj_output_type');
            DB::statement("ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_job_type CHECK (job_type IN ('transcode','thumbnail','ocr','speech_to_text','caption','virus_scan','compress','structured_extraction','formula_normalization'))");
            DB::statement("ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_output_type CHECK (output_type IS NULL OR output_type IN ('transcript','caption','extracted_text','variant','extracted_region','extracted_table','formula_normalization'))");
        }
    }

    public function down(): void
    {
        $outputs = DB::table('media_formula_normalizations')->count();
        $jobs = DB::table('media_processing_jobs')->where('job_type', 'formula_normalization')
            ->orWhere('output_type', 'formula_normalization')->count();
        if ($outputs > 0 || $jobs > 0) {
            throw new RuntimeException("Rollback refused: {$outputs} normalization output(s), {$jobs} normalization job(s); remove owning revisions first.");
        }

        Schema::drop('media_formula_normalizations');
        Schema::table('media_extracted_formulas', function (Blueprint $table): void {
            $table->dropUnique('uk_mef_normalization_scope');
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE media_processing_jobs DROP CONSTRAINT chk_mpj_job_type');
            DB::statement('ALTER TABLE media_processing_jobs DROP CONSTRAINT chk_mpj_output_type');
            DB::statement("ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_job_type CHECK (job_type IN ('transcode','thumbnail','ocr','speech_to_text','caption','virus_scan','compress','structured_extraction'))");
            DB::statement("ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_output_type CHECK (output_type IS NULL OR output_type IN ('transcript','caption','extracted_text','variant','extracted_region','extracted_table'))");
        }
    }
};
