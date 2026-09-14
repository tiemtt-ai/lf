<?php

namespace Tests\Feature;

use App\Contracts\Ai\CommercialEntitlements;
use App\Contracts\Ai\ExternalProcessingApprovals;
use App\Contracts\Ai\TenantSettingSource;
use App\Contracts\Ai\UsageQuotaReserver;
use App\Contracts\Ai\VisionInterpretationProvider;
use App\Events\MediaFileDeleted;
use App\Listeners\PurgeVisionInterpretationsOfDeletedMedia;
use App\Models\User;
use App\Services\Ai\SettingBackedExternalProcessingApprovals;
use App\Services\Ai\UnavailableVisionInterpretationProvider;
use App\Services\AiVisionInterpretationService;
use App\Services\CourseMediaOwnerContextAuthorizer;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaReadService;
use App\Services\MediaService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Ai\FakeCommercialEntitlements;
use Tests\Support\Ai\FakeTenantSettings;
use Tests\Support\Ai\FakeUsageQuotaReserver;
use Tests\Support\Ai\FakeVisionInterpretationProvider;
use Tests\TestCase;

/**
 * AiVisionInterpretationService against the real Media pipeline: a document is
 * uploaded and structurally extracted by the fake Media provider, then one
 * region is given a crop. Every anchor is compared with what Media Read itself
 * returns, not with hand-written expectations.
 */
class AiVisionInterpretationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_ID = 120;

    private int $customerId;

    private User $admin;

    private int $mediaId;

    private int $page;

    private string $locator;

    private FakeTenantSettings $settings;

    private FakeCommercialEntitlements $entitlements;

    private FakeUsageQuotaReserver $quota;

    private FakeVisionInterpretationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media_local');
        config([
            'media.disk' => 'media_local', 'media.bucket' => 'test-media',
            'media.processing.providers.structured_extraction' => 'fake',
            'media.processing.versions.structured_extraction' => 'fake-structured-v1',
            'ai.providers' => ['approved-provider' => [
                'managed' => false, 'models' => ['vision-model'], 'purposes' => ['vision_interpretation'],
                'regions' => ['lf_managed'], 'retention_classes' => ['none', 'transient'],
                'data_classes' => ['media_image'],
            ]],
            'ai.vision.provider' => 'approved-provider',
            'ai.vision.model' => 'vision-model',
            'ai.vision.max_interpretation_chars' => 200,
        ]);

        [$this->customerId, $this->admin] = $this->tenant('vision-a');
        [$this->mediaId, $this->page, $this->locator] = $this->documentWithCroppedRegion($this->customerId, $this->admin);

        $this->settings = new FakeTenantSettings;
        $this->entitlements = new FakeCommercialEntitlements;
        $this->quota = new FakeUsageQuotaReserver(100.0);
        $this->provider = new FakeVisionInterpretationProvider;
        $this->app->instance(TenantSettingSource::class, $this->settings);
        $this->app->bind(ExternalProcessingApprovals::class, SettingBackedExternalProcessingApprovals::class);
        $this->app->instance(CommercialEntitlements::class, $this->entitlements);
        $this->app->instance(UsageQuotaReserver::class, $this->quota);
        $this->app->instance(VisionInterpretationProvider::class, $this->provider);
        $this->authorize(true);
    }

    public function test_an_unconfigured_deployment_neither_reads_media_nor_mints_a_run(): void
    {
        config(['ai.vision.model' => '']);
        $logs = DB::table('media_access_logs')->count();

        $outcome = $this->interpret();

        $this->assertSame('LF_VISION_NOT_CONFIGURED', $outcome['error_code']);
        $this->assertSame($logs, DB::table('media_access_logs')->count());
        $this->assertSame(0, DB::table('ai_model_runs')->count());
        $this->assertSame([], $this->provider->calls);
    }

    public function test_the_shipped_provider_is_refused_before_any_call(): void
    {
        $this->approve();
        $this->app->bind(VisionInterpretationProvider::class, UnavailableVisionInterpretationProvider::class);

        $outcome = $this->interpret();

        $this->assertSame('AI_ADAPTER_MISMATCH', $outcome['error_code']);
        $this->assertSame(0, DB::table('ai_vision_interpretations')->count());
    }

    public function test_a_gate_refusal_writes_nothing_and_calls_no_provider(): void
    {
        $outcome = $this->interpret();   // no tenant approval

        $this->assertSame('AI_APPROVAL_REQUIRED', $outcome['error_code']);
        $this->assertSame('tenant_approval', $outcome['blocked_at']);
        $this->assertSame([], $this->provider->calls);
        $this->assertSame(0, DB::table('ai_vision_interpretations')->count());
        $this->assertSame('blocked', DB::table('ai_model_runs')->value('status'));
    }

    public function test_a_region_is_interpreted_with_its_anchor_copied_from_media_read(): void
    {
        $this->approve();
        $unit = $this->mediaUnit();

        $outcome = $this->interpret();

        $this->assertNull($outcome['error_code']);
        $this->assertFalse($outcome['reused']);
        $row = DB::table('ai_vision_interpretations')->where('interpretation_uuid', $outcome['interpretation_uuid'])->first();
        $run = DB::table('ai_model_runs')->where('id', $row->model_run_id)->first();

        $this->assertSame('ready', $row->status);
        $this->assertSame('completed', $run->status);
        $this->assertSame($run->run_uuid, $outcome['run_uuid']);
        $this->assertSame('vision_interpretation', $run->purpose);
        // Every anchor value is Media Read's, and page is the selector it was read with.
        $this->assertSame($unit['media_file_id'], (int) $row->media_file_id);
        $this->assertSame($unit['source_fingerprint'], $row->source_fingerprint);
        $this->assertSame($unit['processing_version'], $row->processing_version);
        $this->assertSame($unit['locale'], $row->locale);
        $this->assertSame($unit['locator']['value'], $row->locator_start);
        $this->assertSame(['course_activity', self::OWNER_ID, 'document', 'region', 'region', $this->page],
            [$row->source_type, (int) $row->source_id, $row->usage_type, $row->content_type, $row->locator_type, (int) $row->page]);
        $this->assertEqualsWithDelta($unit['structure']['bbox']['width'], (float) $row->bbox_width, 0.000001);
        $this->assertSame(hash('sha256', $row->interpretation), $row->interpretation_hash);
        $this->assertSame([['model' => 'vision-model', 'width' => 320, 'height' => 200, 'has_url' => true, 'role' => $unit['structure']['role']]],
            $this->provider->calls);
    }

    public function test_no_media_table_is_written_and_the_signed_url_is_never_persisted(): void
    {
        $this->approve();
        $url = null;
        $this->provider->respondWith(function ($image) use (&$url): string {
            $url = $image->deliveryUrl;

            return 'Sơ đồ quy trình ba bước.';
        });
        $before = $this->mediaEvidenceSnapshot();

        $outcome = $this->interpret();

        $this->assertNull($outcome['error_code']);
        // Media evidence is untouched. media_access_logs is excluded only because
        // Media Read writes its own access audit for the crop it signed.
        $this->assertSame($before, $this->mediaEvidenceSnapshot());
        $this->assertIsString($url);
        foreach (['ai_vision_interpretations', 'ai_model_runs', 'media_access_logs'] as $table) {
            $dump = json_encode(DB::table($table)->get(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString($url, $dump, "signed URL leaked into {$table}");
        }
        $this->assertStringNotContainsString('Sơ đồ quy trình', json_encode(DB::table('media_access_logs')->get(), JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,array{0:Closure}> */
    public static function unusableOutputs(): array
    {
        return [
            'empty' => [static fn ($image): string => "  \n "],
            'too long' => [static fn ($image): string => str_repeat('a', 201)],
            'echoes the signed URL' => [static fn ($image): string => 'Xem ảnh tại '.$image->deliveryUrl],
        ];
    }

    #[DataProvider('unusableOutputs')]
    public function test_unusable_output_fails_the_run_and_writes_no_row(Closure $respond): void
    {
        $this->approve();
        $this->provider->respondWith($respond);

        $outcome = $this->interpret();

        // Validated inside the adapter, so the gate records the call failed with
        // the approved code and there is never a `completed` run without a row.
        $this->assertSame('AI_PROVIDER_CALL_FAILED', $outcome['error_code']);
        $this->assertSame(0, DB::table('ai_vision_interpretations')->count());
        $this->assertSame('failed', DB::table('ai_model_runs')->value('status'));
    }

    public function test_a_different_provider_is_refused_as_adapter_mismatch(): void
    {
        $this->approve();
        $impostor = new FakeVisionInterpretationProvider(name: 'other-provider');
        $this->app->instance(VisionInterpretationProvider::class, $impostor);

        $outcome = $this->interpret();

        $this->assertSame('AI_ADAPTER_MISMATCH', $outcome['error_code']);
        $this->assertSame([], $impostor->calls);
        $this->assertSame(0, DB::table('ai_vision_interpretations')->count());
    }

    public function test_a_refusal_on_the_second_authorization_writes_nothing(): void
    {
        $this->approve();
        $this->quota->onReserve(fn () => $this->settings->revoke(
            $this->customerId, 'ai.external_processing.approved-provider.vision_interpretation',
        ));

        $outcome = $this->interpret();

        // Returned, not thrown, by execute(); reading it as success is the defect
        // fixed once in the embedding worker.
        $this->assertSame('AI_APPROVAL_REQUIRED', $outcome['error_code']);
        $this->assertSame([], $this->provider->calls);
        $this->assertSame(0, DB::table('ai_vision_interpretations')->count());
    }

    public function test_an_unknown_region_locator_never_reaches_the_gate(): void
    {
        $this->approve();

        $outcome = $this->interpret(locator: 'no-such-region');

        $this->assertSame('LF_VISION_REGION_NOT_FOUND', $outcome['error_code']);
        $this->assertSame(0, DB::table('ai_model_runs')->count());
    }

    public function test_a_region_without_a_crop_never_reaches_the_gate(): void
    {
        $this->approve();
        DB::table('media_extracted_regions')->where('media_file_id', $this->mediaId)
            ->update(['crop_storage_key' => null, 'crop_mime_type' => null, 'crop_width' => null, 'crop_height' => null, 'crop_bytes' => null]);

        $outcome = $this->interpret();

        $this->assertSame('LF_VISION_IMAGE_UNAVAILABLE', $outcome['error_code']);
        $this->assertSame(0, DB::table('ai_model_runs')->count());
    }

    public function test_a_media_read_refusal_is_returned_by_name(): void
    {
        $this->approve();
        $this->authorize(false);

        $outcome = $this->interpret();

        $this->assertSame('unauthorized', $outcome['error_code']);
        $this->assertSame(0, DB::table('ai_model_runs')->count());
    }

    public function test_an_existing_ready_interpretation_is_reused_and_refresh_supersedes_it(): void
    {
        $this->approve();
        $first = $this->interpret();

        $again = $this->interpret();
        $this->assertTrue($again['reused']);
        $this->assertSame($first['interpretation_uuid'], $again['interpretation_uuid']);
        $this->assertCount(1, $this->provider->calls);

        $refreshed = $this->interpret(refresh: true);

        $this->assertFalse($refreshed['reused']);
        $this->assertCount(2, $this->provider->calls);
        $this->assertSame('stale', $this->interpretationStatus($first['interpretation_uuid']));
        $this->assertSame('ready', $this->interpretationStatus($refreshed['interpretation_uuid']));
        $this->assertSame(1, DB::table('ai_vision_interpretations')->where('status', 'ready')->count());
    }

    public function test_a_competing_ready_row_written_during_the_call_is_superseded(): void
    {
        $this->approve();
        $unit = $this->mediaUnit();
        $competitor = null;
        // A concurrent writer lands in the window between the gate claiming the
        // run and this service storing its row. The later completion wins.
        $this->provider->during(function () use ($unit, &$competitor): void {
            $competitor = $this->insertInterpretation($this->customerId, $unit, $this->completedRun($this->customerId));
        });

        $outcome = $this->interpret();

        $this->assertNull($outcome['error_code']);
        $this->assertSame('stale', $this->interpretationStatus($competitor));
        $this->assertSame('ready', $this->interpretationStatus($outcome['interpretation_uuid']));
        $this->assertSame(1, DB::table('ai_vision_interpretations')->where('status', 'ready')->count());
    }

    public function test_interpreting_the_current_revision_stales_an_older_revision(): void
    {
        $this->approve();
        $old = $this->insertInterpretation($this->customerId, array_replace($this->mediaUnit(), [
            'source_fingerprint' => str_repeat('0', 64), 'processing_version' => 'older-structured-v0',
        ]), $this->completedRun($this->customerId));

        $this->interpret();

        $this->assertSame('stale', $this->interpretationStatus($old));
        $this->assertSame(1, DB::table('ai_vision_interpretations')->where('status', 'ready')->count());
    }

    public function test_retrieval_returns_citations_and_audits_without_text(): void
    {
        $this->approve();
        $created = $this->interpret();

        $hits = $this->service()->forOwner($this->admin->id, 'course_activity', self::OWNER_ID);

        $this->assertCount(1, $hits);
        $this->assertSame($created['interpretation_uuid'], $hits[0]['interpretation_uuid']);
        $this->assertSame($created['run_uuid'], $hits[0]['run_uuid']);
        $this->assertSame('vision-model', $hits[0]['model']);
        $this->assertSame($this->page, $hits[0]['page']);
        $audit = $this->lastVisionAudit();
        $this->assertSame('allowed', $audit['decision']);
        $this->assertSame($created['interpretation_uuid'], $audit['interpretation_uuid']);
        $this->assertArrayNotHasKey('interpretation', $audit);
    }

    public function test_retrieval_denies_a_detached_usage_by_name(): void
    {
        $this->approve();
        $this->interpret();
        DB::table('media_file_usages')->where('media_file_id', $this->mediaId)->update(['status' => 'detached']);

        $hits = $this->service()->forOwner($this->admin->id, 'course_activity', self::OWNER_ID);

        $this->assertSame([], $hits);
        $this->assertSame('detached', $this->lastVisionAudit()['error_code']);
    }

    public function test_retrieval_denies_a_row_whose_revision_moved_on(): void
    {
        $this->approve();
        $this->interpret();
        DB::table('ai_vision_interpretations')->update(['source_fingerprint' => str_repeat('9', 64)]);

        $hits = $this->service()->forOwner($this->admin->id, 'course_activity', self::OWNER_ID);

        $this->assertSame([], $hits);
        $this->assertSame('revision_mismatch', $this->lastVisionAudit()['error_code']);
    }

    public function test_retrieval_discloses_nothing_when_audit_cannot_be_written(): void
    {
        $this->approve();
        $this->interpret();
        // The real Media-owned audit refuses to record an `allowed` decision for
        // an actor it cannot resolve in the tenant. Owner authorization is mocked
        // open, so retrieval reaches exactly that audit step and must stop there.
        $unknownActor = 987654;
        $hits = null;

        try {
            $hits = $this->service()->forOwner($unknownActor, 'course_activity', self::OWNER_ID);
            $this->fail('Retrieval must not return when its access evidence cannot be written.');
        } catch (RuntimeException $exception) {
            $this->assertSame('LF_RETRIEVAL_AUDIT_ACTOR_UNAVAILABLE', $exception->getMessage());
        }

        $this->assertNull($hits);
        $this->assertNull(collect(DB::table('media_access_logs')->get())
            ->map(fn ($r) => json_decode($r->metadata, true))
            ->first(fn ($m) => ($m['operation'] ?? null) === 'vision_interpretation_retrieval' && ($m['decision'] ?? null) === 'allowed'));
    }

    public function test_another_tenant_cannot_see_or_delete_interpretations(): void
    {
        $this->approve();
        $created = $this->interpret();

        [$other] = $this->tenant('vision-b');
        TenantContext::set((object) ['id' => $other]);

        $this->assertSame([], $this->service()->forOwner($this->admin->id, 'course_activity', self::OWNER_ID));
        $this->assertSame(0, $this->service()->requestDeletionForMediaFile($this->mediaId));
        $this->assertSame(0, $this->service()->finalizeDeletion());
        $this->assertSame('ready', $this->interpretationStatus($created['interpretation_uuid']));
    }

    public function test_a_deletion_request_is_idempotent_and_keeps_the_original_time(): void
    {
        $this->approve();
        $stale = $this->interpret()['interpretation_uuid'];
        $ready = $this->interpret(refresh: true)['interpretation_uuid'];

        $this->travelTo(Carbon::parse('2026-09-01 08:00:00'));
        $this->assertSame(2, $this->service()->requestDeletionForMediaFile($this->mediaId));
        $firstRequest = now()->toDateTimeString();

        $this->travel(7)->days();
        $this->assertSame(0, $this->service()->requestDeletionForMediaFile($this->mediaId));

        foreach ([$stale, $ready] as $uuid) {
            $row = DB::table('ai_vision_interpretations')->where('interpretation_uuid', $uuid)->first();
            $this->assertSame('deletion_pending', $row->status);
            $this->assertSame($firstRequest, Carbon::parse($row->deletion_requested_at)->toDateTimeString());
        }
        $this->travelBack();
    }

    public function test_finalizing_deletion_erases_text_keeps_provenance_and_hides_it(): void
    {
        $this->approve();
        $uuid = $this->interpret()['interpretation_uuid'];
        $this->service()->requestDeletionForMediaFile($this->mediaId);

        $this->assertSame([], $this->service()->forOwner($this->admin->id, 'course_activity', self::OWNER_ID));
        $this->assertSame(1, $this->service()->finalizeDeletion());

        $row = DB::table('ai_vision_interpretations')->where('interpretation_uuid', $uuid)->first();
        $this->assertSame('deleted', $row->status);
        $this->assertNull($row->interpretation);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame(64, strlen($row->interpretation_hash));
        $this->assertNotNull($row->model_run_id);
    }

    public function test_deleting_the_media_file_erases_its_interpretations_and_no_other(): void
    {
        $this->approve();
        $stale = $this->interpret()['interpretation_uuid'];
        $ready = $this->interpret(refresh: true)['interpretation_uuid'];
        $otherMedia = app(MediaService::class)->upload(UploadedFile::fake()->createWithContent('other.txt', 'other'), [
            'file_type' => 'document', 'module' => 'course', 'entity_type' => 'activities',
            'entity_id' => self::OWNER_ID, 'purpose' => 'document',
        ], $this->admin->id);
        $unit = $this->mediaUnit();
        $unit['media_file_id'] = $otherMedia->id;
        $unit['locator']['value'] = 'other-region';
        $untouched = $this->insertInterpretation($this->customerId, $unit, $this->completedRun($this->customerId));
        $this->detachUsage();

        app(MediaService::class)->deleteMedia($this->mediaId);

        foreach ([$stale, $ready] as $uuid) {
            $row = DB::table('ai_vision_interpretations')->where('interpretation_uuid', $uuid)->first();
            $this->assertSame('deleted', $row->status);
            $this->assertNull($row->interpretation);
            $this->assertNotNull($row->deletion_requested_at);
            $this->assertNotNull($row->deleted_at);
        }
        $this->assertSame('ready', $this->interpretationStatus($untouched));
    }

    public function test_a_media_deletion_blocked_by_an_active_usage_keeps_interpretations(): void
    {
        $this->approve();
        $uuid = $this->interpret()['interpretation_uuid'];

        try {
            app(MediaService::class)->deleteMedia($this->mediaId);
            $this->fail('Deletion of an in-use Media File must be blocked.');
        } catch (ValidationException) {
        }

        $this->assertSame('ready', $this->interpretationStatus($uuid));
    }

    public function test_the_queued_listener_job_is_marked_after_commit(): void
    {
        Queue::fake();

        MediaFileDeleted::dispatch($this->customerId, $this->mediaId);

        // The flag every real driver (redis included) reads before enqueueing.
        Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === PurgeVisionInterpretationsOfDeletedMedia::class
            && $job->afterCommit === true);
    }

    public function test_the_listener_waits_for_the_outermost_commit(): void
    {
        $this->approve();
        $uuid = $this->interpret()['interpretation_uuid'];
        $this->detachUsage();
        $processed = $this->recordListenerJobs();

        DB::transaction(function () use ($uuid, &$processed): void {
            app(MediaService::class)->deleteMedia($this->mediaId);

            // Media's own transaction committed, the caller's has not: nothing
            // may be enqueued yet, or a worker could run before the tombstone
            // is visible and finish without erasing anything.
            $this->assertSame(0, $processed());
            $this->assertSame('ready', $this->interpretationStatus($uuid));
        });

        $this->assertSame(1, $processed());
        $this->assertSame('deleted', $this->interpretationStatus($uuid));
    }

    public function test_a_rolled_back_media_deletion_enqueues_nothing(): void
    {
        $this->approve();
        $uuid = $this->interpret()['interpretation_uuid'];
        $this->detachUsage();
        $processed = $this->recordListenerJobs();

        try {
            DB::transaction(function (): void {
                app(MediaService::class)->deleteMedia($this->mediaId);
                throw new RuntimeException('caller rolls back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $processed());
        $this->assertSame('ready', $this->interpretationStatus($uuid));
        $this->assertNotSame('deleted', DB::table('media_files')->where('id', $this->mediaId)->value('status'));
    }

    public function test_a_deletion_event_is_not_proof_of_deletion(): void
    {
        $this->approve();
        $uuid = $this->interpret()['interpretation_uuid'];

        MediaFileDeleted::dispatch($this->customerId, $this->mediaId);   // Media still exists

        $this->assertSame('ready', $this->interpretationStatus($uuid));
        $this->assertSame(0, $this->service()->purgeForDeletedMediaFile($this->mediaId));
    }

    public function test_the_listener_stays_in_the_event_tenant_and_restores_the_context(): void
    {
        $this->approve();
        $uuid = $this->interpret()['interpretation_uuid'];
        $this->detachUsage();
        Event::fakeFor(fn () => app(MediaService::class)->deleteMedia($this->mediaId), [MediaFileDeleted::class]);
        [$other] = $this->tenant('vision-b');
        $otherContext = TenantContext::customer();

        // Another tenant announcing this id deletes nothing: the tombstone is
        // re-checked inside that tenant, where the file does not exist.
        MediaFileDeleted::dispatch($other, $this->mediaId);
        $this->assertSame('ready', $this->interpretationStatus($uuid));

        MediaFileDeleted::dispatch($this->customerId, $this->mediaId);
        $this->assertSame('deleted', $this->interpretationStatus($uuid));
        $this->assertSame($otherContext, TenantContext::customer());
    }

    public function test_a_media_deleted_during_the_provider_call_leaves_no_interpretation(): void
    {
        $this->approve();
        $this->provider->during(function (): void {
            $this->detachUsage();
            app(MediaService::class)->deleteMedia($this->mediaId);
        });

        $outcome = $this->interpret();

        $this->assertSame('LF_VISION_MEDIA_DELETED', $outcome['error_code']);
        $this->assertNotNull($outcome['run_uuid']);
        $this->assertSame(1, DB::table('ai_vision_interpretations')->count());
        $row = DB::table('ai_vision_interpretations')->first();
        $this->assertSame('deleted', $row->status);
        $this->assertNull($row->interpretation);
    }

    public function test_reconciliation_erases_interpretations_a_missed_event_left_behind(): void
    {
        $this->approve();
        $uuid = $this->interpret()['interpretation_uuid'];
        $this->detachUsage();
        Event::fakeFor(fn () => app(MediaService::class)->deleteMedia($this->mediaId), [MediaFileDeleted::class]);
        $this->assertSame('ready', $this->interpretationStatus($uuid));
        $context = TenantContext::customer();

        // While the row waits for reconciliation it is still in the database,
        // but retrieval re-checks Media Read and refuses it, audited as denied.
        $this->assertNotNull(DB::table('ai_vision_interpretations')->where('interpretation_uuid', $uuid)->value('interpretation'));
        $this->assertSame([], $this->service()->forOwner($this->admin->id, 'course_activity', self::OWNER_ID));
        $audit = $this->lastVisionAudit();
        $this->assertSame('denied', $audit['decision']);
        $this->assertSame('detached', $audit['error_code']);
        // Not only the detach: with the usage forced back to active, the Media
        // tombstone alone still refuses the row.
        DB::table('media_file_usages')->where('media_file_id', $this->mediaId)->update(['status' => 'active']);
        $this->assertSame([], $this->service()->forOwner($this->admin->id, 'course_activity', self::OWNER_ID));
        $this->assertSame(['denied', 'missing'], [$this->lastVisionAudit()['decision'], $this->lastVisionAudit()['error_code']]);
        $this->detachUsage();

        $this->assertSame(0, Artisan::call('ai:vision-reconcile-media-deletion'));
        $this->assertStringContainsString('interpretations deleted: 1', Artisan::output());
        $this->assertSame('deleted', $this->interpretationStatus($uuid));
        $this->assertSame($context, TenantContext::customer());

        $this->assertSame(0, Artisan::call('ai:vision-reconcile-media-deletion'));
        $this->assertStringContainsString('interpretations deleted: 0', Artisan::output());
    }

    public function test_reconciliation_is_not_starved_by_media_files_that_still_exist(): void
    {
        $this->approve();
        $this->interpret();
        $run = $this->completedRun($this->customerId);
        $unit = $this->mediaUnit();
        $existing = [];
        foreach (range(1, 3) as $i) {
            $media = app(MediaService::class)->upload(UploadedFile::fake()->createWithContent("keep{$i}.txt", 'keep'), [
                'file_type' => 'document', 'module' => 'course', 'entity_type' => 'activities',
                'entity_id' => self::OWNER_ID, 'purpose' => 'document',
            ], $this->admin->id);
            $unit['media_file_id'] = $media->id;
            $unit['locator']['value'] = 'keep-'.$i;
            $existing[] = $this->insertInterpretation($this->customerId, $unit, $run);
        }
        // The deleted file sorts after the existing ones.
        $late = app(MediaService::class)->upload(UploadedFile::fake()->createWithContent('late.txt', 'late'), [
            'file_type' => 'document', 'module' => 'course', 'entity_type' => 'activities',
            'entity_id' => self::OWNER_ID, 'purpose' => 'document',
        ], $this->admin->id);
        $unit['media_file_id'] = $late->id;
        $unit['locator']['value'] = 'late';
        $lateUuid = $this->insertInterpretation($this->customerId, $unit, $run);
        Event::fakeFor(fn () => app(MediaService::class)->deleteMedia($late->id), [MediaFileDeleted::class]);

        $result = $this->service()->reconcileDeletedMedia(2);

        $this->assertSame(['media_files' => 1, 'deleted' => 1], $result);
        $this->assertSame('deleted', $this->interpretationStatus($lateUuid));
        foreach ($existing as $uuid) {
            $this->assertSame('ready', $this->interpretationStatus($uuid));
        }
    }

    public function test_media_announces_deletion_without_knowing_about_ai(): void
    {
        foreach (['Services/MediaService.php', 'Events/MediaFileDeleted.php'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/\\\\Ai|Ai[A-Z]|ai_vision/', file_get_contents(app_path($file)), $file);
        }
    }

    /** @return Closure():int how many listener jobs have started, counted outside the database */
    private function recordListenerJobs(): Closure
    {
        $count = 0;
        Queue::before(function (JobProcessing $event) use (&$count): void {
            if (($event->job->payload()['displayName'] ?? null) === PurgeVisionInterpretationsOfDeletedMedia::class) {
                $count++;
            }
        });

        return function () use (&$count): int {
            return $count;
        };
    }

    private function detachUsage(): void
    {
        DB::table('media_file_usages')->where('media_file_id', $this->mediaId)->update(['status' => 'detached']);
    }

    private function service(): AiVisionInterpretationService
    {
        return $this->app->make(AiVisionInterpretationService::class);
    }

    /** @return array<string,mixed> */
    private function interpret(?string $locator = null, bool $refresh = false): array
    {
        return $this->service()->interpret(
            $this->admin->id, 'course_activity', self::OWNER_ID, $this->page, $locator ?? $this->locator, 'vi', $refresh,
        );
    }

    /** The unit exactly as Media Read returns it for this page, without signing a crop. */
    private function mediaUnit(): array
    {
        $units = app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', self::OWNER_ID, 'document', 'region', 'vi', null, null, 'ai', [], $this->page,
        );

        foreach ($units as $unit) {
            if ($unit['locator']['value'] === $this->locator) {
                return $unit;
            }
        }

        $this->fail('Fixture region not returned by Media Read.');
    }

    private function approve(): void
    {
        $this->settings->approve($this->customerId, 'ai.external_processing.approved-provider.vision_interpretation', [
            'approved' => true,
            'data_classes' => ['media_image'],
            'execution_regions' => ['lf_managed'],
            'retention_classes' => ['none'],
        ]);
        $this->entitlements->grant($this->customerId, 'ai_vision_interpretation');
    }

    private function authorize(bool $allowed): void
    {
        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->andReturn($allowed);
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);
    }

    private function interpretationStatus(string $uuid): string
    {
        return (string) DB::table('ai_vision_interpretations')->where('interpretation_uuid', $uuid)->value('status');
    }

    /** @return array<string,mixed> */
    private function lastVisionAudit(): array
    {
        return DB::table('media_access_logs')->orderByDesc('id')->get()
            ->map(fn ($r) => json_decode($r->metadata, true))
            ->firstWhere('operation', 'vision_interpretation_retrieval');
    }

    /** @return array<string,string> Hash of every media_* table except the access audit. */
    private function mediaEvidenceSnapshot(): array
    {
        $tables = collect(Schema::getTables())->pluck('name')
            ->filter(fn (string $name): bool => str_starts_with($name, 'media_') && $name !== 'media_access_logs')
            ->sort()->values();

        return $tables->mapWithKeys(fn (string $table): array => [
            $table => hash('sha256', json_encode(DB::table($table)->orderBy(DB::raw('1'))->get())),
        ])->all();
    }

    private function completedRun(int $customerId): int
    {
        return (int) DB::table('ai_model_runs')->insertGetId([
            'customer_id' => $customerId, 'run_uuid' => (string) Str::uuid(),
            'prompt_hash' => 'sha256:fixture', 'purpose' => 'vision_interpretation',
            'provider' => 'approved-provider', 'model' => 'vision-model',
            'status' => 'completed', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $unit */
    private function insertInterpretation(int $customerId, array $unit, int $runId): string
    {
        $uuid = (string) Str::uuid();
        $bbox = $unit['structure']['bbox'];
        DB::table('ai_vision_interpretations')->insert([
            'customer_id' => $customerId, 'interpretation_uuid' => $uuid, 'model_run_id' => $runId,
            'source_type' => 'course_activity', 'source_id' => self::OWNER_ID, 'media_file_id' => $unit['media_file_id'],
            'usage_type' => 'document', 'content_type' => 'region', 'locale' => $unit['locale'],
            'source_fingerprint' => $unit['source_fingerprint'], 'processing_version' => $unit['processing_version'],
            'locator_type' => 'region', 'locator_start' => $unit['locator']['value'], 'page' => $this->page,
            'bbox_x' => $bbox['x'], 'bbox_y' => $bbox['y'], 'bbox_width' => $bbox['width'], 'bbox_height' => $bbox['height'],
            'interpretation' => 'Diễn giải cạnh tranh.', 'interpretation_hash' => hash('sha256', 'Diễn giải cạnh tranh.'),
            'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $uuid;
    }

    /** @return array{0:int,1:User} */
    private function tenant(string $slug): array
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = User::forceCreate([
            'customer_id' => $customerId, 'name' => 'Admin '.$slug, 'email' => uniqid($slug).'@example.test',
            'password' => Hash::make('password'), 'role' => 'customer_admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        TenantContext::set((object) ['id' => $customerId]);
        $this->actingAs($admin);

        return [$customerId, $admin];
    }

    /** @return array{0:int,1:int,2:string} media id, page, region locator */
    private function documentWithCroppedRegion(int $customerId, User $admin): array
    {
        $media = app(MediaService::class)->upload(UploadedFile::fake()->createWithContent('vision.txt', 'page text'), [
            'file_type' => 'document', 'module' => 'course', 'entity_type' => 'activities',
            'entity_id' => self::OWNER_ID, 'purpose' => 'document',
        ], $admin->id);
        DB::table('media_file_usages')->insert([
            'customer_id' => $customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => self::OWNER_ID, 'usage_type' => 'document',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($customerId, $media->id, 'vi', $admin->id);
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $customerId, $media->id, 'structured_extraction', ['locale' => 'vi', 'structure' => 'layout'], $admin->id,
        );

        $regions = DB::table('media_extracted_regions')->where('media_file_id', $media->id)->orderBy('id')->get();
        // Crop is all-or-nothing within a revision, and crop_storage_key is
        // unique per tenant — so every region gets its own crop.
        foreach ($regions as $candidate) {
            DB::table('media_extracted_regions')->where('id', $candidate->id)->update([
                'bbox_x' => 0.1, 'bbox_y' => 0.1, 'bbox_width' => 0.2, 'bbox_height' => 0.2,
                'crop_storage_key' => 'tenants/'.$customerId.'/media/'.$media->id.'/regions/fp/v1/vi/'.$candidate->id.'.png',
                'crop_mime_type' => 'image/png', 'crop_width' => 320, 'crop_height' => 200, 'crop_bytes' => 45907,
            ]);
        }
        $region = $regions->first();

        return [(int) $media->id, (int) $region->page, (string) $region->locator_value];
    }
}
