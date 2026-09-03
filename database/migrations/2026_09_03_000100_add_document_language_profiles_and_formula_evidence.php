<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_processing_job_locales', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('processing_job_id');
            $table->unsignedTinyInteger('ordinal');
            $table->string('locale', 20);
            $table->timestamps(6);
            $table->unique(['customer_id', 'processing_job_id', 'ordinal'], 'uk_mpjl_job_ordinal');
            $table->unique(['customer_id', 'processing_job_id', 'locale'], 'uk_mpjl_job_locale');
            $table->foreign(['processing_job_id', 'customer_id'], 'fk_mpjl_job_tenant')
                ->references(['id', 'customer_id'])->on('media_processing_jobs')->cascadeOnDelete();
        });

        Schema::table('media_extracted_regions', function (Blueprint $table): void {
            $table->string('detected_locale', 20)->nullable()->after('text');
            $table->string('script', 20)->nullable()->after('detected_locale');
            $table->unique(['id', 'customer_id', 'media_file_id', 'processing_job_id'], 'uk_mer_formula_scope');
        });

        Schema::create('media_extracted_formulas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->unsignedBigInteger('processing_job_id');
            $table->unsignedBigInteger('region_id');
            $table->longText('raw_text')->nullable();
            $table->string('normalized_format', 20)->nullable();
            $table->longText('normalized_value')->nullable();
            $table->string('normalization_status', 20)->default('unavailable');
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);
            $table->unique(['customer_id', 'region_id'], 'uk_mef_region');
            $table->index(['customer_id', 'media_file_id'], 'idx_mef_media');
            $table->index(['customer_id', 'processing_job_id'], 'idx_mef_job');
            $table->foreign(['region_id', 'customer_id', 'media_file_id', 'processing_job_id'], 'fk_mef_region_scope')
                ->references(['id', 'customer_id', 'media_file_id', 'processing_job_id'])
                ->on('media_extracted_regions')->cascadeOnDelete();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE media_processing_job_locales ADD CONSTRAINT chk_mpjl_ordinal CHECK (ordinal BETWEEN 1 AND 3)');
            DB::statement('ALTER TABLE media_extracted_regions DROP CONSTRAINT chk_mer_role');
            DB::statement("ALTER TABLE media_extracted_regions ADD CONSTRAINT chk_mer_role CHECK (role IN ('paragraph','heading','list','table','figure','image','chart','diagram','geometry','formula','caption','note','header','footer','other'))");
            DB::statement("ALTER TABLE media_extracted_formulas ADD CONSTRAINT chk_mef_format CHECK (normalized_format IS NULL OR normalized_format IN ('latex','mathml'))");
            DB::statement("ALTER TABLE media_extracted_formulas ADD CONSTRAINT chk_mef_status CHECK (normalization_status IN ('unavailable','ready','failed'))");
            DB::statement('ALTER TABLE media_extracted_formulas ADD CONSTRAINT chk_mef_confidence CHECK (confidence_score IS NULL OR (confidence_score >= 0 AND confidence_score <= 100))');
            DB::statement("ALTER TABLE media_extracted_formulas ADD CONSTRAINT chk_mef_ready CHECK ((normalization_status = 'ready' AND normalized_format IS NOT NULL AND normalized_value IS NOT NULL AND confidence_score IS NOT NULL) OR (normalization_status <> 'ready' AND normalized_value IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_extracted_formulas');
        Schema::table('media_extracted_regions', function (Blueprint $table): void {
            $table->dropUnique('uk_mer_formula_scope');
            $table->dropColumn(['detected_locale', 'script']);
        });
        Schema::dropIfExists('media_processing_job_locales');

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE media_extracted_regions DROP CONSTRAINT chk_mer_role');
            DB::statement("ALTER TABLE media_extracted_regions ADD CONSTRAINT chk_mer_role CHECK (role IN ('paragraph','heading','list','table','figure','caption','header','footer','other'))");
        }
    }
};
