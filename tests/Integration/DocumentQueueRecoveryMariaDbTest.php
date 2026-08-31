<?php

namespace Tests\Integration;

use App\Services\MediaProcessingOrchestrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DocumentQueueRecoveryMariaDbTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue($this->app->environment('testing'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertMatchesRegularExpression('/^lf_document_review_[a-f0-9]{16}$/D', DB::connection()->getDatabaseName());
        // Cross-process fixtures must commit. The guarded harness drops the
        // entire disposable DB; do not run unrelated domain down() migrations.
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    public function test_real_queue_contention_sigkill_recovery_and_redelivery(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertMatchesRegularExpression('/^lf_document_review_[a-f0-9]{16}$/D', DB::connection()->getDatabaseName());
        Storage::fake('media_local');
        config(['queue.default' => 'database', 'queue.connections.database.queue' => 'document-recovery-probe',
            'media.processing.providers.ocr' => 'local_document']);
        $customer = DB::table('saas_customers')->insertGetId(['name' => 'Queue Probe', 'slug' => 'queue-probe', 'status' => 'active']);
        $user = DB::table('users')->insertGetId(['customer_id' => $customer, 'name' => 'Probe', 'email' => 'queue@example.test',
            'password' => bcrypt('fixture'), 'role' => 'customer_admin', 'status' => 'active']);
        Storage::disk('media_local')->put('queue.txt', 'real local text after recovery');
        $media = DB::table('media_files')->insertGetId([
            'customer_id' => $customer, 'uploaded_by' => $user, 'file_type' => 'document',
            'mime_type' => 'text/plain', 'original_name' => 'queue.txt', 'display_name' => 'Queue', 'extension' => 'txt',
            'storage_disk' => 'media_local', 'storage_bucket' => 'test', 'storage_key' => 'queue.txt',
            'checksum' => str_repeat('a', 64), 'file_size_bytes' => 30, 'visibility' => 'private', 'status' => 'ready',
        ]);
        DB::table('media_file_usages')->insert(['customer_id' => $customer, 'media_file_id' => $media,
            'owner_type' => 'course_activity', 'owner_id' => 1, 'usage_type' => 'document', 'status' => 'active']);
        $orchestrator = app(MediaProcessingOrchestrator::class);
        $orchestrator->materializeOnDemandProfile($customer, $media, 'ocr', ['layout' => 'preserve', 'locale' => 'vi']);
        $first = DB::table('media_processing_jobs')->where('media_file_id', $media)->first();
        $blocked = $this->worker(['--block']);
        try {
            $blocked->start();
            $deadline = microtime(true) + 15;
            do {
                $observed = DB::table('media_processing_jobs')->where('id', $first->id)->first();
                $processing = $observed->status === 'processing' && (float) $observed->billable_units === 1.0;
                if ($processing) {
                    break;
                }
                usleep(50000);
            } while ($blocked->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($processing, $blocked->getErrorOutput());
            $orchestrator->materializeOnDemandProfile($customer, $media, 'ocr', ['layout' => 'preserve', 'locale' => 'en']);
            $second = DB::table('media_processing_jobs')->where('media_file_id', $media)->orderByDesc('id')->first();
            $busyWorker = $this->worker();
            $busyWorker->mustRun();
            $this->assertSame('pending', DB::table('media_processing_jobs')->where('id', $second->id)->value('status'));
            $this->assertTrue(DB::table('jobs')->whereNull('reserved_at')->where('available_at', '>', time())->exists(),
                'Busy delivery must leave a delayed real queue envelope.');
            $blocked->stop(0, 9);
            $this->assertSame('processing', DB::table('media_processing_jobs')->where('id', $first->id)->value('status'), 'SIGKILL must bypass failed callback.');
            DB::table('media_processing_jobs')->where('id', $first->id)->update(['started_at' => now()->subSeconds(3601)]);
            $this->assertSame(0, Artisan::call('media:recover-document-processing', ['--customer' => $customer]));
            $this->assertSame('failed', DB::table('media_processing_jobs')->where('id', $first->id)->value('status'));
            $this->assertSame(1.0, (float) DB::table('media_processing_jobs')->where('id', $first->id)->value('billable_units'));
            $this->assertSame('page', DB::table('media_processing_jobs')->where('id', $first->id)->value('billable_unit_type'));
            // Advance transport visibility and backoff without a 65-minute wait.
            DB::table('jobs')->update(['reserved_at' => null, 'available_at' => time() - 1]);
            $drain = $this->worker(['--drain']);
            $drain->mustRun();
            $this->assertSame('provider_timeout', DB::table('media_processing_jobs')->where('id', $first->id)->value('error_code'));
            $this->assertSame('ready', DB::table('media_processing_jobs')->where('id', $second->id)->value('status'));
            $this->assertSame(1, (int) DB::table('media_processing_jobs')->where('id', $second->id)->value('attempt'));
            $this->assertDatabaseHas('media_extracted_texts', ['processing_job_id' => $second->id, 'locale' => 'en', 'text' => 'real local text after recovery']);
            $this->assertDatabaseMissing('media_extracted_texts', ['processing_job_id' => $first->id]);
        } finally {
            if ($blocked->isRunning()) {
                $blocked->stop(0, 9);
            }
        }
    }

    private function worker(array $arguments = []): Process
    {
        $db = config('database.connections.mysql');
        $process = new Process([PHP_BINARY, base_path('tests/Support/document-queue-worker.php'), ...$arguments], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $db['database'], 'DB_URL' => '',
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'], 'DB_SOCKET' => $db['unix_socket'] ?? '', 'QUEUE_CONNECTION' => 'database',
            'DOCUMENT_REVIEW_STORAGE_ROOT' => Storage::disk('media_local')->path(''),
        ]);
        $process->setTimeout(30);

        return $process;
    }
}
