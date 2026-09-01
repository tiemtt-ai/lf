<?php

namespace Tests\Integration;

use App\Services\MediaProcessingOrchestrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Audio STT tren queue THAT, worker THAT, engine THAT — khong sync, khong fake.
 *
 * Day la nhanh duy nhat chung minh duoc ba dieu ma sync queue khong bao gio
 * chung minh duoc: busy delivery de lai envelope delay that, worker bi SIGKILL
 * bo lai job `processing` (vi `failed()` khong duoc goi), va command recovery
 * dua job do ve terminal roi redeliver phan viec con lai.
 */
class AudioQueueRecoveryMariaDbTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertMatchesRegularExpression('/^lf_audio_review_[a-f0-9]{16}$/D', DB::connection()->getDatabaseName());
        // Cross-process fixtures must commit; the guarded harness drops the DB.
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    public function test_real_queue_contention_sigkill_recovery_and_redelivery(): void
    {
        $fixture = $this->requireRealFixture();
        Storage::fake('media_local');
        config(['queue.default' => 'database', 'queue.connections.database.queue' => 'audio-recovery-probe',
            'media.processing.providers.speech_to_text' => 'faster_whisper_local',
            'media.processing.versions.speech_to_text' => 'faster-whisper-small-local-review-v1']);
        $bytes = (string) file_get_contents($fixture);
        Storage::disk('media_local')->put('queue.wav', $bytes);

        $customer = DB::table('saas_customers')->insertGetId(['name' => 'Audio Queue Probe',
            'slug' => 'audio-queue-probe', 'status' => 'active']);
        $user = DB::table('users')->insertGetId(['customer_id' => $customer, 'name' => 'Probe',
            'email' => 'audio-queue@example.test', 'password' => bcrypt('fixture'),
            'role' => 'customer_admin', 'status' => 'active']);
        $media = DB::table('media_files')->insertGetId([
            'customer_id' => $customer, 'uploaded_by' => $user, 'file_type' => 'audio',
            'mime_type' => 'audio/x-wav', 'original_name' => 'queue.wav', 'display_name' => 'Queue',
            'extension' => 'wav', 'storage_disk' => 'media_local', 'storage_bucket' => 'test',
            'storage_key' => 'queue.wav', 'checksum' => str_repeat('a', 64),
            'file_size_bytes' => strlen($bytes), 'duration_seconds' => 18,
            'visibility' => 'private', 'status' => 'ready',
        ]);
        DB::table('media_file_usages')->insert(['customer_id' => $customer, 'media_file_id' => $media,
            'owner_type' => 'course_activity', 'owner_id' => 1, 'usage_type' => 'audio', 'status' => 'active']);

        $orchestrator = app(MediaProcessingOrchestrator::class);
        $orchestrator->materializeOnDemandProfile($customer, $media, 'speech_to_text',
            ['diarization' => 'off', 'locale' => 'en']);
        $first = DB::table('media_processing_jobs')->where('media_file_id', $media)->firstOrFail();

        $blocked = $this->worker(['--block']);
        try {
            $blocked->start();
            // Cho toi khi job that su `processing`, phep do chi phi da duoc ghi,
            // VA engine da chay xong (moc `probe=blocked`). Cho du moc cuoi la bat
            // buoc: SIGKILL trong luc engine con chay se de lai tien trinh Python
            // mo coi canh tranh CPU voi worker drain — test se timeout vi mot ly do
            // khong lien quan gi den thu no dang do.
            $deadline = microtime(true) + 600;
            $claimed = false;
            do {
                $observed = DB::table('media_processing_jobs')->where('id', $first->id)->first();
                $claimed = $observed->status === 'processing'
                    && (float) $observed->billable_units === 18.0
                    && $observed->billable_unit_type === 'second'
                    && str_contains((string) $observed->metadata, 'blocked');
                if ($claimed) {
                    break;
                }
                usleep(50000);
            } while ($blocked->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($claimed, $blocked->getErrorOutput());

            // Envelope thu hai cho cung Media/job_type khi mot job dang chay:
            // khong duoc claim, phai de lai envelope delay that tren queue.
            $orchestrator->materializeOnDemandProfile($customer, $media, 'speech_to_text',
                ['diarization' => 'off', 'locale' => 'vi']);
            $second = DB::table('media_processing_jobs')->where('media_file_id', $media)
                ->orderByDesc('id')->firstOrFail();
            $this->worker()->mustRun();
            $this->assertSame('pending', DB::table('media_processing_jobs')->where('id', $second->id)->value('status'));
            $this->assertTrue(DB::table('jobs')->whereNull('reserved_at')->where('available_at', '>', time())->exists(),
                'Busy delivery must leave a delayed real queue envelope.');
            $this->assertSame(0, DB::table('media_transcripts')->where('media_file_id', $media)->count());

            // SIGKILL: `failed()` khong duoc goi, job ket lai o `processing`.
            $blocked->stop(0, 9);
            $this->assertSame('processing', DB::table('media_processing_jobs')->where('id', $first->id)->value('status'),
                'SIGKILL must bypass the failed callback.');

            DB::table('media_processing_jobs')->where('id', $first->id)->update(['started_at' => now()->subSeconds(3601)]);
            $this->assertSame(0, Artisan::call('media:recover-audio-processing', ['--customer' => $customer]));
            $recovered = DB::table('media_processing_jobs')->where('id', $first->id)->first();
            $this->assertSame('failed', $recovered->status);
            $this->assertSame('provider_timeout', $recovered->error_code);
            $this->assertSame('provider_timeout', $recovered->error_message);
            // Chi phi da phat sinh phai con dau vet sau khi worker bi giet.
            $this->assertSame(18.0, (float) $recovered->billable_units);
            $this->assertSame('second', $recovered->billable_unit_type);
            $this->assertSame(0, DB::table('media_transcripts')->where('processing_job_id', $first->id)->count());

            // Day visibility/backoff toi han thay vi cho that.
            DB::table('jobs')->update(['reserved_at' => null, 'available_at' => time() - 1]);
            $this->worker(['--drain'])->mustRun();

            // Envelope cua job da terminal khong hoi sinh no.
            $this->assertSame('failed', DB::table('media_processing_jobs')->where('id', $first->id)->value('status'));
            // Job con lai chay het bang engine that.
            $done = DB::table('media_processing_jobs')->where('id', $second->id)->first();
            $this->assertSame('ready', $done->status, (string) $done->error_code);
            $this->assertSame(1, (int) $done->attempt);
            $this->assertSame('second', $done->billable_unit_type);
            $rows = DB::table('media_transcripts')->where('processing_job_id', $second->id)->orderBy('id')->get();
            $this->assertGreaterThanOrEqual(2, $rows->count());
            $previousEnd = null;
            foreach ($rows as $row) {
                $this->assertSame('ready', $row->status);
                $this->assertSame('vi', $row->locale);
                [$start, $end] = array_map('intval', explode('-', $row->locator_value));
                $this->assertLessThan($end, $start);
                if ($previousEnd !== null) {
                    $this->assertGreaterThanOrEqual($previousEnd, $start);
                }
                $previousEnd = $end;
            }
        } finally {
            if ($blocked->isRunning()) {
                $blocked->stop(0, 9);
            }
        }
    }

    private function requireRealFixture(): string
    {
        $configured = getenv('LF_REAL_AUDIO_FIXTURE');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }
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

    private function worker(array $arguments = []): Process
    {
        $db = config('database.connections.mysql');
        $process = new Process([PHP_BINARY, base_path('tests/Support/audio-queue-worker.php'), ...$arguments], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $db['database'], 'DB_URL' => '',
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'], 'DB_SOCKET' => $db['unix_socket'] ?? '', 'QUEUE_CONNECTION' => 'database',
            'MEDIA_SPEECH_TO_TEXT_PROVIDER' => 'faster_whisper_local',
            'MEDIA_SPEECH_TO_TEXT_VERSION' => 'faster-whisper-small-local-review-v1',
            'AUDIO_REVIEW_STORAGE_ROOT' => Storage::disk('media_local')->path(''),
        ]);
        $process->setTimeout(900);

        return $process;
    }
}
