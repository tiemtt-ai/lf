<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI Foundation — Media-consumer packet (ADR-0006 v1.0.3, Frozen).
 *
 * Four tables on the Media -> AI path:
 *   ai_model_runs, ai_knowledge_sources, ai_knowledge_chunks, ai_embeddings.
 *
 * Authorized by the independent Architecture Review Round 3 (2026-09-08),
 * docs/quality/LF-AI-Foundation-Media-Consumer-Database-Architecture-Review.md.
 * ai_vision_interpretations depends only on ai_model_runs and ships separately.
 *
 * Creation order is forced by ai_embeddings.model_run_id being NOT NULL:
 *   users -> ai_model_runs
 *   ai_knowledge_sources -> ai_knowledge_chunks -> ai_embeddings
 *
 * Tenant identity: every table carries customer_id NOT NULL, a
 * UNIQUE (id, customer_id) target key, and composite child foreign keys, so a
 * cross-tenant reference is rejected by the database and not merely by
 * application code (Guardrails, Tenant Rule 5).
 *
 * NULL-safe registration identity: MariaDB treats each NULL in a UNIQUE index
 * as distinct, so content_type / locale / fingerprint / version are folded into
 * STORED generated sentinel columns before entering the unique key. Without
 * them the same Media revision could be registered an unbounded number of
 * times and every chunk and embedding below it duplicated.
 *
 * Deletion is a tombstone lifecycle, never a hard delete: rows reach `deleted`,
 * sensitive chunk text is erased, and minimal relational provenance is
 * retained. RESTRICT foreign keys and the audit trail therefore hold at the
 * same time (ADR-0006 v1.0.3, LF-AI "Knowledge deletion barrier").
 *
 * No provider is activated by this migration and no AI runtime exists yet.
 */
return new class extends Migration
{
    private const PACKET_TABLES = [
        'ai_embeddings',
        'ai_knowledge_chunks',
        'ai_knowledge_sources',
        'ai_model_runs',
    ];

    public function up(): void
    {
        $this->createModelRuns();
        $this->createKnowledgeSources();
        $this->createKnowledgeChunks();
        $this->createEmbeddings();
        $this->addCheckConstraints();
    }

    private function createModelRuns(): void
    {
        Schema::create('ai_model_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->char('run_uuid', 36);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('assistant_session_id')->nullable();
            $table->unsignedBigInteger('prompt_template_id')->nullable();
            $table->unsignedBigInteger('prompt_scope_customer_id')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('prompt_hash', 128);
            $table->string('purpose', 100);
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->char('correlation_id', 36)->nullable();
            $table->string('status', 50)->default('queued');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('estimated_cost', 14, 6)->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('latency_ms')->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->json('safety_metadata')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);

            $table->unique(['customer_id', 'run_uuid'], 'uk_amr_run_uuid');
            $table->unique(['id', 'customer_id'], 'uk_amr_tenant_identity');
            $table->index(['customer_id', 'user_id', 'created_at'], 'idx_amr_user_created');
            $table->index(['customer_id', 'assistant_session_id'], 'idx_amr_session');
            $table->index(['customer_id', 'prompt_template_id', 'prompt_version'], 'idx_amr_prompt');
            $table->index(['customer_id', 'provider', 'model'], 'idx_amr_provider_model');
            $table->index(['customer_id', 'status', 'created_at'], 'idx_amr_status_created');
            $table->index(['customer_id', 'correlation_id'], 'idx_amr_correlation');
            $table->foreign(['user_id', 'customer_id'], 'fk_amr_user_tenant')
                ->references(['id', 'customer_id'])->on('users')->restrictOnDelete();
        });
    }

    private function createKnowledgeSources(): void
    {
        Schema::create('ai_knowledge_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->char('source_uuid', 36);
            $table->string('source_type', 100);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('media_file_id')->nullable();
            $table->string('usage_type', 50)->default('');
            // A tombstoned registration keeps its identity forever, so a
            // later re-attach of the same Media revision needs a dimension
            // of its own. Generation is append-only: the old row stays
            // readable for a Proposal that already cited it.
            $table->unsignedInteger('generation')->default(1);
            $table->string('content_type', 50)->nullable();
            $table->string('title', 255);
            $table->string('locale', 20)->nullable();
            $table->string('source_version', 100)->nullable();
            $table->string('content_hash', 128)->nullable();
            $table->char('source_fingerprint', 64)->nullable();
            $table->string('processing_version', 100)->nullable();
            // STORED sentinels: MariaDB does not collide NULLs inside a UNIQUE
            // index, so folding them to '' is what makes the registration key
            // actually unique for non-Media sources and undetermined locales.
            $table->string('identity_content_type', 50)->storedAs("COALESCE(content_type, '')");
            $table->string('identity_locale', 20)->storedAs("COALESCE(locale, '')");
            $table->string('identity_fingerprint', 128)->storedAs("COALESCE(source_fingerprint, content_hash, '')");
            $table->string('identity_version', 100)->storedAs("COALESCE(processing_version, source_version, '')");
            $table->string('status', 50)->default('pending');
            $table->timestamp('last_synced_at', 6)->nullable();
            $table->timestamp('deletion_requested_at', 6)->nullable();
            $table->timestamp('deleted_at', 6)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);

            $table->unique(['customer_id', 'source_uuid'], 'uk_aks_source_uuid');
            $table->unique(['id', 'customer_id'], 'uk_aks_tenant_identity');
            $table->unique([
                'customer_id', 'source_type', 'source_id', 'usage_type',
                'identity_content_type', 'identity_locale',
                'identity_fingerprint', 'identity_version', 'generation',
            ], 'uk_aks_registration_identity');
            $table->index(['customer_id', 'source_type', 'source_id'], 'idx_aks_owner');
            $table->index(['customer_id', 'status'], 'idx_aks_status');
            $table->index(['customer_id', 'last_synced_at'], 'idx_aks_synced');
            $table->index(['customer_id', 'source_fingerprint'], 'idx_aks_fingerprint');
            $table->index(['customer_id', 'media_file_id'], 'idx_aks_media_file');
            $table->foreign(['created_by', 'customer_id'], 'fk_aks_creator_tenant')
                ->references(['id', 'customer_id'])->on('users')->restrictOnDelete();
            // media_file_id is citation provenance, never authorization
            // (ADR-0006 v1.0.3). It still needs the tenant-composite foreign
            // key: without it a tenant A registration can carry a tenant B
            // file id, or an id that never existed, and the citation trail is
            // worthless even though every read re-enters Media Read through
            // owner context. RESTRICT matches every other cross-domain
            // reference to media_files in this repository; Media purge stays
            // a deliberate, orchestrated operation (ADR-0018 retention chain).
            $table->foreign(['media_file_id', 'customer_id'], 'fk_aks_media_tenant')
                ->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
        });
    }

    private function createKnowledgeChunks(): void
    {
        Schema::create('ai_knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('knowledge_source_id');
            $table->char('chunk_uuid', 36);
            $table->unsignedInteger('sequence_no');
            // Nullable only so a `deleted` tombstone can erase the text while
            // keeping provenance; a CHECK below requires it for every other state.
            $table->longText('content')->nullable();
            $table->string('content_hash', 128);
            $table->unsignedInteger('token_count')->nullable();
            $table->string('locale', 20)->nullable();
            $table->unsignedInteger('part_index')->default(1);
            $table->unsignedInteger('char_start')->default(0);
            $table->unsignedInteger('char_end');
            $table->string('locator_type', 20);
            $table->string('locator_start', 50);
            $table->string('locator_end', 50);
            $table->string('source_role', 20)->nullable();
            $table->string('source_quality_status', 20)->nullable();
            $table->json('language_evidence')->nullable();
            $table->unsignedInteger('reading_order')->nullable();
            $table->string('source_text_quality', 20)->nullable();
            $table->decimal('bbox_x', 9, 6)->nullable();
            $table->decimal('bbox_y', 9, 6)->nullable();
            $table->decimal('bbox_width', 9, 6)->nullable();
            $table->decimal('bbox_height', 9, 6)->nullable();
            $table->unsignedInteger('frame_width')->nullable();
            $table->unsignedInteger('frame_height')->nullable();
            $table->string('status', 50)->default('pending');
            $table->timestamp('deletion_requested_at', 6)->nullable();
            $table->timestamp('deleted_at', 6)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);

            $table->unique(['customer_id', 'chunk_uuid'], 'uk_akc_chunk_uuid');
            $table->unique(['id', 'customer_id'], 'uk_akc_tenant_identity');
            $table->unique(['customer_id', 'knowledge_source_id', 'sequence_no'], 'uk_akc_sequence');
            $table->unique([
                'customer_id', 'knowledge_source_id', 'locator_type',
                'locator_start', 'part_index',
            ], 'uk_akc_locator_part');
            $table->index(['customer_id', 'knowledge_source_id'], 'idx_akc_source');
            $table->index(['customer_id', 'status'], 'idx_akc_status');
            $table->index(['customer_id', 'content_hash'], 'idx_akc_content_hash');
            $table->foreign(['knowledge_source_id', 'customer_id'], 'fk_akc_source_tenant')
                ->references(['id', 'customer_id'])->on('ai_knowledge_sources')->restrictOnDelete();
        });
    }

    private function createEmbeddings(): void
    {
        Schema::create('ai_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('knowledge_chunk_id');
            $table->unsignedBigInteger('model_run_id');
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->unsignedInteger('dimensions');
            $table->string('vector_store', 50);
            $table->string('vector_index', 255);
            $table->string('vector_key', 255);
            $table->string('embedding_hash', 128);
            $table->string('status', 50)->default('pending');
            $table->timestamp('embedded_at', 6)->nullable();
            $table->timestamp('deletion_requested_at', 6)->nullable();
            $table->unsignedInteger('deletion_attempts')->default(0);
            $table->timestamp('deleted_at', 6)->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps(6);

            $table->unique(['customer_id', 'vector_store', 'vector_index', 'vector_key'], 'uk_aem_vector_key');
            $table->unique(['id', 'customer_id'], 'uk_aem_tenant_identity');
            $table->unique([
                'customer_id', 'knowledge_chunk_id', 'provider', 'model', 'embedding_hash',
            ], 'uk_aem_chunk_model');
            $table->index(['customer_id', 'knowledge_chunk_id'], 'idx_aem_chunk');
            $table->index(['customer_id', 'provider', 'model'], 'idx_aem_provider_model');
            $table->index(['customer_id', 'status'], 'idx_aem_status');
            $table->foreign(['knowledge_chunk_id', 'customer_id'], 'fk_aem_chunk_tenant')
                ->references(['id', 'customer_id'])->on('ai_knowledge_chunks')->restrictOnDelete();
            $table->foreign(['model_run_id', 'customer_id'], 'fk_aem_run_tenant')
                ->references(['id', 'customer_id'])->on('ai_model_runs')->restrictOnDelete();
        });
    }

    private function addCheckConstraints(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach ([
            'ALTER TABLE ai_model_runs'
                ." ADD CONSTRAINT chk_amr_status CHECK (status IN ('queued','running','completed','failed','blocked','cancelled')),"
                .' ADD CONSTRAINT chk_amr_tokens CHECK (total_tokens >= input_tokens),'
                ." ADD CONSTRAINT chk_amr_completed CHECK (status <> 'completed' OR completed_at IS NOT NULL),"
                ." ADD CONSTRAINT chk_amr_failed CHECK (status <> 'failed' OR error_code IS NOT NULL),"
                ." ADD CONSTRAINT chk_amr_blocked CHECK (status <> 'blocked' OR error_code IS NOT NULL),"
                // Review finding N-3: the documented form passed whenever
                // prompt_scope_customer_id was NULL, because `NULL IN (...)`
                // is UNKNOWN and a composite FK with a NULL part is not
                // enforced. The scope must be present whenever a template is
                // referenced, or the deferred prompt FK guards nothing.
                .' ADD CONSTRAINT chk_amr_prompt_scope CHECK ('
                .'prompt_template_id IS NULL'
                .' OR (prompt_scope_customer_id IS NOT NULL'
                .' AND prompt_scope_customer_id IN (0, customer_id)))',

            'ALTER TABLE ai_knowledge_sources'
                ." ADD CONSTRAINT chk_aks_status CHECK (status IN ('pending','active','stale','archived','failed','deletion_pending','deleted')),"
                ." ADD CONSTRAINT chk_aks_source_type CHECK (source_type IN ('course_activity','course_version_activity','course_version','assessment_snapshot','track_summary','liveclass_transcript','other')),"
                .' ADD CONSTRAINT chk_aks_content_type CHECK (content_type IS NULL'
                ." OR content_type IN ('extracted_text','transcript','region','table','formula','video_frame_text')),"
                .' ADD CONSTRAINT chk_aks_media_revision CHECK (content_type IS NULL'
                .' OR (source_fingerprint IS NOT NULL AND processing_version IS NOT NULL)),'
                .' ADD CONSTRAINT chk_aks_media_binding CHECK ('
                ." (content_type IS NULL AND media_file_id IS NULL AND usage_type = '')"
                .' OR (content_type IS NOT NULL AND media_file_id IS NOT NULL'
                ." AND source_type IN ('course_activity','course_version_activity'))),"
                .' ADD CONSTRAINT chk_aks_usage_pair CHECK (content_type IS NULL OR ('
                ." (content_type IN ('extracted_text','region','table','formula')"
                ." AND usage_type = 'document')"
                ." OR (content_type = 'transcript' AND usage_type IN ('audio','video'))"
                ." OR (content_type = 'video_frame_text' AND usage_type = 'video'))),"
                ." ADD CONSTRAINT chk_aks_deletion_pending CHECK (status <> 'deletion_pending' OR deletion_requested_at IS NOT NULL),"
                .' ADD CONSTRAINT chk_aks_generation CHECK (generation >= 1),'
                ." ADD CONSTRAINT chk_aks_deleted CHECK (status <> 'deleted' OR deleted_at IS NOT NULL)",

            'ALTER TABLE ai_knowledge_chunks'
                ." ADD CONSTRAINT chk_akc_status CHECK (status IN ('pending','active','stale','archived','failed','deletion_pending','deleted')),"
                ." ADD CONSTRAINT chk_akc_locator_type CHECK (locator_type IN ('page','timespan','sheet','region')),"
                .' ADD CONSTRAINT chk_akc_sequence CHECK (sequence_no >= 1),'
                .' ADD CONSTRAINT chk_akc_part CHECK (part_index >= 1 AND char_end > char_start),'
                .' ADD CONSTRAINT chk_akc_source_role CHECK (source_role IS NULL'
                ." OR source_role IN ('paragraph','heading','list','table','figure','caption','header','footer','other')),"
                .' ADD CONSTRAINT chk_akc_quality_status CHECK (source_quality_status IS NULL'
                ." OR source_quality_status IN ('complete','incomplete','undetermined')),"
                .' ADD CONSTRAINT chk_akc_text_quality CHECK (source_text_quality IS NULL'
                ." OR source_text_quality IN ('normal','low')),"
                ." ADD CONSTRAINT chk_akc_deletion_pending CHECK (status <> 'deletion_pending' OR deletion_requested_at IS NOT NULL),"
                ." ADD CONSTRAINT chk_akc_deleted CHECK (status <> 'deleted' OR deleted_at IS NOT NULL),"
                ." ADD CONSTRAINT chk_akc_content_retained CHECK (status = 'deleted' OR content IS NOT NULL)",

            'ALTER TABLE ai_embeddings'
                ." ADD CONSTRAINT chk_aem_status CHECK (status IN ('pending','ready','failed','stale','deletion_pending','deleted')),"
                .' ADD CONSTRAINT chk_aem_dimensions CHECK (dimensions >= 1),'
                ." ADD CONSTRAINT chk_aem_vector_store CHECK (vector_store = 'qdrant'),"
                .' ADD CONSTRAINT chk_aem_deletion_attempts CHECK (deletion_attempts >= 0),'
                ." ADD CONSTRAINT chk_aem_deletion_pending CHECK (status <> 'deletion_pending' OR deletion_requested_at IS NOT NULL),"
                ." ADD CONSTRAINT chk_aem_deleted CHECK (status <> 'deleted' OR deleted_at IS NOT NULL),"
                ." ADD CONSTRAINT chk_aem_ready CHECK (status <> 'ready' OR embedded_at IS NOT NULL)",
        ] as $statement) {
            DB::statement($statement);
        }
    }

    /**
     * Rollback is fail-closed. Every row in this packet is citation-bearing or
     * audit-bearing evidence: an archived Knowledge Source keeps a cited
     * Proposal resolvable, a chunk carries the citation locator, a `deleted`
     * embedding is the proof its Qdrant point was removed, and a Model Run is
     * the cost and provenance record of a provider call. Dropping the tables
     * destroys evidence that cannot be reconstructed, so the operator must
     * retire the data through the canonical AI lifecycle first.
     */
    public function down(): void
    {
        $populated = [];

        foreach (self::PACKET_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $count = DB::table($table)->count();

            if ($count > 0) {
                $populated[] = "{$table}={$count}";
            }
        }

        if ($populated !== []) {
            throw new RuntimeException(
                'Rollback refused: AI Foundation knowledge rows must be retained ('
                .implode(', ', $populated).'). '
                .'Retire sources, chunks and embeddings through the canonical AI '
                .'deletion lifecycle and retain Model Run audit rows; archived and '
                .'tombstoned rows remain citation-bearing historical evidence.'
            );
        }

        foreach (self::PACKET_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
