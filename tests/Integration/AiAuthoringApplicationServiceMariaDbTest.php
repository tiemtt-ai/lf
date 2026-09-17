<?php

namespace Tests\Integration;

use App\Services\AiAuthoringApplicationService;
use App\Services\AiAuthoringProposalService;
use App\Services\CourseTemplateLearningMappingIntentService;
use App\Services\LearningFrameworkAuthoringService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Ai\AuthoringMariaDbFixture;
use Tests\TestCase;

/**
 * Step 7 owner handoff on MariaDB: admin Node approval, human target
 * confirmation, Course Intent application and human cancellation. Every owner
 * write must land atomically with its AI receipt, and nothing is promoted.
 */
class AiAuthoringApplicationServiceMariaDbTest extends TestCase
{
    use AuthoringMariaDbFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The Step 7 authoring packet exists only on MariaDB.');
        }
        $this->setUpAuthoring();
        $this->approveProvider();
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------ Approve Node

    public function test_an_admin_accepts_and_creates_a_new_node_in_an_explicit_draft_in_one_command(): void
    {
        $uuid = $this->proposeNewNode();
        $draft = $this->draftVersion();

        $outcome = $this->applications()->approveNode($this->f['admin_id'], $uuid, $this->f['framework_id'], $draft, 1, 1, (string) Str::uuid());

        $this->assertNull($outcome['error_code']);
        $this->assertSame('accepted', DB::table('ai_authoring_proposals')->value('status'));
        $this->assertSame(['accept', 'approve_node'], DB::table('ai_authoring_proposal_reviews')->orderBy('id')->pluck('action')->all());
        $receipt = DB::table('ai_authoring_proposal_applications')->sole();
        $this->assertSame(['create_node', 'applied'], [$receipt->operation, $receipt->status]);
        $this->assertSame($this->f['admin_id'], (int) $receipt->approved_by);
        $node = DB::table('core_learning_nodes')->where('id', $receipt->node_id)->sole();
        $this->assertSame($draft, (int) $node->framework_version_id);
        $this->assertSame('NEW-1', $node->code_snapshot);
        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')->count());
        $this->assertSame(0, DB::table('core_learning_node_mappings')->count());
    }

    public function test_a_teacher_cannot_create_a_node_and_nothing_is_written(): void
    {
        $uuid = $this->proposeNewNode();
        $draft = $this->draftVersion();
        $nodes = DB::table('core_learning_nodes')->count();

        $outcome = $this->applications()->approveNode($this->f['teacher_id'], $uuid, $this->f['framework_id'], $draft, 1, 1, (string) Str::uuid());

        $this->assertSame('proposal_forbidden', $outcome['error_code']);
        $this->assertSame($nodes, DB::table('core_learning_nodes')->count());
        $this->assertSame(0, DB::table('ai_authoring_proposal_reviews')->count());
        $this->assertSame('pending_review', DB::table('ai_authoring_proposals')->value('status'));
    }

    public function test_node_approval_replays_and_never_creates_a_second_node(): void
    {
        $uuid = $this->proposeNewNode();
        $draft = $this->draftVersion();
        $request = (string) Str::uuid();
        $this->applications()->approveNode($this->f['admin_id'], $uuid, $this->f['framework_id'], $draft, 1, 1, $request);

        $replay = $this->applications()->approveNode($this->f['admin_id'], $uuid, $this->f['framework_id'], $draft, 1, 1, $request);
        $another = $this->applications()->approveNode($this->f['admin_id'], $uuid, $this->f['framework_id'], $draft, 1, 2, (string) Str::uuid());

        $this->assertTrue($replay['replayed']);
        $this->assertSame('proposal_idempotency_conflict', $another['error_code']);
        $this->assertSame(1, DB::table('core_learning_nodes')->where('framework_version_id', $draft)->count());
        $this->assertSame(1, DB::table('ai_authoring_proposal_applications')->count());
    }

    public function test_a_failed_owner_write_rolls_back_the_decisions_and_the_receipt(): void
    {
        $uuid = $this->proposeNewNode();
        $published = $this->f['version_id'];   // not a draft: Learning refuses

        $outcome = $this->applications()->approveNode($this->f['admin_id'], $uuid, $this->f['framework_id'], $published, 1, 1, (string) Str::uuid());

        $this->assertSame('framework_selection_conflict', $outcome['error_code']);
        $this->assertSame(0, DB::table('ai_authoring_proposal_reviews')->count());
        $this->assertSame(0, DB::table('ai_authoring_proposal_applications')->count());
        $this->assertSame('pending_review', DB::table('ai_authoring_proposals')->value('status'));
    }

    // ------------------------------------------------------- Confirm and apply

    public function test_failed_intent_retry_keeps_receipt_and_approver_records_retry_actor_and_replays(): void
    {
        $uuid = $this->acceptedReuse();
        $preview = $this->applications()->targetPreview($this->f['teacher_id'], $uuid);
        $this->applications()->confirmTarget($this->f['teacher_id'], $uuid, 2, $preview['target_hash'], (string) Str::uuid());
        $receipt = DB::table('ai_authoring_proposal_applications')->sole();
        DB::table('ai_authoring_proposal_applications')->where('id', $receipt->id)->update(['status' => 'failed', 'error_code' => 'owner_write_failed']);
        $request = (string) Str::uuid();
        $result = $this->applications()->retryApplication($this->f['admin_id'], $uuid, $receipt->application_uuid, 3, $request);
        $this->assertNull($result['error_code']);
        $this->assertSame('applied', DB::table('ai_authoring_proposal_applications')->value('status'));
        $this->assertSame($this->f['teacher_id'], (int) DB::table('ai_authoring_proposal_applications')->value('approved_by'));
        $review = DB::table('ai_authoring_proposal_reviews')->where('action', 'retry_application')->sole();
        $this->assertSame($this->f['admin_id'], (int) $review->actor_id);
        $this->assertSame((int) $receipt->id, (int) $review->application_id);
        $this->assertTrue($this->applications()->retryApplication($this->f['admin_id'], $uuid, $receipt->application_uuid, 3, $request)['replayed']);
        $this->assertSame('proposal_revision_conflict', $this->applications()->retryApplication($this->f['admin_id'], $uuid, $receipt->application_uuid, 4, (string) Str::uuid())['error_code']);
        $this->assertSame(1, DB::table('ai_authoring_proposal_applications')->count());
        $this->assertSame(1, DB::table('core_course_template_learning_mapping_intents')->count());
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_retry_refuses_revoked_actor_or_source_and_preserves_failed_receipt(): void
    {
        $uuid = $this->acceptedReuse();
        $preview = $this->applications()->targetPreview($this->f['teacher_id'], $uuid);
        $this->applications()->confirmTarget($this->f['teacher_id'], $uuid, 2, $preview['target_hash'], (string) Str::uuid());
        $receipt = DB::table('ai_authoring_proposal_applications')->sole();
        DB::table('ai_authoring_proposal_applications')->where('id', $receipt->id)->update(['status' => 'failed', 'error_code' => 'owner_write_failed']);
        $this->assertSame('proposal_not_found', $this->applications()->retryApplication($this->f['outsider_id'], $uuid, $receipt->application_uuid, 3, (string) Str::uuid())['error_code']);
        DB::table('media_file_usages')->where('owner_id', $this->f['activity_id'])->update(['status' => 'detached']);
        $this->assertSame('proposal_stale', $this->applications()->retryApplication($this->f['teacher_id'], $uuid, $receipt->application_uuid, 3, (string) Str::uuid())['error_code']);
        $this->assertSame('failed', DB::table('ai_authoring_proposal_applications')->value('status'));
        $this->assertSame(0, DB::table('ai_authoring_proposal_reviews')->where('action', 'retry_application')->count());
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_failed_node_retry_requires_admin_and_reuses_the_original_receipt(): void
    {
        $uuid = $this->proposeNewNode();
        $this->tenantService(AiAuthoringProposalService::class)->decide($this->f['teacher_id'], $uuid, 'accept', 1, 1, (string) Str::uuid());
        $draft = $this->draftVersion();
        $proposal = DB::table('ai_authoring_proposals')->sole();
        $revision = DB::table('ai_authoring_proposal_revisions')->sole();
        $receiptUuid = (string) Str::uuid();
        DB::table('ai_authoring_proposal_applications')->insert([
            'customer_id' => $this->f['customer_id'], 'proposal_id' => $proposal->id, 'revision_id' => $revision->id,
            'application_uuid' => $receiptUuid, 'operation' => 'create_node', 'status' => 'failed',
            'error_code' => 'owner_write_failed', 'target_hash' => hash('sha256', 'test-target'),
            'command_hash' => hash('sha256', 'test-command'), 'expected_basis_hash' => $proposal->basis_hash,
            'framework_id' => $this->f['framework_id'], 'framework_version_id' => $draft,
            'approved_by' => $this->f['admin_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('proposal_forbidden', $this->applications()->retryApplication($this->f['teacher_id'], $uuid, $receiptUuid, 2, (string) Str::uuid())['error_code']);
        $result = $this->applications()->retryApplication($this->f['admin_id'], $uuid, $receiptUuid, 2, (string) Str::uuid());
        $this->assertNull($result['error_code']);
        $this->assertSame('applied', DB::table('ai_authoring_proposal_applications')->value('status'));
        $this->assertSame(1, DB::table('ai_authoring_proposal_applications')->count());
        $this->assertSame(1, DB::table('core_learning_nodes')->where('framework_version_id', $draft)->count());
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_node_code_collision_with_different_content_is_not_silently_reused(): void
    {
        $uuid = $this->proposeNewNode();
        $draft = $this->draftVersion();
        $this->tenantService(LearningFrameworkAuthoringService::class)->createDefinition($this->f['admin_id'], [
            'framework_id' => $this->f['framework_id'], 'code' => 'NEW-1', 'node_type' => 'competency', 'canonical_name' => 'Different existing name',
        ]);
        $result = $this->applications()->approveNode($this->f['admin_id'], $uuid, $this->f['framework_id'], $draft, 1, 1, (string) Str::uuid());
        $this->assertSame(['invalid_proposal', 'definition_conflict'], [$result['error_code'], $result['detail']]);
        $this->assertSame(0, DB::table('ai_authoring_proposal_applications')->count());
        $this->assertSame(0, DB::table('core_learning_nodes')->where('framework_version_id', $draft)->count());
    }

    public function test_retry_owner_conflict_rolls_back_ready_transition_and_retry_audit(): void
    {
        $uuid = $this->acceptedReuse();
        $preview = $this->applications()->targetPreview($this->f['teacher_id'], $uuid);
        $this->applications()->confirmTarget($this->f['teacher_id'], $uuid, 2, $preview['target_hash'], (string) Str::uuid());
        $receipt = DB::table('ai_authoring_proposal_applications')->sole();
        DB::table('ai_authoring_proposal_applications')->where('id', $receipt->id)->update(['status' => 'failed', 'error_code' => 'owner_write_failed']);
        $this->tenantService(CourseTemplateLearningMappingIntentService::class)->store($this->f['admin_id'], $this->f['customer_id'], $this->f['template_id'], [
            'source_type' => 'course_template_activity', 'source_id' => $this->f['activity_id'],
            'learning_node_id' => $this->f['node_id'], 'mapping_role' => 'practices', 'weight' => null,
        ]);
        $result = $this->applications()->retryApplication($this->f['teacher_id'], $uuid, $receipt->application_uuid, 3, (string) Str::uuid());
        $this->assertSame('proposal_intent_conflict', $result['error_code']);
        $unchanged = DB::table('ai_authoring_proposal_applications')->sole();
        $this->assertSame(['failed', 'owner_write_failed'], [$unchanged->status, $unchanged->error_code]);
        $this->assertSame(0, DB::table('ai_authoring_proposal_reviews')->where('action', 'retry_application')->count());
        $this->assertSame('manual', DB::table('core_course_template_learning_mapping_intents')->value('origin'));
    }

    public function test_a_confirmed_reuse_target_becomes_an_ai_intent_with_provenance_and_no_mapping(): void
    {
        $uuid = $this->acceptedReuse();
        $preview = $this->applications()->targetPreview($this->f['teacher_id'], $uuid);

        $confirmed = $this->applications()->confirmTarget($this->f['teacher_id'], $uuid, 2, $preview['target_hash'], (string) Str::uuid());
        $applied = $this->applications()->applyIntent($this->f['teacher_id'], $uuid, 3, (string) Str::uuid());

        $this->assertSame('ready_to_apply', $confirmed['application_status']);
        $this->assertNull($applied['error_code']);
        $intent = DB::table('core_course_template_learning_mapping_intents')->sole();
        $review = DB::table('ai_authoring_proposal_reviews')->where('action', 'confirm_target')->sole();
        $this->assertSame('ai_proposal', $intent->origin);
        $this->assertSame((int) DB::table('ai_authoring_proposals')->value('id'), (int) $intent->ai_proposal_id);
        $this->assertSame((int) $review->revision_id, (int) $intent->ai_proposal_revision_id);
        $this->assertSame((int) $review->id, (int) $intent->ai_target_review_id);
        $this->assertSame($this->f['node_id'], (int) $intent->learning_node_id);
        $receipt = DB::table('ai_authoring_proposal_applications')->sole();
        $this->assertSame(['applied', (int) $intent->id], [$receipt->status, (int) $receipt->intent_id]);
        $this->assertSame((int) $receipt->id, (int) DB::table('ai_authoring_proposal_reviews')->where('action', 'apply_intent')->value('application_id'));
        $this->assertSame(0, DB::table('core_learning_node_mappings')->count());
    }

    public function test_a_stale_snapshot_hash_cannot_be_confirmed(): void
    {
        $uuid = $this->acceptedReuse();

        $this->assertSame('proposal_target_changed', $this->applications()->confirmTarget($this->f['teacher_id'], $uuid, 2, str_repeat('0', 64), (string) Str::uuid())['error_code']);
        $this->assertSame(0, DB::table('ai_authoring_proposal_applications')->count());
    }

    public function test_a_new_draft_node_waits_for_publication_and_a_changed_target_needs_reconfirmation(): void
    {
        $uuid = $this->proposeNewNode();
        $draft = $this->draftVersion();
        $nodeId = $this->applications()->approveNode($this->f['admin_id'], $uuid, $this->f['framework_id'], $draft, 1, 1, (string) Str::uuid())['node_id'];
        $first = $this->applications()->targetPreview($this->f['teacher_id'], $uuid);
        $confirmed = $this->applications()->confirmTarget($this->f['teacher_id'], $uuid, 2, $first['target_hash'], (string) Str::uuid());
        $this->assertSame('awaiting_publication', $confirmed['application_status']);
        $this->assertSame('invalid_proposal', $this->applications()->applyIntent($this->f['teacher_id'], $uuid, 3, (string) Str::uuid())['error_code']);

        // The admin changes the target's criteria while it is still a draft.
        $learning = $this->tenantService(LearningFrameworkAuthoringService::class);
        $node = DB::table('core_learning_nodes')->where('id', $nodeId)->sole();
        $learning->updateDraftNode($this->f['admin_id'], $nodeId, [
            'framework_version_id' => $draft, 'node_definition_id' => $node->node_definition_id, 'criteria' => ['level' => 'changed'],
        ]);
        $learning->publishVersion($this->f['admin_id'], $draft);
        $this->tenantService(CourseTemplateLearningMappingIntentService::class)
            ->select($this->f['admin_id'], $this->f['customer_id'], $this->f['template_id'], $this->f['framework_id'], $draft);

        $this->assertSame('proposal_target_changed', $this->applications()->applyIntent($this->f['teacher_id'], $uuid, 3, (string) Str::uuid())['error_code']);
        $second = $this->applications()->targetPreview($this->f['teacher_id'], $uuid);
        $this->assertNotSame($first['target_hash'], $second['target_hash']);
        $reconfirmed = $this->applications()->confirmTarget($this->f['teacher_id'], $uuid, 3, $second['target_hash'], (string) Str::uuid());
        $applied = $this->applications()->applyIntent($this->f['teacher_id'], $uuid, 4, (string) Str::uuid());

        $this->assertNull($reconfirmed['error_code']);
        $this->assertNull($applied['error_code']);
        $this->assertSame(['confirm_target', 'reconfirm_target'], DB::table('ai_authoring_proposal_reviews')
            ->whereIn('action', ['confirm_target', 'reconfirm_target'])->orderBy('id')->pluck('action')->all());
        $this->assertSame($nodeId, (int) DB::table('core_course_template_learning_mapping_intents')->value('learning_node_id'));
        $this->assertSame(1, DB::table('core_learning_nodes')->where('framework_version_id', $draft)->count());
        $this->assertCount(1, $this->provider->calls, 'Confirmation and application never call the provider.');
    }

    public function test_a_manual_intent_with_the_same_key_is_never_relabelled(): void
    {
        $uuid = $this->acceptedReuse();
        $preview = $this->applications()->targetPreview($this->f['teacher_id'], $uuid);
        $this->applications()->confirmTarget($this->f['teacher_id'], $uuid, 2, $preview['target_hash'], (string) Str::uuid());
        $this->tenantService(CourseTemplateLearningMappingIntentService::class)->store($this->f['admin_id'], $this->f['customer_id'], $this->f['template_id'], [
            'source_type' => 'course_template_activity', 'source_id' => $this->f['activity_id'],
            'learning_node_id' => $this->f['node_id'], 'mapping_role' => 'practices', 'weight' => null,
        ]);

        $outcome = $this->applications()->applyIntent($this->f['teacher_id'], $uuid, 3, (string) Str::uuid());

        $this->assertSame('proposal_intent_conflict', $outcome['error_code']);
        $this->assertSame('manual', DB::table('core_course_template_learning_mapping_intents')->sole()->origin);
        $this->assertSame('ready_to_apply', DB::table('ai_authoring_proposal_applications')->value('status'));
        $this->assertSame(0, DB::table('ai_authoring_proposal_reviews')->where('action', 'apply_intent')->count());
    }

    public function test_accepted_context_drift_and_a_detached_source_block_application(): void
    {
        $uuid = $this->acceptedReuse();
        $preview = $this->applications()->targetPreview($this->f['teacher_id'], $uuid);
        $this->applications()->confirmTarget($this->f['teacher_id'], $uuid, 2, $preview['target_hash'], (string) Str::uuid());

        DB::table('core_course_template_activities')->where('id', $this->f['activity_id'])->update(['title' => 'Đã đổi']);
        $this->assertSame('proposal_context_changed', $this->applications()->applyIntent($this->f['teacher_id'], $uuid, 3, (string) Str::uuid())['error_code']);

        DB::table('core_course_template_activities')->where('id', $this->f['activity_id'])->update(['title' => 'Đọc tài liệu']);
        DB::table('media_file_usages')->where('owner_id', $this->f['activity_id'])->update(['status' => 'detached']);
        $this->assertSame('proposal_stale', $this->applications()->applyIntent($this->f['teacher_id'], $uuid, 3, (string) Str::uuid())['error_code']);
        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')->count());
    }

    // ----------------------------------------------------------------- Cancel

    public function test_human_cancellation_is_audited_and_applied_work_cannot_be_cancelled(): void
    {
        $uuid = $this->acceptedReuse();
        $preview = $this->applications()->targetPreview($this->f['teacher_id'], $uuid);
        $receipt = $this->applications()->confirmTarget($this->f['teacher_id'], $uuid, 2, $preview['target_hash'], (string) Str::uuid())['application_uuid'];

        $cancelled = $this->applications()->cancelApplication($this->f['teacher_id'], $receipt, 'target_not_wanted', 3, (string) Str::uuid());

        $this->assertNull($cancelled['error_code']);
        $row = DB::table('ai_authoring_proposal_applications')->sole();
        $this->assertSame(['cancelled', 'human', $this->f['teacher_id'], 'target_not_wanted'], [$row->status, $row->cancellation_kind, (int) $row->cancelled_by, $row->cancel_reason_code]);
        $audit = DB::table('ai_authoring_proposal_reviews')->where('action', 'cancel_application')->sole();
        $this->assertSame([(int) $row->id, 'target_not_wanted'], [(int) $audit->application_id, $audit->reason_code]);
        $this->assertSame('proposal_revision_conflict', $this->applications()->applyIntent($this->f['teacher_id'], $uuid, 4, (string) Str::uuid())['error_code']);
        $this->assertSame('proposal_revision_conflict', $this->applications()->cancelApplication($this->f['teacher_id'], $receipt, 'again', 4, (string) Str::uuid())['error_code']);
    }

    // ---------------------------------------------------------------- Helpers

    private function applications(): AiAuthoringApplicationService
    {
        return $this->tenantService(AiAuthoringApplicationService::class);
    }

    private function acceptedReuse(): string
    {
        $uuid = $this->generateAs($this->f['teacher_id'], ['node_mapping'])['proposals'][0]['proposal_uuid'];
        $this->tenantService(AiAuthoringProposalService::class)->decide($this->f['teacher_id'], $uuid, 'accept', 1, 1, (string) Str::uuid());

        return $uuid;
    }

    private function proposeNewNode(): string
    {
        $this->provider->respondWith(fn () => json_encode(['items' => [[
            'kind' => 'node_mapping', 'title' => 'Năng lực mới', 'body' => 'Đề xuất năng lực chưa có.',
            'confidence' => 0.7, 'rationale' => 'Không có ứng viên khớp.', 'source_refs' => [1],
            'mapping' => [
                'mode' => 'propose_new', 'code' => 'NEW-1', 'label' => 'So sánh phân số', 'node_type' => 'competency',
                'criteria' => ['level' => 'initial'], 'role' => 'teaches', 'weight' => 0.5,
            ],
        ]]], JSON_UNESCAPED_UNICODE));

        return $this->generateAs($this->f['teacher_id'], ['node_mapping'])['proposals'][0]['proposal_uuid'];
    }

    private function draftVersion(): int
    {
        return (int) $this->tenantService(LearningFrameworkAuthoringService::class)->createDraftVersion($this->f['admin_id'], [
            'framework_id' => $this->f['framework_id'], 'version_code' => 'v2', 'title' => 'V2',
        ])->id;
    }
}
