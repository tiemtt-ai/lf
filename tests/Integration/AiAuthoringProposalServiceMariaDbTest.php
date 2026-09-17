<?php

namespace Tests\Integration;

use App\Contracts\Ai\AuthoringProposalProvider;
use App\Contracts\Ai\CommercialEntitlements;
use App\Contracts\Ai\ExternalProcessingApprovals;
use App\Contracts\Ai\TenantSettingSource;
use App\Contracts\Ai\UsageQuotaReserver;
use App\Models\User;
use App\Services\Ai\SettingBackedExternalProcessingApprovals;
use App\Services\AiAuthoringProposalService;
use App\Services\CourseTemplateLearningMappingIntentService;
use App\Services\LearningFrameworkAuthoringService;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaReadService;
use App\Services\MediaService;
use App\Support\Ai\AuthoringPromptContract;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Ai\FakeAuthoringProposalProvider;
use Tests\Support\Ai\FakeCommercialEntitlements;
use Tests\Support\Ai\FakeTenantSettings;
use Tests\Support\Ai\FakeUsageQuotaReserver;
use Tests\TestCase;

/**
 * Step 7 generation, read and review against the real Media pipeline, Course
 * rows and Learning graph on MariaDB (the packet does not exist on SQLite).
 * Anchors are compared with what Media Read returns, not hand-written values.
 */
class AiAuthoringProposalServiceMariaDbTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,mixed> */
    private array $f;

    private FakeTenantSettings $settings;

    private FakeCommercialEntitlements $entitlements;

    private FakeAuthoringProposalProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The Step 7 authoring packet exists only on MariaDB.');
        }

        Storage::fake('media_local');
        config([
            'media.disk' => 'media_local', 'media.bucket' => 'test-media',
            'ai.providers' => ['approved-provider' => [
                'managed' => false, 'models' => ['authoring-model'], 'purposes' => ['authoring_proposal'],
                'regions' => ['lf_managed'], 'retention_classes' => ['none', 'transient'], 'data_classes' => ['derived_text'],
            ]],
            'ai.authoring.provider' => 'approved-provider',
            'ai.authoring.model' => 'authoring-model',
        ]);

        $this->settings = new FakeTenantSettings;
        $this->entitlements = new FakeCommercialEntitlements;
        $this->provider = new FakeAuthoringProposalProvider;
        $this->app->instance(TenantSettingSource::class, $this->settings);
        $this->app->bind(ExternalProcessingApprovals::class, SettingBackedExternalProcessingApprovals::class);
        $this->app->instance(CommercialEntitlements::class, $this->entitlements);
        $this->app->instance(UsageQuotaReserver::class, new FakeUsageQuotaReserver(100.0));
        $this->app->instance(AuthoringProposalProvider::class, $this->provider);

        $this->f = $this->fixture('a');
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- Generate

    public function test_an_unconfigured_deployment_reads_no_source_and_writes_nothing(): void
    {
        config(['ai.authoring.model' => '']);
        $logs = DB::table('media_access_logs')->count();

        $outcome = $this->generate();

        $this->assertSame('AI_APPROVAL_REQUIRED', $outcome['error_code']);
        $this->assertSame('configuration', $outcome['blocked_at']);
        $this->assertSame($logs, DB::table('media_access_logs')->count());
        $this->assertSame(0, DB::table('ai_authoring_generation_requests')->count());
        $this->assertSame(0, DB::table('ai_model_runs')->count());
        $this->assertSame([], $this->provider->calls);
    }

    public function test_generation_seals_items_with_exact_media_anchors_and_prompt_provenance(): void
    {
        $this->approve();
        $courseBefore = $this->ownerSnapshot();

        $outcome = $this->generate(['summary', 'node_mapping']);

        $this->assertNull($outcome['error_code']);
        $this->assertSame('completed', $outcome['request_status']);
        $this->assertCount(2, $outcome['proposals']);
        $this->assertCount(1, $this->provider->calls);

        $request = DB::table('ai_authoring_generation_requests')->sole();
        $prompt = new AuthoringPromptContract;
        $run = DB::table('ai_model_runs')->where('id', $request->model_run_id)->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame($request->run_uuid, $run->run_uuid);
        $this->assertSame('sha256:'.$prompt->hash(), $run->prompt_hash);
        $this->assertSame($prompt->hash(), $request->prompt_hash);
        $this->assertSame(2, (int) $request->item_count);

        $units = $this->mediaUnits();
        foreach (DB::table('ai_authoring_proposals')->orderBy('item_ordinal')->get() as $proposal) {
            $this->assertSame('pending_review', $proposal->status);
            $this->assertNotNull($proposal->sources_sealed_at);
            $this->assertSame((int) $request->model_run_id, (int) $proposal->model_run_id);
            $source = DB::table('ai_authoring_proposal_sources')->where('proposal_id', $proposal->id)->sole();
            $this->assertSame($units[0]['media_file_id'], (int) $source->media_file_id);
            $this->assertSame($units[0]['source_fingerprint'], $source->source_fingerprint);
            $this->assertSame($units[0]['processing_version'], $source->processing_version);
            $this->assertSame($units[0]['locator'], json_decode($source->locator, true));
            $this->assertNull($source->excerpt);
            $revision = DB::table('ai_authoring_proposal_revisions')->where('proposal_id', $proposal->id)->sole();
            $this->assertSame('generated', $revision->origin);
            $this->assertSame(hash('sha256', $revision->payload), $revision->payload_hash);
        }
        $mapping = DB::table('ai_authoring_proposals')->where('kind', 'node_mapping')->sole();
        $this->assertSame($this->f['framework_id'], (int) $mapping->framework_id);
        $this->assertNotNull($mapping->basis_hash);

        // AI wrote no Course or Learning row and sent no source text anywhere.
        $this->assertSame($courseBefore, $this->ownerSnapshot());
        $this->assertStringNotContainsString($units[0]['text'], (string) $run->metadata);
    }

    public function test_an_exact_replay_returns_the_same_items_without_a_second_call(): void
    {
        $this->approve();
        $uuid = (string) Str::uuid();
        $first = $this->generate(['summary'], $uuid);
        $logs = DB::table('media_access_logs')->count();

        $second = $this->generate(['summary'], $uuid);

        $this->assertSame(array_column($first['proposals'], 'proposal_uuid'), array_column($second['proposals'], 'proposal_uuid'));
        $this->assertCount(1, $this->provider->calls);
        $this->assertSame(1, DB::table('ai_model_runs')->count());
        // A terminal replay reads no source again: it only returns the stored outcome.
        $this->assertSame($logs, DB::table('media_access_logs')->count());
    }

    public function test_the_same_request_uuid_with_other_kinds_conflicts_without_any_call(): void
    {
        $this->approve();
        $uuid = (string) Str::uuid();
        $this->generate(['summary'], $uuid);

        $outcome = $this->generate(['summary', 'concept'], $uuid);

        $this->assertSame('proposal_idempotency_conflict', $outcome['error_code']);
        $this->assertCount(1, $this->provider->calls);
    }

    public function test_a_zero_item_answer_completes_and_replays_without_calling_again(): void
    {
        $this->approve();
        $this->provider->respondWith(fn () => '{"items":[]}');
        $uuid = (string) Str::uuid();

        $first = $this->generate(['summary'], $uuid);
        $second = $this->generate(['summary'], $uuid);

        $this->assertSame(0, $first['item_count']);
        $this->assertSame(0, $second['item_count']);
        $this->assertSame('completed', DB::table('ai_authoring_generation_requests')->value('status'));
        $this->assertCount(1, $this->provider->calls);
    }

    /** @return array<string,array{0:\Closure}> */
    public static function unusableAnswers(): array
    {
        $summary = ['kind' => 'summary', 'title' => 'T', 'body' => 'B', 'confidence' => 0.5, 'rationale' => 'R', 'source_refs' => [1]];

        return [
            'not json' => [fn () => 'items: none'],
            'unknown key' => [fn () => json_encode(['items' => [$summary + ['extra' => 1]]])],
            'unrequested kind' => [fn () => json_encode(['items' => [array_replace($summary, ['kind' => 'concept'])]])],
            'source out of range' => [fn () => json_encode(['items' => [array_replace($summary, ['source_refs' => [999]])]])],
            'confidence above one' => [fn () => json_encode(['items' => [array_replace($summary, ['confidence' => 1.5])]])],
        ];
    }

    #[DataProvider('unusableAnswers')]
    public function test_an_unusable_answer_fails_the_request_and_writes_no_proposal(\Closure $answer): void
    {
        $this->approve();
        $this->provider->respondWith($answer);

        $outcome = $this->generate(['summary']);

        $this->assertSame('AI_PROVIDER_CALL_FAILED', $outcome['error_code']);
        $request = DB::table('ai_authoring_generation_requests')->sole();
        $this->assertSame('failed', $request->status);
        $this->assertSame('AI_PROVIDER_CALL_FAILED', $request->error_code);
        $this->assertSame(0, DB::table('ai_authoring_proposals')->count());
    }

    public function test_a_reuse_mapping_outside_the_offered_basis_fails_the_whole_answer(): void
    {
        $this->approve();
        $this->provider->respondWith(fn () => json_encode(['items' => [[
            'kind' => 'node_mapping', 'title' => 'T', 'body' => 'B', 'confidence' => 0.5, 'rationale' => 'R', 'source_refs' => [1],
            'mapping' => ['mode' => 'reuse_existing', 'node_id' => 999999, 'definition_id' => 1, 'role' => 'teaches', 'weight' => null],
        ]]]));

        $this->assertSame('AI_PROVIDER_CALL_FAILED', $this->generate(['node_mapping'])['error_code']);
        $this->assertSame(0, DB::table('ai_authoring_proposals')->count());
    }

    public function test_a_gate_refusal_fails_the_request_without_a_provider_call(): void
    {
        $outcome = $this->generate();   // no tenant approval

        $this->assertSame('AI_APPROVAL_REQUIRED', $outcome['error_code']);
        $this->assertSame('failed', DB::table('ai_authoring_generation_requests')->value('status'));
        $this->assertSame([], $this->provider->calls);
    }

    public function test_the_basis_must_be_the_template_selection_and_mapping_needs_one(): void
    {
        $this->approve();

        $this->assertSame('invalid_proposal', $this->service()->generate(
            $this->f['teacher_id'], $this->f['activity_id'], ['node_mapping'], null, null, (string) Str::uuid(),
        )['error_code']);
        $this->assertSame('framework_selection_conflict', $this->service()->generate(
            $this->f['teacher_id'], $this->f['activity_id'], ['node_mapping'], $this->f['framework_id'], $this->f['version_id'] + 1000, (string) Str::uuid(),
        )['error_code']);
        $this->assertSame(0, DB::table('ai_authoring_generation_requests')->count());
    }

    public function test_an_unassigned_teacher_and_another_tenant_see_nothing(): void
    {
        $this->approve();
        $proposal = $this->generate(['summary'])['proposals'][0]['proposal_uuid'];

        $this->assertSame('proposal_not_found', $this->service()->generate(
            $this->f['outsider_id'], $this->f['activity_id'], ['summary'], null, null, (string) Str::uuid(),
        )['error_code']);
        $this->assertSame('proposal_not_found', $this->service()->show($this->f['outsider_id'], $proposal)['error_code']);

        $other = $this->fixture('b');
        TenantContext::set((object) ['id' => $other['customer_id']]);
        $this->assertSame('proposal_not_found', $this->app->make(AiAuthoringProposalService::class)->show($other['admin_id'], $proposal)['error_code']);
    }

    // -------------------------------------------------------------------- Read

    public function test_an_assigned_teacher_reads_payload_citations_without_source_text(): void
    {
        $this->approve();
        $uuid = $this->generate(['summary'])['proposals'][0]['proposal_uuid'];

        $read = $this->service()->show($this->f['teacher_id'], $uuid);

        $this->assertNull($read['error_code']);
        $this->assertSame('summary', $read['payload']['kind']);
        $this->assertSame([1], $read['payload']['source_refs']);
        $this->assertSame(['edit', 'accept', 'reject'], $read['allowed_actions']);
        $this->assertArrayNotHasKey('text', $read['citations'][0]);
        $this->assertStringNotContainsString($this->mediaUnits()[0]['text'], json_encode($read));
    }

    // ------------------------------------------------------------------ Review

    public function test_an_edit_appends_a_human_revision_and_an_exact_replay_does_not_repeat_it(): void
    {
        $this->approve();
        $uuid = $this->generate(['summary'])['proposals'][0]['proposal_uuid'];
        $payload = $this->service()->show($this->f['teacher_id'], $uuid)['payload'];
        $payload['title'] = 'Tiêu đề đã sửa';
        $request = (string) Str::uuid();

        $edited = $this->service()->edit($this->f['teacher_id'], $uuid, 1, 1, $payload, 1, $request);
        $replayed = $this->service()->edit($this->f['teacher_id'], $uuid, 1, 1, $payload, 1, $request);

        $this->assertNull($edited['error_code']);
        $this->assertSame(2, $edited['revision_no']);
        $this->assertTrue($replayed['replayed']);
        $this->assertSame(2, DB::table('ai_authoring_proposal_revisions')->count());
        $this->assertSame('human', DB::table('ai_authoring_proposal_revisions')->where('revision_no', 2)->value('origin'));
        $this->assertSame('edit', DB::table('ai_authoring_proposal_reviews')->value('action'));

        $payload['title'] = 'Khác';
        $this->assertSame('proposal_idempotency_conflict', $this->service()->edit($this->f['teacher_id'], $uuid, 1, 1, $payload, 1, $request)['error_code']);
    }

    public function test_a_stale_expected_revision_or_lock_version_conflicts(): void
    {
        $this->approve();
        $uuid = $this->generate(['summary'])['proposals'][0]['proposal_uuid'];
        $payload = $this->service()->show($this->f['teacher_id'], $uuid)['payload'];
        $this->service()->edit($this->f['teacher_id'], $uuid, 1, 1, $payload, 1, (string) Str::uuid());

        $this->assertSame('proposal_revision_conflict', $this->service()->decide($this->f['admin_id'], $uuid, 'accept', 1, 2, (string) Str::uuid())['error_code']);
        $this->assertSame('proposal_revision_conflict', $this->service()->decide($this->f['admin_id'], $uuid, 'accept', 2, 1, (string) Str::uuid())['error_code']);
        $this->assertSame('pending_review', DB::table('ai_authoring_proposals')->value('status'));
    }

    public function test_an_invalid_edit_is_refused_by_field_and_writes_nothing(): void
    {
        $this->approve();
        $uuid = $this->generate(['node_mapping'])['proposals'][0]['proposal_uuid'];
        $payload = $this->service()->show($this->f['teacher_id'], $uuid)['payload'];

        $foreign = $payload;
        $foreign['mapping']['node_id'] = 999999;
        $weight = $payload;
        $weight['mapping']['weight'] = 1.2;

        $this->assertSame('mapping.candidate', $this->service()->edit($this->f['teacher_id'], $uuid, 1, 1, $foreign, 1, (string) Str::uuid())['detail']);
        $this->assertSame('mapping.weight', $this->service()->edit($this->f['teacher_id'], $uuid, 1, 1, $weight, 1, (string) Str::uuid())['detail']);
        $this->assertSame(1, DB::table('ai_authoring_proposal_revisions')->count());
        $this->assertSame(0, DB::table('ai_authoring_proposal_reviews')->count());
    }

    public function test_accept_records_the_exact_revision_and_closes_editing_without_publishing(): void
    {
        $this->approve();
        $uuid = $this->generate(['node_mapping'])['proposals'][0]['proposal_uuid'];
        $courseBefore = $this->ownerSnapshot();

        $accepted = $this->service()->decide($this->f['teacher_id'], $uuid, 'accept', 1, 1, (string) Str::uuid());

        $this->assertNull($accepted['error_code']);
        $this->assertSame('accepted', DB::table('ai_authoring_proposals')->value('status'));
        $review = DB::table('ai_authoring_proposal_reviews')->sole();
        $this->assertSame((int) DB::table('ai_authoring_proposal_revisions')->value('id'), (int) $review->revision_id);
        $this->assertSame(['accept', 'pending_review', 'accepted'], [$review->action, $review->from_status, $review->to_status]);
        $this->assertSame(0, DB::table('ai_authoring_proposal_applications')->count());
        $this->assertSame($courseBefore, $this->ownerSnapshot());

        $payload = $this->service()->show($this->f['teacher_id'], $uuid)['payload'];
        $this->assertSame('proposal_revision_conflict', $this->service()->edit($this->f['teacher_id'], $uuid, 1, 2, $payload, 1, (string) Str::uuid())['error_code']);
    }

    public function test_reject_is_terminal_for_review(): void
    {
        $this->approve();
        $uuid = $this->generate(['summary'])['proposals'][0]['proposal_uuid'];

        $this->assertNull($this->service()->decide($this->f['admin_id'], $uuid, 'reject', 1, 1, (string) Str::uuid(), 'Không phù hợp')['error_code']);
        $this->assertSame('rejected', DB::table('ai_authoring_proposals')->value('status'));
        $this->assertSame('proposal_revision_conflict', $this->service()->decide($this->f['admin_id'], $uuid, 'accept', 1, 2, (string) Str::uuid())['error_code']);
    }

    public function test_a_revoked_assignment_blocks_mutation(): void
    {
        $this->approve();
        $uuid = $this->generate(['summary'])['proposals'][0]['proposal_uuid'];
        DB::table('core_course_template_teachers')->where('teacher_id', $this->f['teacher_id'])->update(['status' => 'inactive']);

        $this->assertSame('proposal_not_found', $this->service()->decide($this->f['teacher_id'], $uuid, 'accept', 1, 1, (string) Str::uuid())['error_code']);
        $this->assertSame('pending_review', DB::table('ai_authoring_proposals')->value('status'));
    }

    // --------------------------------------------------------------- Freshness

    public function test_source_revision_drift_stales_pending_work_and_hides_its_payload(): void
    {
        $this->approve();
        $uuid = $this->generate(['summary'])['proposals'][0]['proposal_uuid'];
        $this->bumpOcrRevision();

        $read = $this->service()->show($this->f['teacher_id'], $uuid);

        $this->assertSame('stale', $read['status']);
        $this->assertNull($read['payload']);
        $this->assertSame('proposal_stale', $this->service()->decide($this->f['teacher_id'], $uuid, 'accept', 1, 2, (string) Str::uuid())['error_code']);
    }

    public function test_a_detached_source_denies_content_without_marking_stale(): void
    {
        $this->approve();
        $uuid = $this->generate(['summary'])['proposals'][0]['proposal_uuid'];
        DB::table('media_file_usages')->where('owner_id', $this->f['activity_id'])->update(['status' => 'detached']);

        $read = $this->service()->show($this->f['teacher_id'], $uuid);

        $this->assertSame('pending_review', $read['status']);
        $this->assertNull($read['payload']);
        $this->assertSame('detached', $read['content_denied']);
        $this->assertSame('proposal_stale', $this->service()->decide($this->f['teacher_id'], $uuid, 'accept', 1, 1, (string) Str::uuid())['error_code']);
        $this->assertSame('pending_review', DB::table('ai_authoring_proposals')->value('status'));
    }

    public function test_a_course_context_change_stales_pending_but_not_accepted_work(): void
    {
        $this->approve();
        $proposals = $this->generate(['summary', 'node_mapping'])['proposals'];
        $this->service()->decide($this->f['teacher_id'], $proposals[1]['proposal_uuid'], 'accept', 1, 1, (string) Str::uuid());
        DB::table('core_course_template_activities')->where('id', $this->f['activity_id'])->update(['title' => 'Tiêu đề mới']);

        $pending = $this->service()->show($this->f['teacher_id'], $proposals[0]['proposal_uuid']);
        $accepted = $this->service()->show($this->f['teacher_id'], $proposals[1]['proposal_uuid']);

        $this->assertSame('stale', $pending['status']);
        $this->assertSame('accepted', $accepted['status']);
        $this->assertTrue($accepted['context_changed']);
        $this->assertNotNull($accepted['payload']);
    }

    public function test_a_deployed_prompt_update_stales_generated_pending_work(): void
    {
        $this->approve();
        $uuid = $this->generate(['summary'])['proposals'][0]['proposal_uuid'];
        $this->app->instance(AuthoringPromptContract::class, new class extends AuthoringPromptContract
        {
            public function version(): int
            {
                return 2;
            }
        });

        $this->assertSame('stale', $this->service()->show($this->f['teacher_id'], $uuid)['status']);
    }

    // ----------------------------------------------------------------- Helpers

    private function service(): AiAuthoringProposalService
    {
        TenantContext::set((object) ['id' => $this->f['customer_id']]);

        return $this->app->make(AiAuthoringProposalService::class);
    }

    /** @param array<int,string> $kinds @return array<string,mixed> */
    private function generate(array $kinds = ['summary'], ?string $uuid = null): array
    {
        $basis = array_intersect($kinds, ['node_mapping', 'competency']) !== [];

        return $this->service()->generate(
            $this->f['teacher_id'], $this->f['activity_id'], $kinds,
            $basis ? $this->f['framework_id'] : null, $basis ? $this->f['version_id'] : null,
            $uuid ?? (string) Str::uuid(),
        );
    }

    private function approve(): void
    {
        $this->settings->approve($this->f['customer_id'], 'ai.external_processing.approved-provider.authoring_proposal', [
            'approved' => true, 'data_classes' => ['derived_text'],
            'execution_regions' => ['lf_managed'], 'retention_classes' => ['none'],
        ]);
        $this->entitlements->grant($this->f['customer_id'], 'ai_authoring_proposal');
    }

    /** @return array<int,array<string,mixed>> */
    private function mediaUnits(): array
    {
        TenantContext::set((object) ['id' => $this->f['customer_id']]);

        return app(MediaReadService::class)->read(
            $this->f['admin_id'], 'course_activity', $this->f['activity_id'], 'document', 'extracted_text',
        );
    }

    private function bumpOcrRevision(): void
    {
        DB::table('media_extracted_texts')->where('customer_id', $this->f['customer_id'])->update(['processing_version' => 'fake-v2']);
        DB::table('media_processing_jobs')->where('customer_id', $this->f['customer_id'])->where('job_type', 'ocr')->update(['processing_version' => 'fake-v2']);
    }

    /** @return array<string,string> Hash of every Course and Learning table. */
    private function ownerSnapshot(): array
    {
        $tables = collect(DB::select('SHOW TABLES'))->map(fn ($row) => array_values((array) $row)[0])
            ->filter(fn (string $name): bool => str_starts_with($name, 'core_course_') || str_starts_with($name, 'core_learning_'))
            ->sort()->values();

        return $tables->mapWithKeys(fn (string $table): array => [
            $table => hash('sha256', json_encode(DB::table($table)->orderBy(DB::raw('1'))->get())),
        ])->all();
    }

    /** @return array<string,mixed> */
    private function fixture(string $slug): array
    {
        $now = now();
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => 'authoring-'.$slug, 'subdomain' => 'authoring-'.$slug, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $user = fn (string $role, string $name): User => User::forceCreate([
            'customer_id' => $customerId, 'name' => $name, 'email' => "{$name}-{$slug}@example.test",
            'password' => Hash::make('password123'), 'role' => $role, 'status' => 'active', 'email_verified_at' => $now,
        ]);
        $admin = $user('customer_admin', 'admin');
        $teacher = $user('teacher', 'teacher');
        $outsider = $user('teacher', 'outsider');
        TenantContext::set((object) ['id' => $customerId]);

        $categoryId = DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId, 'parent_id' => null, 'name' => 'General '.$slug, 'slug' => 'general-'.$slug,
            'description' => null, 'thumbnail_image' => null, 'banner_image' => null, 'sort_order' => 1,
            'is_featured' => false, 'meta_title' => null, 'meta_description' => null, 'meta_keywords' => null,
            'status' => 'active', 'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId, 'category_id' => $categoryId, 'title' => 'Template '.$slug,
            'short_description' => 'Course', 'description' => 'Course description.', 'publisher_name' => 'LearnForge',
            'intro_video_source' => null, 'intro_image_media_file_id' => null, 'intro_video_media_file_id' => null,
            'difficulty_level' => 'beginner', 'estimated_minutes_per_lesson' => 30, 'estimated_lesson_count' => null,
            'lesson_count' => 1, 'meta_title' => null, 'meta_description' => null, 'meta_keywords' => null,
            'working_revision' => 1, 'status' => 'active', 'created_by' => $admin->id, 'last_version_published_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('core_course_template_teachers')->insert([
            'customer_id' => $customerId, 'template_id' => $templateId, 'teacher_id' => $teacher->id, 'role' => 'primary',
            'sort_order' => 0, 'status' => 'active', 'assigned_by' => $admin->id, 'assigned_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $lessonId = DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId, 'template_section_id' => null, 'title' => 'Lesson',
            'short_description' => null, 'description' => null, 'sort_order' => 0, 'is_preview' => false,
            'duration_seconds' => 0, 'activity_count' => 1, 'unlock_rule' => 'none', 'unlock_after_lesson_id' => null,
            'unlock_at' => null, 'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId, 'template_lesson_id' => $lessonId,
            'title' => 'Đọc tài liệu', 'description' => 'Đọc và ghi chú.', 'sort_order' => 0, 'activity_type' => 'document',
            'external_video_url' => null, 'live_class_url' => null, 'assessment_quiz_id' => null, 'duration_seconds' => 600,
            'is_required' => true, 'completion_rule' => 'view', 'completion_threshold' => null, 'is_preview' => false,
            'unlock_rule' => 'none', 'unlock_after_activity_id' => null, 'unlock_at' => null, 'created_by' => $admin->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $media = app(MediaService::class)->upload(UploadedFile::fake()->createWithContent('lesson.txt', 'Nội dung bài học về phân số.'), [
            'file_type' => 'document', 'module' => 'course', 'entity_type' => 'activities', 'entity_id' => $activityId, 'purpose' => 'document',
        ], $admin->id);
        DB::table('media_file_usages')->insert([
            'customer_id' => $customerId, 'media_file_id' => $media->id, 'owner_type' => 'course_activity', 'owner_id' => $activityId,
            'usage_type' => 'document', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($customerId, $media->id, 'vi', $admin->id);

        $authoring = app(LearningFrameworkAuthoringService::class);
        $framework = $authoring->createFramework($admin->id, [
            'code' => 'fw-'.$slug, 'name' => 'Framework '.$slug, 'mastery_scale_key' => 'direct', 'mastery_scale_version' => '1',
            'mastery_scale' => ['levels' => [['key' => 'novice', 'threshold' => 0], ['key' => 'mastered', 'threshold' => 0.8]]],
        ]);
        $definition = $authoring->createDefinition($admin->id, [
            'framework_id' => $framework->id, 'code' => 'D-'.$slug, 'node_type' => 'competency', 'canonical_name' => 'Phân số',
        ]);
        $version = $authoring->createDraftVersion($admin->id, ['framework_id' => $framework->id, 'version_code' => 'v1', 'title' => 'V1']);
        $authoring->createNode($admin->id, ['framework_version_id' => $version->id, 'node_definition_id' => $definition->id]);
        $authoring->publishVersion($admin->id, (int) $version->id);
        app(CourseTemplateLearningMappingIntentService::class)->select($admin->id, $customerId, $templateId, (int) $framework->id, (int) $version->id);

        return [
            'customer_id' => $customerId, 'admin_id' => (int) $admin->id, 'teacher_id' => (int) $teacher->id,
            'outsider_id' => (int) $outsider->id, 'template_id' => $templateId, 'activity_id' => $activityId,
            'framework_id' => (int) $framework->id, 'version_id' => (int) $version->id,
        ];
    }
}
