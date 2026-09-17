<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREFIX = 'ai_authoring_';

    private const TABLES = ['generation_requests', 'proposals', 'proposal_revisions', 'proposal_sources', 'proposal_reviews', 'proposal_applications'];

    private const INTENTS = 'core_course_template_learning_mapping_intents';

    public function up(): void
    {
        // Learning Foundation and its composite targets exist only on MySQL/MariaDB.
        // Do not claim SQLite coverage for this cross-domain physical packet.
        if (! $this->supported()) {
            return;
        }

        $this->requests();
        $this->proposals();
        $this->revisions();
        $this->sources();
        $this->reviews();
        $this->applications();
        Schema::table(self::PREFIX.'proposals', function (Blueprint $t): void {
            $this->fk($t, ['predecessor_revision_id', 'customer_id', 'supersedes_proposal_id'], 'proposal_revisions', ['id', 'customer_id', 'proposal_id'], 'ap_predecessor');
        });
        $this->intents();
        $this->historyTriggers();
        $this->aggregateTriggers();
    }

    private function supported(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function base(Blueprint $t, string $key): void
    {
        $t->id();
        $t->unsignedBigInteger('customer_id');
        $t->unique(['id', 'customer_id'], 'uk_'.$key.'_tenant');
        $t->foreign('customer_id', 'fk_'.$key.'_customer')->references('id')->on('saas_customers')->restrictOnDelete();
    }

    private function ids(Blueprint $t, array $names, bool $nullable = false): void
    {
        foreach ($names as $name) {
            $t->unsignedBigInteger($name)->nullable($nullable);
        }
    }

    private function hashes(Blueprint $t, array $names, bool $nullable = false): void
    {
        foreach ($names as $name) {
            $t->char($name, 64)->nullable($nullable);
        }
    }

    private function dates(Blueprint $t, array $names, bool $nullable = false): void
    {
        foreach ($names as $name) {
            $t->dateTime($name, 6)->nullable($nullable);
        }
    }

    private function fk(Blueprint $t, array $columns, string $target, array $references, string $key): void
    {
        $table = in_array($target, self::TABLES, true) ? self::PREFIX.$target : $target;
        $t->foreign($columns, 'fk_'.$key)->references($references)->on($table)->restrictOnDelete();
    }

    private function actor(Blueprint $t, string $column, string $key): void
    {
        $this->fk($t, [$column, 'customer_id'], 'users', ['id', 'customer_id'], $key);
    }

    private function checks(string $table, string $key, array $checks): void
    {
        foreach ($checks as $name => $expression) {
            DB::unprepared("ALTER TABLE `$table` ADD CONSTRAINT `chk_{$key}_{$name}` CHECK ($expression)");
        }
    }

    private function requests(): void
    {
        Schema::create('ai_authoring_generation_requests', function (Blueprint $t): void {
            $this->base($t, 'agr');
            $t->char('request_uuid', 36);
            $this->hashes($t, ['command_hash']);
            $t->string('mode', 32);
            $this->ids($t, ['actor_id', 'template_id', 'activity_id']);
            $t->char('run_uuid', 36)->nullable();
            $this->ids($t, ['model_run_id'], true);
            $t->string('prompt_contract_id', 100)->nullable();
            $t->unsignedInteger('prompt_version')->nullable();
            $this->hashes($t, ['prompt_hash'], true);
            $t->string('status', 32);
            $t->unsignedInteger('item_count')->nullable();
            $t->string('error_code', 100)->nullable();
            $this->dates($t, ['created_at', 'updated_at']);
            $this->dates($t, ['completed_at'], true);
            $t->unique(['customer_id', 'request_uuid'], 'uk_agr_request');
            $t->unique(['customer_id', 'run_uuid'], 'uk_agr_run');
            $t->index(['customer_id', 'status', 'id'], 'idx_agr_status');
            $this->actor($t, 'actor_id', 'agr_actor');
            $this->fk($t, ['model_run_id', 'customer_id'], 'ai_model_runs', ['id', 'customer_id'], 'agr_run');
        });
        $this->checks(self::PREFIX.'generation_requests', 'agr', [
            'mode' => "mode IN ('generated','human_successor')",
            'status' => "status IN ('pending','running','completed','failed')",
            'prompt' => "(mode = 'generated' AND run_uuid IS NOT NULL AND prompt_contract_id IS NOT NULL AND prompt_version IS NOT NULL AND prompt_version >= 1 AND prompt_hash IS NOT NULL) OR (mode = 'human_successor' AND run_uuid IS NULL AND model_run_id IS NULL AND prompt_contract_id IS NULL AND prompt_version IS NULL AND prompt_hash IS NULL)",
            'count' => "(status = 'completed' AND item_count IS NOT NULL AND item_count <= 100) OR (status <> 'completed' AND item_count IS NULL)",
            'human_count' => "mode <> 'human_successor' OR status <> 'completed' OR item_count = 1",
            'completed_run' => "mode <> 'generated' OR status <> 'completed' OR model_run_id IS NOT NULL",
            'error' => "(status = 'failed' AND error_code IS NOT NULL) OR (status <> 'failed' AND error_code IS NULL)",
            'completion' => "(status IN ('completed','failed') AND completed_at IS NOT NULL) OR (status IN ('pending','running') AND completed_at IS NULL)",
        ]);
    }

    private function proposals(): void
    {
        Schema::create('ai_authoring_proposals', function (Blueprint $t): void {
            $this->base($t, 'ap');
            $t->char('proposal_uuid', 36);
            $t->char('generation_request_uuid', 36);
            $t->unsignedInteger('item_ordinal');
            $this->ids($t, ['template_id', 'activity_id', 'model_run_id']);
            $t->string('creation_mode', 32);
            $this->ids($t, ['framework_id', 'framework_version_id'], true);
            $this->hashes($t, ['basis_hash'], true);
            $t->string('kind', 32);
            $t->string('context_schema_version', 50);
            $this->hashes($t, ['context_hash', 'course_context_hash']);
            $this->dates($t, ['sources_sealed_at'], true);
            $t->unsignedInteger('source_count')->nullable();
            $this->hashes($t, ['source_set_hash'], true);
            $this->ids($t, ['supersedes_proposal_id'], true);
            $t->string('successor_reason', 64)->nullable();
            $t->boolean('inherited_decision_draft')->default(false);
            $this->ids($t, ['predecessor_revision_id'], true);
            $t->string('status', 32);
            $t->unsignedInteger('lock_version')->default(1);
            $this->ids($t, ['created_by']);
            $this->dates($t, ['created_at', 'updated_at']);
            $this->dates($t, ['deletion_requested_at', 'deleted_at'], true);
            $t->unique(['customer_id', 'proposal_uuid'], 'uk_ap_uuid');
            $t->unique(['customer_id', 'generation_request_uuid', 'item_ordinal'], 'uk_ap_item');
            $t->index(['customer_id', 'template_id', 'status', 'id'], 'idx_ap_status');
            $this->fk($t, ['customer_id', 'generation_request_uuid'], 'generation_requests', ['customer_id', 'request_uuid'], 'ap_request');
            $this->fk($t, ['model_run_id', 'customer_id'], 'ai_model_runs', ['id', 'customer_id'], 'ap_run');
            $this->actor($t, 'created_by', 'ap_actor');
            $this->fk($t, ['framework_version_id', 'customer_id', 'framework_id'], 'core_learning_framework_versions', ['id', 'customer_id', 'framework_id'], 'ap_version');
            $this->fk($t, ['supersedes_proposal_id', 'customer_id'], 'proposals', ['id', 'customer_id'], 'ap_parent');
        });
        $this->checks(self::PREFIX.'proposals', 'ap', [
            'inherited' => 'inherited_decision_draft IN (0,1)',
            'reason' => "(creation_mode = 'generated' AND successor_reason IS NULL AND inherited_decision_draft = 0) OR (creation_mode = 'human_successor' AND successor_reason IS NOT NULL AND successor_reason IN ('source_revision_changed','context_changed','target_changed','intent_removed','human_correction'))",
            'inherited_reason' => "(successor_reason = 'source_revision_changed' AND inherited_decision_draft = 1) OR ((successor_reason IS NULL OR successor_reason <> 'source_revision_changed') AND inherited_decision_draft = 0)",
            'mode' => "creation_mode IN ('generated','human_successor')",
            'predecessor' => "(creation_mode = 'human_successor' AND supersedes_proposal_id IS NOT NULL AND predecessor_revision_id IS NOT NULL) OR (creation_mode = 'generated' AND predecessor_revision_id IS NULL)",
            'ordinal' => 'item_ordinal BETWEEN 1 AND 100',
            'lock' => 'lock_version >= 1',
            'seal' => '(sources_sealed_at IS NULL AND source_count IS NULL AND source_set_hash IS NULL) OR (sources_sealed_at IS NOT NULL AND source_count IS NOT NULL AND source_count > 0 AND source_set_hash IS NOT NULL)',
            'sealed_status' => "status = 'pending_review' OR sources_sealed_at IS NOT NULL",
            'kind' => "kind IN ('summary','concept','learning_objective','competency','node_mapping')",
            'status' => "status IN ('pending_review','accepted','rejected','stale','deletion_pending','deleted')",
            'framework' => '(framework_id IS NULL AND framework_version_id IS NULL) OR (framework_id IS NOT NULL AND framework_version_id IS NOT NULL)',
            'basis' => '(framework_id IS NULL AND basis_hash IS NULL) OR (framework_id IS NOT NULL AND basis_hash IS NOT NULL)',
            'learning' => "kind NOT IN ('competency','node_mapping') OR (framework_id IS NOT NULL AND framework_version_id IS NOT NULL)",
            'deletion' => "(status IN ('deletion_pending','deleted') AND deletion_requested_at IS NOT NULL) OR (status NOT IN ('deletion_pending','deleted') AND deletion_requested_at IS NULL)",
            'deleted' => "(status = 'deleted' AND deleted_at IS NOT NULL) OR (status <> 'deleted' AND deleted_at IS NULL)",
        ]);
    }

    private function revisions(): void
    {
        Schema::create('ai_authoring_proposal_revisions', function (Blueprint $t): void {
            $this->base($t, 'apr');
            $this->ids($t, ['proposal_id']);
            $t->unsignedInteger('revision_no');
            $t->string('origin', 32);
            $t->unsignedInteger('payload_schema_version');
            $t->json('payload')->nullable();
            $this->hashes($t, ['payload_hash']);
            $this->ids($t, ['created_by']);
            $this->dates($t, ['created_at']);
            $this->dates($t, ['erased_at'], true);
            $t->unique(['customer_id', 'proposal_id', 'revision_no'], 'uk_apr_revision');
            $t->unique(['id', 'customer_id', 'proposal_id'], 'uk_apr_parent');
            $this->fk($t, ['proposal_id', 'customer_id'], 'proposals', ['id', 'customer_id'], 'apr_parent');
            $this->actor($t, 'created_by', 'apr_actor');
        });
        $this->checks(self::PREFIX.'proposal_revisions', 'apr', [
            'version' => 'revision_no >= 1 AND payload_schema_version >= 1',
            'origin' => "origin IN ('generated','human_successor','human')",
            'payload' => '(payload IS NOT NULL AND erased_at IS NULL) OR (payload IS NULL AND erased_at IS NOT NULL)',
            'first' => "(origin IN ('generated','human_successor') AND revision_no = 1) OR (origin = 'human' AND revision_no > 1)",
        ]);
    }

    private function sources(): void
    {
        Schema::create('ai_authoring_proposal_sources', function (Blueprint $t): void {
            $this->base($t, 'aps');
            $this->ids($t, ['proposal_id', 'media_file_id']);
            $t->unsignedInteger('source_ordinal');
            $t->string('usage_type', 32);
            $t->string('content_type', 32);
            $t->string('locale', 20)->nullable();
            $this->hashes($t, ['source_fingerprint']);
            $t->string('processing_version', 100);
            $t->json('locator');
            $this->hashes($t, ['anchor_hash']);
            $t->text('excerpt')->nullable();
            $this->dates($t, ['created_at']);
            $this->dates($t, ['erased_at'], true);
            $t->unique(['customer_id', 'proposal_id', 'source_ordinal'], 'uk_aps_ordinal');
            $t->unique(['customer_id', 'proposal_id', 'anchor_hash'], 'uk_aps_anchor');
            $t->index(['customer_id', 'media_file_id', 'proposal_id'], 'idx_aps_media');
            $this->fk($t, ['proposal_id', 'customer_id'], 'proposals', ['id', 'customer_id'], 'aps_parent');
            $this->fk($t, ['media_file_id', 'customer_id'], 'media_files', ['id', 'customer_id'], 'aps_media');
        });
        $this->checks(self::PREFIX.'proposal_sources', 'aps', [
            'ordinal' => 'source_ordinal >= 1',
            'erasure' => 'erased_at IS NULL OR excerpt IS NULL',
            'usage' => "(usage_type = 'document' AND content_type IN ('extracted_text','region','table','formula')) OR (usage_type IN ('audio','video') AND content_type = 'transcript') OR (usage_type = 'video' AND content_type = 'video_frame_text')",
        ]);
    }

    private function reviews(): void
    {
        Schema::create('ai_authoring_proposal_reviews', function (Blueprint $t): void {
            $this->base($t, 'apv');
            $this->ids($t, ['proposal_id', 'revision_id', 'actor_id']);
            $t->char('request_uuid', 36);
            $this->hashes($t, ['command_hash']);
            $t->string('action', 32);
            $t->json('target_snapshot')->nullable();
            $this->hashes($t, ['target_hash'], true);
            $t->json('context_snapshot')->nullable();
            $this->hashes($t, ['context_hash'], true);
            $this->ids($t, ['application_id', 'result_version_id', 'result_framework_id'], true);
            $t->string('reason_code', 64)->nullable();
            $t->string('from_status', 32);
            $t->string('to_status', 32);
            $t->text('reason')->nullable();
            $this->dates($t, ['created_at']);
            $this->dates($t, ['erased_at'], true);
            $t->unique(['customer_id', 'request_uuid'], 'uk_apv_request');
            $t->index(['customer_id', 'proposal_id', 'id'], 'idx_apv_parent');
            $t->unique(['id', 'customer_id', 'proposal_id', 'revision_id'], 'uk_apv_revision');
            $this->fk($t, ['revision_id', 'customer_id', 'proposal_id'], 'proposal_revisions', ['id', 'customer_id', 'proposal_id'], 'apv_revision');
            $this->actor($t, 'actor_id', 'apv_actor');
            $this->fk($t, ['result_version_id', 'customer_id', 'result_framework_id'], 'core_learning_framework_versions', ['id', 'customer_id', 'framework_id'], 'apv_version');
        });
        $targets = "'confirm_target','reconfirm_target','reject_target','rebase_target','inherit_draft','rebase_selection'";
        $this->checks(self::PREFIX.'proposal_reviews', 'apv', [
            'action' => "action IN ('edit','accept','reject','approve_node','confirm_target','reconfirm_target','reject_target','rebase_target','inherit_draft','rebase_selection','reconfirm_context','cancel_application','apply_intent','retry_application')",
            'erasure' => 'erased_at IS NULL OR (reason IS NULL AND target_snapshot IS NULL AND context_snapshot IS NULL)',
            'target' => "(action IN ($targets) AND target_hash IS NOT NULL AND ((erased_at IS NULL AND target_snapshot IS NOT NULL) OR (erased_at IS NOT NULL AND target_snapshot IS NULL))) OR (action NOT IN ($targets) AND target_hash IS NULL AND target_snapshot IS NULL)",
            'transition' => "(action = 'edit' AND from_status = 'pending_review' AND to_status = 'pending_review') OR (action = 'accept' AND from_status = 'pending_review' AND to_status = 'accepted') OR (action = 'reject' AND from_status = 'pending_review' AND to_status = 'rejected') OR (action = 'approve_node' AND from_status = 'accepted' AND to_status = 'accepted') OR (action = 'cancel_application' AND from_status = 'stale' AND to_status = 'stale') OR (action IN ($targets,'reconfirm_context','cancel_application','apply_intent','retry_application') AND from_status = 'accepted' AND to_status = 'accepted')",
            'context' => "(action IN ('reconfirm_context','rebase_target') AND context_hash IS NOT NULL AND ((erased_at IS NULL AND context_snapshot IS NOT NULL) OR (erased_at IS NOT NULL AND context_snapshot IS NULL))) OR (action NOT IN ('reconfirm_context','rebase_target') AND context_hash IS NULL AND context_snapshot IS NULL)",
            'application' => "(action IN ('cancel_application','apply_intent','retry_application') AND application_id IS NOT NULL) OR (action NOT IN ('cancel_application','apply_intent','retry_application') AND application_id IS NULL)",
            'reason' => "(action = 'cancel_application' AND reason_code IS NOT NULL) OR (action <> 'cancel_application' AND reason_code IS NULL)",
            'result' => "(action IN ('inherit_draft','rebase_selection') AND result_version_id IS NOT NULL AND result_framework_id IS NOT NULL) OR (action NOT IN ('inherit_draft','rebase_selection') AND result_version_id IS NULL AND result_framework_id IS NULL)",
        ]);
    }

    private function applications(): void
    {
        Schema::create('ai_authoring_proposal_applications', function (Blueprint $t): void {
            $this->base($t, 'apa');
            $this->ids($t, ['proposal_id', 'revision_id']);
            $t->char('application_uuid', 36);
            $this->hashes($t, ['target_hash', 'command_hash', 'expected_basis_hash']);
            $this->hashes($t, ['result_basis_hash'], true);
            $this->ids($t, ['target_review_id'], true);
            $t->string('operation', 32);
            $t->string('status', 32);
            $this->ids($t, ['framework_id', 'framework_version_id']);
            $this->ids($t, ['node_id', 'intent_id', 'approved_by'], true);
            $t->string('error_code', 100)->nullable();
            $this->dates($t, ['created_at', 'updated_at']);
            $this->dates($t, ['applied_at', 'cancelled_at'], true);
            $t->string('cancellation_kind', 16)->nullable();
            $this->ids($t, ['cancelled_by'], true);
            $t->string('cancel_reason_code', 64)->nullable();
            $t->unique(['customer_id', 'application_uuid'], 'uk_apa_uuid');
            $t->unique(['customer_id', 'revision_id', 'operation'], 'uk_apa_operation');
            $t->index(['customer_id', 'status', 'id'], 'idx_apa_status');
            $this->fk($t, ['revision_id', 'customer_id', 'proposal_id'], 'proposal_revisions', ['id', 'customer_id', 'proposal_id'], 'apa_revision');
            $this->fk($t, ['target_review_id', 'customer_id', 'proposal_id', 'revision_id'], 'proposal_reviews', ['id', 'customer_id', 'proposal_id', 'revision_id'], 'apa_review');
            $this->fk($t, ['framework_version_id', 'customer_id', 'framework_id'], 'core_learning_framework_versions', ['id', 'customer_id', 'framework_id'], 'apa_version');
            $this->fk($t, ['node_id', 'customer_id', 'framework_id', 'framework_version_id'], 'core_learning_nodes', ['id', 'customer_id', 'framework_id', 'framework_version_id'], 'apa_node');
            $this->actor($t, 'approved_by', 'apa_approver');
            $this->actor($t, 'cancelled_by', 'apa_canceller');
        });
        $this->checks(self::PREFIX.'proposal_applications', 'apa', [
            'cancel' => "(status = 'cancelled' AND cancelled_at IS NOT NULL AND cancel_reason_code IS NOT NULL AND ((cancellation_kind = 'human' AND cancelled_by IS NOT NULL) OR (cancellation_kind = 'system' AND cancelled_by IS NULL)) AND cancellation_kind IS NOT NULL) OR (status <> 'cancelled' AND cancelled_at IS NULL AND cancel_reason_code IS NULL AND cancellation_kind IS NULL AND cancelled_by IS NULL)",
            'operation' => "operation IN ('create_node','apply_intent')",
            'status' => "status IN ('awaiting_publication','ready_to_apply','applied','failed','cancelled')",
            'creation_status' => "operation <> 'create_node' OR status <> 'awaiting_publication'",
            'creation_review' => "operation <> 'create_node' OR target_review_id IS NULL",
            'intent_review' => "operation <> 'apply_intent' OR status NOT IN ('ready_to_apply','applied') OR target_review_id IS NOT NULL",
            'result_basis' => "(operation = 'create_node' AND status = 'applied' AND result_basis_hash IS NOT NULL) OR ((operation <> 'create_node' OR status <> 'applied') AND result_basis_hash IS NULL)",
            'error' => "(status = 'failed' AND error_code IS NOT NULL) OR (status <> 'failed' AND error_code IS NULL)",
            'applied' => "(status = 'applied' AND applied_at IS NOT NULL AND approved_by IS NOT NULL) OR (status <> 'applied' AND applied_at IS NULL)",
            'results' => "(status = 'applied' AND operation = 'create_node' AND node_id IS NOT NULL AND intent_id IS NULL) OR (status = 'applied' AND operation = 'apply_intent' AND node_id IS NOT NULL AND intent_id IS NOT NULL) OR (status <> 'applied' AND intent_id IS NULL)",
            'creation_node' => "operation <> 'create_node' OR status = 'applied' OR node_id IS NULL",
        ]);
    }

    private function intents(): void
    {
        Schema::table(self::INTENTS, function (Blueprint $t): void {
            $this->ids($t, ['ai_proposal_id', 'ai_proposal_revision_id', 'ai_target_review_id', 'ai_context_review_id'], true);
            $this->fk($t, ['ai_proposal_revision_id', 'customer_id', 'ai_proposal_id'], 'proposal_revisions', ['id', 'customer_id', 'proposal_id'], 'cct_lmi_ai_revision');
            foreach (['target', 'context'] as $type) {
                $this->fk($t, ['ai_'.$type.'_review_id', 'customer_id', 'ai_proposal_id', 'ai_proposal_revision_id'], 'proposal_reviews', ['id', 'customer_id', 'proposal_id', 'revision_id'], 'cct_lmi_ai_'.$type);
            }
        });
        DB::unprepared('ALTER TABLE '.self::INTENTS.' DROP CONSTRAINT chk_cct_lmi_origin');
        $this->checks(self::INTENTS, 'cct_lmi', [
            'origin' => "origin IN ('manual','ai_proposal')",
            'ai_provenance' => "(origin = 'manual' AND ai_proposal_id IS NULL AND ai_proposal_revision_id IS NULL AND ai_target_review_id IS NULL AND ai_context_review_id IS NULL) OR (origin = 'ai_proposal' AND ai_proposal_id IS NOT NULL AND ai_proposal_revision_id IS NOT NULL AND ai_target_review_id IS NOT NULL)",
        ]);
    }

    private function historyTriggers(): void
    {
        // Identity comparisons are binary and NULL-safe, including JSON bytes.
        // No session-variable bypass and no trigger removal during erasure.
        $content = [
            'proposal_revisions' => ['payload'],
            'proposal_sources' => ['excerpt'],
            'proposal_reviews' => ['reason', 'target_snapshot', 'context_snapshot'],
        ];
        foreach ($content as $suffix => $erasable) {
            $table = self::PREFIX.$suffix;
            $key = ['proposal_revisions' => 'apr', 'proposal_sources' => 'aps', 'proposal_reviews' => 'apv'][$suffix];
            $immutable = $this->unchanged($table, [...$erasable, 'erased_at']);
            $all = $this->unchanged($table);
            $nulls = implode(' AND ', array_map(fn ($c) => "NEW.`$c` IS NULL", $erasable));
            $this->trigger($table, $key.'_update', 'UPDATE', "
                DECLARE parent_status VARCHAR(32) DEFAULT NULL;
                IF NOT ($all) THEN
                    SELECT status INTO parent_status FROM ai_authoring_proposals
                      WHERE id = OLD.proposal_id AND customer_id = OLD.customer_id FOR UPDATE;
                    IF NOT ($immutable) OR parent_status IS NULL OR parent_status <> 'deletion_pending'
                      OR OLD.erased_at IS NOT NULL OR NEW.erased_at IS NULL OR NOT ($nulls) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_HISTORY_IMMUTABLE';
                    END IF;
                END IF;");
            $extra = match ($suffix) {
                'proposal_sources' => "IF parent_status <> 'pending_review' OR parent_seal IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_SOURCES_SEALED'; END IF;",
                'proposal_revisions' => "IF parent_status <> 'pending_review' OR (NEW.revision_no = 1 AND BINARY NEW.origin <> BINARY parent_mode) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_REVISION_CLOSED'; END IF;",
                default => "IF parent_status IN ('deletion_pending','deleted') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_REVIEW_CLOSED'; END IF;",
            };
            $this->trigger($table, $key.'_insert', 'INSERT', "
                DECLARE parent_status VARCHAR(32) DEFAULT NULL;
                DECLARE parent_mode VARCHAR(32) DEFAULT NULL;
                DECLARE parent_seal DATETIME(6) DEFAULT NULL;
                SELECT status, creation_mode, sources_sealed_at INTO parent_status, parent_mode, parent_seal
                  FROM ai_authoring_proposals WHERE id = NEW.proposal_id AND customer_id = NEW.customer_id FOR UPDATE;
                IF parent_status IS NULL OR NEW.erased_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_PARENT_INVALID';
                END IF;
                $extra");
        }
        foreach (self::TABLES as $suffix) {
            $key = array_search($suffix, self::TABLES, true);
            $this->trigger(self::PREFIX.$suffix, 'ap_'.$key.'_delete', 'DELETE', "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_HISTORY_IMMUTABLE';");
        }
    }

    private function unchanged(string $table, array $mutable = []): string
    {
        return implode(' AND ', array_map(
            fn ($c) => "(BINARY OLD.`$c` <=> BINARY NEW.`$c`)",
            array_values(array_diff(Schema::getColumnListing($table), $mutable))
        ));
    }

    private function trigger(string $table, string $name, string $event, string $body): void
    {
        DB::unprepared("CREATE TRIGGER `trg_{$name}` BEFORE $event ON `$table` FOR EACH ROW BEGIN $body END");
    }

    private function aggregateTriggers(): void
    {
        $requests = self::PREFIX.'generation_requests';
        $requestIdentity = $this->unchanged($requests, ['model_run_id', 'status', 'item_count', 'error_code', 'updated_at', 'completed_at']);
        $requestAll = $this->unchanged($requests);
        $this->trigger($requests, 'agr_insert', 'INSERT', "
            IF NEW.status <> 'pending' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_REQUEST_INITIAL'; END IF;");
        $this->trigger($requests, 'agr_update', 'UPDATE', "
            IF NOT ($requestIdentity) OR (OLD.model_run_id IS NOT NULL AND NOT (OLD.model_run_id <=> NEW.model_run_id))
              OR (OLD.status IN ('completed','failed') AND NOT ($requestAll)) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_REQUEST_IMMUTABLE';
            END IF;
            IF NEW.status <> OLD.status AND NOT ((OLD.status = 'pending' AND NEW.status IN ('running','failed')) OR (OLD.status = 'running' AND NEW.status IN ('completed','failed'))) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_REQUEST_TRANSITION';
            END IF;
            IF NEW.status = 'completed' AND OLD.status <> 'completed' THEN
                IF NEW.item_count <> (SELECT COUNT(*) FROM ai_authoring_proposals WHERE customer_id = NEW.customer_id AND generation_request_uuid = NEW.request_uuid)
                  OR EXISTS (SELECT 1 FROM ai_authoring_proposals WHERE customer_id = NEW.customer_id AND generation_request_uuid = NEW.request_uuid AND (sources_sealed_at IS NULL OR BINARY creation_mode <> BINARY NEW.mode)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_REQUEST_INCOMPLETE';
                END IF;
            END IF;");

        $parents = self::PREFIX.'proposals';
        $identity = $this->unchanged($parents, ['status', 'lock_version', 'updated_at', 'sources_sealed_at', 'source_count', 'source_set_hash', 'deletion_requested_at', 'deleted_at']);
        $seal = implode(' AND ', array_map(fn ($c) => "(BINARY OLD.$c <=> BINARY NEW.$c)", ['sources_sealed_at', 'source_count', 'source_set_hash']));
        $parentAll = $this->unchanged($parents);
        $this->trigger($parents, 'ap_insert', 'INSERT', "
            DECLARE request_status VARCHAR(32) DEFAULT NULL;
            DECLARE request_mode VARCHAR(32) DEFAULT NULL;
            SELECT status, mode INTO request_status, request_mode FROM ai_authoring_generation_requests
              WHERE customer_id = NEW.customer_id AND request_uuid = NEW.generation_request_uuid FOR UPDATE;
            IF NEW.status <> 'pending_review' OR NEW.sources_sealed_at IS NOT NULL OR request_status IS NULL OR request_status <> 'running' OR BINARY request_mode <> BINARY NEW.creation_mode THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_CONSTRUCTION_INVALID';
            END IF;
            IF NEW.creation_mode = 'human_successor' AND NOT EXISTS (
                SELECT 1 FROM ai_authoring_proposal_reviews r JOIN ai_authoring_proposals p ON p.id = r.proposal_id AND p.customer_id = r.customer_id
                WHERE r.customer_id = NEW.customer_id AND r.proposal_id = NEW.supersedes_proposal_id AND r.revision_id = NEW.predecessor_revision_id AND r.action = 'accept'
                  AND (NEW.id = 0 OR p.id < NEW.id)
            ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_PREDECESSOR_INVALID'; END IF;");
        $this->trigger($parents, 'ap_update', 'UPDATE', "
            IF NOT ($identity) OR (OLD.sources_sealed_at IS NOT NULL AND NOT ($seal)) OR (OLD.status = 'deleted' AND NOT ($parentAll)) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_IDENTITY_IMMUTABLE';
            END IF;
            IF NEW.status <> OLD.status AND NOT (
                (OLD.status = 'pending_review' AND NEW.status IN ('accepted','rejected','stale','deletion_pending')) OR
                (OLD.status = 'accepted' AND NEW.status IN ('stale','deletion_pending')) OR
                (OLD.status IN ('rejected','stale') AND NEW.status = 'deletion_pending') OR
                (OLD.status = 'deletion_pending' AND NEW.status = 'deleted')
            ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_STATUS_TRANSITION'; END IF;
            IF OLD.sources_sealed_at IS NULL AND NEW.sources_sealed_at IS NOT NULL AND
              NEW.source_count <> (SELECT COUNT(*) FROM ai_authoring_proposal_sources WHERE proposal_id = OLD.id AND customer_id = OLD.customer_id) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_SOURCE_COUNT';
            END IF;
            IF NEW.status = 'deleted' AND (
                EXISTS (SELECT 1 FROM ai_authoring_proposal_revisions WHERE proposal_id = OLD.id AND customer_id = OLD.customer_id AND (payload IS NOT NULL OR erased_at IS NULL)) OR
                EXISTS (SELECT 1 FROM ai_authoring_proposal_sources WHERE proposal_id = OLD.id AND customer_id = OLD.customer_id AND (excerpt IS NOT NULL OR erased_at IS NULL)) OR
                EXISTS (SELECT 1 FROM ai_authoring_proposal_reviews WHERE proposal_id = OLD.id AND customer_id = OLD.customer_id AND (reason IS NOT NULL OR target_snapshot IS NOT NULL OR context_snapshot IS NOT NULL OR erased_at IS NULL))
            ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_ERASURE_INCOMPLETE'; END IF;");

        $applications = self::PREFIX.'proposal_applications';
        $appIdentity = $this->unchanged($applications, ['target_review_id', 'status', 'result_basis_hash', 'node_id', 'intent_id', 'error_code', 'updated_at', 'applied_at', 'cancelled_at', 'cancellation_kind', 'cancelled_by', 'cancel_reason_code']);
        $appAll = $this->unchanged($applications);
        $this->trigger($applications, 'apa_update', 'UPDATE', "
            IF NOT ($appIdentity) OR (OLD.status IN ('applied','cancelled') AND NOT ($appAll)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_APPLICATION_IMMUTABLE'; END IF;
            IF NEW.status <> OLD.status AND NOT (
                (OLD.status = 'awaiting_publication' AND NEW.status IN ('ready_to_apply','cancelled')) OR
                (OLD.status = 'ready_to_apply' AND NEW.status IN ('applied','failed','cancelled')) OR
                (OLD.status = 'failed' AND NEW.status IN ('ready_to_apply','cancelled'))
            ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'LF_PROPOSAL_APPLICATION_TRANSITION'; END IF;");
    }

    public function down(): void
    {
        if (! $this->supported()) {
            return;
        }
        // MariaDB DDL auto-commits: inspect the WHOLE packet before any DDL.
        foreach (self::TABLES as $suffix) {
            if (Schema::hasTable(self::PREFIX.$suffix) && DB::table(self::PREFIX.$suffix)->exists()) {
                throw new RuntimeException('LF_AUTHORING_ROLLBACK_REFUSED: retained history in '.self::PREFIX.$suffix);
            }
        }
        if (DB::table(self::INTENTS)->where('origin', 'ai_proposal')->orWhereNotNull('ai_proposal_id')->orWhereNotNull('ai_proposal_revision_id')->orWhereNotNull('ai_target_review_id')->orWhereNotNull('ai_context_review_id')->exists()) {
            throw new RuntimeException('LF_AUTHORING_ROLLBACK_REFUSED: Course provenance remains');
        }
        Schema::table(self::INTENTS, function (Blueprint $t): void {
            foreach (['revision', 'target', 'context'] as $key) {
                $t->dropForeign('fk_cct_lmi_ai_'.$key);
            }
        });
        DB::unprepared('ALTER TABLE '.self::INTENTS.' DROP CONSTRAINT chk_cct_lmi_ai_provenance, DROP CONSTRAINT chk_cct_lmi_origin');
        Schema::table(self::INTENTS, function (Blueprint $t): void {
            $t->dropColumn(['ai_proposal_id', 'ai_proposal_revision_id', 'ai_target_review_id', 'ai_context_review_id']);
        });
        $this->checks(self::INTENTS, 'cct_lmi', ['origin' => "origin = 'manual'"]);
        Schema::table(self::PREFIX.'proposals', fn (Blueprint $t) => $t->dropForeign('fk_ap_predecessor'));
        foreach (array_reverse(self::TABLES) as $suffix) {
            Schema::drop(self::PREFIX.$suffix);
        }
    }
};
