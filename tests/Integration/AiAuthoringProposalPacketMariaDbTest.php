<?php

namespace Tests\Integration;

use App\Services\LearningFrameworkAuthoringService;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** Physical schema proof only: this does not certify authoring permissions or providers. */
class AiAuthoringProposalPacketMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = 'ai_authoring_';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The authoring packet requires real MariaDB and Learning Foundation.');
        }
        TenantContext::set(null);
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    public function test_all_six_tables_have_tenant_foreign_keys_and_explicit_datetime_precision(): void
    {
        foreach (['generation_requests', 'proposals', 'proposal_revisions', 'proposal_sources', 'proposal_reviews', 'proposal_applications'] as $suffix) {
            $table = self::PREFIX.$suffix;
            $foreign = collect(Schema::getForeignKeys($table))->first(fn ($fk) => $fk['columns'] === ['customer_id']);
            $this->assertSame('saas_customers', $foreign['foreign_table']);
            $this->assertSame('restrict', strtolower($foreign['on_delete']));
            $date = DB::selectOne('SELECT DATA_TYPE, DATETIME_PRECISION, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [DB::getDatabaseName(), $table, 'created_at']);
            $this->assertSame('datetime', $date->DATA_TYPE);
            $this->assertSame(6, (int) $date->DATETIME_PRECISION);
            $this->assertStringNotContainsString('on update', strtolower($date->EXTRA));
        }
    }

    public function test_request_identity_and_terminal_zero_outcome_cannot_be_reused(): void
    {
        $f = $this->fixture();
        $r = $this->request($f);
        $this->reject(fn () => $this->request($f, ['request_uuid' => $r->request_uuid, 'run_uuid' => (string) Str::uuid()]), 'Duplicate');
        $this->reject(fn () => $this->table('generation_requests')->where('id', $r->id)->update(['command_hash' => str_repeat('b', 64)]), 'LF_PROPOSAL_REQUEST_IMMUTABLE');
        $this->table('generation_requests')->where('id', $r->id)->update(['status' => 'running']);
        $this->table('generation_requests')->where('id', $r->id)->update(['status' => 'completed', 'item_count' => 0, 'completed_at' => now()]);
        $this->assertSame(0, (int) $this->table('generation_requests')->find($r->id)->item_count);
        $this->reject(fn () => $this->table('generation_requests')->where('id', $r->id)->update(['status' => 'running', 'completed_at' => null, 'item_count' => null]), 'LF_PROPOSAL_REQUEST_IMMUTABLE');
    }

    public function test_request_claim_is_conditional_and_completed_count_requires_sealed_items(): void
    {
        $f = $this->fixture();
        $r = $this->request($f);
        $query = fn () => $this->table('generation_requests')->where('id', $r->id)->where('status', 'pending');
        $this->assertSame(1, $query()->update(['status' => 'running']));
        $this->assertSame(0, $query()->update(['status' => 'running']));
        $p = $this->proposal($f, $r);
        $this->revision($f, $p);
        $this->reject(fn () => $this->table('generation_requests')->where('id', $r->id)->update(['status' => 'completed', 'item_count' => 1, 'completed_at' => now()]), 'LF_PROPOSAL_REQUEST_INCOMPLETE');
        $this->source($f, $p);
        $this->seal($p);
        $this->reject(fn () => $this->table('generation_requests')->where('id', $r->id)->update(['status' => 'completed', 'item_count' => 0, 'completed_at' => now()]), 'LF_PROPOSAL_REQUEST_INCOMPLETE');
        $this->table('generation_requests')->where('id', $r->id)->update(['status' => 'completed', 'item_count' => 1, 'completed_at' => now()]);
        $this->assertSame('completed', $this->table('generation_requests')->find($r->id)->status);
        $this->reject(fn () => $this->proposal($f, $r, ['item_ordinal' => 2]), 'LF_PROPOSAL_CONSTRUCTION_INVALID');
    }

    public function test_prompt_null_coherence_and_request_run_tenant_are_physical(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        foreach (['run_uuid', 'prompt_contract_id', 'prompt_hash', 'prompt_version'] as $column) {
            $this->reject(fn () => $this->request($f, [$column => null]), 'chk_agr_prompt');
        }
        $this->reject(fn () => $this->request($f, ['model_run_id' => $other['run']]), 'fk_agr_run');
        $this->reject(fn () => $this->request($f, ['actor_id' => $other['actor']]), 'fk_agr_actor');
        $this->reject(fn () => $this->request($f, ['mode' => 'human_successor']), 'chk_agr_prompt');
        $this->reject(fn () => $this->request($f, ['status' => 'completed', 'item_count' => 0, 'completed_at' => now()]), 'LF_PROPOSAL_REQUEST_INITIAL');
    }

    public function test_seal_requires_exact_nonzero_sources_and_cannot_be_reset_or_extended(): void
    {
        $f = $this->fixture();
        $r = $this->running($f);
        $p = $this->proposal($f, $r);
        $this->revision($f, $p);
        $this->reject(fn () => $this->seal($p), 'LF_PROPOSAL_SOURCE_COUNT');
        $this->source($f, $p);
        $this->reject(fn () => $this->seal($p, 2), 'LF_PROPOSAL_SOURCE_COUNT');
        $this->seal($p);
        $this->reject(fn () => $this->source($f, $p, ['source_ordinal' => 2, 'anchor_hash' => str_repeat('b', 64)]), 'LF_PROPOSAL_SOURCES_SEALED');
        $this->reject(fn () => $this->table('proposals')->where('id', $p)->update(['sources_sealed_at' => null, 'source_count' => null, 'source_set_hash' => null]), 'LF_PROPOSAL_IDENTITY_IMMUTABLE');
        $this->reject(fn () => $this->table('proposals')->where('id', $p)->update(['source_set_hash' => str_repeat('c', 64)]), 'LF_PROPOSAL_IDENTITY_IMMUTABLE');
    }

    public function test_tenant_revision_parent_and_media_constraints_reject_cross_references(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $p = $this->proposal($f, $this->running($f));
        $this->reject(fn () => $this->source($f, $p, ['media_file_id' => $other['media']]), 'fk_aps_media');
        $this->reject(fn () => $this->revision($f, $p, ['created_by' => $other['actor']]), 'fk_apr_actor');
        $rev = $this->revision($f, $p);
        $p2 = $this->proposal($f, $this->running($f));
        $this->reject(fn () => $this->review($f, $p2, $rev), 'fk_apv_revision');
        $this->reject(fn () => $this->source($other, $p), 'LF_PROPOSAL_PARENT_INVALID');
        $this->reject(fn () => $this->proposal($f, $this->running($f), ['model_run_id' => $other['run']]), 'fk_ap_run');
    }

    public function test_source_usage_pairs_and_anchor_identity_are_enforced(): void
    {
        $f = $this->fixture();
        $p = $this->proposal($f, $this->running($f));
        foreach ([['audio', 'region'], ['document', 'transcript'], ['audio', 'video_frame_text'], ['video', 'formula']] as [$usage, $content]) {
            $this->reject(fn () => $this->source($f, $p, ['usage_type' => $usage, 'content_type' => $content]), 'chk_aps_usage');
        }
        $this->source($f, $p);
        $this->reject(fn () => $this->source($f, $p, ['source_ordinal' => 2]), 'uk_aps_anchor');
        $this->reject(fn () => $this->source($f, $p, ['anchor_hash' => str_repeat('b', 64)]), 'uk_aps_ordinal');
    }

    public function test_revision_origin_and_immutable_payload_and_hash(): void
    {
        $f = $this->fixture();
        [$p, $rev] = $this->sealed($f);
        $this->reject(fn () => $this->revision($f, $p, ['revision_no' => 2]), 'chk_apr_first');
        $human = $this->revision($f, $p, ['revision_no' => 2, 'origin' => 'human']);
        $this->assertGreaterThan($rev, $human);
        $this->reject(fn () => $this->table('proposal_revisions')->where('id', $rev)->update(['payload' => '{"title":"changed"}']), 'LF_PROPOSAL_HISTORY_IMMUTABLE');
        $this->reject(fn () => $this->table('proposal_revisions')->where('id', $rev)->update(['payload_hash' => str_repeat('b', 64)]), 'LF_PROPOSAL_HISTORY_IMMUTABLE');
        $this->table('proposals')->where('id', $p)->update(['status' => 'accepted']);
        $this->reject(fn () => $this->revision($f, $p, ['revision_no' => 3, 'origin' => 'human']), 'LF_PROPOSAL_REVISION_CLOSED');
    }

    public function test_parent_state_cannot_reopen_and_identity_is_binary_immutable(): void
    {
        $f = $this->fixture();
        [$p] = $this->sealed($f);
        $this->reject(fn () => $this->table('proposals')->where('id', $p)->update(['context_hash' => str_repeat('A', 64)]), 'LF_PROPOSAL_IDENTITY_IMMUTABLE');
        $this->table('proposals')->where('id', $p)->update(['status' => 'accepted']);
        $this->reject(fn () => $this->table('proposals')->where('id', $p)->update(['status' => 'pending_review']), 'LF_PROPOSAL_STATUS_TRANSITION');
        $this->table('proposals')->where('id', $p)->update(['status' => 'stale']);
        $this->reject(fn () => $this->table('proposals')->where('id', $p)->update(['status' => 'accepted']), 'LF_PROPOSAL_STATUS_TRANSITION');
    }

    public function test_erasure_requires_deletion_pending_and_retains_all_hashes_and_audits(): void
    {
        $f = $this->fixture();
        [$p, $rev, $source] = $this->sealed($f);
        $review = $this->review($f, $p, $rev, ['reason' => 'Human explanation']);
        $this->reject(fn () => $this->table('proposal_revisions')->where('id', $rev)->update(['payload' => null, 'erased_at' => now()]), 'LF_PROPOSAL_HISTORY_IMMUTABLE');
        $this->table('proposals')->where('id', $p)->update(['status' => 'deletion_pending', 'deletion_requested_at' => now()]);
        $this->reject(fn () => $this->table('proposals')->where('id', $p)->update(['status' => 'deleted', 'deleted_at' => now()]), 'LF_PROPOSAL_ERASURE_INCOMPLETE');
        $this->reject(fn () => $this->table('proposal_sources')->where('id', $source)->update(['excerpt' => null, 'erased_at' => now(), 'processing_version' => 'forged']), 'LF_PROPOSAL_HISTORY_IMMUTABLE');
        $this->table('proposal_revisions')->where('id', $rev)->update(['payload' => null, 'erased_at' => now()]);
        $this->table('proposal_sources')->where('id', $source)->update(['excerpt' => null, 'erased_at' => now()]);
        $this->table('proposal_reviews')->where('id', $review)->update(['reason' => null, 'erased_at' => now()]);
        $this->table('proposals')->where('id', $p)->update(['status' => 'deleted', 'deleted_at' => now()]);
        $this->assertNull($this->table('proposal_revisions')->find($rev)->payload);
        $this->assertSame(str_repeat('a', 64), $this->table('proposal_revisions')->find($rev)->payload_hash);
        $this->assertSame(str_repeat('a', 64), $this->table('proposal_sources')->find($source)->anchor_hash);
        $this->assertSame(str_repeat('a', 64), $this->table('proposal_reviews')->find($review)->command_hash);
        $this->assertSame('deleted', $this->table('proposals')->find($p)->status);
        $this->reject(fn () => $this->table('proposal_revisions')->where('id', $rev)->update(['payload' => '{}', 'erased_at' => null]), 'LF_PROPOSAL_HISTORY_IMMUTABLE');
    }

    public function test_deletes_are_physically_blocked_for_all_six_tables(): void
    {
        $f = $this->fixture();
        [$p, $rev, $source, $request] = $this->sealed($f);
        $review = $this->review($f, $p, $rev);
        $learning = $this->learning($f);
        $application = $this->application($f, $p, $rev, $learning);
        foreach (['generation_requests' => $request->id, 'proposals' => $p, 'proposal_revisions' => $rev, 'proposal_sources' => $source, 'proposal_reviews' => $review, 'proposal_applications' => $application] as $suffix => $id) {
            $this->reject(fn () => $this->table($suffix)->where('id', $id)->delete(), 'LF_PROPOSAL_HISTORY_IMMUTABLE');
        }
    }

    public function test_review_action_context_and_cancellation_null_branches(): void
    {
        $f = $this->fixture();
        [$p, $rev] = $this->sealed($f);
        $this->reject(fn () => $this->review($f, $p, $rev, ['action' => 'confirm_target', 'from_status' => 'accepted', 'to_status' => 'accepted']), 'chk_apv_target');
        $this->reject(fn () => $this->review($f, $p, $rev, ['action' => 'reconfirm_context', 'from_status' => 'accepted', 'to_status' => 'accepted']), 'chk_apv_context');
        $this->reject(fn () => $this->review($f, $p, $rev, ['action' => 'cancel_application', 'from_status' => 'stale', 'to_status' => 'stale', 'application_id' => 100]), 'chk_apv_reason');
        $id = $this->review($f, $p, $rev, ['action' => 'cancel_application', 'from_status' => 'stale', 'to_status' => 'stale', 'application_id' => 100, 'reason_code' => 'source_revision_changed']);
        $this->assertNotNull($this->table('proposal_reviews')->find($id));
        $this->reject(fn () => $this->table('proposal_reviews')->where('id', $id)->update(['actor_id' => $f['actor'] + 1]), 'LF_PROPOSAL_HISTORY_IMMUTABLE');
    }

    public function test_target_and_context_snapshots_erase_without_losing_hashes(): void
    {
        $f = $this->fixture();
        [$p, $rev] = $this->sealed($f);
        $id = $this->review($f, $p, $rev, ['action' => 'rebase_target', 'from_status' => 'accepted', 'to_status' => 'accepted', 'target_snapshot' => '{"node":1}', 'target_hash' => str_repeat('b', 64), 'context_snapshot' => '{"title":"text"}', 'context_hash' => str_repeat('c', 64)]);
        $this->table('proposals')->where('id', $p)->update(['status' => 'deletion_pending', 'deletion_requested_at' => now()]);
        $this->reject(fn () => $this->table('proposal_reviews')->where('id', $id)->update(['erased_at' => now(), 'target_snapshot' => null]), 'LF_PROPOSAL_HISTORY_IMMUTABLE');
        $this->table('proposal_reviews')->where('id', $id)->update(['erased_at' => now(), 'target_snapshot' => null, 'context_snapshot' => null, 'reason' => null]);
        $saved = $this->table('proposal_reviews')->find($id);
        $this->assertSame(str_repeat('b', 64), $saved->target_hash);
        $this->assertSame(str_repeat('c', 64), $saved->context_hash);
    }

    public function test_human_successor_requires_exact_historically_accepted_predecessor(): void
    {
        $f = $this->fixture();
        [$p, $rev] = $this->sealed($f);
        $r = $this->running($f, ['mode' => 'human_successor', 'run_uuid' => null, 'model_run_id' => null, 'prompt_contract_id' => null, 'prompt_version' => null, 'prompt_hash' => null]);
        $fields = ['creation_mode' => 'human_successor', 'supersedes_proposal_id' => $p, 'predecessor_revision_id' => $rev, 'successor_reason' => 'source_revision_changed', 'inherited_decision_draft' => true];
        $this->reject(fn () => $this->proposal($f, $r, $fields), 'LF_PROPOSAL_PREDECESSOR_INVALID');
        $this->review($f, $p, $rev, ['action' => 'accept', 'to_status' => 'accepted']);
        $this->table('proposals')->where('id', $p)->update(['status' => 'accepted']);
        $successor = $this->proposal($f, $r, $fields);
        $this->revision($f, $successor, ['origin' => 'human_successor']);
        $this->source($f, $successor, ['processing_version' => 'new-revision']);
        $this->seal($successor);
        $this->table('generation_requests')->where('id', $r->id)->update(['status' => 'completed', 'item_count' => 1, 'completed_at' => now()]);
        $this->assertNull($this->table('generation_requests')->find($r->id)->model_run_id);
        $this->assertSame($f['run'], (int) $this->table('proposals')->find($successor)->model_run_id);
        $this->assertSame($rev, (int) $this->table('proposals')->find($successor)->predecessor_revision_id);
    }

    public function test_application_identity_prevents_new_uuid_from_creating_a_second_node(): void
    {
        $f = $this->fixture();
        [$p, $rev] = $this->sealed($f);
        $learning = $this->learning($f);
        $id = $this->application($f, $p, $rev, $learning);
        $this->reject(fn () => $this->application($f, $p, $rev, $learning), 'uk_apa_operation');
        $this->reject(fn () => $this->table('proposal_applications')->where('id', $id)->update(['target_hash' => str_repeat('b', 64)]), 'LF_PROPOSAL_APPLICATION_IMMUTABLE');
        $this->reject(fn () => $this->table('proposal_applications')->where('id', $id)->update(['node_id' => $learning['node']]), 'chk_apa_creation_node');
        $this->table('proposal_applications')->where('id', $id)->update(['status' => 'failed', 'error_code' => 'owner_conflict']);
        $this->table('proposal_applications')->where('id', $id)->update(['status' => 'ready_to_apply', 'error_code' => null]);
        $this->table('proposal_applications')->where('id', $id)->update(['status' => 'applied', 'applied_at' => now(), 'node_id' => $learning['node'], 'result_basis_hash' => str_repeat('b', 64)]);
        $this->reject(fn () => $this->table('proposal_applications')->where('id', $id)->update(['status' => 'failed', 'error_code' => 'retry']), 'LF_PROPOSAL_APPLICATION_IMMUTABLE');
    }

    public function test_application_cancellation_is_terminal_and_requires_coherent_actor(): void
    {
        $f = $this->fixture();
        [$p, $rev] = $this->sealed($f);
        $id = $this->application($f, $p, $rev, $this->learning($f));
        $changes = ['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason_code' => 'source_deleted'];
        $this->reject(fn () => $this->table('proposal_applications')->where('id', $id)->update($changes), 'chk_apa_cancel');
        $this->reject(fn () => $this->table('proposal_applications')->where('id', $id)->update($changes + ['cancellation_kind' => 'human']), 'chk_apa_cancel');
        $this->table('proposal_applications')->where('id', $id)->update($changes + ['cancellation_kind' => 'system']);
        $this->reject(fn () => $this->table('proposal_applications')->where('id', $id)->update(['status' => 'ready_to_apply']), 'LF_PROPOSAL_APPLICATION_IMMUTABLE');
    }

    public function test_application_confirmation_must_belong_to_same_revision_and_freezes_when_applied(): void
    {
        $f = $this->fixture();
        [$p, $rev] = $this->sealed($f);
        [$p2, $rev2] = $this->sealed($f);
        $review = $this->review($f, $p, $rev, ['action' => 'confirm_target', 'from_status' => 'accepted', 'to_status' => 'accepted', 'target_hash' => str_repeat('a', 64), 'target_snapshot' => '{}']);
        $wrong = $this->review($f, $p2, $rev2);
        $learning = $this->learning($f);
        $fields = ['operation' => 'apply_intent', 'node_id' => $learning['node'], 'target_review_id' => $wrong];
        $this->reject(fn () => $this->application($f, $p, $rev, $learning, $fields), 'fk_apa_review');
        $fields['target_review_id'] = $review;
        $id = $this->application($f, $p, $rev, $learning, $fields);
        $this->table('proposal_applications')->where('id', $id)->update(['status' => 'applied', 'applied_at' => now(), 'intent_id' => 900]);
        $otherReview = $this->review($f, $p, $rev, ['action' => 'reconfirm_target', 'from_status' => 'accepted', 'to_status' => 'accepted', 'target_hash' => str_repeat('b', 64), 'target_snapshot' => '{}']);
        $this->reject(fn () => $this->table('proposal_applications')->where('id', $id)->update(['target_review_id' => $otherReview]), 'LF_PROPOSAL_APPLICATION_IMMUTABLE');
    }

    public function test_course_manual_path_is_preserved_and_ai_provenance_is_same_parent(): void
    {
        $f = $this->fixture();
        [$p, $rev] = $this->sealed($f);
        [$p2, $rev2] = $this->sealed($f);
        $review = $this->review($f, $p, $rev);
        $wrong = $this->review($f, $p2, $rev2);
        $l = $this->learning($f);
        $template = DB::table('core_course_templates')->insertGetId(['customer_id' => $f['customer'], 'title' => 'Manual unchanged', 'status' => 'active', 'created_by' => $f['actor'], 'selected_learning_framework_id' => $l['framework'], 'selected_learning_framework_version_id' => $l['version'], 'created_at' => now(), 'updated_at' => now()]);
        $base = ['customer_id' => $f['customer'], 'template_id' => $template, 'source_type' => 'course_template_activity', 'source_id' => 200, 'framework_id' => $l['framework'], 'framework_version_id' => $l['version'], 'learning_node_id' => $l['node'], 'mapping_role' => 'teaches', 'created_by' => $f['actor'], 'updated_by' => $f['actor'], 'created_at' => now(), 'updated_at' => now()];
        $table = DB::table('core_course_template_learning_mapping_intents');
        $id = $table->insertGetId($base);
        $this->assertSame('manual', (clone $table)->find($id)->origin);
        $this->assertNull((clone $table)->find($id)->ai_proposal_id);
        $ai = $base + ['origin' => 'ai_proposal', 'ai_proposal_id' => $p, 'ai_proposal_revision_id' => $rev, 'ai_target_review_id' => $review];
        $ai['source_id'] = 201;
        $bad = $ai;
        $bad['ai_target_review_id'] = null;
        $this->reject(fn () => $table->insert($bad), 'chk_cct_lmi_ai_provenance');
        $bad['ai_target_review_id'] = $wrong;
        $this->reject(fn () => $table->insert($bad), 'fk_cct_lmi_ai_target');
        $aiId = $table->insertGetId($ai);
        $this->assertSame($p, (int) (clone $table)->find($aiId)->ai_proposal_id);
        // Canonical Mapping is not materialized by a schema insertion or accept.
        $this->assertSame(0, DB::table('core_learning_node_mappings')->where('customer_id', $f['customer'])->count());
    }

    public function test_rollback_preflight_does_not_remove_any_constraint_or_table(): void
    {
        $f = $this->fixture();
        $this->request($f);
        $migration = require database_path('migrations/2026_09_15_000100_create_ai_authoring_proposal_packet.php');
        $before = DB::select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME', [DB::getDatabaseName()]);
        try {
            $migration->down();
            $this->fail('Rollback must refuse retained history.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('LF_AUTHORING_ROLLBACK_REFUSED', $e->getMessage());
        }
        foreach (['generation_requests', 'proposals', 'proposal_revisions', 'proposal_sources', 'proposal_reviews', 'proposal_applications'] as $suffix) {
            $this->assertTrue(Schema::hasTable(self::PREFIX.$suffix));
        }
        $this->assertTrue(Schema::hasColumn('core_course_template_learning_mapping_intents', 'ai_proposal_id'));
        $after = DB::select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME', [DB::getDatabaseName()]);
        $this->assertEquals($before, $after);
    }

    public function test_failed_construction_rolls_back_request_items_revisions_and_source_seal(): void
    {
        $f = $this->fixture();
        $r = $this->request($f);
        try {
            DB::transaction(function () use ($f, $r): void {
                $this->table('generation_requests')->where('id', $r->id)->update(['status' => 'running']);
                $p = $this->proposal($f, $r);
                $this->revision($f, $p);
                $this->source($f, $p);
                $this->seal($p);
                throw new RuntimeException('simulated interruption');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('simulated interruption', $e->getMessage());
        }
        $this->assertSame('pending', $this->table('generation_requests')->find($r->id)->status);
        foreach (['proposals', 'proposal_revisions', 'proposal_sources'] as $suffix) {
            $this->assertSame(0, $this->table($suffix)->where('customer_id', $f['customer'])->count());
        }
    }

    private function table(string $suffix): Builder
    {
        return DB::table(self::PREFIX.$suffix);
    }

    private function reject(callable $write, string $expected): void
    {
        try {
            $write();
            $this->fail('Expected database rejection: '.$expected);
        } catch (QueryException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    private function fixture(): array
    {
        $uuid = (string) Str::uuid();
        $customer = DB::table('saas_customers')->insertGetId(['name' => 'Authoring', 'slug' => $uuid, 'subdomain' => $uuid, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $actor = DB::table('users')->insertGetId(['customer_id' => $customer, 'name' => 'Admin', 'email' => $uuid.'@example.test', 'password' => 'test-only-not-used', 'role' => 'customer_admin', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $run = DB::table('ai_model_runs')->insertGetId(['customer_id' => $customer, 'run_uuid' => (string) Str::uuid(), 'prompt_hash' => str_repeat('a', 64), 'purpose' => 'authoring_proposal', 'provider' => 'fixture', 'model' => 'fixture', 'status' => 'completed', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $media = DB::table('media_files')->insertGetId(['customer_id' => $customer, 'uploaded_by' => $actor, 'file_type' => 'document', 'mime_type' => 'application/pdf', 'original_name' => 'fixture.pdf', 'display_name' => 'Fixture', 'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_bucket' => 'test', 'storage_key' => $uuid.'.pdf', 'file_size_bytes' => 1, 'visibility' => 'private', 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);

        return compact('customer', 'actor', 'run', 'media');
    }

    private function request(array $f, array $changes = []): object
    {
        $id = $this->table('generation_requests')->insertGetId(array_replace(['customer_id' => $f['customer'], 'request_uuid' => (string) Str::uuid(), 'command_hash' => str_repeat('a', 64), 'mode' => 'generated', 'actor_id' => $f['actor'], 'template_id' => 10, 'activity_id' => 20, 'run_uuid' => (string) Str::uuid(), 'model_run_id' => $f['run'], 'prompt_contract_id' => 'authoring-v1', 'prompt_version' => 1, 'prompt_hash' => str_repeat('a', 64), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()], $changes));

        return $this->table('generation_requests')->find($id);
    }

    private function running(array $f, array $changes = []): object
    {
        $r = $this->request($f, $changes);
        $this->table('generation_requests')->where('id', $r->id)->update(['status' => 'running']);

        return $r;
    }

    private function proposal(array $f, object $r, array $changes = []): int
    {
        return $this->table('proposals')->insertGetId(array_replace(['customer_id' => $f['customer'], 'proposal_uuid' => (string) Str::uuid(), 'generation_request_uuid' => $r->request_uuid, 'item_ordinal' => 1, 'template_id' => 10, 'activity_id' => 20, 'model_run_id' => $f['run'], 'creation_mode' => 'generated', 'kind' => 'summary', 'context_schema_version' => '1', 'context_hash' => str_repeat('a', 64), 'course_context_hash' => str_repeat('a', 64), 'status' => 'pending_review', 'created_by' => $f['actor'], 'created_at' => now(), 'updated_at' => now()], $changes));
    }

    private function revision(array $f, int $p, array $changes = []): int
    {
        return $this->table('proposal_revisions')->insertGetId(array_replace(['customer_id' => $f['customer'], 'proposal_id' => $p, 'revision_no' => 1, 'origin' => 'generated', 'payload_schema_version' => 1, 'payload' => '{"title":"Suggestion"}', 'payload_hash' => str_repeat('a', 64), 'created_by' => $f['actor'], 'created_at' => now()], $changes));
    }

    private function source(array $f, int $p, array $changes = []): int
    {
        return $this->table('proposal_sources')->insertGetId(array_replace(['customer_id' => $f['customer'], 'proposal_id' => $p, 'media_file_id' => $f['media'], 'source_ordinal' => 1, 'usage_type' => 'document', 'content_type' => 'extracted_text', 'source_fingerprint' => str_repeat('a', 64), 'processing_version' => 'v1', 'locator' => '{"page":1}', 'anchor_hash' => str_repeat('a', 64), 'excerpt' => 'Source text', 'created_at' => now()], $changes));
    }

    private function seal(int $p, int $count = 1): void
    {
        $this->table('proposals')->where('id', $p)->update(['sources_sealed_at' => now(), 'source_count' => $count, 'source_set_hash' => str_repeat('a', 64)]);
    }

    private function sealed(array $f): array
    {
        $r = $this->running($f);
        $p = $this->proposal($f, $r);
        $rev = $this->revision($f, $p);
        $source = $this->source($f, $p);
        $this->seal($p);

        return [$p, $rev, $source, $r];
    }

    private function review(array $f, int $p, int $rev, array $changes = []): int
    {
        return $this->table('proposal_reviews')->insertGetId(array_replace(['customer_id' => $f['customer'], 'proposal_id' => $p, 'revision_id' => $rev, 'actor_id' => $f['actor'], 'request_uuid' => (string) Str::uuid(), 'command_hash' => str_repeat('a', 64), 'action' => 'edit', 'from_status' => 'pending_review', 'to_status' => 'pending_review', 'created_at' => now()], $changes));
    }

    private function learning(array $f): array
    {
        TenantContext::set((object) ['id' => $f['customer']]);
        $owner = app(LearningFrameworkAuthoringService::class);
        $fw = $owner->createFramework($f['actor'], ['code' => 'fw-'.Str::uuid(), 'name' => 'Fixture framework', 'mastery_scale_key' => 'direct', 'mastery_scale_version' => '1', 'mastery_scale' => ['levels' => [['key' => 'novice', 'threshold' => 0], ['key' => 'mastered', 'threshold' => 0.8]]]]);
        $version = $owner->createDraftVersion($f['actor'], ['framework_id' => $fw->id, 'version_code' => 'v1', 'title' => 'Fixture']);
        $definition = $owner->createDefinition($f['actor'], ['framework_id' => $fw->id, 'code' => 'node-1', 'node_type' => 'competency', 'canonical_name' => 'Fixture competency']);
        $node = $owner->createNode($f['actor'], ['framework_version_id' => $version->id, 'node_definition_id' => $definition->id]);

        return ['framework' => (int) $fw->id, 'version' => (int) $version->id, 'node' => (int) $node->id];
    }

    private function application(array $f, int $p, int $rev, array $l, array $changes = []): int
    {
        return $this->table('proposal_applications')->insertGetId(array_replace(['customer_id' => $f['customer'], 'proposal_id' => $p, 'revision_id' => $rev, 'application_uuid' => (string) Str::uuid(), 'target_hash' => str_repeat('a', 64), 'command_hash' => str_repeat('a', 64), 'expected_basis_hash' => str_repeat('a', 64), 'operation' => 'create_node', 'status' => 'ready_to_apply', 'framework_id' => $l['framework'], 'framework_version_id' => $l['version'], 'approved_by' => $f['actor'], 'created_at' => now(), 'updated_at' => now()], $changes));
    }
}
