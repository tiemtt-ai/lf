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

    public function materializeForCourseActivity(int $customerId, int $mediaFileId, ?string $locale, ?int $actorId): void
    {
        DB::transaction(function () use ($customerId, $mediaFileId, $locale, $actorId): void {
            $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaFileId)->lockForUpdate()->first();
            if (! $media) {
                throw new InvalidArgumentException('Media File not found.');
            }

            try {
                $canonicalLocale = in_array($media->file_type, ['document', 'audio', 'video'], true)
                    ? $this->profiles->canonicalLocale((string) $locale)
                    : null;
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

            foreach ($this->requiredProfiles($media->file_type, $canonicalLocale) as [$jobType, $profile]) {
                $this->createInitialJob($customerId, $media, $jobType, $profile, $actorId);
            }
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

    /** @return array<int, array{string, string}> */
    private function requiredProfiles(string $fileType, ?string $locale): array
    {
        $jobs = [['virus_scan', '']];
        if ($fileType === 'document') {
            $jobs[] = ['ocr', $this->profiles->canonical(['layout' => 'preserve', 'locale' => (string) $locale])];
        }
        if (in_array($fileType, ['audio', 'video'], true)) {
            $jobs[] = ['speech_to_text', $this->profiles->canonical(['diarization' => 'off', 'locale' => (string) $locale])];
        }
        if ($fileType === 'video') {
            $jobs[] = ['caption', $this->profiles->canonical(['format' => 'vtt', 'locale' => (string) $locale])];
        }

        return $jobs;
    }

    private function createInitialJob(int $customerId, object $media, string $jobType, string $profile, ?int $actorId): void
    {
        $version = (string) config("media.processing.versions.$jobType", 'unconfigured-v1');
        $provider = (string) config("media.processing.providers.$jobType", 'unconfigured');
        if (! is_string($media->checksum) || trim($media->checksum) === '') {
            throw new InvalidArgumentException('Source checksum is required for processing.');
        }
        $fingerprint = hash('sha256', $media->checksum.':'.$media->file_type);
        $profileHash = $this->profiles->hash($profile);
        $key = implode(':', [$jobType, $media->id, $fingerprint, $version, $profileHash, 1]);
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
}
