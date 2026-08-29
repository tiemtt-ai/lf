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
    public function materializeForCourseActivity(int $customerId, int $mediaFileId, ?string $locale, ?int $actorId, bool $structuredExtraction = false, bool $speechToText = true): void
    {
        DB::transaction(function () use ($customerId, $mediaFileId, $locale, $actorId, $structuredExtraction, $speechToText): void {
            $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->lockForUpdate()->first();
            if (! $media) {
                throw new InvalidArgumentException('Media File not found.');
            }

            try {
                $canonicalLocale = $this->canonicalLocaleFor($media, $locale, $speechToText);
            } catch (InvalidArgumentException) {
                DB::table('media_files')->where('id', $mediaFileId)->where('customer_id', $customerId)->update([
                    'status' => 'failed', 'processing_error_code' => 'required_profile_configuration_missing', 'updated_at' => now(),
                ]);

                return;
            }

            if ($media->processing_locale !== null && $canonicalLocale !== null && $media->processing_locale !== $canonicalLocale) {
                DB::table('media_files')->where('id', $mediaFileId)->where('customer_id', $customerId)->update([
                    'status' => 'failed', 'processing_error_code' => 'required_profile_configuration_missing', 'updated_at' => now(),
                ]);

                return;
            }

            DB::table('media_files')->where('id', $mediaFileId)->where('customer_id', $customerId)->update([
                'processing_locale' => $media->processing_locale ?? $canonicalLocale,
                'processing_error_code' => null,
                'updated_at' => now(),
            ]);

            foreach ($this->requiredProfiles($media->file_type, $canonicalLocale, $speechToText) as [$jobType, $profile]) {
                $this->createInitialJob($customerId, $media, $jobType, $profile, $actorId);
            }

            // Opt-in, khong nam trong required set: structured extraction that bai
            // khong duoc lam file mat 'ready', va moi truong chua co Python runtime
            // van upload duoc binh thuong.
            if ($structuredExtraction && $media->file_type === 'document') {
                $this->createInitialJob($customerId, $media, 'structured_extraction',
                    $this->profiles->canonical(['locale' => (string) $canonicalLocale, 'structure' => 'layout']),
                    $actorId);
            }
        });
    }

    /**
     * Khoi tao STT lan dau cho Media legacy duoc upload truoc khi locale bat buoc.
     * Day khong phai retry: Media da co bat ky STT job nao phai di theo state
     * machine/retry chain hien co, va locale da ghi khong duoc sua tai day.
     */
    public function initializeLegacySpeechToText(int $customerId, int $activityId, string $locale, ?int $actorId): void
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

            $canonicalLocale = $this->profiles->canonicalLocale($locale);
            $usageMetadata = json_decode((string) ($usage->metadata ?? ''), true);
            $usageMetadata = is_array($usageMetadata) ? $usageMetadata : [];
            $usageMetadata['speech_to_text'] = true;
            $usageMetadata['processing_locale'] = $canonicalLocale;
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
                $this->profiles->canonical(['diarization' => 'off', 'locale' => $canonicalLocale]),
                $actorId,
            );
        });
    }

    public function retry(int $customerId, int $failedJobId, ?int $actorId = null): object
    {
        return DB::transaction(function () use ($customerId, $failedJobId, $actorId): object {
            $failed = DB::table('media_processing_jobs')->where('customer_id', $customerId)->where('id', $failedJobId)->lockForUpdate()->first();
            if (! $failed || $failed->status !== 'failed'
                || ! in_array($failed->error_code, ['provider_timeout', 'provider_unavailable', 'rate_limited'], true)
                || (int) $failed->attempt >= 3) {
                throw new InvalidArgumentException('Job is not retry eligible.');
            }
            $highest = DB::table('media_processing_jobs')->where('customer_id', $customerId)
                ->where('media_file_id', $failed->media_file_id)->where('job_type', $failed->job_type)
                ->where('source_fingerprint', $failed->source_fingerprint)->where('processing_version', $failed->processing_version)
                ->where('output_profile_hash', $failed->output_profile_hash)->max('attempt');
            if ((int) $highest !== (int) $failed->attempt) {
                throw new InvalidArgumentException('Only the highest attempt may retry.');
            }
            $attempt = (int) $failed->attempt + 1;
            $key = implode(':', [$failed->job_type, $failed->media_file_id, $failed->source_fingerprint, $failed->processing_version, $failed->output_profile_hash, $attempt]);
            $id = DB::table('media_processing_jobs')->insertGetId([
                'customer_id' => $customerId, 'media_file_id' => $failed->media_file_id, 'job_type' => $failed->job_type,
                'status' => 'pending', 'attempt' => $attempt, 'supersedes_job_id' => $failed->id,
                'idempotency_key' => $key, 'correlation_id' => $failed->correlation_id,
                'source_fingerprint' => $failed->source_fingerprint, 'processing_version' => $failed->processing_version,
                'output_profile' => $failed->output_profile, 'output_profile_hash' => $failed->output_profile_hash,
                'provider' => $failed->provider, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
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
            'ocr' => ['layout', 'locale'],
            'speech_to_text' => ['diarization', 'locale'],
            'caption' => ['format', 'locale'],
            'thumbnail' => ['size'],
            'transcode' => ['preset'],
            'structured_extraction' => ['locale', 'structure'],
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
        $profile = $this->profiles->canonical($parameters);
        DB::transaction(function () use ($customerId, $mediaFileId, $jobType, $profile, $actorId): void {
            $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->lockForUpdate()->first();
            if (! $media) {
                throw new InvalidArgumentException('Media File not found.');
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
    public function planForCourseActivity(int $customerId, int $mediaFileId, ?string $locale): array
    {
        $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->first();
        if (! $media) {
            throw new InvalidArgumentException('Media File not found.');
        }

        $canonicalLocale = $this->canonicalLocaleFor($media, $locale);
        if ($media->processing_locale !== null && $canonicalLocale !== null && $media->processing_locale !== $canonicalLocale) {
            throw new InvalidArgumentException('Requested locale conflicts with the recorded processing locale.');
        }

        $fingerprint = $this->sourceFingerprint($media);
        $plan = [];
        foreach ($this->requiredProfiles($media->file_type, $canonicalLocale) as [$jobType, $profile]) {
            $version = $this->versionFor($jobType);
            $key = $this->initialIdempotencyKey($media, $jobType, $fingerprint, $version, $this->profiles->hash($profile));
            $existing = DB::table('media_processing_jobs')->where('customer_id', $customerId)
                ->where('idempotency_key', $key)->value('id');
            $plan[] = [
                'job_type' => $jobType,
                'output_profile' => $profile,
                'provider' => $this->providerFor($jobType),
                'processing_version' => $version,
                'existing_job_id' => $existing !== null ? (int) $existing : null,
            ];
        }

        return $plan;
    }

    /** @return array<int, array{string, string}> */
    private function requiredProfiles(string $fileType, ?string $locale, bool $speechToText = true): array
    {
        $jobs = [['virus_scan', '']];
        if ($fileType === 'document') {
            $jobs[] = ['ocr', $this->profiles->canonical(['layout' => 'preserve', 'locale' => (string) $locale])];
        }
        if ($fileType === 'video' || ($fileType === 'audio' && $speechToText)) {
            $jobs[] = ['speech_to_text', $this->profiles->canonical(['diarization' => 'off', 'locale' => (string) $locale])];
        }
        if ($fileType === 'video') {
            $jobs[] = ['caption', $this->profiles->canonical(['format' => 'vtt', 'locale' => (string) $locale])];
        }

        return $jobs;
    }

    private function createInitialJob(int $customerId, object $media, string $jobType, string $profile, ?int $actorId): void
    {
        $version = $this->versionFor($jobType);
        $provider = $this->providerFor($jobType);
        $fingerprint = $this->sourceFingerprint($media);
        $profileHash = $this->profiles->hash($profile);
        $key = $this->initialIdempotencyKey($media, $jobType, $fingerprint, $version, $profileHash);
        $id = DB::table('media_processing_jobs')->where('customer_id', $customerId)->where('idempotency_key', $key)->value('id');
        if (! $id) {
            try {
                $id = DB::table('media_processing_jobs')->insertGetId([
                    'customer_id' => $customerId, 'media_file_id' => $media->id, 'job_type' => $jobType,
                    'status' => 'pending', 'attempt' => 1, 'idempotency_key' => $key, 'correlation_id' => (string) Str::uuid(),
                    'source_fingerprint' => $fingerprint, 'processing_version' => $version, 'output_profile' => $profile,
                    'output_profile_hash' => $profileHash, 'provider' => $provider, 'created_by' => $actorId,
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
        $jobId = (int) $id;
        DB::afterCommit(fn () => ProcessMediaProcessingJob::dispatch($customerId, $jobId));
    }

    private function canonicalLocaleFor(object $media, ?string $locale, bool $speechToText = true): ?string
    {
        return ($media->file_type !== 'audio' || $speechToText)
            && in_array($media->file_type, ['document', 'audio', 'video'], true)
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

    private function initialIdempotencyKey(object $media, string $jobType, string $fingerprint, string $version, string $profileHash): string
    {
        return implode(':', [$jobType, $media->id, $fingerprint, $version, $profileHash, 1]);
    }

    private function providerFor(string $jobType): string
    {
        return (string) config("media.processing.providers.$jobType", 'unconfigured');
    }

    private function versionFor(string $jobType): string
    {
        return (string) config("media.processing.versions.$jobType", 'unconfigured-v1');
    }
}
