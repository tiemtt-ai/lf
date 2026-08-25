<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->string('processing_locale', 20)->nullable()->after('status');
            $table->string('processing_error_code', 100)->nullable()->after('processing_locale');
            $table->unique(['id', 'customer_id'], 'uk_media_files_tenant_identity');
        });

        Schema::create('media_processing_jobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->string('job_type', 50);
            $table->string('status', 50)->default('pending');
            $table->unsignedInteger('attempt')->default(1);
            $table->unsignedBigInteger('supersedes_job_id')->nullable();
            $table->string('idempotency_key', 191);
            $table->uuid('correlation_id');
            $table->char('source_fingerprint', 64);
            $table->string('processing_version', 100);
            $table->string('output_profile', 191);
            $table->char('output_profile_hash', 64);
            $table->string('provider', 100);
            $table->string('output_type', 50)->nullable();
            $table->unsignedBigInteger('output_id')->nullable();
            $table->decimal('billable_units', 18, 6)->nullable();
            $table->string('billable_unit_type', 50)->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps(6);

            $table->unique(['id', 'customer_id'], 'uk_mpj_tenant_identity');
            $table->unique(['customer_id', 'idempotency_key'], 'uk_mpj_idempotency');
            $table->unique(['customer_id', 'media_file_id', 'job_type', 'source_fingerprint', 'processing_version', 'output_profile_hash', 'attempt'], 'uk_mpj_profile_attempt');
            $table->unique(['customer_id', 'supersedes_job_id'], 'uk_mpj_supersedes');
            $table->index(['customer_id', 'media_file_id', 'job_type', 'status'], 'idx_mpj_file_type_status');
            $table->index(['customer_id', 'status', 'created_at'], 'idx_mpj_status_created');
            $table->index(['customer_id', 'correlation_id'], 'idx_mpj_correlation');
            $table->index(['customer_id', 'output_type', 'output_id'], 'idx_mpj_output');
            $table->foreign(['media_file_id', 'customer_id'], 'fk_mpj_media_tenant')->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
            $table->foreign(['supersedes_job_id', 'customer_id'], 'fk_mpj_parent_tenant')->references(['id', 'customer_id'])->on('media_processing_jobs')->restrictOnDelete();
            $table->foreign(['created_by', 'customer_id'], 'fk_mpj_creator_tenant')->references(['id', 'customer_id'])->on('users')->restrictOnDelete();
        });

        $this->createExtractedTexts();
        $this->createTranscripts();
        $this->createCaptions();
        $this->createVariants();
        $this->createAccessLogs();
        $this->addMariaDbChecks();
        $this->createAppendOnlyTriggers();
    }

    private function createExtractedTexts(): void
    {
        Schema::create('media_extracted_texts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->unsignedBigInteger('processing_job_id')->nullable();
            $table->string('locale', 20);
            $table->string('locator_type', 20);
            $table->string('locator_value', 50);
            $table->unsignedInteger('sequence');
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
            $table->unique(['customer_id', 'media_file_id', 'locale', 'locator_type', 'locator_value', 'processing_version'], 'uk_met_revision_locator');
            $table->unique(['id', 'customer_id'], 'uk_met_tenant_identity');
            $table->index(['customer_id', 'media_file_id', 'locale', 'sequence'], 'idx_met_read_order');
            $table->index(['customer_id', 'media_file_id', 'status'], 'idx_met_file_status');
            $table->index(['customer_id', 'source_fingerprint'], 'idx_met_fingerprint');
            $table->index(['customer_id', 'processing_job_id'], 'idx_met_job');
            $table->foreign(['media_file_id', 'customer_id'], 'fk_met_media_tenant')->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
            $table->foreign(['processing_job_id', 'customer_id'], 'fk_met_job_tenant')->references(['id', 'customer_id'])->on('media_processing_jobs')->restrictOnDelete();
        });
    }

    private function createTranscripts(): void
    {
        Schema::create('media_transcripts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->string('locale', 20);
            $table->string('provider', 100)->nullable();
            $table->string('status', 50)->default('pending');
            $table->longText('text')->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('processing_job_id')->nullable();
            $table->string('processing_version', 100);
            $table->char('source_fingerprint', 64);
            $table->string('locator_type', 20);
            $table->string('locator_value', 50);
            $table->timestamps();
            $table->unique(['customer_id', 'media_file_id', 'locale', 'locator_type', 'locator_value', 'processing_version'], 'uk_mt_revision_locator');
            $table->unique(['id', 'customer_id'], 'uk_mt_tenant_identity');
            $table->index(['customer_id', 'media_file_id', 'status'], 'idx_mt_file_status');
            $table->index(['customer_id', 'source_fingerprint'], 'idx_mt_fingerprint');
            $table->foreign(['media_file_id', 'customer_id'], 'fk_mt_media_tenant')->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
            $table->foreign(['processing_job_id', 'customer_id'], 'fk_mt_job_tenant')->references(['id', 'customer_id'])->on('media_processing_jobs')->restrictOnDelete();
        });
    }

    private function createCaptions(): void
    {
        Schema::create('media_captions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->string('locale', 20);
            $table->string('caption_type', 20);
            $table->string('storage_key', 1024);
            $table->string('status', 50)->default('pending');
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('processing_job_id')->nullable();
            $table->string('processing_version', 100);
            $table->char('source_fingerprint', 64);
            $table->timestamps();
            $table->unique(['customer_id', 'media_file_id', 'locale', 'caption_type', 'processing_version'], 'uk_mc_revision');
            $table->unique(['customer_id', 'storage_key'], 'uk_mc_storage');
            $table->unique(['id', 'customer_id'], 'uk_mc_tenant_identity');
            $table->index(['customer_id', 'media_file_id', 'status'], 'idx_mc_file_status');
            $table->index(['customer_id', 'source_fingerprint'], 'idx_mc_fingerprint');
            $table->foreign(['media_file_id', 'customer_id'], 'fk_mc_media_tenant')->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
            $table->foreign(['processing_job_id', 'customer_id'], 'fk_mc_job_tenant')->references(['id', 'customer_id'])->on('media_processing_jobs')->restrictOnDelete();
        });
    }

    private function createVariants(): void
    {
        Schema::create('media_variants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->string('variant_type', 50);
            $table->string('storage_key', 1024);
            $table->text('cdn_url')->nullable();
            $table->string('mime_type');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('bitrate')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->unsignedBigInteger('processing_job_id')->nullable();
            $table->string('processing_version', 100);
            $table->char('source_fingerprint', 64);
            $table->string('status', 50)->default('processing');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'media_file_id', 'variant_type', 'processing_version'], 'uk_mv_revision');
            $table->unique(['customer_id', 'storage_key'], 'uk_mv_storage');
            $table->unique(['id', 'customer_id'], 'uk_mv_tenant_identity');
            $table->index(['customer_id', 'media_file_id', 'status'], 'idx_mv_file_status');
            $table->index(['customer_id', 'source_fingerprint'], 'idx_mv_fingerprint');
            $table->index(['customer_id', 'processing_job_id'], 'idx_mv_job');
            $table->foreign(['media_file_id', 'customer_id'], 'fk_mv_media_tenant')->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
            $table->foreign(['processing_job_id', 'customer_id'], 'fk_mv_job_tenant')->references(['id', 'customer_id'])->on('media_processing_jobs')->restrictOnDelete();
        });
    }

    private function createAccessLogs(): void
    {
        Schema::create('media_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 50);
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('accessed_at');
            $table->json('metadata')->nullable();
            $table->index(['customer_id', 'media_file_id', 'accessed_at'], 'idx_mal_file_time');
            $table->index(['customer_id', 'user_id', 'accessed_at'], 'idx_mal_user_time');
            $table->index(['customer_id', 'action', 'accessed_at'], 'idx_mal_action_time');
            $table->index(['customer_id', 'source_type', 'source_id'], 'idx_mal_source');
            $table->foreign(['media_file_id', 'customer_id'], 'fk_mal_media_tenant')->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
            $table->foreign(['user_id', 'customer_id'], 'fk_mal_user_tenant')->references(['id', 'customer_id'])->on('users')->restrictOnDelete();
        });
    }

    private function addMariaDbChecks(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        foreach ([
            "ALTER TABLE media_processing_jobs ADD CONSTRAINT chk_mpj_job_type CHECK (job_type IN ('transcode','thumbnail','ocr','speech_to_text','caption','virus_scan','compress')), ADD CONSTRAINT chk_mpj_status CHECK (status IN ('pending','processing','ready','failed','cancelled')), ADD CONSTRAINT chk_mpj_attempt CHECK (attempt >= 1), ADD CONSTRAINT chk_mpj_output_pair CHECK ((output_type IS NULL AND output_id IS NULL) OR (output_type IS NOT NULL AND output_id IS NOT NULL)), ADD CONSTRAINT chk_mpj_ready CHECK (status <> 'ready' OR (completed_at IS NOT NULL AND (job_type = 'virus_scan' OR output_id IS NOT NULL))), ADD CONSTRAINT chk_mpj_failed CHECK (status <> 'failed' OR (completed_at IS NOT NULL AND error_code IS NOT NULL))",
            "ALTER TABLE media_extracted_texts ADD CONSTRAINT chk_met_status CHECK (status IN ('pending','processing','ready','failed','archived')), ADD CONSTRAINT chk_met_locator CHECK (locator_type = 'page'), ADD CONSTRAINT chk_met_method CHECK (extraction_method IN ('ocr','embedded_text')), ADD CONSTRAINT chk_met_sequence CHECK (sequence >= 1), ADD CONSTRAINT chk_met_confidence CHECK (confidence_score IS NULL OR confidence_score BETWEEN 0 AND 100), ADD CONSTRAINT chk_met_ready CHECK (status <> 'ready' OR text IS NOT NULL)",
            "ALTER TABLE media_transcripts ADD CONSTRAINT chk_mt_status CHECK (status IN ('pending','processing','ready','failed','archived')), ADD CONSTRAINT chk_mt_locator CHECK (locator_type = 'timespan'), ADD CONSTRAINT chk_mt_confidence CHECK (confidence_score IS NULL OR confidence_score BETWEEN 0 AND 100), ADD CONSTRAINT chk_mt_ready CHECK (status <> 'ready' OR text IS NOT NULL)",
            "ALTER TABLE media_captions ADD CONSTRAINT chk_mc_status CHECK (status IN ('pending','processing','ready','failed','archived')), ADD CONSTRAINT chk_mc_type CHECK (caption_type IN ('vtt','srt','ass'))",
            "ALTER TABLE media_variants ADD CONSTRAINT chk_mv_status CHECK (status IN ('processing','ready','failed','archived')), ADD CONSTRAINT chk_mv_type CHECK (variant_type IN ('thumbnail','preview','compressed','720p','1080p','hls','webp'))",
            "ALTER TABLE media_access_logs ADD CONSTRAINT chk_mal_action CHECK (action IN ('upload','stream','view','download','delete','share','read_derived'))",
        ] as $statement) {
            DB::statement($statement);
        }
    }

    private function createAppendOnlyTriggers(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER trg_media_access_logs_bu_immutable BEFORE UPDATE ON media_access_logs BEGIN SELECT RAISE(ABORT, 'LF_MEDIA_ACCESS_LOG_IMMUTABLE'); END");
            DB::unprepared("CREATE TRIGGER trg_media_access_logs_bd_immutable BEFORE DELETE ON media_access_logs BEGIN SELECT RAISE(ABORT, 'LF_MEDIA_ACCESS_LOG_IMMUTABLE'); END");

            return;
        }
        DB::unprepared("CREATE TRIGGER trg_media_access_logs_bu_immutable BEFORE UPDATE ON media_access_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_MEDIA_ACCESS_LOG_IMMUTABLE'");
        DB::unprepared("CREATE TRIGGER trg_media_access_logs_bd_immutable BEFORE DELETE ON media_access_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_MEDIA_ACCESS_LOG_IMMUTABLE'");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_media_access_logs_bu_immutable');
        DB::statement('DROP TRIGGER IF EXISTS trg_media_access_logs_bd_immutable');
        foreach (['media_access_logs', 'media_variants', 'media_captions', 'media_transcripts', 'media_extracted_texts', 'media_processing_jobs'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('media_files', function (Blueprint $table): void {
            $table->dropUnique('uk_media_files_tenant_identity');
            $table->dropColumn(['processing_locale', 'processing_error_code']);
        });
    }
};
