<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CourseMediaOwnerContextAuthorizer;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaReadService;
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class MediaRevisionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media_local');
        config(['media.disk' => 'media_local', 'media.bucket' => 'test-media']);
        $this->customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Tenant A', 'slug' => 'tenant-a', 'subdomain' => 'tenant-a', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::forceCreate([
            'customer_id' => $this->customerId, 'name' => 'Admin', 'email' => uniqid().'@example.test',
            'password' => Hash::make('password'), 'role' => 'customer_admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        TenantContext::set((object) ['id' => $this->customerId]);
        $this->actingAs($this->admin);
    }

    public function test_a_new_processing_version_archives_the_previous_ready_revision(): void
    {
        $media = $this->uploadVideo();
        $this->materialize($media->id);
        $this->assertSame('ready', $this->transcriptStatus($media->id, 'fake-v1'));

        config(['media.processing.versions.speech_to_text' => 'fake-v2']);
        $this->materialize($media->id);

        $this->assertSame('archived', $this->transcriptStatus($media->id, 'fake-v1'));
        $this->assertSame('ready', $this->transcriptStatus($media->id, 'fake-v2'));
        $this->assertSame(1, DB::table('media_transcripts')->where('media_file_id', $media->id)
            ->where('locale', 'vi')->where('status', 'ready')->count());
    }

    public function test_a_version_bump_does_not_archive_a_coexisting_locale(): void
    {
        $media = $this->uploadVideo();
        $this->materialize($media->id);
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'speech_to_text', ['diarization' => 'off', 'locale' => 'ko'], $this->admin->id
        );
        $this->assertSame('ready', $this->transcriptStatus($media->id, 'fake-v1', 'ko'));

        config(['media.processing.versions.speech_to_text' => 'fake-v2']);
        $this->materialize($media->id);

        $this->assertSame('archived', $this->transcriptStatus($media->id, 'fake-v1', 'vi'));
        $this->assertSame('ready', $this->transcriptStatus($media->id, 'fake-v1', 'ko'));
    }

    public function test_caption_formats_of_the_same_locale_coexist(): void
    {
        $media = $this->uploadVideo();
        $this->materialize($media->id);
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'caption', ['format' => 'srt', 'locale' => 'vi'], $this->admin->id
        );

        $this->assertDatabaseHas('media_captions', ['media_file_id' => $media->id, 'caption_type' => 'vtt', 'status' => 'ready']);
        $this->assertDatabaseHas('media_captions', ['media_file_id' => $media->id, 'caption_type' => 'srt', 'status' => 'ready']);
    }

    public function test_the_archived_revision_stays_readable_by_explicit_version(): void
    {
        $media = $this->uploadVideo();
        $this->materialize($media->id);
        $superseded = DB::table('media_transcripts')->where('media_file_id', $media->id)->first();

        config(['media.processing.versions.speech_to_text' => 'fake-v2']);
        $this->materialize($media->id);

        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'owner_type' => 'course_activity',
            'owner_id' => 99, 'usage_type' => 'video', 'status' => 'active', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->andReturnTrue();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);
        $service = app(MediaReadService::class);

        $current = $service->read($this->admin->id, 'course_activity', 99, 'video', 'transcript', 'vi');
        $this->assertSame('fake-v2', $current[0]['processing_version']);
        $this->assertSame('ready', $current[0]['status']);

        $archived = $service->read($this->admin->id, 'course_activity', 99, 'video', 'transcript', 'vi',
            $superseded->processing_version, $superseded->source_fingerprint);
        $this->assertSame('fake-v1', $archived[0]['processing_version']);
        $this->assertSame('archived', $archived[0]['status']);
    }

    private function materialize(int $mediaFileId): void
    {
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $mediaFileId, 'vi', $this->admin->id);
    }

    private function transcriptStatus(int $mediaFileId, string $version, string $locale = 'vi'): ?string
    {
        return DB::table('media_transcripts')->where('media_file_id', $mediaFileId)
            ->where('locale', $locale)->where('processing_version', $version)->value('status');
    }

    private function uploadVideo(): object
    {
        return app(MediaService::class)->upload(UploadedFile::fake()->create('lesson.mp4', 32, 'video/mp4'), [
            'file_type' => 'video', 'module' => 'course', 'entity_type' => 'activities', 'entity_id' => 99, 'purpose' => 'video',
        ], $this->admin->id);
    }
}
