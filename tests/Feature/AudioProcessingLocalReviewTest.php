<?php

namespace Tests\Feature;

use App\Exceptions\MediaReadException;
use App\Jobs\ProcessMediaProcessingJob;
use App\Models\User;
use App\Services\FakeMediaProcessingProvider;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaReadService;
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Audio Processing local closure — Course Activity → media_file_usages →
 * speech_to_text → media_transcripts theo timespan → Media Read.
 *
 * Cac test "real" chay ENGINE THAT (`faster_whisper_local`) tren mot fixture
 * giong noi TONG HOP: khong PII, khong goi provider ngoai, khong mock provider.
 * Fixture duoc dung tai cho bang `say` + `ffmpeg` nen khong co binary audio nao
 * bi commit — dung luat fixture cua § Acceptance trong Processing Contract.
 */
class AudioProcessingLocalReviewTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;

    private User $admin;

    private int $activityId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media_local');
        config([
            'media.disk' => 'media_local', 'media.bucket' => 'test-media',
            'queue.default' => 'sync',
            'media.processing.providers.virus_scan' => 'fake',
            'media.processing.providers.speech_to_text' => 'faster_whisper_local',
            'media.processing.versions.speech_to_text' => 'faster-whisper-small-local-review-v1',
            // Video STT khong thuoc pham vi review nay va phai giu mac dinh TAT.
            'media.processing.speech_to_text.video_enabled' => false,
        ]);
        $this->customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Audio Review', 'slug' => 'audio-review', 'subdomain' => 'audio-review',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::forceCreate([
            'customer_id' => $this->customerId, 'name' => 'Fixture Admin', 'email' => 'audio@example.test',
            'password' => bcrypt('fixture'), 'role' => 'customer_admin', 'status' => 'active',
        ]);
        TenantContext::set((object) ['id' => $this->customerId]);
        $this->actingAs($this->admin);
        [$template, $lesson] = $this->courseFixture($this->customerId, $this->admin->id);
        $this->activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $template,
            'template_lesson_id' => $lesson, 'title' => 'Audio fixture',
            'activity_type' => 'audio', 'sort_order' => 1, 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * E2E day du: upload → active Course usage → dispatch sau commit → engine
     * that → nhieu media_transcripts `ready` theo timespan → authorized read.
     */
    public function test_real_audio_activity_usage_after_commit_dispatch_and_authorized_read(): void
    {
        $media = $this->uploadAudio($this->requireRealFixture());
        $this->attach($media, 'en');

        $job = $this->sttJob($media);
        $this->assertSame('ready', $job->status, (string) $job->error_code);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->completed_at);
        $this->assertSame('transcript', $job->output_type);
        $this->assertSame(1, (int) $job->attempt);
        $this->assertSame(1, (int) $job->dispatch_generation);
        $this->assertSame('diarization=off;locale=en', $job->output_profile);
        $this->assertSame(hash('sha256', $media->checksum.':audio'), $job->source_fingerprint);
        // § 5 Do luong: don vi chuan cua speech-to-text la `second`.
        $this->assertSame('second', $job->billable_unit_type);
        $this->assertGreaterThan(0.0, (float) $job->billable_units);

        $rows = DB::table('media_transcripts')->where('processing_job_id', $job->id)->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $rows->count(), 'Mot row phai la mot doan, khong phai ca file.');
        $previousEnd = null;
        foreach ($rows as $row) {
            $this->assertSame('ready', $row->status);
            $this->assertSame('timespan', $row->locator_type);
            $this->assertSame('en', $row->locale);
            $this->assertSame($job->source_fingerprint, $row->source_fingerprint);
            $this->assertSame($job->processing_version, $row->processing_version);
            $this->assertSame((int) $job->id, (int) $row->processing_job_id);
            $this->assertNotSame('', trim((string) $row->text));
            // Transcript text chi nam trong `text`, khong nhet vao metadata.
            $this->assertStringNotContainsString(trim((string) $row->text), (string) $row->metadata);
            $this->assertMatchesRegularExpression('/^(0|[1-9][0-9]*)-(0|[1-9][0-9]*)$/', $row->locator_value);
            [$start, $end] = array_map('intval', explode('-', $row->locator_value));
            $this->assertLessThan($end, $start, 'Segment do dai 0 khong hop le.');
            if ($previousEnd !== null) {
                $this->assertGreaterThanOrEqual($previousEnd, $start, 'Timespan la nua mo, khong chong lan.');
            }
            $previousEnd = $end;
        }

        // Media Read that su: Course authorizer that, owner context, khong mock.
        $units = $this->read('en');
        $this->assertSame($rows->pluck('locator_value')->all(), array_column(array_column($units, 'locator'), 'value'));
        $this->assertStringContainsString('learning', strtolower(implode(' ', array_column($units, 'text'))));
        foreach ($units as $unit) {
            $this->assertSame('transcript', $unit['content_type']);
            $this->assertSame('timespan', $unit['locator']['type']);
            $this->assertSame('en', $unit['locale']);
            $this->assertSame($job->source_fingerprint, $unit['source_fingerprint']);
            $this->assertSame($job->processing_version, $unit['processing_version']);
        }

        $audit = DB::table('media_access_logs')->where('media_file_id', $media->id)
            ->where('action', 'read_derived')->orderByDesc('id')->firstOrFail();
        $metadata = json_decode((string) $audit->metadata, true);
        $this->assertSame('allowed', $metadata['decision']);
        $this->assertSame('audio', $metadata['usage_type']);
        $this->assertSame('transcript', $metadata['content_type']);
        foreach ($units as $unit) {
            $this->assertStringNotContainsString(trim($unit['text']), (string) $audit->metadata);
        }

        // Binary van phuc vu duoc; derived output khong phai deliverability gate.
        $this->assertSame('ready', DB::table('media_files')->where('id', $media->id)->value('status'));
    }

    /** Queue co the giao mot message hai lan; chi duoc mot lan goi provider. */
    public function test_real_duplicate_dispatch_creates_one_job_and_one_transcript_revision(): void
    {
        $media = $this->uploadAudio($this->requireRealFixture());
        $this->attach($media, 'en');
        $job = $this->sttJob($media);
        $before = DB::table('media_transcripts')->where('media_file_id', $media->id)->count();

        // Cung attach lai voi cung locale, roi giao lai chinh envelope cu.
        $this->attach($media, 'en');
        ProcessMediaProcessingJob::dispatchSync($this->customerId, (int) $job->id);

        $this->assertSame(1, DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->count());
        $this->assertSame($before, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
        $this->assertSame('ready', $this->sttJob($media)->status);
    }

    /**
     * § 5: `billable_units` phai duoc ghi "ke ca khi failed sau khi provider da
     * tinh phi". Engine that chay xong roi revision moi bi tu choi — chi phi da
     * phat sinh va phai con dau vet.
     */
    public function test_real_failure_after_the_engine_ran_still_records_the_billable_seconds(): void
    {
        $media = $this->uploadAudio($this->requireRealFixture());
        // Output that vuot tran => `transcript_invalid`, sau khi engine da chay.
        config(['media.processing.speech_to_text.max_output_bytes' => 1]);
        $this->attach($media, 'en');

        $job = $this->sttJob($media);
        $this->assertSame('failed', $job->status);
        $this->assertSame('transcript_invalid', $job->error_code);
        $this->assertSame('second', $job->billable_unit_type);
        $this->assertGreaterThan(0.0, (float) $job->billable_units);
        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
    }

    #[DataProvider('preflightRejections')]
    public function test_preflight_rejection_fails_without_transcript_or_cost(array $mutation, string $locale, string $error): void
    {
        $media = $this->uploadAudio($this->requireRealFixture());
        if ($mutation !== []) {
            DB::table('media_files')->where('id', $media->id)->update($mutation);
        }
        $this->attach($media, $locale);

        $job = $this->sttJob($media);
        $this->assertSame('failed', $job->status);
        $this->assertSame($error, $job->error_code);
        // error_message khong duoc mang message/stderr cua engine.
        $this->assertSame($error, $job->error_message);
        // Preflight tu choi truoc khi engine chay: khong co chi phi de ghi.
        $this->assertNull($job->billable_units);
        $this->assertNull($job->billable_unit_type);
        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
        $this->assertSame('ready', DB::table('media_files')->where('id', $media->id)->value('status'));
        $this->assertReadError('failed', $locale);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function preflightRejections(): array
    {
        return [
            'mime ngoai allowlist' => [['mime_type' => 'audio/flac'], 'en', 'unsupported_source'],
            'khong do duoc thoi luong' => [['duration_seconds' => null], 'en', 'corrupt_source'],
            'vuot tran thoi luong' => [['duration_seconds' => 7201], 'en', 'audio_limit_exceeded'],
            'vuot tran dung luong' => [['file_size_bytes' => 1073741825], 'en', 'audio_limit_exceeded'],
        ];
    }

    /**
     * `MediaOutputProfile::canonicalLocale()` chi validate cu phap BCP 47.
     * Allowlist Phase 1 la `vi|ko|en`, va `fr` phai dung o provider.
     */
    public function test_locale_outside_the_phase_one_allowlist_never_produces_a_transcript(): void
    {
        $media = $this->uploadAudio($this->requireRealFixture());
        $this->attach($media, 'fr');

        $job = $this->sttJob($media);
        $this->assertSame('failed', $job->status);
        $this->assertSame('locale_unavailable', $job->error_code);
        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
        $this->assertSame('fr', DB::table('media_files')->where('id', $media->id)->value('processing_locale'));
    }

    /** Locale khong khai bao thi khong enqueue job phu thuoc locale. */
    public function test_missing_locale_fails_closed_without_a_speech_job(): void
    {
        $media = $this->uploadAudio($this->requireRealFixture());
        app(MediaService::class)->attachUsage($media->id, 'course_activity', $this->activityId, 'audio',
            ['processing_locale' => null, 'speech_to_text' => true]);

        $this->assertDatabaseMissing('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'speech_to_text',
        ]);
        $this->assertDatabaseHas('media_files', [
            'id' => $media->id, 'status' => 'failed',
            'processing_error_code' => 'required_profile_configuration_missing',
        ]);
    }

    #[DataProvider('invalidTimings')]
    public function test_invalid_audio_timing_fails_the_whole_revision(array $units): void
    {
        config(['media.processing.providers.speech_to_text' => 'fake']);
        $media = $this->uploadAudio();
        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andReturn(['units' => $units]);
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);
        $this->attach($media, 'vi');

        $job = $this->sttJob($media);
        $this->assertSame('failed', $job->status);
        $this->assertSame('transcript_invalid', $job->error_code);
        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
        // Loi vinh vien: khong duoc retry.
        $this->expectException(\InvalidArgumentException::class);
        app(MediaProcessingOrchestrator::class)->retry($this->customerId, (int) $job->id, $this->admin->id);
    }

    /** @return array<string, array{array<int, array<string, string>>}> */
    public static function invalidTimings(): array
    {
        $unit = static fn (string $locator, string $text = 'doan'): array => [
            'locator_type' => 'timespan', 'locator_value' => $locator, 'text' => $text,
        ];

        return [
            'do dai 0' => [[$unit('1000-1000')]],
            'chong lan' => [[$unit('0-1000'), $unit('500-1500')]],
            'giam dan' => [[$unit('2000-3000'), $unit('0-1000')]],
            'locator sai dinh dang' => [[$unit('0:00-0:01')]],
            'text rong' => [[$unit('0-1000', '   ')]],
            'vuot thoi luong audio' => [[$unit('0-3001')]],
        ];
    }

    /** Segment giap ranh la hop le theo khoang nua mo `[start, end)`. */
    public function test_abutting_segments_are_valid_and_read_in_temporal_order(): void
    {
        config(['media.processing.providers.speech_to_text' => 'fake']);
        $media = $this->uploadAudio();
        DB::table('media_files')->where('id', $media->id)->update(['duration_seconds' => 15]);
        $media->duration_seconds = 15;
        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andReturn(['units' => [
            ['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => 'mot'],
            ['locator_type' => 'timespan', 'locator_value' => '1000-2000', 'text' => 'hai'],
            // Thu tu doc KHONG duoc phu thuoc vao sap xep chuoi cua locator.
            ['locator_type' => 'timespan', 'locator_value' => '2000-15000', 'text' => 'ba'],
        ]]);
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);
        $this->attach($media, 'vi');

        $this->assertSame('ready', $this->sttJob($media)->status);
        $this->assertSame(['0-1000', '1000-2000', '2000-15000'],
            array_column(array_column($this->read('vi'), 'locator'), 'value'));
    }

    /** Revision moi archive ban cu; mac dinh chi tra ban hien hanh. */
    public function test_a_new_processing_version_archives_the_previous_audio_revision(): void
    {
        config(['media.processing.providers.speech_to_text' => 'fake']);
        $media = $this->uploadAudio();
        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldReceive('process')->twice()->andReturn(
            ['units' => [['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => 'ban cu']]],
            ['units' => [['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => 'ban moi']]],
        );
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);
        $this->attach($media, 'vi');
        $first = $this->sttJob($media);

        config(['media.processing.versions.speech_to_text' => 'faster-whisper-small-local-review-v2']);
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, (int) $media->id, 'speech_to_text', ['locale' => 'vi', 'diarization' => 'off'], $this->admin->id
        );

        $this->assertDatabaseHas('media_transcripts', [
            'processing_job_id' => $first->id, 'status' => 'archived', 'text' => 'ban cu',
        ]);
        // Job terminal cu khong bi hoi sinh hay sua.
        $this->assertSame('ready', $this->refreshJob($first->id)->status);

        $current = $this->read('vi');
        $this->assertCount(1, $current);
        $this->assertSame('ban moi', $current[0]['text']);
        $this->assertSame('faster-whisper-small-local-review-v2', $current[0]['processing_version']);

        // Ban archived van doc duoc khi neu dich danh processing_version.
        $archived = app(MediaReadService::class)->read($this->admin->id, 'course_activity', $this->activityId,
            'audio', 'transcript', 'vi', 'faster-whisper-small-local-review-v1');
        $this->assertSame('ban cu', $archived[0]['text']);
        $this->assertSame('archived', $archived[0]['status']);

        // Fingerprint lech thi la loi, khong phai bo qua selector.
        try {
            app(MediaReadService::class)->read($this->admin->id, 'course_activity', $this->activityId,
                'audio', 'transcript', 'vi', 'faster-whisper-small-local-review-v1', str_repeat('0', 64));
            $this->fail('Stale fingerprint phai bi tu choi.');
        } catch (MediaReadException $error) {
            $this->assertSame('revision_mismatch', $error->errorCode);
        }
        $this->assertReadError('revision_unavailable', 'vi', 'khong-ton-tai-v9');
    }

    /** Tenant khac khong doc duoc transcript, ke ca khi biet owner id. */
    public function test_another_tenant_cannot_read_the_audio_transcript(): void
    {
        config(['media.processing.providers.speech_to_text' => 'fake']);
        $media = $this->uploadAudio();
        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andReturn(['units' => [
            ['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => 'noi dung rieng cua tenant A'],
        ]]);
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);
        $this->attach($media, 'vi');
        $this->assertCount(1, $this->read('vi'));

        $otherCustomerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Audio Review B', 'slug' => 'audio-review-b', 'subdomain' => 'audio-review-b',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $intruder = User::forceCreate([
            'customer_id' => $otherCustomerId, 'name' => 'Intruder', 'email' => 'intruder@example.test',
            'password' => bcrypt('fixture'), 'role' => 'customer_admin', 'status' => 'active',
        ]);
        TenantContext::set((object) ['id' => $otherCustomerId]);

        try {
            app(MediaReadService::class)->read($intruder->id, 'course_activity', $this->activityId,
                'audio', 'transcript', 'vi');
            $this->fail('Cross-tenant read phai bi tu choi.');
        } catch (MediaReadException $error) {
            $this->assertSame('unauthorized', $error->errorCode);
        }
        // Actor cua tenant A cung khong duoc muon context cua tenant B.
        try {
            app(MediaReadService::class)->read($this->admin->id, 'course_activity', $this->activityId,
                'audio', 'transcript', 'vi');
            $this->fail('Owner cua tenant khac phai bi tu choi.');
        } catch (MediaReadException $error) {
            $this->assertSame('unauthorized', $error->errorCode);
        }
        $this->assertSame(0, DB::table('media_access_logs')->where('customer_id', $otherCustomerId)->count());
    }

    /** Chua ready, detach va deleted deu tra ma on dinh, khong lo noi dung. */
    public function test_unready_detached_and_deleted_reads_return_stable_errors(): void
    {
        config(['media.processing.providers.speech_to_text' => 'fake']);
        Queue::fake(); // Giu job o `pending`: doc truoc khi co output.
        $media = $this->uploadAudio();
        $this->attach($media, 'vi');
        // Spec B § 6: chua co output thi ma loi la `pending`, khong phai
        // `locale_unavailable` — hai thu doi hoi hai hanh dong khac han nhau.
        $this->assertReadError('pending', 'vi');

        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andReturn(['units' => [
            ['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => 'noi dung rieng'],
        ]]);
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);
        (new ProcessMediaProcessingJob($this->customerId, (int) $this->sttJob($media)->id))->handle();
        $this->assertCount(1, $this->read('vi'));

        app(MediaService::class)->detachUsage($media->id, 'course_activity', $this->activityId, 'audio');
        $this->assertReadError('detached', 'vi');

        $denied = DB::table('media_access_logs')->where('media_file_id', $media->id)
            ->where('action', 'read_derived')->orderByDesc('id')->firstOrFail();
        $metadata = json_decode((string) $denied->metadata, true);
        $this->assertSame('denied', $metadata['decision']);
        $this->assertSame('detached', $metadata['error_code']);
        $this->assertStringNotContainsString('noi dung rieng', (string) $denied->metadata);
        // Detach khong xoa output; no chi ngung phuc vu qua owner nay.
        $this->assertSame(1, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());

        // Xoa Media purge transcript trong cung transaction ghi tombstone.
        app(MediaService::class)->deleteMedia((int) $media->id);
        $this->assertSame('deleted', DB::table('media_files')->where('id', $media->id)->value('status'));
        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
        $this->assertReadError('detached', 'vi');
        // Provenance duoc giu lai: job va access log khong bi xoa theo.
        $this->assertSame(1, DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->count());
    }

    /**
     * Retry sau provider failure: row moi, giu history, cung generation va
     * correlation, va chi chay khi Audio con active usage.
     */
    public function test_retry_after_provider_failure_keeps_history_and_requires_active_usage(): void
    {
        config(['media.processing.providers.speech_to_text' => 'fake']);
        $media = $this->uploadAudio();
        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andThrow(new \RuntimeException('provider_unavailable'));
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);
        $this->attach($media, 'vi');
        $failed = $this->sttJob($media);
        $this->assertSame('failed', $failed->status);
        $this->assertSame('provider_unavailable', $failed->error_code);

        // Detach truoc: retry khong duoc chay khi Audio khong con active usage.
        app(MediaService::class)->detachUsage($media->id, 'course_activity', $this->activityId, 'audio');
        try {
            app(MediaProcessingOrchestrator::class)->retry($this->customerId, (int) $failed->id, $this->admin->id);
            $this->fail('Retry phai yeu cau active Audio usage.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('Active Audio Course usage is required.', $error->getMessage());
        }

        DB::table('media_file_usages')->where('media_file_id', $media->id)->where('usage_type', 'audio')
            ->update(['status' => 'active', 'updated_at' => now()]);
        $second = Mockery::mock(FakeMediaProcessingProvider::class);
        $second->shouldReceive('process')->once()->andReturn(['units' => [
            ['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => 'lan hai'],
        ]]);
        $this->app->instance(FakeMediaProcessingProvider::class, $second);
        $retry = app(MediaProcessingOrchestrator::class)->retry($this->customerId, (int) $failed->id, $this->admin->id);

        $this->assertSame(2, (int) $retry->attempt);
        $this->assertSame((int) $failed->id, (int) $retry->supersedes_job_id);
        $this->assertSame($failed->correlation_id, $retry->correlation_id);
        $this->assertSame((int) $failed->dispatch_generation, (int) $retry->dispatch_generation);
        // Row cu giu nguyen lam dau vet chi phi.
        $this->assertSame('failed', $this->refreshJob($failed->id)->status);
        $this->assertSame(2, DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->count());
    }

    /**
     * Contract § Concurrency: toi da MOT job `processing` cho moi
     * (media_file_id, job_type). Envelope thu hai khong duoc goi provider va
     * khong duoc claim; no cung khong bi danh dau terminal.
     */
    public function test_a_second_envelope_never_claims_while_another_stt_job_is_processing(): void
    {
        config(['media.processing.providers.speech_to_text' => 'fake']);
        Queue::fake();
        $media = $this->uploadAudio();
        $this->attach($media, 'vi');
        $busy = $this->sttJob($media);
        DB::table('media_processing_jobs')->where('id', $busy->id)
            ->update(['status' => 'processing', 'started_at' => now()]);
        $second = DB::table('media_processing_jobs')->insertGetId([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'job_type' => 'speech_to_text',
            'status' => 'pending', 'attempt' => 2, 'dispatch_generation' => 1,
            'supersedes_job_id' => $busy->id,
            'idempotency_key' => 'audio-concurrency-probe', 'correlation_id' => $busy->correlation_id,
            'source_fingerprint' => $busy->source_fingerprint, 'processing_version' => $busy->processing_version,
            'output_profile' => $busy->output_profile, 'output_profile_hash' => $busy->output_profile_hash,
            'provider' => 'fake', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldNotReceive('process');
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);
        (new ProcessMediaProcessingJob($this->customerId, $second))->handle();

        $this->assertSame('pending', $this->refreshJob($second)->status);
        $this->assertNull($this->refreshJob($second)->started_at);
        $this->assertSame('processing', $this->refreshJob((int) $busy->id)->status);
        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
    }

    /**
     * Recovery chi cham job Audio QUA HAN. Job vua tao khong duoc lay nham, va
     * khong lan nao tao transcript trung.
     */
    public function test_recovery_touches_only_expired_audio_jobs_and_is_registered_on_the_scheduler(): void
    {
        config(['media.processing.providers.speech_to_text' => 'fake']);
        Queue::fake();
        $media = $this->uploadAudio();
        $this->attach($media, 'vi');
        $fresh = $this->sttJob($media);

        // Job vua tao: recovery khong duoc cham toi. `attach` da day mot envelope,
        // nen phep do la "khong co envelope NAO THEM", khong phai "khong co gi".
        $pushedAfterAttach = Queue::pushed(ProcessMediaProcessingJob::class)->count();
        $this->artisan('media:recover-audio-processing --customer='.$this->customerId)->assertSuccessful();
        $this->assertSame($pushedAfterAttach, Queue::pushed(ProcessMediaProcessingJob::class)->count());
        $this->assertSame('pending', $this->refreshJob((int) $fresh->id)->status);

        // Pending qua han: redeliver dung envelope cu, khong tao job moi.
        DB::table('media_processing_jobs')->where('id', $fresh->id)->update(['updated_at' => now()->subHours(2)]);
        $this->artisan('media:recover-audio-processing --customer='.$this->customerId)->assertSuccessful();
        $this->assertSame($pushedAfterAttach + 1, Queue::pushed(ProcessMediaProcessingJob::class)->count());
        Queue::assertPushed(ProcessMediaProcessingJob::class,
            fn ($job) => $job->processingJobId === (int) $fresh->id);
        $this->assertSame(1, DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->count());

        // Processing qua worker timeout: fail co ten, khong treo va khong cancelled.
        DB::table('media_processing_jobs')->where('id', $fresh->id)->update([
            'status' => 'processing', 'started_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
        ]);
        $this->artisan('media:recover-audio-processing --customer='.$this->customerId)->assertSuccessful();
        $recovered = $this->refreshJob((int) $fresh->id);
        $this->assertSame('failed', $recovered->status);
        $this->assertSame('provider_timeout', $recovered->error_code);
        $this->assertSame('provider_timeout', $recovered->error_message);
        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());

        // Mot command khong duoc dang ky vao scheduler la mot co che khong chay.
        $this->assertTrue(
            collect(app(Schedule::class)->events())->contains(
                fn ($event): bool => str_contains((string) $event->command, 'media:recover-audio-processing')
            ),
            'media:recover-audio-processing phai duoc dang ky trong scheduler.'
        );
    }

    private function requireRealFixture(): string
    {
        $configured = getenv('LF_REAL_AUDIO_FIXTURE');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }
        foreach (['python_binary', 'script'] as $key) {
            $path = (string) config('media.processing.speech_to_text.'.$key);
            if (! file_exists($path)) {
                $this->markTestSkipped('Faster Whisper runtime missing: '.$key);
            }
        }
        if (! is_dir((string) config('media.processing.speech_to_text.model_path'))) {
            $this->markTestSkipped('Faster Whisper model missing.');
        }

        return $this->synthesizeFixture();
    }

    /**
     * Fixture giong noi tong hop, khong PII, dung tai cho bang `say` + `ffmpeg`.
     * Cache theo tien trinh de nhieu test khong tong hop lai.
     */
    private function synthesizeFixture(): string
    {
        $target = sys_get_temp_dir().'/lf-audio-review-fixture.wav';
        if (is_file($target) && filesize($target) > 0) {
            return $target;
        }
        $finder = new ExecutableFinder;
        $say = $finder->find('say');
        $ffmpeg = $finder->find('ffmpeg');
        if ($say === null || $ffmpeg === null) {
            $this->markTestSkipped('Set LF_REAL_AUDIO_FIXTURE, or install `say` and `ffmpeg` to synthesize one.');
        }
        $aiff = sys_get_temp_dir().'/lf-audio-review-fixture.aiff';
        $script = 'Welcome to this lesson on learning design. [[slnc 800]] '
            .'In this module we study how learners build durable knowledge. [[slnc 800]] '
            .'The first principle is spaced repetition over many days. [[slnc 800]] '
            .'The second principle is retrieval practice before review. [[slnc 800]] '
            .'Together these two ideas improve long term memory.';
        (new Process([$say, '-o', $aiff, $script]))->setTimeout(120)->mustRun();
        (new Process([$ffmpeg, '-y', '-i', $aiff, '-ar', '16000', '-ac', '1', '-c:a', 'pcm_s16le', $target,
            '-loglevel', 'error']))->setTimeout(120)->mustRun();
        @unlink($aiff);

        return $target;
    }

    private function uploadAudio(?string $fixture = null): object
    {
        // Khong co fixture that thi dung mot WAV toi thieu hop le: cac test dung
        // fake provider chi can Media audio dung MIME/thoi luong.
        $file = $fixture === null
            ? UploadedFile::fake()->createWithContent('lesson.wav', $this->silentWav())
            : new UploadedFile($fixture, basename($fixture), 'audio/x-wav', null, true);

        $media = app(MediaService::class)->upload($file, [
            'file_type' => 'audio', 'module' => 'course', 'entity_type' => 'activities',
            'entity_id' => $this->activityId, 'purpose' => 'audio',
        ], $this->admin->id);

        if ($fixture === null) {
            DB::table('media_files')->where('id', $media->id)->update(['duration_seconds' => 3]);
            $media->duration_seconds = 3;
        }

        return $media;
    }

    /** WAV PCM 16 kHz mono 1 giay, dung header that de MIME detection dung. */
    private function silentWav(): string
    {
        $samples = 16000;
        $data = str_repeat("\x00\x00", $samples);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('V', 16)
            .pack('vvVVvv', 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($data)).$data;
    }

    private function attach(object $media, string $locale): void
    {
        app(MediaService::class)->attachUsage($media->id, 'course_activity', $this->activityId, 'audio', [
            'processing_locale' => $locale, 'speech_to_text' => true,
        ]);
    }

    private function sttJob(object $media): object
    {
        return DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->orderByDesc('id')->firstOrFail();
    }

    private function refreshJob(int $jobId): object
    {
        return DB::table('media_processing_jobs')->where('id', $jobId)->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    private function read(string $locale): array
    {
        return app(MediaReadService::class)->read($this->admin->id, 'course_activity', $this->activityId,
            'audio', 'transcript', $locale);
    }

    private function assertReadError(string $expected, string $locale, ?string $processingVersion = null): void
    {
        try {
            app(MediaReadService::class)->read($this->admin->id, 'course_activity', $this->activityId,
                'audio', 'transcript', $locale, $processingVersion);
            $this->fail('Read must fail with '.$expected);
        } catch (MediaReadException $error) {
            $this->assertSame($expected, $error->errorCode);
        }
    }

    /** @return array{int, int} */
    private function courseFixture(int $customerId, int $actorId): array
    {
        $now = now();
        $categoryId = DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId, 'parent_id' => null, 'name' => 'General',
            'slug' => 'general', 'description' => null, 'thumbnail_image' => null, 'banner_image' => null,
            'sort_order' => 1, 'is_featured' => false, 'meta_title' => null, 'meta_description' => null,
            'meta_keywords' => null, 'status' => 'active', 'created_by' => $actorId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId, 'category_id' => $categoryId, 'title' => 'Template',
            'short_description' => 'Short', 'description' => 'Detail', 'publisher_name' => 'LearnForge',
            'intro_video_source' => null, 'intro_image_media_file_id' => null, 'intro_video_media_file_id' => null,
            'difficulty_level' => 'beginner', 'estimated_minutes_per_lesson' => 90, 'estimated_lesson_count' => null,
            'lesson_count' => 1, 'meta_title' => 'T', 'meta_description' => 'D', 'meta_keywords' => 'k',
            'working_revision' => 1, 'status' => 'active', 'created_by' => $actorId,
            'last_version_published_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $lessonId = DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId, 'template_section_id' => null,
            'title' => 'Lesson', 'short_description' => 'S', 'description' => 'D', 'sort_order' => 0,
            'is_preview' => true, 'duration_seconds' => 0, 'activity_count' => 0, 'unlock_rule' => 'none',
            'unlock_after_lesson_id' => null, 'unlock_at' => null, 'created_by' => $actorId,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$templateId, $lessonId];
    }
}
