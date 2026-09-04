<?php

namespace Tests\Feature;

use App\Exceptions\MediaReadException;
use App\Jobs\ProcessMediaProcessingJob;
use App\Models\User;
use App\Services\DocumentProcessRunner;
use App\Services\LocalDocumentProcessingProvider;
use App\Services\MediaProcessingOrchestrator;
use App\Services\MediaReadService;
use App\Services\MediaService;
use App\Services\RegionCropStorage;
use App\Services\StructuredExtractionPersistenceService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class DocumentProcessingLocalReviewTest extends TestCase
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
            'media.processing.providers.ocr' => 'local_document',
            'media.processing.versions.ocr' => 'local-document-review-v1',
        ]);
        $this->customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Document Review', 'slug' => 'document-review', 'subdomain' => 'document-review',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::forceCreate([
            'customer_id' => $this->customerId, 'name' => 'Fixture Admin', 'email' => 'document@example.test',
            'password' => bcrypt('fixture'), 'role' => 'customer_admin', 'status' => 'active',
        ]);
        TenantContext::set((object) ['id' => $this->customerId]);
        $this->actingAs($this->admin);
        [$template, $lesson] = $this->courseFixture();
        $this->activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $template,
            'template_lesson_id' => $lesson, 'title' => 'Document fixture',
            'activity_type' => 'document', 'sort_order' => 1, 'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_real_mixed_pdf_activity_usage_queue_provider_and_authorized_read(): void
    {
        $this->requirePdfRuntime();
        $media = $this->upload('mixed.pdf');
        $this->attach($media);
        $job = $this->ocrJob($media);
        $this->assertSame('ready', $job->status, (string) $job->error_code);
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->completed_at);
        $this->assertSame(hash('sha256', $media->checksum.':document'), $job->source_fingerprint);
        $rows = DB::table('media_extracted_texts')->where('processing_job_id', $job->id)->orderBy('sequence')->get();
        $this->assertSame(['embedded_text', 'ocr'], $rows->pluck('extraction_method')->all());
        $units = $this->read(); // Real Course authorizer, no mock and no bare-media read.
        $this->assertSame(['1', '2'], array_column(array_column($units, 'locator'), 'value'));
        $this->assertStringContainsString('Nội dung tiếng Việt.', $units[0]['text']);
        $this->assertStringContainsString('LEARNFORGE SCANNED PAGE TWO', $units[1]['text']);
        foreach ($units as $unit) {
            $this->assertSame('vi', $unit['locale']);
            $this->assertSame($job->source_fingerprint, $unit['source_fingerprint']);
            $this->assertSame($job->processing_version, $unit['processing_version']);
        }
        $this->assertDatabaseMissing('media_processing_jobs', ['media_file_id' => $media->id, 'job_type' => 'structured_extraction']);
        $this->assertDatabaseHas('media_access_logs', ['media_file_id' => $media->id, 'action' => 'read_derived']);
    }

    public function test_real_scan_pdf_produces_ocr_text(): void
    {
        $this->requirePdfRuntime();
        $media = $this->upload('scan.pdf');
        $this->attach($media);
        $this->assertSame('ready', $this->ocrJob($media)->status);
        $this->assertStringContainsString('LEARNFORGE SCANNED PAGE TWO', $this->read()[0]['text']);
        $this->assertDatabaseHas('media_extracted_texts', ['media_file_id' => $media->id, 'extraction_method' => 'ocr']);
    }

    public function test_document_three_locale_profile_is_canonical_persisted_and_read_in_isolation(): void
    {
        $media = $this->upload(null, 'Tiếng Việt. English. 한국어.');
        app(MediaService::class)->attachUsage($media->id, 'course_activity', $this->activityId, 'document', [
            'processing_locale' => 'vi',
            'processing_locales' => ['vi', 'ko', 'en'],
        ]);

        $job = $this->ocrJob($media);
        $this->assertSame('layout=preserve;locales=en,ko,vi', $job->output_profile);
        $this->assertSame(['en', 'ko', 'vi'], DB::table('media_processing_job_locales')
            ->where('processing_job_id', $job->id)->orderBy('ordinal')->pluck('locale')->all());

        $units = app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', $this->activityId, 'document', 'extracted_text',
            null, null, null, 'test', [], null, false, ['ko', 'vi', 'en']
        );
        $this->assertSame(['en', 'ko', 'vi'], $units[0]['language_profile']);
        $this->assertSame($job->id, DB::table('media_extracted_texts')->where('media_file_id', $media->id)->value('processing_job_id'));

        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity(
            $this->customerId, $media->id, ['vi', 'en'], $this->admin->id
        );
        $replacement = $this->ocrJob($media);
        $this->assertNotSame($job->id, $replacement->id);
        $this->assertNotSame($job->processing_version, $replacement->processing_version);
        $this->assertDatabaseHas('media_extracted_texts', ['processing_job_id' => $job->id, 'status' => 'archived']);
        $this->assertDatabaseHas('media_extracted_texts', ['processing_job_id' => $replacement->id, 'status' => 'ready']);
    }

    #[DataProvider('badPdfs')]
    public function test_real_invalid_pdf_fails_without_output(string $fixture, string $error): void
    {
        $this->requirePdfRuntime();
        $media = $this->upload($fixture);
        $this->attach($media);
        $job = $this->ocrJob($media);
        $this->assertSame('failed', $job->status);
        $this->assertSame($error, $job->error_code);
        $this->assertSame('page', $job->billable_unit_type);
        $this->assertSame($fixture === 'blank.pdf' ? 1.0 : 0.0, (float) $job->billable_units);
        $this->assertDatabaseMissing('media_extracted_texts', ['processing_job_id' => $job->id]);
        $this->assertReadError('failed');
    }

    public static function badPdfs(): array
    {
        return [['broken.pdf', 'corrupt_source'], ['encrypted.pdf', 'corrupt_source'], ['blank.pdf', 'no_extractable_text']];
    }

    public function test_unsupported_extension_fails_without_output(): void
    {
        $media = $this->upload();
        // Import/legacy path: upload validators reject unsupported formats first.
        DB::table('media_files')->where('id', $media->id)->update(['extension' => 'unsupported']);
        $this->attach($media);
        $this->assertSame('unsupported_source', $this->ocrJob($media)->error_code);
        $this->assertDatabaseMissing('media_extracted_texts', ['media_file_id' => $media->id]);
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_provider_output_is_atomic_and_never_ready(array $units, string $error): void
    {
        $media = $this->upload();
        config(['media.processing.local_document.max_extracted_characters' => 10]);
        $provider = Mockery::mock(LocalDocumentProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andReturn(['units' => $units]);
        $this->app->instance(LocalDocumentProcessingProvider::class, $provider);
        $this->attach($media);
        $this->assertSame($error, $this->ocrJob($media)->error_code);
        $this->assertSame('failed', $this->ocrJob($media)->status);
        $this->assertDatabaseMissing('media_extracted_texts', ['media_file_id' => $media->id]);
    }

    public static function invalidOutputs(): array
    {
        $unit = ['locator_type' => 'page', 'locator_value' => '1', 'sequence' => 1, 'text' => '123456'];

        return [
            'empty' => [[], 'no_extractable_text'],
            'blank' => [[array_replace($unit, ['text' => '  '])], 'no_extractable_text'],
            'invalid utf8' => [[array_replace($unit, ['text' => "\xff"])], 'corrupt_source'],
            'duplicate locator' => [[$unit, array_replace($unit, ['sequence' => 2])], 'corrupt_source'],
            'total cap' => [[$unit, array_replace($unit, ['sequence' => 2, 'locator_value' => '2'])], 'extracted_text_too_large'],
        ];
    }

    public function test_provider_exception_metadata_does_not_persist_source_or_credentials(): void
    {
        $media = $this->upload();
        $provider = Mockery::mock(LocalDocumentProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andThrow(new \RuntimeException('secret source https://example.test/?token=sensitive'));
        $this->app->instance(LocalDocumentProcessingProvider::class, $provider);
        $this->attach($media);
        $job = $this->ocrJob($media);
        $this->assertSame('processing_failed', $job->error_code);
        $this->assertSame('processing_failed', $job->error_message);
    }

    public function test_late_provider_result_cannot_resurrect_a_terminal_job(): void
    {
        $media = $this->upload();
        $provider = Mockery::mock(LocalDocumentProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andReturnUsing(function ($media, $job): array {
            DB::table('media_processing_jobs')->where('id', $job->id)->update([
                'status' => 'failed', 'error_code' => 'provider_timeout', 'completed_at' => now(),
            ]);

            return ['units' => [['locator_type' => 'page', 'locator_value' => '1', 'sequence' => 1, 'text' => 'late']]];
        });
        $this->app->instance(LocalDocumentProcessingProvider::class, $provider);
        $this->attach($media);
        $this->assertSame('failed', $this->ocrJob($media)->status);
        $this->assertSame('provider_timeout', $this->ocrJob($media)->error_code);
        $this->assertDatabaseMissing('media_extracted_texts', ['media_file_id' => $media->id]);
    }

    public function test_read_reports_pending_and_processing_job_without_output(): void
    {
        $media = $this->upload();
        Queue::fake();
        $this->attach($media);
        $this->assertReadError('pending');
        DB::table('media_processing_jobs')->where('id', $this->ocrJob($media)->id)->update(['status' => 'processing', 'started_at' => now()]);
        $this->assertReadError('processing');
    }

    public function test_read_reports_job_state_for_every_member_of_a_multi_locale_profile(): void
    {
        // `locales=en,vi` duoc sap xep tang dan, nen `vi` khong nam dau chuoi va
        // `vi` la tien to cua `vi-VN`. Tra job state bang cach doan chuoi profile
        // vua truot thanh vien khong dung dau, vua khop nham locale khac.
        $media = $this->upload();
        Queue::fake();
        app(MediaService::class)->attachUsage($media->id, 'course_activity', $this->activityId, 'document', [
            'processing_locale' => 'vi', 'processing_locales' => ['vi', 'en'],
        ]);
        $job = $this->ocrJob($media);
        $this->assertSame('layout=preserve;locales=en,vi', $job->output_profile);

        foreach (['vi', 'en'] as $locale) {
            $this->assertReadError('pending', $locale);
        }
        DB::table('media_processing_jobs')->where('id', $job->id)->update(['status' => 'processing', 'started_at' => now()]);
        foreach (['vi', 'en'] as $locale) {
            $this->assertReadError('processing', $locale);
        }

        // Locale ngoai profile khong duoc muon trang thai cua job nay.
        $this->assertReadError('locale_unavailable', 'ko');
    }

    public function test_read_excludes_output_linked_to_a_failed_job(): void
    {
        $media = $this->upload();
        $this->attach($media);
        DB::table('media_processing_jobs')->where('id', $this->ocrJob($media)->id)->update(['status' => 'failed', 'error_code' => 'processing_failed']);
        $this->assertReadError('failed');
    }

    public function test_read_rejects_cross_tenant_actor_and_invalid_locale(): void
    {
        $media = $this->upload();
        $this->attach($media);
        $this->assertReadError('locale_unavailable', 'invalid_locale');
        $other = DB::table('saas_customers')->insertGetId(['name' => 'Other', 'slug' => 'other-review', 'status' => 'active']);
        TenantContext::set((object) ['id' => $other]);
        $this->assertReadError('unauthorized');
    }

    public function test_structure_coverage_rejects_invalid_locale_and_audits_denial(): void
    {
        $media = $this->upload();
        $this->attach($media);
        $before = DB::table('media_access_logs')->count();

        try {
            app(MediaReadService::class)->structureCoverage(
                $this->admin->id, 'course_activity', $this->activityId, 'document', 'invalid_locale',
            );
            $this->fail('Expected a domain read error.');
        } catch (MediaReadException $exception) {
            $this->assertSame('locale_unavailable', $exception->errorCode);
        }

        $this->assertSame($before + 1, DB::table('media_access_logs')->count());
        $audit = DB::table('media_access_logs')->orderByDesc('id')->first();
        $this->assertSame($this->customerId, (int) $audit->customer_id);
        $this->assertSame((int) $media->id, (int) $audit->media_file_id);
        $metadata = json_decode($audit->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('denied', $metadata['decision']);
        $this->assertSame('locale_unavailable', $metadata['error_code']);
        $this->assertSame('region', $metadata['content_type']);
    }

    public function test_duplicate_dispatch_locale_and_version_revisions_preserve_history(): void
    {
        $media = $this->upload();
        $this->attach($media);
        $old = $this->ocrJob($media);
        $this->attach($media);
        (new ProcessMediaProcessingJob($this->customerId, $old->id))->handle();
        $this->assertSame(1, DB::table('media_processing_jobs')->where('job_type', 'ocr')->count());
        $orchestrator = app(MediaProcessingOrchestrator::class);
        $orchestrator->materializeOnDemandProfile($this->customerId, $media->id, 'ocr', ['layout' => 'preserve', 'locale' => 'en'], $this->admin->id);
        $this->assertSame('en', $this->read('en')[0]['locale']);
        config(['media.processing.versions.ocr' => 'local-document-review-v2']);
        $this->attach($media);
        $this->assertStringStartsWith('local-document-review-v2+document-v2+lp-', $this->read()[0]['processing_version']);
        $archived = app(MediaReadService::class)->read($this->admin->id, 'course_activity', $this->activityId, 'document', 'extracted_text', 'vi', $old->processing_version, $old->source_fingerprint);
        $this->assertSame('archived', $archived[0]['status']);
        $this->assertSame('en', $this->read('en')[0]['locale']);
    }

    public function test_changed_source_is_a_new_media_and_fingerprint(): void
    {
        $first = $this->upload();
        $second = $this->upload(null, 'different source');
        $this->attach($first);
        $firstJob = $this->ocrJob($first);
        app(MediaService::class)->detachUsage($first->id, 'course_activity', $this->activityId, 'document');
        $this->attach($second);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($firstJob->source_fingerprint, $this->ocrJob($second)->source_fingerprint);
        $this->assertSame('different source', $this->read()[0]['text']);
    }

    public function test_real_optional_docling_preserves_independent_ocr_and_readable_regions(): void
    {
        $this->requirePdfRuntime();
        if (! is_executable((string) config('media.processing.docling.python_binary'))
            || ! is_dir((string) config('media.processing.docling.artifacts_path'))) {
            $this->markTestSkipped('Optional offline Docling runtime is not installed.');
        }
        config([
            'media.processing.providers.structured_extraction' => 'docling_local',
            'media.processing.versions.structured_extraction' => 'docling-2.119.0-review-v1',
        ]);
        $media = $this->upload('mixed.pdf');
        app(MediaService::class)->attachUsage($media->id, 'course_activity', $this->activityId, 'document', [
            'processing_locale' => 'vi', 'structured_extraction' => true,
        ]);
        $this->assertSame('ready', $this->ocrJob($media)->status);
        $job = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'structured_extraction')->firstOrFail();
        $this->assertSame('ready', $job->status, (string) $job->error_code);
        $regions = app(MediaReadService::class)->read($this->admin->id, 'course_activity', $this->activityId, 'document', 'region', 'vi');
        $this->assertNotEmpty($regions);
        $this->assertSame($job->processing_version, $regions[0]['processing_version']);
        $this->assertSame('region', $regions[0]['locator']['type']);
        $this->assertCount(2, $this->read());

        $crops = Mockery::mock(RegionCropStorage::class);
        $crops->shouldNotReceive('purgeRevision');
        $this->app->instance(RegionCropStorage::class, $crops);
        (new ProcessMediaProcessingJob($this->customerId, $job->id))->failed(new \RuntimeException('late queue notification'));
        $this->assertDatabaseHas('media_processing_jobs', ['id' => $job->id, 'status' => 'ready']);
    }

    public function test_retry_after_unavailable_provider_uses_new_row_and_real_txt_provider(): void
    {
        $media = $this->upload();
        Queue::fake();
        $this->attach($media);
        $old = $this->ocrJob($media);
        $provider = Mockery::mock(LocalDocumentProcessingProvider::class);
        $provider->shouldReceive('process')->once()->andThrow(new \RuntimeException('provider_unavailable'));
        $this->app->instance(LocalDocumentProcessingProvider::class, $provider);
        (new ProcessMediaProcessingJob($this->customerId, $old->id))->handle();
        $failed = DB::table('media_processing_jobs')->where('id', $old->id)->first();
        $this->app->forgetInstance(LocalDocumentProcessingProvider::class);
        $retry = app(MediaProcessingOrchestrator::class)->retry($this->customerId, $old->id, $this->admin->id);
        $this->assertNotSame($old->id, $retry->id);
        $this->assertSame(2, (int) $retry->attempt);
        $this->assertSame($old->source_fingerprint, $retry->source_fingerprint);
        $this->assertSame((int) $old->id, (int) $retry->supersedes_job_id);
        // Explicit worker execution; Queue::fake captures the scheduled backoff.
        Queue::assertPushed(ProcessMediaProcessingJob::class, fn ($envelope) => $envelope->processingJobId === (int) $retry->id && $envelope->delay !== null);
        (new ProcessMediaProcessingJob($this->customerId, $retry->id))->handle();
        $this->assertEquals($failed, DB::table('media_processing_jobs')->where('id', $old->id)->first());
        $this->assertSame('ready', $this->ocrJob($media)->status);
        $this->assertCount(1, $this->read());
        $this->assertSame('page', $this->ocrJob($media)->billable_unit_type);
        $this->assertSame(1.0, (float) $this->ocrJob($media)->billable_units);
    }

    public function test_late_clean_scan_does_not_clear_missing_document_locale(): void
    {
        Queue::fake();
        $media = $this->upload();
        app(MediaService::class)->attachUsage($media->id, 'course_activity', $this->activityId, 'document', ['processing_locale' => null]);
        $scan = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'virus_scan')->firstOrFail();
        (new ProcessMediaProcessingJob($this->customerId, $scan->id))->handle();
        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'status' => 'failed', 'processing_error_code' => 'required_profile_configuration_missing']);
        $this->assertDatabaseHas('media_processing_jobs', ['id' => $scan->id, 'status' => 'ready']);
    }

    public function test_worker_failure_metadata_is_sanitized(): void
    {
        $media = $this->upload();
        Queue::fake();
        $this->attach($media);
        $job = $this->ocrJob($media);
        DB::table('media_processing_jobs')->where('id', $job->id)->update(['status' => 'processing', 'started_at' => now()]);
        (new ProcessMediaProcessingJob($this->customerId, $job->id))->failed(new \RuntimeException('secret https://example.test/?token=sensitive'));
        $this->assertSame('provider_timeout', $this->ocrJob($media)->error_message);
    }

    public function test_legacy_rows_are_sorted_and_never_mixed_across_source_fingerprints(): void
    {
        $media = $this->upload();
        Queue::fake();
        $this->attach($media);
        $base = [
            'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'processing_job_id' => null,
            'locale' => 'vi', 'locator_type' => 'page', 'text' => 'legacy', 'char_count' => 6,
            'extraction_method' => 'embedded_text', 'processing_version' => 'legacy-v1',
            'source_fingerprint' => str_repeat('a', 64), 'status' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ];
        foreach ([2, 1] as $page) {
            DB::table('media_extracted_texts')->insert($base + ['locator_value' => (string) $page, 'sequence' => $page]);
        }
        $this->assertSame(['1', '2'], array_column(array_column($this->read(), 'locator'), 'value'));
        DB::table('media_extracted_texts')->insert(array_replace($base, [
            'locator_value' => '3', 'sequence' => 3, 'source_fingerprint' => str_repeat('b', 64),
        ]));
        $this->assertSame(['3'], array_column(array_column($this->read(), 'locator'), 'value'));
    }

    public function test_read_excludes_a_row_linked_to_the_wrong_job_type(): void
    {
        $media = $this->upload();
        $this->attach($media);
        $scan = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'virus_scan')->firstOrFail();
        DB::table('media_extracted_texts')->where('media_file_id', $media->id)->update(['processing_job_id' => $scan->id]);
        $this->assertReadError('missing');
    }

    #[DataProvider('officeFormats')]
    public function test_real_office_format_upload_extract_and_authorized_read(string $extension): void
    {
        if (! (new ExecutableFinder)->find('soffice')) {
            $this->markTestSkipped('LibreOffice runtime is not installed.');
        }
        $media = $this->upload('office.'.$extension);
        $this->attach($media);
        $job = $this->ocrJob($media);
        $this->assertSame('ready', $job->status, (string) $job->error_code);
        $this->assertStringContainsString('DOCUMENT_ACCEPTANCE_ALPHA', implode(' ', array_column($this->read(), 'text')));
        $this->assertSame('page', $job->billable_unit_type);
        $this->assertGreaterThanOrEqual(1, (float) $job->billable_units);
    }

    public static function officeFormats(): array
    {
        return array_map(static fn ($extension) => [$extension], ['docx', 'doc', 'pptx', 'ppt', 'xlsx', 'xls']);
    }

    public function test_real_docling_table_merged_cells_chart_and_diagram_fixture(): void
    {
        $this->requirePdfRuntime();
        if (! is_executable((string) config('media.processing.docling.python_binary'))
            || ! is_dir((string) config('media.processing.docling.artifacts_path'))) {
            $this->markTestSkipped('Optional offline Docling runtime is not installed.');
        }
        config(['media.processing.providers.structured_extraction' => 'docling_local']);
        $media = $this->upload('structured.pdf');
        app(MediaService::class)->attachUsage($media->id, 'course_activity', $this->activityId, 'document',
            ['processing_locale' => 'vi', 'structured_extraction' => true]);
        $job = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'structured_extraction')->first();
        $this->assertSame('ready', $job->status, (string) $job->error_code);
        $this->assertSame('ready', $this->ocrJob($media)->status);
        $tables = DB::table('media_extracted_tables')->where('processing_job_id', $job->id)->get();
        $this->assertNotEmpty($tables);
        $cells = DB::table('media_table_cells')->whereIn('extracted_table_id', $tables->pluck('id'))->get();
        $text = $cells->pluck('text')->implode(' ');
        foreach (['ALPHA', 'BETA', '10', '20', '30', '40'] as $value) {
            $this->assertStringContainsString($value, $text);
        }
        $this->assertTrue($cells->contains(fn ($cell) => (int) $cell->column_span > 1), 'Merged heading must survive.');
        foreach ($tables as $table) {
            $observed = $cells->contains(fn ($cell) => (int) $cell->extracted_table_id === (int) $table->id
                && (int) $cell->is_header === 1);
            $this->assertSame($observed, (bool) $table->has_header,
                'has_header phai khop voi cell tieu de that cua chinh bang do.');
        }
        $units = app(MediaReadService::class)->read($this->admin->id, 'course_activity', $this->activityId, 'document', 'region', 'vi');
        $this->assertNotEmpty($units);
        $this->assertDatabaseHas('media_extracted_regions', ['processing_job_id' => $job->id, 'page' => 2, 'role' => 'image']);
        $canonical = implode(' ', array_column($this->read(), 'text'));
        foreach (['Q1 10', 'Q2 20', 'INPUT', 'OUTPUT'] as $label) {
            $this->assertStringContainsString($label, $canonical);
        }
    }

    /**
     * `media_extracted_tables.md` dinh nghia `has_header` la quan sat ve hinh
     * dang, nen no phai suy ra tu chinh cell cua bang do. extract.py truoc day
     * hardcode `True`, khien mot bang khong co cell tieu de nao van tu khai la
     * co — mau thuan voi `is_header` di cung trong cung payload.
     */
    public function test_docling_table_header_is_derived_from_observed_cells(): void
    {
        $python = (string) config('media.processing.docling.python_binary');
        if (! is_executable($python)) {
            $this->markTestSkipped('Optional offline Docling runtime is not installed.');
        }

        $code = 'import json,sys; sys.path.insert(0, sys.argv[1]); import extract; '
            ."print(json.dumps({'mixed': extract.table_has_header([{'is_header': False}, {'is_header': True}]), "
            ."'none': extract.table_has_header([{'is_header': False}, {'is_header': False}]), "
            ."'empty': extract.table_has_header([])}))";

        $observed = json_decode(
            app(DocumentProcessRunner::class)->run([$python, '-c', $code, base_path('runtime/docling')], 30),
            true, 512, JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($observed['mixed']);
        $this->assertFalse($observed['none'], 'Bang khong co cell tieu de khong duoc tu khai has_header.');
        $this->assertFalse($observed['empty']);
    }

    public function test_detach_before_claim_cancels_without_provider_work(): void
    {
        $media = $this->upload();
        Queue::fake();
        $this->attach($media);
        $job = $this->ocrJob($media);
        app(MediaService::class)->detachUsage($media->id, 'course_activity', $this->activityId, 'document');
        $provider = Mockery::mock(LocalDocumentProcessingProvider::class);
        $provider->shouldNotReceive('process');
        $this->app->instance(LocalDocumentProcessingProvider::class, $provider);
        (new ProcessMediaProcessingJob($this->customerId, $job->id))->handle();
        $this->assertSame('cancelled', $this->ocrJob($media)->status);
        $this->assertDatabaseMissing('media_extracted_texts', ['media_file_id' => $media->id]);
    }

    public function test_one_remaining_active_usage_keeps_pending_document_eligible(): void
    {
        $media = $this->upload();
        Queue::fake();
        $this->attach($media);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id,
            'owner_type' => 'course_activity', 'owner_id' => $this->activityId + 1000,
            'usage_type' => 'document', 'status' => 'active',
        ]);
        app(MediaService::class)->detachUsage($media->id, 'course_activity', $this->activityId, 'document');
        (new ProcessMediaProcessingJob($this->customerId, $this->ocrJob($media)->id))->handle();
        $this->assertSame('ready', $this->ocrJob($media)->status);
    }

    public function test_direct_document_materialization_requires_active_usage(): void
    {
        $media = $this->upload();
        $before = DB::table('media_processing_jobs')->count();
        foreach (['initial', 'on-demand'] as $path) {
            try {
                $orchestrator = app(MediaProcessingOrchestrator::class);
                $path === 'initial'
                    ? $orchestrator->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id)
                    : $orchestrator->materializeOnDemandProfile($this->customerId, $media->id, 'ocr', ['layout' => 'preserve', 'locale' => 'vi']);
                $this->fail('Unattached Document must not dispatch.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Active Document Course usage is required.', $e->getMessage());
            }
        }
        $this->assertSame($before, DB::table('media_processing_jobs')->count());
    }

    public function test_unsupported_document_profile_values_do_not_create_an_attempt(): void
    {
        $media = $this->upload();
        $this->attach($media);
        $before = DB::table('media_processing_jobs')->count();
        try {
            app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile($this->customerId, $media->id,
                'ocr', ['layout' => 'other', 'locale' => 'vi']);
            $this->fail('Expected unsupported profile.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Unsupported output profile.', $e->getMessage());
        }
        $this->assertSame($before, DB::table('media_processing_jobs')->count());
    }

    public function test_busy_async_claim_redelivers_same_attempt_instead_of_losing_work(): void
    {
        $media = $this->upload();
        Queue::fake();
        $this->attach($media);
        $first = $this->ocrJob($media);
        DB::table('media_processing_jobs')->where('id', $first->id)->update(['status' => 'processing', 'started_at' => now()]);
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile($this->customerId, $media->id,
            'ocr', ['layout' => 'preserve', 'locale' => 'en']);
        $pending = $this->ocrJob($media);
        Queue::fake();
        config(['queue.default' => 'database']);
        (new ProcessMediaProcessingJob($this->customerId, $pending->id))->handle();
        Queue::assertPushed(ProcessMediaProcessingJob::class, fn ($envelope) => $envelope->processingJobId === $pending->id
            && $envelope->delay !== null && $envelope->delay->isFuture());
        $this->assertSame('pending', $this->ocrJob($media)->status);
        $this->assertSame(1, (int) $this->ocrJob($media)->attempt);
    }

    public function test_recovery_fails_expired_processing_and_preserves_fresh_and_terminal_rows(): void
    {
        $media = $this->upload();
        Queue::fake();
        $this->attach($media);
        $job = $this->ocrJob($media);
        DB::table('media_processing_jobs')->where('id', $job->id)->update(['status' => 'processing', 'started_at' => now()->subSeconds(3601)]);
        $this->artisan('media:recover-document-processing', ['--customer' => $this->customerId])->assertSuccessful();
        $this->assertSame('failed', $this->ocrJob($media)->status);
        $this->assertSame('provider_timeout', $this->ocrJob($media)->error_code);
        $retry = app(MediaProcessingOrchestrator::class)->retry($this->customerId, $job->id);
        DB::table('media_processing_jobs')->where('id', $retry->id)->update(['status' => 'processing', 'started_at' => now()]);
        $snapshot = (array) DB::table('media_processing_jobs')->where('id', $job->id)->first();
        $this->artisan('media:recover-document-processing', ['--customer' => $this->customerId])->assertSuccessful();
        $this->assertSame($snapshot, (array) DB::table('media_processing_jobs')->where('id', $job->id)->first());
        $this->assertSame('processing', $this->ocrJob($media)->status);
    }

    public function test_recovery_redelivers_old_pending_but_respects_retry_delay(): void
    {
        $media = $this->upload();
        Queue::fake();
        $this->attach($media);
        $job = $this->ocrJob($media);
        Queue::fake();
        $this->artisan('media:recover-document-processing')->assertSuccessful();
        Queue::assertNothingPushed();
        DB::table('media_processing_jobs')->where('id', $job->id)->update(['updated_at' => now()->subSeconds(3901)]);
        $this->artisan('media:recover-document-processing', ['--customer' => $this->customerId])->assertSuccessful();
        Queue::assertPushed(ProcessMediaProcessingJob::class, fn ($envelope) => $envelope->processingJobId === $job->id);
        $this->assertSame(1, (int) $this->ocrJob($media)->attempt);
    }

    public function test_late_crop_writer_and_cleanup_cannot_touch_successor_attempt(): void
    {
        $media = $this->upload();
        Queue::fake();
        $this->attach($media);
        (new ProcessMediaProcessingJob($this->customerId, $this->ocrJob($media)->id))->handle();
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile($this->customerId, $media->id,
            'structured_extraction', ['locale' => 'vi', 'structure' => 'layout']);
        $old = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'structured_extraction')->first();
        DB::table('media_processing_jobs')->where('id', $old->id)->update(['status' => 'failed', 'error_code' => 'provider_timeout', 'completed_at' => now()]);
        $retry = app(MediaProcessingOrchestrator::class)->retry($this->customerId, $old->id);
        DB::table('media_processing_jobs')->where('id', $retry->id)->update(['status' => 'processing', 'started_at' => now()]);
        $crops = app(RegionCropStorage::class);
        $key = $crops->key($media, $retry, 'vi', 1, 1);
        Storage::disk('media_local')->put($key, 'successor crop');
        $this->assertTrue($crops->purgeRevision($media, $old, 'vi'));
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, 'stale crop');
        rewind($stream);
        try {
            $crops->put($media, $old, $key, $stream);
            $this->fail('Late writer must be fenced.');
        } catch (\RuntimeException $e) {
            $this->assertSame('provider_timeout', $e->getMessage());
        } finally {
            fclose($stream);
        }
        $this->assertSame('successor crop', Storage::disk('media_local')->get($key));
    }

    public function test_d4_real_mixed_blank_page_preserves_locators(): void
    {
        $this->requirePdfRuntime();
        $media = $this->upload('mixed-blank.pdf');
        $this->attach($media);
        $this->assertSame('ready', $this->ocrJob($media)->status);
        $units = $this->read();
        $this->assertSame(['1', '2', '3'], array_column(array_column($units, 'locator'), 'value'));
        $this->assertSame('', $units[1]['text']);
        $this->assertStringContainsString('LEARNFORGE SCANNED PAGE TWO', $units[2]['text']);
        $this->assertDatabaseHas('media_extracted_texts', ['media_file_id' => $media->id, 'locator_value' => '2', 'char_count' => 0]);
    }

    public function test_d6_reattach_creates_generation_without_consuming_provider_attempt(): void
    {
        Queue::fake();
        $media = $this->upload();
        $this->attach($media);
        $first = $this->ocrJob($media);
        app(MediaService::class)->detachUsage($media->id, 'course_activity', $this->activityId, 'document');
        (new ProcessMediaProcessingJob($this->customerId, $first->id))->handle();
        $this->assertSame('cancelled', $this->ocrJob($media)->status);
        $this->attach($media);
        $second = $this->ocrJob($media);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, (int) $second->dispatch_generation);
        $this->assertSame(1, (int) $second->attempt);
        $this->assertSame($first->correlation_id, $second->correlation_id);
        $this->assertSame((int) $first->id, (int) $second->supersedes_job_id);
        $this->attach($media);
        $this->assertSame($second->id, $this->ocrJob($media)->id);
        (new ProcessMediaProcessingJob($this->customerId, $first->id))->handle();
        (new ProcessMediaProcessingJob($this->customerId, $second->id))->handle();
        $this->assertSame('ready', $this->ocrJob($media)->status);
        $this->assertDatabaseHas('media_processing_jobs', ['id' => $first->id, 'status' => 'cancelled']);
    }

    public function test_d2_native_spreadsheet_structure_and_d5_sheet_measurement(): void
    {
        $media = $this->upload('office.xlsx');
        $this->attach($media);
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile($this->customerId, $media->id,
            'structured_extraction', ['locale' => 'vi', 'structure' => 'cells'], $this->admin->id);
        $job = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'structured_extraction')->firstOrFail();
        $this->assertSame('ready', $job->status, (string) $job->error_code);
        $this->assertSame('sheet', $job->billable_unit_type);
        $this->assertGreaterThanOrEqual(1, (float) $job->billable_units);
        $this->assertDatabaseHas('media_extracted_tables', ['processing_job_id' => $job->id, 'locator_type' => 'sheet', 'extraction_method' => 'spreadsheet_cells']);
        $this->assertDatabaseHas('media_table_cells', ['text' => 'DOCUMENT_ACCEPTANCE_ALPHA']);
    }

    public function test_d3_aggregate_failure_preserves_ocr_and_old_structure_archives_on_new_ocr(): void
    {
        config(['media.processing.providers.structured_extraction' => 'fake', 'media.processing.versions.structured_extraction' => 'structure-test-v1']);
        $media = $this->upload(null, 'canonical text');
        $this->attach($media);
        $orchestrator = app(MediaProcessingOrchestrator::class);
        $profile = ['locale' => 'vi', 'structure' => 'layout'];
        $orchestrator->materializeOnDemandProfile($this->customerId, $media->id, 'structured_extraction', $profile);
        $old = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'structured_extraction')->firstOrFail();
        $this->assertSame('ready', $old->status, (string) $old->error_code);
        config(['media.processing.versions.ocr' => 'ocr-new']);
        $orchestrator->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        $this->assertDatabaseMissing('media_extracted_regions', ['processing_job_id' => $old->id, 'status' => 'ready']);
        $this->assertDatabaseHas('media_extracted_regions', ['processing_job_id' => $old->id, 'status' => 'archived']);
        $this->assertDatabaseHas('media_processing_jobs', ['id' => $old->id, 'status' => 'ready']);
        config(['media.processing.structured_extraction.max_extracted_characters' => 14]);
        $orchestrator->materializeOnDemandProfile($this->customerId, $media->id, 'structured_extraction', $profile);
        $new = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'structured_extraction')->latest('id')->firstOrFail();
        $this->assertNotSame($old->processing_version, $new->processing_version);
        $this->assertSame('structured_extraction_too_large', $new->error_code);
        $this->assertSame('ready', $this->ocrJob($media)->status);
        $this->assertSame('canonical text', $this->read()[0]['text']);
    }

    public function test_low_confidence_formula_keeps_region_and_is_read_as_failed_evidence(): void
    {
        config(['media.processing.providers.structured_extraction' => 'fake']);
        $media = $this->upload(null, 'Formula source text');
        $this->attach($media);
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'structured_extraction',
            ['locales' => ['vi'], 'structure' => 'layout'], $this->admin->id
        );

        $job = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'structured_extraction')->firstOrFail();
        $this->assertSame('ready', $job->status, (string) $job->error_code);
        $this->assertDatabaseHas('media_extracted_regions', ['processing_job_id' => $job->id, 'role' => 'formula', 'status' => 'ready']);
        $this->assertDatabaseHas('media_extracted_formulas', [
            'processing_job_id' => $job->id, 'normalization_status' => 'failed',
            'normalized_format' => null, 'normalized_value' => null,
        ]);
        $units = app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', $this->activityId, 'document', 'formula',
            null, null, null, 'test', [], null, false, ['vi']
        );
        $this->assertSame('x^2 + H2O = 0', $units[0]['text']);
        $this->assertSame('failed', $units[0]['structure']['normalization_status']);
        $this->assertNotNull($units[0]['structure']['bbox']);
        $this->assertNotNull($units[0]['structure']['crop']);
    }

    /**
     * ADR-0019 v1.8. Do tren tai lieu that (`tieng-han-so-cap-2-100.pdf`): ca 5
     * row formula deu do Docling gan label `formula`, va ca 5 deu la mau bien
     * doi ngu phap tieng Han hoac nhieu OCR. Region van phai giu role va van
     * doc duoc; chi evidence child bi tu choi, va viec tu choi khong duoc lam
     * hong revision.
     */
    public function test_formula_without_an_observed_operator_keeps_the_region_and_the_revision(): void
    {
        config(['media.processing.providers.structured_extraction' => 'fake']);
        $media = $this->upload(null, 'Formula source text');
        $this->attach($media);
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'structured_extraction',
            ['locales' => ['vi'], 'structure' => 'layout'], $this->admin->id
        );

        $job = DB::table('media_processing_jobs')->where('media_file_id', $media->id)
            ->where('job_type', 'structured_extraction')->firstOrFail();
        $this->assertSame('ready', $job->status, (string) $job->error_code);

        $regions = DB::table('media_extracted_regions')->where('processing_job_id', $job->id)
            ->where('role', 'formula')->orderBy('reading_order')->get();
        $this->assertCount(2, $regions, 'Ca hai formula region phai duoc giu.');
        $this->assertSame('가고 있다 가다', $regions[1]->text);

        $formulas = DB::table('media_extracted_formulas')->where('processing_job_id', $job->id)->get();
        $this->assertCount(1, $formulas, 'Chi formula co toan tu quan sat duoc moi sinh evidence child.');
        $this->assertSame($regions[0]->id, (int) $formulas[0]->region_id);
    }

    public function test_formula_evidence_rejects_long_prose_with_an_operator(): void
    {
        $method = new \ReflectionMethod(StructuredExtractionPersistenceService::class, 'formulaEvidenceQualifies');
        $service = app(StructuredExtractionPersistenceService::class);
        $region = [
            'bbox' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.5, 'height' => 0.2],
            'crop' => ['storage_key' => 'formula.png'],
            'formula' => ['raw_text' => 'Q = '.implode(' ', array_fill(0, 25, 'đoạn văn'))],
        ];

        $this->assertFalse($method->invoke($service, $region));
        $region['formula']['raw_text'] = 'Q = mc∆t';
        $this->assertTrue($method->invoke($service, $region));
    }

    public function test_unscored_ready_formula_keeps_provider_latex_without_inventing_confidence(): void
    {
        $method = new \ReflectionMethod(StructuredExtractionPersistenceService::class, 'normalizeFormulaEvidence');
        $region = ['role' => 'formula', 'formula' => [
            'raw_text' => 'Q = mc∆t', 'normalized_format' => 'latex',
            'normalized_value' => 'Q = mc\\Delta t', 'normalization_status' => 'ready',
            'confidence_score' => null,
        ]];

        $normalized = $method->invoke(app(StructuredExtractionPersistenceService::class), $region);
        $this->assertSame('ready', $normalized['formula']['normalization_status']);
        $this->assertSame('Q = mc\\Delta t', $normalized['formula']['normalized_value']);
        $this->assertNull($normalized['formula']['confidence_score']);
    }

    /**
     * Bang chung ngon ngu phai di xuong toi bang con, khong dung lai o hai cot
     * dominant tren region.
     */
    public function test_region_language_evidence_is_persisted_and_read_back(): void
    {
        config(['media.processing.providers.structured_extraction' => 'fake']);
        $media = $this->upload(null, 'Formula source text');
        $this->attach($media);
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
            $this->customerId, $media->id, 'structured_extraction',
            ['locales' => ['vi'], 'structure' => 'layout'], $this->admin->id
        );

        $region = DB::table('media_extracted_regions')->where('media_file_id', $media->id)
            ->where('role', 'paragraph')->firstOrFail();
        $this->assertDatabaseHas('media_region_languages', [
            'region_id' => $region->id, 'ordinal' => 1, 'script' => 'Latn', 'locale' => 'vi', 'char_count' => 13,
        ]);
        $this->assertSame('Latn', $region->script);
        $this->assertSame('vi', $region->detected_locale);

        // Mot lan doc region tra ve toan bo vung cua revision — 1.949 vung tren
        // tai lieu that. Bang con phai duoc nap mot lan, khong phai mot query
        // moi vung.
        $languageQueries = 0;
        DB::listen(function ($query) use (&$languageQueries): void {
            if (str_contains($query->sql, 'media_region_languages')) {
                $languageQueries++;
            }
        });
        $units = app(MediaReadService::class)->read(
            $this->admin->id, 'course_activity', $this->activityId, 'document', 'region',
            null, null, null, 'test', [], null, false, ['vi']
        );
        $this->assertSame(1, $languageQueries, 'Bang con phai duoc nap bang dung mot query cho ca revision.');
        $paragraph = collect($units)->firstWhere(fn (array $unit): bool => $unit['structure']['role'] === 'paragraph');
        $this->assertSame([['script' => 'Latn', 'locale' => 'vi', 'char_count' => 13]], $paragraph['structure']['languages']);
    }

    public function test_d3_missing_and_stale_canonical_never_publish_structure(): void
    {
        config(['media.processing.providers.structured_extraction' => 'fake']);
        Queue::fake();
        $media = $this->upload();
        $this->attach($media);
        $orchestrator = app(MediaProcessingOrchestrator::class);
        try {
            $orchestrator->materializeOnDemandProfile($this->customerId, $media->id, 'structured_extraction', ['locale' => 'vi', 'structure' => 'layout']);
            $this->fail('Structured materialization accepted missing canonical.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseMissing('media_processing_jobs', ['media_file_id' => $media->id, 'job_type' => 'structured_extraction']);
        }
        (new ProcessMediaProcessingJob($this->customerId, $this->ocrJob($media)->id))->handle();
        $orchestrator->materializeOnDemandProfile($this->customerId, $media->id, 'structured_extraction', ['locale' => 'vi', 'structure' => 'layout']);
        $old = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'structured_extraction')->firstOrFail();
        config(['media.processing.versions.ocr' => 'next-canonical']);
        $orchestrator->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        (new ProcessMediaProcessingJob($this->customerId, $this->ocrJob($media)->id))->handle();
        (new ProcessMediaProcessingJob($this->customerId, $old->id))->handle();
        $this->assertDatabaseHas('media_processing_jobs', ['id' => $old->id, 'status' => 'failed', 'error_code' => 'source_unavailable']);
        $this->assertDatabaseMissing('media_extracted_regions', ['processing_job_id' => $old->id]);
        $this->assertSame('ready', $this->ocrJob($media)->status);
    }

    public function test_d6_retry_budget_survives_cancelled_retry_and_reattach(): void
    {
        Queue::fake();
        $media = $this->upload();
        $this->attach($media);
        $first = $this->ocrJob($media);
        DB::table('media_processing_jobs')->where('id', $first->id)->update(['status' => 'failed', 'error_code' => 'provider_timeout', 'completed_at' => now()]);
        $orchestrator = app(MediaProcessingOrchestrator::class);
        $retry = $orchestrator->retry($this->customerId, $first->id);
        app(MediaService::class)->detachUsage($media->id, 'course_activity', $this->activityId, 'document');
        (new ProcessMediaProcessingJob($this->customerId, $retry->id))->handle();
        $this->attach($media);
        $resumed = $this->ocrJob($media);
        $this->assertSame(2, (int) $resumed->attempt);
        $this->assertSame(2, (int) $resumed->dispatch_generation);
        DB::table('media_processing_jobs')->where('id', $resumed->id)->update(['status' => 'failed', 'error_code' => 'provider_timeout', 'completed_at' => now()]);
        $last = $orchestrator->retry($this->customerId, $resumed->id);
        $this->assertSame(3, (int) $last->attempt);
        $this->assertSame(2, (int) $last->dispatch_generation);
        DB::table('media_processing_jobs')->where('id', $last->id)->update(['status' => 'failed', 'error_code' => 'provider_timeout', 'completed_at' => now()]);
        $this->expectException(\InvalidArgumentException::class);
        $orchestrator->retry($this->customerId, $last->id);
    }

    public function test_d2_old_spreadsheet_page_citation_remains_readable_after_sheet_revision(): void
    {
        $media = $this->upload('office.xlsx');
        $fingerprint = hash('sha256', $media->checksum.':document');
        DB::table('media_extracted_texts')->insert([
            'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'locale' => 'vi',
            'locator_type' => 'page', 'locator_value' => '1', 'sequence' => 1, 'text' => 'legacy full text', 'char_count' => 16,
            'extraction_method' => 'embedded_text', 'processing_version' => 'legacy-spreadsheet-v1',
            'source_fingerprint' => $fingerprint, 'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->attach($media);
        $this->assertSame('sheet', $this->read()[0]['locator']['type']);
        $old = app(MediaReadService::class)->read($this->admin->id, 'course_activity', $this->activityId, 'document',
            'extracted_text', 'vi', 'legacy-spreadsheet-v1', $fingerprint);
        $this->assertSame('page', $old[0]['locator']['type']);
        $this->assertSame('legacy full text', $old[0]['text']);
        $this->assertDatabaseHas('media_extracted_texts', ['media_file_id' => $media->id, 'processing_version' => 'legacy-spreadsheet-v1', 'status' => 'archived']);
    }

    public function test_d2_structured_spreadsheet_retry_cli_uses_recorded_canonical_identity(): void
    {
        Queue::fake();
        $media = $this->upload('office.xlsx');
        $this->attach($media);
        (new ProcessMediaProcessingJob($this->customerId, $this->ocrJob($media)->id))->handle();
        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile($this->customerId, $media->id,
            'structured_extraction', ['locale' => 'vi', 'structure' => 'cells']);
        $job = DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'structured_extraction')->firstOrFail();
        DB::table('media_processing_jobs')->where('id', $job->id)->update(['status' => 'failed', 'error_code' => 'provider_timeout', 'completed_at' => now()]);
        $this->artisan('media:reprocess', ['--customer' => $this->customerId, '--job' => $job->id, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('media_extracted_tables', 0);
        $this->artisan('media:reprocess', ['--customer' => $this->customerId, '--job' => $job->id])->assertSuccessful();
        $retry = DB::table('media_processing_jobs')->where('supersedes_job_id', $job->id)->firstOrFail();
        $this->assertSame($job->processing_version, $retry->processing_version);
        $this->assertSame($job->metadata, $retry->metadata);
        (new ProcessMediaProcessingJob($this->customerId, $retry->id))->handle();
        $this->assertDatabaseHas('media_processing_jobs', ['id' => $retry->id, 'status' => 'ready', 'billable_unit_type' => 'sheet']);
    }

    public function test_d2_old_pending_version_cannot_publish_new_sheet_semantics(): void
    {
        Queue::fake();
        $media = $this->upload('office.xlsx');
        $this->attach($media);
        $old = $this->ocrJob($media);
        DB::table('media_processing_jobs')->where('id', $old->id)->update([
            'processing_version' => 'legacy-ocr-v1',
            'idempotency_key' => str_replace($old->processing_version, 'legacy-ocr-v1', $old->idempotency_key),
        ]);
        (new ProcessMediaProcessingJob($this->customerId, $old->id))->handle();
        $this->assertDatabaseHas('media_processing_jobs', ['id' => $old->id, 'status' => 'failed', 'error_code' => 'unsupported_output_profile']);
        $this->assertDatabaseMissing('media_extracted_texts', ['processing_job_id' => $old->id]);
        app(MediaProcessingOrchestrator::class)->materializeForCourseActivity($this->customerId, $media->id, 'vi', $this->admin->id);
        $new = $this->ocrJob($media);
        $this->assertNotSame($old->id, $new->id);
        (new ProcessMediaProcessingJob($this->customerId, $new->id))->handle();
        $this->assertDatabaseHas('media_extracted_texts', ['processing_job_id' => $new->id, 'locator_type' => 'sheet']);
    }

    private function upload(?string $fixture = null, string $text = 'Nội dung tài liệu local.'): object
    {
        $file = $fixture === null
            ? UploadedFile::fake()->createWithContent('lesson.txt', $text)
            : new UploadedFile(base_path('tests/Fixtures/document/'.$fixture), $fixture, 'application/pdf', null, true);

        return app(MediaService::class)->upload($file, [
            'file_type' => 'document', 'module' => 'course', 'entity_type' => 'activities',
            'entity_id' => $this->activityId, 'purpose' => 'document',
        ], $this->admin->id);
    }

    private function attach(object $media): void
    {
        app(MediaService::class)->attachUsage($media->id, 'course_activity', $this->activityId, 'document', ['processing_locale' => 'vi']);
    }

    private function ocrJob(object $media): object
    {
        return DB::table('media_processing_jobs')->where('media_file_id', $media->id)->where('job_type', 'ocr')->orderByDesc('id')->firstOrFail();
    }

    private function read(?string $locale = 'vi'): array
    {
        return app(MediaReadService::class)->read($this->admin->id, 'course_activity', $this->activityId, 'document', 'extracted_text', $locale);
    }

    private function assertReadError(string $expected, ?string $locale = 'vi'): void
    {
        try {
            $this->read($locale);
            $this->fail('Read must fail with '.$expected);
        } catch (MediaReadException $error) {
            $this->assertSame($expected, $error->errorCode);
        }
    }

    private function requirePdfRuntime(): void
    {
        foreach (['pdfinfo', 'pdftotext', 'pdftoppm', 'tesseract'] as $binary) {
            $configured = (string) config('media.processing.local_document.'.$binary.'_binary');
            if (! is_executable($configured) && (new ExecutableFinder)->find($configured) === null) {
                $this->markTestSkipped('Real PDF runtime missing: '.$binary);
            }
        }
    }

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
}
