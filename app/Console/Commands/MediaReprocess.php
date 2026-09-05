<?php

namespace App\Console\Commands;

use App\Services\MediaOutputProfile;
use App\Services\MediaProcessingOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MediaReprocess extends Command
{
    /**
     * Terminal error codes never reach a retry chain: LF-Media-Processing-Contract
     * limits retry to the transient group.
     */
    private const RETRYABLE_ERROR_CODES = ['provider_timeout', 'provider_unavailable', 'rate_limited', 'transcript_invalid'];

    private const MAX_ATTEMPTS = 3;

    protected $signature = 'media:reprocess
        {--customer= : Tenant saas_customers.id that owns the media}
        {--media= : Re-materialize the required output profiles of this media_files.id}
        {--job= : Retry the failed media_processing_jobs.id attempt chain}
        {--locale= : Canonical processing locale for --media; defaults to media_files.processing_locale}
        {--locales= : Unordered comma-separated 1-3 locale Document profile for --media}
        {--actor= : users.id recorded as created_by on the enqueued rows}
        {--dry-run : Report the plan without writing or dispatching}';

    protected $description = 'Operator entry point for Media Processing chains: retry a failed job, or re-materialize the required output profiles of a media file.';

    public function handle(MediaProcessingOrchestrator $orchestrator): int
    {
        foreach (['customer', 'media', 'job', 'actor'] as $name) {
            if ($this->option($name) !== null && $this->positiveOption($name) === null) {
                $this->error("Option --{$name} must be a positive integer id.");

                return self::FAILURE;
            }
        }

        $customerId = $this->positiveOption('customer');
        if ($customerId === null) {
            $this->error('Option --customer=<saas_customers.id> is required.');

            return self::FAILURE;
        }
        if (! DB::table('saas_customers')->where('id', $customerId)->exists()) {
            $this->error("Customer {$customerId} does not exist.");

            return self::FAILURE;
        }

        $mediaId = $this->positiveOption('media');
        $jobId = $this->positiveOption('job');
        if (($mediaId === null) === ($jobId === null)) {
            $this->error('Select exactly one target: --media=<id> or --job=<id>.');

            return self::FAILURE;
        }
        if ($jobId !== null && $this->option('locale') !== null) {
            $this->error('Option --locale applies to --media only: a retry keeps the output profile of the chain it continues.');

            return self::FAILURE;
        }
        if ($jobId !== null && $this->option('locales') !== null) {
            $this->error('Option --locales applies to --media only: a retry keeps its recorded language profile.');

            return self::FAILURE;
        }
        if ($this->option('locale') !== null && $this->option('locales') !== null) {
            $this->error('Select only one of --locale or --locales.');

            return self::FAILURE;
        }

        $actorId = null;
        if ($this->option('actor') !== null) {
            $actorId = $this->positiveOption('actor');
            if (! DB::table('users')->where('customer_id', $customerId)->where('id', $actorId)->exists()) {
                $this->error('Option --actor must be a users.id belonging to the selected customer.');

                return self::FAILURE;
            }
        }

        return $jobId !== null
            ? $this->retryJob($orchestrator, $customerId, $jobId, $actorId)
            : $this->rematerialize($orchestrator, $customerId, (int) $mediaId, $actorId);
    }

    private function retryJob(MediaProcessingOrchestrator $orchestrator, int $customerId, int $jobId, ?int $actorId): int
    {
        $job = DB::table('media_processing_jobs')->where('customer_id', $customerId)->where('id', $jobId)->first();
        if (! $job) {
            $this->error("Processing job {$jobId} does not exist for customer {$customerId}.");

            return self::FAILURE;
        }

        $blocker = $this->retryBlocker($orchestrator, $customerId, $job);
        if ($blocker !== null) {
            $this->error($blocker);

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Chain: media_file_id=%d job_type=%s output_profile=%s processing_version=%s provider=%s',
            $job->media_file_id, $job->job_type, $job->output_profile === '' ? '(none)' : $job->output_profile,
            $job->processing_version, $job->provider
        ));
        $this->line(sprintf('Retrying attempt %d after %s.', (int) $job->attempt, $job->error_code));

        if ($this->option('dry-run')) {
            $this->info(sprintf('Dry run: attempt %d would be enqueued.', (int) $job->attempt + 1));

            return self::SUCCESS;
        }

        try {
            $created = $orchestrator->retry($customerId, $jobId, $actorId);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Enqueued job %d as attempt %d.', (int) $created->id, (int) $created->attempt));

        return self::SUCCESS;
    }

    /**
     * Diagnoses why a chain cannot continue. MediaProcessingOrchestrator::retry
     * re-checks every rule under a row lock; this only names the reason first.
     */
    private function retryBlocker(MediaProcessingOrchestrator $orchestrator, int $customerId, object $job): ?string
    {
        if ($job->status !== 'failed') {
            return "Job {$job->id} is {$job->status}; only a failed job can be retried.";
        }
        if (! in_array($job->error_code, self::RETRYABLE_ERROR_CODES, true)) {
            return sprintf(
                'Job %d failed with %s, which is permanent under LF-Media-Processing-Contract. Retryable: %s.',
                $job->id, $job->error_code ?? 'no error code', implode(', ', self::RETRYABLE_ERROR_CODES)
            );
        }
        if ((int) $job->attempt >= self::MAX_ATTEMPTS) {
            return sprintf('Job %d is attempt %d of %d; the chain is exhausted.', $job->id, (int) $job->attempt, self::MAX_ATTEMPTS);
        }

        $highest = DB::table('media_processing_jobs')->where('customer_id', $customerId)
            ->where('media_file_id', $job->media_file_id)->where('job_type', $job->job_type)
            ->where('source_fingerprint', $job->source_fingerprint)->where('processing_version', $job->processing_version)
            ->where('output_profile_hash', $job->output_profile_hash)->max('attempt');
        if ((int) $highest !== (int) $job->attempt) {
            return sprintf('Job %d is attempt %d; only the highest attempt (%d) of the chain may retry.', $job->id, (int) $job->attempt, (int) $highest);
        }

        // Qua orchestrator chu khong doc thang config: version cua video STT gom
        // ca canonical ffmpeg extraction profile. Doc thang se khong bao gio khop
        // va tu choi retry bang mot thong bao sai — "version da doi" trong khi
        // khong co gi doi.
        $media = DB::table('media_files')->where('customer_id', $customerId)
            ->where('id', $job->media_file_id)->first();
        $parameters = $job->job_type === 'structured_extraction' ? app(MediaOutputProfile::class)->parse($job->output_profile) : [];
        $version = $orchestrator->versionFor((string) $job->job_type, $media, $parameters);
        $provider = $orchestrator->providerFor((string) $job->job_type, $media);
        if ($job->provider !== $provider || $job->processing_version !== $version) {
            return sprintf(
                "Job %d was recorded against provider=%s version=%s, but %s is now configured as provider=%s version=%s.\n"
                ."A retry continues the recorded chain, so it would call the old provider again. Run\n"
                .'  php artisan media:reprocess --customer=%d --media=%d'."\n"
                .'to open a fresh attempt-1 chain under the configured version instead.',
                $job->id, $job->provider, $job->processing_version, $job->job_type, $provider, $version,
                $customerId, (int) $job->media_file_id
            );
        }

        return null;
    }

    private function rematerialize(MediaProcessingOrchestrator $orchestrator, int $customerId, int $mediaId, ?int $actorId): int
    {
        $media = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaId)->first();
        if (! $media) {
            $this->error("Media file {$mediaId} does not exist for customer {$customerId}.");

            return self::FAILURE;
        }

        $locale = $this->option('locales') !== null
            ? explode(',', (string) $this->option('locales'))
            : ($this->option('locale') ?? $media->processing_locale);
        if ($locale === null && in_array($media->file_type, ['document', 'audio', 'video'], true)) {
            $this->error("Media file {$mediaId} has no processing_locale recorded; pass --locale=<BCP 47>.");

            return self::FAILURE;
        }

        try {
            $plan = $orchestrator->planForCourseActivity($customerId, $mediaId, $locale);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Media file %d: file_type=%s status=%s processing_locale=%s%s',
            $mediaId, $media->file_type, $media->status, $media->processing_locale ?? '(none)',
            $media->processing_error_code !== null ? ' processing_error_code='.$media->processing_error_code : ''
        ));
        $this->table(
            ['job_type', 'output_profile', 'provider', 'processing_version', 'chain'],
            array_map(fn (array $row): array => [
                $row['job_type'],
                $row['output_profile'] === '' ? '(none)' : $row['output_profile'],
                $row['provider'],
                $row['processing_version'],
                $row['existing_job_id'] === null ? 'new' : 'exists as job '.$row['existing_job_id'],
            ], $plan)
        );

        $new = array_filter($plan, fn (array $row): bool => $row['existing_job_id'] === null);
        if ($this->option('dry-run')) {
            $this->info(sprintf('Dry run: %d chain(s) would be created, %d already exist.', count($new), count($plan) - count($new)));

            return self::SUCCESS;
        }
        if ($new === []) {
            $this->info('Every required chain already exists under the configured version; nothing to enqueue.');

            return self::SUCCESS;
        }

        $orchestrator->materializeForCourseActivity($customerId, $mediaId, $locale, $actorId);

        $refreshed = DB::table('media_files')->where('customer_id', $customerId)->where('id', $mediaId)->first();
        if ($refreshed->processing_error_code !== null) {
            $this->error("Materialization rejected the request: {$refreshed->processing_error_code}.");

            return self::FAILURE;
        }

        $this->info(sprintf('Enqueued %d chain(s) for media file %d.', count($new), $mediaId));

        return self::SUCCESS;
    }

    private function positiveOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_scalar($value) && preg_match('/^[1-9][0-9]*$/', (string) $value) === 1 ? (int) $value : null;
    }
}
