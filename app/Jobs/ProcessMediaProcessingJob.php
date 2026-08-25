<?php

namespace App\Jobs;

use App\Contracts\MediaProcessingProvider;
use App\Services\FakeMediaProcessingProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProcessMediaProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

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
            $alreadyProcessing = DB::table('media_processing_jobs')->where('customer_id', $this->customerId)
                ->where('media_file_id', $job->media_file_id)->where('job_type', $job->job_type)
                ->where('status', 'processing')->where('id', '<>', $job->id)->exists();
            if ($alreadyProcessing) {
                return null;
            }
            DB::table('media_processing_jobs')->where('id', $job->id)->where('customer_id', $this->customerId)->update(['status' => 'processing', 'started_at' => now(), 'updated_at' => now()]);

            return [$media, $job];
        });
        if (! $claimed) {
            return;
        }
        [$media, $job] = $claimed;

        try {
            $result = $this->provider((string) $job->provider)->process($media, $job);
            DB::transaction(fn () => $this->persistSuccess($media, $job, $result));
        } catch (Throwable $e) {
            DB::transaction(function () use ($job, $e): void {
                $errorCode = $e->getMessage() === 'infected_source'
                    ? 'infected_source'
                    : ($e instanceof RuntimeException && $e->getMessage() === 'provider_unavailable' ? 'provider_unavailable' : 'processing_failed');
                DB::table('media_processing_jobs')->where('customer_id', $this->customerId)->where('id', $job->id)->update([
                    'status' => 'failed', 'completed_at' => now(),
                    'error_code' => $errorCode,
                    'error_message' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now(),
                ]);
                if ($job->job_type === 'virus_scan' && ($errorCode === 'infected_source'
                    || $errorCode === 'provider_unavailable' || (int) $job->attempt >= 3)) {
                    DB::table('media_files')->where('customer_id', $this->customerId)->where('id', $job->media_file_id)->update([
                        'status' => 'failed', 'processing_error_code' => $errorCode, 'updated_at' => now(),
                    ]);
                }
            });
        }
    }

    private function provider(string $provider): MediaProcessingProvider
    {
        return match ($provider) {
            'fake' => app(FakeMediaProcessingProvider::class),
            default => throw new RuntimeException('provider_unavailable'),
        };
    }

    /** @param array<string, mixed> $result */
    private function persistSuccess(object $media, object $job, array $result): void
    {
        $outputType = null;
        $outputId = null;
        $now = now();
        $locale = $this->profileValue($job->output_profile, 'locale');
        if ($job->job_type === 'virus_scan') {
            if (! ($result['clean'] ?? false)) {
                throw new RuntimeException('infected_source');
            }
            DB::table('media_files')->where('customer_id', $this->customerId)->where('id', $media->id)->update(['status' => 'ready', 'processing_error_code' => null, 'updated_at' => $now]);
        } elseif ($job->job_type === 'ocr') {
            foreach ($result['units'] ?? [] as $unit) {
                $outputId = DB::table('media_extracted_texts')->insertGetId([
                    'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'processing_job_id' => $job->id,
                    'locale' => $locale, 'locator_type' => $unit['locator_type'], 'locator_value' => $unit['locator_value'],
                    'sequence' => $unit['sequence'], 'text' => $unit['text'], 'char_count' => mb_strlen($unit['text']),
                    'extraction_method' => 'ocr', 'provider' => $job->provider, 'processing_version' => $job->processing_version,
                    'source_fingerprint' => $job->source_fingerprint, 'status' => 'ready', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $outputType = 'extracted_text';
        } elseif ($job->job_type === 'speech_to_text') {
            foreach ($result['units'] ?? [] as $unit) {
                $outputId = DB::table('media_transcripts')->insertGetId([
                    'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'locale' => $locale,
                    'provider' => $job->provider, 'status' => 'ready', 'text' => $unit['text'], 'processing_job_id' => $job->id,
                    'processing_version' => $job->processing_version, 'source_fingerprint' => $job->source_fingerprint,
                    'locator_type' => $unit['locator_type'], 'locator_value' => $unit['locator_value'], 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $outputType = 'transcript';
        } elseif ($job->job_type === 'caption') {
            $outputId = DB::table('media_captions')->insertGetId([
                'customer_id' => $this->customerId, 'media_file_id' => $media->id, 'locale' => $locale,
                'caption_type' => $this->profileValue($job->output_profile, 'format'), 'storage_key' => $result['storage_key'],
                'status' => 'ready', 'processing_job_id' => $job->id, 'processing_version' => $job->processing_version,
                'source_fingerprint' => $job->source_fingerprint, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $outputType = 'caption';
        }
        DB::table('media_processing_jobs')->where('customer_id', $this->customerId)->where('id', $job->id)->update([
            'status' => 'ready', 'output_type' => $outputType, 'output_id' => $outputId,
            'completed_at' => $now, 'updated_at' => $now,
        ]);
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
}
