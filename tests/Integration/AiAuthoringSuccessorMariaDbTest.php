<?php

namespace Tests\Integration;

use App\Services\AiAuthoringApplicationService;
use App\Services\AiAuthoringProposalService;
use App\Services\AiAuthoringSuccessorService;
use App\Services\CourseTemplateLearningMappingIntentService;
use App\Services\LearningFrameworkAuthoringService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Ai\AuthoringMariaDbFixture;
use Tests\TestCase;

/**
 * Step 7 human successors on MariaDB, including the restricted inheritance of
 * an accepted decision after an OCR/transcript revision change (P1-N1). No
 * path here may call the provider again.
 */
class AiAuthoringSuccessorMariaDbTest extends TestCase
{
    use AuthoringMariaDbFixture;
    use RefreshDatabase;

    private const CANARY = 'CANARY-RATIONALE-7f3a';

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

    public function test_an_ocr_revision_bump_inherits_only_the_decision_fields_without_a_provider_call(): void
    {
        $predecessor = $this->acceptedSummary();
        $this->bumpOcrRevision();

        $preview = $this->successors()->preview($this->f['teacher_id'], $predecessor);
        $created = $this->createSuccessor($this->f['teacher_id'], $predecessor, 'source_revision_changed', null, (string) Str::uuid());

        $this->assertSame(['kind' => 'summary', 'title' => 'Tóm tắt bài học', 'body' => 'Bài học giới thiệu nội dung chính.', 'confidence' => null, 'rationale' => '', 'source_refs' => []], $preview['payload']);
        $this->assertNull($created['error_code']);
        $this->assertTrue($created['inherited_decision_draft']);
        $old = DB::table('ai_authoring_proposals')->where('proposal_uuid', $predecessor)->sole();
        $new = DB::table('ai_authoring_proposals')->where('proposal_uuid', $created['proposal_uuid'])->sole();
        $this->assertSame(['human_successor', 'pending_review', 'source_revision_changed'], [$new->creation_mode, $new->status, $new->successor_reason]);
        $this->assertSame([(int) $old->id, (int) $old->model_run_id], [(int) $new->supersedes_proposal_id, (int) $new->model_run_id]);
        $request = DB::table('ai_authoring_generation_requests')->where('request_uuid', $new->generation_request_uuid)->sole();
        $this->assertSame(['human_successor', null, null], [$request->mode, $request->run_uuid, $request->model_run_id]);
        $this->assertSame('fake-v2', DB::table('ai_authoring_proposal_sources')->where('proposal_id', $new->id)->value('processing_version'));
        $this->assertSame('human_successor', DB::table('ai_authoring_proposal_revisions')->where('proposal_id', $new->id)->value('origin'));
        $this->assertCount(1, $this->provider->calls);
        $this->assertSame(1, DB::table('ai_model_runs')->count());
    }

    public function test_old_rationale_never_leaks_into_preview_successor_or_ledger(): void
    {
        $predecessor = $this->acceptedSummary();
        $this->bumpOcrRevision();

        $preview = $this->successors()->preview($this->f['teacher_id'], $predecessor);
        $created = $this->createSuccessor($this->f['teacher_id'], $predecessor, 'source_revision_changed', null, (string) Str::uuid());

        $this->assertStringNotContainsString(self::CANARY, json_encode($preview));
        $this->assertStringNotContainsString(self::CANARY, json_encode($created));
        $new = DB::table('ai_authoring_proposals')->where('proposal_uuid', $created['proposal_uuid'])->sole();
        $this->assertStringNotContainsString(self::CANARY, (string) DB::table('ai_authoring_proposal_revisions')->where('proposal_id', $new->id)->value('payload'));
        $this->assertStringNotContainsString(self::CANARY, json_encode(DB::table('ai_authoring_generation_requests')->get()));
    }

    public function test_a_successor_needs_fresh_references_before_it_can_be_accepted(): void
    {
        $predecessor = $this->acceptedSummary();
        $this->bumpOcrRevision();
        $uuid = $this->createSuccessor($this->f['teacher_id'], $predecessor, 'source_revision_changed', null, (string) Str::uuid())['proposal_uuid'];

        $refused = $this->proposals()->decide($this->f['teacher_id'], $uuid, 'accept', 1, 1, (string) Str::uuid());
        $payload = $this->proposals()->show($this->f['teacher_id'], $uuid)['payload'];
        $payload['source_refs'] = [1];
        $payload['rationale'] = 'Đối chiếu lại với bản OCR mới.';
        $edited = $this->proposals()->edit($this->f['teacher_id'], $uuid, 1, 1, $payload, 1, (string) Str::uuid());
        $accepted = $this->proposals()->decide($this->f['teacher_id'], $uuid, 'accept', 2, 2, (string) Str::uuid());

        $this->assertSame(['invalid_proposal', 'source_refs'], [$refused['error_code'], $refused['detail']]);
        $this->assertNull($edited['error_code']);
        $this->assertNull($accepted['error_code']);
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_one_changed_fingerprint_refuses_the_whole_inheritance(): void
    {
        $predecessor = $this->acceptedSummary();
        DB::table('media_extracted_texts')->where('customer_id', $this->f['customer_id'])->update(['source_fingerprint' => str_repeat('e', 64), 'processing_version' => 'fake-v2']);
        DB::table('media_processing_jobs')->where('customer_id', $this->f['customer_id'])->where('job_type', 'ocr')->update(['source_fingerprint' => str_repeat('e', 64), 'processing_version' => 'fake-v2']);

        $this->assertSame('proposal_stale', $this->successors()->preview($this->f['teacher_id'], $predecessor)['error_code']);
        $this->assertSame('proposal_stale', $this->createSuccessor($this->f['teacher_id'], $predecessor, 'source_revision_changed', null, (string) Str::uuid())['error_code']);
        $this->assertSame(1, DB::table('ai_authoring_proposals')->count());
    }

    public function test_detachment_or_lost_authority_refuses_inheritance(): void
    {
        $predecessor = $this->acceptedSummary();
        $this->bumpOcrRevision();

        DB::table('core_course_template_teachers')->where('teacher_id', $this->f['teacher_id'])->update(['status' => 'inactive']);
        $this->assertSame('proposal_not_found', $this->successors()->preview($this->f['teacher_id'], $predecessor)['error_code']);

        DB::table('core_course_template_teachers')->where('teacher_id', $this->f['teacher_id'])->update(['status' => 'active']);
        DB::table('media_file_usages')->where('owner_id', $this->f['activity_id'])->update(['status' => 'detached']);
        $this->assertSame('proposal_stale', $this->successors()->preview($this->f['teacher_id'], $predecessor)['error_code']);
    }

    public function test_an_already_created_node_is_reused_never_created_again(): void
    {
        [$predecessor, $nodeId] = $this->acceptedNewNodeInSelectedVersion();
        $nodes = DB::table('core_learning_nodes')->count();
        $this->bumpOcrRevision();

        $created = $this->createSuccessor($this->f['teacher_id'], $predecessor, 'source_revision_changed', null, (string) Str::uuid());

        $this->assertNull($created['error_code']);
        $payload = $this->proposals()->show($this->f['teacher_id'], $created['proposal_uuid'])['payload'];
        $this->assertSame('reuse_existing', $payload['mapping']['mode']);
        $this->assertSame($nodeId, $payload['mapping']['node_id']);
        $this->assertSame($nodes, DB::table('core_learning_nodes')->count());
    }

    public function test_a_new_node_proposal_without_its_created_node_cannot_be_inherited(): void
    {
        $this->provider->respondWith(fn () => json_encode(['items' => [[
            'kind' => 'node_mapping', 'title' => 'Mới', 'body' => 'B', 'confidence' => 0.5, 'rationale' => 'R', 'source_refs' => [1],
            'mapping' => ['mode' => 'propose_new', 'code' => 'NEW-9', 'label' => 'L', 'node_type' => 'concept', 'criteria' => null, 'role' => 'teaches', 'weight' => null],
        ]]]));
        $uuid = $this->generateAs($this->f['teacher_id'], ['node_mapping'])['proposals'][0]['proposal_uuid'];
        $this->proposals()->decide($this->f['teacher_id'], $uuid, 'accept', 1, 1, (string) Str::uuid());
        $this->bumpOcrRevision();

        $this->assertSame('proposal_successor_node_conflict', $this->createSuccessor($this->f['teacher_id'], $uuid, 'source_revision_changed', null, (string) Str::uuid())['error_code']);
    }

    public function test_other_reasons_need_the_actors_own_payload_and_replay_exactly(): void
    {
        $predecessor = $this->acceptedSummary();
        $payload = ['kind' => 'summary', 'title' => 'Tự viết lại', 'body' => 'Nội dung mới.', 'confidence' => null, 'rationale' => 'Viết lại theo nguồn.', 'source_refs' => [1]];
        $request = (string) Str::uuid();

        $this->assertSame('invalid_proposal', $this->createSuccessor($this->f['teacher_id'], $predecessor, 'intent_removed', null, (string) Str::uuid())['error_code']);
        $first = $this->createSuccessor($this->f['teacher_id'], $predecessor, 'intent_removed', $payload, $request);
        $again = $this->createSuccessor($this->f['teacher_id'], $predecessor, 'intent_removed', $payload, $request);
        $payload['title'] = 'Khác';
        $conflict = $this->createSuccessor($this->f['teacher_id'], $predecessor, 'intent_removed', $payload, $request);

        $this->assertNull($first['error_code']);
        $this->assertFalse($first['inherited_decision_draft']);
        $this->assertSame($first['proposal_uuid'], $again['proposal_uuid']);
        $this->assertSame('proposal_idempotency_conflict', $conflict['error_code']);
        $this->assertSame(2, DB::table('ai_authoring_proposals')->count());
        $this->assertCount(1, $this->provider->calls);
    }

    // ---------------------------------------------------------------- Helpers

    public function test_successor_scope_is_explicit_and_unknown_duplicate_or_stale_selection_is_rejected(): void
    {
        $predecessor = $this->acceptedSummary();
        $scope = $this->successors()->sourceScope($this->f['teacher_id'], $predecessor);
        $keys = array_keys($scope['anchors']);
        $this->assertSame(1, $scope['available_count']);
        $this->assertSame(200, $scope['selection_limit']);
        foreach ([null, [], [$keys[0], $keys[0]], array_map(fn ($i) => hash('sha256', (string) $i), range(1, 201))] as $selection) {
            $outcome = $this->successors()->create($this->f['teacher_id'], $predecessor, 'source_revision_changed', null, (string) Str::uuid(), $selection);
            $this->assertSame('source_scope', $outcome['detail']);
        }
        $unknown = $this->successors()->create($this->f['teacher_id'], $predecessor, 'source_revision_changed', null, (string) Str::uuid(), [str_repeat('f', 64)]);
        $this->assertSame('source_scope_changed', $unknown['detail']);
        $this->bumpOcrRevision();
        $stale = $this->successors()->create($this->f['teacher_id'], $predecessor, 'source_revision_changed', null, (string) Str::uuid(), $keys);
        $this->assertSame('source_scope_changed', $stale['detail']);
        $this->assertSame(1, DB::table('ai_authoring_proposals')->count());
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_proposal_and_successor_disclosure_are_audited_without_payload_and_denials_are_recorded(): void
    {
        $predecessor = $this->acceptedSummary();
        $this->proposals()->show($this->f['teacher_id'], $predecessor);
        $this->bumpOcrRevision();
        $this->successors()->preview($this->f['teacher_id'], $predecessor);
        DB::table('media_file_usages')->where('owner_id', $this->f['activity_id'])->update(['status' => 'detached']);
        $denied = $this->proposals()->show($this->f['teacher_id'], $predecessor);
        $this->assertNull($denied['payload']);
        $logs = DB::table('media_access_logs')->where('customer_id', $this->f['customer_id'])->get()
            ->map(fn ($row) => json_decode($row->metadata ?? '{}', true))
            ->filter(fn ($meta) => in_array($meta['operation'] ?? null, ['authoring_proposal_retrieval', 'authoring_successor_preview'], true))->values();
        $this->assertSame(['allowed', 'allowed', 'denied'], $logs->pluck('decision')->all());
        $this->assertSame('detached', $logs->last()['error_code']);
        $this->assertStringNotContainsString(self::CANARY, $logs->toJson());
        $this->assertSame($predecessor, $logs->first()['proposal_uuid']);
        $this->assertNotNull($logs->first()['revision_id']);
    }

    public function test_successor_scope_seals_only_explicit_selected_anchors_and_changes_command_identity(): void
    {
        $predecessor = $this->acceptedSummary();
        $row = (array) DB::table('media_extracted_texts')->where('media_file_id', $this->f['media_id'])->first();
        unset($row['id']);
        $row['locator_value'] = '2';
        $row['sequence'] = 2;
        DB::table('media_extracted_texts')->insert($row);
        $scope = $this->successors()->sourceScope($this->f['teacher_id'], $predecessor);
        $this->assertSame(2, $scope['available_count']);
        $keys = array_keys($scope['anchors']);
        $request = (string) Str::uuid();
        $payload = ['kind' => 'summary', 'title' => 'Selected source', 'body' => 'Human decision', 'confidence' => null, 'rationale' => '', 'source_refs' => [1]];
        $created = $this->successors()->create($this->f['teacher_id'], $predecessor, 'human_correction', $payload, $request, [$keys[1]]);
        $this->assertNull($created['error_code']);
        $new = DB::table('ai_authoring_proposals')->where('proposal_uuid', $created['proposal_uuid'])->sole();
        $this->assertSame(1, (int) $new->source_count);
        $this->assertSame($keys[1], DB::table('ai_authoring_proposal_sources')->where('proposal_id', $new->id)->value('anchor_hash'));
        $conflict = $this->successors()->create($this->f['teacher_id'], $predecessor, 'human_correction', $payload, $request, [$keys[0]]);
        $this->assertSame('proposal_idempotency_conflict', $conflict['error_code']);
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_audit_insert_failure_prevents_both_content_read_and_successor_preview(): void
    {
        $predecessor = $this->acceptedSummary();
        $fail = true;
        DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$fail): void {
            if ($fail && str_starts_with($sql, 'insert into `media_access_logs`')
                && collect($bindings)->contains(fn ($b) => is_string($b) && str_contains($b, 'authoring_'))) {
                throw new \RuntimeException('TEST_AUDIT_UNAVAILABLE');
            }
        });
        try {
            foreach ([fn () => $this->proposals()->show($this->f['teacher_id'], $predecessor),
                fn () => $this->successors()->preview($this->f['teacher_id'], $predecessor)] as $read) {
                try {
                    $read();
                    $this->fail('Content escaped despite unavailable audit.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame('TEST_AUDIT_UNAVAILABLE', $exception->getMessage());
                }
            }
        } finally {
            $fail = false;
        }
    }

    private function createSuccessor(int $actor, string $predecessor, string $reason, ?array $payload, string $request): array
    {
        $scope = $this->successors()->sourceScope($actor, $predecessor);

        return $this->successors()->create($actor, $predecessor, $reason, $payload, $request, array_keys($scope['anchors'] ?? []));
    }

    private function successors(): AiAuthoringSuccessorService
    {
        return $this->tenantService(AiAuthoringSuccessorService::class);
    }

    private function proposals(): AiAuthoringProposalService
    {
        return $this->tenantService(AiAuthoringProposalService::class);
    }

    private function acceptedSummary(): string
    {
        $this->provider->respondWith(fn () => json_encode(['items' => [[
            'kind' => 'summary', 'title' => 'Tóm tắt bài học', 'body' => 'Bài học giới thiệu nội dung chính.',
            'confidence' => 0.8, 'rationale' => self::CANARY, 'source_refs' => [1],
        ]]], JSON_UNESCAPED_UNICODE));
        $uuid = $this->generateAs($this->f['teacher_id'], ['summary'])['proposals'][0]['proposal_uuid'];
        $this->proposals()->decide($this->f['teacher_id'], $uuid, 'accept', 1, 1, (string) Str::uuid());

        return $uuid;
    }

    /** @return array{0:string,1:int} */
    private function acceptedNewNodeInSelectedVersion(): array
    {
        $this->provider->respondWith(fn () => json_encode(['items' => [[
            'kind' => 'node_mapping', 'title' => 'Năng lực mới', 'body' => 'B', 'confidence' => 0.5, 'rationale' => 'R', 'source_refs' => [1],
            'mapping' => ['mode' => 'propose_new', 'code' => 'NEW-5', 'label' => 'L', 'node_type' => 'competency', 'criteria' => null, 'role' => 'teaches', 'weight' => null],
        ]]]));
        $uuid = $this->generateAs($this->f['teacher_id'], ['node_mapping'])['proposals'][0]['proposal_uuid'];
        $learning = $this->tenantService(LearningFrameworkAuthoringService::class);
        $draft = (int) $learning->createDraftVersion($this->f['admin_id'], ['framework_id' => $this->f['framework_id'], 'version_code' => 'v2', 'title' => 'V2'])->id;
        $nodeId = $this->tenantService(AiAuthoringApplicationService::class)->approveNode($this->f['admin_id'], $uuid, $this->f['framework_id'], $draft, 1, 1, (string) Str::uuid())['node_id'];
        $learning->publishVersion($this->f['admin_id'], $draft);
        $this->tenantService(CourseTemplateLearningMappingIntentService::class)->select($this->f['admin_id'], $this->f['customer_id'], $this->f['template_id'], $this->f['framework_id'], $draft);

        return [$uuid, $nodeId];
    }

    private function bumpOcrRevision(): void
    {
        DB::table('media_extracted_texts')->where('customer_id', $this->f['customer_id'])->update(['processing_version' => 'fake-v2']);
        DB::table('media_processing_jobs')->where('customer_id', $this->f['customer_id'])->where('job_type', 'ocr')->update(['processing_version' => 'fake-v2']);
    }
}
