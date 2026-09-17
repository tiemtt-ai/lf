<?php

namespace Tests\Integration;

use App\Exceptions\CourseAuthoringContextException;
use App\Services\AiAuthoringApplicationService;
use App\Services\AiAuthoringProposalService;
use App\Services\CourseAuthoringContextService;
use App\Services\CourseAuthoringIntentService;
use App\Services\CourseTemplateLearningMappingIntentService;
use App\Services\CourseTemplatePublishingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Ai\AuthoringMariaDbFixture;
use Tests\TestCase;

/**
 * Step 7 at Course publication on MariaDB: AI-origin Intents are revalidated
 * inside the publish transaction, fail it closed when anything moved, and
 * leave immutable content-free lineage on the canonical Mapping.
 */
class AiAuthoringPublicationMariaDbTest extends TestCase
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

    public function test_publish_promotes_an_ai_intent_with_content_free_lineage(): void
    {
        [$uuid] = $this->appliedIntent();

        $version = $this->publish();

        $mapping = DB::table('core_learning_node_mappings')->sole();
        $snapshot = json_decode($mapping->source_snapshot, true);
        $proposal = DB::table('ai_authoring_proposals')->sole();
        $revision = DB::table('ai_authoring_proposal_revisions')->sole();
        $this->assertSame($version->id, $snapshot['course_version_id']);
        $this->assertSame([
            'proposal_id' => (int) $proposal->id, 'proposal_uuid' => $uuid, 'revision_id' => (int) $revision->id,
            'payload_hash' => $revision->payload_hash,
            'target_review_id' => (int) DB::table('ai_authoring_proposal_reviews')->where('action', 'confirm_target')->value('id'),
            'target_hash' => DB::table('ai_authoring_proposal_reviews')->where('action', 'confirm_target')->value('target_hash'),
            'context_review_id' => null, 'context_hash' => $proposal->course_context_hash,
        ], $snapshot['ai_lineage']);
        $this->assertStringNotContainsString(json_decode($revision->payload, true)['body'], $mapping->source_snapshot);
    }

    public function test_source_drift_fails_the_publish_closed_and_keeps_the_intent(): void
    {
        $this->appliedIntent();
        DB::table('media_extracted_texts')->where('customer_id', $this->f['customer_id'])->update(['processing_version' => 'fake-v2']);
        DB::table('media_processing_jobs')->where('customer_id', $this->f['customer_id'])->where('job_type', 'ocr')->update(['processing_version' => 'fake-v2']);

        $this->assertPublishRefused('proposal_stale');
        $this->assertSame(1, DB::table('core_course_template_learning_mapping_intents')->count());
    }

    public function test_context_drift_blocks_publish_until_a_human_reconfirms_it(): void
    {
        [$uuid, $lock] = $this->appliedIntent();
        DB::table('core_course_template_activities')->where('id', $this->f['activity_id'])->update(['title' => 'Tên mới của hoạt động']);
        $this->assertPublishRefused('proposal_context_changed');

        $hash = $this->tenantService(CourseAuthoringContextService::class)->proposalContext($this->f['teacher_id'], $this->f['activity_id'])['course_context_hash'];
        $reconfirmed = $this->applications()->reconfirmContext($this->f['teacher_id'], $uuid, $lock, $hash, (string) Str::uuid());
        $this->publish();

        $this->assertNull($reconfirmed['error_code']);
        $review = DB::table('ai_authoring_proposal_reviews')->where('action', 'reconfirm_context')->sole();
        $this->assertSame((int) $review->id, (int) DB::table('core_course_template_learning_mapping_intents')->value('ai_context_review_id'));
        $lineage = json_decode(DB::table('core_learning_node_mappings')->value('source_snapshot'), true)['ai_lineage'];
        $this->assertSame([(int) $review->id, $hash], [$lineage['context_review_id'], $lineage['context_hash']]);
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_a_rejected_target_blocks_publish_until_a_fresh_confirmation(): void
    {
        [$uuid, $lock] = $this->appliedIntent();
        $hash = $this->applications()->targetPreview($this->f['teacher_id'], $uuid)['target_hash'];

        $rejected = $this->applications()->rejectTarget($this->f['teacher_id'], $uuid, $lock, $hash, (string) Str::uuid());
        $this->assertNull($rejected['error_code']);
        $this->assertSame('applied', DB::table('ai_authoring_proposal_applications')->value('status'));
        $this->assertPublishRefused('proposal_target_changed');

        $confirmed = $this->applications()->confirmTarget($this->f['teacher_id'], $uuid, $lock + 1, $hash, (string) Str::uuid());
        $this->publish();

        $this->assertNull($confirmed['error_code']);
        $this->assertSame(['confirm_target', 'reject_target', 'reconfirm_target'], DB::table('ai_authoring_proposal_reviews')
            ->whereIn('action', ['confirm_target', 'reject_target', 'reconfirm_target'])->orderBy('id')->pluck('action')->all());
        $this->assertSame(1, DB::table('core_learning_node_mappings')->count());
    }

    public function test_lineage_survives_deletion_of_the_mutable_intent(): void
    {
        $this->appliedIntent();
        $this->publish();
        $intent = DB::table('core_course_template_learning_mapping_intents')->sole();

        $this->tenantService(CourseTemplateLearningMappingIntentService::class)->destroy($this->f['customer_id'], $this->f['template_id'], (int) $intent->id);

        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')->count());
        $this->assertArrayHasKey('ai_lineage', json_decode(DB::table('core_learning_node_mappings')->value('source_snapshot'), true));
        $this->assertSame('applied', DB::table('ai_authoring_proposal_applications')->value('status'));
    }

    public function test_the_course_confirmation_port_refuses_a_lost_update(): void
    {
        $this->appliedIntent();
        $intent = DB::table('core_course_template_learning_mapping_intents')->sole();

        $this->expectException(CourseAuthoringContextException::class);
        $this->expectExceptionMessage('confirmation_conflict');
        DB::transaction(fn () => $this->tenantService(CourseAuthoringIntentService::class)->updateProposalConfirmations(
            $this->f['teacher_id'], $this->f['activity_id'], (int) $intent->id,
            (int) $intent->ai_target_review_id + 999, null, (int) $intent->ai_target_review_id, null,
        ));
    }

    public function test_manual_intents_publish_without_lineage(): void
    {
        $this->tenantService(CourseTemplateLearningMappingIntentService::class)->store($this->f['admin_id'], $this->f['customer_id'], $this->f['template_id'], [
            'source_type' => 'course_template_activity', 'source_id' => $this->f['activity_id'],
            'learning_node_id' => $this->f['node_id'], 'mapping_role' => 'teaches', 'weight' => null,
        ]);

        $this->publish();

        $this->assertArrayNotHasKey('ai_lineage', json_decode(DB::table('core_learning_node_mappings')->value('source_snapshot'), true));
    }

    // ---------------------------------------------------------------- Helpers

    private function applications(): AiAuthoringApplicationService
    {
        return $this->tenantService(AiAuthoringApplicationService::class);
    }

    /** @return array{0:string,1:int} proposal uuid and its current lock_version */
    private function appliedIntent(): array
    {
        $uuid = $this->generateAs($this->f['teacher_id'], ['node_mapping'])['proposals'][0]['proposal_uuid'];
        $this->tenantService(AiAuthoringProposalService::class)->decide($this->f['teacher_id'], $uuid, 'accept', 1, 1, (string) Str::uuid());
        $hash = $this->applications()->targetPreview($this->f['teacher_id'], $uuid)['target_hash'];
        $this->applications()->confirmTarget($this->f['teacher_id'], $uuid, 2, $hash, (string) Str::uuid());
        $applied = $this->applications()->applyIntent($this->f['teacher_id'], $uuid, 3, (string) Str::uuid());
        $this->assertNull($applied['error_code']);

        return [$uuid, 4];
    }

    private function publish(): object
    {
        return $this->tenantService(CourseTemplatePublishingService::class)->publish($this->f['customer_id'], $this->f['template_id'], $this->f['admin_id']);
    }

    private function assertPublishRefused(string $code): void
    {
        $versions = DB::table('core_course_template_versions')->count();
        try {
            $this->publish();
            $this->fail('Publish must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($code, json_encode($exception->errors()));
        }
        $this->assertSame($versions, DB::table('core_course_template_versions')->count());
        $this->assertSame(0, DB::table('core_learning_node_mappings')->count());
    }
}
