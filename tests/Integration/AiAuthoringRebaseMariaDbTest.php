<?php

namespace Tests\Integration;

use App\Services\AiAuthoringApplicationService;
use App\Services\AiAuthoringProposalService;
use App\Services\AiAuthoringRebaseService;
use App\Services\CourseTemplateLearningMappingIntentService;
use App\Services\CourseTemplatePublishingService;
use App\Services\LearningAuthoringInheritanceService;
use App\Services\LearningFrameworkAuthoringService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Ai\AuthoringMariaDbFixture;
use Tests\TestCase;

/**
 * The approved new-Node path end to end on MariaDB (contract § P1-1): a Template
 * with manual and AI Intents on V1, an inherited draft V2 holding a new Node, an
 * explicit reviewed rebase, application of the new Node and the next Course
 * publish. Also the failure modes: an incomplete plan or a changed preview
 * restores everything.
 */
class AiAuthoringRebaseMariaDbTest extends TestCase
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

    public function test_manual_and_ai_intents_on_v1_move_to_an_inherited_v2_with_a_new_node_and_publish(): void
    {
        $manual = $this->manualIntent();
        $aiIntent = $this->appliedAiIntent();
        [$initiating, $lock] = $this->acceptedNewNodeProposal();

        $plan = $this->tenantService(LearningAuthoringInheritanceService::class)->preview($this->f['framework_id'], $this->f['version_id']);
        $inherited = $this->rebaseService()->inheritDraft($this->f['admin_id'], $initiating, $this->f['version_id'], 'v2', 'V2', $plan['source_graph_hash'], $plan['plan_hash'], (string) Str::uuid());
        $this->assertNull($inherited['error_code']);
        $v2 = $inherited['result_version_id'];
        $copiedNode = $inherited['node_map'][$this->f['node_id']];

        $newNode = $this->applications()->approveNode($this->f['admin_id'], $initiating, $this->f['framework_id'], $v2, 1, $lock + 1, (string) Str::uuid())['node_id'];
        $hash = $this->applications()->targetPreview($this->f['teacher_id'], $initiating)['target_hash'];
        $this->assertSame('awaiting_publication', $this->applications()->confirmTarget($this->f['teacher_id'], $initiating, $lock + 2, $hash, (string) Str::uuid())['application_status']);
        $this->tenantService(LearningFrameworkAuthoringService::class)->publishVersion($this->f['admin_id'], $v2);

        $preview = $this->rebaseService()->rebasePreview($this->f['admin_id'], $initiating, $v2);
        $this->assertSame([$copiedNode, $copiedNode], array_column($preview['preview']['intents'], 'proposed_node_id'));
        $rebased = $this->rebaseService()->rebaseSelection($this->f['admin_id'], $initiating, $v2, [
            $manual => ['disposition' => 'map', 'node_id' => $copiedNode],
            $aiIntent => ['disposition' => 'map', 'node_id' => $copiedNode],
        ], $preview['preview_hash'], (string) Str::uuid());

        $this->assertNull($rebased['error_code']);
        $this->assertSame($v2, (int) DB::table('core_course_templates')->where('id', $this->f['template_id'])->value('selected_learning_framework_version_id'));
        $intents = DB::table('core_course_template_learning_mapping_intents')->orderBy('id')->get();
        $this->assertSame([$copiedNode, $copiedNode], $intents->pluck('learning_node_id')->map(fn ($id) => (int) $id)->all());
        $rebaseReview = DB::table('ai_authoring_proposal_reviews')->where('action', 'rebase_target')->sole();
        $ai = $intents->firstWhere('origin', 'ai_proposal');
        $this->assertSame([(int) $rebaseReview->id, (int) $rebaseReview->id], [(int) $ai->ai_target_review_id, (int) $ai->ai_context_review_id]);
        $this->assertSame(1, DB::table('ai_authoring_proposal_applications')->where('operation', 'apply_intent')->where('status', 'applied')->count());

        $applied = $this->applications()->applyIntent($this->f['teacher_id'], $initiating, $lock + 4, (string) Str::uuid());
        $this->assertNull($applied['error_code']);
        $version = $this->tenantService(CourseTemplatePublishingService::class)->publish($this->f['customer_id'], $this->f['template_id'], $this->f['admin_id']);

        $mappings = DB::table('core_learning_node_mappings')->where('source_discriminator', (string) $version->id)->get();
        $this->assertCount(3, $mappings);
        $this->assertEqualsCanonicalizing([$copiedNode, $copiedNode, $newNode], $mappings->pluck('learning_node_id')->map(fn ($id) => (int) $id)->all());
        $lineages = $mappings->map(fn ($m) => json_decode($m->source_snapshot, true)['ai_lineage'] ?? null)->filter()->values();
        $this->assertCount(2, $lineages);
        $this->assertContains((int) $rebaseReview->id, $lineages->pluck('target_review_id')->all());
        $this->assertCount(2, $this->provider->calls, 'Inheritance, rebase and application never call the provider.');
    }

    public function test_an_incomplete_plan_or_a_changed_preview_restores_every_intent_and_the_selection(): void
    {
        $manual = $this->manualIntent();
        $aiIntent = $this->appliedAiIntent();
        [$initiating, $lock] = $this->acceptedNewNodeProposal();
        $plan = $this->tenantService(LearningAuthoringInheritanceService::class)->preview($this->f['framework_id'], $this->f['version_id']);
        $v2 = $this->rebaseService()->inheritDraft($this->f['admin_id'], $initiating, $this->f['version_id'], 'v2', 'V2', $plan['source_graph_hash'], $plan['plan_hash'], (string) Str::uuid())['result_version_id'];
        $this->tenantService(LearningFrameworkAuthoringService::class)->publishVersion($this->f['admin_id'], $v2);
        $preview = $this->rebaseService()->rebasePreview($this->f['admin_id'], $initiating, $v2);
        $before = DB::table('core_course_template_learning_mapping_intents')->orderBy('id')->get();
        $copied = $preview['preview']['intents'][0]['proposed_node_id'];

        $incomplete = $this->rebaseService()->rebaseSelection($this->f['admin_id'], $initiating, $v2, [
            $manual => ['disposition' => 'map', 'node_id' => $copied],
        ], $preview['preview_hash'], (string) Str::uuid());
        DB::table('core_course_template_activities')->where('id', $this->f['activity_id'])->update(['title' => 'Đổi sau khi xem trước']);
        $changed = $this->rebaseService()->rebaseSelection($this->f['admin_id'], $initiating, $v2, [
            $manual => ['disposition' => 'map', 'node_id' => $copied],
            $aiIntent => ['disposition' => 'remove_explicit', 'reason' => 'no_longer_relevant'],
        ], $preview['preview_hash'], (string) Str::uuid());

        $this->assertSame('invalid_proposal', $incomplete['error_code']);
        $this->assertSame('proposal_revision_conflict', $changed['error_code']);
        $this->assertEquals($before, DB::table('core_course_template_learning_mapping_intents')->orderBy('id')->get());
        $this->assertSame($this->f['version_id'], (int) DB::table('core_course_templates')->where('id', $this->f['template_id'])->value('selected_learning_framework_version_id'));
        $this->assertSame(0, DB::table('ai_authoring_proposal_reviews')->whereIn('action', ['rebase_target', 'rebase_selection'])->count());
    }

    public function test_a_changed_inheritance_plan_hash_copies_nothing(): void
    {
        [$initiating, $lock] = $this->acceptedNewNodeProposal();
        $versions = DB::table('core_learning_framework_versions')->count();

        $outcome = $this->rebaseService()->inheritDraft($this->f['admin_id'], $initiating, $this->f['version_id'], 'v2', 'V2', str_repeat('0', 64), str_repeat('0', 64), (string) Str::uuid());

        $this->assertSame('proposal_revision_conflict', $outcome['error_code']);
        $this->assertSame($versions, DB::table('core_learning_framework_versions')->count());
        $this->assertSame(0, DB::table('ai_authoring_proposal_reviews')->where('action', 'inherit_draft')->count());
        $this->assertSame('proposal_forbidden', $this->rebaseService()->inheritDraft($this->f['teacher_id'], $initiating, $this->f['version_id'], 'v2', 'V2', str_repeat('0', 64), str_repeat('0', 64), (string) Str::uuid())['error_code']);
    }

    // ---------------------------------------------------------------- Helpers

    private function rebaseService(): AiAuthoringRebaseService
    {
        return $this->tenantService(AiAuthoringRebaseService::class);
    }

    private function applications(): AiAuthoringApplicationService
    {
        return $this->tenantService(AiAuthoringApplicationService::class);
    }

    private function manualIntent(): int
    {
        $this->tenantService(CourseTemplateLearningMappingIntentService::class)->store($this->f['admin_id'], $this->f['customer_id'], $this->f['template_id'], [
            'source_type' => 'course_template_activity', 'source_id' => $this->f['activity_id'],
            'learning_node_id' => $this->f['node_id'], 'mapping_role' => 'teaches', 'weight' => null,
        ]);

        return (int) DB::table('core_course_template_learning_mapping_intents')->where('origin', 'manual')->value('id');
    }

    private function appliedAiIntent(): int
    {
        $proposals = $this->tenantService(AiAuthoringProposalService::class);
        $this->provider->respondWith(fn () => json_encode(['items' => [[
            'kind' => 'node_mapping', 'title' => 'Luyện tập', 'body' => 'B', 'confidence' => 0.6, 'rationale' => 'R', 'source_refs' => [1],
            'mapping' => ['mode' => 'reuse_existing', 'node_id' => $this->f['node_id'], 'definition_id' => $this->f['definition_id'], 'role' => 'practices', 'weight' => 0.5],
        ]]]));
        $uuid = $this->generateAs($this->f['teacher_id'], ['node_mapping'])['proposals'][0]['proposal_uuid'];
        $proposals->decide($this->f['teacher_id'], $uuid, 'accept', 1, 1, (string) Str::uuid());
        $hash = $this->applications()->targetPreview($this->f['teacher_id'], $uuid)['target_hash'];
        $this->applications()->confirmTarget($this->f['teacher_id'], $uuid, 2, $hash, (string) Str::uuid());
        $this->assertNull($this->applications()->applyIntent($this->f['teacher_id'], $uuid, 3, (string) Str::uuid())['error_code']);

        return (int) DB::table('core_course_template_learning_mapping_intents')->where('origin', 'ai_proposal')->value('id');
    }

    /** @return array{0:string,1:int} */
    private function acceptedNewNodeProposal(): array
    {
        $this->provider->respondWith(fn () => json_encode(['items' => [[
            'kind' => 'node_mapping', 'title' => 'Năng lực mới', 'body' => 'B', 'confidence' => 0.7, 'rationale' => 'R', 'source_refs' => [1],
            'mapping' => ['mode' => 'propose_new', 'code' => 'NEW-R', 'label' => 'So sánh', 'node_type' => 'competency', 'criteria' => null, 'role' => 'assesses', 'weight' => null],
        ]]]));
        $uuid = $this->generateAs($this->f['teacher_id'], ['node_mapping'])['proposals'][0]['proposal_uuid'];
        $this->tenantService(AiAuthoringProposalService::class)->decide($this->f['teacher_id'], $uuid, 'accept', 1, 1, (string) Str::uuid());

        return [$uuid, 2];
    }
}
