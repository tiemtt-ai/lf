<?php

namespace Tests\Integration;

use App\Models\User;
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
        DB::table('core_course_template_teachers')->where('teacher_id', $this->f['teacher_id'])->update(['status' => 'inactive']);
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
