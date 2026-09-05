<?php

namespace Tests\Feature;

use App\Contracts\MediaProcessingProvider;
use App\Exceptions\DocumentUsageException;
use App\Exceptions\MediaReadException;
use App\Jobs\ProcessMediaProcessingJob;
use App\Models\User;
use App\Services\CaptionAssetStorage;
use App\Services\CourseMediaOwnerContextAuthorizer;
use App\Services\DoclingStructuredExtractionProvider;
use App\Services\DocumentProcessRunner;
use App\Services\FakeMediaProcessingProvider;
use App\Services\FasterWhisperSpeechToTextProvider;
use App\Services\LocalDocumentProcessingProvider;
use App\Services\MediaMetadataProbe;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaReadService;
use App\Services\MediaService;
use App\Services\RegionCropStorage;
use App\Services\StructuredExtractionPersistenceService;
use App\Services\VideoAudioWorkspace;
use App\Services\VideoSpeechToTextProfile;
use App\Services\VideoSttQualification;
use App\Support\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class MediaProcessingSubstrateTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media_local');
        // Nhieu test o day dung fixture VIDEO de kiem co che STT, nen bat gate
        // tuong minh. Gate mac dinh TAT theo Temporary Safety Rule cua
        // DOC-CONFLICT-0027 — test_video_stt_is_off_by_default kiem dieu do.
        config([
            'media.disk' => 'media_local', 'media.bucket' => 'test-media',
            'media.processing.speech_to_text.video_enabled' => true,
            'media.processing.video_audio.ffmpeg_version' => '7.1.1',
        ]);
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

    public function test_required_profiles_are_deterministic_and_binary_ready_is_independent(): void
    {
        $media = $this->uploadVideo();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);

        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'processing_locale' => 'vi', 'status' => 'ready']);
        $this->assertDatabaseHas('media_processing_jobs', ['media_file_id' => $media->id, 'job_type' => 'speech_to_text', 'output_profile' => 'diarization=off;locale=vi', 'status' => 'ready']);
        $this->assertDatabaseHas('media_transcripts', ['media_file_id' => $media->id, 'locale' => 'vi', 'status' => 'ready']);

        // Caption la required-nhung-deferred: khong nam trong initial set, nhung
        // duoc materialize SAU khi transcript commit `ready` (DOC-CONFLICT-0025).
        // Job caption ton tai o day chinh la bang chung trigger da chay.
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'caption',
            'output_profile' => 'format=vtt;locale=vi',
        ]);
    }

    /**
     * Trigger chi chay sau transcript. Khi Video STT gate tat thi khong co
     * transcript nao, nen cung khong duoc co caption job — neu khong no se cho
     * mot transcript ma he thong da quyet dinh khong sinh ra.
     */
    public function test_no_caption_job_exists_when_the_video_stt_gate_is_off(): void
    {
        config(['media.processing.speech_to_text.video_enabled' => false]);
        $media = $this->uploadVideo();

        app(MediaProcessingOrchestrator::class)
            ->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);

        $this->assertDatabaseMissing('media_processing_jobs', ['media_file_id' => $media->id, 'job_type' => 'speech_to_text']);
        $this->assertDatabaseMissing('media_processing_jobs', ['media_file_id' => $media->id, 'job_type' => 'caption']);
    }

    public function test_local_document_provider_persists_real_embedded_text(): void
    {
        config([
            'media.processing.providers.ocr' => 'local_document',
            'media.processing.versions.ocr' => 'local-document-v1',
        ]);
        $text = "LearnForge local extraction\nNội dung tiếng Việt thật.";
        $media = app(MediaService::class)->upload(
            UploadedFile::fake()->createWithContent('lesson.txt', $text),
            [
                'file_type' => 'document', 'module' => 'course', 'entity_type' => 'activities',
                'entity_id' => 99, 'purpose' => 'document',
            ],
            $this->admin->id,
        );

        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => 99, 'usage_type' => 'document', 'status' => 'active',
        ]);
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity(
            $this->customerId,
            $media->id,
            'vi',
            $this->admin->id,
        );

        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'ocr',
            'provider' => 'local_document', 'status' => 'ready',
        ]);
        $this->assertDatabaseHas('media_extracted_texts', [
            'media_file_id' => $media->id, 'locale' => 'vi', 'text' => $text,
            'extraction_method' => 'embedded_text', 'provider' => 'local_document', 'status' => 'ready',
        ]);
    }

    public function test_faster_whisper_provider_returns_timestamped_units_with_explicit_locale(): void
    {
        Storage::disk('media_local')->put('lesson.mp3', 'audio bytes');
        config([
            'media.processing.speech_to_text.python_binary' => base_path('artisan'),
            'media.processing.speech_to_text.script' => base_path('runtime/stt/transcribe.py'),
            'media.processing.speech_to_text.model_path' => base_path('runtime'),
        ]);
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldReceive('run')->once()->withArgs(function (array $command, int $timeout): bool {
            $this->assertContains('ko', $command);
            $this->assertSame(3300, $timeout);
            $outputIndex = array_search('--output', $command, true);
            file_put_contents($command[$outputIndex + 1], json_encode([
                'status' => 'ready',
                'units' => [
                    ['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => '안녕하세요'],
                    ['locator_type' => 'timespan', 'locator_value' => '1000-2000', 'text' => '반갑습니다'],
                ],
            ], JSON_THROW_ON_ERROR));

            return true;
        })->andReturn('{"status":"ready","units":2}');

        $result = (new FasterWhisperSpeechToTextProvider($runner))->process(
            (object) [
                'file_type' => 'audio', 'mime_type' => 'audio/mpeg', 'extension' => 'mp3',
                'file_size_bytes' => 11, 'duration_seconds' => 2,
                'storage_disk' => 'media_local', 'storage_key' => 'lesson.mp3',
            ],
            (object) ['job_type' => 'speech_to_text', 'output_profile' => 'diarization=off;locale=ko'],
        );

        $this->assertSame('0-1000', $result['units'][0]['locator_value']);
        $this->assertSame('1000-2000', $result['units'][1]['locator_value']);
    }

    public function test_faster_whisper_multilingual_profile_runs_one_auto_detect_timeline(): void
    {
        Storage::disk('media_local')->put('mixed.mp3', 'audio bytes');
        config([
            'media.processing.speech_to_text.python_binary' => base_path('artisan'),
            'media.processing.speech_to_text.script' => base_path('runtime/stt/transcribe.py'),
            'media.processing.speech_to_text.model_path' => base_path('runtime'),
        ]);
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldReceive('run')->once()->withArgs(function (array $command): bool {
            $this->assertNotContains('--locale', $command);
            $index = array_search('--locales', $command, true);
            $this->assertNotFalse($index);
            $this->assertSame('ko,vi', $command[$index + 1]);
            $outputIndex = array_search('--output', $command, true);
            file_put_contents($command[$outputIndex + 1], json_encode([
                'status' => 'ready',
                'units' => [[
                    'locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => '안녕하세요, xin chào',
                    'languages' => [
                        ['locale' => 'ko', 'char_count' => 5],
                        ['locale' => 'vi', 'char_count' => 7],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR));

            return true;
        })->andReturn('{"status":"ready","units":1}');

        $result = (new FasterWhisperSpeechToTextProvider($runner))->process(
            (object) [
                'file_type' => 'audio', 'mime_type' => 'audio/mpeg', 'extension' => 'mp3',
                'file_size_bytes' => 11, 'duration_seconds' => 2,
                'storage_disk' => 'media_local', 'storage_key' => 'mixed.mp3',
            ],
            (object) ['job_type' => 'speech_to_text', 'output_profile' => 'diarization=off;locales=ko,vi'],
        );

        $this->assertSame('ko', $result['units'][0]['languages'][0]['locale']);
        $this->assertSame('vi', $result['units'][0]['languages'][1]['locale']);
    }

    public function test_faster_whisper_provider_fails_before_model_when_audio_exceeds_duration_limit(): void
    {
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldNotReceive('run');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('audio_limit_exceeded');
        (new FasterWhisperSpeechToTextProvider($runner))->process(
            (object) [
                'file_type' => 'audio', 'mime_type' => 'audio/mpeg', 'extension' => 'mp3',
                'file_size_bytes' => 1024, 'duration_seconds' => 7201,
            ],
            (object) ['job_type' => 'speech_to_text', 'output_profile' => 'diarization=off;locale=ko'],
        );
    }

    /**
     * Video 120 phut can 3.432s xu ly (RTF 0,48 do tren video that), vuot provider
     * deadline 3.300s. Tu choi TRUOC khi chay khac han chay 57 phut roi chet:
     * Amendment Record 2.21 § 2.
     */
    /**
     * Khong co gate nay thi deploy code se tu dong bat Video STT tren bat ky he
     * thong nao dang bat STT audio — trai Temporary Safety Rule cua
     * DOC-CONFLICT-0027, vi tran thoi luong hien la provisional.
     */
    /**
     * `put()` tra `true` khong chung minh object da nam tren storage — driver co
     * the bao thanh cong roi that bai o tang duoi. Database chi duoc chuyen `ready`
     * sau khi object duoc XAC MINH, nen phep xac minh phai nam trong buoc ghi.
     */
    public function test_a_caption_write_that_silently_stores_nothing_is_refused(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturnTrue();
        $disk->shouldReceive('exists')->andReturnFalse();
        $disk->shouldReceive('delete')->andReturnTrue();
        Storage::shouldReceive('disk')->with('media_local')->andReturn($disk);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('caption_write_failed');
        (new CaptionAssetStorage)->write(
            (object) ['storage_disk' => 'media_local'], 'tenants/1/captions/a.vtt', "WEBVTT\n\n"
        );
    }

    public function test_video_stt_is_off_by_default_and_creates_no_job(): void
    {
        // Kiem MAC DINH DUOC SHIP, khong phai gia tri da resolve.
        //
        // `require config_path(...)` van goi env(), nen no doc `.env` cua may
        // dang chay: test se do tren may co MEDIA_VIDEO_STT_ENABLED=true de test
        // local, du code khong doi gi. Doc thang doi so mac dinh moi tra loi dung
        // cau hoi "ban ship ra co tat khong".
        $this->assertStringContainsString(
            "env('MEDIA_VIDEO_STT_ENABLED', false)",
            (string) file_get_contents(config_path('media.php')),
            'Video STT phai mac dinh TAT trong config duoc ship.',
        );
        config(['media.processing.speech_to_text.video_enabled' => false]);

        $media = $this->uploadDocument();
        DB::table('media_files')->where('id', $media->id)->update(['file_type' => 'video']);
        DB::table('media_processing_jobs')->where('media_file_id', $media->id)->delete();

        app(MediaProcessingOrchestrator::class)
            ->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);

        $this->assertDatabaseMissing('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'speech_to_text',
        ]);
        $this->assertDatabaseMissing('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'caption',
        ]);
    }

    public function test_production_video_stt_requires_current_unexpired_qualification_evidence(): void
    {
        $path = sys_get_temp_dir().'/lf-video-qualification-'.Str::random(12).'.json';
        config([
            'media.processing.speech_to_text.video_enabled' => true,
            'media.processing.video_qualification.required' => true,
            'media.processing.video_qualification.evidence_path' => $path,
        ]);
        $qualification = app(VideoSttQualification::class);
        $this->assertSame('evidence_missing', $qualification->status()['code']);

        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'verdict' => 'PASS',
            'expires_at' => now()->addDay()->toIso8601String(),
            'processing_version' => $qualification->processingVersion(),
            'configuration_hash' => $qualification->configurationHash(),
        ], JSON_THROW_ON_ERROR));
        try {
            $this->assertSame('qualified', $qualification->status()['code']);
            $this->assertTrue($qualification->isQualified());

            config(['media.processing.speech_to_text.threads' => 8]);
            $this->assertSame('identity_mismatch', $qualification->status()['code']);
            $this->assertFalse($qualification->isQualified());
        } finally {
            @unlink($path);
        }
    }

    /**
     * Gate thu hai o tang provider: mot job da nam trong hang doi tu truoc khong
     * duoc lot qua sau khi gate bi tat.
     */
    public function test_the_provider_refuses_video_even_when_a_job_already_exists(): void
    {
        config(['media.processing.speech_to_text.video_enabled' => false]);
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldNotReceive('run');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('video_stt_disabled');
        (new FasterWhisperSpeechToTextProvider($runner))->process(
            (object) [
                'file_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4',
                'file_size_bytes' => 1024, 'duration_seconds' => 600,
            ],
            (object) ['job_type' => 'speech_to_text', 'output_profile' => 'diarization=off;locale=ko'],
        );
    }

    public function test_the_provider_refuses_a_queued_video_when_qualification_is_no_longer_valid(): void
    {
        config([
            'media.processing.speech_to_text.video_enabled' => true,
            'media.processing.video_qualification.required' => true,
            'media.processing.video_qualification.evidence_path' => '/missing/evidence.json',
        ]);
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldNotReceive('run');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('video_stt_unqualified');
        (new FasterWhisperSpeechToTextProvider($runner))->process(
            (object) [
                'file_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4',
                'file_size_bytes' => 1024, 'duration_seconds' => 600,
            ],
            (object) ['job_type' => 'speech_to_text', 'output_profile' => 'diarization=off;locale=ko'],
        );
    }

    /**
     * Revision identity phai den tu inventory cua deployment, khong tu probe.
     *
     * Ban truoc doc `ffmpeg -version` ngay luc TAO job — co the tren web node
     * khong co ffmpeg. Node do ghi `unavailable` vao processing_version, roi
     * worker CO ffmpeg xu ly bang binary that: output mang mot identity noi rang
     * ffmpeg khong ton tai. Transcript van `ready`, khong ai thay.
     */
    public function test_video_revision_identity_comes_from_inventory_not_from_probing(): void
    {
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldNotReceive('run');
        $profile = new VideoSpeechToTextProfile($runner);

        config(['media.processing.video_audio.ffmpeg_version' => '7.1.1']);
        $this->assertStringContainsString('ffmpeg-7.1.1', $profile->label());

        config(['media.processing.video_audio.ffmpeg_version' => '6.0']);
        $this->assertStringContainsString('ffmpeg-6.0', $profile->label());
    }

    public function test_the_worker_refuses_when_the_real_binary_does_not_match_inventory(): void
    {
        config([
            'media.processing.video_audio.ffmpeg_version' => '99.9',
            'media.processing.video_audio.ffmpeg_binary' => PHP_BINARY,
        ]);
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldReceive('run')->once()->andReturn("ffmpeg version 7.1.1 Copyright\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('extraction_profile_mismatch');
        (new VideoSpeechToTextProfile($runner))->assertBinaryMatchesInventory();
    }

    public function test_an_undeclared_ffmpeg_version_fails_closed(): void
    {
        config(['media.processing.video_audio.ffmpeg_version' => '']);
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldNotReceive('run');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider_unavailable');
        (new VideoSpeechToTextProfile($runner))->assertBinaryMatchesInventory();
    }

    public function test_sample_format_participates_in_the_command_and_the_identity(): void
    {
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $profile = new VideoSpeechToTextProfile($runner);
        config(['media.processing.video_audio.ffmpeg_version' => '7.1.1']);

        $this->assertContains('-sample_fmt', $profile->arguments('/a.mp4', '/b.wav'));
        $before = $profile->hash();

        config(['media.processing.video_audio.sample_format' => 's32']);
        $this->assertNotSame($before, $profile->hash(),
            'Doi sample_format phai doi ca lenh lan identity.');
    }

    public function test_video_threads_participate_in_identity_without_changing_audio_identity(): void
    {
        $orchestrator = app(MediaProcessingOrchestrator::class);
        $video = (object) ['file_type' => 'video'];
        $audio = (object) ['file_type' => 'audio'];

        config(['media.processing.speech_to_text.threads' => 0]);
        $videoBefore = $orchestrator->versionFor('speech_to_text', $video);
        $audioBefore = $orchestrator->versionFor('speech_to_text', $audio);

        config(['media.processing.speech_to_text.threads' => 8]);

        $this->assertNotSame($videoBefore, $orchestrator->versionFor('speech_to_text', $video));
        $this->assertSame($audioBefore, $orchestrator->versionFor('speech_to_text', $audio));
    }

    public function test_video_over_ninety_minutes_is_refused_before_the_model_runs(): void
    {
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldNotReceive('run');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('video_limit_exceeded');
        (new FasterWhisperSpeechToTextProvider($runner))->process(
            (object) [
                'file_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4',
                'file_size_bytes' => 1024, 'duration_seconds' => 5401,
            ],
            (object) ['job_type' => 'speech_to_text', 'output_profile' => 'diarization=off;locale=ko'],
        );
    }

    public function test_audio_keeps_its_own_two_hour_ceiling(): void
    {
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldNotReceive('run');

        // 5.401s vuot tran video nhung nam trong tran audio 7.200s. Locale `fr`
        // khong nam trong allowlist, va no duoc kiem SAU tran thoi luong — nen
        // `locale_unavailable` chung minh tran thoi luong da di qua.
        // Mot dong sua nham se lam co tran cua moi audio dang chay.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('locale_unavailable');
        (new FasterWhisperSpeechToTextProvider($runner))->process(
            (object) [
                'file_type' => 'audio', 'mime_type' => 'audio/mpeg', 'extension' => 'mp3',
                'file_size_bytes' => 1024, 'duration_seconds' => 5401,
            ],
            (object) ['job_type' => 'speech_to_text', 'output_profile' => 'diarization=off;locale=fr'],
        );
    }

    public function test_video_over_one_gibibyte_is_refused_with_the_video_error_code(): void
    {
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldNotReceive('run');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('video_limit_exceeded');
        (new FasterWhisperSpeechToTextProvider($runner))->process(
            (object) [
                'file_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4',
                'file_size_bytes' => 1073741825, 'duration_seconds' => 600,
            ],
            (object) ['job_type' => 'speech_to_text', 'output_profile' => 'diarization=off;locale=ko'],
        );
    }

    public function test_a_video_mime_outside_the_allowlist_is_unsupported(): void
    {
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldNotReceive('run');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupported_source');
        (new FasterWhisperSpeechToTextProvider($runner))->process(
            (object) [
                'file_type' => 'video', 'mime_type' => 'video/x-flv', 'extension' => 'flv',
                'file_size_bytes' => 1024, 'duration_seconds' => 600,
            ],
            (object) ['job_type' => 'speech_to_text', 'output_profile' => 'diarization=off;locale=ko'],
        );
    }

    /**
     * `source_fingerprint` la van tay cua BINARY GOC, khong doi khi tham so tach
     * audio doi. Neu extraction profile khong nam trong `processing_version` thi
     * doi `-ar` se lam transcript doi noi dung ma khong sinh revision moi.
     */
    public function test_changing_an_extraction_parameter_changes_the_video_processing_version(): void
    {
        $media = (object) ['file_type' => 'video'];
        $orchestrator = app(MediaProcessingOrchestrator::class);

        config(['media.processing.versions.speech_to_text' => 'stt-v1']);
        $before = $orchestrator->versionFor('speech_to_text', $media);

        config(['media.processing.video_audio.sample_rate' => 44100]);
        $after = $orchestrator->versionFor('speech_to_text', $media);

        $this->assertNotSame($before, $after, 'Doi sample rate phai sinh processing_version moi.');
        $this->assertStringContainsString('ar16000', $before);
        $this->assertStringContainsString('ar44100', $after);
    }

    public function test_the_extraction_profile_never_touches_audio_identity(): void
    {
        config(['media.processing.versions.speech_to_text' => 'stt-v1']);
        $orchestrator = app(MediaProcessingOrchestrator::class);

        // Them profile cho ca audio se doi identity cua MOI transcript audio dang
        // chay, archive toan bo va bat chay lai. Amendment Record 2.19 § 1.
        $this->assertSame('stt-v1', $orchestrator->versionFor('speech_to_text', (object) ['file_type' => 'audio']));
        $this->assertSame('stt-v1', $orchestrator->versionFor('speech_to_text', null));
        $this->assertStringStartsWith('stt-v1+ffmpeg-',
            $orchestrator->versionFor('speech_to_text', (object) ['file_type' => 'video']));
    }

    public function test_invalid_transcript_rolls_back_every_segment_and_names_the_error(): void
    {
        config([
            'media.processing.providers.speech_to_text' => 'fake',
            'media.processing.versions.speech_to_text' => 'fake-invalid-v1',
        ]);
        $media = $this->uploadVideo('invalid-transcript.mp4');
        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andReturn(['units' => [
            ['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => 'first'],
            ['locator_type' => 'timespan', 'locator_value' => '500-1500', 'text' => 'overlap'],
        ]]);
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);

        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'speech_to_text',
            ['locale' => 'ko', 'diarization' => 'off'], $this->admin->id,
        );

        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'speech_to_text',
            'status' => 'failed', 'error_code' => 'transcript_invalid',
        ]);
        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
    }

    public function test_local_document_provider_rejects_pdf_over_page_limit_before_extraction(): void
    {
        config(['media.processing.local_document.max_pages' => 100]);
        Storage::disk('media_local')->put('page-limit.pdf', 'pdf');
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldReceive('run')->once()->andReturn("Pages: 101\n");
        $provider = new LocalDocumentProcessingProvider($runner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('page_limit_exceeded');
        $provider->process(
            (object) ['file_type' => 'document', 'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_key' => 'page-limit.pdf'],
            (object) ['job_type' => 'ocr', 'output_profile' => 'layout=preserve;locale=vi'],
        );
    }

    public function test_docling_provider_rejects_pdf_over_page_limit_before_model_process(): void
    {
        config(['media.processing.structured_extraction.max_pages' => 100]);
        Storage::disk('media_local')->put('docling-page-limit.pdf', 'pdf');
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldReceive('run')->once()->andReturn("Pages: 101\n");
        $provider = new DoclingStructuredExtractionProvider($runner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('page_limit_exceeded');
        $provider->process(
            (object) ['file_type' => 'document', 'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_key' => 'docling-page-limit.pdf'],
            (object) ['job_type' => 'structured_extraction', 'output_profile' => 'locale=ko;structure=layout'],
        );
    }

    /**
     * Runner gia: tra ket qua theo lenh duoc goi, va voi `pdftoppm` thi ghi mot
     * PNG that ra dia de duong crop chay den cung.
     */
    public function test_docling_deadline_is_shared_and_cannot_be_swallowed_as_optional_ocr_failure(): void
    {
        $this->doclingConfig(['media.processing.structured_extraction.max_processing_seconds' => 10]);
        Storage::disk('media_local')->put('deadline.pdf', 'pdf');
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $provider = new DoclingStructuredExtractionProvider($runner);
        $runner->shouldReceive('run')->once()->andReturnUsing(function (array $command, int $timeout) use ($provider): string {
            $this->assertLessThanOrEqual(10, $timeout);
            // Simulate a command exhausting the entire remaining revision budget.
            (new \ReflectionProperty($provider, 'deadline'))->setValue($provider, microtime(true) - 1);

            return "Pages: 1\n";
        });
        $this->expectExceptionMessage('provider_timeout');
        $provider->process((object) ['file_type' => 'document', 'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_key' => 'deadline.pdf'],
            (object) ['job_type' => 'structured_extraction', 'output_profile' => 'locale=vi;structure=layout']);
    }

    public function test_docling_figure_text_timeout_fails_instead_of_returning_partial_ready(): void
    {
        $this->doclingConfig();
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldReceive('run')->withArgs(fn ($command) => basename($command[0]) === 'pdfinfo')
            ->andReturn("Pages: 1\nPage size: 720 x 540 pts\n");
        $runner->shouldReceive('run')->withArgs(fn ($command) => in_array('--output', $command, true))
            ->andReturnUsing(function ($command): string {
                file_put_contents($command[array_search('--output', $command, true) + 1],
                    json_encode(['status' => 'ready', 'regions' => $this->doclingFigureRegion()]));

                return '{"status":"ready"}';
            });
        $runner->shouldReceive('run')->withArgs(fn ($command) => basename($command[0]) === 'pdftotext')
            ->once()->andThrow(new \RuntimeException('provider_timeout'));
        $this->expectExceptionMessage('provider_timeout');
        $this->doclingProcess($runner, 'figure-timeout.pdf');
    }

    private function doclingRunner(string $resultPath, array $regions, string $figureText = '', string $ocrText = ''): DocumentProcessRunner
    {
        return new class($resultPath, $regions, $figureText, $ocrText) extends DocumentProcessRunner
        {
            public array $commands = [];

            public function __construct(
                private string $resultPath,
                private array $regions,
                private string $figureText,
                private string $ocrText,
            ) {}

            public function run(array $command, int $timeoutSeconds): string
            {
                $this->commands[] = $command;
                $binary = basename((string) $command[0]);

                if ($binary === 'pdfinfo') {
                    return "Pages: 1\nPage size: 720 x 540 pts\n";
                }
                if ($binary === 'pdftotext') {
                    return $this->figureText;
                }
                if ($binary === 'tesseract') {
                    return $this->ocrText;
                }
                if ($binary === 'pdftoppm') {
                    $target = (string) $command[array_key_last($command)].'.png';
                    file_put_contents($target, base64_decode(
                        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEklEQVR4nGP8//8/AzJgYkAD'
                        .'RAsAAP//AwCJsAPFAAAAAElFTkSuQmCC'
                    ));

                    return '';
                }

                $output = (string) $command[array_search('--output', $command, true) + 1];
                file_put_contents($output, json_encode([
                    'status' => 'ready',
                    'regions' => $this->regions,
                ], JSON_THROW_ON_ERROR));

                return json_encode(['status' => 'ready'], JSON_THROW_ON_ERROR);
            }
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function doclingFormulaRegions(): array
    {
        $evidence = static fn (?string $raw): array => [
            'raw_text' => $raw, 'normalized_format' => null, 'normalized_value' => null,
            'normalization_status' => 'unavailable', 'confidence_score' => null,
        ];

        return [
            ['page' => 1, 'ordinal' => 1, 'reading_order' => 1, 'locator_value' => '1#1',
                'role' => 'formula', 'bbox' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.4, 'height' => 0.3],
                'text' => 'x^2 + y^2 = z^2', 'extraction_method' => 'embedded_text',
                'metadata' => ['docling_label' => 'formula'], 'formula' => $evidence('x^2 + y^2 = z^2')],
            ['page' => 1, 'ordinal' => 2, 'reading_order' => 2, 'locator_value' => '1#2',
                'role' => 'formula', 'bbox' => ['x' => 0.1, 'y' => 0.5, 'width' => 0.4, 'height' => 0.2],
                'text' => null, 'extraction_method' => 'embedded_text',
                'metadata' => ['docling_label' => 'formula'], 'formula' => $evidence(null)],
        ];
    }

    private function doclingFigureRegion(): array
    {
        return [[
            'page' => 1, 'ordinal' => 1, 'reading_order' => 1, 'locator_value' => '1#1',
            'role' => 'figure', 'bbox' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.4, 'height' => 0.3],
            'text' => null, 'extraction_method' => 'embedded_text',
        ]];
    }

    private function doclingProcess(DocumentProcessRunner $runner, string $key, string $locale = 'vi', ?string $profile = null): array
    {
        Storage::disk('media_local')->put($key, 'pdf');

        return (new DoclingStructuredExtractionProvider($runner))->process(
            (object) ['id' => 7, 'customer_id' => 3, 'file_type' => 'document', 'extension' => 'pdf',
                'storage_disk' => 'media_local', 'storage_key' => $key],
            (object) ['job_type' => 'structured_extraction', 'output_profile' => $profile ?? 'locale='.$locale.';structure=layout',
                'source_fingerprint' => str_repeat('a', 64), 'processing_version' => 'docling-test-v1'],
        );
    }

    private function doclingConfig(array $overrides = []): void
    {
        config(array_merge([
            'media.processing.structured_extraction.max_pages' => 100,
            'media.processing.docling.python_binary' => PHP_BINARY,
            'media.processing.docling.script' => __FILE__,
            'media.processing.docling.artifacts_path' => __DIR__,
            'media.processing.structured_extraction.crop_enabled' => true,
            'media.processing.structured_extraction.crop_dpi' => 200,
            'media.processing.structured_extraction.crop_ocr_enabled' => true,
            'media.processing.structured_extraction.crop_ocr_min_text_characters' => 2,
            'media.processing.structured_extraction.max_crop_bytes_per_document' => 67108864,
        ], $overrides));
    }

    public function test_docling_provider_cleans_control_characters_and_records_observed_language_signals(): void
    {
        $this->doclingConfig(['media.processing.structured_extraction.crop_enabled' => false]);
        $regions = [[
            'page' => 1, 'ordinal' => 1, 'reading_order' => 1, 'locator_value' => '1#1',
            'role' => 'paragraph', 'bbox' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.4, 'height' => 0.3],
            'text' => "Công thức xác suất\f", 'confidence_score' => 97.25,
            'extraction_method' => 'embedded_text',
        ]];

        $result = $this->doclingProcess(
            $this->doclingRunner(sys_get_temp_dir().'/unused.json', $regions),
            'docling-signals.pdf',
        );

        $this->assertSame('Công thức xác suất', $result['regions'][0]['text']);
        $this->assertSame('vi', $result['regions'][0]['detected_locale']);
        $this->assertSame('Latn', $result['regions'][0]['script']);
        $this->assertSame(97.25, $result['regions'][0]['confidence_score']);
    }

    /**
     * ADR-0019 v1.8. Do tren tai lieu that: 263 region chua dong thoi Hangul va
     * tieng Viet, tat ca bi ghi la `ko` va phan tieng Viet bien mat. Chuoi
     * if/elseif cu chi giu duoc chu viet khop truoc.
     */
    public function test_docling_provider_records_every_observed_script_in_a_bilingual_region(): void
    {
        $this->doclingConfig(['media.processing.structured_extraction.crop_enabled' => false]);
        $regions = [[
            'page' => 1, 'ordinal' => 1, 'reading_order' => 1, 'locator_value' => '1#1',
            'role' => 'paragraph', 'text' => '비상한국어 - Tiếng Hàn ứng dụng học nhanh',
            'extraction_method' => 'embedded_text',
        ]];
        $result = $this->doclingProcess(
            $this->doclingRunner(sys_get_temp_dir().'/unused.json', $regions),
            'docling-bilingual.pdf', 'vi', 'locales=ko,vi;structure=layout',
        );

        $languages = $result['regions'][0]['languages'];
        $this->assertSame(['Latn', 'Hang'], array_column($languages, 'script'));
        $this->assertSame(['vi', 'ko'], array_column($languages, 'locale'));
        $this->assertSame(5, $languages[1]['char_count']);
        $this->assertSame('Latn', $result['regions'][0]['script']);
        $this->assertSame('vi', $result['regions'][0]['detected_locale']);
    }

    /**
     * Jamo doc lap (`ㅂ`, `ㄹ`) nam ngoai dai syllable U+AC00-U+D7A3. Regex cu
     * chi bat syllable nen 5 region kieu nay trong tai lieu that duoc doc thanh
     * tieng Viet thuan va mat han bang chung tieng Han.
     */
    public function test_docling_provider_reads_standalone_hangul_jamo_as_korean_evidence(): void
    {
        $this->doclingConfig(['media.processing.structured_extraction.crop_enabled' => false]);
        $regions = [[
            'page' => 1, 'ordinal' => 1, 'reading_order' => 1, 'locator_value' => '1#1',
            'role' => 'paragraph', 'text' => 'Ôn tập về bất quy tắc của ㅂ và giản lược ㄹ',
            'extraction_method' => 'embedded_text',
        ]];
        $result = $this->doclingProcess(
            $this->doclingRunner(sys_get_temp_dir().'/unused.json', $regions),
            'docling-jamo.pdf', 'vi', 'locales=ko,vi;structure=layout',
        );

        $languages = collect($result['regions'][0]['languages'])->keyBy('script');
        $this->assertTrue($languages->has('Hang'), 'Jamo doc lap phai duoc ghi nhan la bang chung tieng Han.');
        $this->assertSame(2, $languages['Hang']['char_count']);
        $this->assertSame('ko', $languages['Hang']['locale']);
        $this->assertSame('Latn', $result['regions'][0]['script']);
    }

    /**
     * Do tren tai lieu that: de cuong toan tieng Viet, profile chi `vi`, co
     * 304/1531 vung (20%) mat locale vi chung la phuong an trac nghiem va cong
     * thuc khong mang dau. Profile mot ngon ngu thi khong co gi mo ho de phai
     * chung minh — `Hang` cung khong bi doi bang chung nhu vay.
     */
    public function test_latin_region_takes_the_only_latin_locale_in_the_profile(): void
    {
        $this->doclingConfig(['media.processing.structured_extraction.crop_enabled' => false]);
        $result = $this->doclingProcess(
            $this->doclingRunner(sys_get_temp_dir().'/unused.json', $this->doclingTextRegion('A. 55 0 .')),
            'docling-latin-single.pdf', 'vi', 'locales=vi;structure=layout',
        );

        $this->assertSame('vi', $result['regions'][0]['detected_locale']);
        $this->assertSame([['script' => 'Latn', 'locale' => 'vi', 'char_count' => 1]], $result['regions'][0]['languages']);
    }

    /**
     * `en` truoc day chi duoc gan khi profile bang DUNG `['en']`, nen mot profile
     * nhieu ngon ngu co `en` khong bao gio sinh ra bang chung tieng Anh.
     */
    public function test_english_is_assigned_when_it_is_the_only_latin_locale_and_withheld_when_ambiguous(): void
    {
        $this->doclingConfig(['media.processing.structured_extraction.crop_enabled' => false]);
        $regions = $this->doclingTextRegion('AI Powered EdTech Platform');

        $assigned = $this->doclingProcess(
            $this->doclingRunner(sys_get_temp_dir().'/unused.json', $regions),
            'docling-en-ko.pdf', 'vi', 'locales=en,ko;structure=layout',
        );
        $this->assertSame('en', $assigned['regions'][0]['detected_locale']);

        // Hai ngon ngu Latin cung luc, khong dau de phan biet: tra NULL chu khong doan.
        $ambiguous = $this->doclingProcess(
            $this->doclingRunner(sys_get_temp_dir().'/unused.json', $regions),
            'docling-en-ko-vi.pdf', 'vi', 'locales=en,ko,vi;structure=layout',
        );
        $this->assertNull($ambiguous['regions'][0]['detected_locale']);
        $this->assertSame('Latn', $ambiguous['regions'][0]['script']);
    }

    /**
     * Tesseract tren logo tra ve chuoi rac dai hon text hien co, va truoc day
     * chuoi do duoc nhan lam text cua vung roi di thang vao read model.
     */
    public function test_garbage_crop_ocr_is_refused_while_the_crop_is_kept(): void
    {
        $this->doclingConfig();
        $result = $this->doclingProcess(
            $this->doclingRunner(sys_get_temp_dir().'/unused.json', $this->doclingFigureRegion(), '', 'LIÌ í>} 11 |'),
            'docling-ocr-garbage.pdf',
        );

        $this->assertNull($result['regions'][0]['text'], 'Chuoi rac khong duoc tro thanh text cua vung.');
        $this->assertArrayHasKey('crop', $result['regions'][0], 'Crop van phai duoc giu lai lam bang chung.');
        $this->assertSame('embedded_text', $result['regions'][0]['extraction_method']);
    }

    public function test_rejected_crop_ocr_preserves_short_observed_text(): void
    {
        $this->doclingConfig();
        $result = $this->doclingProcess(
            $this->doclingRunner(sys_get_temp_dir().'/unused.json', $this->doclingFigureRegion(), 'A', 'LIÌ í>} 11 |'),
            'docling-ocr-garbage-with-prior-text.pdf',
        );

        $this->assertSame('A', $result['regions'][0]['text']);
        $this->assertSame('embedded_text', $result['regions'][0]['extraction_method']);
        $this->assertArrayHasKey('crop', $result['regions'][0]);
    }

    public function test_low_quality_text_from_the_text_layer_is_flagged_not_discarded(): void
    {
        $this->doclingConfig(['media.processing.structured_extraction.crop_enabled' => false]);
        $result = $this->doclingProcess(
            $this->doclingRunner(sys_get_temp_dir().'/unused.json', $this->doclingTextRegion('Visanơ @®')),
            'docling-low-quality.pdf',
        );

        $this->assertSame('Visanơ @®', $result['regions'][0]['text']);
        $this->assertSame('low', $result['regions'][0]['metadata']['text_quality']);
    }

    public function test_legitimate_date_text_is_not_flagged_low_only_because_it_has_punctuation(): void
    {
        $this->doclingConfig(['media.processing.structured_extraction.crop_enabled' => false]);
        $text = '• Thời gian dự án : 2025.12.18 ~ 2028.12.17 (3 năm)';
        $result = $this->doclingProcess(
            $this->doclingRunner(sys_get_temp_dir().'/unused.json', $this->doclingTextRegion($text)),
            'docling-date-negative-control.pdf',
        );

        $this->assertSame($text, $result['regions'][0]['text']);
        $this->assertArrayNotHasKey('text_quality', $result['regions'][0]['metadata'] ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function doclingTextRegion(string $text): array
    {
        return [[
            'page' => 1, 'ordinal' => 1, 'reading_order' => 1, 'locator_value' => '1#1',
            'role' => 'paragraph', 'text' => $text, 'extraction_method' => 'embedded_text',
        ]];
    }

    public function test_docling_provider_renders_region_crop_and_keys_it_by_full_revision_identity(): void
    {
        $this->doclingConfig();
        $runner = $this->doclingRunner(sys_get_temp_dir().'/unused.json', $this->doclingFigureRegion(), 'nhan truc');
        $result = $this->doclingProcess($runner, 'docling-crop.pdf');

        $crop = $result['regions'][0]['crop'];
        $this->assertSame('image/png', $crop['mime_type']);
        $this->assertSame(2, $crop['width']);
        $this->assertGreaterThan(0, $crop['bytes']);

        // Duong dan phai chua ca ba chieu dinh danh mot revision. Chi co
        // processing_version thi file doi noi dung se de len crop cua ban cu.
        $this->assertSame(
            'tenants/3/media/7/regions/'.str_repeat('a', 64).'/docling-test-v1/vi/1-1.png',
            $crop['storage_key'],
        );
        Storage::disk('media_local')->assertExists($crop['storage_key']);
    }

    public function test_docling_provider_reads_figure_text_by_ocr_only_when_text_layer_is_empty(): void
    {
        $this->doclingConfig();
        $runner = $this->doclingRunner(sys_get_temp_dir().'/unused.json', $this->doclingFigureRegion(), '', 'doanh thu 2026');
        $result = $this->doclingProcess($runner, 'docling-crop-ocr.pdf');

        $region = $result['regions'][0];
        $this->assertSame('doanh thu 2026', $region['text']);
        $this->assertSame('ocr', $region['extraction_method']);
        $this->assertSame('tesseract', $region['metadata']['ocr_engine']);
        $this->assertSame('vie', $region['metadata']['ocr_language']);
    }

    public function test_multilingual_crop_ocr_flattens_and_deduplicates_canonical_language_packs(): void
    {
        $this->doclingConfig();
        $result = $this->doclingProcess(
            $this->doclingRunner(sys_get_temp_dir().'/unused.json', $this->doclingFigureRegion(), '', '한국어 EdTech'),
            'docling-ocr-ko-vi.pdf', 'vi', 'locales=ko,vi;structure=layout',
        );

        $this->assertSame('kor+vie', $result['regions'][0]['metadata']['ocr_language']);
    }

    public function test_docling_provider_keeps_embedded_text_and_skips_ocr_when_text_layer_has_content(): void
    {
        $this->doclingConfig();
        $runner = $this->doclingRunner(sys_get_temp_dir().'/unused.json', $this->doclingFigureRegion(), 'nhan truc', 'KHONG DUOC DUNG');
        $result = $this->doclingProcess($runner, 'docling-crop-embedded.pdf');

        $this->assertSame('nhan truc', $result['regions'][0]['text']);
        $this->assertSame('embedded_text', $result['regions'][0]['extraction_method']);
        $this->assertSame([], array_values(array_filter(
            $runner->commands,
            static fn (array $command): bool => basename((string) $command[0]) === 'tesseract',
        )));
    }

    public function test_docling_provider_replaces_one_character_figure_noise_with_longer_crop_ocr(): void
    {
        $this->doclingConfig();
        $runner = $this->doclingRunner(
            sys_get_temp_dir().'/unused.json',
            $this->doclingFigureRegion(),
            '가',
            '한국어 학습 자료',
        );
        $result = $this->doclingProcess($runner, 'docling-crop-short-text.pdf', 'ko');

        $region = $result['regions'][0];
        $this->assertSame('한국어 학습 자료', $region['text']);
        $this->assertSame('ocr', $region['extraction_method']);
        $this->assertSame('kor', $region['metadata']['ocr_language']);
    }

    public function test_docling_provider_keeps_short_figure_text_when_crop_ocr_is_not_better(): void
    {
        $this->doclingConfig();
        $runner = $this->doclingRunner(
            sys_get_temp_dir().'/unused.json',
            $this->doclingFigureRegion(),
            '가',
            '나',
        );
        $result = $this->doclingProcess($runner, 'docling-crop-short-text-preserved.pdf', 'ko');

        $region = $result['regions'][0];
        $this->assertSame('가', $region['text']);
        $this->assertSame('embedded_text', $region['extraction_method']);
        $this->assertArrayNotHasKey('ocr_engine', $region['metadata'] ?? []);
    }

    public function test_docling_provider_fails_closed_for_an_unsupported_language_profile(): void
    {
        $this->doclingConfig();
        $runner = $this->doclingRunner(sys_get_temp_dir().'/unused.json', $this->doclingFigureRegion(), '', 'rac');
        $this->expectExceptionMessage('document_language_profile_unsupported');
        $this->doclingProcess($runner, 'docling-crop-unknown-locale.pdf', 'xx');
    }

    public function test_worker_kill_purges_crops_of_the_job_it_abandons(): void
    {
        $media = $this->uploadDocument();
        $cropKey = "tenants/{$this->customerId}/media/{$media->id}/regions/".str_repeat('a', 64).'/v1/vi/1-1.png';
        Storage::disk($media->storage_disk)->put($cropKey, 'png-bytes');

        $jobId = DB::table('media_processing_jobs')->insertGetId([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'job_type' => 'structured_extraction', 'status' => 'processing',
            'provider' => 'docling_local', 'processing_version' => 'v1',
            'source_fingerprint' => str_repeat('a', 64), 'output_profile' => 'locale=vi;structure=layout',
            'output_profile_hash' => str_repeat('b', 64), 'attempt' => 1,
            'idempotency_key' => 'worker-kill-1', 'correlation_id' => 'worker-kill-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Worker bi giet giua chung: handle() khong chay het, nen chi failed()
        // con co the don crop da len storage.
        (new ProcessMediaProcessingJob($this->customerId, $jobId))->failed(null);

        $this->assertSame('failed', DB::table('media_processing_jobs')->where('id', $jobId)->value('status'));
        Storage::disk($media->storage_disk)->assertMissing($cropKey);
    }

    public function test_docling_provider_crops_only_eligible_regions_and_leaves_the_rest_null(): void
    {
        $this->doclingConfig();
        $figure = $this->doclingFigureRegion()[0];
        $paragraph = $figure;
        $paragraph['role'] = 'paragraph';
        $paragraph['ordinal'] = 2;
        $paragraph['reading_order'] = 2;
        $paragraph['locator_value'] = '1#2';
        $figureNoBbox = $figure;
        $figureNoBbox['bbox'] = null;
        $figureNoBbox['ordinal'] = 3;
        $figureNoBbox['reading_order'] = 3;
        $figureNoBbox['locator_value'] = '1#3';

        $runner = $this->doclingRunner(
            sys_get_temp_dir().'/unused.json', [$figure, $paragraph, $figureNoBbox], 'nhan truc'
        );
        $result = $this->doclingProcess($runner, 'docling-crop-eligibility.pdf');

        // Mot revision hien hanh co ca vung mang crop lan vung crop=null. Spec B
        // § 5.3 phai doc duoc theo eligibility, khong phai theo ca revision.
        $this->assertNotNull($result['regions'][0]['crop'] ?? null);
        $this->assertNull($result['regions'][1]['crop'] ?? null, 'paragraph khong thuoc crop_roles.');
        $this->assertNull($result['regions'][2]['crop'] ?? null, 'figure khong bbox thi khong co gi de cat.');
    }

    public function test_docling_provider_fails_whole_revision_when_crop_budget_is_exceeded(): void
    {
        // Tran nam GIUA mot va hai crop: crop dau len storage thanh cong, crop
        // thu hai vuot tran. Neu tran chan ngay tu crop dau thi test khong noi
        // duoc gi ve viec don crop da upload.
        $first = $this->doclingFigureRegion();
        $second = $first[0];
        $second['ordinal'] = 2;
        $second['reading_order'] = 2;
        $second['locator_value'] = '1#2';
        $regions = [$first[0], $second];

        $this->doclingConfig(['media.processing.structured_extraction.max_crop_bytes_per_document' => 100]);
        $runner = $this->doclingRunner(sys_get_temp_dir().'/unused.json', $regions, 'nhan truc');

        try {
            $this->doclingProcess($runner, 'docling-crop-budget.pdf');
            $this->fail('Crop budget overrun was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('structured_extraction_too_large', $exception->getMessage());
        }

        // DB rollback duoc, storage thi khong. Crop da len truoc khi vuot tran
        // phai bi xoa, neu khong chung la object mo coi co the chua PII.
        $this->assertSame([], Storage::disk('media_local')->allFiles(
            'tenants/3/media/7/regions/'.str_repeat('a', 64).'/docling-test-v1/vi'
        ));
    }

    public function test_failed_persistence_leaves_no_orphan_crop_in_storage(): void
    {
        $this->doclingConfig();
        // reading_order 2 khong lien tuc -> persistence nem sau khi crop da upload.
        $regions = $this->doclingFigureRegion();
        $regions[0]['reading_order'] = 2;
        $runner = $this->doclingRunner(sys_get_temp_dir().'/unused.json', $regions, 'nhan truc');

        $media = (object) ['id' => 7, 'customer_id' => 3, 'file_type' => 'document', 'extension' => 'pdf',
            'storage_disk' => 'media_local', 'storage_key' => 'docling-orphan.pdf'];
        $job = (object) ['job_type' => 'structured_extraction', 'provider' => 'docling_local',
            'output_profile' => 'locale=vi;structure=layout', 'source_fingerprint' => str_repeat('a', 64),
            'processing_version' => 'docling-test-v1'];
        Storage::disk('media_local')->put($media->storage_key, 'pdf');

        $result = (new DoclingStructuredExtractionProvider($runner, new RegionCropStorage))->process($media, $job);
        $prefix = 'tenants/3/media/7/regions/'.str_repeat('a', 64).'/docling-test-v1/vi';
        $this->assertCount(1, Storage::disk('media_local')->allFiles($prefix), 'Crop phai ton tai truoc khi persist.');

        // Chay dung duong job that: provider da upload crop, persistence nem,
        // transaction rollback — job phai don storage.
        config([
            'media.processing.providers.structured_extraction' => 'docling_local',
            'media.processing.versions.structured_extraction' => 'docling-test-v1',
        ]);
        $realMedia = $this->uploadDocument();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $realMedia->id, 'vi', $this->admin->id);
        $this->app->instance(DoclingStructuredExtractionProvider::class, new class($result) implements MediaProcessingProvider
        {
            public function __construct(private array $result) {}

            public function process(object $mediaFile, object $job): array
            {
                Storage::disk((string) $mediaFile->storage_disk)->put(
                    app(RegionCropStorage::class)->key($mediaFile, $job, 'vi', 1, 1), 'png-bytes'
                );

                return $this->result;
            }
        });

        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $realMedia->id, 'structured_extraction',
            ['locale' => 'vi', 'structure' => 'layout'], $this->admin->id
        );

        $failed = DB::table('media_processing_jobs')->where('media_file_id', $realMedia->id)
            ->where('job_type', 'structured_extraction')->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('structured_extraction_invalid', $failed->error_code);
        $this->assertSame('page', $failed->billable_unit_type);
        $this->assertSame(1, (int) $failed->billable_units);
        $this->assertSame(0, DB::table('media_extracted_regions')->where('media_file_id', $realMedia->id)->count());
        $this->assertSame([], Storage::disk((string) $realMedia->storage_disk)->allFiles(
            app(RegionCropStorage::class)->revisionPrefix($realMedia, $failed, 'vi')
        ), 'Job phai xoa crop cua revision that bai.');
    }

    public function test_docling_provider_preserves_stable_domain_error_from_json_protocol(): void
    {
        config([
            'media.processing.structured_extraction.max_pages' => 100,
            'media.processing.docling.python_binary' => PHP_BINARY,
            'media.processing.docling.script' => __FILE__,
            'media.processing.docling.artifacts_path' => __DIR__,
        ]);
        Storage::disk('media_local')->put('docling-error.pdf', 'pdf');
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldReceive('run')->once()->andReturn("Pages: 1\n");
        $runner->shouldReceive('run')->once()->andReturn(json_encode([
            'status' => 'failed',
            'error_code' => 'provider_unavailable',
        ], JSON_THROW_ON_ERROR));
        $provider = new DoclingStructuredExtractionProvider($runner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider_unavailable');
        $provider->process(
            (object) ['file_type' => 'document', 'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_key' => 'docling-error.pdf'],
            (object) ['job_type' => 'structured_extraction', 'output_profile' => 'locale=vi;structure=layout'],
        );
    }

    public function test_docling_provider_rejects_oversized_result_before_json_decode(): void
    {
        config([
            'media.processing.structured_extraction.max_pages' => 100,
            'media.processing.docling.python_binary' => PHP_BINARY,
            'media.processing.docling.script' => __FILE__,
            'media.processing.docling.artifacts_path' => __DIR__,
            'media.processing.docling.max_output_bytes' => 8,
        ]);
        Storage::disk('media_local')->put('docling-large-result.pdf', 'pdf');
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldReceive('run')->once()->andReturn("Pages: 1\n");
        $runner->shouldReceive('run')->once()->andReturnUsing(function (array $command): string {
            $outputIndex = array_search('--output', $command, true);
            file_put_contents($command[$outputIndex + 1], '{"status":"ready"}');

            return '{"status":"written","completed_pages":1}';
        });
        $provider = new DoclingStructuredExtractionProvider($runner);

        try {
            $provider->process(
                (object) ['file_type' => 'document', 'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_key' => 'docling-large-result.pdf'],
                (object) ['job_type' => 'structured_extraction', 'output_profile' => 'locale=vi;structure=layout'],
            );
            $this->fail('Oversized output accepted.');
        } catch (DocumentUsageException $exception) {
            $this->assertSame('structured_extraction_too_large', $exception->getMessage());
            $this->assertSame(1, $exception->pages);
            $this->assertSame('page', $exception->unitType);
        }
    }

    public function test_local_document_provider_rejects_docx_expansion_before_copy(): void
    {
        config(['media.processing.local_document.max_docx_xml_bytes' => 100]);
        $path = tempnam(sys_get_temp_dir(), 'lf-docx-limit-');
        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $archive->addFromString('word/document.xml', str_repeat('x', 101));
        $archive->close();
        Storage::disk('media_local')->put('expansion.docx', file_get_contents($path));
        unlink($path);
        $provider = new LocalDocumentProcessingProvider(Mockery::mock(DocumentProcessRunner::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('source_expansion_limit_exceeded');
        $provider->process(
            (object) ['file_type' => 'document', 'extension' => 'docx', 'storage_disk' => 'media_local', 'storage_key' => 'expansion.docx'],
            (object) ['job_type' => 'ocr', 'output_profile' => 'layout=preserve;locale=vi'],
        );
    }

    public function test_worker_timeout_failure_releases_processing_job_for_retry(): void
    {
        $queueJob = new ProcessMediaProcessingJob($this->customerId, 1);
        $this->assertGreaterThan($queueJob->timeout, config('queue.connections.redis.retry_after'));
        $this->assertGreaterThan(config('media.processing.local_document.max_processing_seconds'), $queueJob->timeout);

        $media = $this->uploadVideo();
        $job = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'virus_scan')->first();
        DB::table('media_processing_jobs')->where('id', $job->id)->update([
            'status' => 'processing', 'completed_at' => null, 'started_at' => now(),
            'error_code' => null, 'error_message' => null,
        ]);

        (new ProcessMediaProcessingJob($this->customerId, $job->id))->failed(new \RuntimeException('worker timeout'));

        $this->assertDatabaseHas('media_processing_jobs', [
            'id' => $job->id, 'status' => 'failed', 'error_code' => 'provider_timeout',
        ]);
        $retry = app(MediaProcessingOrchestrator::class)->retry($this->customerId, $job->id, $this->admin->id);
        $this->assertSame(2, (int) $retry->attempt);
    }

    public function test_infected_upload_fails_closed_and_clean_upload_is_ready(): void
    {
        $clean = $this->uploadVideo();
        $this->assertDatabaseHas('media_files', ['id' => $clean->id, 'status' => 'ready']);

        config(['media.processing.fake.virus_infected' => true]);
        $infected = $this->uploadVideo('infected.mp4');
        $this->assertDatabaseHas('media_files', [
            'id' => $infected->id,
            'status' => 'failed',
            'processing_error_code' => 'infected_source',
        ]);
    }

    public function test_unconfigured_virus_provider_fails_file_instead_of_leaving_processing_forever(): void
    {
        config(['media.processing.providers.virus_scan' => 'unconfigured']);

        $media = $this->uploadVideo('provider-unavailable.mp4');

        $this->assertDatabaseHas('media_files', [
            'id' => $media->id,
            'status' => 'failed',
            'processing_error_code' => 'provider_unavailable',
        ]);
    }

    public function test_missing_checksum_fails_closed_before_creating_an_output_identity(): void
    {
        $media = $this->uploadVideo();
        DB::table('media_files')->where('id', $media->id)->update(['checksum' => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Source checksum is required');
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId,
            $media->id,
            'thumbnail',
            ['size' => 'small'],
            $this->admin->id,
        );
    }

    /**
     * Ba attempt duoc chung minh cho STT; phia caption chi chung minh hai profile
     * co chain doc lap. Chuoi retry cua chinh caption nam o
     * `test_a_failed_caption_chain_retries_independently_up_to_three_attempts`.
     */
    public function test_transcript_retries_three_times_while_caption_profiles_stay_independent(): void
    {
        $media = $this->uploadVideo();
        $orchestrator = app(MediaProcessingOrchestrator::class);
        $orchestrator->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);

        config(['media.processing.providers.speech_to_text' => 'unconfigured']);
        $orchestrator->materializeOnDemandProfile($this->customerId, $media->id, 'speech_to_text', ['locale' => 'ko', 'diarization' => 'off'], $this->admin->id);
        $ko = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'speech_to_text')->where('output_profile', 'diarization=off;locale=ko')->first();
        $orchestrator->retry($this->customerId, $ko->id, $this->admin->id);
        $ko2 = DB::table('media_processing_jobs')->where('supersedes_job_id', $ko->id)->first();
        $orchestrator->retry($this->customerId, $ko2->id, $this->admin->id);

        // Caption khong con nam trong initial set; test chain caption phai
        // materialize no tuong minh, tuong duong buoc sau transcript `ready`.
        $orchestrator->materializeOnDemandProfile($this->customerId, $media->id, 'caption', ['locale' => 'vi', 'format' => 'vtt'], $this->admin->id);
        config(['media.processing.providers.caption' => 'unconfigured']);
        $orchestrator->materializeOnDemandProfile($this->customerId, $media->id, 'caption', ['locale' => 'vi', 'format' => 'srt'], $this->admin->id);

        $this->assertSame(3, DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'speech_to_text')->where('output_profile_hash', $ko->output_profile_hash)->count());
        $this->assertDatabaseHas('media_transcripts', ['media_file_id' => $media->id, 'locale' => 'vi', 'status' => 'ready']);
        $this->assertDatabaseHas('media_processing_jobs', ['media_file_id' => $media->id, 'job_type' => 'caption', 'output_profile' => 'format=vtt;locale=vi', 'status' => 'ready']);
        $this->assertDatabaseHas('media_processing_jobs', ['media_file_id' => $media->id, 'job_type' => 'caption', 'output_profile' => 'format=srt;locale=vi', 'status' => 'failed']);
        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'status' => 'ready']);
    }

    /**
     * Caption that bai phai retry duoc doc lap, khong keo theo STT lan Media.
     *
     * Truoc day khong test nao goi `retry()` tren mot caption job that bai — test
     * ten "three attempt chains" chi retry STT tieng Han, con phia caption chi tao
     * mot job `ready` va mot job `failed`. Ten hua nhieu hon noi dung.
     */
    public function test_a_failed_caption_chain_retries_independently_up_to_three_attempts(): void
    {
        $media = $this->uploadVideo();
        $orchestrator = app(MediaProcessingOrchestrator::class);
        $orchestrator->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);

        // Caption fail bang `provider_unavailable` — mot trong ba ma loi tam thoi
        // duy nhat duoc phep retry theo LF-Media-Processing-Contract § Retry.
        config(['media.processing.providers.caption' => 'unconfigured']);
        $orchestrator->materializeOnDemandProfile(
            $this->customerId, $media->id, 'caption', ['locale' => 'vi', 'format' => 'srt'], $this->admin->id
        );
        $attempt1 = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'caption')->where('output_profile', 'format=srt;locale=vi')->firstOrFail();
        $this->assertSame('failed', $attempt1->status);
        $this->assertSame('provider_unavailable', $attempt1->error_code);
        $this->assertSame(1, (int) $attempt1->attempt);

        $orchestrator->retry($this->customerId, $attempt1->id, $this->admin->id);
        $attempt2 = DB::table('media_processing_jobs')->where('supersedes_job_id', $attempt1->id)->firstOrFail();

        // Retry TIEP TUC chain cu, khong mo chain moi: cung profile va cung
        // revision identity, chi khac `attempt`.
        $this->assertSame(2, (int) $attempt2->attempt);
        $this->assertSame($attempt1->output_profile, $attempt2->output_profile);
        $this->assertSame($attempt1->output_profile_hash, $attempt2->output_profile_hash);
        $this->assertSame($attempt1->processing_version, $attempt2->processing_version);
        $this->assertSame($attempt1->source_fingerprint, $attempt2->source_fingerprint);
        $this->assertSame($attempt1->correlation_id, $attempt2->correlation_id);

        $orchestrator->retry($this->customerId, $attempt2->id, $this->admin->id);
        $attempt3 = DB::table('media_processing_jobs')->where('supersedes_job_id', $attempt2->id)->firstOrFail();
        $this->assertSame(3, (int) $attempt3->attempt);

        // Het ba attempt: chain dung lai, khong tu keo dai vo han.
        try {
            $orchestrator->retry($this->customerId, $attempt3->id, $this->admin->id);
            $this->fail('Chain caption da het attempt van duoc retry.');
        } catch (\InvalidArgumentException) {
            // Dung theo hop dong: toi da ba attempt.
        }
        $this->assertSame(3, DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'caption')->where('output_profile_hash', $attempt1->output_profile_hash)->count());

        // Caption that bai khong duoc lam hong transcript lan deliverability:
        // chi `virus_scan` moi gate `media_files.status`.
        $this->assertDatabaseHas('media_transcripts', ['media_file_id' => $media->id, 'locale' => 'vi', 'status' => 'ready']);
        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'status' => 'ready']);
        $this->assertSame(1, DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->count());
    }

    public function test_database_blocks_duplicate_same_profile_and_attempt(): void
    {
        $media = $this->uploadVideo();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        $job = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'speech_to_text')->first();
        $duplicate = (array) $job;
        unset($duplicate['id']);
        $this->expectException(QueryException::class);
        DB::table('media_processing_jobs')->insert($duplicate);
    }

    public function test_read_service_uses_exact_revision_and_appends_immutable_audit(): void
    {
        $media = $this->uploadVideo();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'owner_type' => 'course_activity',
            'owner_id' => 99, 'usage_type' => 'video', 'status' => 'active', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->andReturnTrue();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);

        Auth::logout();
        $units = app(MediaReadService::class)->read($this->admin->id, 'course_activity', 99, 'video', 'transcript', 'vi');
        $this->assertSame('timespan', $units[0]['locator']['type']);
        $this->assertDatabaseHas('media_access_logs', ['media_file_id' => $media->id, 'action' => 'read_derived', 'source_type' => 'ai']);
        $logId = DB::table('media_access_logs')->value('id');
        try {
            DB::table('media_access_logs')->where('id', $logId)->update(['action' => 'view']);
            $this->fail('Append-only update was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        try {
            DB::table('media_access_logs')->where('id', $logId)->delete();
            $this->fail('Append-only delete was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_current_revision_uses_job_identity_not_segment_timestamp(): void
    {
        $media = $this->uploadVideo();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'owner_type' => 'course_activity',
            'owner_id' => 101, 'usage_type' => 'video', 'status' => 'active', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $firstJob = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->first();
        $secondJobId = DB::table('media_processing_jobs')->insertGetId([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'job_type' => 'speech_to_text',
            'status' => 'ready', 'attempt' => 1, 'idempotency_key' => 'current-revision-v2',
            'correlation_id' => '22222222-2222-4222-8222-222222222222',
            'source_fingerprint' => $firstJob->source_fingerprint, 'processing_version' => 'fake-v2',
            'output_profile' => $firstJob->output_profile, 'output_profile_hash' => $firstJob->output_profile_hash,
            'provider' => 'fake', 'completed_at' => now()->subDay(), 'created_by' => $this->admin->id,
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        DB::table('media_transcripts')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'locale' => 'vi',
            'provider' => 'fake', 'status' => 'ready', 'text' => 'Revision two',
            'processing_job_id' => $secondJobId, 'processing_version' => 'fake-v2',
            'source_fingerprint' => $firstJob->source_fingerprint, 'locator_type' => 'timespan',
            'locator_value' => '1000-2000', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->andReturnTrue();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);

        $units = app(MediaReadService::class)->read($this->admin->id, 'course_activity', 101, 'video', 'transcript', 'vi');

        $this->assertSame('fake-v2', $units[0]['processing_version']);
        $this->assertSame('Revision two', $units[0]['text']);
    }

    public function test_after_commit_callback_is_not_dispatched_on_rollback(): void
    {
        $media = $this->uploadVideo();
        $before = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->count();
        try {
            DB::transaction(function () use ($media): void {
                DB::afterCommit(fn () => app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame($before, DB::table('media_processing_jobs')->where('media_file_id', $media->id)->count());
    }

    public function test_read_errors_and_explicit_archived_revision_are_fail_closed(): void
    {
        $media = $this->uploadVideo();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'owner_type' => 'course_activity',
            'owner_id' => 100, 'usage_type' => 'video', 'status' => 'active', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->andReturnTrue();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);
        $service = app(MediaReadService::class);

        try {
            $service->read($this->admin->id, 'course_activity', 100, 'video', 'transcript', 'ko');
            $this->fail();
        } catch (MediaReadException $e) {
            $this->assertSame('locale_unavailable', $e->errorCode);
        }

        $row = DB::table('media_transcripts')->where('media_file_id', $media->id)->first();
        DB::table('media_transcripts')->where('id', $row->id)->update(['status' => 'archived']);
        try {
            $service->read($this->admin->id, 'course_activity', 100, 'video', 'transcript', 'vi');
            $this->fail();
        } catch (MediaReadException $e) {
            $this->assertSame('archived', $e->errorCode);
        }
        $archived = $service->read($this->admin->id, 'course_activity', 100, 'video', 'transcript', 'vi', $row->processing_version, $row->source_fingerprint);
        $this->assertSame('archived', $archived[0]['status']);
        try {
            $service->read($this->admin->id, 'course_activity', 100, 'video', 'transcript', 'vi', $row->processing_version, str_repeat('0', 64));
            $this->fail();
        } catch (MediaReadException $e) {
            $this->assertSame('revision_mismatch', $e->errorCode);
        }
        try {
            $service->read($this->admin->id, 'course_activity', 100, 'video', 'transcript', 'vi', 'missing-version');
            $this->fail();
        } catch (MediaReadException $e) {
            $this->assertSame('revision_unavailable', $e->errorCode);
        }
    }

    public function test_owner_authorization_denial_precedes_media_lookup(): void
    {
        $media = $this->uploadVideo();
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'owner_type' => 'course_activity',
            'owner_id' => 999, 'usage_type' => 'video', 'status' => 'active', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->once()->andReturnFalse();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);
        try {
            app(MediaReadService::class)->read($this->admin->id, 'course_activity', 999, 'video', 'transcript', 'vi');
            $this->fail('Unauthorized read was accepted.');
        } catch (MediaReadException $exception) {
            $this->assertSame('unauthorized', $exception->errorCode);
        }
        $audit = DB::table('media_access_logs')->where('media_file_id', $media->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('denied', json_decode($audit->metadata, true)['decision']);
    }

    public function test_structured_extraction_persists_atomic_regions_and_media_read_returns_structure(): void
    {
        config([
            'media.processing.providers.structured_extraction' => 'fake',
            'media.processing.versions.structured_extraction' => 'fake-structured-v1',
        ]);
        $media = $this->uploadDocument();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'structured_extraction',
            ['locale' => 'vi', 'structure' => 'layout'], $this->admin->id
        );

        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'structured_extraction',
            'status' => 'ready', 'output_type' => 'extracted_region',
        ]);
        $structuredJob = DB::table('media_processing_jobs')
            ->where('media_file_id', $media->id)
            ->where('job_type', 'structured_extraction')
            ->firstOrFail();
        $this->assertSame([
            'pages_with_text' => 1,
            'pages_with_regions' => 1,
            'pages_text_without_structure' => [],
        ], json_decode($structuredJob->metadata, true)['structure_coverage']);
        // heading, paragraph, formula co toan tu, formula khong co toan tu:
        // vung thu tu van duoc giu du no khong sinh evidence child.
        $this->assertSame(4, DB::table('media_extracted_regions')->where('media_file_id', $media->id)->count());

        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->andReturnTrue();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);

        $units = app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', 120, 'document', 'region', 'vi'
        );
        $this->assertSame('heading', $units[0]['structure']['role']);
        $this->assertSame(1, $units[0]['structure']['reading_order']);
        $this->assertSame('normal', $units[0]['structure']['text_quality']);

        DB::table('media_extracted_regions')->where('media_file_id', $media->id)
            ->orderBy('id')->limit(1)->update([
                'metadata' => json_encode(['text_quality' => 'low'], JSON_THROW_ON_ERROR),
            ]);
        $units = app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', 120, 'document', 'region', 'vi'
        );
        $this->assertSame('low', $units[0]['structure']['text_quality']);

        // Revision fake nay khong sinh crop. `null` khong mo ho: crop la
        // tat-ca-hoac-khong-co trong mot revision.
        $this->assertNull($units[0]['structure']['crop']);

        DB::table('media_extracted_regions')->where('media_file_id', $media->id)
            ->orderBy('id')->limit(1)->update([
                'bbox_x' => 0.1, 'bbox_y' => 0.1, 'bbox_width' => 0.2, 'bbox_height' => 0.2,
                'crop_storage_key' => 'tenants/1/media/'.$media->id.'/regions/fp/v1/vi/1-1.png',
                'crop_mime_type' => 'image/png', 'crop_width' => 320, 'crop_height' => 200,
                'crop_bytes' => 45907,
            ]);

        $units = app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', 120, 'document', 'region', 'vi'
        );
        $crop = $units[0]['structure']['crop'];
        $this->assertSame([320, 200, 45907], [$crop['width'], $crop['height'], $crop['bytes']]);
        $this->assertNull($crop['delivery_url'], 'Khong duoc ky URL khi consumer khong xin.');

        $units = app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', 120, 'document', 'region', 'vi',
            null, null, 'ai', [], null, true
        );
        $this->assertNotNull($units[0]['structure']['crop']['delivery_url']);

        $audit = DB::table('media_access_logs')->where('media_file_id', $media->id)->latest('id')->first();
        $this->assertTrue(json_decode($audit->metadata, true)['include_crop']);

        // `page` cung la selector; § 8 buoc audit tra loi duoc "doc trang nao".
        app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', 120, 'document', 'region', 'vi',
            null, null, 'ai', [], 1
        );
        $audit = DB::table('media_access_logs')->where('media_file_id', $media->id)->latest('id')->first();
        $this->assertSame(1, json_decode($audit->metadata, true)['page']);

        try {
            app(MediaReadService::class)->read(
                $this->admin->id, 'course_activity', 120, 'document', 'extracted_text', 'vi',
                null, null, 'ai', [], null, true
            );
            $this->fail('include_crop was accepted for a non-region content type.');
        } catch (MediaReadException $exception) {
            $this->assertSame('unsupported_source', $exception->errorCode);
        }
    }

    public function test_structured_limit_failure_rolls_back_every_table_for_the_revision(): void
    {
        config([
            'media.processing.providers.structured_extraction' => 'fake',
            'media.processing.versions.structured_extraction' => 'fake-structured-limit-v1',
            'media.processing.structured_extraction.max_regions_per_page' => 1,
        ]);
        $media = $this->uploadDocument();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'structured_extraction',
            ['locale' => 'vi', 'structure' => 'layout'], $this->admin->id
        );

        $job = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'structured_extraction')->first();
        $this->assertSame('failed', $job->status);
        $this->assertSame('structured_extraction_too_large', $job->error_code);
        foreach (['media_extracted_texts', 'media_extracted_regions', 'media_extracted_tables'] as $table) {
            $this->assertSame(0, DB::table($table)->where('media_file_id', $media->id)
                ->where('processing_version', $job->processing_version)->count(), $table);
        }
        $this->assertSame(0, DB::table('media_table_cells')->count());
    }

    public function test_structured_extraction_uses_the_owner_approved_production_resource_defaults(): void
    {
        $this->assertSame(100, config('media.processing.structured_extraction.max_regions_per_page'));
        $this->assertSame(5000, config('media.processing.structured_extraction.max_regions_per_document'));
        $this->assertSame(200000, config('media.processing.structured_extraction.max_table_cells_per_document'));
        $this->assertSame(500000, config('media.processing.structured_extraction.max_extracted_characters'));
    }

    public function test_media_read_fails_closed_when_exact_usage_slot_is_ambiguous(): void
    {
        $first = $this->uploadVideo('lesson.mp4');
        $second = $this->uploadVideo('second.mp4');
        foreach ([$first, $second] as $media) {
            DB::table('media_file_usages')->insert([
                'customer_id' => $this->customerId, 'media_file_id' => $media->id,
                'owner_type' => 'course_activity', 'owner_id' => 121,
                'usage_type' => 'video', 'status' => 'active', 'created_by' => $this->admin->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->andReturnTrue();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);

        try {
            app(MediaReadService::class)->read($this->admin->id, 'course_activity', 121, 'video', 'transcript', 'vi');
            $this->fail('Ambiguous exact usage slot was accepted.');
        } catch (MediaReadException $exception) {
            $this->assertSame('ambiguous_source', $exception->errorCode);
        }
    }

    /**
     * Checkbox tren form upload la opt-in. Khong tick thi luong xu ly giu nguyen
     * hanh vi cu — day la nhanh quan trong hon, vi no la mac dinh cua moi upload.
     */
    public function test_document_upload_without_the_structured_checkbox_runs_no_structured_job(): void
    {
        $media = $this->uploadDocument();
        app(MediaProcessingOrchestrator::class)
            ->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);

        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'ocr', 'status' => 'ready',
        ]);
        $this->assertDatabaseMissing('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'structured_extraction',
        ]);
        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'status' => 'ready']);
    }

    public function test_docling_processing_version_changes_with_text_quality_and_ocr_language_semantics(): void
    {
        $media = $this->uploadDocument();
        app(MediaProcessingOrchestrator::class)
            ->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);

        config([
            'media.processing.providers.structured_extraction' => 'docling_local',
            'media.processing.versions.structured_extraction' => 'docling-local-v1',
            'media.processing.structured_extraction.text_symbol_ratio_max' => 0.2,
        ]);
        $orchestrator = app(MediaProcessingOrchestrator::class);
        $parameters = ['locale' => 'vi', 'structure' => 'layout'];
        $baseline = $orchestrator->versionFor('structured_extraction', $media, $parameters);

        config(['media.processing.structured_extraction.text_symbol_ratio_max' => 0.25]);
        $thresholdChanged = $orchestrator->versionFor('structured_extraction', $media, $parameters);

        config([
            'media.processing.structured_extraction.text_symbol_ratio_max' => 0.2,
            'media.processing.structured_extraction.crop_ocr_languages.vi' => 'vie+eng',
        ]);
        $packsChanged = $orchestrator->versionFor('structured_extraction', $media, $parameters);

        $this->assertNotSame($baseline, $thresholdChanged);
        $this->assertNotSame($baseline, $packsChanged);
        $this->assertLessThanOrEqual(100, strlen($baseline));

        config([
            'media.processing.structured_extraction.crop_ocr_languages.vi' => 'vie',
            'media.processing.structured_extraction.text_quality_min_letters' => 12,
        ]);
        $letterControlChanged = $orchestrator->versionFor('structured_extraction', $media, $parameters);
        $this->assertNotSame($baseline, $letterControlChanged);
    }

    public function test_document_upload_with_the_structured_checkbox_adds_the_structured_job(): void
    {
        $media = $this->uploadDocument();
        app(MediaProcessingOrchestrator::class)
            ->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id, true);

        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'ocr', 'status' => 'ready',
        ]);
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id,
            'job_type' => 'structured_extraction',
            'output_profile' => 'locale=vi;structure=layout',
        ]);
    }

    /**
     * Structured extraction la opt-in, khong phai required profile: no that bai
     * khong duoc lam file mat 'ready' va khong duoc chan delivery.
     */
    public function test_a_failed_structured_job_does_not_take_the_document_out_of_ready(): void
    {
        config(['media.processing.providers.structured_extraction' => 'unconfigured']);

        $media = $this->uploadDocument();
        app(MediaProcessingOrchestrator::class)
            ->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id, true);

        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id,
            'job_type' => 'structured_extraction',
            'status' => 'failed',
            'error_code' => 'provider_unavailable',
        ]);
        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'status' => 'ready']);
    }

    /**
     * Ba test tren goi thang orchestrator, nen chung dong bang logic chu chua dong
     * bang duong truyen. Ba test duoi di qua HTTP that: form -> controller ->
     * usage metadata -> MediaService -> orchestrator. Do la lop ma checkbox thuc su
     * song, va la lop da nhieu lan xanh o service roi hong o boundary.
     */
    public function test_http_document_upload_with_checkbox_persists_flag_and_enqueues_structured_job(): void
    {
        config([
            'media.processing.providers.structured_extraction' => 'fake',
            'media.processing.versions.structured_extraction' => 'fake-structured-http-v1',
        ]);
        [$templateId, $lessonId] = $this->courseFixture();

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
                'structured_extraction' => '1',
            ]))
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_structured_queued_notice'))
            ->assertSeeText(__('lf.LF_course_template_activity_structured_ready'))
            ->assertSeeText(__('lf.LF_course_template_activity_structured_ready_help'))
            ->assertSessionHasNoErrors();

        $usage = DB::table('media_file_usages')->where('owner_type', 'course_activity')->latest('id')->firstOrFail();
        $this->assertTrue((bool) (json_decode($usage->metadata, true)['structured_extraction'] ?? false));

        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id,
            'job_type' => 'structured_extraction',
            'output_profile' => 'locale=vi;structure=layout',
        ]);
    }

    public function test_http_multilingual_document_preserves_full_profile_when_enqueuing_structured_job(): void
    {
        config([
            'media.processing.providers.structured_extraction' => 'fake',
            'media.processing.versions.structured_extraction' => 'fake-structured-multilingual-v1',
        ]);
        [$templateId, $lessonId] = $this->courseFixture();

        $this->actingAs($this->admin)->post(
            $this->activityUrl($templateId, $lessonId),
            $this->documentActivityPayload([
                'processing_locale' => null,
                'processing_locales' => ['vi', 'ko'],
                'structured_extraction' => '1',
            ]),
        )->assertRedirect()->assertSessionHasNoErrors();

        $usage = DB::table('media_file_usages')->where('owner_type', 'course_activity')->latest('id')->firstOrFail();
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id,
            'job_type' => 'structured_extraction',
            'output_profile' => 'locales=ko,vi;structure=layout',
            'status' => 'ready',
        ]);
    }

    public function test_http_structured_failure_shows_a_non_blocking_admin_warning(): void
    {
        config(['media.processing.providers.structured_extraction' => 'unconfigured']);
        [$templateId, $lessonId] = $this->courseFixture();

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
                'structured_extraction' => '1',
            ]))
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_structured_failed'))
            ->assertSeeText(__('lf.LF_course_template_activity_structured_failed_provider_help'));

        $usage = DB::table('media_file_usages')->where('owner_type', 'course_activity')->latest('id')->firstOrFail();
        $this->assertDatabaseHas('media_files', ['id' => $usage->media_file_id, 'status' => 'ready']);
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id,
            'job_type' => 'structured_extraction',
            'status' => 'failed',
            'error_code' => 'provider_unavailable',
        ]);
    }

    public function test_http_document_upload_without_checkbox_enqueues_no_structured_job(): void
    {
        [$templateId, $lessonId] = $this->courseFixture();

        $this->actingAs($this->admin)
            ->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload())
            ->assertSessionHasNoErrors();

        $usage = DB::table('media_file_usages')->where('owner_type', 'course_activity')->latest('id')->firstOrFail();
        $this->assertFalse((bool) (json_decode($usage->metadata, true)['structured_extraction'] ?? false));

        $this->assertDatabaseMissing('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id, 'job_type' => 'structured_extraction',
        ]);
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id, 'job_type' => 'ocr',
        ]);
    }

    public function test_http_document_upload_redirect_renders_while_media_is_still_processing(): void
    {
        Queue::fake();
        [$templateId, $lessonId] = $this->courseFixture();

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
                'structured_extraction' => '1',
            ]))
            ->assertOk()
            ->assertSeeText('Tai lieu bai hoc')
            ->assertSeeText(__('lf.LF_course_template_activity_structured_queued_notice'))
            ->assertSessionHasNoErrors();

        $usage = DB::table('media_file_usages')
            ->where('owner_type', 'course_activity')
            ->latest('id')
            ->firstOrFail();

        $this->assertDatabaseHas('media_files', [
            'id' => $usage->media_file_id,
            'status' => 'processing',
        ]);
    }

    /**
     * Checkbox chi hien voi document, nhung request thi gia mao duoc. Controller
     * phai ep co ve false theo file type, khong tin vao input.
     */
    public function test_http_audio_upload_forging_the_checkbox_creates_no_structured_job(): void
    {
        [$templateId, $lessonId] = $this->courseFixture();

        $this->actingAs($this->admin)
            ->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
                'activity_type' => 'audio',
                'activity_document_file' => null,
                'activity_audio_file' => UploadedFile::fake()->create('lesson.mp3', 24, 'audio/mpeg'),
                'structured_extraction' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $usage = DB::table('media_file_usages')->where('owner_type', 'course_activity')->latest('id')->firstOrFail();
        $this->assertFalse((bool) (json_decode($usage->metadata, true)['structured_extraction'] ?? false));
        $this->assertDatabaseMissing('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id, 'job_type' => 'structured_extraction',
        ]);
    }

    public function test_http_audio_upload_accepts_multiple_languages_and_enqueues_one_timeline(): void
    {
        config([
            'media.processing.providers.speech_to_text' => 'fake',
            'media.processing.versions.speech_to_text' => 'fake-stt-http-v1',
        ]);
        $probe = Mockery::mock(MediaMetadataProbe::class);
        $probe->shouldReceive('durationSeconds')->once()->andReturn(3);
        $this->app->instance(MediaMetadataProbe::class, $probe);
        [$templateId, $lessonId] = $this->courseFixture();

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
                'title' => 'Audio tieng Han',
                'activity_type' => 'audio',
                'activity_document_file' => null,
                'activity_audio_file' => UploadedFile::fake()->create('lesson.mp3', 24, 'audio/mpeg'),
                'processing_locale' => null,
                'processing_locales' => ['vi', 'ko'],
                'speech_to_text' => '1',
            ]))
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_stt_queued_notice'))
            ->assertSeeText(__('lf.LF_course_template_activity_stt_ready'))
            ->assertSeeText(__('lf.LF_course_template_activity_stt_ready_help'))
            ->assertSessionHasNoErrors();

        $usage = DB::table('media_file_usages')->where('usage_type', 'audio')->latest('id')->firstOrFail();
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id, 'job_type' => 'speech_to_text',
            'provider' => 'fake', 'status' => 'ready',
            'output_profile' => 'diarization=off;locales=ko,vi',
        ]);
        $this->assertDatabaseHas('media_transcripts', [
            'media_file_id' => $usage->media_file_id, 'locale' => 'mul',
            'locator_type' => 'timespan', 'status' => 'ready',
        ]);
        $job = DB::table('media_processing_jobs')->where('media_file_id', $usage->media_file_id)
            ->where('job_type', 'speech_to_text')->firstOrFail();
        $this->assertSame(['ko', 'vi'], DB::table('media_processing_job_locales')
            ->where('processing_job_id', $job->id)->orderBy('ordinal')->pluck('locale')->all());
    }

    public function test_http_audio_transcription_failure_is_visible_but_audio_remains_ready(): void
    {
        config(['media.processing.providers.speech_to_text' => 'unconfigured']);
        [$templateId, $lessonId] = $this->courseFixture();

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
                'title' => 'Audio khong co provider',
                'activity_type' => 'audio',
                'activity_document_file' => null,
                'activity_audio_file' => UploadedFile::fake()->create('lesson.mp3', 24, 'audio/mpeg'),
                'processing_locale' => 'ko',
                'speech_to_text' => '1',
            ]))
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_stt_failed'))
            ->assertSeeText(__('lf.LF_course_template_activity_stt_failed_provider_help'));

        $usage = DB::table('media_file_usages')->where('usage_type', 'audio')->latest('id')->firstOrFail();
        $this->assertDatabaseHas('media_files', ['id' => $usage->media_file_id, 'status' => 'ready']);
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id, 'job_type' => 'speech_to_text',
            'status' => 'failed', 'error_code' => 'provider_unavailable',
        ]);
    }

    public function test_http_audio_upload_without_transcription_needs_no_locale_and_creates_no_stt_job(): void
    {
        [$templateId, $lessonId] = $this->courseFixture();

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
                'title' => 'Audio chi de nghe',
                'activity_type' => 'audio',
                'activity_document_file' => null,
                'activity_audio_file' => UploadedFile::fake()->create('listen-only.mp3', 24, 'audio/mpeg'),
                'processing_locale' => null,
                'speech_to_text' => null,
            ]))
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_stt_disabled'))
            ->assertSeeText(__('lf.LF_course_template_activity_stt_disabled_help'))
            ->assertDontSeeText(__('lf.LF_course_template_activity_stt_queued_notice'))
            ->assertSessionHasNoErrors();

        $usage = DB::table('media_file_usages')->where('usage_type', 'audio')->latest('id')->firstOrFail();
        $metadata = json_decode($usage->metadata, true);
        $this->assertFalse((bool) $metadata['speech_to_text']);
        $this->assertNull($metadata['processing_locale']);
        $this->assertDatabaseMissing('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id,
            'job_type' => 'speech_to_text',
        ]);
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id,
            'job_type' => 'virus_scan',
        ]);
    }

    public function test_audio_form_defaults_transcription_on_and_explains_both_choices(): void
    {
        [$templateId, $lessonId] = $this->courseFixture();

        $this->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities/create")
            ->assertOk()
            ->assertSee('name="speech_to_text"', false)
            ->assertSee('checked', false)
            ->assertSeeText('Tự động phiên âm nội dung audio')
            ->assertSeeText('Không tick: hệ thống chỉ lưu và quét an toàn file');
    }

    public function test_video_form_shows_opt_in_and_named_qualification_status(): void
    {
        config([
            'media.processing.video_qualification.required' => true,
            'media.processing.video_qualification.evidence_path' => '/missing/evidence.json',
        ]);
        [$templateId, $lessonId] = $this->courseFixture();

        $this->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities/create")
            ->assertOk()
            ->assertSee('name="video_speech_to_text"', false)
            ->assertSee('disabled', false)
            ->assertSeeText(__('lf.LF_course_template_activity_video_stt_option'))
            ->assertSeeText(__('lf.LF_media_processing_audio_limits', ['minutes' => 120, 'gib' => 1]))
            ->assertSeeText(__('lf.LF_media_processing_document_limits', ['pages' => 100, 'ocr_pages' => 100, 'regions' => 100, 'total' => 5000]))
            ->assertSeeText(__('lf.LF_media_processing_processing_requirements'))
            ->assertSeeText(__('lf.LF_media_processing_video_limits', ['minutes' => 90, 'gib' => 1]))
            ->assertSeeText(__('lf.LF_course_template_activity_video_stt_qualification_evidence_missing'));
    }

    public function test_video_ui_translations_exist_in_both_languages_and_never_render_raw_keys(): void
    {
        [$templateId, $lessonId] = $this->courseFixture();
        foreach (['vi', 'en'] as $locale) {
            foreach (['max_file', 'max_duration', 'help', 'queued_notice', 'failed_limit_help',
                'qualification_feature_disabled', 'qualification_local_test', 'qualification_evidence_missing',
                'qualification_evidence_invalid', 'qualification_evidence_expired', 'qualification_identity_mismatch',
                'qualification_qualified'] as $suffix) {
                $key = 'lf.LF_course_template_activity_video_stt_'.$suffix;
                $this->assertTrue(app('translator')->hasForLocale($key, $locale), "$locale missing $key");
                $this->assertNotSame($key, __($key, [], $locale));
            }
        }
        $this->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities/create")
            ->assertOk()
            ->assertDontSee('lf.LF_course_template_activity_video_stt_', false)
            ->assertSee('<details', false)
            ->assertSeeText(__('lf.LF_media_processing_details'));
    }

    public function test_video_limit_failure_displays_specific_warning_without_claiming_partial_transcription(): void
    {
        config(['media.processing.video_qualification.required' => false]);
        Queue::fake();
        [$templateId, $lessonId] = $this->courseFixture();
        $this->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
            'title' => 'Video over processing limit',
            'activity_type' => 'video',
            'activity_document_file' => null,
            'activity_video_file' => UploadedFile::fake()->create('over-limit.mp4', 32, 'video/mp4'),
            'processing_locale' => 'vi',
            'video_speech_to_text' => '1',
        ]))->assertSessionHasNoErrors();

        $usage = DB::table('media_file_usages')->where('usage_type', 'video')->latest('id')->firstOrFail();
        $this->assertSame(1, DB::table('media_processing_jobs')
            ->where('media_file_id', $usage->media_file_id)->where('job_type', 'speech_to_text')
            ->update(['status' => 'failed', 'error_code' => 'video_limit_exceeded']));

        $this->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_video_stt_failed_limit_help', ['minutes' => 90, 'gib' => 1]))
            ->assertDontSeeText(__('lf.LF_course_template_activity_stt_failed_help'));
        $this->assertDatabaseMissing('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id, 'job_type' => 'caption',
        ]);
        foreach ([
            'unsupported_source' => 'lf.LF_media_processing_unsupported',
            'transcript_invalid' => 'lf.LF_media_processing_invalid',
            'audio_extraction_limit_exceeded' => 'lf.LF_media_processing_extraction',
            'video_stt_disabled' => 'lf.LF_course_template_activity_video_stt_qualification_feature_disabled',
        ] as $error => $message) {
            DB::table('media_processing_jobs')->where('media_file_id', $usage->media_file_id)
                ->where('job_type', 'speech_to_text')->update(['error_code' => $error]);
            $this->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure")
                ->assertOk()->assertSeeText(__($message));
        }
    }

    public function test_unqualified_video_request_uploads_but_creates_no_stt_or_caption_job(): void
    {
        config([
            'media.processing.video_qualification.required' => true,
            'media.processing.video_qualification.evidence_path' => '/missing/evidence.json',
        ]);
        [$templateId, $lessonId] = $this->courseFixture();

        $this->followingRedirects()->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
            'title' => 'Video unqualified',
            'activity_type' => 'video',
            'activity_document_file' => null,
            'activity_video_file' => UploadedFile::fake()->create('lesson.mp4', 32, 'video/mp4'),
            'processing_locale' => 'vi',
            'video_speech_to_text' => '1',
        ]))
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_video_stt_qualification_evidence_missing'))
            ->assertSeeText(__('lf.LF_course_template_activity_stt_unqualified'))
            ->assertSessionHasNoErrors();

        $usage = DB::table('media_file_usages')->where('usage_type', 'video')->latest('id')->firstOrFail();
        $metadata = json_decode($usage->metadata, true);
        $this->assertTrue($metadata['speech_to_text_requested']);
        $this->assertFalse($metadata['speech_to_text']);
        $this->assertSame('evidence_missing', $metadata['video_stt_qualification']);
        $this->assertDatabaseMissing('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id,
            'job_type' => 'speech_to_text',
        ]);
        $this->assertDatabaseMissing('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id,
            'job_type' => 'caption',
        ]);
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id,
            'job_type' => 'virus_scan',
        ]);
    }

    public function test_qualified_video_multilingual_opt_in_enqueues_one_stt_and_deferred_mul_caption(): void
    {
        config([
            'media.processing.video_qualification.required' => false,
            'media.processing.providers.speech_to_text' => 'fake',
            'media.processing.versions.speech_to_text' => 'fake-video-http-v1',
        ]);
        $probe = Mockery::mock(MediaMetadataProbe::class);
        $probe->shouldReceive('durationSeconds')->once()->andReturn(3);
        $this->app->instance(MediaMetadataProbe::class, $probe);
        [$templateId, $lessonId] = $this->courseFixture();

        $this->followingRedirects()->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
            'title' => 'Video qualified',
            'activity_type' => 'video',
            'activity_document_file' => null,
            'activity_video_file' => UploadedFile::fake()->create('qualified.mp4', 32, 'video/mp4'),
            'processing_locale' => null,
            'processing_locales' => ['vi', 'ko'],
            'video_speech_to_text' => '1',
        ]))
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_video_stt_queued_notice'))
            ->assertSeeText(__('lf.LF_course_template_activity_stt_ready'))
            ->assertSessionHasNoErrors();

        $usage = DB::table('media_file_usages')->where('usage_type', 'video')->latest('id')->firstOrFail();
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id,
            'job_type' => 'speech_to_text',
            'status' => 'ready',
            'output_profile' => 'diarization=off;locales=ko,vi',
        ]);
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id,
            'job_type' => 'caption',
            'output_profile' => 'format=vtt;locale=mul;locales=ko,vi',
        ]);
        $this->assertDatabaseHas('media_transcripts', ['media_file_id' => $usage->media_file_id, 'locale' => 'mul']);
        $this->assertDatabaseHas('media_captions', ['media_file_id' => $usage->media_file_id, 'locale' => 'mul']);
    }

    public function test_video_without_opt_in_needs_no_locale_and_creates_no_stt_job(): void
    {
        [$templateId, $lessonId] = $this->courseFixture();

        $this->followingRedirects()->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
            'title' => 'Video playback only',
            'activity_type' => 'video',
            'activity_document_file' => null,
            'activity_video_file' => UploadedFile::fake()->create('playback.mp4', 32, 'video/mp4'),
            'processing_locale' => null,
            'video_speech_to_text' => null,
        ]))
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_stt_disabled'))
            ->assertSessionHasNoErrors();

        $usage = DB::table('media_file_usages')->where('usage_type', 'video')->latest('id')->firstOrFail();
        $metadata = json_decode($usage->metadata, true);
        $this->assertFalse($metadata['speech_to_text_requested']);
        $this->assertFalse($metadata['speech_to_text']);
        $this->assertNull($metadata['processing_locale']);
        $this->assertDatabaseMissing('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id,
            'job_type' => 'speech_to_text',
        ]);
    }

    public function test_unchecked_deduplicated_audio_still_shows_an_existing_transcript(): void
    {
        config([
            'media.processing.providers.speech_to_text' => 'fake',
            'media.processing.versions.speech_to_text' => 'fake-stt-existing-v1',
        ]);
        [$templateId, $lessonId] = $this->courseFixture();
        $media = $this->uploadDocument();
        DB::table('media_files')->where('id', $media->id)
            ->update(['file_type' => 'audio', 'status' => 'ready', 'processing_locale' => null, 'duration_seconds' => 3]);
        DB::table('media_processing_jobs')->where('media_file_id', $media->id)->delete();
        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $templateId,
            'template_lesson_id' => $lessonId, 'title' => 'Audio deduplicated',
            'activity_type' => 'audio', 'sort_order' => 1,
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => $activityId,
            'usage_type' => 'audio', 'status' => 'active',
            'metadata' => json_encode(['speech_to_text' => false, 'processing_locale' => null]),
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity(
            $this->customerId, $media->id, 'ko', $this->admin->id
        );

        $this->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_stt_ready'))
            ->assertDontSeeText(__('lf.LF_course_template_activity_stt_disabled'));
    }

    public function test_http_non_pdf_document_forging_the_checkbox_creates_no_structured_job(): void
    {
        [$templateId, $lessonId] = $this->courseFixture();

        $this->actingAs($this->admin)
            ->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
                'activity_document_file' => UploadedFile::fake()->createWithContent('bai-hoc.txt', 'noi dung trang'),
                'structured_extraction' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $usage = DB::table('media_file_usages')->where('owner_type', 'course_activity')->latest('id')->firstOrFail();
        $this->assertFalse((bool) (json_decode($usage->metadata, true)['structured_extraction'] ?? false));
        $this->assertDatabaseMissing('media_processing_jobs', [
            'media_file_id' => $usage->media_file_id, 'job_type' => 'structured_extraction',
        ]);
    }

    public function test_http_document_upload_requires_a_supported_processing_locale(): void
    {
        [$templateId, $lessonId] = $this->courseFixture();

        $this->actingAs($this->admin)
            ->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
                'processing_locale' => null,
            ]))
            ->assertSessionHasErrors('processing_locales');

        $this->assertSame(0, DB::table('media_file_usages')->where('owner_type', 'course_activity')->count());
    }

    public function test_http_document_upload_rejects_a_locale_not_supported_in_phase_one(): void
    {
        [$templateId, $lessonId] = $this->courseFixture();

        $this->actingAs($this->admin)
            ->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload([
                'processing_locale' => 'en-US',
            ]))
            ->assertSessionHasErrors('processing_locale');

        $this->assertSame(0, DB::table('media_file_usages')->where('owner_type', 'course_activity')->count());
    }

    public function test_http_pdf_over_page_limit_returns_a_friendly_error_before_persistence(): void
    {
        [$templateId, $lessonId] = $this->courseFixture();
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldReceive('run')->once()->andReturn("Pages: 500\n");
        $this->app->instance(DocumentProcessRunner::class, $runner);

        $message = 'PDF có 500 trang. Hệ thống chỉ hỗ trợ tối đa 100 trang cho bước trích xuất text/OCR, dù có hoặc không bật Docling. Tệp cũng phải không vượt quá 1 GB. Vui lòng chia tài liệu thành các phần nhỏ hơn.';

        $this->actingAs($this->admin)
            ->post($this->activityUrl($templateId, $lessonId), $this->documentActivityPayload())
            ->assertSessionHasErrors([
                'activity_document_file' => $message,
            ]);

        $this->assertSame(0, DB::table('media_file_usages')->where('owner_type', 'course_activity')->count());
        $this->assertSame(0, DB::table('media_processing_jobs')->count());
    }

    /**
     * Media audio cu khong co job STT nao. Bao "Cho xu ly" o day la noi sai:
     * khong co gi duoc xep hang, nen admin refresh mai mot thu khong bao gio toi.
     * Cung loai loi ma `structure_unavailable` sinh ra de chan.
     */
    public function test_activity_with_audio_but_no_speech_job_is_not_shown_as_queued(): void
    {
        [$templateId, $lessonId] = $this->courseFixture();
        $media = $this->uploadDocument();
        DB::table('media_files')->where('id', $media->id)
            ->update(['file_type' => 'audio', 'status' => 'ready', 'processing_locale' => null]);
        DB::table('media_processing_jobs')->where('media_file_id', $media->id)->delete();

        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $templateId,
            'template_lesson_id' => $lessonId, 'title' => 'Phat am 1',
            'activity_type' => 'audio', 'sort_order' => 1,
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => $activityId,
            'usage_type' => 'audio', 'status' => 'active', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_stt_absent'))
            ->assertSeeText(__('lf.LF_course_template_activity_stt_absent_help'))
            ->assertDontSeeText(__('lf.LF_course_template_activity_stt_pending_help'));
    }

    public function test_admin_can_initialize_first_transcription_job_for_legacy_audio_once(): void
    {
        Queue::fake();
        [$templateId, $lessonId] = $this->courseFixture();
        $media = $this->uploadDocument();
        DB::table('media_files')->where('id', $media->id)
            ->update(['file_type' => 'audio', 'status' => 'ready', 'processing_locale' => null]);
        DB::table('media_processing_jobs')->where('media_file_id', $media->id)->delete();
        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $templateId,
            'template_lesson_id' => $lessonId, 'title' => 'Audio legacy',
            'activity_type' => 'audio', 'sort_order' => 1,
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => $activityId,
            'usage_type' => 'audio', 'status' => 'active', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities/{$activityId}/initialize-transcription";

        $this->post($url, ['processing_locale' => 'ko'])->assertSessionHas('success');

        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'processing_locale' => 'ko']);
        $usageMetadata = json_decode((string) DB::table('media_file_usages')
            ->where('media_file_id', $media->id)->where('usage_type', 'audio')->value('metadata'), true);
        $this->assertTrue((bool) $usageMetadata['speech_to_text']);
        $this->assertSame('ko', $usageMetadata['processing_locale']);
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id,
            'job_type' => 'speech_to_text',
            'status' => 'pending',
            'output_profile' => 'diarization=off;locale=ko',
        ]);

        $this->post($url, ['processing_locale' => 'vi'])->assertSessionHasErrors('processing_locale');
        $this->assertSame(1, DB::table('media_processing_jobs')
            ->where('media_file_id', $media->id)->where('job_type', 'speech_to_text')->count());
        $this->assertSame('ko', DB::table('media_files')->where('id', $media->id)->value('processing_locale'));
    }

    public function test_audio_detach_cancels_pending_stt_and_reattach_creates_a_new_generation(): void
    {
        Queue::fake();
        [$media, $activityId] = $this->audioCourseUsage();
        $orchestrator = app(MediaProcessingOrchestrator::class);
        $orchestrator->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        $first = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->firstOrFail();

        app(MediaService::class)->detachUsage($media->id, 'course_activity', $activityId, 'audio');
        $this->assertDatabaseHas('media_processing_jobs', ['id' => $first->id, 'status' => 'cancelled']);

        DB::table('media_file_usages')->where('media_file_id', $media->id)->where('usage_type', 'audio')
            ->update(['status' => 'active', 'updated_at' => now()]);
        $orchestrator->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id,
            speechToText: true, reattach: true);

        $second = DB::table('media_processing_jobs')->where('supersedes_job_id', $first->id)->firstOrFail();
        $this->assertSame(2, (int) $second->dispatch_generation);
        $this->assertSame($first->correlation_id, $second->correlation_id);
        $this->assertSame('pending', $second->status);
    }

    public function test_audio_callback_after_detach_cannot_persist_transcript_or_resurrect_job(): void
    {
        config(['media.processing.providers.speech_to_text' => 'fake']);
        [$media] = $this->audioCourseUsage();
        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andReturnUsing(function () use ($media): array {
            DB::table('media_file_usages')->where('media_file_id', $media->id)->where('usage_type', 'audio')
                ->update(['status' => 'detached', 'updated_at' => now()]);

            return ['units' => [['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => 'late']]];
        });
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);

        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'speech_to_text', ['locale' => 'vi', 'diarization' => 'off'], $this->admin->id
        );

        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'speech_to_text',
            'status' => 'failed', 'error_code' => 'source_unavailable', 'error_message' => 'source_unavailable',
        ]);
    }

    public function test_video_detach_cancels_pending_stt_and_caption_and_reattach_creates_a_new_generation(): void
    {
        Queue::fake();
        [$media, $activityId] = $this->videoCourseUsage();
        $orchestrator = app(MediaProcessingOrchestrator::class);
        $orchestrator->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        $first = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->firstOrFail();

        app(MediaService::class)->detachUsage($media->id, 'course_activity', $activityId, 'video');
        $this->assertDatabaseHas('media_processing_jobs', ['id' => $first->id, 'status' => 'cancelled']);

        DB::table('media_file_usages')->where('media_file_id', $media->id)->where('usage_type', 'video')
            ->update(['status' => 'active', 'updated_at' => now()]);
        $orchestrator->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id,
            speechToText: true, reattach: true);

        $second = DB::table('media_processing_jobs')->where('supersedes_job_id', $first->id)->firstOrFail();
        $this->assertSame(2, (int) $second->dispatch_generation);
        $this->assertSame($first->correlation_id, $second->correlation_id);
    }

    public function test_video_callback_after_detach_cannot_persist_transcript_or_caption(): void
    {
        config(['media.processing.providers.speech_to_text' => 'fake']);
        [$media] = $this->videoCourseUsage();
        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andReturnUsing(function () use ($media): array {
            DB::table('media_file_usages')->where('media_file_id', $media->id)->where('usage_type', 'video')
                ->update(['status' => 'detached', 'updated_at' => now()]);

            return ['units' => [['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => 'late']]];
        });
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);

        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'speech_to_text', ['locale' => 'vi', 'diarization' => 'off'], $this->admin->id
        );

        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
        $this->assertSame(0, DB::table('media_captions')->where('media_file_id', $media->id)->count());
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'speech_to_text',
            'status' => 'failed', 'error_code' => 'source_unavailable',
        ]);
    }

    public function test_video_transcript_timespan_cannot_exceed_source_duration(): void
    {
        config(['media.processing.providers.speech_to_text' => 'fake']);
        [$media] = $this->videoCourseUsage();
        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andReturn(['units' => [
            ['locator_type' => 'timespan', 'locator_value' => '0-3001', 'text' => 'outside video'],
        ]]);
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);

        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'speech_to_text', ['locale' => 'vi', 'diarization' => 'off'], $this->admin->id
        );

        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'speech_to_text',
            'status' => 'failed', 'error_code' => 'transcript_invalid',
        ]);
        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
        $this->assertSame(0, DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'caption')->count());
    }

    public function test_video_caption_callback_after_detach_cannot_publish_asset(): void
    {
        config([
            'media.processing.providers.speech_to_text' => 'fake',
            'media.processing.providers.caption' => 'fake',
        ]);
        [$media] = $this->videoCourseUsage();
        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldReceive('process')->twice()->andReturnUsing(function ($source, $job) use ($media): array {
            if ($job->job_type === 'speech_to_text') {
                return ['units' => [['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => 'ready']]];
            }
            DB::table('media_file_usages')->where('media_file_id', $media->id)->where('usage_type', 'video')
                ->update(['status' => 'detached', 'updated_at' => now()]);

            return ['storage_key' => 'late-caption.vtt', 'transcript_processing_version' => 'fake-video-v1'];
        });
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);

        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'speech_to_text', ['locale' => 'vi', 'diarization' => 'off'], $this->admin->id
        );

        $this->assertSame(0, DB::table('media_captions')->where('media_file_id', $media->id)->count());
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'caption',
            'status' => 'failed', 'error_code' => 'source_unavailable',
        ]);
    }

    public function test_audio_transcript_confidence_is_validated_persisted_and_returned_in_temporal_order(): void
    {
        config(['media.processing.providers.speech_to_text' => 'fake']);
        [$media, $activityId] = $this->audioCourseUsage();
        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andReturn(['units' => [
            ['locator_type' => 'timespan', 'locator_value' => '500-1000', 'text' => 'first', 'confidence_score' => 91.234],
            ['locator_type' => 'timespan', 'locator_value' => '1000-1500', 'text' => 'second', 'confidence_score' => 88],
        ]]);
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'speech_to_text', ['locale' => 'vi', 'diarization' => 'off'], $this->admin->id
        );
        $job = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->firstOrFail();
        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->once()->andReturnTrue();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);
        $units = app(MediaReadService::class)->read($this->admin->id, 'course_activity', $activityId, 'audio', 'transcript', 'vi');

        $this->assertSame(['500-1000', '1000-1500'], array_column(array_column($units, 'locator'), 'value'));
        $this->assertSame(91.23, (float) $units[0]['confidence']);
        $this->assertSame(88.0, (float) $units[1]['confidence']);
    }

    public function test_audio_invalid_confidence_fails_entire_revision(): void
    {
        config(['media.processing.providers.speech_to_text' => 'fake']);
        [$media] = $this->audioCourseUsage();
        $provider = Mockery::mock(FakeMediaProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andReturn(['units' => [
            ['locator_type' => 'timespan', 'locator_value' => '0-1000', 'text' => 'invalid', 'confidence_score' => 101],
        ]]);
        $this->app->instance(FakeMediaProcessingProvider::class, $provider);
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'speech_to_text', ['locale' => 'vi', 'diarization' => 'off'], $this->admin->id
        );
        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'speech_to_text',
            'status' => 'failed', 'error_code' => 'transcript_invalid',
        ]);
        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
    }

    public function test_real_local_audio_provider_persists_timespans_and_media_read_returns_them(): void
    {
        $fixture = getenv('LF_REAL_AUDIO_FIXTURE') ?: null;
        if (! is_string($fixture) || ! is_file($fixture)) {
            $this->markTestSkipped('Set LF_REAL_AUDIO_FIXTURE to a non-PII speech fixture.');
        }
        config([
            'media.processing.providers.speech_to_text' => 'faster_whisper_local',
            'media.processing.versions.speech_to_text' => 'faster-whisper-small-local-v1',
            'media.processing.speech_to_text.timeout_seconds' => 3300,
        ]);
        [$media, $activityId] = $this->audioCourseUsage();
        $bytes = file_get_contents($fixture);
        $this->assertIsString($bytes);
        Storage::disk($media->storage_disk)->put($media->storage_key, $bytes);
        DB::table('media_files')->where('id', $media->id)->update([
            'file_size_bytes' => strlen($bytes), 'checksum' => hash('sha256', $bytes),
            'duration_seconds' => 30, 'processing_locale' => null,
        ]);

        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'speech_to_text', ['locale' => 'en', 'diarization' => 'off'], $this->admin->id
        );
        $job = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->firstOrFail();
        $this->assertSame('ready', $job->status, (string) $job->error_code);
        $this->assertGreaterThanOrEqual(2, DB::table('media_transcripts')->where('processing_job_id', $job->id)->count());

        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->once()->andReturnTrue();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);
        $units = app(MediaReadService::class)->read($this->admin->id, 'course_activity', $activityId, 'audio', 'transcript', 'en');
        $this->assertGreaterThanOrEqual(2, count($units));
        $this->assertSame('timespan', $units[0]['locator']['type']);
        $this->assertStringContainsString('learning', strtolower(implode(' ', array_column($units, 'text'))));
        $audit = DB::table('media_access_logs')->where('media_file_id', $media->id)
            ->where('action', 'read_derived')->firstOrFail();
        $this->assertSame('allowed', json_decode((string) $audit->metadata, true)['decision']);
    }

    public function test_real_local_video_pipeline_persists_transcript_caption_and_media_read_outputs(): void
    {
        $fixture = getenv('LF_REAL_VIDEO_FIXTURE') ?: null;
        if (! is_string($fixture) || ! is_file($fixture)) {
            $this->markTestSkipped('Set LF_REAL_VIDEO_FIXTURE to a non-PII speech video fixture.');
        }
        config([
            'media.processing.providers.speech_to_text' => 'faster_whisper_local',
            'media.processing.providers.caption' => 'transcript_vtt',
            'media.processing.speech_to_text.video_enabled' => true,
            'media.processing.video_qualification.required' => false,
        ]);
        [$media, $activityId] = $this->videoCourseUsage();
        $bytes = file_get_contents($fixture);
        $this->assertIsString($bytes);
        $duration = app(MediaMetadataProbe::class)->durationSeconds(
            new UploadedFile($fixture, basename($fixture), 'video/mp4', null, true), 'video'
        );
        $this->assertNotNull($duration);
        Storage::disk($media->storage_disk)->put($media->storage_key, $bytes);
        DB::table('media_files')->where('id', $media->id)->update([
            'file_size_bytes' => strlen($bytes), 'checksum' => hash('sha256', $bytes),
            'duration_seconds' => $duration, 'processing_locale' => 'en',
        ]);

        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'speech_to_text', ['locale' => 'en', 'diarization' => 'off'], $this->admin->id
        );
        $stt = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->firstOrFail();
        $caption = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'caption')->firstOrFail();
        $this->assertSame('ready', $stt->status, (string) $stt->error_code);
        $this->assertSame('ready', $caption->status, (string) $caption->error_code);

        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->twice()->andReturnTrue();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);
        $transcript = app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', $activityId, 'video', 'transcript', 'en'
        );
        $asset = app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', $activityId, 'video', 'caption_asset', 'en'
        );
        $this->assertGreaterThanOrEqual(2, count($transcript));
        $this->assertSame('timespan', $transcript[0]['locator']['type']);
        $this->assertNull($asset[0]['locator']);
        $captionRow = DB::table('media_captions')->where('processing_job_id', $caption->id)->firstOrFail();
        $vtt = Storage::disk($media->storage_disk)->get($captionRow->storage_key);
        $this->assertStringStartsWith("WEBVTT\n\n", $vtt);
        $this->assertSame(substr_count($vtt, ' --> '), count($transcript));
        $this->assertFalse(is_dir(app(VideoAudioWorkspace::class)->directory($media, $stt)));
    }

    public function test_audio_recovery_redelivers_only_expired_pending_and_fails_only_expired_processing(): void
    {
        Queue::fake();
        [$media] = $this->audioCourseUsage();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity(
            $this->customerId, $media->id, 'vi', $this->admin->id
        );
        $pending = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->firstOrFail();
        DB::table('media_processing_jobs')->where('id', $pending->id)->update(['updated_at' => now()->subHours(2)]);

        $this->artisan('media:recover-audio-processing --customer='.$this->customerId)->assertSuccessful();
        Queue::assertPushed(ProcessMediaProcessingJob::class, fn ($job) => $job->processingJobId === $pending->id);

        DB::table('media_processing_jobs')->where('id', $pending->id)->update([
            'status' => 'processing', 'started_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
        ]);
        $this->artisan('media:recover-audio-processing --customer='.$this->customerId)->assertSuccessful();
        $this->assertDatabaseHas('media_processing_jobs', [
            'id' => $pending->id, 'status' => 'failed', 'error_code' => 'provider_timeout',
            'error_message' => 'provider_timeout',
        ]);
    }

    public function test_speech_to_text_recovery_also_handles_expired_video_jobs(): void
    {
        Queue::fake();
        [$media] = $this->videoCourseUsage();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity(
            $this->customerId, $media->id, 'vi', $this->admin->id
        );
        $pending = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'speech_to_text')->firstOrFail();
        DB::table('media_processing_jobs')->where('id', $pending->id)->update(['updated_at' => now()->subHours(2)]);

        $this->artisan('media:recover-audio-processing --customer='.$this->customerId)->assertSuccessful();
        Queue::assertPushed(ProcessMediaProcessingJob::class, fn ($job) => $job->processingJobId === $pending->id);

        DB::table('media_processing_jobs')->where('id', $pending->id)->update([
            'status' => 'processing', 'started_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
        ]);
        $this->artisan('media:recover-audio-processing --customer='.$this->customerId)->assertSuccessful();
        $this->assertDatabaseHas('media_processing_jobs', [
            'id' => $pending->id, 'status' => 'failed', 'error_code' => 'provider_timeout',
        ]);
    }

    public function test_requested_docling_without_a_job_is_not_shown_as_queued(): void
    {
        [$templateId, $lessonId] = $this->courseFixture();
        $media = $this->uploadDocument();
        DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'structured_extraction')->delete();
        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $templateId,
            'template_lesson_id' => $lessonId, 'title' => 'Document legacy',
            'activity_type' => 'document', 'sort_order' => 1,
            'created_by' => $this->admin->id, 'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10),
        ]);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => $activityId,
            'usage_type' => 'document', 'status' => 'active',
            'metadata' => json_encode(['structured_extraction' => true]),
            'created_by' => $this->admin->id, 'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10),
        ]);

        $this->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_structured_absent'))
            ->assertSeeText(__('lf.LF_course_template_activity_structured_absent_help'))
            ->assertDontSeeText(__('lf.LF_course_template_activity_structured_pending_help'));
    }

    public function test_recent_docling_request_without_a_job_is_shown_as_initializing(): void
    {
        [$templateId, $lessonId] = $this->courseFixture();
        $media = $this->uploadDocument();
        DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'structured_extraction')->delete();
        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $templateId,
            'template_lesson_id' => $lessonId, 'title' => 'Document initializing',
            'activity_type' => 'document', 'sort_order' => 1,
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => $activityId,
            'usage_type' => 'document', 'status' => 'active',
            'metadata' => json_encode(['structured_extraction' => true]),
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_structured_initializing'))
            ->assertSeeText(__('lf.LF_course_template_activity_structured_initializing_help'))
            ->assertDontSeeText(__('lf.LF_course_template_activity_structured_absent_help'));
    }

    private function activityUrl(int $templateId, int $lessonId): string
    {
        return "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities";
    }

    /** @param array<string, mixed> $override */
    private function documentActivityPayload(array $override = []): array
    {
        return array_filter(array_merge([
            'title' => 'Tai lieu bai hoc',
            'activity_type' => 'document',
            'learning_phases' => ['anytime'],
            'is_required' => '1',
            'completion_rule' => 'view',
            'is_preview' => '0',
            'unlock_rule' => 'none',
            'processing_locale' => 'vi',
            'activity_document_file' => UploadedFile::fake()->create('bai-hoc.pdf', 24, 'application/pdf'),
        ], $override), fn ($value) => $value !== null);
    }

    /** @return array{int, int} */
    private function courseFixture(): array
    {
        $now = now();
        $categoryId = DB::table('core_course_categories')->insertGetId([
            'customer_id' => $this->customerId, 'parent_id' => null, 'name' => 'General',
            'slug' => 'general', 'description' => null, 'thumbnail_image' => null, 'banner_image' => null,
            'sort_order' => 1, 'is_featured' => false, 'meta_title' => null, 'meta_description' => null,
            'meta_keywords' => null, 'status' => 'active', 'created_by' => $this->admin->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $this->customerId, 'category_id' => $categoryId, 'title' => 'Template',
            'short_description' => 'Short', 'description' => 'Detail', 'publisher_name' => 'LearnForge',
            'intro_video_source' => null, 'intro_image_media_file_id' => null, 'intro_video_media_file_id' => null,
            'difficulty_level' => 'beginner', 'estimated_minutes_per_lesson' => 90, 'estimated_lesson_count' => null,
            'lesson_count' => 1, 'meta_title' => 'T', 'meta_description' => 'D', 'meta_keywords' => 'k',
            'working_revision' => 1, 'status' => 'active', 'created_by' => $this->admin->id,
            'last_version_published_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $lessonId = DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $templateId, 'template_section_id' => null,
            'title' => 'Lesson', 'short_description' => 'S', 'description' => 'D', 'sort_order' => 0,
            'is_preview' => true, 'duration_seconds' => 0, 'activity_count' => 0, 'unlock_rule' => 'none',
            'unlock_after_lesson_id' => null, 'unlock_at' => null, 'created_by' => $this->admin->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return [$templateId, $lessonId];
    }

    /**
     * Trang co text nhung khong co region la su vang mat im lang: consumer hoi
     * region se nhan mang rong va khong phan biet duoc "trang trang" voi "trang co
     * noi dung nhung layout model truot". Test nay dong bang viec do lech duoc ghi
     * lai thanh so, khong de no chi ton tai duoi dang phat hien tinh co.
     */
    public function test_structured_job_records_pages_that_have_text_but_no_structure(): void
    {
        $media = $this->uploadDocument();
        DB::table('media_extracted_texts')->where('media_file_id', $media->id)->delete();
        $fingerprint = str_repeat('a', 64);
        $ocrJobId = DB::table('media_processing_jobs')->insertGetId([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'job_type' => 'ocr', 'status' => 'ready', 'attempt' => 1,
            'idempotency_key' => 'coverage-ocr', 'correlation_id' => (string) Str::uuid(),
            'source_fingerprint' => $fingerprint, 'processing_version' => 'ocr-v1',
            'output_profile' => 'layout=preserve;locale=vi', 'output_profile_hash' => str_repeat('c', 64),
            'provider' => 'fake', 'output_type' => 'extracted_text', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([1, 2, 3] as $page) {
            DB::table('media_extracted_texts')->insert([
                'customer_id' => $this->customerId, 'media_file_id' => $media->id,
                'processing_job_id' => $ocrJobId, 'locale' => 'vi', 'locator_type' => 'page',
                'locator_value' => (string) $page, 'sequence' => $page, 'text' => 'noi dung trang '.$page,
                'char_count' => 16, 'confidence_score' => null, 'extraction_method' => 'ocr',
                'provider' => 'fake', 'processing_version' => 'ocr-v1',
                'source_fingerprint' => $fingerprint, 'status' => 'ready',
                'metadata' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Provider gia lap chi nhan dien cau truc o trang 1 va 3; trang 2 co text
        // nhung khong co region — dung hinh dang da do tren tai lieu scan that.
        $jobId = DB::table('media_processing_jobs')->insertGetId([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'job_type' => 'structured_extraction', 'status' => 'processing', 'attempt' => 1,
            'metadata' => json_encode(['canonical_processing_job_id' => $ocrJobId]),
            'idempotency_key' => 'coverage-probe', 'correlation_id' => (string) Str::uuid(),
            'source_fingerprint' => $fingerprint, 'processing_version' => 'structured-v1',
            'output_profile' => 'locale=vi;structure=layout', 'output_profile_hash' => str_repeat('b', 64),
            'provider' => 'fake', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $job = DB::table('media_processing_jobs')->where('id', $jobId)->first();
        $coverage = app(StructuredExtractionPersistenceService::class)->persist(
            $this->customerId, $media, $job, 'vi', [
                'regions' => [
                    ['locator_value' => '1#1', 'page' => 1, 'ordinal' => 1, 'reading_order' => 1,
                        'role' => 'paragraph', 'text' => 'a', 'bbox' => null, 'extraction_method' => 'ocr'],
                    ['locator_value' => '3#1', 'page' => 3, 'ordinal' => 1, 'reading_order' => 2,
                        'role' => 'paragraph', 'text' => 'b', 'bbox' => null, 'extraction_method' => 'ocr'],
                ],
                'tables' => [],
            ]
        )['coverage'];

        $this->assertSame(3, $coverage['pages_with_text']);
        $this->assertSame(2, $coverage['pages_with_regions']);
        $this->assertSame([2], $coverage['pages_text_without_structure']);
    }

    public function test_structured_character_budget_includes_canonical_text_from_a_different_processing_version(): void
    {
        config(['media.processing.structured_extraction.max_extracted_characters' => 16]);
        $media = $this->uploadDocument();
        $fingerprint = str_repeat('d', 64);
        $ocrJobId = DB::table('media_processing_jobs')->insertGetId([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'job_type' => 'ocr', 'status' => 'ready', 'attempt' => 1,
            'idempotency_key' => 'budget-ocr', 'correlation_id' => (string) Str::uuid(),
            'source_fingerprint' => $fingerprint, 'processing_version' => 'ocr-budget-v1',
            'output_profile' => 'layout=preserve;locale=vi', 'output_profile_hash' => str_repeat('e', 64),
            'provider' => 'fake', 'output_type' => 'extracted_text', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_extracted_texts')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'processing_job_id' => $ocrJobId, 'locale' => 'vi', 'locator_type' => 'page',
            'locator_value' => '1', 'sequence' => 1, 'text' => '1234567890123456', 'char_count' => 16,
            'extraction_method' => 'ocr', 'provider' => 'fake', 'processing_version' => 'ocr-budget-v1',
            'source_fingerprint' => $fingerprint, 'status' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $structuredJobId = DB::table('media_processing_jobs')->insertGetId([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'job_type' => 'structured_extraction', 'status' => 'processing', 'attempt' => 1,
            'metadata' => json_encode(['canonical_processing_job_id' => $ocrJobId]),
            'idempotency_key' => 'budget-structured', 'correlation_id' => (string) Str::uuid(),
            'source_fingerprint' => $fingerprint, 'processing_version' => 'structured-budget-v1',
            'output_profile' => 'locale=vi;structure=layout', 'output_profile_hash' => str_repeat('f', 64),
            'provider' => 'fake', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $structuredJob = DB::table('media_processing_jobs')->where('id', $structuredJobId)->first();

        $this->expectExceptionMessage('structured_extraction_too_large');
        app(StructuredExtractionPersistenceService::class)->persist(
            $this->customerId, $media, $structuredJob, 'vi', [
                'regions' => [[
                    'locator_value' => '1#1', 'page' => 1, 'ordinal' => 1, 'reading_order' => 1,
                    'role' => 'paragraph', 'text' => 'x', 'bbox' => null, 'extraction_method' => 'ocr',
                ]],
                'tables' => [],
            ]
        );
    }

    /**
     * Muc dich cua `structure_unavailable`: consumer phai phan biet duoc "trang
     * trang" voi "trang co noi dung nhung layout model truot". Tra mang rong cho
     * ca hai la thu khien AI khong biet minh dang thieu.
     */
    public function test_d4_live_coverage_does_not_count_blank_canonical_pages(): void
    {
        $fixture = $this->structuredCoverageFixture();
        DB::table('media_extracted_texts')->where('media_file_id', $fixture['media_id'])->where('locator_value', '2')
            ->update(['text' => '', 'char_count' => 0]);
        $coverage = app(MediaReadService::class)->structureCoverage($this->admin->id, 'course_activity', $fixture['activity_id'], 'document', 'vi');
        $this->assertSame(2, $coverage['pages_with_text']);
        $this->assertSame([], $coverage['pages_text_without_structure']);
    }

    public function test_read_returns_structure_unavailable_for_a_page_with_text_but_no_region(): void
    {
        $fixture = $this->structuredCoverageFixture();

        try {
            app(MediaReadService::class)->read(
                $this->admin->id, 'course_activity', $fixture['activity_id'], 'document',
                'region', 'vi', null, null, 'ai', [], 2
            );
            $this->fail('Trang 2 khong co region nen phai bao structure_unavailable.');
        } catch (MediaReadException $exception) {
            $this->assertSame('structure_unavailable', $exception->errorCode);
        }

        // Trang co cau truc van doc binh thuong qua cung mot tham so.
        $units = app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', $fixture['activity_id'], 'document',
            'region', 'vi', null, null, 'ai', [], 1
        );
        $this->assertCount(1, $units);
        $this->assertSame(1, $units[0]['locator']['page'] ?? 1);
    }

    public function test_structure_coverage_is_computed_live_from_output_tables(): void
    {
        $fixture = $this->structuredCoverageFixture();

        // A ready row from another source revision must not inflate current
        // coverage merely because tenant/media/locale match.
        DB::table('media_extracted_texts')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $fixture['media_id'],
            'processing_job_id' => null, 'locale' => 'vi', 'locator_type' => 'page',
            'locator_value' => '4', 'sequence' => 4, 'text' => 'old revision', 'char_count' => 12,
            'confidence_score' => null, 'extraction_method' => 'ocr', 'provider' => 'fake',
            'processing_version' => 'ocr-old', 'source_fingerprint' => str_repeat('e', 64),
            'status' => 'ready', 'metadata' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_extracted_regions')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $fixture['media_id'],
            'processing_job_id' => null, 'locale' => 'vi', 'locator_type' => 'region',
            'locator_value' => '4#1', 'page' => 4, 'ordinal' => 1, 'reading_order' => 1,
            'role' => 'paragraph', 'text' => 'old region', 'char_count' => 10,
            'bbox_x' => null, 'bbox_y' => null, 'bbox_width' => null, 'bbox_height' => null,
            'confidence_score' => null, 'extraction_method' => 'ocr', 'provider' => 'fake',
            'processing_version' => 'structured-old', 'source_fingerprint' => str_repeat('e', 64),
            'status' => 'ready', 'metadata' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $coverage = app(MediaReadService::class)->structureCoverage(
            $this->admin->id, 'course_activity', $fixture['activity_id'], 'document', 'vi'
        );

        $this->assertSame(3, $coverage['pages_with_text']);
        $this->assertSame(2, $coverage['pages_with_regions']);
        $this->assertSame([2], $coverage['pages_text_without_structure']);
        $this->assertDatabaseHas('media_access_logs', [
            'media_file_id' => $fixture['media_id'],
            'action' => 'read_derived',
        ]);
    }

    public function test_denied_structure_coverage_read_is_audited_when_media_can_be_resolved(): void
    {
        $fixture = $this->structuredCoverageFixture();
        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->once()->andReturnFalse();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);

        try {
            app(MediaReadService::class)->structureCoverage(
                $this->admin->id, 'course_activity', $fixture['activity_id'], 'document', 'vi'
            );
            $this->fail('Denied structure coverage read must fail closed.');
        } catch (MediaReadException $exception) {
            $this->assertSame('unauthorized', $exception->errorCode);
        }

        $log = DB::table('media_access_logs')->where('media_file_id', $fixture['media_id'])
            ->orderByDesc('id')->firstOrFail();
        $metadata = json_decode($log->metadata, true);
        $this->assertSame('denied', $metadata['decision']);
        $this->assertSame('unauthorized', $metadata['error_code']);
        $this->assertSame('region', $metadata['content_type']);
    }

    public function test_deleting_media_purges_derived_content_and_storage_assets(): void
    {
        $fixture = $this->structuredCoverageFixture();
        $mediaId = $fixture['media_id'];
        $jobCount = DB::table('media_processing_jobs')->where('media_file_id', $mediaId)->count();
        DB::table('media_access_logs')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $mediaId,
            'user_id' => $this->admin->id, 'action' => 'read_derived',
            'source_type' => 'ai', 'source_id' => null, 'accessed_at' => now(),
            'metadata' => json_encode(['decision' => 'allowed']),
        ]);
        $accessLogCount = DB::table('media_access_logs')->where('media_file_id', $mediaId)->count();
        DB::table('media_file_usages')->where('media_file_id', $mediaId)->update(['status' => 'archived']);

        DB::table('media_transcripts')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $mediaId, 'locale' => 'ko',
            'provider' => 'fake', 'status' => 'ready', 'text' => '삭제할 전사',
            'processing_job_id' => null, 'processing_version' => 'retention-v1',
            'source_fingerprint' => str_repeat('a', 64), 'locator_type' => 'timespan',
            'locator_value' => '0-1000', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $captionKey = 'tenants/'.$this->customerId.'/captions/delete-me.vtt';
        Storage::disk('media_local')->put($captionKey, 'WEBVTT');
        DB::table('media_captions')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $mediaId, 'locale' => 'ko',
            'caption_type' => 'vtt', 'storage_key' => $captionKey, 'status' => 'ready',
            'processing_job_id' => null, 'processing_version' => 'retention-v1',
            'source_fingerprint' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(MediaService::class)->deleteMedia($mediaId);

        $this->assertDatabaseHas('media_files', ['id' => $mediaId, 'status' => 'deleted']);
        foreach (['media_extracted_texts', 'media_extracted_regions', 'media_transcripts', 'media_captions'] as $table) {
            $this->assertSame(0, DB::table($table)->where('media_file_id', $mediaId)->count(), $table);
        }
        Storage::disk('media_local')->assertMissing($captionKey);
        $this->assertSame($jobCount, DB::table('media_processing_jobs')->where('media_file_id', $mediaId)->count(),
            'Processing jobs la provenance va phai duoc giu sau deletion.');
        $this->assertSame($accessLogCount, DB::table('media_access_logs')->where('media_file_id', $mediaId)->count(),
            'Access logs la audit append-only va phai duoc giu sau deletion.');
    }

    /**
     * Ba trang co text canonical, nhung chi trang 1 va 3 co region — dung hinh dang
     * da do tren tai lieu scan that.
     *
     * @return array{activity_id: int, media_id: int}
     */
    private function structuredCoverageFixture(): array
    {
        $activityId = 4242;
        $media = $this->uploadDocument();
        DB::table('media_extracted_texts')->where('media_file_id', $media->id)->delete();
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'owner_type' => 'course_activity',
            'owner_id' => $activityId, 'usage_type' => 'document', 'status' => 'active',
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_files')->where('id', $media->id)->update(['processing_locale' => 'vi']);

        $jobId = DB::table('media_processing_jobs')->insertGetId([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'job_type' => 'structured_extraction', 'status' => 'ready', 'attempt' => 1,
            'idempotency_key' => 'coverage-read-probe', 'correlation_id' => (string) Str::uuid(),
            'source_fingerprint' => str_repeat('c', 64), 'processing_version' => 'fake-v1',
            'output_profile' => 'locale=vi;structure=layout', 'output_profile_hash' => str_repeat('d', 64),
            'provider' => 'fake', 'created_by' => $this->admin->id, 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([1, 2, 3] as $page) {
            DB::table('media_extracted_texts')->insert([
                'customer_id' => $this->customerId, 'media_file_id' => $media->id,
                'processing_job_id' => null, 'locale' => 'vi', 'locator_type' => 'page',
                'locator_value' => (string) $page, 'sequence' => $page, 'text' => 'noi dung trang '.$page,
                'char_count' => 16, 'confidence_score' => null, 'extraction_method' => 'ocr',
                'provider' => 'fake', 'processing_version' => 'ocr-v1',
                'source_fingerprint' => str_repeat('c', 64), 'status' => 'ready',
                'metadata' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ([1, 3] as $index => $page) {
            DB::table('media_extracted_regions')->insert([
                'customer_id' => $this->customerId, 'media_file_id' => $media->id,
                'processing_job_id' => $jobId, 'locale' => 'vi', 'locator_type' => 'region',
                'locator_value' => $page.'#1', 'page' => $page, 'ordinal' => 1,
                'reading_order' => $index + 1, 'role' => 'paragraph', 'text' => 'vung trang '.$page,
                'char_count' => 13, 'bbox_x' => null, 'bbox_y' => null, 'bbox_width' => null,
                'bbox_height' => null, 'confidence_score' => null, 'extraction_method' => 'ocr',
                'provider' => 'fake', 'processing_version' => 'fake-v1',
                'source_fingerprint' => str_repeat('c', 64), 'status' => 'ready',
                'metadata' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->andReturnTrue();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);

        return ['activity_id' => $activityId, 'media_id' => (int) $media->id];
    }

    private function uploadVideo(string $name = 'lesson.mp4'): object
    {
        $size = $name === 'lesson.mp4' ? 32 : 33;
        $media = app(MediaService::class)->upload(UploadedFile::fake()->create($name, $size, 'video/mp4'), [
            'file_type' => 'video', 'module' => 'course', 'entity_type' => 'activities', 'entity_id' => 99, 'purpose' => 'video',
        ], $this->admin->id);
        DB::table('media_files')->where('id', $media->id)->update(['duration_seconds' => 3]);
        $media->duration_seconds = 3;
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => 999999, 'usage_type' => 'video',
            'status' => 'active', 'metadata' => json_encode(['speech_to_text' => true, 'processing_locale' => 'vi']),
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $media;
    }

    /** @return array{object, int} */
    private function audioCourseUsage(): array
    {
        [$templateId, $lessonId] = $this->courseFixture();
        $media = $this->uploadDocument();
        DB::table('media_processing_jobs')->where('media_file_id', $media->id)->delete();
        DB::table('media_file_usages')->where('media_file_id', $media->id)->delete();
        DB::table('media_files')->where('id', $media->id)->update([
            'file_type' => 'audio', 'mime_type' => 'audio/wav', 'extension' => 'wav',
            'duration_seconds' => 3, 'status' => 'ready', 'processing_locale' => null,
        ]);
        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $templateId,
            'template_lesson_id' => $lessonId, 'title' => 'Audio review',
            'activity_type' => 'audio', 'sort_order' => 1,
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => $activityId, 'usage_type' => 'audio',
            'status' => 'active', 'metadata' => json_encode(['speech_to_text' => true, 'processing_locale' => 'vi']),
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$media, $activityId];
    }

    /** @return array{object, int} */
    private function videoCourseUsage(): array
    {
        [$templateId, $lessonId] = $this->courseFixture();
        $media = $this->uploadVideo();
        DB::table('media_processing_jobs')->where('media_file_id', $media->id)->delete();
        DB::table('media_file_usages')->where('media_file_id', $media->id)->delete();
        DB::table('media_files')->where('id', $media->id)->update([
            'duration_seconds' => 3, 'status' => 'ready', 'processing_locale' => 'vi',
        ]);
        $media = DB::table('media_files')->where('id', $media->id)->firstOrFail();
        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $templateId,
            'template_lesson_id' => $lessonId, 'title' => 'Video review',
            'activity_type' => 'video', 'sort_order' => 1,
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => $activityId, 'usage_type' => 'video',
            'status' => 'active', 'metadata' => json_encode(['speech_to_text' => true, 'processing_locale' => 'vi']),
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$media, $activityId];
    }

    private function uploadDocument(): object
    {
        $media = app(MediaService::class)->upload(UploadedFile::fake()->createWithContent('structured.txt', 'page text'), [
            'file_type' => 'document', 'module' => 'course', 'entity_type' => 'activities',
            'entity_id' => 120, 'purpose' => 'document',
        ], $this->admin->id);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => 120, 'usage_type' => 'document',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $media;
    }
}
