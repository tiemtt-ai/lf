<?php

namespace Tests\Feature;

use App\Exceptions\MediaReadException;
use App\Models\User;
use App\Services\CourseMediaOwnerContextAuthorizer;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaReadService;
use App\Services\MediaService;
use App\Services\TranscriptVttCaptionProvider;
use App\Services\VideoSpeechToTextProfile;
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
        // Cac test nay dung fixture VIDEO de kiem co che STT, nen phai bat
        // gate mot cach tuong minh. Gate mac dinh TAT theo Temporary Safety
        // Rule cua DOC-CONFLICT-0027 — xem test_video_stt_is_off_by_default.
        config([
            'media.processing.speech_to_text.video_enabled' => true,
            'media.processing.video_audio.ffmpeg_version' => '7.1.1',
        ]);
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

    /**
     * Caption dung TU transcript, nen transcript revision moi lam caption cua ban
     * cu thanh stale. `source_fingerprint` khong bat duoc: no la van tay binary
     * goc, khong doi khi transcript len version moi.
     */
    public function test_a_new_transcript_revision_archives_the_caption_built_on_the_old_one(): void
    {
        $media = $this->uploadVideo();
        $this->materialize($media->id);
        $fingerprint = DB::table('media_transcripts')->where('media_file_id', $media->id)
            ->where('processing_version', $this->videoVersion('fake-v1'))->value('source_fingerprint');

        $captionId = DB::table('media_captions')->insertGetId([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'locale' => 'vi', 'caption_type' => 'vtt',
            'storage_key' => 'tenants/1/captions/v1.vtt', 'status' => 'ready',
            'processing_job_id' => DB::table('media_processing_jobs')->where('media_file_id', $media->id)
                ->where('job_type', 'speech_to_text')->value('id'),
            'processing_version' => 'caption-v1',
            'transcript_processing_version' => $this->videoVersion('fake-v1'),
            'source_fingerprint' => $fingerprint,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Caption khong do job sinh ra, va mang fingerprint khac. Khong dung tu
        // transcript nao nen khong duoc dung toi — ke ca khi fingerprint lech.
        $manualId = DB::table('media_captions')->insertGetId([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'locale' => 'vi', 'caption_type' => 'srt',
            'storage_key' => 'tenants/1/captions/manual.srt', 'status' => 'ready',
            'processing_job_id' => null, 'processing_version' => 'manual-v1',
            'transcript_processing_version' => null, 'source_fingerprint' => str_repeat('f', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Caption dung tu transcript SAP toi (fake-v2). `processing_version` cua
        // chinh no van la caption-v1, nen so nham cot se archive nham row nay.
        $currentId = DB::table('media_captions')->insertGetId([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'locale' => 'vi', 'caption_type' => 'ass',
            'storage_key' => 'tenants/1/captions/v2.ass', 'status' => 'ready',
            'processing_job_id' => DB::table('media_processing_jobs')->where('media_file_id', $media->id)
                ->where('job_type', 'speech_to_text')->value('id'),
            'processing_version' => 'caption-v1',
            'transcript_processing_version' => $this->videoVersion('fake-v2'),
            'source_fingerprint' => $fingerprint,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        config(['media.processing.versions.speech_to_text' => 'fake-v2']);
        $this->materialize($media->id);

        $this->assertSame('archived', $this->transcriptStatus($media->id, 'fake-v1'));
        $this->assertSame('archived', DB::table('media_captions')->where('id', $captionId)->value('status'),
            'Caption dung tu transcript da bi thay the phai stale.');
        $this->assertSame('ready', DB::table('media_captions')->where('id', $manualId)->value('status'),
            'Caption khong dung tu transcript khong duoc dung toi.');
        $this->assertSame('ready', DB::table('media_captions')->where('id', $currentId)->value('status'),
            'Caption dung tu transcript hien hanh phai giu ready.');
    }

    /**
     * STT chay lai duoi revision moi phai sinh caption MOI.
     *
     * Neu `processing_version` cua caption khong gom revision nguon thi caption
     * cua transcript v1 va v2 co cung version, cung idempotency key — lan
     * materialize thu hai bi dedupe. Ket qua: caption cu bi stale cascade archive,
     * caption moi khong bao gio duoc tao, va video mat phu de vinh vien.
     */
    public function test_a_transcript_rerun_materializes_a_new_caption_chain(): void
    {
        $media = $this->uploadVideo();
        $this->materialize($media->id);
        $first = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'caption')->pluck('processing_version')->all();
        $this->assertCount(1, $first);

        config(['media.processing.versions.speech_to_text' => 'fake-v2']);
        $this->materialize($media->id);

        $all = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'caption')->pluck('processing_version')->unique()->all();
        $this->assertCount(2, $all, 'Moi transcript revision phai co caption chain rieng.');
    }

    /**
     * Caption phai ghi lai transcript revision da dung. `source_fingerprint` khong
     * dien dat duoc dieu do — no la van tay binary goc, khong doi khi transcript
     * len revision moi.
     */
    public function test_the_caption_row_records_the_transcript_revision_it_was_built_from(): void
    {
        config([
            'media.processing.providers.caption' => 'transcript_vtt',
            'media.processing.versions.caption' => 'transcript-vtt-v1',
        ]);
        $media = $this->uploadVideo();
        $this->materialize($media->id);

        $caption = DB::table('media_captions')->where('media_file_id', $media->id)->firstOrFail();
        $transcriptVersion = DB::table('media_transcripts')->where('media_file_id', $media->id)
            ->where('status', 'ready')->value('processing_version');

        $this->assertSame($transcriptVersion, $caption->transcript_processing_version);
        $this->assertSame('ready', $caption->status);
        Storage::disk($media->storage_disk)->assertExists($caption->storage_key);

        $vtt = Storage::disk($media->storage_disk)->get($caption->storage_key);
        $this->assertStringStartsWith('WEBVTT', $vtt);

        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => 156,
            'usage_type' => 'video', 'status' => 'active', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->andReturnTrue();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);

        $units = app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', 156, 'video', 'caption_asset', 'vi'
        );
        $this->assertCount(1, $units);
        $this->assertNull($units[0]['locator'], 'Caption asset khong duoc bia mot timespan cho ca file.');
        $this->assertNull($units[0]['text']);
        $this->assertNotNull($units[0]['delivery_url']);
        $this->assertNull($units[0]['structure']);

        try {
            app(MediaReadService::class)->read(
                $this->admin->id, 'course_activity', 156, 'video', 'caption_asset', 'vi',
                null, null, 'ai', [], null, true
            );
            $this->fail('include_crop was accepted for a caption asset.');
        } catch (MediaReadException $exception) {
            $this->assertSame('unsupported_source', $exception->errorCode);
        }
    }

    /**
     * Hai transcript revision `ready` cung luc la trang thai khong duoc phep doan.
     * Chon nham se sinh phu de cua mot ban phien am khac voi ban AI dang doc, va
     * citation van hop le nen sai khong lo ra.
     */
    public function test_caption_fails_closed_when_the_transcript_revision_is_ambiguous(): void
    {
        $media = $this->uploadVideo();
        $this->materialize($media->id);

        $row = DB::table('media_transcripts')->where('media_file_id', $media->id)->firstOrFail();
        DB::table('media_transcripts')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'locale' => 'vi', 'locator_type' => 'timespan', 'locator_value' => '0-1000',
            'text' => 'ban thu hai', 'status' => 'ready', 'provider' => 'fake',
            'processing_version' => 'fake-v9', 'source_fingerprint' => $row->source_fingerprint,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ambiguous_source');
        app(TranscriptVttCaptionProvider::class)->process(
            DB::table('media_files')->where('id', $media->id)->first(),
            (object) [
                'job_type' => 'caption', 'output_profile' => 'format=vtt;locale=vi',
                'source_fingerprint' => $row->source_fingerprint,
                'processing_version' => 'transcript-vtt-v1',
            ],
        );
    }

    /**
     * Provider fail-closed is not enough: the worker must preserve the named
     * domain error. Mapping it to `processing_failed` would hide the exact
     * remediation from operators even though the provider diagnosed it.
     */
    public function test_caption_job_preserves_ambiguous_source_error_code(): void
    {
        $media = $this->uploadVideo();
        $this->materialize($media->id);

        $row = DB::table('media_transcripts')->where('media_file_id', $media->id)->firstOrFail();
        DB::table('media_transcripts')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'locale' => 'vi', 'locator_type' => 'timespan', 'locator_value' => '0-1000',
            'text' => 'ban thu hai', 'status' => 'ready', 'provider' => 'fake',
            'processing_version' => 'fake-v9', 'source_fingerprint' => $row->source_fingerprint,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        config([
            'media.processing.providers.caption' => 'transcript_vtt',
            'media.processing.versions.caption' => 'transcript-vtt-ambiguous-v1',
        ]);

        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId,
            $media->id,
            'caption',
            ['format' => 'vtt', 'locale' => 'vi'],
            $this->admin->id,
        );

        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id,
            'job_type' => 'caption',
            'processing_version' => 'transcript-vtt-ambiguous-v1',
            'status' => 'failed',
            'error_code' => 'ambiguous_source',
        ]);
    }

    /**
     * Object storage is outside the database transaction. If the provider has
     * written VTT but the caption row cannot be inserted, the new object must
     * be removed while the pre-existing row/object remain untouched.
     */
    public function test_caption_asset_is_purged_when_persistence_rolls_back(): void
    {
        $media = $this->uploadVideo();
        $this->materialize($media->id);
        config([
            'media.processing.providers.caption' => 'transcript_vtt',
            'media.processing.versions.caption' => 'transcript-vtt-persist-failure-v1',
        ]);
        $orchestrator = app(MediaProcessingOrchestrator::class);
        $version = $orchestrator->versionFor('caption', $media, ['locale' => 'vi']);
        $existingKey = 'tenants/'.$this->customerId.'/captions/existing.vtt';
        $newKey = 'tenants/'.$this->customerId.'/captions/must-be-purged.vtt';
        Storage::disk('media_local')->put($existingKey, "WEBVTT\n\n");
        DB::table('media_captions')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'locale' => 'vi', 'caption_type' => 'vtt', 'storage_key' => $existingKey,
            'status' => 'ready', 'processing_job_id' => null,
            'processing_version' => $version,
            'transcript_processing_version' => null,
            'source_fingerprint' => hash('sha256', $media->checksum.':video'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $provider = Mockery::mock(TranscriptVttCaptionProvider::class);
        $provider->shouldReceive('process')->once()->andReturnUsing(function () use ($media, $newKey): array {
            Storage::disk('media_local')->put($newKey, "WEBVTT\n\n");

            return [
                'storage_key' => $newKey,
                'transcript_processing_version' => DB::table('media_transcripts')
                    ->where('media_file_id', $media->id)->where('status', 'ready')
                    ->value('processing_version'),
            ];
        });
        $this->app->instance(TranscriptVttCaptionProvider::class, $provider);

        $orchestrator->materializeOnDemandProfile(
            $this->customerId,
            $media->id,
            'caption',
            ['format' => 'vtt', 'locale' => 'vi'],
            $this->admin->id,
        );

        Storage::disk('media_local')->assertMissing($newKey);
        Storage::disk('media_local')->assertExists($existingKey);
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id,
            'job_type' => 'caption',
            'processing_version' => $version,
            'status' => 'failed',
        ]);
        $this->assertSame(1, DB::table('media_captions')
            ->where('media_file_id', $media->id)
            ->where('processing_version', $version)
            ->count());
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
        $fingerprint = hash('sha256', $media->checksum.':video');
        $now = now();
        DB::table('media_captions')->insert([
            [
                'customer_id' => $this->customerId, 'media_file_id' => $media->id,
                'locale' => 'vi', 'caption_type' => 'vtt', 'storage_key' => 'captions/existing.vtt',
                'status' => 'ready', 'processing_job_id' => null, 'processing_version' => 'manual-v1',
                'transcript_processing_version' => null, 'source_fingerprint' => $fingerprint,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'customer_id' => $this->customerId, 'media_file_id' => $media->id,
                'locale' => 'vi', 'caption_type' => 'srt', 'storage_key' => 'captions/existing.srt',
                'status' => 'ready', 'processing_job_id' => null, 'processing_version' => 'manual-v1',
                'transcript_processing_version' => null, 'source_fingerprint' => $fingerprint,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

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
        $this->assertSame($this->videoVersion('fake-v2'), $current[0]['processing_version']);
        $this->assertSame('ready', $current[0]['status']);

        $archived = $service->read($this->admin->id, 'course_activity', 99, 'video', 'transcript', 'vi',
            $superseded->processing_version, $superseded->source_fingerprint);
        $this->assertSame($this->videoVersion('fake-v1'), $archived[0]['processing_version']);
        $this->assertSame('archived', $archived[0]['status']);
    }

    private function materialize(int $mediaFileId): void
    {
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $mediaFileId, 'vi', $this->admin->id);
    }

    /**
     * Moi fixture trong file nay la video, va `processing_version` cua video STT
     * gom ca canonical ffmpeg extraction profile (Amendment Record 2.19 § 1).
     * Test dung chinh ham dung version cua runtime thay vi go tay chuoi ghep —
     * neu cach ghep doi, test doi theo chu khong im lang bo qua.
     */
    private function videoVersion(string $base): string
    {
        return $base.'+'.app(VideoSpeechToTextProfile::class)->label();
    }

    private function transcriptStatus(int $mediaFileId, string $version, string $locale = 'vi'): ?string
    {
        return DB::table('media_transcripts')->where('media_file_id', $mediaFileId)
            ->where('locale', $locale)->where('processing_version', $this->videoVersion($version))->value('status');
    }

    private function uploadVideo(): object
    {
        return app(MediaService::class)->upload(UploadedFile::fake()->create('lesson.mp4', 32, 'video/mp4'), [
            'file_type' => 'video', 'module' => 'course', 'entity_type' => 'activities', 'entity_id' => 99, 'purpose' => 'video',
        ], $this->admin->id);
    }
}
