<?php

namespace Tests\Feature;

use App\Exceptions\MediaReadException;
use App\Jobs\ProcessMediaProcessingJob;
use App\Models\User;
use App\Services\CourseMediaOwnerContextAuthorizer;
use App\Services\DoclingStructuredExtractionProvider;
use App\Services\DocumentProcessRunner;
use App\Services\LocalDocumentProcessingProvider;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaReadService;
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    public function test_required_profiles_are_deterministic_and_binary_ready_is_independent(): void
    {
        $media = $this->uploadVideo();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);

        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'processing_locale' => 'vi', 'status' => 'ready']);
        $this->assertDatabaseHas('media_processing_jobs', ['media_file_id' => $media->id, 'job_type' => 'speech_to_text', 'output_profile' => 'diarization=off;locale=vi', 'status' => 'ready']);
        $this->assertDatabaseHas('media_processing_jobs', ['media_file_id' => $media->id, 'job_type' => 'caption', 'output_profile' => 'format=vtt;locale=vi', 'status' => 'ready']);
        $this->assertDatabaseHas('media_transcripts', ['media_file_id' => $media->id, 'locale' => 'vi', 'status' => 'ready']);
        $this->assertDatabaseHas('media_captions', ['media_file_id' => $media->id, 'locale' => 'vi', 'caption_type' => 'vtt', 'status' => 'ready']);
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

            return '{"status":"written"}';
        });
        $provider = new DoclingStructuredExtractionProvider($runner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('structured_extraction_too_large');
        $provider->process(
            (object) ['file_type' => 'document', 'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_key' => 'docling-large-result.pdf'],
            (object) ['job_type' => 'structured_extraction', 'output_profile' => 'locale=vi;structure=layout'],
        );
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

    public function test_transcript_and_caption_profiles_have_independent_three_attempt_chains(): void
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

        config(['media.processing.providers.caption' => 'unconfigured']);
        $orchestrator->materializeOnDemandProfile($this->customerId, $media->id, 'caption', ['locale' => 'vi', 'format' => 'srt'], $this->admin->id);

        $this->assertSame(3, DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'speech_to_text')->where('output_profile_hash', $ko->output_profile_hash)->count());
        $this->assertDatabaseHas('media_transcripts', ['media_file_id' => $media->id, 'locale' => 'vi', 'status' => 'ready']);
        $this->assertDatabaseHas('media_processing_jobs', ['media_file_id' => $media->id, 'job_type' => 'caption', 'output_profile' => 'format=vtt;locale=vi', 'status' => 'ready']);
        $this->assertDatabaseHas('media_processing_jobs', ['media_file_id' => $media->id, 'job_type' => 'caption', 'output_profile' => 'format=srt;locale=vi', 'status' => 'failed']);
        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'status' => 'ready']);
    }

    public function test_database_blocks_duplicate_same_profile_and_attempt(): void
    {
        $media = $this->uploadVideo();
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        $job = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'caption')->first();
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
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'structured_extraction',
            ['locale' => 'vi', 'structure' => 'layout'], $this->admin->id
        );

        $this->assertDatabaseHas('media_processing_jobs', [
            'media_file_id' => $media->id, 'job_type' => 'structured_extraction',
            'status' => 'ready', 'output_type' => 'extracted_region',
        ]);
        $this->assertSame(2, DB::table('media_extracted_regions')->where('media_file_id', $media->id)->count());

        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => 120,
            'usage_type' => 'document', 'status' => 'active', 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $authorizer = Mockery::mock(CourseMediaOwnerContextAuthorizer::class);
        $authorizer->shouldReceive('authorized')->andReturnTrue();
        $this->app->instance(CourseMediaOwnerContextAuthorizer::class, $authorizer);

        $units = app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', 120, 'document', 'region', 'vi'
        );
        $this->assertSame('heading', $units[0]['structure']['role']);
        $this->assertSame(1, $units[0]['structure']['reading_order']);
    }

    public function test_structured_limit_failure_rolls_back_every_table_for_the_revision(): void
    {
        config([
            'media.processing.providers.structured_extraction' => 'fake',
            'media.processing.versions.structured_extraction' => 'fake-structured-limit-v1',
            'media.processing.structured_extraction.max_regions_per_page' => 1,
        ]);
        $media = $this->uploadDocument();
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

    private function uploadVideo(string $name = 'lesson.mp4'): object
    {
        $size = $name === 'lesson.mp4' ? 32 : 33;

        return app(MediaService::class)->upload(UploadedFile::fake()->create($name, $size, 'video/mp4'), [
            'file_type' => 'video', 'module' => 'course', 'entity_type' => 'activities', 'entity_id' => 99, 'purpose' => 'video',
        ], $this->admin->id);
    }

    private function uploadDocument(): object
    {
        return app(MediaService::class)->upload(UploadedFile::fake()->createWithContent('structured.txt', 'page text'), [
            'file_type' => 'document', 'module' => 'course', 'entity_type' => 'activities',
            'entity_id' => 120, 'purpose' => 'document',
        ], $this->admin->id);
    }
}
