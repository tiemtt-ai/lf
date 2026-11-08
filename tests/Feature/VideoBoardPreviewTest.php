<?php

namespace Tests\Feature;

use App\Jobs\ProcessMediaProcessingJob;
use App\Models\User;
use App\Services\DocumentProcessRunner;
use App\Services\MediaService;
use App\Services\VideoBoardPreview;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * LF-Media-Processing-Contract § Video board playback copy: an MP4/MOV whose
 * `moov` index is at the end gets one faststart copy for the LiveClass board.
 */
class VideoBoardPreviewTest extends TestCase
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
            'media.processing.speech_to_text.video_enabled' => false,
            'media.processing.frame_ocr.enabled' => false,
        ]);
        $this->customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Video Board', 'slug' => 'video-board', 'subdomain' => 'video-board',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::forceCreate([
            'customer_id' => $this->customerId, 'name' => 'Fixture Admin', 'email' => 'video-board@example.test',
            'password' => bcrypt('fixture'), 'role' => 'customer_admin', 'status' => 'active',
        ]);
        TenantContext::set((object) ['id' => $this->customerId]);
        $this->actingAs($this->admin);
        $this->activityId = $this->videoActivity();
    }

    // ------------------------------------------------------------------ box order

    public function test_box_order_tells_an_index_at_the_end_from_one_at_the_front(): void
    {
        $ftyp = $this->box('ftyp', 'isom');
        $this->assertTrue(VideoBoardPreview::indexAtEnd($this->memory($ftyp.$this->box('mdat', str_repeat('x', 40)).$this->box('moov', 'm'))));
        $this->assertFalse(VideoBoardPreview::indexAtEnd($this->memory($ftyp.$this->box('moov', 'm').$this->box('mdat', 'x'))));
        // A 64-bit `mdat` size is followed, not misread.
        $large = pack('N', 1).'mdat'.pack('J', 16 + 5).'xxxxx';
        $this->assertTrue(VideoBoardPreview::indexAtEnd($this->memory($ftyp.$large.$this->box('moov', 'm'))));
        // Cannot tell: no `moov`, a box running to the end, a size smaller than its header, a truncated header.
        $this->assertNull(VideoBoardPreview::indexAtEnd($this->memory($ftyp.$this->box('mdat', 'x'))));
        $this->assertNull(VideoBoardPreview::indexAtEnd($this->memory($ftyp.pack('N', 0).'mdat'.'xxxx')));
        $this->assertNull(VideoBoardPreview::indexAtEnd($this->memory($ftyp.pack('N', 4).'mdat')));
        $this->assertNull(VideoBoardPreview::indexAtEnd($this->memory('abc')));
    }

    // ------------------------------------------------------------------ attach

    public function test_attaching_a_video_with_its_index_at_the_end_queues_one_copy_on_the_preview_queue(): void
    {
        Queue::fake();
        $media = $this->storedVideo($this->mp4(faststart: false));
        $this->attach($media);

        $job = $this->copyJob($media);
        $this->assertSame(['pending', 'local_video', 'preset=faststart', 'ffmpeg-faststart-v1'],
            [$job->status, $job->provider, $job->output_profile, $job->processing_version]);
        Queue::assertPushedOn('media-preview', ProcessMediaProcessingJob::class,
            fn (ProcessMediaProcessingJob $pushed): bool => $pushed->processingJobId === (int) $job->id);

        // Saving the Activity again copies nothing twice.
        $this->attach($media);
        $this->assertSame(1, $this->copyJobs($media));
    }

    public function test_a_video_already_fast_or_not_mp4_gets_no_copy(): void
    {
        Queue::fake();
        $fast = $this->storedVideo($this->mp4(faststart: true));
        $this->attach($fast);
        $this->assertSame(0, $this->copyJobs($fast));

        $webm = $this->storedVideo($this->mp4(faststart: false), 'clip.webm', 'video/webm');
        $this->attach($webm, $this->videoActivity());
        $this->assertSame(0, $this->copyJobs($webm));

        $missing = $this->storedVideo(null);
        $this->attach($missing, $this->videoActivity());
        $this->assertSame(0, $this->copyJobs($missing));
    }

    // ------------------------------------------------------------------ run

    public function test_the_copy_moves_the_index_first_and_is_stored_as_a_ready_preview_variant(): void
    {
        $source = $this->mp4(faststart: false);
        $media = $this->storedVideo($source);
        $this->attach($media);

        $job = $this->copyJob($media);
        $this->assertSame('ready', $job->status, (string) $job->error_code);
        $this->assertSame(['variant', strlen($source), 'byte'], [$job->output_type, (int) $job->billable_units, $job->billable_unit_type]);
        $variant = DB::table('media_variants')->where('id', $job->output_id)->first();
        $key = "tenants/{$this->customerId}/media/{$media->id}/previews/ffmpeg-faststart-v1/{$job->id}.mp4";
        $this->assertSame(['preview', 'video/mp4', 'ready', $key, $job->source_fingerprint],
            [$variant->variant_type, $variant->mime_type, $variant->status, $variant->storage_key, $variant->source_fingerprint]);
        $metadata = json_decode($variant->metadata, true);
        $this->assertTrue($metadata['faststart']);
        $this->assertGreaterThan(0, $metadata['duration_ms']);

        $copy = Storage::disk('media_local')->readStream($key);
        $this->assertFalse(VideoBoardPreview::indexAtEnd($copy));
        fclose($copy);
        $this->assertSame((int) $variant->file_size_bytes, Storage::disk('media_local')->size($key));
        // The original is never touched.
        $this->assertSame($source, Storage::disk('media_local')->get($media->storage_key));
        $this->assertSame((int) $variant->id, (int) app(VideoBoardPreview::class)->readyVariant($this->customerId, $media)->id);

        // A new version makes a new copy and archives the old one.
        config(['media.processing.video_preview.version' => 'ffmpeg-faststart-v2']);
        $this->artisan('media:video-faststart', ['--customer' => $this->customerId])->assertSuccessful();
        $newer = $this->copyJob($media);
        $this->assertSame(['ready', 'ffmpeg-faststart-v2'], [$newer->status, $newer->processing_version]);
        $this->assertSame('archived', DB::table('media_variants')->where('id', $variant->id)->value('status'));
    }

    public function test_a_copy_that_loses_its_usage_keeps_no_file(): void
    {
        $this->wrapFfmpeg(function (): void {
            // The Activity drops the video while ffmpeg runs.
            DB::table('media_file_usages')->where('owner_id', $this->activityId)->update(['status' => 'detached']);
        });
        $media = $this->storedVideo($this->mp4(faststart: false));
        $this->attach($media);

        $job = $this->copyJob($media);
        $this->assertSame(['failed', 'source_unavailable'], [$job->status, $job->error_code]);
        $this->assertSame(0, DB::table('media_variants')->where('media_file_id', $media->id)->count());
        $this->assertSame([], Storage::disk('media_local')->allFiles("tenants/{$this->customerId}/media/{$media->id}/previews"));
    }

    public function test_a_broken_video_fails_for_good_and_leaves_no_file(): void
    {
        $this->requireFfmpeg();
        // Box headers say "index at the end", but there is no real video inside.
        $media = $this->storedVideo($this->box('ftyp', 'isom').$this->box('mdat', str_repeat('x', 64)).$this->box('moov', 'broken'));
        $this->attach($media);

        $job = $this->copyJob($media);
        $this->assertSame(['failed', 'corrupt_source'], [$job->status, $job->error_code]);
        $this->assertSame(0, DB::table('media_variants')->where('media_file_id', $media->id)->count());
        $this->assertSame([], Storage::disk('media_local')->allFiles("tenants/{$this->customerId}/media/{$media->id}/previews"));
    }

    public function test_the_command_queues_a_copy_for_a_video_attached_before_and_never_twice(): void
    {
        Queue::fake();
        $media = $this->storedVideo($this->mp4(faststart: false));
        $this->attach($media);
        DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'transcode')->delete();

        $this->artisan('media:video-faststart', ['--customer' => $this->customerId])
            ->expectsOutputToContain('Created 1, retried 0, skipped 0.')->assertSuccessful();
        $this->assertSame('pending', $this->copyJob($media)->status);
        $this->artisan('media:video-faststart', ['--customer' => $this->customerId, '--media' => $media->id])
            ->expectsOutputToContain('Created 0, retried 0, skipped 1.')->assertSuccessful();
        $this->assertSame(1, $this->copyJobs($media));

        // A transient failure is retried; a permanent one is not.
        DB::table('media_processing_jobs')->where('id', $this->copyJob($media)->id)
            ->update(['status' => 'failed', 'error_code' => 'provider_timeout', 'started_at' => now(), 'completed_at' => now()]);
        $this->artisan('media:video-faststart', ['--customer' => $this->customerId])
            ->expectsOutputToContain('retried 1')->assertSuccessful();
        DB::table('media_processing_jobs')->where('id', $this->copyJob($media)->id)
            ->update(['status' => 'failed', 'error_code' => 'corrupt_source', 'started_at' => now(), 'completed_at' => now()]);
        $this->artisan('media:video-faststart', ['--customer' => $this->customerId])
            ->expectsOutputToContain('skipped 1')->assertSuccessful();

        $this->artisan('media:video-faststart')->assertFailed();
    }

    // ------------------------------------------------------------------ helpers

    private function copyJob(object $media): object
    {
        return DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'transcode')
            ->orderByDesc('id')->firstOrFail();
    }

    private function copyJobs(object $media): int
    {
        return DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'transcode')->count();
    }

    private function attach(object $media, ?int $activityId = null): void
    {
        app(MediaService::class)->attachUsage($media->id, 'course_activity', $activityId ?? $this->activityId, 'video',
            ['processing_locale' => null, 'speech_to_text' => false]);
    }

    /** A ready video Media File whose bytes are `$content` (null: nothing stored). */
    private function storedVideo(?string $content, string $name = 'lesson.mp4', string $mime = 'video/mp4'): object
    {
        $key = "tenants/{$this->customerId}/course/activities/{$this->activityId}/video/".uniqid().'-'.$name;
        if ($content !== null) {
            Storage::disk('media_local')->put($key, $content);
        }
        $id = DB::table('media_files')->insertGetId([
            'customer_id' => $this->customerId, 'uploaded_by' => $this->admin->id, 'file_type' => 'video', 'mime_type' => $mime,
            'original_name' => $name, 'display_name' => $name, 'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'storage_disk' => 'media_local', 'storage_bucket' => 'test-media', 'storage_region' => 'ap-southeast-1',
            'storage_key' => $key, 'checksum' => 'sha256:'.hash('sha256', ($content ?? '').$key),
            'file_size_bytes' => strlen((string) $content), 'visibility' => 'private', 'status' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('media_files')->where('id', $id)->first();
    }

    /** A one-second MP4 made by ffmpeg; without +faststart the MP4 muxer writes `moov` last. */
    private function mp4(bool $faststart): string
    {
        $this->requireFfmpeg();
        $path = sys_get_temp_dir().'/lf-video-board-'.bin2hex(random_bytes(6)).'.mp4';
        $process = new Process(array_merge([
            (string) config('media.processing.video_preview.ffmpeg_binary'), '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc=duration=1:size=64x48:rate=5',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1',
            '-c:v', 'mpeg4', '-c:a', 'aac', '-shortest',
        ], $faststart ? ['-movflags', '+faststart'] : [], [$path]));
        $process->mustRun();
        $content = (string) file_get_contents($path);
        unlink($path);

        return $content;
    }

    private function requireFfmpeg(): void
    {
        $ffmpeg = (string) config('media.processing.video_preview.ffmpeg_binary');
        $ffprobe = (string) config('media.ffprobe_binary');
        if ((! is_executable($ffmpeg) && (new ExecutableFinder)->find($ffmpeg) === null)
            || (! is_executable($ffprobe) && (new ExecutableFinder)->find($ffprobe) === null)) {
            $this->markTestSkipped('ffmpeg/ffprobe runtime is not installed.');
        }
    }

    /** Runs ffmpeg for real and calls `$during` right after it. */
    private function wrapFfmpeg(callable $during): void
    {
        $real = new DocumentProcessRunner;
        $runner = Mockery::mock(DocumentProcessRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(function (array $command, int $timeout) use ($real, $during): string {
            $output = $real->run($command, $timeout);
            if ($command[0] === config('media.processing.video_preview.ffmpeg_binary')) {
                $during();
            }

            return $output;
        });
        $this->app->instance(DocumentProcessRunner::class, $runner);
    }

    private function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)).$type.$payload;
    }

    /** @return resource */
    private function memory(string $bytes)
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    private function videoActivity(): int
    {
        $now = now();
        $categoryId = DB::table('core_course_categories')->insertGetId([
            'customer_id' => $this->customerId, 'parent_id' => null, 'name' => 'General '.uniqid(),
            'slug' => 'general-'.uniqid(), 'description' => null, 'thumbnail_image' => null, 'banner_image' => null,
            'sort_order' => 1, 'is_featured' => false, 'meta_title' => null, 'meta_description' => null,
            'meta_keywords' => null, 'status' => 'active', 'created_by' => $this->admin->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $templateId = DB::table('core_course_templates')->insertGetId(['course_type' => 'blended',
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

        return DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $templateId,
            'template_lesson_id' => $lessonId, 'title' => 'Video fixture',
            'activity_type' => 'video', 'sort_order' => 1, 'created_by' => $this->admin->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
