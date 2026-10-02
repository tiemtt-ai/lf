<?php

namespace Tests\Integration;

use App\Models\User;
use App\Services\LearningAuthoringBasisService;
use App\Services\LearningFrameworkAuthoringService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Ai\AuthoringMariaDbFixture;
use Tests\TestCase;

class AiAuthoringHttpMariaDbTest extends TestCase
{
    use AuthoringMariaDbFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Step 7 schema requires MariaDB.');
        }
        config(['app.base_domain' => 'localhost']);
        $this->setUpAuthoring();
        $this->approveProvider();
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    public function test_teacher_generates_reads_edits_and_accepts_without_publishing(): void
    {
        $uuid = $this->generateHttp();
        $detail = $this->getJson($this->base().'/proposals/'.$uuid)->assertOk()->assertJsonPath('error', null);
        $detail->assertHeader('Cache-Control', 'no-store, private');
        $payload = $detail->json('data.payload');
        $payload['body'] = 'Teacher edited decision';
        $this->patchJson($this->base().'/proposals/'.$uuid, $this->command() + [
            'expected_revision_no' => 1, 'payload_schema_version' => 1, 'payload' => $payload,
        ])->assertOk();
        $this->postJson($this->base().'/proposals/'.$uuid.'/decisions', $this->command(2) + [
            'expected_revision_no' => 2, 'action' => 'accept',
        ])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame(0, DB::table('core_course_template_versions')->count());
        $this->assertSame(0, DB::table('core_learning_node_mappings')->count());
        $this->assertCount(1, $this->provider->calls);
        $this->assertGreaterThan(0, DB::table('media_access_logs')->where('action', 'read_derived')->count());
    }

    public function test_admin_and_all_three_active_assignment_roles_are_supported(): void
    {
        foreach (['primary', 'assistant', 'reviewer'] as $role) {
            DB::table('core_course_template_teachers')->where('teacher_id', $this->f['teacher_id'])->update(['role' => $role]);
            $this->actingAs(User::findOrFail($this->f['teacher_id']))->getJson($this->base().'/proposals')->assertOk();
        }
        $this->actingAs(User::findOrFail($this->f['admin_id']))->getJson($this->base('admin').'/proposals')->assertOk();
    }

    public function test_guest_student_unverified_inactive_and_wrong_role_are_denied(): void
    {
        $this->getJson($this->base().'/proposals')->assertUnauthorized();
        $teacher = User::findOrFail($this->f['teacher_id']);
        $this->actingAs($teacher)->getJson($this->base('admin').'/proposals')->assertForbidden();
        $teacher->forceFill(['role' => 'student'])->save();
        $this->getJson($this->base().'/proposals')->assertForbidden();
        $teacher->forceFill(['role' => 'teacher', 'email_verified_at' => null])->save();
        $this->getJson($this->base().'/proposals')->assertForbidden();
        $teacher->forceFill(['email_verified_at' => now(), 'status' => 'inactive'])->save();
        $this->getJson($this->base().'/proposals')->assertForbidden();
        $this->assertSame([], $this->provider->calls);
    }

    public function test_unassigned_teacher_cannot_read_or_mutate_even_an_existing_uuid(): void
    {
        $uuid = $this->generateHttp();
        $this->actingAs(User::findOrFail($this->f['outsider_id']));
        $this->getJson($this->base().'/proposals/'.$uuid)->assertNotFound();
        $this->getJson($this->base().'/proposals')->assertNotFound();
        $this->postJson($this->base().'/proposals/'.$uuid.'/decisions', $this->decision())->assertNotFound();
        $this->assertSame(0, DB::table('ai_authoring_proposal_reviews')->count());
        $denied = DB::table('media_access_logs')->where('user_id', $this->f['outsider_id'])->orderByDesc('id')->value('metadata');
        $this->assertSame('denied', json_decode($denied, true)['decision']);
    }

    public function test_cross_tenant_and_mismatched_template_paths_do_not_disclose_objects(): void
    {
        $uuid = $this->generateHttp();
        $other = $this->authoringFixture('other');
        $this->actingAs(User::findOrFail($other['admin_id']));
        $path = 'http://authoring-other.localhost/admin/course-templates/'.$this->f['template_id'].'/activities/'.$this->f['activity_id'].'/ai-authoring';
        $this->getJson($path.'/proposals/'.$uuid)->assertNotFound();
        $this->getJson($this->base('admin').'/proposals/'.$uuid)->assertForbidden();
        $this->actingAs(User::findOrFail($this->f['teacher_id']));
        $wrong = str_replace('/course-templates/'.$this->f['template_id'].'/', '/course-templates/'.$other['template_id'].'/', $this->base());
        $this->getJson($wrong.'/proposals/'.$uuid)->assertNotFound();
        $sibling = (array) DB::table('core_course_template_activities')->where('id', $this->f['activity_id'])->first();
        unset($sibling['id']);
        $siblingId = DB::table('core_course_template_activities')->insertGetId($sibling);
        $wrong = str_replace('/activities/'.$this->f['activity_id'].'/', '/activities/'.$siblingId.'/', $this->base());
        $this->getJson($wrong.'/proposals/'.$uuid)->assertNotFound();
    }

    public function test_idempotent_generation_and_decision_replay_recheck_assignment(): void
    {
        $request = (string) Str::uuid();
        $uuid = $this->generateHttp($request);
        $this->generateHttp($request);
        $decision = $this->decision();
        $path = $this->base().'/proposals/'.$uuid.'/decisions';
        $this->postJson($path, $decision)->assertOk();
        $this->postJson($path, $decision)->assertOk();
        $this->postJson($path, array_replace($decision, ['action' => 'reject']))->assertStatus(409);
        DB::table('core_course_template_teachers')->where('teacher_id', $this->f['teacher_id'])->update(['status' => 'archived']);
        $this->postJson($path, $decision)->assertNotFound();
        $this->postJson($this->base().'/generation-requests', ['request_id' => $request, 'requested_kinds' => ['summary']])->assertNotFound();
        $this->assertCount(1, $this->provider->calls);
        $this->assertSame(1, DB::table('ai_authoring_proposal_reviews')->count());
    }

    public function test_listing_requires_the_template_of_the_authorized_activity(): void
    {
        $this->generateHttp();
        $wrong = str_replace('/course-templates/'.$this->f['template_id'].'/', '/course-templates/999999999/', $this->base());
        $this->getJson($wrong.'/proposals')->assertNotFound()->assertJsonPath('data', null);
        $this->postJson($wrong.'/generation-requests', ['request_id' => (string) Str::uuid(), 'requested_kinds' => ['summary']])
            ->assertNotFound()->assertJsonPath('data', null);
        $this->assertCount(1, $this->provider->calls);
        $this->assertSame(1, DB::table('ai_authoring_generation_requests')->count());
    }

    public function test_bulk_cannot_decide_a_proposal_from_another_activity_in_the_same_template(): void
    {
        $foreign = $this->generateHttp();
        $originalActivity = $this->f['activity_id'];
        $sibling = (array) DB::table('core_course_template_activities')->where('id', $originalActivity)->sole();
        unset($sibling['id']);
        $this->f['activity_id'] = DB::table('core_course_template_activities')->insertGetId($sibling);
        $calls = count($this->provider->calls);
        $item = $this->decision() + ['proposal_uuid' => $foreign];

        $this->postJson($this->base().'/proposal-decisions', ['request_id' => (string) Str::uuid(), 'items' => [$item]])
            ->assertOk()->assertJsonPath('data.items.0.http_status', 404)
            ->assertJsonPath('data.items.0.error.code', 'proposal_not_found');

        $this->assertSame('pending_review', DB::table('ai_authoring_proposals')->where('proposal_uuid', $foreign)->value('status'));
        $this->assertSame(0, DB::table('ai_authoring_proposal_reviews')->count());
        $this->assertCount($calls, $this->provider->calls);
    }

    #[DataProvider('persistenceFailureTypes')]
    public function test_generation_persistence_failure_never_copies_model_payload_into_diagnostic_logs(?string $sqlState): void
    {
        $canary = 'PRIVATE_MODEL_PAYLOAD_CANARY_792';
        $this->provider->respondWith(fn () => json_encode(['items' => [[
            'kind' => 'summary', 'title' => 'Title', 'body' => $canary,
            'confidence' => 0.5, 'rationale' => 'Reason', 'source_refs' => [1],
        ]]]));
        $path = tempnam(sys_get_temp_dir(), 'lf-authoring-log-');
        $originalLogger = Log::getFacadeRoot();
        Log::swap(Log::build(['driver' => 'single', 'path' => $path]));
        $armed = true;
        DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$armed, $canary, $sqlState): void {
            if ($armed && str_starts_with($sql, 'insert into `ai_authoring_proposal_revisions`')) {
                if ($sqlState === null) {
                    throw new \RuntimeException('Private failure '.$canary);
                }
                $driver = new \PDOException('Driver contains '.$canary);
                $driver->errorInfo = [$sqlState, 999, 'Private driver detail '.$canary];
                throw new QueryException('mysql', $sql, $bindings, $driver);
            }
        });
        try {
            $this->actingAs(User::findOrFail($this->f['teacher_id']));
            $request = (string) Str::uuid();
            $body = ['request_id' => $request, 'requested_kinds' => ['summary']];
            $response = $this->postJson($this->base().'/generation-requests', $body)
                ->assertStatus(409)->assertJsonPath('error.code', 'proposal_generation_output_unavailable');
            $log = file_get_contents($path);
            $this->assertStringNotContainsString($canary, $log);
            $this->assertStringNotContainsString('insert into', $log);
            $this->assertStringNotContainsString($canary, $response->getContent());
            $this->assertStringContainsString('ai_authoring_generation_persistence_failed', $log);
            $this->assertStringContainsString($request, $log);
            $context = json_decode(substr(trim($log), strpos($log, '{')), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['exception_class', 'request_uuid', 'sqlstate'], array_keys($context));
            $this->assertSame($sqlState === null ? \RuntimeException::class : QueryException::class, $context['exception_class']);
            $this->assertSame($sqlState === '23000' ? '23000' : null, $context['sqlstate']);
            $this->assertSame('failed', DB::table('ai_authoring_generation_requests')->where('request_uuid', $request)->value('status'));
            $this->assertSame(0, DB::table('ai_authoring_proposals')->count());
            $this->assertSame('completed', DB::table('ai_model_runs')->value('status'));
            $armed = false;
            $this->postJson($this->base().'/generation-requests', $body)->assertStatus(409);
            $this->assertCount(1, $this->provider->calls, 'Persistence failure must never re-execute the provider.');
        } finally {
            $armed = false;
            Log::swap($originalLogger);
            @unlink($path);
        }
    }

    public static function persistenceFailureTypes(): array
    {
        return ['query exception' => ['23000'], 'untrusted SQLSTATE' => ['PRIVATE_MODEL_PAYLOAD_CANARY_792'], 'other exception' => [null]];
    }

    public function test_unknown_fields_strings_as_integers_and_stale_guards_are_rejected(): void
    {
        $uuid = $this->generateHttp();
        foreach (['customer_id', 'actor_id', 'activity_id', 'model', 'publish'] as $field) {
            $this->postJson($this->base().'/proposals/'.$uuid.'/decisions', $this->decision() + [$field => 1])->assertUnprocessable();
        }
        $this->postJson($this->base().'/proposals/'.$uuid.'/decisions', array_replace($this->decision(), ['expected_lock_version' => '1']))->assertUnprocessable();
        $this->postJson($this->base().'/proposals/'.$uuid.'/decisions', array_replace($this->decision(), ['expected_lock_version' => 9]))->assertStatus(409);
        $this->assertSame(0, DB::table('ai_authoring_proposal_reviews')->count());
    }

    public function test_detach_and_tombstone_hide_payload_and_citations_but_keep_metadata(): void
    {
        $uuid = $this->generateHttp();
        DB::table('media_file_usages')->where('media_file_id', $this->f['media_id'])->update(['status' => 'detached']);
        $this->getJson($this->base().'/proposals/'.$uuid)->assertOk()->assertJsonPath('data.payload', null)->assertJsonPath('data.citations', []);
        $this->getJson($this->base().'/proposals')->assertOk()->assertJsonMissingPath('data.items.0.payload')->assertJsonMissingPath('data.items.0.title');
        $this->postJson($this->base().'/proposals/'.$uuid.'/decisions', $this->decision())->assertStatus(409);
        DB::table('media_file_usages')->where('media_file_id', $this->f['media_id'])->update(['status' => 'active']);
        DB::table('media_files')->where('id', $this->f['media_id'])->update(['status' => 'deleted']);
        $this->getJson($this->base().'/proposals/'.$uuid)->assertOk()->assertJsonPath('data.payload', null)->assertJsonPath('data.citations', []);
    }

    public function test_failed_audit_never_returns_payload_or_exception_text(): void
    {
        $uuid = $this->generateHttp();
        $fail = true;
        DB::connection()->beforeExecuting(function ($sql) use (&$fail): void {
            if ($fail && str_starts_with($sql, 'insert into `media_access_logs`')) {
                throw new \RuntimeException('SECRET SQL source payload');
            }
        });
        try {
            $response = $this->getJson($this->base().'/proposals/'.$uuid)->assertStatus(503)->assertJsonPath('data', null);
            $this->assertStringNotContainsString('SECRET', $response->getContent());
        } finally {
            $fail = false;
        }
    }

    public function test_keyset_cursor_is_bound_to_actor_filters_and_parent(): void
    {
        $one = $this->generateHttp();
        $two = $this->generateHttp();
        $first = $this->getJson($this->base().'/proposals?limit=1')->assertOk();
        $cursor = $first->json('data.next_cursor');
        $this->assertNotEmpty($cursor);
        $this->getJson($this->base().'/proposals?limit=1&cursor='.urlencode($cursor))->assertOk()->assertJsonPath('data.items.0.proposal_uuid', $two);
        $this->getJson($this->base().'/proposals?kind=summary&cursor='.urlencode($cursor))->assertUnprocessable();
        $this->getJson($this->base().'/proposals?cursor='.urlencode($cursor.'x'))->assertUnprocessable();
        $this->actingAs(User::findOrFail($this->f['admin_id']))->getJson($this->base('admin').'/proposals?cursor='.urlencode($cursor))->assertUnprocessable();
    }

    public function test_bulk_reports_each_outcome_and_never_calls_provider(): void
    {
        $one = $this->generateHttp();
        $two = $this->generateHttp();
        $items = [$this->decision() + ['proposal_uuid' => $one], $this->decision() + ['proposal_uuid' => (string) Str::uuid()],
            array_replace($this->decision(), ['proposal_uuid' => $two, 'action' => 'reject'])];
        $body = ['request_id' => (string) Str::uuid(), 'items' => $items];
        $this->postJson($this->base().'/proposal-decisions', $body)->assertOk()
            ->assertJsonPath('data.items.0.http_status', 200)->assertJsonPath('data.items.1.http_status', 404)->assertJsonPath('data.items.2.http_status', 200);
        $this->postJson($this->base().'/proposal-decisions', $body)->assertOk();
        $this->assertCount(2, $this->provider->calls);
        $this->assertSame(2, DB::table('ai_authoring_proposal_reviews')->count());
        $this->postJson($this->base().'/proposal-decisions', ['request_id' => (string) Str::uuid(), 'items' => [$items[0], $items[0]]])->assertUnprocessable();
    }

    public function test_admin_only_routes_are_not_registered_for_teacher(): void
    {
        $uuid = $this->generateHttp();
        foreach (['node-approvals', 'inherited-drafts', 'rebases'] as $suffix) {
            $this->postJson($this->base().'/proposals/'.$uuid.'/'.$suffix, [])->assertNotFound();
            $this->postJson($this->base('admin').'/proposals/'.$uuid.'/'.$suffix, [])->assertForbidden();
        }
        foreach (['inherited-draft-preview', 'rebase-preview'] as $suffix) {
            $this->getJson($this->base().'/proposals/'.$uuid.'/'.$suffix)->assertNotFound();
        }
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_status_endpoint_and_unconfigured_provider_do_not_expose_raw_inputs(): void
    {
        $request = (string) Str::uuid();
        $this->generateHttp($request);
        $this->getJson($this->base().'/generation-requests/'.$request)->assertOk()
            ->assertJsonPath('data.request_status', 'completed')->assertJsonMissingPath('data.prompt_hash')->assertJsonMissingPath('data.run_uuid');
        config(['ai.authoring.provider' => '']);
        $this->postJson($this->base().'/generation-requests', ['request_id' => (string) Str::uuid(), 'requested_kinds' => ['summary']])
            ->assertStatus(409)->assertJsonPath('error.code', 'AI_APPROVAL_REQUIRED');
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_csrf_is_required_when_the_test_bypass_is_disabled(): void
    {
        $this->actingAs(User::findOrFail($this->f['teacher_id']));
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson($this->base().'/generation-requests', ['request_id' => (string) Str::uuid(), 'requested_kinds' => ['summary']])->assertStatus(419);
        $this->assertSame([], $this->provider->calls);
    }

    public function test_mapping_confirm_apply_and_foreign_receipt_cancellation(): void
    {
        $uuid = $this->generateHttp(null, ['node_mapping']);
        $path = $this->base().'/proposals/'.$uuid;
        $this->postJson($path.'/decisions', $this->decision())->assertOk();
        $hash = $this->getJson($path.'/target-preview')->assertOk()->json('data.target_hash');
        $this->postJson($path.'/target-confirmations', $this->command(2) + ['expected_target_hash' => $hash])->assertOk();
        $other = $this->generateHttp();
        $receipt = DB::table('ai_authoring_proposal_applications')->sole();
        $this->postJson($this->base().'/proposals/'.$other.'/applications/'.$receipt->application_uuid.'/cancel',
            $this->command(3) + ['reason_code' => 'human_cancel'])->assertNotFound();
        $this->assertSame('ready_to_apply', DB::table('ai_authoring_proposal_applications')->value('status'));
        $this->postJson($path.'/intent-applications', $this->command(3))->assertOk();
        $this->assertSame(1, DB::table('core_course_template_learning_mapping_intents')->count());
        $this->assertSame(0, DB::table('core_learning_node_mappings')->count());
        $this->postJson($path.'/applications/'.$receipt->application_uuid.'/retry', $this->command(4))->assertStatus(409);
        $this->postJson($path.'/applications/'.$receipt->application_uuid.'/cancel', $this->command(4) + ['reason_code' => 'human_cancel'])->assertStatus(409);
        $this->assertCount(2, $this->provider->calls);
    }

    public function test_failed_receipt_retry_keeps_approval_and_records_retry_actor(): void
    {
        $uuid = $this->generateHttp(null, ['node_mapping']);
        $path = $this->base().'/proposals/'.$uuid;
        $this->postJson($path.'/decisions', $this->decision())->assertOk();
        $hash = $this->getJson($path.'/target-preview')->assertOk()->json('data.target_hash');
        $this->postJson($path.'/target-confirmations', $this->command(2) + ['expected_target_hash' => $hash])->assertOk();
        $receipt = DB::table('ai_authoring_proposal_applications')->sole();
        DB::table('ai_authoring_proposal_applications')->where('id', $receipt->id)->update(['status' => 'failed', 'error_code' => 'owner_failure']);
        $this->actingAs(User::findOrFail($this->f['admin_id']));
        $retry = $this->command(3);
        $url = $this->base('admin').'/proposals/'.$uuid.'/applications/'.$receipt->application_uuid.'/retry';
        $this->postJson($url, $retry)->assertOk();
        $this->postJson($url, $retry)->assertOk();
        $row = DB::table('ai_authoring_proposal_applications')->sole();
        $this->assertSame('applied', $row->status);
        $this->assertSame($receipt->approved_by, $row->approved_by);
        $this->assertSame($this->f['admin_id'], (int) DB::table('ai_authoring_proposal_reviews')->where('action', 'retry_application')->value('actor_id'));
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_context_reconfirmation_and_target_rejection_do_not_call_provider(): void
    {
        $uuid = $this->generateHttp(null, ['node_mapping']);
        $path = $this->base().'/proposals/'.$uuid;
        $this->postJson($path.'/decisions', $this->decision())->assertOk();
        $target = $this->getJson($path.'/target-preview')->assertOk()->json('data.target_hash');
        $this->postJson($path.'/target-confirmations', $this->command(2) + ['expected_target_hash' => $target])->assertOk();
        $this->postJson($path.'/intent-applications', $this->command(3))->assertOk();
        DB::table('core_course_template_activities')->where('id', $this->f['activity_id'])->update(['description' => 'New context']);
        $hash = $this->getJson($path.'/context-preview')->assertOk()->json('data.course_context_hash');
        $this->postJson($path.'/context-confirmations', $this->command(4) + ['expected_course_context_hash' => $hash])->assertOk();
        $target = $this->getJson($path.'/target-preview')->assertOk()->json('data.target_hash');
        $this->postJson($path.'/target-rejections', $this->command(5) + ['expected_target_hash' => $target])->assertOk();
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_successor_scope_pages_and_selection_are_current_and_explicit(): void
    {
        $uuid = $this->generateHttp();
        $path = $this->base().'/proposals/'.$uuid;
        $this->postJson($path.'/decisions', $this->decision())->assertOk();
        $row = (array) DB::table('media_extracted_texts')->where('media_file_id', $this->f['media_id'])->first();
        unset($row['id']);
        $row['locator_value'] = '2';
        $row['sequence'] = 2;
        DB::table('media_extracted_texts')->insert($row);
        $first = $this->getJson($path.'/successor-source-scope?limit=1')->assertOk();
        $cursor = $first->json('data.next_cursor');
        $selected = $first->json('data.anchors.0.anchor_hash');
        $this->assertNotEmpty($cursor);
        $this->assertArrayNotHasKey('text', $first->json('data.anchors.0.anchor'));
        $this->getJson($path.'/successor-source-scope?cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'data.anchors');
        $body = ['request_id' => (string) Str::uuid(), 'reason' => 'human_correction', 'payload' => [
            'kind' => 'summary', 'title' => 'Human decision', 'body' => 'Corrected', 'confidence' => null, 'rationale' => '', 'source_refs' => [1],
        ], 'selected_anchor_hashes' => [$selected]];
        $this->postJson($path.'/successors', $body)->assertOk()->assertJsonPath('data.request_status', 'completed');
        $this->postJson($path.'/successors', $body)->assertOk();
        $this->postJson($path.'/successors', array_replace($body, ['selected_anchor_hashes' => []]))->assertUnprocessable();
        $this->assertCount(1, $this->provider->calls);
        $row['locator_value'] = '3';
        $row['sequence'] = 3;
        DB::table('media_extracted_texts')->insert($row);
        $this->getJson($path.'/successor-source-scope?cursor='.urlencode($cursor))->assertStatus(409)->assertJsonPath('error.code', 'proposal_stale');
    }

    public function test_source_revision_successor_strips_old_rationale_and_does_not_generate(): void
    {
        $uuid = $this->generateHttp();
        $path = $this->base().'/proposals/'.$uuid;
        $this->postJson($path.'/decisions', $this->decision())->assertOk();
        DB::table('media_processing_jobs')->where('media_file_id', $this->f['media_id'])->where('job_type', 'ocr')->update(['processing_version' => 'fake-v2']);
        DB::table('media_extracted_texts')->where('media_file_id', $this->f['media_id'])->update(['processing_version' => 'fake-v2']);
        $this->getJson($path.'/successor-preview')->assertOk()->assertJsonPath('data.payload.rationale', '')->assertJsonPath('data.payload.source_refs', []);
        $selected = $this->getJson($path.'/successor-source-scope')->assertOk()->json('data.anchors.0.anchor_hash');
        $this->postJson($path.'/successors', ['request_id' => (string) Str::uuid(), 'reason' => 'source_revision_changed', 'payload' => null,
            'selected_anchor_hashes' => [$selected]])->assertOk();
        $this->assertCount(1, $this->provider->calls);
    }

    // ------------------------------------------- P3-C: a successor of a once-accepted stale proposal

    private function changeSource(): void
    {
        DB::table('media_processing_jobs')->where('media_file_id', $this->f['media_id'])->where('job_type', 'ocr')->update(['processing_version' => 'fake-v2']);
        DB::table('media_extracted_texts')->where('media_file_id', $this->f['media_id'])->update(['processing_version' => 'fake-v2']);
    }

    public function test_a_stale_proposal_that_was_once_accepted_offers_a_successor_to_both_roles_and_a_pending_one_does_not(): void
    {
        $accepted = $this->generateHttp();
        $this->postJson($this->base().'/proposals/'.$accepted.'/decisions', $this->decision())->assertOk();
        $neverAccepted = $this->generateHttp();
        $this->assertSame([], $this->actionsOf($accepted)[0], 'accepted summary offers nothing yet');

        $this->changeSource();

        foreach (['teacher', 'admin'] as $role) {
            $detail = $this->actingAs(User::findOrFail($this->f[$role.'_id']))->getJson($this->base($role).'/proposals/'.$accepted)->assertOk()->json('data');
            $this->assertSame('stale', $detail['status']);
            $this->assertNull($detail['payload'], 'content stays hidden');
            $this->assertSame(['create_successor'], $detail['allowed_actions'], $role);
            $this->assertSame([], $this->actionsOf($neverAccepted, $role)[0], 'never accepted: no successor');
        }
    }

    public function test_a_successor_is_shown_as_an_inherited_unapproved_draft_with_its_reason(): void
    {
        $uuid = $this->generateHttp();
        $this->postJson($this->base().'/proposals/'.$uuid.'/decisions', $this->decision())->assertOk();
        $generated = $this->getJson($this->base().'/proposals/'.$uuid)->assertOk()->json('data');
        $this->assertFalse($generated['inherited_decision_draft']);
        $this->assertNull($generated['successor_reason']);

        $this->changeSource();
        $path = $this->base().'/proposals/'.$uuid;
        $selected = $this->getJson($path.'/successor-source-scope')->assertOk()->json('data.anchors.0.anchor_hash');
        $made = $this->postJson($path.'/successors', ['request_id' => (string) Str::uuid(), 'reason' => 'source_revision_changed',
            'payload' => null, 'selected_anchor_hashes' => [$selected]])->assertOk()->json('data');

        $successor = $this->getJson($this->base().'/proposals/'.$made['proposal_uuid'])->assertOk()->json('data');
        $this->assertSame('pending_review', $successor['status']);
        $this->assertSame('human_successor', $successor['creation_mode']);
        $this->assertTrue($successor['inherited_decision_draft']);
        $this->assertSame('source_revision_changed', $successor['successor_reason']);
        $this->assertSame(['edit', 'accept', 'reject'], $successor['allowed_actions']);
    }

    public function test_admin_inherits_draft_approves_node_and_previews_rebase(): void
    {
        $this->provider->respondWith(fn () => json_encode(['items' => [[
            'kind' => 'node_mapping', 'title' => 'New', 'body' => 'New competency', 'confidence' => .8, 'rationale' => 'Reason', 'source_refs' => [1],
            'mapping' => ['mode' => 'propose_new', 'code' => 'HTTP-NEW', 'label' => 'HTTP Node', 'node_type' => 'competency',
                'criteria' => ['level' => 'initial'], 'role' => 'teaches', 'weight' => null],
        ]]]));
        $uuid = $this->generateHttp(null, ['node_mapping']);
        $this->postJson($this->base().'/proposals/'.$uuid.'/decisions', $this->decision())->assertOk();
        $this->actingAs(User::findOrFail($this->f['admin_id']));
        $path = $this->base('admin').'/proposals/'.$uuid;
        $preview = $this->getJson($path.'/inherited-draft-preview?base_version_id='.$this->f['version_id'])->assertOk()->json('data.preview');
        $draft = $this->postJson($path.'/inherited-drafts', ['request_id' => (string) Str::uuid(), 'base_version_id' => $this->f['version_id'],
            'version_code' => 'http-v2', 'title' => 'V2', 'expected_source_graph_hash' => $preview['source_graph_hash'],
            'expected_plan_hash' => $preview['plan_hash']])->assertOk()->json('data.result_version_id');
        $this->postJson($path.'/node-approvals', $this->command(3) + ['expected_revision_no' => 1,
            'framework_id' => $this->f['framework_id'], 'framework_version_id' => $draft])->assertOk();
        $this->tenantService(LearningFrameworkAuthoringService::class)->publishVersion($this->f['admin_id'], $draft);
        $rebase = $this->getJson($path.'/rebase-preview?target_version_id='.$draft)->assertOk()->json('data.preview_hash');
        $this->postJson($path.'/rebases', ['request_id' => (string) Str::uuid(), 'target_version_id' => $draft,
            'expected_preview_hash' => $rebase, 'dispositions' => (object) []])->assertOk();
        $this->assertCount(1, $this->provider->calls);
        $this->assertSame(0, DB::table('core_learning_node_mappings')->count());
    }

    public function test_unapplied_receipt_can_be_cancelled_with_actor_and_reason(): void
    {
        $uuid = $this->generateHttp(null, ['node_mapping']);
        $path = $this->base().'/proposals/'.$uuid;
        $this->postJson($path.'/decisions', $this->decision())->assertOk();
        $hash = $this->getJson($path.'/target-preview')->assertOk()->json('data.target_hash');
        $this->postJson($path.'/target-confirmations', $this->command(2) + ['expected_target_hash' => $hash])->assertOk();
        $receipt = DB::table('ai_authoring_proposal_applications')->sole();
        $body = $this->command(3) + ['reason_code' => 'teacher_changed_mind'];
        $this->postJson($path.'/applications/'.$receipt->application_uuid.'/cancel', $body)->assertOk()->assertJsonPath('data.application_status', 'cancelled');
        $this->postJson($path.'/applications/'.$receipt->application_uuid.'/cancel', $body)->assertOk();
        $row = DB::table('ai_authoring_proposal_applications')->sole();
        $this->assertSame('human', $row->cancellation_kind);
        $this->assertSame($this->f['teacher_id'], (int) $row->cancelled_by);
        $this->assertSame('teacher_changed_mind', $row->cancel_reason_code);
        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')->count());
    }

    public function test_every_endpoint_inherits_auth_tenant_verified_role_and_web_csrf_stack(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), '/ai-authoring/'));
        $this->assertCount(41, $routes);
        foreach ($routes as $route) {
            $stack = $route->gatherMiddleware();
            foreach (['web', 'tenant', 'auth', 'verified', 'tenant.user'] as $middleware) {
                $this->assertContains($middleware, $stack);
            }
            $this->assertContains(str_starts_with($route->uri(), 'admin/') ? 'role:customer_admin' : 'role:teacher', $stack);
            $this->assertStringNotContainsString('recovery', $route->uri());
            $this->assertStringNotContainsString('publish', $route->uri());
        }
    }

    public function test_bounds_and_malformed_envelopes_are_rejected_before_provider(): void
    {
        $this->actingAs(User::findOrFail($this->f['teacher_id']));
        foreach (['0', '101', '-1', '1.5', '1e2'] as $limit) {
            $this->getJson($this->base().'/proposals?limit='.$limit)->assertUnprocessable();
        }
        $this->postJson($this->base().'/generation-requests', ['request_id' => (string) Str::uuid(), 'requested_kinds' => ['summary'], 'framework_id' => $this->f['framework_id']])->assertUnprocessable();
        $this->postJson($this->base().'/generation-requests?customer_id=9', ['request_id' => (string) Str::uuid(), 'requested_kinds' => ['summary']])->assertUnprocessable();
        $items = array_map(fn () => $this->decision() + ['proposal_uuid' => (string) Str::uuid()], range(1, 101));
        $this->postJson($this->base().'/proposal-decisions', ['request_id' => (string) Str::uuid(), 'items' => $items])->assertUnprocessable();
        $this->assertSame([], $this->provider->calls);
    }

    private function base(string $role = 'teacher'): string
    {
        return 'http://authoring-a.localhost/'.$role.'/course-templates/'.$this->f['template_id'].'/activities/'.$this->f['activity_id'].'/ai-authoring';
    }

    // ------------------------------------------- P3-B: allowed_actions after acceptance (amendment A1)

    /** @return array{0:array<int,string>,1:array<string,array<int,string>>,2:int} proposal actions, actions by receipt, lock version */
    private function actionsOf(string $uuid, string $role = 'teacher'): array
    {
        $user = $role === 'admin' ? $this->f['admin_id'] : $this->f['teacher_id'];
        $data = $this->actingAs(User::findOrFail($user))->getJson($this->base($role).'/proposals/'.$uuid)->assertOk()->json('data');
        $receipts = [];
        foreach ($data['applications'] as $application) {
            $this->assertSame(['application_uuid', 'operation', 'status', 'approved_by', 'allowed_actions'], array_keys($application));
            $receipts[$application['application_uuid']] = $application['allowed_actions'];
        }

        return [$data['allowed_actions'], $receipts, (int) $data['lock_version']];
    }

    private function acceptedReuse(): string
    {
        $uuid = $this->generateHttp(null, ['node_mapping']);
        $this->postJson($this->base().'/proposals/'.$uuid.'/decisions', $this->decision())->assertOk();

        return $uuid;
    }

    private function confirmTarget(string $uuid): string
    {
        $path = $this->base().'/proposals/'.$uuid;
        $lock = $this->actionsOf($uuid)[2];
        $hash = $this->getJson($path.'/target-preview')->assertOk()->json('data.target_hash');
        $this->postJson($path.'/target-confirmations', $this->command($lock) + ['expected_target_hash' => $hash])->assertOk();

        return $hash;
    }

    public function test_allowed_actions_follow_the_life_of_an_existing_node_mapping_for_a_teacher(): void
    {
        $uuid = $this->generateHttp(null, ['node_mapping']);
        $path = $this->base().'/proposals/'.$uuid;
        $this->assertSame(['edit', 'accept', 'reject'], $this->actionsOf($uuid)[0], 'pending: unchanged');

        $this->postJson($path.'/decisions', $this->decision())->assertOk();
        [$actions, $receipts] = $this->actionsOf($uuid);
        $this->assertSame(['preview_target', 'confirm_target'], $actions, 'accepted, nothing confirmed');
        $this->assertSame([], $receipts);

        $hash = $this->confirmTarget($uuid);
        [$actions, $receipts, $lock] = $this->actionsOf($uuid);
        $this->assertSame(['preview_target', 'confirm_target', 'apply_intent'], $actions, 'confirmed, ready to apply');
        $this->assertSame([['cancel']], array_values($receipts));

        $this->postJson($path.'/intent-applications', $this->command($lock))->assertOk();
        [$actions, $receipts] = $this->actionsOf($uuid);
        $this->assertSame(['preview_target', 'reject_target'], $actions, 'applied: nothing more to apply, the target can be rejected');
        $this->assertSame([[]], array_values($receipts), 'an applied receipt is never retried or cancelled');
        $this->assertNotSame('', $hash);
    }

    public function test_other_kinds_and_a_hidden_payload_offer_nothing_after_acceptance(): void
    {
        $summary = $this->generateHttp();
        $this->postJson($this->base().'/proposals/'.$summary.'/decisions', $this->decision())->assertOk();
        $this->assertSame([], $this->actionsOf($summary)[0]);

        $mapping = $this->acceptedReuse();
        $this->assertNotSame([], $this->actionsOf($mapping)[0]);
        DB::table('media_file_usages')->where('media_file_id', $this->f['media_id'])->update(['status' => 'detached']);
        $this->assertSame([], $this->actionsOf($mapping)[0], 'no content, no actions');
    }

    public function test_a_new_node_waits_for_an_admin_who_alone_is_offered_the_approval(): void
    {
        $this->provider->respondWith(fn () => json_encode(['items' => [[
            'kind' => 'node_mapping', 'title' => 'New', 'body' => 'New competency', 'confidence' => .8, 'rationale' => 'Reason', 'source_refs' => [1],
            'mapping' => ['mode' => 'propose_new', 'code' => 'HTTP-NEW', 'label' => 'HTTP Node', 'node_type' => 'competency',
                'criteria' => ['level' => 'initial'], 'role' => 'teaches', 'weight' => null],
        ]]]));
        $uuid = $this->generateHttp(null, ['node_mapping']);
        $this->postJson($this->base().'/proposals/'.$uuid.'/decisions', $this->decision())->assertOk();

        $this->assertSame([], $this->actionsOf($uuid)[0], 'the teacher waits: there is no target yet');
        $this->assertSame(['approve_node', 'inherit_draft', 'rebase'], $this->actionsOf($uuid, 'admin')[0]);
    }

    // ------------------------------------------- P3-C: copying a draft Framework version, as an admin

    public function test_copying_a_draft_and_rebasing_are_offered_to_an_admin_of_an_accepted_mapping_and_not_to_a_teacher(): void
    {
        $uuid = $this->acceptedReuse();
        $this->assertNotContains('inherit_draft', $this->actionsOf($uuid)[0]);
        $this->assertNotContains('rebase', $this->actionsOf($uuid)[0]);
        $this->assertSame(['inherit_draft', 'rebase'], array_slice($this->actionsOf($uuid, 'admin')[0], -2));

        $summary = $this->generateHttp();
        $this->postJson($this->base().'/proposals/'.$summary.'/decisions', $this->decision())->assertOk();
        $this->assertSame([], $this->actionsOf($summary, 'admin')[0], 'only a mapping can start them');
    }

    public function test_the_copy_plan_names_what_it_leaves_out_and_what_this_template_uses_and_keeps_the_hashes(): void
    {
        $uuid = $this->acceptedReuse();
        $path = $this->base().'/proposals/'.$uuid;
        $this->confirmTarget($uuid);
        $this->postJson($path.'/intent-applications', $this->command($this->actionsOf($uuid)[2]))->assertOk();
        $mapping = $this->getJson($path)->assertOk()->json('data.payload.mapping');
        DB::table('core_learning_node_definitions')->where('id', $mapping['definition_id'])->update(['status' => 'archived']);
        $intent = DB::table('core_course_template_learning_mapping_intents')->where('learning_node_id', $mapping['node_id'])->first();
        $table = $intent->source_type === 'course_template_lesson' ? 'core_course_template_lessons' : 'core_course_template_activities';
        $name = DB::table($table)->where('id', $intent->source_id)->value('title');

        $this->actingAs(User::findOrFail($this->f['admin_id']));
        $url = $this->base('admin').'/proposals/'.$uuid.'/inherited-draft-preview?base_version_id='.$this->f['version_id'];
        $data = $this->getJson($url)->assertOk()->json('data');

        $this->assertSame(['source_graph_hash', 'plan_hash', 'eligible_node_ids', 'excluded_node_ids', 'excluded_relation_ids'], array_keys($data['preview']), 'the hashed plan is unchanged');
        $this->assertSame([$mapping['node_id']], $data['preview']['excluded_node_ids']);
        $this->assertSame(0, $data['display']['eligible_count']);
        $this->assertSame(['node_id', 'code', 'label', 'node_type', 'reason'], array_keys($data['display']['excluded_nodes'][0]));
        $this->assertSame('definition_inactive', $data['display']['excluded_nodes'][0]['reason']);
        $this->assertSame([['intent_id' => (int) $intent->id, 'source_label' => $name, 'node_label' => $data['display']['excluded_nodes'][0]['label']]], $data['display']['affected_intents']);
        $this->assertStringNotContainsString('criteria', json_encode($data['display']));

        $this->actingAs(User::findOrFail($this->f['teacher_id']))->getJson($this->base('admin').'/proposals/'.$uuid.'/inherited-draft-preview?base_version_id='.$this->f['version_id'])->assertForbidden();
    }

    public function test_the_rebase_plan_names_each_intent_and_both_nodes_and_can_be_sent_back_unchanged(): void
    {
        $uuid = $this->acceptedReuse();
        $this->confirmTarget($uuid);
        $this->postJson($this->base().'/proposals/'.$uuid.'/intent-applications', $this->command($this->actionsOf($uuid)[2]))->assertOk();

        $this->actingAs(User::findOrFail($this->f['admin_id']));
        $path = $this->base('admin').'/proposals/'.$uuid;
        $plan = $this->getJson($path.'/inherited-draft-preview?base_version_id='.$this->f['version_id'])->assertOk()->json('data.preview');
        $draft = $this->postJson($path.'/inherited-drafts', ['request_id' => (string) Str::uuid(), 'base_version_id' => $this->f['version_id'],
            'version_code' => 'http-v2', 'title' => 'V2', 'expected_source_graph_hash' => $plan['source_graph_hash'],
            'expected_plan_hash' => $plan['plan_hash']])->assertOk()->json('data.result_version_id');
        $this->tenantService(LearningFrameworkAuthoringService::class)->publishVersion($this->f['admin_id'], $draft);

        $data = $this->getJson($path.'/rebase-preview?target_version_id='.$draft)->assertOk()->json('data');

        $this->assertSame(['preview', 'preview_hash', 'display'], array_keys($data), 'names sit outside the hashed plan');
        $row = $data['preview']['intents'][0];
        $shown = $data['display']['intents'][0];
        $this->assertSame($row['intent_id'], $shown['intent_id']);
        $intent = DB::table('core_course_template_learning_mapping_intents')->where('id', $row['intent_id'])->first();
        $table = $intent->source_type === 'course_template_lesson' ? 'core_course_template_lessons' : 'core_course_template_activities';
        $this->assertSame(DB::table($table)->where('id', $intent->source_id)->value('title'), $shown['source_label']);
        $this->assertSame($row['old_node_id'], $shown['old_node']['node_id']);
        $this->assertSame($row['proposed_node_id'], $shown['proposed_node']['node_id']);
        $this->assertSame(['node_id', 'definition_id', 'code', 'label', 'node_type', 'description', 'status'], array_keys($shown['proposed_node']));
        $this->assertStringNotContainsString('criteria', json_encode($data['display']));

        $this->postJson($path.'/rebases', ['request_id' => (string) Str::uuid(), 'target_version_id' => $draft, 'expected_preview_hash' => $data['preview_hash'],
            'dispositions' => [(string) $row['intent_id'] => ['disposition' => 'map', 'node_id' => $row['proposed_node_id']]]])->assertOk();
        $this->assertSame($draft, (int) DB::table('core_course_templates')->where('id', $this->f['template_id'])->value('selected_learning_framework_version_id'));
    }

    public function test_the_copy_plan_lists_only_this_templates_intents(): void
    {
        $uuid = $this->acceptedReuse();
        $path = $this->base().'/proposals/'.$uuid;
        $this->confirmTarget($uuid);
        $this->postJson($path.'/intent-applications', $this->command($this->actionsOf($uuid)[2]))->assertOk();
        $mapping = $this->getJson($path)->assertOk()->json('data.payload.mapping');
        DB::table('core_learning_node_definitions')->where('id', $mapping['definition_id'])->update(['status' => 'archived']);
        DB::table('core_course_templates')->insert([
            'customer_id' => $this->f['customer_id'], 'category_id' => null, 'title' => 'Another Template', 'estimated_minutes_per_lesson' => 0,
            'lesson_count' => 0, 'working_revision' => 1, 'status' => 'draft', 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
            'selected_learning_framework_id' => $this->f['framework_id'], 'selected_learning_framework_version_id' => $this->f['version_id'],
        ]);
        $otherTemplate = (int) DB::table('core_course_templates')->where('title', 'Another Template')->value('id');
        $copy = (array) DB::table('core_course_template_learning_mapping_intents')->where('learning_node_id', $mapping['node_id'])->first();
        unset($copy['id']);
        DB::table('core_course_template_learning_mapping_intents')->insert(['template_id' => $otherTemplate] + $copy);

        $this->actingAs(User::findOrFail($this->f['admin_id']));
        $display = $this->getJson($this->base('admin').'/proposals/'.$uuid.'/inherited-draft-preview?base_version_id='.$this->f['version_id'])->assertOk()->json('data.display');

        $this->assertCount(1, $display['affected_intents'], 'the other Template shares the Node but is not listed');
    }

    public function test_a_failed_apply_receipt_can_be_retried_by_both_and_cancelled(): void
    {
        $uuid = $this->acceptedReuse();
        $this->confirmTarget($uuid);
        $receipt = DB::table('ai_authoring_proposal_applications')->sole();
        DB::table('ai_authoring_proposal_applications')->where('id', $receipt->id)->update(['status' => 'failed', 'error_code' => 'owner_failure']);

        $this->assertSame([['retry', 'cancel']], array_values($this->actionsOf($uuid)[1]), 'teacher, apply_intent');
        $this->assertSame([['retry', 'cancel']], array_values($this->actionsOf($uuid, 'admin')[1]));
    }

    public function test_a_cancelled_receipt_ends_the_offers(): void
    {
        $uuid = $this->acceptedReuse();
        $this->confirmTarget($uuid);
        $receipt = DB::table('ai_authoring_proposal_applications')->sole();
        $lock = $this->actionsOf($uuid)[2];
        $this->postJson($this->base().'/proposals/'.$uuid.'/applications/'.$receipt->application_uuid.'/cancel',
            $this->command($lock) + ['reason_code' => 'human_cancel'])->assertOk();

        [$actions, $receipts] = $this->actionsOf($uuid);

        $this->assertSame([], $actions);
        $this->assertSame([[]], array_values($receipts));
    }

    public function test_a_changed_course_context_offers_reconfirmation_and_withholds_what_it_would_refuse(): void
    {
        $uuid = $this->acceptedReuse();
        $path = $this->base().'/proposals/'.$uuid;
        $this->confirmTarget($uuid);
        $this->assertContains('apply_intent', $this->actionsOf($uuid)[0]);

        DB::table('core_course_template_activities')->where('id', $this->f['activity_id'])->update(['description' => 'New context']);
        DB::table('ai_authoring_proposal_applications')->update(['status' => 'failed', 'error_code' => 'owner_failure']);

        $this->assertTrue($this->getJson($path)->json('data.context_changed'), 'the context changed');
        [$actions, $receipts, $lock] = $this->actionsOf($uuid);
        $this->assertContains('reconfirm_context', $actions);
        $this->assertNotContains('apply_intent', $actions, 'it would answer proposal_context_changed');
        $this->assertSame([['cancel']], array_values($receipts), 'retry too would be refused');

        $hash = $this->getJson($path.'/context-preview')->assertOk()->json('data.course_context_hash');
        $this->postJson($path.'/context-confirmations', $this->command($lock) + ['expected_course_context_hash' => $hash])->assertOk();

        [$actions, $receipts] = $this->actionsOf($uuid);
        $this->assertNotContains('reconfirm_context', $actions, 'reconfirmed: nothing left to confirm');
        $this->assertSame([['retry', 'cancel']], array_values($receipts));
        $this->assertFalse($this->getJson($path)->json('data.context_changed'), 'reconfirmed: the flag agrees with the actions');

        DB::table('core_course_template_activities')->where('id', $this->f['activity_id'])->update(['description' => 'Newer context']);
        $this->assertTrue($this->getJson($path)->json('data.context_changed'), 'a later change is a change again');
        $this->assertContains('reconfirm_context', $this->actionsOf($uuid)[0]);
    }

    public function test_every_offered_action_is_not_refused_for_the_state_it_was_offered_in(): void
    {
        $uuid = $this->acceptedReuse();
        $path = $this->base().'/proposals/'.$uuid;
        [$actions, , $lock] = $this->actionsOf($uuid);
        $this->assertContains('preview_target', $actions);
        $hash = $this->getJson($path.'/target-preview')->assertOk()->json('data.target_hash');
        $this->assertContains('confirm_target', $actions);
        $this->postJson($path.'/target-confirmations', $this->command($lock) + ['expected_target_hash' => $hash])->assertOk();

        [$actions, $receipts, $lock] = $this->actionsOf($uuid);
        $this->assertContains('apply_intent', $actions);
        $cancel = $this->postJson($path.'/applications/'.array_key_first($receipts).'/cancel', $this->command($lock) + ['reason_code' => 'human_cancel']);
        $this->assertSame(200, $cancel->status(), 'cancel was offered on a ready receipt');
        $this->assertSame([], $this->actionsOf($uuid)[0], 'and once cancelled nothing is offered');

        $applied = $this->acceptedReuse();
        $this->confirmTarget($applied);
        [, , $lock] = $this->actionsOf($applied);
        $this->assertSame(200, $this->postJson($this->base().'/proposals/'.$applied.'/intent-applications', $this->command($lock))->status());
        [$actions, , $lock] = $this->actionsOf($applied);
        $this->assertContains('reject_target', $actions);
        $target = $this->getJson($this->base().'/proposals/'.$applied.'/target-preview')->assertOk()->json('data.target_hash');
        $this->assertSame(200, $this->postJson($this->base().'/proposals/'.$applied.'/target-rejections', $this->command($lock) + ['expected_target_hash' => $target])->status());
    }

    public function test_the_list_keeps_only_the_pending_values(): void
    {
        $uuid = $this->acceptedReuse();

        $item = $this->getJson($this->base().'/proposals')->assertOk()->json('data.items.0');

        $this->assertSame([], $item['allowed_actions'], 'accepted items list no actions; the detail has them');
        $this->assertNotSame([], $this->actionsOf($uuid)[0]);
    }

    // ------------------------------------------- P3-B: display of an existing Node

    public function test_a_reuse_mapping_shows_the_node_to_the_teacher_who_reviews_it(): void
    {
        $uuid = $this->generateHttp(null, ['node_mapping']);

        $data = $this->getJson($this->base().'/proposals/'.$uuid)->assertOk()->json('data');

        $node = $data['mapping_node'];
        $this->assertSame(['node_id', 'definition_id', 'code', 'label', 'node_type', 'description', 'status'], array_keys($node));
        $this->assertSame($data['payload']['mapping']['node_id'], $node['node_id']);
        $this->assertSame($data['payload']['mapping']['definition_id'], $node['definition_id']);
        $this->assertNotSame('', $node['label']);
        $this->assertSame('active', $node['status']);
        $this->assertArrayNotHasKey('criteria', $node);
        // The stored payload is not touched: the display is a sibling, never inside it.
        $keys = array_keys($data['payload']['mapping']);
        sort($keys);
        $this->assertSame(['definition_id', 'mode', 'node_id', 'role', 'weight'], $keys);
    }

    public function test_the_node_is_the_same_for_an_admin_and_absent_for_other_kinds_and_the_list(): void
    {
        $uuid = $this->generateHttp(null, ['summary', 'node_mapping']);
        $summary = $this->getJson($this->base().'/proposals/'.$uuid)->assertOk();
        $summary->assertJsonMissingPath('data.mapping_node');
        $mapping = DB::table('ai_authoring_proposals')->where('kind', 'node_mapping')->value('proposal_uuid');

        $this->actingAs(User::findOrFail($this->f['admin_id']))
            ->getJson($this->base('admin').'/proposals/'.$mapping)->assertOk()->assertJsonPath('data.mapping_node.status', 'active');
        $this->getJson($this->base('admin').'/proposals')->assertOk()->assertJsonMissingPath('data.items.0.mapping_node');
    }

    public function test_the_node_is_disclosed_only_where_the_payload_is(): void
    {
        $uuid = $this->generateHttp(null, ['node_mapping']);
        DB::table('media_file_usages')->where('media_file_id', $this->f['media_id'])->update(['status' => 'detached']);

        $this->getJson($this->base().'/proposals/'.$uuid)->assertOk()
            ->assertJsonPath('data.payload', null)->assertJsonMissingPath('data.mapping_node');
    }

    public function test_an_unassigned_teacher_learns_nothing_about_the_node(): void
    {
        $uuid = $this->generateHttp(null, ['node_mapping']);
        DB::table('core_course_template_teachers')->where('teacher_id', $this->f['teacher_id'])->update(['status' => 'archived']);

        $this->getJson($this->base().'/proposals/'.$uuid)->assertNotFound()->assertJsonMissingPath('data.mapping_node');
    }

    public function test_the_display_port_shows_nothing_for_a_pair_that_is_not_exactly_a_published_active_node(): void
    {
        $uuid = $this->generateHttp(null, ['node_mapping']);
        $mapping = $this->getJson($this->base().'/proposals/'.$uuid)->json('data.payload.mapping');
        $port = $this->tenantService(LearningAuthoringBasisService::class);
        $f = $this->f;

        $this->assertNotNull($port->nodeDisplay($f['framework_id'], $f['version_id'], $mapping['node_id'], $mapping['definition_id']));
        // A definition that is not the node's, another version, another framework, another id.
        $this->assertNull($port->nodeDisplay($f['framework_id'], $f['version_id'], $mapping['node_id'], $mapping['definition_id'] + 1));
        $this->assertNull($port->nodeDisplay($f['framework_id'], $f['version_id'] + 1, $mapping['node_id'], $mapping['definition_id']));
        $this->assertNull($port->nodeDisplay($f['framework_id'] + 1, $f['version_id'], $mapping['node_id'], $mapping['definition_id']));
        $this->assertNull($port->nodeDisplay($f['framework_id'], $f['version_id'], $mapping['node_id'] + 1000, $mapping['definition_id']));
    }

    public function test_the_description_is_cut_to_what_a_reviewer_needs(): void
    {
        // Published Learning content cannot be edited, so a Node with a long description is made the proper way.
        $authoring = $this->tenantService(LearningFrameworkAuthoringService::class);
        $admin = $this->f['admin_id'];
        $framework = $authoring->createFramework($admin, [
            'code' => 'fw-long', 'name' => 'Long', 'mastery_scale_key' => 'direct', 'mastery_scale_version' => '1',
            'mastery_scale' => ['levels' => [['key' => 'novice', 'threshold' => 0], ['key' => 'mastered', 'threshold' => 0.8]]],
        ]);
        $definition = $authoring->createDefinition($admin, [
            'framework_id' => $framework->id, 'code' => 'D-LONG', 'node_type' => 'objective', 'canonical_name' => 'Dài',
            'description' => str_repeat('é', 900),
        ]);
        $version = $authoring->createDraftVersion($admin, ['framework_id' => $framework->id, 'version_code' => 'v1', 'title' => 'V1']);
        $node = $authoring->createNode($admin, ['framework_version_id' => $version->id, 'node_definition_id' => $definition->id]);
        $authoring->publishVersion($admin, (int) $version->id);

        $shown = $this->tenantService(LearningAuthoringBasisService::class)
            ->nodeDisplay((int) $framework->id, (int) $version->id, (int) $node->id, (int) $definition->id);

        $this->assertSame(LearningAuthoringBasisService::DISPLAY_DESCRIPTION_MAX, mb_strlen($shown['description']));
        $this->assertSame(str_repeat('é', 500), $shown['description']);
    }

    public function test_a_node_of_another_tenant_is_never_shown(): void
    {
        $uuid = $this->generateHttp(null, ['node_mapping']);
        $mapping = $this->getJson($this->base().'/proposals/'.$uuid)->json('data.payload.mapping');
        $other = $this->authoringFixture('b');

        TenantContext::set((object) ['id' => $other['customer_id']]);
        $port = $this->app->make(LearningAuthoringBasisService::class);

        $this->assertNull($port->nodeDisplay($this->f['framework_id'], $this->f['version_id'], $mapping['node_id'], $mapping['definition_id']));
    }

    private function generateHttp(?string $request = null, array $kinds = ['summary']): string
    {
        $this->actingAs(User::findOrFail($this->f['teacher_id']));
        $body = ['request_id' => $request ?? (string) Str::uuid(), 'requested_kinds' => $kinds];
        if (in_array('node_mapping', $kinds, true)) {
            $body += ['framework_id' => $this->f['framework_id'], 'framework_version_id' => $this->f['version_id']];
        }

        return $this->postJson($this->base().'/generation-requests', $body)->assertOk()->json('data.proposals.0.proposal_uuid');
    }

    private function command(int $lock = 1): array
    {
        return ['request_id' => (string) Str::uuid(), 'expected_lock_version' => $lock];
    }

    private function decision(): array
    {
        return $this->command() + ['expected_revision_no' => 1, 'action' => 'accept'];
    }
}
