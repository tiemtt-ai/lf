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
use App\Services\StructuredExtractionPersistenceService;
use App\Support\TenantContext;
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
        $structuredJob = DB::table('media_processing_jobs')
            ->where('media_file_id', $media->id)
            ->where('job_type', 'structured_extraction')
            ->firstOrFail();
        $this->assertSame([
            'pages_with_text' => 0,
            'pages_with_regions' => 1,
            'pages_text_without_structure' => [],
        ], json_decode($structuredJob->metadata, true)['structure_coverage']);
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
            ->assertSessionHasErrors('processing_locale');

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
