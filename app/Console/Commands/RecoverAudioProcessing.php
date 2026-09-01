<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMediaProcessingJob;
use App\Services\AudioProcessingEligibility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecoverAudioProcessing extends Command
{
    protected $signature = 'media:recover-audio-processing {--customer= : Limit recovery to one tenant}';

    protected $description = 'Recover expired Audio STT jobs and redeliver durable pending work.';

    public function handle(): int
    {
        $customer = $this->option('customer');
        if ($customer !== null && (! ctype_digit((string) $customer) || (int) $customer < 1)) {
            $this->error('Invalid customer.');

            return self::INVALID;
        }
        $cutoff = now()->subSeconds((new ProcessMediaProcessingJob(0, 0))->timeout);
        $pendingCutoff = now()->subSeconds(3900);
        $recovered = 0;
        $errors = 0;
        DB::table('media_processing_jobs')->where('job_type', 'speech_to_text')
            ->whereIn('status', ['pending', 'processing'])
            ->when($customer !== null, fn ($q) => $q->where('customer_id', (int) $customer))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('media_files')
                ->whereColumn('media_files.id', 'media_processing_jobs.media_file_id')
                ->whereColumn('media_files.customer_id', 'media_processing_jobs.customer_id')
                ->where('file_type', 'audio'))
            ->where(fn ($q) => $q->where(fn ($p) => $p->where('status', 'pending')->where('updated_at', '<=', $pendingCutoff))
                ->orWhere(fn ($p) => $p->where('status', 'processing')->whereRaw('COALESCE(started_at, updated_at) <= ?', [$cutoff])))
            ->orderBy('id')->chunkById(100, function ($jobs) use ($cutoff, $pendingCutoff, &$recovered, &$errors): void {
                foreach ($jobs as $candidate) {
                    try {
                        DB::transaction(function () use ($candidate, $cutoff, $pendingCutoff, &$recovered): void {
                            $media = DB::table('media_files')->where('customer_id', $candidate->customer_id)
                                ->where('id', $candidate->media_file_id)->lockForUpdate()->first();
                            $job = DB::table('media_processing_jobs')->where('customer_id', $candidate->customer_id)
                                ->where('id', $candidate->id)->lockForUpdate()->first();
                            if (! $media || ! $job || ! app(AudioProcessingEligibility::class)
                                ->hasActiveUsage((int) $candidate->customer_id, (int) $candidate->media_file_id)) {
                                if ($job?->status === 'pending') {
                                    DB::table('media_processing_jobs')->where('id', $job->id)->where('customer_id', $job->customer_id)
                                        ->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
                                    $recovered++;
                                }

                                return;
                            }
                            if ($job->status === 'processing' && ($job->started_at ?? $job->updated_at) <= $cutoff->toDateTimeString()) {
                                DB::table('media_processing_jobs')->where('id', $job->id)->where('customer_id', $job->customer_id)->update([
                                    'status' => 'failed', 'error_code' => 'provider_timeout', 'error_message' => 'provider_timeout',
                                    'completed_at' => now(), 'updated_at' => now(),
                                ]);
                                $recovered++;
                            } elseif ($job->status === 'pending' && $job->updated_at <= $pendingCutoff->toDateTimeString()) {
                                DB::table('media_processing_jobs')->where('id', $job->id)->where('customer_id', $job->customer_id)
                                    ->update(['updated_at' => now()]);
                                DB::afterCommit(fn () => ProcessMediaProcessingJob::dispatch((int) $job->customer_id, (int) $job->id));
                                $recovered++;
                            }
                        });
                    } catch (Throwable $exception) {
                        $errors++;
                        Log::error('Audio recovery failed.', ['customer_id' => (int) $candidate->customer_id,
                            'processing_job_id' => (int) $candidate->id, 'exception' => $exception::class]);
                    }
                }
            });
        $this->info("Recovered {$recovered} Audio job(s); {$errors} error(s).");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
