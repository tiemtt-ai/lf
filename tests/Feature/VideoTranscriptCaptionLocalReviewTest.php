<?php

namespace Tests\Feature;

use App\Exceptions\MediaReadException;
use App\Jobs\ProcessMediaProcessingJob;
use App\Models\User;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaReadService;
use App\Services\MediaService;
use App\Services\VideoAudioWorkspace;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Closure Video local: Course video usage → FFmpeg → STT → media_transcripts
 * theo timespan → caption asset VTT → Media Read.
 *
 * Cac test "real" chay FFmpeg THAT va engine STT THAT tren mot video TONG HOP:
 * nen mau navy + giong noi tong hop, khong PII, khong goi provider ngoai. Fixture
 * duoc dung tai cho nen khong co binary video nao bi commit.
 */
class VideoTranscriptCaptionLocalReviewTest extends TestCase
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
            'media.processing.providers.caption' => 'transcript_vtt',
            'media.processing.versions.speech_to_text' => 'faster-whisper-small-video-review-v1',
            'media.processing.versions.caption' => 'transcript-vtt-video-review-v1',
            // Gate mac dinh TAT theo DOC-CONFLICT-0027; bat tuong minh cho local.
            'media.processing.speech_to_text.video_enabled' => true,
            'media.processing.video_qualification.required' => false,
            'media.processing.video_audio.ffmpeg_version' => $this->realFfmpegVersion(),
        ]);
        $this->customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Video Review', 'slug' => 'video-review', 'subdomain' => 'video-review',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::forceCreate([
            'customer_id' => $this->customerId, 'name' => 'Fixture Admin', 'email' => 'video@example.test',
            'password' => bcrypt('fixture'), 'role' => 'customer_admin', 'status' => 'active',
        ]);
        TenantContext::set((object) ['id' => $this->customerId]);
        $this->actingAs($this->admin);
        [$template, $lesson] = $this->courseFixture($this->customerId, $this->admin->id);
        $this->activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $template,
            'template_lesson_id' => $lesson, 'title' => 'Video fixture',
            'activity_type' => 'video', 'sort_order' => 1, 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * E2E day du tren binary that: attach co opt-in → dispatch sau commit →
     * FFmpeg tach audio → STT → transcript timespan → caption VTT → Media Read.
     */
    public function test_real_video_pipeline_from_course_usage_to_transcript_caption_and_read(): void
    {
        $media = $this->uploadVideo($this->requireRealFixture());
        $this->attach($media, 'en');

        $stt = $this->job($media, 'speech_to_text');
        $this->assertSame('ready', $stt->status, (string) $stt->error_code);
        $this->assertSame('transcript', $stt->output_type);
        $this->assertSame(hash('sha256', $media->checksum.':video'), $stt->source_fingerprint,
            'Fingerprint phai la van tay VIDEO GOC, khong phai audio tam.');
        // Identity phai mang ca engine lan extraction profile (Amendment 2.19 § 1).
        $this->assertStringContainsString('+ffmpeg-', $stt->processing_version);
        $this->assertStringContainsString('+stt-', $stt->processing_version);

        $rows = DB::table('media_transcripts')->where('processing_job_id', $stt->id)->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $rows->count());
        $durationMs = (int) DB::table('media_files')->where('id', $media->id)->value('duration_seconds') * 1000;
        $previousEnd = null;
        foreach ($rows as $row) {
            $this->assertSame('ready', $row->status);
            $this->assertSame('timespan', $row->locator_type);
            $this->assertSame('en', $row->locale);
            $this->assertSame($stt->source_fingerprint, $row->source_fingerprint);
            $this->assertSame($stt->processing_version, $row->processing_version);
            $this->assertMatchesRegularExpression('/^(0|[1-9][0-9]*)-(0|[1-9][0-9]*)$/', $row->locator_value);
            [$start, $end] = array_map('intval', explode('-', $row->locator_value));
            $this->assertLessThan($end, $start);
            $this->assertLessThanOrEqual($durationMs, $end, 'Segment khong duoc tro ra ngoai video.');
            if ($previousEnd !== null) {
                $this->assertGreaterThanOrEqual($previousEnd, $start);
            }
            $previousEnd = $end;
        }

        // Caption chi duoc materialize SAU khi transcript commit `ready`.
        $caption = $this->job($media, 'caption');
        $this->assertSame('ready', $caption->status, (string) $caption->error_code);
        $captionRow = DB::table('media_captions')->where('processing_job_id', $caption->id)->firstOrFail();
        $this->assertSame($stt->processing_version, $captionRow->transcript_processing_version);
        $this->assertSame('vtt', $captionRow->caption_type);
        $this->assertSame('en', $captionRow->locale);
        $this->assertSame('ready', $captionRow->status);

        // Storage key phai dinh danh tenant/media/fingerprint/version/locale/format.
        $safe = static fn (string $v): string => (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $v);
        foreach ([(string) $this->customerId, (string) $media->id, $safe($stt->source_fingerprint),
            $safe($caption->processing_version), 'en.vtt'] as $part) {
            $this->assertStringContainsString($part, $captionRow->storage_key);
        }

        $this->assertVttIsWellFormed(
            Storage::disk($media->storage_disk)->get($captionRow->storage_key), $rows->all()
        );

        // Workspace audio tam phai duoc don sau khi chay xong.
        $this->assertFalse(is_dir(app(VideoAudioWorkspace::class)->directory($media, $stt)));

        $transcript = $this->read('transcript', 'en');
        $asset = $this->read('caption_asset', 'en');
        $this->assertSame($rows->pluck('locator_value')->all(),
            array_column(array_column($transcript, 'locator'), 'value'));
        $this->assertCount(1, $asset);
        $this->assertNull($asset[0]['locator'], 'Caption asset khong duoc bia timespan.');
        $this->assertNull($asset[0]['text']);
        $this->assertNotNull($asset[0]['delivery_url']);

        // Ca hai lan doc deu phai duoc audit, va khong ro ri noi dung.
        $logs = DB::table('media_access_logs')->where('media_file_id', $media->id)
            ->where('action', 'read_derived')->get();
        $this->assertGreaterThanOrEqual(2, $logs->count());
        foreach ($logs as $log) {
            $this->assertSame('allowed', json_decode((string) $log->metadata, true)['decision']);
            foreach ($transcript as $unit) {
                $this->assertStringNotContainsString(trim($unit['text']), (string) $log->metadata);
            }
        }
        $this->assertStringNotContainsString('X-Amz-Signature', (string) $logs->last()->metadata);

        $this->assertSame('ready', DB::table('media_files')->where('id', $media->id)->value('status'));
    }

    /** Video hong: FFmpeg fail co ten, khong transcript, khong caption, workspace sach. */
    public function test_a_corrupt_video_fails_extraction_without_output_or_workspace_residue(): void
    {
        $media = $this->uploadVideo(null);
        $this->attach($media, 'en');

        $stt = $this->job($media, 'speech_to_text');
        $this->assertSame('failed', $stt->status);
        $this->assertSame('audio_extraction_failed', $stt->error_code);
        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
        // STT that bai thi caption KHONG duoc materialize — khong tao caption
        // failure gia cho mot phu de khong bao gio co nguon.
        $this->assertSame(0, DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'caption')->count());
        $this->assertFalse(is_dir(app(VideoAudioWorkspace::class)->directory($media, $stt)));
        // Binary van phuc vu duoc.
        $this->assertSame('ready', DB::table('media_files')->where('id', $media->id)->value('status'));
        $this->assertReadError('failed', 'transcript', 'en');
    }

    /** Inventory lech binary that: fail-closed truoc khi chay, khong doan. */
    public function test_an_ffmpeg_inventory_mismatch_fails_closed_before_extraction(): void
    {
        config(['media.processing.video_audio.ffmpeg_version' => '0.0.0-not-installed']);
        $media = $this->uploadVideo($this->requireRealFixture());
        $this->attach($media, 'en');

        $stt = $this->job($media, 'speech_to_text');
        $this->assertSame('failed', $stt->status);
        $this->assertSame('extraction_profile_mismatch', $stt->error_code);
        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
    }

    /** Gate tat sau khi job da vao queue: provider van phai chan. */
    public function test_a_queued_video_job_is_refused_when_the_gate_is_switched_off(): void
    {
        $media = $this->uploadVideo($this->requireRealFixture());
        Queue::fake();
        $this->attach($media, 'en');
        $stt = $this->job($media, 'speech_to_text');
        $this->assertSame('pending', $stt->status);

        config(['media.processing.speech_to_text.video_enabled' => false]);
        (new ProcessMediaProcessingJob($this->customerId, (int) $stt->id))->handle();

        $this->assertSame('failed', $this->job($media, 'speech_to_text')->status);
        $this->assertSame('video_stt_disabled', $this->job($media, 'speech_to_text')->error_code);
        $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media->id)->count());
    }

    /**
     * `processing_version` la VARCHAR(100) va `ffmpeg_version` la chuoi inventory
     * tu do. Tran cot lam MariaDB nem 22001 trong afterCommit cua attachUsage —
     * usage da commit nhung job khong bao gio duoc tao. SQLite khong cuong che do
     * dai nen bai kiem nay do chinh do dai, khong doi database bat ho.
     */
    public function test_a_long_ffmpeg_inventory_string_never_overflows_the_version_column(): void
    {
        config([
            'media.processing.versions.speech_to_text' => 'faster-whisper-1.2.1-small-int8',
            'media.processing.video_audio.ffmpeg_version' => '7:6.1.1-3ubuntu5+really-long-vendor-build-identifier',
        ]);
        $media = $this->uploadVideo($this->requireRealFixture());

        $version = app(MediaProcessingOrchestrator::class)->versionFor(
            'speech_to_text', $media, ['locale' => 'en', 'diarization' => 'off']
        );

        $this->assertLessThanOrEqual(100, strlen($version));
        $this->assertStringStartsWith('video-stt-', $version);
        // Van phai la identity: doi extraction profile thi version phai doi.
        config(['media.processing.video_audio.ffmpeg_version' => '7:6.1.1-3ubuntu5+a-different-vendor-build-identifier']);
        $this->assertNotSame($version, app(MediaProcessingOrchestrator::class)->versionFor(
            'speech_to_text', $media, ['locale' => 'en', 'diarization' => 'off']
        ));
    }

    /** Tenant khac khong doc duoc transcript lan caption asset cua video. */
    public function test_another_tenant_cannot_read_video_transcript_or_caption(): void
    {
        $media = $this->uploadVideo($this->requireRealFixture());
        $this->attach($media, 'en');
        $this->assertSame('ready', $this->job($media, 'caption')->status);
        $this->assertGreaterThanOrEqual(2, count($this->read('transcript', 'en')));

        $other = DB::table('saas_customers')->insertGetId([
            'name' => 'Video Review B', 'slug' => 'video-review-b', 'subdomain' => 'video-review-b',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $intruder = User::forceCreate([
            'customer_id' => $other, 'name' => 'Intruder', 'email' => 'video-intruder@example.test',
            'password' => bcrypt('fixture'), 'role' => 'customer_admin', 'status' => 'active',
        ]);
        TenantContext::set((object) ['id' => $other]);

        foreach (['transcript', 'caption_asset'] as $contentType) {
            try {
                app(MediaReadService::class)->read($intruder->id, 'course_activity', $this->activityId,
                    'video', $contentType, 'en');
                $this->fail('Cross-tenant read cua '.$contentType.' phai bi tu choi.');
            } catch (MediaReadException $exception) {
                $this->assertSame('unauthorized', $exception->errorCode);
            }
        }
        $this->assertSame(0, DB::table('media_access_logs')->where('customer_id', $other)->count());
    }

    /** @param array<int, object> $segments */
    private function assertVttIsWellFormed(string $vtt, array $segments): void
    {
        $this->assertStringStartsWith("WEBVTT\n\n", $vtt, 'WEBVTT phai la byte dau tien; khong BOM.');
        $this->assertStringNotContainsString("\r", $vtt, 'Newline phai la LF.');
        $this->assertSame($vtt, mb_convert_encoding($vtt, 'UTF-8', 'UTF-8'), 'VTT phai la UTF-8 hop le.');
        $this->assertSame(count($segments), substr_count($vtt, ' --> '),
            'Mot transcript segment phai ung mot cue.');

        preg_match_all('/^(\d{2}:\d{2}:\d{2}\.\d{3}) --> (\d{2}:\d{2}:\d{2}\.\d{3})$/m', $vtt, $cues, PREG_SET_ORDER);
        $this->assertCount(count($segments), $cues, 'Moi cue phai dung dinh dang HH:MM:SS.mmm.');
        foreach ($segments as $index => $segment) {
            [$start, $end] = array_map('intval', explode('-', $segment->locator_value));
            $this->assertSame($this->vttTimestamp($start), $cues[$index][1],
                'Cue order phai theo transcript order va giu nguyen moc bat dau.');
            $this->assertSame($this->vttTimestamp($end), $cues[$index][2]);
        }
    }

    private function vttTimestamp(int $ms): string
    {
        return sprintf('%02d:%02d:%02d.%03d', intdiv($ms, 3600000),
            intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $ms % 1000);
    }

    private function realFfmpegVersion(): string
    {
        $binary = (string) config('media.processing.video_audio.ffmpeg_binary');
        if (! is_executable($binary)) {
            return 'undeclared';
        }
        $process = new Process([$binary, '-version']);
        $process->run();

        return preg_match('/^ffmpeg version (\S+)/', $process->getOutput(), $m) === 1 ? $m[1] : 'undeclared';
    }

    private function requireRealFixture(): string
    {
        $configured = getenv('LF_REAL_VIDEO_FIXTURE');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }
        foreach (['python_binary', 'script'] as $key) {
            if (! file_exists((string) config('media.processing.speech_to_text.'.$key))) {
                $this->markTestSkipped('Faster Whisper runtime missing: '.$key);
            }
        }
        if (! is_dir((string) config('media.processing.speech_to_text.model_path'))) {
            $this->markTestSkipped('Faster Whisper model missing.');
        }
        if (! is_executable((string) config('media.processing.video_audio.ffmpeg_binary'))) {
            $this->markTestSkipped('FFmpeg missing at the configured absolute path.');
        }

        return $this->synthesizeFixture();
    }

    /** Video tong hop: nen mau + giong noi tong hop. Khong PII, khong commit. */
    private function synthesizeFixture(): string
    {
        $target = sys_get_temp_dir().'/lf-video-review-fixture.mp4';
        if (is_file($target) && filesize($target) > 0) {
            return $target;
        }
        $finder = new ExecutableFinder;
        $say = $finder->find('say');
        $ffmpeg = (string) config('media.processing.video_audio.ffmpeg_binary');
        if ($say === null) {
            $this->markTestSkipped('Set LF_REAL_VIDEO_FIXTURE, or install `say` to synthesize one.');
        }
        $aiff = sys_get_temp_dir().'/lf-video-review-fixture.aiff';
        (new Process([$say, '-o', $aiff,
            'Welcome to this lesson on learning design. [[slnc 700]] '
            .'In this module we study how learners build durable knowledge. [[slnc 700]] '
            .'The first principle is spaced repetition over many days. [[slnc 700]] '
            .'The second principle is retrieval practice before review.',
        ]))->setTimeout(120)->mustRun();
        (new Process([$ffmpeg, '-y', '-f', 'lavfi', '-i', 'color=c=navy:s=320x240:r=10',
            '-i', $aiff, '-shortest', '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '96k', $target, '-loglevel', 'error']))->setTimeout(180)->mustRun();
        @unlink($aiff);

        return $target;
    }

    /**
     * Mot mp4 CUT DO: giu `ftyp` header nen MIME detection van nhan la video/mp4
     * va upload validation cho qua, nhung FFmpeg khong the decode.
     */
    private function corruptVideoFixture(): string
    {
        $target = sys_get_temp_dir().'/lf-video-review-corrupt.mp4';
        if (! is_file($target) || filesize($target) === 0) {
            file_put_contents($target, substr((string) file_get_contents($this->synthesizeFixture()), 0, 2048));
        }

        return $target;
    }

    private function uploadVideo(?string $fixture): object
    {
        $file = $fixture === null
            ? new UploadedFile($this->corruptVideoFixture(), 'broken.mp4', 'video/mp4', null, true)
            : new UploadedFile($fixture, basename($fixture), 'video/mp4', null, true);

        $media = app(MediaService::class)->upload($file, [
            'file_type' => 'video', 'module' => 'course', 'entity_type' => 'activities',
            'entity_id' => $this->activityId, 'purpose' => 'video',
        ], $this->admin->id);

        if ($fixture === null) {
            // Duration khong probe duoc tu file hong; dat tuong minh de bai kiem
            // dung o buoc FFmpeg chu khong dung som o `corrupt_source`.
            DB::table('media_files')->where('id', $media->id)->update([
                'duration_seconds' => 5, 'mime_type' => 'video/mp4',
            ]);
            $media = DB::table('media_files')->where('id', $media->id)->firstOrFail();
        }

        return $media;
    }

    private function attach(object $media, string $locale): void
    {
        app(MediaService::class)->attachUsage($media->id, 'course_activity', $this->activityId, 'video', [
            'processing_locale' => $locale, 'speech_to_text' => true,
        ]);
    }

    private function job(object $media, string $type): object
    {
        return DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', $type)->orderByDesc('id')->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    private function read(string $contentType, string $locale): array
    {
        return app(MediaReadService::class)->read($this->admin->id, 'course_activity', $this->activityId,
            'video', $contentType, $locale);
    }

    private function assertReadError(string $expected, string $contentType, string $locale): void
    {
        try {
            $this->read($contentType, $locale);
            $this->fail('Read must fail with '.$expected);
        } catch (MediaReadException $exception) {
            $this->assertSame($expected, $exception->errorCode);
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
