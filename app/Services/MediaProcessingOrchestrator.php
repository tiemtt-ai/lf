<?php

namespace App\Services;

use App\Jobs\ProcessMediaProcessingJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MediaProcessingOrchestrator
{
    public function __construct(private readonly MediaOutputProfile $profiles) {}

    public function materializeVirusScanOnUpload(int $customerId, int $mediaFileId, ?int $actorId): void
    {
        DB::transaction(function () use ($customerId, $mediaFileId, $actorId): void {
            $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->lockForUpdate()->first();
            if (! $media) {
                return;
            }
            $this->createInitialJob($customerId, $media, 'virus_scan', '', $actorId);
        });
    }

    /**
     * @param  bool  $structuredExtraction  Opt-in cua tac gia tren form upload. Docling khong thay
     *                                      the OCR — no them lop cau truc ben tren cung text.
     */
    public function materializeForCourseActivity(int $customerId, int $mediaFileId, string|array|null $locale, ?int $actorId, bool $structuredExtraction = false, bool $speechToText = true, bool $reattach = false): void
    {
        DB::transaction(function () use ($customerId, $mediaFileId, $locale, $actorId, $structuredExtraction, $speechToText, $reattach): void {
            $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->lockForUpdate()->first();
            if (! $media) {
                throw new InvalidArgumentException('Media File not found.');
            }

            $this->assertDocumentUsage($customerId, $media);
            $this->assertAudioUsage($customerId, $media, $speechToText);

            try {
                $documentLocales = $media->file_type === 'document'
                    ? app(DocumentLanguageProfile::class)->canonical(is_array($locale) ? $locale : (string) $locale)
                    : null;
                $speechLocales = null;
                if (in_array($media->file_type, ['audio', 'video'], true) && $speechToText) {
                    try {
                        $speechLocales = app(SpeechLanguageProfile::class)->canonical(is_array($locale) ? $locale : (string) $locale);
                    } catch (InvalidArgumentException $exception) {
                        // Legacy single-locale calls historically materialize a job and
                        // let the provider expose `locale_unavailable`. Preserve that
                        // observable state; array profiles use the stricter D7 contract.
                        if (is_array($locale) || $exception->getMessage() !== 'speech_language_profile_unsupported') {
                            throw $exception;
                        }
                        $speechLocales = [$this->profiles->canonicalLocale((string) $locale)];
                    }
                }
                $canonicalLocale = $documentLocales[0] ?? $speechLocales[0] ?? null;
            } catch (InvalidArgumentException $exception) {
                $profileError = $locale !== null && in_array($exception->getMessage(), [
                    'document_language_profile_invalid', 'document_language_profile_unsupported',
                    'speech_language_profile_invalid', 'speech_language_profile_unsupported',
                ], true) ? $exception->getMessage() : 'required_profile_configuration_missing';
                DB::table('media_files')->where('id', $mediaFileId)->where('customer_id', $customerId)->update([
                    'status' => 'failed', 'processing_error_code' => $profileError, 'updated_at' => now(),
                ]);

                return;
            }

            DB::table('media_files')->where('id', $mediaFileId)->where('customer_id', $customerId)->update([
                'processing_locale' => $media->processing_locale ?? $canonicalLocale,
                'processing_error_code' => null,
                'updated_at' => now(),
            ]);

            foreach ($this->initialMaterializationProfiles($media->file_type, $canonicalLocale, $speechToText, $documentLocales, $speechLocales) as [$jobType, $profile]) {
                $this->createInitialJob($customerId, $media, $jobType, $profile, $actorId, $reattach, $structuredExtraction);
            }

            // Opt-in, khong nam trong required set: structured extraction that bai
            // khong duoc lam file mat 'ready', va moi truong chua co Python runtime
            // van upload duoc binh thuong.
            if ($structuredExtraction && $media->file_type === 'document') {
                $this->createInitialJob($customerId, $media, 'structured_extraction',
                    $this->profiles->canonical(['locales' => app(DocumentLanguageProfile::class)->serialize($documentLocales ?? [$canonicalLocale]), 'structure' => $this->structureFor($media)]),
                    $actorId, $reattach);
            }
        });
    }

    /**
     * Khoi tao STT lan dau cho Media legacy duoc upload truoc khi locale bat buoc.
     * Day khong phai retry: Media da co bat ky STT job nao phai di theo state
     * machine/retry chain hien co, va locale da ghi khong duoc sua tai day.
     */
    public function initializeLegacySpeechToText(int $customerId, int $activityId, string|array $locale, ?int $actorId): void
    {
        DB::transaction(function () use ($customerId, $activityId, $locale, $actorId): void {
            $usage = DB::table('media_file_usages')
                ->where('customer_id', $customerId)
                ->where('owner_type', 'course_activity')
                ->where('owner_id', $activityId)
                ->where('usage_type', 'audio')
                ->where('status', 'active')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if (! $usage) {
                throw new InvalidArgumentException('Active audio usage not found.');
            }
            $mediaFileId = (int) $usage->media_file_id;
            $media = DB::table('media_files')
                ->where('customer_id', $customerId)
                ->where('id', $mediaFileId)
                ->lockForUpdate()
                ->first();

            if (! $media || $media->status !== 'ready' || $media->file_type !== 'audio') {
                throw new InvalidArgumentException('Media is not eligible for initial transcription.');
            }
            if ($media->processing_locale !== null) {
                throw new InvalidArgumentException('Processing locale is already fixed.');
            }
            if (DB::table('media_processing_jobs')->where('customer_id', $customerId)
                ->where('media_file_id', $mediaFileId)->where('job_type', 'speech_to_text')->exists()) {
                throw new InvalidArgumentException('A transcription job already exists.');
            }

            $locales = app(SpeechLanguageProfile::class)->canonical($locale);
            $canonicalLocale = $locales[0];
            $usageMetadata = json_decode((string) ($usage->metadata ?? ''), true);
            $usageMetadata = is_array($usageMetadata) ? $usageMetadata : [];
            $usageMetadata['speech_to_text'] = true;
            $usageMetadata['processing_locale'] = $canonicalLocale;
            $usageMetadata['processing_locales'] = $locales;
            DB::table('media_file_usages')->where('customer_id', $customerId)->where('id', $usage->id)->update([
                'metadata' => json_encode($usageMetadata, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->update([
                'processing_locale' => $canonicalLocale,
                'processing_error_code' => null,
                'updated_at' => now(),
            ]);

            $media->processing_locale = $canonicalLocale;
            $this->createInitialJob(
                $customerId,
                $media,
                'speech_to_text',
                $this->profiles->canonical(['diarization' => 'off'] + (count($locales) === 1
                    ? ['locale' => $canonicalLocale]
                    : ['locales' => app(SpeechLanguageProfile::class)->serialize($locales)])),
                $actorId,
            );
        });
    }

    public function retry(int $customerId, int $failedJobId, ?int $actorId = null): object
    {
        return DB::transaction(function () use ($customerId, $failedJobId, $actorId): object {
            $failed = DB::table('media_processing_jobs')->where('customer_id', $customerId)->where('id', $failedJobId)->lockForUpdate()->first();
            if (! $failed || $failed->status !== 'failed'
                || ! in_array($failed->error_code, ['provider_timeout', 'provider_unavailable', 'rate_limited', 'transcript_invalid'], true)
                || (int) $failed->attempt >= 3) {
                throw new InvalidArgumentException('Job is not retry eligible.');
            }
            if (in_array($failed->job_type, ['ocr', 'structured_extraction'], true)
                || $failed->job_type === 'speech_to_text') {
                $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $failed->media_file_id)->lockForUpdate()->first();
                if (! $media) {
                    throw new InvalidArgumentException('Media File not found.');
                }
                $this->assertDocumentUsage($customerId, $media);
                $this->assertAudioUsage($customerId, $media, true);
            }
            $highest = DB::table('media_processing_jobs')->where('customer_id', $customerId)
                ->where('media_file_id', $failed->media_file_id)->where('job_type', $failed->job_type)
                ->where('source_fingerprint', $failed->source_fingerprint)->where('processing_version', $failed->processing_version)
                ->where('output_profile_hash', $failed->output_profile_hash)->max('attempt');
            if (DB::table('media_processing_jobs')->where('customer_id', $customerId)->where('supersedes_job_id', $failed->id)->exists() || (int) $highest !== (int) $failed->attempt) {
                throw new InvalidArgumentException('Only the highest attempt may retry.');
            }
            $attempt = (int) $failed->attempt + 1;
            $key = $this->generationKey($customerId, $failed->media_file_id, $failed->job_type, $failed->source_fingerprint, $failed->processing_version, $failed->output_profile_hash, $attempt, (int) $failed->dispatch_generation);
            $id = DB::table('media_processing_jobs')->insertGetId([
                'customer_id' => $customerId, 'media_file_id' => $failed->media_file_id, 'job_type' => $failed->job_type,
                'status' => 'pending', 'attempt' => $attempt, 'dispatch_generation' => $failed->dispatch_generation, 'supersedes_job_id' => $failed->id,
                'metadata' => in_array($failed->job_type, ['ocr', 'structured_extraction'], true) ? $failed->metadata : null,
                'idempotency_key' => $key, 'correlation_id' => $failed->correlation_id,
                'source_fingerprint' => $failed->source_fingerprint, 'processing_version' => $failed->processing_version,
                'output_profile' => $failed->output_profile, 'output_profile_hash' => $failed->output_profile_hash,
                'provider' => $failed->provider, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (in_array($failed->job_type, ['ocr', 'structured_extraction', 'speech_to_text'], true)) {
                $languageProfile = $failed->job_type === 'speech_to_text'
                    ? app(SpeechLanguageProfile::class)
                    : app(DocumentLanguageProfile::class);
                $languageProfile->persistForJob(
                    $customerId,
                    (int) $id,
                    $languageProfile->fromProfile((string) $failed->output_profile)
                );
            }
            $jitter = random_int(80, 120) / 100;
            $delay = (int) round(60 * (2 ** ($attempt - 2)) * $jitter);
            DB::afterCommit(fn () => ProcessMediaProcessingJob::dispatch($customerId, (int) $id)->delay(now()->addSeconds($delay)));

            return DB::table('media_processing_jobs')->where('customer_id', $customerId)->where('id', $id)->first();
        });
    }

    /** @param array<string, string> $parameters */
    public function materializeOnDemandProfile(int $customerId, int $mediaFileId, string $jobType, array $parameters, ?int $actorId = null): void
    {
        $allowed = [
            'ocr' => isset($parameters['locales']) ? ['layout', 'locales'] : ['layout', 'locale'],
            'speech_to_text' => isset($parameters['locales']) ? ['diarization', 'locales'] : ['diarization', 'locale'],
            'caption' => isset($parameters['locales']) ? ['format', 'locale', 'locales'] : ['format', 'locale'],
            'thumbnail' => ['size'],
            'transcode' => ['preset'],
            'structured_extraction' => isset($parameters['locales']) ? ['locales', 'structure'] : ['locale', 'structure'],
        ];
        $parameterKeys = array_keys($parameters);
        sort($parameterKeys);
        $expectedKeys = $allowed[$jobType] ?? [];
        sort($expectedKeys);
        if ($parameterKeys !== $expectedKeys) {
            throw new InvalidArgumentException('Unsupported output profile.');
        }
        if (isset($parameters['locale'])) {
            $parameters['locale'] = $this->profiles->canonicalLocale($parameters['locale']);
        }
        if (isset($parameters['locales'])) {
            $parameters['locales'] = in_array($jobType, ['speech_to_text', 'caption'], true)
                ? app(SpeechLanguageProfile::class)->serialize($parameters['locales'])
                : app(DocumentLanguageProfile::class)->serialize($parameters['locales']);
        }
        if (($jobType === 'ocr' && ($parameters['layout'] ?? null) !== 'preserve')
            || ($jobType === 'structured_extraction' && ! in_array($parameters['structure'] ?? null, ['layout', 'cells'], true))) {
            throw new InvalidArgumentException('Unsupported output profile.');
        }
        $profile = $this->profiles->canonical($parameters);
        DB::transaction(function () use ($customerId, $mediaFileId, $jobType, $profile, $actorId): void {
            $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->lockForUpdate()->first();
            if (! $media) {
                throw new InvalidArgumentException('Media File not found.');
            }
            if ($jobType === 'structured_extraction') {
                $locales = app(DocumentLanguageProfile::class)->fromProfile($profile);
                if (app(DocumentCanonicalRevision::class)->current($customerId, (int) $media->id, $this->sourceFingerprint($media), $locales) === null) {
                    throw new InvalidArgumentException('Canonical OCR revision is not ready.');
                }
                $extension = strtolower((string) ($media->extension ?? pathinfo($media->storage_key, PATHINFO_EXTENSION)));
                $expected = in_array($extension, ['xls', 'xlsx'], true) ? 'cells' : 'layout';
                if (($this->profiles->parse($profile)['structure'] ?? null) !== $expected) {
                    throw new InvalidArgumentException('Unsupported output profile.');
                }
            }
            $this->createInitialJob($customerId, $media, $jobType, $profile, $actorId);
        });
    }

    /**
     * Read-only mirror of materializeForCourseActivity, for operator inspection
     * before a chain that costs a provider call is enqueued.
     *
     * @return array<int, array{job_type: string, output_profile: string, provider: string, processing_version: string, existing_job_id: int|null}>
     */
    public function planForCourseActivity(int $customerId, int $mediaFileId, string|array|null $locale): array
    {
        $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->first();
        if (! $media) {
            throw new InvalidArgumentException('Media File not found.');
        }

        $documentLocales = $media->file_type === 'document'
            ? app(DocumentLanguageProfile::class)->canonical(is_array($locale) ? $locale : (string) $locale)
            : null;
        $speechLocales = in_array($media->file_type, ['audio', 'video'], true)
            ? app(SpeechLanguageProfile::class)->canonical(is_array($locale) ? $locale : (string) $locale)
            : null;
        $canonicalLocale = $documentLocales[0] ?? $speechLocales[0] ?? null;

        $fingerprint = $this->sourceFingerprint($media);
        $plan = [];
        foreach ($this->initialMaterializationProfiles($media->file_type, $canonicalLocale, true, $documentLocales, $speechLocales) as [$jobType, $profile]) {
            if (in_array($jobType, ['ocr', 'structured_extraction'], true)) {
                $this->assertDocumentUsage($customerId, $media);
            }
            $version = $this->versionFor($jobType, $media, $this->profiles->parse($profile));
            $key = $this->initialIdempotencyKey($media, $jobType, $fingerprint, $version, $this->profiles->hash($profile));
            $existing = DB::table('media_processing_jobs')->where('customer_id', $customerId)
                ->when($media->file_type === 'document' && $jobType === 'ocr',
                    fn ($q) => $q->where('media_file_id', $media->id)->where('job_type', $jobType)->where('source_fingerprint', $fingerprint)
                        ->where('processing_version', $version)->where('output_profile_hash', $this->profiles->hash($profile)),
                    fn ($q) => $q->where('idempotency_key', $key))
                ->orderByDesc('dispatch_generation')->orderByDesc('attempt')->value('id');
            $plan[] = [
                'job_type' => $jobType,
                'output_profile' => $profile,
                'provider' => $this->providerFor($jobType, $media),
                'processing_version' => $version,
                'existing_job_id' => $existing !== null ? (int) $existing : null,
            ];
        }

        return $plan;
    }

    /** @return array<int, array{string, string}> */
    /**
     * Profile duoc materialize NGAY luc upload — khong phai toan bo required set.
     *
     * `caption` van la output bat buoc cua video, nhung deferred: no chi duoc tao
     * sau khi STT commit mot transcript revision `ready`. Ten cu
     * `requiredProfiles()` vi the noi sai pham vi cua chinh no.
     */
    /** Transcript revision `ready` hien hanh cua mot Media/locale, neu co dung mot. */
    private function currentTranscriptRevision(object $media, string $locale, ?array $profile = null): ?string
    {
        $query = DB::table('media_transcripts')
            ->where('customer_id', $media->customer_id)
            ->where('media_file_id', $media->id)
            ->where('locale', $locale)
            ->where('status', 'ready');
        if ($profile !== null) {
            $jobIds = DB::table('media_processing_jobs')->where('customer_id', $media->customer_id)
                ->where('media_file_id', $media->id)->where('job_type', 'speech_to_text')
                ->get(['id', 'output_profile'])->filter(function (object $job) use ($profile): bool {
                    try {
                        return app(SpeechLanguageProfile::class)->fromProfile((string) $job->output_profile) === $profile;
                    } catch (InvalidArgumentException) {
                        return false;
                    }
                })->pluck('id')->all();
            $query->whereIn('processing_job_id', $jobIds);
        }
        $versions = $query
            ->distinct()
            ->pluck('processing_version')
            ->all();

        return count($versions) === 1 ? (string) $versions[0] : null;
    }

    private function initialMaterializationProfiles(string $fileType, ?string $locale, bool $speechToText = true, ?array $documentLocales = null, ?array $speechLocales = null): array
    {
        $jobs = [['virus_scan', '']];
        if ($fileType === 'document') {
            $jobs[] = ['ocr', $this->profiles->canonical([
                'layout' => 'preserve',
                'locales' => app(DocumentLanguageProfile::class)->serialize($documentLocales ?? [$locale]),
            ])];
        }
        $videoSttEligible = (bool) config('media.processing.speech_to_text.video_enabled', false)
            && app(VideoSttQualification::class)->isQualified();
        if (($fileType === 'video' && $speechToText && $videoSttEligible) || ($fileType === 'audio' && $speechToText)) {
            $speechLocales ??= [(string) $locale];
            $language = count($speechLocales) === 1
                ? ['locale' => $speechLocales[0]]
                : ['locales' => app(SpeechLanguageProfile::class)->serialize($speechLocales)];
            $jobs[] = ['speech_to_text', $this->profiles->canonical(['diarization' => 'off'] + $language)];
        }
        // Caption KHONG thuoc initial required set. Amendment 2.19 / conflict
        // 0025 chot rang caption chi duoc materialize sau khi STT da commit mot
        // transcript revision `ready`. Tao no o day se dispatch dong thoi voi
        // STT; khi video gate tat thi con te hon: caption ton tai trong khi
        // transcript bi quyet dinh khong sinh ra.

        return $jobs;
    }

    private function createInitialJob(int $customerId, object $media, string $jobType, string $profile, ?int $actorId, bool $reattach = false, bool $requestStructure = false): void
    {
        if (in_array($jobType, ['ocr', 'structured_extraction'], true)) {
            $this->assertDocumentUsage($customerId, $media);
        }
        if ($jobType === 'speech_to_text') {
            $this->assertAudioUsage($customerId, $media, true);
        }
        $metadata = $jobType === 'ocr' && $requestStructure ? ['structured_requested' => true] : [];
        if ($jobType === 'structured_extraction') {
            $locales = app(DocumentLanguageProfile::class)->fromProfile($profile);
            $input = app(DocumentCanonicalRevision::class)->current($customerId, (int) $media->id, $this->sourceFingerprint($media), $locales);
            if ($input === null) {
                return; // Initial opt-in is recorded on OCR and active usage, then dispatched after ready.
            }
            $metadata['canonical_processing_job_id'] = (int) $input->id;
        }
        $version = $this->versionFor($jobType, $media, $this->profiles->parse($profile));
        $provider = $this->providerFor($jobType, $media);
        $fingerprint = $this->sourceFingerprint($media);
        $profileHash = $this->profiles->hash($profile);
        $key = $this->initialIdempotencyKey($media, $jobType, $fingerprint, $version, $profileHash);
        $latest = DB::table('media_processing_jobs')->where('customer_id', $customerId)
            ->where('media_file_id', $media->id)->where('job_type', $jobType)
            ->where('source_fingerprint', $fingerprint)->where('processing_version', $version)
            ->where('output_profile_hash', $profileHash)
            ->when($media->file_type !== 'document' || ! in_array($jobType, ['ocr', 'structured_extraction'], true), fn ($q) => $q->where('idempotency_key', $key))
            ->orderByDesc('dispatch_generation')->orderByDesc('attempt')->first();
        $successor = $reattach
            && (($media->file_type === 'document' && in_array($jobType, ['ocr', 'structured_extraction'], true))
                || (in_array($media->file_type, ['audio', 'video'], true) && $jobType === 'speech_to_text'))
            && $latest?->status === 'cancelled' && $latest->started_at === null
            && $latest->error_code === null;
        $generation = $successor ? (int) $latest->dispatch_generation + 1 : 1;
        $attempt = $successor ? (int) $latest->attempt : 1;
        if ($successor) {
            $key = $this->generationKey($customerId, $media->id, $jobType, $fingerprint, $version, $profileHash, $attempt, $generation);
        }
        $id = $successor ? null : $latest?->id;
        if (! $id) {
            try {
                $id = DB::table('media_processing_jobs')->insertGetId([
                    'customer_id' => $customerId, 'media_file_id' => $media->id, 'job_type' => $jobType,
                    'status' => 'pending', 'attempt' => $attempt, 'dispatch_generation' => $generation,
                    'supersedes_job_id' => $successor ? $latest->id : null,
                    'idempotency_key' => $key, 'correlation_id' => $successor ? $latest->correlation_id : (string) Str::uuid(),
                    'source_fingerprint' => $fingerprint, 'processing_version' => $version, 'output_profile' => $profile,
                    'output_profile_hash' => $profileHash, 'provider' => $provider, 'created_by' => $actorId,
                    'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (QueryException $exception) {
                $id = DB::table('media_processing_jobs')->where('customer_id', $customerId)
                    ->where('idempotency_key', $key)->value('id');
                if (! $id) {
                    throw $exception;
                }
            }
        }
        if (in_array($jobType, ['ocr', 'structured_extraction', 'speech_to_text'], true)) {
            $languageProfile = $jobType === 'speech_to_text'
                ? app(SpeechLanguageProfile::class)
                : app(DocumentLanguageProfile::class);
            try {
                $languageProfile->persistForJob($customerId, (int) $id, $languageProfile->fromProfile($profile));
            } catch (InvalidArgumentException $exception) {
                if ($jobType !== 'speech_to_text' || $exception->getMessage() !== 'speech_language_profile_unsupported') {
                    throw $exception;
                }
            }
        }
        if ($jobType === 'ocr' && $requestStructure) {
            $pending = DB::table('media_processing_jobs')->where('customer_id', $customerId)->where('id', $id)->where('status', 'pending')->first();
            if ($pending !== null) {
                $meta = json_decode((string) ($pending->metadata ?? ''), true) ?: [];
                DB::table('media_processing_jobs')->where('customer_id', $customerId)->where('id', $id)->where('status', 'pending')
                    ->update(['metadata' => json_encode($meta + ['structured_requested' => true], JSON_THROW_ON_ERROR)]);
            }
        }
        $jobId = (int) $id;
        DB::afterCommit(fn () => ProcessMediaProcessingJob::dispatch($customerId, $jobId));
    }

    private function structureFor(object $media): string
    {
        $extension = strtolower((string) ($media->extension ?? pathinfo($media->storage_key, PATHINFO_EXTENSION)));

        return in_array($extension, ['xls', 'xlsx'], true) ? 'cells' : 'layout';
    }

    private function assertDocumentUsage(int $customerId, object $media): void
    {
        if ($media->file_type === 'document' && ($media->status === 'deleted'
            || ! app(DocumentProcessingEligibility::class)->hasActiveUsage($customerId, (int) $media->id))) {
            throw new InvalidArgumentException('Active Document Course usage is required.');
        }
    }

    private function assertAudioUsage(int $customerId, object $media, bool $speechToText): void
    {
        $requiresUsage = $media->file_type === 'audio'
            || ($media->file_type === 'video' && (bool) config('media.processing.speech_to_text.video_enabled', false)
                && app(VideoSttQualification::class)->isQualified());
        if ($speechToText && $requiresUsage && ($media->status === 'deleted'
            || ! app(SpeechToTextProcessingEligibility::class)
                ->hasActiveUsage($customerId, (int) $media->id, $media->file_type))) {
            throw new InvalidArgumentException($media->file_type === 'audio'
                ? 'Active Audio Course usage is required.'
                : 'Active Video Course usage is required.');
        }
    }

    private function canonicalLocaleFor(object $media, ?string $locale, bool $speechToText = true): ?string
    {
        return ($media->file_type === 'document'
            || (in_array($media->file_type, ['audio', 'video'], true) && $speechToText))
            ? $this->profiles->canonicalLocale((string) $locale)
            : null;
    }

    private function sourceFingerprint(object $media): string
    {
        if (! is_string($media->checksum) || trim($media->checksum) === '') {
            throw new InvalidArgumentException('Source checksum is required for processing.');
        }

        return hash('sha256', $media->checksum.':'.$media->file_type);
    }

    private function generationKey(int $customerId, int $mediaId, string $type, string $fingerprint, string $version, string $profileHash, int $attempt, int $generation): string
    {
        $tuple = [$type, $mediaId, $fingerprint, $version, $profileHash, $attempt];

        return $generation === 1 ? implode(':', $tuple)
            : hash('sha256', json_encode([$customerId, ...$tuple, $generation], JSON_THROW_ON_ERROR));
    }

    private function initialIdempotencyKey(object $media, string $jobType, string $fingerprint, string $version, string $profileHash): string
    {
        return implode(':', [$jobType, $media->id, $fingerprint, $version, $profileHash, 1]);
    }

    public function providerFor(string $jobType, ?object $media = null): string
    {
        if ($jobType === 'structured_extraction' && $media !== null && $this->structureFor($media) === 'cells') {
            return 'local_document';
        }

        return (string) config("media.processing.providers.$jobType", 'unconfigured');
    }

    /**
     * Version cau hinh hien hanh cho mot job type tren mot Media cu the.
     *
     * Public vi consumer ngoai orchestrator — vi du `media:reprocess` khi so
     * chain cu voi cau hinh hien tai — phai dung DUNG mot phep dung version.
     * Doc thang `config(...)` o noi khac se bo qua extraction profile cua video
     * va bao "version da doi" trong khi khong co gi doi.
     */
    public function versionFor(string $jobType, ?object $media = null, array $parameters = []): string
    {
        $version = (string) config("media.processing.versions.$jobType", 'unconfigured-v1');

        if ($jobType === 'ocr' && ($media->file_type ?? null) === 'document' && $this->providerFor('ocr') === 'local_document') {
            $version .= '+document-v2';
            if (strlen($version) > 100) {
                $version = 'document-v2-'.hash('sha256', $version);
            }
        }

        if (in_array($jobType, ['ocr', 'structured_extraction'], true) && ($media->file_type ?? null) === 'document') {
            $languageProfile = app(DocumentLanguageProfile::class)->serialize(
                isset($parameters['locales']) ? explode(',', (string) $parameters['locales']) : [(string) ($parameters['locale'] ?? '')]
            );
            $version .= '+lp-'.substr(hash('sha256', $languageProfile), 0, 12);
            if (strlen($version) > 100) {
                $version = 'document-'.hash('sha256', $version);
            }
        }

        if ($jobType === 'speech_to_text' && isset($parameters['locales'])) {
            $languageProfile = app(SpeechLanguageProfile::class)->serialize(
                explode(',', (string) $parameters['locales'])
            );
            $version .= '+lp-'.substr(hash('sha256', $languageProfile), 0, 12);
            if (strlen($version) > 100) {
                $version = 'speech-'.hash('sha256', $version);
            }
        }

        if ($jobType === 'structured_extraction' && $media !== null && (isset($parameters['locale']) || isset($parameters['locales']))) {
            $languageProfile = isset($parameters['locales'])
                ? app(DocumentLanguageProfile::class)->canonical(explode(',', (string) $parameters['locales']))
                : [(string) $parameters['locale']];
            $input = app(DocumentCanonicalRevision::class)->current((int) $media->customer_id, (int) $media->id, $this->sourceFingerprint($media), $languageProfile);
            if ($input !== null) {
                // OCR text-quality va language packs doi output, nen phai nam
                // trong revision identity. Neu chi doi config ma giu version cu,
                // job se bi dedupe va revision moi khong bao gio duoc sinh ra.
                $documentSemantics = $this->providerFor('structured_extraction') === 'docling_local'
                    ? [
                        'latin_locale' => 'profile-candidates-v1',
                        'text_quality' => [
                            'algorithm' => 'observed-symbol-ratio-with-letter-control-v2',
                            'max' => (float) config('media.processing.structured_extraction.text_symbol_ratio_max', 0.2),
                            'min_letters' => (int) config('media.processing.structured_extraction.text_quality_min_letters', 10),
                        ],
                        'crop_ocr_languages' => (array) config('media.processing.structured_extraction.crop_ocr_languages', []),
                    ]
                    : null;
                $identity = [
                    $version, $this->structureFor($media), (int) $input->id,
                    $input->processing_version,
                ];
                if ($documentSemantics !== null) {
                    $identity[] = $documentSemantics;
                }
                $version = 'structure-v2-'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
            }
        }

        // Caption duoc dung TU transcript, nen identity cua no phai gom ca
        // revision nguon. Neu khong: caption dung tu transcript v1 va tu v2 co
        // cung processing_version, cung idempotency key — va lan materialize thu
        // hai bi dedupe. STT chay lai se khong bao gio sinh caption moi, trong khi
        // caption cu da bi stale cascade archive. Video mat phu de vinh vien.
        if ($jobType === 'caption' && $media !== null && isset($parameters['locale'])) {
            $speechProfile = isset($parameters['locales'])
                ? app(SpeechLanguageProfile::class)->canonical(explode(',', (string) $parameters['locales']))
                : null;
            $source = $this->currentTranscriptRevision($media, (string) $parameters['locale'], $speechProfile);
            if ($source !== null) {
                $version .= '+from-'.substr(hash('sha256', $source), 0, 8);
            }
        }

        // Transcript cua video phu thuoc CACH audio duoc tach ra, nhung
        // `source_fingerprint` chi la van tay cua binary goc. Khong dua
        // extraction profile vao version thi doi mot tham so ffmpeg se lam
        // transcript doi noi dung ma khong sinh revision moi.
        //
        // Chi nhanh video duoc them hau to. Them cho ca audio se doi identity
        // cua moi transcript audio dang chay, archive toan bo va bat chay lai —
        // Amendment Record 2.19 § 1 canh bao dung dieu nay.
        if ($jobType === 'speech_to_text' && ($media->file_type ?? null) === 'video') {
            $version .= '+'.app(VideoSpeechToTextProfile::class)->label();
            // `processing_version` la VARCHAR(100). Base version + label da la
            // 88 ky tu voi cau hinh khuyen nghi, va `ffmpeg_version` la chuoi
            // INVENTORY tu do do deployment khai (`7:6.1.1-3ubuntu5` day len 99).
            // Tran thi MariaDB nem 22001 ngay trong afterCommit cua attachUsage:
            // usage da commit, job khong bao gio duoc tao. SQLite khong cuong che
            // do dai nen loi nay vo hinh o local. Cung guard nhu nhanh OCR.
            if (strlen($version) > 100) {
                $version = 'video-stt-'.hash('sha256', $version);
            }
        }

        return $version;
    }
}
