<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ai_vision_interpretations — database/ai/ai_vision_interpretations.md v1.1.
 *
 * AI-owned derived data: a model's interpretation of one Media region. It is
 * never Media evidence and nothing here writes a `media_*` table (ADR-0020 D1).
 * v1.1 scope is document regions only; video frame interpretation stays out of
 * scope until ADR-0020 D7 is amended.
 *
 * Owner waived the Architecture Review condition for this packet on 2026-09-14;
 * see LF-AI-Vision-Interpretation-Implementation-Review.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::getDriverName() === 'mysql';

        Schema::create('ai_vision_interpretations', function (Blueprint $table) use ($mysql): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->char('interpretation_uuid', 36);
            $table->unsignedBigInteger('model_run_id');
            $table->string('source_type', 100);
            $table->unsignedBigInteger('source_id');
            // Citation provenance only; authorization is always re-derived
            // through Media Read (ADR-0006 v1.0.3). The tenant FK below exists
            // so a row can never cite another tenant's file.
            $table->unsignedBigInteger('media_file_id');
            $table->string('usage_type', 50);
            $table->string('content_type', 50);
            $table->string('locale', 20)->nullable();
            $table->char('source_fingerprint', 64);
            $table->string('processing_version', 100);
            $table->string('locator_type', 20);
            $table->string('locator_start', 50);
            $table->unsignedInteger('page');
            // Copied from the source Media region, which may itself have no bbox.
            $table->decimal('bbox_x', 9, 6)->nullable();
            $table->decimal('bbox_y', 9, 6)->nullable();
            $table->decimal('bbox_width', 9, 6)->nullable();
            $table->decimal('bbox_height', 9, 6)->nullable();
            // Nullable only so a `deleted` tombstone can erase the text; CHECKs
            // below require it for every other status and forbid it once deleted.
            $table->longText('interpretation')->nullable();
            $table->char('interpretation_hash', 64);
            $table->string('status', 50)->default('ready');
            // At most one `ready` row per unit slot: NULL for every other status,
            // and NULLs never collide in a UNIQUE index. Free-text fields carry a
            // length prefix so the encoding is injective — a bare `|` join let two
            // different slots produce the same string. SQLite has no SHA2, so it
            // stores the same prefixed encoding unhashed; uniqueness is identical.
            $table->string('active_slot', 64)->storedAs($mysql
                ? "CASE WHEN status = 'ready' THEN SHA2(CONCAT_WS('|', source_type, source_id, usage_type, content_type, RTRIM(source_fingerprint), CHAR_LENGTH(processing_version), processing_version, locator_type, CHAR_LENGTH(locator_start), locator_start), 256) END"
                : "CASE WHEN status = 'ready' THEN source_type || '|' || source_id || '|' || usage_type || '|' || content_type || '|' || rtrim(source_fingerprint) || '|' || length(processing_version) || '|' || processing_version || '|' || locator_type || '|' || length(locator_start) || '|' || locator_start END");
            // Explicitly NULL, so the shape does not depend on
            // explicit_defaults_for_timestamp (OFF on 10.4, ON on 11.4).
            $table->timestamp('deletion_requested_at', 6)->nullable();
            $table->timestamp('deleted_at', 6)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at', 6)->nullable();
            $table->timestamp('updated_at', 6)->nullable();

            $table->unique(['customer_id', 'interpretation_uuid'], 'uk_avi_uuid');
            $table->unique(['id', 'customer_id'], 'uk_avi_tenant_identity');
            $table->unique([
                'customer_id', 'source_type', 'source_id', 'usage_type', 'content_type',
                'source_fingerprint', 'processing_version', 'locator_type', 'locator_start',
                'model_run_id',
            ], 'uk_avi_run_unit');
            $table->unique(['customer_id', 'active_slot'], 'uk_avi_active_slot');
            $table->index(['customer_id', 'source_type', 'source_id', 'status'], 'idx_avi_owner_status');
            $table->index(['customer_id', 'media_file_id', 'status'], 'idx_avi_media_status');
            $table->index(['customer_id', 'model_run_id'], 'idx_avi_run');

            $table->foreign(['model_run_id', 'customer_id'], 'fk_avi_run_tenant')
                ->references(['id', 'customer_id'])->on('ai_model_runs')->restrictOnDelete();
            $table->foreign(['media_file_id', 'customer_id'], 'fk_avi_media_tenant')
                ->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
        });

        if (! $mysql) {
            return;
        }

        foreach ([
            'chk_avi_source_type' => "source_type IN ('course_activity','course_version_activity')",
            // One closed tuple, not three independent lists: widening scope
            // (e.g. video) must change this pairing deliberately.
            'chk_avi_scope' => "usage_type = 'document' AND content_type = 'region' AND locator_type = 'region'",
            'chk_avi_page' => 'page >= 1',
            'chk_avi_status' => "status IN ('ready','stale','deletion_pending','deleted')",
            // The second branch repeats IS NOT NULL on purpose. Without it a
            // partial bbox (say bbox_width NULL) makes `bbox_width > 0` UNKNOWN,
            // `FALSE OR UNKNOWN` is UNKNOWN, and a CHECK passes on UNKNOWN.
            'chk_avi_bbox' => '(bbox_x IS NULL AND bbox_y IS NULL AND bbox_width IS NULL AND bbox_height IS NULL)'
                .' OR (bbox_x IS NOT NULL AND bbox_y IS NOT NULL AND bbox_width IS NOT NULL AND bbox_height IS NOT NULL'
                .' AND bbox_x BETWEEN 0 AND 1 AND bbox_y BETWEEN 0 AND 1 AND bbox_width > 0 AND bbox_height > 0'
                .' AND bbox_x + bbox_width <= 1 AND bbox_y + bbox_height <= 1)',
            'chk_avi_content_retained' => "status = 'deleted' OR interpretation IS NOT NULL",
            'chk_avi_content_erased' => "status <> 'deleted' OR interpretation IS NULL",
            'chk_avi_deletion_pending' => "status <> 'deletion_pending' OR deletion_requested_at IS NOT NULL",
            'chk_avi_deleted' => "status <> 'deleted' OR deleted_at IS NOT NULL",
        ] as $name => $expression) {
            DB::statement("ALTER TABLE ai_vision_interpretations ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    /**
     * Fail-closed: an interpretation row is AI-derived evidence with a cost and
     * provenance trail, and a `deleted` tombstone is the proof the text was
     * erased. Retire rows through the canonical lifecycle before rolling back.
     */
    public function down(): void
    {
        if (Schema::hasTable('ai_vision_interpretations')
            && DB::table('ai_vision_interpretations')->exists()) {
            throw new RuntimeException('LF_AI_VISION_ROLLBACK_REFUSED: ai_vision_interpretations has rows');
        }

        Schema::dropIfExists('ai_vision_interpretations');
    }
};
