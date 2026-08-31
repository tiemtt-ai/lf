<?php

namespace App\Jobs;

use App\Contracts\MediaProcessingProvider;
use App\Exceptions\DocumentCommandFailure;
use App\Exceptions\DocumentUsageException;
use App\Services\CaptionAssetStorage;
use App\Services\DoclingStructuredExtractionProvider;
use App\Services\DocumentCanonicalRevision;
use App\Services\DocumentProcessingEligibility;
use App\Services\DocumentTextUnits;
use App\Services\FakeMediaProcessingProvider;
use App\Services\FasterWhisperSpeechToTextProvider;
use App\Services\LocalDocumentProcessingProvider;
use App\Services\MediaProcessingOrchestrator;
use App\Services\RegionCropStorage;
use App\Services\StructuredExtractionPersistenceService;
use App\Services\TranscriptVttCaptionProvider;
use App\Services\VideoAudioWorkspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProcessMediaProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $customerId, public readonly int $processingJobId)
    {
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $claimed = DB::transaction(function (): ?array {
            $job = DB::table('media_processing_jobs')->where('customer_id', $this->customerId)->where('id', $this->processingJobId)->lockForUpdate()->first();
            if (! $job || $job->status !== 'pending') {
                return null;
            }
            $media = DB::table('media_files')->where('customer_id', $this->customerId)->where('id', $job->media_file_id)->lockForUpdate()->first();
            if (! $media) {
                return null;
            }
            if ($media->status === 'deleted'
                || ($media->file_type === 'document' && in_array($job->job_type, ['ocr', 'structured_extraction'], true)
                    && ! app(DocumentProcessingEligibility::class)->hasActiveUsage($this->customerId, (int) $media->id))) {
                DB::table('media_processing_jobs')->where('id', $job->id)->where('customer_id', $this->customerId)->update([
                    'status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now(),
                ]);

                return null;
            }
            $alreadyProcessing = DB::table('media_processing_jobs')->where('customer_id', $this->customerId)
                ->where('media_file_id', $job->media_file_id)->where('job_type', $job->job_type)
                ->where('status', 'processing')->where('id', '<>', $job->id)->exists();
            if ($alreadyProcessing) {
                // A fresh queue envelope preserves the same provider attempt.
                // Sync cannot honor delay: completion and scheduled recovery
                // drain its durable pending row instead of recursing here.
                if ($media->file_type === 'document' && in_array($job->job_type, ['ocr', 'structured_extraction'], true)
                    && config('queue.connections.'.($this->connection ?? config('queue.default')).'.driver') !== 'sync') {
                    $connection = $this->connection ?? $this->job?->getConnectionName();
                    $queue = $this->queue ?? $this->job?->getQueue();
                    DB::afterCommit(fn () => self::dispatch($this->customerId, (int) $job->id)
                        ->onConnection($connection)->onQueue($queue)->delay(now()->addMinute()));
                }

                return null;
            }
            DB::table('media_processing_jobs')->where('id', $job->id)->where('customer_id', $this->customerId)->update(['status' => 'processing', 'started_at' => now(), 'updated_at' => now()]);

            return [$media, $job];
        });
        if (! $claimed) {
            return;
        }
        [$media, $job] = $claimed;
        $result = null;

        try {
            if ($job->job_type === 'structured_extraction') {
                app(DocumentCanonicalRevision::class)->forStructure($this->customerId, $media, $job, (string) $this->profileValue($job->output_profile, 'locale'));
            }
            $result = $this->provider((string) $job->provider)->process($media, $job);
            try {
                DB::transaction(fn () => $this->persistSuccess($media, $job, $result));
            } catch (Throwable $persistFailure) {
                // Transaction rollback duoc, object storage thi khong. Object da
                // ghi trong provider phai bi xoa, neu khong chung song sot ma
                // khong con row nao tham chieu.
                $this->purgeRegionCrops($media, $job);
                $this->purgeCaptionAsset($media, $result);

                throw $persistFailure;
            }
        } catch (Throwable $e) {
            if (in_array($job->job_type, ['ocr', 'structured_extraction'], true)) {
                // Operator diagnostics contain only identifiers and exception
                // categories, never message, stack, source text or stderr.
                $diagnostic = $e;
                while (! $diagnostic instanceof DocumentCommandFailure && $diagnostic->getPrevious() !== null) {
                    $diagnostic = $diagnostic->getPrevious();
                }
                Log::warning('Document processing failed.', [
                    'customer_id' => $this->customerId, 'processing_job_id' => (int) $job->id,
                    'exception' => $e::class, 'cause' => $e->getPrevious() ? $e->getPrevious()::class : null,
                    'exit_code' => $diagnostic instanceof DocumentCommandFailure ? $diagnostic->exitCode : null,
                    'signal' => $diagnostic instanceof DocumentCommandFailure ? $diagnostic->signal : null,
                ]);
            }
            DB::transaction(function () use ($job, $e, $result): void {
                $knownErrorCodes = [
                    'infected_source', 'provider_unavailable', 'provider_timeout', 'rate_limited',
                    'unsupported_source', 'corrupt_source', 'source_unavailable',
                    'no_extractable_text', 'extracted_text_too_large', 'missing_canonical_locale',
                    'page_limit_exceeded', 'source_expansion_limit_exceeded',
                    'structured_extraction_too_large', 'structured_extraction_invalid',
                    'audio_limit_exceeded', 'transcript_invalid', 'locale_unavailable',
                    'video_limit_exceeded', 'audio_extraction_limit_exceeded', 'audio_extraction_failed',
                    'video_stt_disabled', 'video_stt_unqualified', 'extraction_profile_mismatch',
                    'caption_invalid', 'caption_too_large', 'caption_write_failed', 'transcript_unavailable',
                    'ambiguous_source',
                    'unsupported_output_profile',
                ];
                $errorCode = $e instanceof RuntimeException && in_array($e->getMessage(), $knownErrorCodes, true)
                    ? $e->getMessage()
                    : 'processing_failed';
                $measured = $result['usage'] ?? null;
                $pages = $e instanceof DocumentUsageException ? $e->pages
                    : (is_array($measured) && in_array($measured['unit_type'] ?? null, ['page', 'sheet'], true)
                        && is_int($measured['units'] ?? null) && $measured['units'] >= 0 ? $measured['units'] : null);
                $unitType = $e instanceof DocumentUsageException ? $e->unitType : ($measured['unit_type'] ?? null);
                $usage = $pages !== null && in_array($job->job_type, ['ocr', 'structured_extraction'], true)
                    ? ['billable_units' => $pages, 'billable_unit_type' => $unitType] : [];
                DB::table('media_processing_jobs')->where('customer_id', $this->customerId)->where('id', $job->id)->where('status', 'processing')->update([
                    'status' => 'failed', 'completed_at' => now(),
                    'error_code' => $errorCode,
                    'error_message' => in_array($job->job_type, ['ocr', 'structured_extraction'], true)
                        ? $errorCode : mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now(),
                ] + $usage);
                if ($job->job_type === 'virus_scan' && ($errorCode === 'infected_source'
                    || $errorCode === 'provider_unavailable' || (int) $job->attempt >= 3)) {
                    DB::table('media_files')->where('customer_id', $this->customerId)->where('id', $job->media_file_id)->update([
                        'status' => 'failed', 'processing_error_code' => $errorCode, 'updated_at' => now(),
                    ]);
                }
            });
        } finally {
            if ($media->file_type === 'document' && in_array($job->job_type, ['ocr', 'structured_extraction'], true)
                && config('queue.connections.'.($this->connection ?? config('queue.default')).'.driver') === 'sync') {
                $pending = DB::table('media_processing_jobs')->where('customer_id', $this->customerId)
                    ->where('media_file_id', $media->id)->where('job_type', $job->job_type)
                    ->where('status', 'pending')->orderBy('id')->value('id');
                if ($pending !== null) {
                    DB::afterCommit(fn () => self::dispatch($this->customerId, (int) $pending));
                }
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $documentJob = DB::table('media_processing_jobs')->where('customer_id', $this->customerId)
            ->where('id', $this->processingJobId)->whereIn('job_type', ['ocr', 'structured_extraction'])->exists();
        $transitioned = DB::table('media_processing_jobs')
            ->where('customer_id', $this->customerId)
            ->where('id', $this->processingJobId)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_code' => 'provider_timeout',
                'error_message' => $documentJob ? 'provider_timeout'
                    : mb_substr($exception?->getMessage() ?? 'Queue worker terminated the processing job.', 0, 1000),
                'updated_at' => now(),
            ]);

        if ($documentJob && $transitioned === 0) {
            // A repeated failure notification must not purge a committed revision.
            return;
        }

        // Worker bi giet giua chung van co the da day crop len storage. Khong don
        // o day thi khong nhanh nao con chay de don.
        $job = DB::table('media_processing_jobs')->where('customer_id', $this->customerId)
            ->where('id', $this->processingJobId)->first();
        $media = $job === null ? null : DB::table('media_files')->where('customer_id', $this->customerId)
            ->where('id', $job->media_file_id)->first();
        if ($job !== null && $media !== null) {
            $this->purgeRegionCrops($media, $job);
            $this->purgeVideoAudioWorkspace($media, $job);
        }
    }

    /**
     * Audio tach tu video la audio DAY DU cua hoc lieu nam ngoai moi kiem soat
     * retention. Worker bi giet truoc `finally` thi day la nhanh duy nhat con
     * chay de don no.
     */
    private function purgeVideoAudioWorkspace(object $media, object $job): void
    {
        if ($job->job_type !== 'speech_to_text' || $media->file_type !== 'video') {
            return;
        }

        try {
            if (! app(VideoAudioWorkspace::class)->purge($media, $job)) {
                Log::error('Video audio workspace purge incomplete; extracted audio may remain on disk.', [
                    'media_file_id' => (int) $media->id,
                    'processing_job_id' => (int) $job->id,
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('Video audio workspace purge failed.', [
                'media_file_id' => (int) $media->id,
                'processing_job_id' => (int) $job->id,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Caption asset duoc ghi trong provider, truoc transaction persist. Persist
     * hong ma khong don thi file VTT nam lai khong row nao tham chieu.
     *
     * @param  array<string, mixed>  $result
     */
    private function purgeCaptionAsset(object $media, array $result): void
    {
        $key = $result['storage_key'] ?? null;
        if (! is_string($key) || $key === '') {
            return;
        }

        try {
            if (! app(CaptionAssetStorage::class)->purge($media, $key)) {
                Log::error('Caption asset purge incomplete after a failed persist.', [
                    'media_file_id' => (int) $media->id, 'storage_key' => $key,
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('Caption asset purge failed.', [
                'media_file_id' => (int) $media->id, 'storage_key' => $key,
                'exception' => $exception::class,
            ]);
        }
    }

    /** Chi structured extraction sinh crop; job khac khong co gi de don. */
    private function purgeRegionCrops(object $media, object $job): void
    {
        if ($job->job_type !== 'structured_extraction') {
            return;
        }

        try {
            if (app(RegionCropStorage::class)->purgeRevision($media, $job, RegionCropStorage::localeOf($job))) {
                return;
            }
            // `false` nghia la object van con. Nuot no di nghia la bao da xoa PII
            // trong khi no van nam do — va sweeper phai biet de quay lai.
            Log::error('Region crop purge incomplete after a failed revision.', [
                'media_file_id' => (int) $media->id,
                'processing_job_id' => (int) $job->id,
                'retry_with' => 'php artisan media:purge-deleted-storage',
            ]);

            return;
        } catch (Throwable $exception) {
            Log::error('Region crop purge failed after a failed revision.', [
                'media_file_id' => (int) $media->id,
                'processing_job_id' => (int) $job->id,
                'exception' => $exception::class,
            ]);
        }
    }

    private function provider(string $provider): MediaProcessingProvider
    {
        return match ($provider) {
            'fake' => app(FakeMediaProcessingProvider::class),
            'local_document' => app(LocalDocumentProcessingProvider::class),
            'docling_local' => app(DoclingStructuredExtractionProvider::class),
            'transcript_vtt' => app(TranscriptVttCaptionProvider::class),
            'faster_whisper_local' => app(FasterWhisperSpeechToTextProvider::class),
            default => throw new RuntimeException('provider_unavailable'),
        };
    }

    /** @param array<string, mixed> $result */
    private function persistSuccess(object $media, object $job, array $result): void
    {
        if (in_array($job->job_type, ['ocr', 'structured_extraction'], true)) {
            $currentJob = DB::table('media_processing_jobs')->where('customer_id', $this->customerId)
                ->where('id', $job->id)->lockForUpdate()->first();
            if ($currentJob === null || $currentJob->status !== 'processing') {
                throw new RuntimeException('source_unavailable');
            }
        }
        $currentMedia = DB::table('media_files')
            ->where('customer_id', $this->customerId)
            ->where('id', $media->id)
            ->lockForUpdate()
            ->first(['status', 'processing_error_code']);
        if ($currentMedia === null || $currentMedia->status === 'deleted') {
            throw new RuntimeException('source_unavailable');
        }

        $outputType = null;
        $outputId = null;
        $now = now();
        $locale = $this->profileValue($job->output_profile, 'locale');
        if ($job->job_type === 'virus_scan') {
            if (! ($result['clean'] ?? false)) {
                throw new RuntimeException('infected_source');
            }
            if ($media->file_type !== 'document' || $currentMedia->processing_error_code !== 'required_profile_configuration_missing') {
                DB::table('media_files')->where('customer_id', $this->customerId)->where('id', $media->id)->update(['status' => 'ready', 'processing_error_code' => null, 'updated_at' => $now]);
            }
        } elseif ($job->job_type === 'ocr') {
            foreach (app(DocumentTextUnits::class)->validate($result['units'] ?? []) as $unit) {
                $outputId = DB::table('media_extracted_texts')->insertGetId([
                    'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'processing_job_id' => $job->id,
                    'locale' => $locale, 'locator_type' => $unit['locator_type'], 'locator_value' => $unit['locator_value'],
                    'sequence' => $unit['sequence'], 'text' => $unit['text'], 'char_count' => mb_strlen($unit['text']),
                    'extraction_method' => $unit['extraction_method'] ?? 'ocr', 'provider' => $job->provider, 'processing_version' => $job->processing_version,
                    'source_fingerprint' => $job->source_fingerprint, 'status' => 'ready',
                    'metadata' => isset($unit['metadata']) ? json_encode($unit['metadata']) : null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->archiveSupersededRevisions('media_extracted_texts', $media, $job, ['locale' => $locale], $now);
            foreach (['media_extracted_regions', 'media_extracted_tables'] as $table) {
                DB::table($table)->where('customer_id', $this->customerId)->where('media_file_id', $media->id)
                    ->where('locale', $locale)->where('status', 'ready')->update(['status' => 'archived', 'updated_at' => $now]);
            }
            $outputType = 'extracted_text';
        } elseif ($job->job_type === 'speech_to_text') {
            $units = $this->validatedTranscriptUnits($result['units'] ?? []);
            foreach ($units as $unit) {
                $outputId = DB::table('media_transcripts')->insertGetId([
                    'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'locale' => $locale,
                    'provider' => $job->provider, 'status' => 'ready', 'text' => $unit['text'], 'processing_job_id' => $job->id,
                    'processing_version' => $job->processing_version, 'source_fingerprint' => $job->source_fingerprint,
                    'locator_type' => $unit['locator_type'], 'locator_value' => $unit['locator_value'],
                    'metadata' => isset($unit['metadata']) ? json_encode($unit['metadata'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->archiveSupersededRevisions('media_transcripts', $media, $job, ['locale' => $locale], $now);
            $this->archiveCaptionsBuiltOnSupersededTranscript($media, $job, $locale, $now);
            $this->materializeCaptionAfterTranscript($media, $locale);
            $outputType = 'transcript';
        } elseif ($job->job_type === 'caption') {
            $captionType = $this->profileValue($job->output_profile, 'format');
            $outputId = DB::table('media_captions')->insertGetId([
                'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'locale' => $locale,
                'caption_type' => $captionType, 'storage_key' => $result['storage_key'],
                'status' => 'ready', 'processing_job_id' => $job->id, 'processing_version' => $job->processing_version,
                // Caption dung TU transcript, nen phai ghi revision nguon.
                // `source_fingerprint` khong dien dat duoc dieu do: no la van tay
                // binary goc, khong doi khi transcript len revision moi.
                'transcript_processing_version' => $result['transcript_processing_version'] ?? null,
                'source_fingerprint' => $job->source_fingerprint, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->archiveSupersededRevisions('media_captions', $media, $job, ['locale' => $locale, 'caption_type' => $captionType], $now);
            $outputType = 'caption';
        } elseif ($job->job_type === 'structured_extraction') {
            $output = app(StructuredExtractionPersistenceService::class)
                ->persist($this->customerId, $media, $job, (string) $locale, $result);
            $outputType = $output['output_type'];
            $outputId = $output['output_id'];
            $coverage = $output['coverage'] ?? null;
        }

        $update = [
            'status' => 'ready', 'output_type' => $outputType, 'output_id' => $outputId,
            'completed_at' => $now, 'updated_at' => $now,
        ];
        if (in_array($job->job_type, ['ocr', 'structured_extraction'], true) && isset($result['usage'])) {
            $usage = $result['usage'];
            if (! is_array($usage) || ! in_array($usage['unit_type'] ?? null, ['page', 'sheet'], true)
                || ! is_int($usage['units'] ?? null) || $usage['units'] < 0) {
                throw new RuntimeException('processing_failed');
            }
            $update['billable_units'] = $usage['units'];
            $update['billable_unit_type'] = $usage['unit_type'];
        }
        if (isset($coverage)) {
            // Giu metadata cu, chi them mot khoa: coverage la du kien do duoc, khong
            // phai trang thai nghiep vu, nen no khong thay the gi dang co.
            $existing = json_decode((string) ($job->metadata ?? ''), true);
            $update['metadata'] = json_encode(
                array_replace(is_array($existing) ? $existing : [], ['structure_coverage' => $coverage]),
                JSON_THROW_ON_ERROR
            );
        }
        DB::table('media_processing_jobs')->where('customer_id', $this->customerId)->where('id', $job->id)->update($update);
        if ($job->job_type === 'ocr') {
            $metadata = json_decode((string) ($job->metadata ?? ''), true) ?: [];
            $requested = ($metadata['structured_requested'] ?? false) === true;
            $usages = DB::table('media_file_usages')->where('customer_id', $this->customerId)->where('media_file_id', $media->id)
                ->where('owner_type', 'course_activity')->where('usage_type', 'document')->where('status', 'active')->pluck('metadata');
            foreach ($usages as $usage) {
                $requested = $requested || ((json_decode((string) $usage, true)['structured_extraction'] ?? false) === true);
            }
            if ($requested && $usages->isNotEmpty()) {
                $extension = strtolower((string) ($media->extension ?? pathinfo($media->storage_key, PATHINFO_EXTENSION)));
                DB::afterCommit(function () use ($media, $locale, $extension): void {
                    try {
                        app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile($this->customerId, (int) $media->id, 'structured_extraction',
                            ['locale' => (string) $locale, 'structure' => in_array($extension, ['xls', 'xlsx'], true) ? 'cells' : 'layout']);
                    } catch (\InvalidArgumentException) {
                        // Detach or canonical supersession between commits: OCR remains ready.
                    }
                });
            }
        }

    }

    /**
     * LF-Media-Processing-Contract § Stale và revision: output is never
     * overwritten. A new fingerprint or processing version supersedes the
     * previous revision, which must stay readable forever as `archived`.
     *
     * @param  array<string, string|null>  $scope  Columns that separate coexisting
     *                                             revisions (locale, caption format)
     *                                             from superseded ones.
     */
    private function archiveSupersededRevisions(string $table, object $media, object $job, array $scope, mixed $now): void
    {
        DB::table($table)
            ->where('customer_id', $this->customerId)
            ->where('media_file_id', $media->id)
            ->where($scope)
            ->where('status', 'ready')
            ->where(fn ($query) => $query
                ->where('processing_version', '<>', $job->processing_version)
                ->orWhere('source_fingerprint', '<>', $job->source_fingerprint))
            ->update(['status' => 'archived', 'updated_at' => $now]);
    }

    /**
     * Caption duoc dung TU transcript (Owner 2026-08-29, DOC-CONFLICT-0024), nen
     * mot transcript revision moi lam moi caption dung tu ban cu tro thanh stale.
     *
     * `source_fingerprint` cua caption khong bat duoc dieu do: no la van tay cua
     * BINARY GOC nen khong doi khi transcript len revision moi. Phai so bang
     * `transcript_processing_version`.
     *
     * Chay trong CHINH transaction dang archive transcript: hai ban ghi khong duoc
     * phep roi vao trang thai nua chung, nguoi hoc xem phu de cua ban phien am da
     * bi thay the.
     *
     * Caption khong do job sinh ra (`transcript_processing_version IS NULL`) khong
     * dung tu transcript nao, nen khong bi dung toi.
     */
    private function archiveCaptionsBuiltOnSupersededTranscript(object $media, object $job, ?string $locale, mixed $now): void
    {
        DB::table('media_captions')
            ->where('customer_id', $this->customerId)
            ->where('media_file_id', $media->id)
            ->where('locale', $locale)
            ->where('status', 'ready')
            ->whereNotNull('transcript_processing_version')
            ->where(fn ($query) => $query
                ->where('transcript_processing_version', '<>', $job->processing_version)
                ->orWhere('source_fingerprint', '<>', $job->source_fingerprint))
            ->update(['status' => 'archived', 'updated_at' => $now]);
    }

    /**
     * Caption la required-nhung-deferred: no chi duoc materialize SAU khi
     * transcript revision hien hanh da commit `ready` (DOC-CONFLICT-0025).
     *
     * Goi trong cung transaction ghi transcript, nhung viec dispatch job that su
     * xay ra sau commit — orchestrator dung `DB::afterCommit`. Neu transaction
     * rollback thi khong co caption job nao duoc gui di.
     *
     * Idempotent qua idempotency key: STT chay lai duoi mot revision moi se sinh
     * mot caption chain moi, con chay lai cung revision thi khong tao trung.
     */
    private function materializeCaptionAfterTranscript(object $media, ?string $locale): void
    {
        if ($media->file_type !== 'video' || $locale === null) {
            return;
        }

        try {
            app(MediaProcessingOrchestrator::class)->materializeOnDemandProfile(
                $this->customerId, (int) $media->id, 'caption',
                ['format' => (string) config('media.processing.caption.format', 'vtt'), 'locale' => $locale],
            );
        } catch (Throwable $exception) {
            // Khong lam hong transcript vua ghi: transcript la output doc lap va
            // van dung duoc. Caption thieu se hien o trang thai cua chinh no.
            Log::error('Caption materialization after transcript failed.', [
                'media_file_id' => (int) $media->id, 'locale' => $locale,
                'exception' => $exception::class, 'message' => $exception->getMessage(),
            ]);
        }
    }

    private function profileValue(string $profile, string $key): ?string
    {
        foreach (array_filter(explode(';', $profile)) as $pair) {
            [$candidate, $value] = explode('=', $pair, 2);
            if ($candidate === $key) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function validatedTranscriptUnits(mixed $units): array
    {
        if (! is_array($units) || $units === []) {
            throw new RuntimeException('no_extractable_text');
        }

        $validated = [];
        $previousEnd = null;
        foreach ($units as $unit) {
            if (! is_array($unit) || ($unit['locator_type'] ?? null) !== 'timespan'
                || ! is_string($unit['locator_value'] ?? null)
                || preg_match('/^(0|[1-9][0-9]*)-(0|[1-9][0-9]*)$/D', $unit['locator_value'], $matches) !== 1
                || ! is_string($unit['text'] ?? null) || trim($unit['text']) === '') {
                throw new RuntimeException('transcript_invalid');
            }
            $start = (int) $matches[1];
            $end = (int) $matches[2];
            if ($start >= $end || ($previousEnd !== null && $start < $previousEnd)) {
                throw new RuntimeException('transcript_invalid');
            }
            $previousEnd = $end;
            $validated[] = $unit;
        }

        return $validated;
    }
}
