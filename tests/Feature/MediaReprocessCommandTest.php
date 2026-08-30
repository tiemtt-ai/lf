<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaService;
use App\Services\VideoSpeechToTextProfile;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaReprocessCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Cac test nay dung fixture VIDEO de kiem co che STT, nen phai bat
        // gate mot cach tuong minh. Gate mac dinh TAT theo Temporary Safety
        // Rule cua DOC-CONFLICT-0027 — xem test_video_stt_is_off_by_default.
        config([
            'media.processing.speech_to_text.video_enabled' => true,
            'media.processing.video_audio.ffmpeg_version' => '7.1.1',
        ]);
        Storage::fake('media_local');
        config(['media.disk' => 'media_local', 'media.bucket' => 'test-media']);
        $this->customerId = $this->createCustomer('tenant-a');
        $this->admin = User::forceCreate([
            'customer_id' => $this->customerId, 'name' => 'Admin', 'email' => uniqid().'@example.test',
            'password' => Hash::make('password'), 'role' => 'customer_admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        TenantContext::set((object) ['id' => $this->customerId]);
        $this->actingAs($this->admin);
    }

    public function test_media_target_opens_a_fresh_chain_when_the_configured_version_moves(): void
    {
        $media = $this->uploadVideo();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        $this->assertSame(1, $this->chainCount($media->id, 'speech_to_text'));

        config(['media.processing.versions.speech_to_text' => 'fake-v2']);

        $this->artisan('media:reprocess', [
            '--customer' => $this->customerId,
            '--media' => $media->id,
            '--actor' => $this->admin->id,
        ])->expectsOutputToContain('Enqueued 1 chain(s)')->assertExitCode(0);

        $this->assertSame(2, $this->chainCount($media->id, 'speech_to_text'));
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'speech_to_text',
            'processing_version' => $this->videoVersion('fake-v2'), 'attempt' => 1, 'created_by' => $this->admin->id,
        ]);
        // Caption khong nam trong initial/reprocess set, nhung transcript vua
        // chuyen `ready` nen trigger post-STT da materialize dung MOT chain.
        // Reprocess mo revision moi => caption cua revision cu va moi la hai chain.
        $this->assertSame(2, $this->chainCount($media->id, 'caption'),
            'Moi transcript revision `ready` phai co dung mot caption chain tuong ung.');
    }

    public function test_dry_run_reports_the_plan_without_writing(): void
    {
        $media = $this->uploadVideo();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        config(['media.processing.versions.speech_to_text' => 'fake-v2']);
        $before = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->count();

        $this->artisan('media:reprocess', [
            '--customer' => $this->customerId,
            '--media' => $media->id,
            '--dry-run' => true,
        ])->expectsOutputToContain('Dry run: 1 chain(s) would be created')->assertExitCode(0);

        $this->assertSame($before, DB::table('media_processing_jobs')->where('media_file_id', $media->id)->count());
    }

    public function test_existing_chains_are_not_duplicated(): void
    {
        $media = $this->uploadVideo();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        $before = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->count();

        $this->artisan('media:reprocess', [
            '--customer' => $this->customerId,
            '--media' => $media->id,
        ])->expectsOutputToContain('nothing to enqueue')->assertExitCode(0);

        $this->assertSame($before, DB::table('media_processing_jobs')->where('media_file_id', $media->id)->count());
    }

    public function test_job_target_continues_a_chain_whose_provider_still_matches_configuration(): void
    {
        $job = $this->failedSpeechToTextJob();

        $this->artisan('media:reprocess', [
            '--customer' => $this->customerId,
            '--job' => $job->id,
            '--actor' => $this->admin->id,
        ])->expectsOutputToContain('as attempt 2')->assertExitCode(0);

        $this->assertDatabaseHas('media_processing_jobs', [
            'supersedes_job_id' => $job->id, 'attempt' => 2, 'processing_version' => $this->videoVersion('unconfigured-v1'),
        ]);
    }

    public function test_job_target_refuses_to_replay_a_chain_recorded_against_a_superseded_provider(): void
    {
        $job = $this->failedSpeechToTextJob();
        config(['media.processing.providers.speech_to_text' => 'fake', 'media.processing.versions.speech_to_text' => 'fake-v1']);

        $this->artisan('media:reprocess', [
            '--customer' => $this->customerId,
            '--job' => $job->id,
        ])->expectsOutputToContain('would call the old provider again')->assertExitCode(1);

        $this->assertDatabaseMissing('media_processing_jobs', ['supersedes_job_id' => $job->id]);
    }

    public function test_permanent_error_codes_are_refused(): void
    {
        $job = $this->failedSpeechToTextJob();
        DB::table('media_processing_jobs')->where('id', $job->id)->update(['error_code' => 'corrupt_source']);

        $this->artisan('media:reprocess', [
            '--customer' => $this->customerId,
            '--job' => $job->id,
        ])->expectsOutputToContain('which is permanent')->assertExitCode(1);

        $this->assertDatabaseMissing('media_processing_jobs', ['supersedes_job_id' => $job->id]);
    }

    public function test_targets_of_another_tenant_are_invisible(): void
    {
        $media = $this->uploadVideo();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        $other = $this->createCustomer('tenant-b');

        $this->artisan('media:reprocess', [
            '--customer' => $other,
            '--media' => $media->id,
        ])->expectsOutputToContain('does not exist for customer')->assertExitCode(1);
    }

    public function test_exactly_one_target_is_required(): void
    {
        $this->artisan('media:reprocess', ['--customer' => $this->customerId])
            ->expectsOutputToContain('Select exactly one target')->assertExitCode(1);
    }

    private function failedSpeechToTextJob(): object
    {
        config(['media.processing.providers.speech_to_text' => 'unconfigured', 'media.processing.versions.speech_to_text' => 'unconfigured-v1']);
        $media = $this->uploadVideo();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);

        $job = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->first();
        $this->assertSame('failed', $job->status);
        $this->assertSame('provider_unavailable', $job->error_code);

        return $job;
    }

    private function chainCount(int $mediaFileId, string $jobType): int
    {
        return DB::table('media_processing_jobs')->where('media_file_id', $mediaFileId)
            ->where('job_type', $jobType)->distinct()->count('processing_version');
    }

    private function createCustomer(string $slug): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => ucfirst($slug), 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Fixture o day la video, va `processing_version` cua video STT gom ca
     * canonical ffmpeg extraction profile (Amendment Record 2.19 § 1).
     */
    private function videoVersion(string $base): string
    {
        return $base.'+'.app(VideoSpeechToTextProfile::class)->label();
    }

    private function uploadVideo(): object
    {
        return app(MediaService::class)->upload(UploadedFile::fake()->create('lesson-'.uniqid().'.mp4', 32, 'video/mp4'), [
            'file_type' => 'video', 'module' => 'course', 'entity_type' => 'activities', 'entity_id' => 99, 'purpose' => 'video',
        ], $this->admin->id);
    }
}
