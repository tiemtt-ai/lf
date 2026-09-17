<?php

namespace Tests\Integration;

use App\Events\MediaFileDeleted;
use App\Listeners\EraseAuthoringProposalsOfDeletedMedia;
use App\Services\AiAuthoringApplicationService;
use App\Services\AiAuthoringErasureService;
use App\Services\AiAuthoringProposalService;
use App\Services\AiAuthoringRequestRecoveryService;
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Ai\AuthoringMariaDbFixture;
use Tests\TestCase;

/**
 * Step 7 erasure and request recovery on MariaDB. Content goes, identity and
 * decisions stay, applied owner writes are never undone, nothing is re-executed.
 */
class AiAuthoringErasureMariaDbTest extends TestCase
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

    public function test_the_authoring_listener_job_is_explicitly_after_commit(): void
    {
        Queue::fake();
        MediaFileDeleted::dispatch($this->f['customer_id'], $this->f['media_id']);
        Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === EraseAuthoringProposalsOfDeletedMedia::class && $job->afterCommit === true);
    }

    public function test_authoring_erasure_waits_for_the_callers_outermost_commit(): void
    {
        $uuid = $this->generateAs($this->f['teacher_id'], ['summary'])['proposals'][0]['proposal_uuid'];
        DB::table('media_file_usages')->where('media_file_id', $this->f['media_id'])->update(['status' => 'detached']);
        $processed = $this->recordErasureJobs();

        DB::transaction(function () use ($uuid, $processed): void {
            $this->tenantService(MediaService::class)->deleteMedia($this->f['media_id']);
            $this->assertSame(0, $processed());
            $this->assertSame('pending_review', DB::table('ai_authoring_proposals')->where('proposal_uuid', $uuid)->value('status'));
        });

        $this->assertSame(1, $processed());
        $this->assertSame('deleted', DB::table('ai_authoring_proposals')->where('proposal_uuid', $uuid)->value('status'));
        $this->assertNull(DB::table('ai_authoring_proposal_revisions')->value('payload'));
    }

    public function test_rolling_back_media_deletion_runs_no_authoring_erasure_job(): void
    {
        $uuid = $this->generateAs($this->f['teacher_id'], ['summary'])['proposals'][0]['proposal_uuid'];
        DB::table('media_file_usages')->where('media_file_id', $this->f['media_id'])->update(['status' => 'detached']);
        $processed = $this->recordErasureJobs();
        try {
            DB::transaction(function (): void {
                $this->tenantService(MediaService::class)->deleteMedia($this->f['media_id']);
                throw new \RuntimeException('caller rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('caller rollback', $exception->getMessage());
        }

        // A database assertion alone cannot detect a sync job that ran and
        // was rolled back with its caller. This counter lives outside the DB.
        $this->assertSame(0, $processed());
        $this->assertSame('pending_review', DB::table('ai_authoring_proposals')->where('proposal_uuid', $uuid)->value('status'));
        $this->assertNotNull(DB::table('ai_authoring_proposal_revisions')->value('payload'));
        $this->assertNotSame('deleted', DB::table('media_files')->where('id', $this->f['media_id'])->value('status'));
    }

    private function recordErasureJobs(): \Closure
    {
        $count = 0;
        Queue::before(function (JobProcessing $event) use (&$count): void {
            if (($event->job->payload()['displayName'] ?? null) === EraseAuthoringProposalsOfDeletedMedia::class) {
                $count++;
            }
        });

        return function () use (&$count): int {
            return $count;
        };
    }

    public function test_deleting_the_source_media_erases_content_keeps_decisions_and_cancels_unapplied_work(): void
    {
        [$applied, $pending] = $this->appliedAndConfirmedProposals();
        DB::table('media_file_usages')->where('owner_id', $this->f['activity_id'])->update(['status' => 'detached']);

        $this->tenantService(MediaService::class)->deleteMedia($this->f['media_id']);   // listener runs after commit

        $this->assertSame(['deleted'], DB::table('ai_authoring_proposals')->distinct()->pluck('status')->all());
        $this->assertSame(0, DB::table('ai_authoring_proposal_revisions')->whereNotNull('payload')->count());
        $this->assertSame(0, DB::table('ai_authoring_proposal_reviews')->whereNotNull('target_snapshot')->orWhereNotNull('context_snapshot')->orWhereNotNull('reason')->count());
        $this->assertSame(0, DB::table('ai_authoring_proposal_revisions')->whereNull('payload_hash')->count());
        $this->assertGreaterThan(0, DB::table('ai_authoring_proposal_reviews')->where('action', 'confirm_target')->whereNotNull('target_hash')->count());

        $receipts = DB::table('ai_authoring_proposal_applications')->orderBy('id')->get();
        $this->assertSame('applied', $receipts->firstWhere('proposal_id', DB::table('ai_authoring_proposals')->where('proposal_uuid', $applied)->value('id'))->status);
        $cancelled = $receipts->firstWhere('proposal_id', DB::table('ai_authoring_proposals')->where('proposal_uuid', $pending)->value('id'));
        $this->assertSame(['cancelled', 'system', null, 'source_deleted'], [$cancelled->status, $cancelled->cancellation_kind, $cancelled->cancelled_by, $cancelled->cancel_reason_code]);
        $this->assertSame(1, DB::table('core_course_template_learning_mapping_intents')->count());
        $read = $this->tenantService(AiAuthoringProposalService::class)->show($this->f['teacher_id'], $applied);
        $this->assertSame('deleted', $read['status']);
        $this->assertNull($read['payload']);
        $this->assertCount(3, $this->provider->calls, 'Three generations in the fixture; erasure adds no call.');
    }

    public function test_a_missing_working_activity_is_reconciled_and_never_blocks_its_deletion(): void
    {
        $uuid = $this->generateAs($this->f['teacher_id'], ['summary'])['proposals'][0]['proposal_uuid'];
        DB::table('media_file_usages')->where('owner_id', $this->f['activity_id'])->delete();
        DB::table('core_course_template_activities')->where('id', $this->f['activity_id'])->delete();

        $this->assertSame(0, Artisan::call('ai:authoring-reconcile-erasure'));

        $proposal = DB::table('ai_authoring_proposals')->where('proposal_uuid', $uuid)->sole();
        $this->assertSame('deleted', $proposal->status);
        $this->assertNull(DB::table('ai_authoring_proposal_revisions')->where('proposal_id', $proposal->id)->value('payload'));
        $this->assertSame(0, Artisan::call('ai:authoring-reconcile-erasure'));
        $this->assertStringContainsString('erased: 0', Artisan::output());
    }

    public function test_a_spurious_deletion_request_erases_nothing(): void
    {
        $this->generateAs($this->f['teacher_id'], ['summary']);

        $this->assertSame(0, $this->tenantService(AiAuthoringErasureService::class)->requestForDeletedMediaFile($this->f['media_id']));
        $this->assertSame('pending_review', DB::table('ai_authoring_proposals')->value('status'));
    }

    public function test_a_pending_request_can_be_cancelled_by_an_admin_only_before_execution(): void
    {
        $uuid = (string) Str::uuid();
        DB::table('ai_authoring_generation_requests')->insert([
            'customer_id' => $this->f['customer_id'], 'request_uuid' => $uuid, 'command_hash' => str_repeat('a', 64),
            'mode' => 'generated', 'actor_id' => $this->f['teacher_id'], 'template_id' => $this->f['template_id'],
            'activity_id' => $this->f['activity_id'], 'run_uuid' => (string) Str::uuid(), 'prompt_contract_id' => 'learnforge.authoring.proposal',
            'prompt_version' => 1, 'prompt_hash' => str_repeat('b', 64), 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $recovery = $this->tenantService(AiAuthoringRequestRecoveryService::class);

        $this->assertSame('proposal_forbidden', $recovery->cancelPending($this->f['teacher_id'], $uuid)['error_code']);
        $this->assertNull($recovery->cancelPending($this->f['admin_id'], $uuid)['error_code']);
        $this->assertSame(['failed', 'proposal_generation_cancelled_before_execution'], [
            DB::table('ai_authoring_generation_requests')->value('status'), DB::table('ai_authoring_generation_requests')->value('error_code'),
        ]);
        $this->assertSame('proposal_revision_conflict', $recovery->cancelPending($this->f['admin_id'], $uuid)['error_code']);
    }

    public function test_a_running_request_with_a_completed_run_fails_as_output_unavailable_without_re_execution(): void
    {
        $this->provider->respondWith(fn () => '{"items":[]}');
        $uuid = (string) Str::uuid();
        $this->generateAs($this->f['teacher_id'], ['summary'], $uuid);
        $run = DB::table('ai_model_runs')->sole();
        // Simulate a worker that died after the provider answered but before the
        // atomic outcome: a separate request row still claimed as running.
        $lost = (string) Str::uuid();
        DB::table('ai_authoring_generation_requests')->insert([
            'customer_id' => $this->f['customer_id'], 'request_uuid' => $lost, 'command_hash' => str_repeat('c', 64),
            'mode' => 'generated', 'actor_id' => $this->f['teacher_id'], 'template_id' => $this->f['template_id'],
            'activity_id' => $this->f['activity_id'], 'run_uuid' => (string) Str::uuid(), 'prompt_contract_id' => 'learnforge.authoring.proposal',
            'prompt_version' => 1, 'prompt_hash' => str_repeat('d', 64), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ai_authoring_generation_requests')->where('request_uuid', $lost)->update(['status' => 'running']);
        DB::table('ai_model_runs')->insert(array_merge((array) $run, ['id' => null, 'run_uuid' => DB::table('ai_authoring_generation_requests')->where('request_uuid', $lost)->value('run_uuid')]));
        $recovery = $this->tenantService(AiAuthoringRequestRecoveryService::class);

        $this->assertSame('invalid_proposal', $recovery->recoverRunning($this->f['admin_id'], $lost, false)['error_code']);
        $outcome = $recovery->recoverRunning($this->f['admin_id'], $lost, true);

        $this->assertSame('proposal_generation_output_unavailable', $outcome['request_error_code']);
        $this->assertCount(1, $this->provider->calls);
    }

    // ---------------------------------------------------------------- Helpers

    /** @return array{0:string,1:string} an applied proposal and one with an unapplied receipt */
    private function appliedAndConfirmedProposals(): array
    {
        $proposals = $this->tenantService(AiAuthoringProposalService::class);
        $applications = $this->tenantService(AiAuthoringApplicationService::class);

        $applied = $this->generateAs($this->f['teacher_id'], ['node_mapping'])['proposals'][0]['proposal_uuid'];
        $proposals->decide($this->f['teacher_id'], $applied, 'accept', 1, 1, (string) Str::uuid());
        $hash = $applications->targetPreview($this->f['teacher_id'], $applied)['target_hash'];
        $applications->confirmTarget($this->f['teacher_id'], $applied, 2, $hash, (string) Str::uuid());
        $applications->applyIntent($this->f['teacher_id'], $applied, 3, (string) Str::uuid());

        $this->provider->respondWith(fn () => json_encode(['items' => [[
            'kind' => 'node_mapping', 'title' => 'T', 'body' => 'B', 'confidence' => 0.5, 'rationale' => 'R', 'source_refs' => [1],
            'mapping' => ['mode' => 'reuse_existing', 'node_id' => $this->f['node_id'], 'definition_id' => $this->f['definition_id'], 'role' => 'assesses', 'weight' => null],
        ]]]));
        $pending = $this->generateAs($this->f['teacher_id'], ['node_mapping'])['proposals'][0]['proposal_uuid'];
        $proposals->decide($this->f['teacher_id'], $pending, 'reject', 1, 1, (string) Str::uuid(), 'Lý do riêng tư');
        // A rejected proposal has no receipt; use a second accepted one for the unapplied receipt.
        $pending = $this->generateAs($this->f['teacher_id'], ['node_mapping'])['proposals'][0]['proposal_uuid'];
        $proposals->decide($this->f['teacher_id'], $pending, 'accept', 1, 1, (string) Str::uuid());
        $applications->confirmTarget($this->f['teacher_id'], $pending, 2, $applications->targetPreview($this->f['teacher_id'], $pending)['target_hash'], (string) Str::uuid());

        return [$applied, $pending];
    }
}
