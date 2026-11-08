<?php

namespace App\Console\Commands;

use App\Services\MediaProcessingOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Faststart copies for videos attached before LF-Media-Processing-Contract
 * § Video board playback copy. New attachments get theirs automatically; this
 * only creates a job for an eligible video with an active Course video usage
 * and never duplicates one that is pending, processing or ready.
 */
class MediaVideoFaststart extends Command
{
    protected $signature = 'media:video-faststart
        {--customer= : Tenant saas_customers.id that owns the videos}
        {--media= : Only this media_files.id}
        {--actor= : users.id recorded as created_by on the enqueued rows}';

    protected $description = 'Queue the faststart board copy for MP4/MOV videos whose index is at the end of the file.';

    public function handle(MediaProcessingOrchestrator $orchestrator): int
    {
        foreach (['customer', 'media', 'actor'] as $name) {
            $value = $this->option($name);
            if ($value !== null && (! ctype_digit((string) $value) || (int) $value < 1)) {
                $this->error("Option --{$name} must be a positive integer id.");

                return self::FAILURE;
            }
        }
        $customerId = $this->option('customer') !== null ? (int) $this->option('customer') : null;
        if ($customerId === null) {
            $this->error('Option --customer=<saas_customers.id> is required.');

            return self::FAILURE;
        }
        if (! DB::table('saas_customers')->where('id', $customerId)->exists()) {
            $this->error("Customer {$customerId} does not exist.");

            return self::FAILURE;
        }
        $mediaId = $this->option('media') !== null ? (int) $this->option('media') : null;
        $actorId = $this->option('actor') !== null ? (int) $this->option('actor') : null;

        $ids = DB::table('media_file_usages as usages')
            ->join('media_files as media', function ($join) use ($customerId): void {
                $join->on('media.id', '=', 'usages.media_file_id')->where('media.customer_id', $customerId);
            })
            ->where('usages.customer_id', $customerId)->where('usages.owner_type', 'course_activity')
            ->where('usages.usage_type', 'video')->where('usages.status', 'active')
            ->where('media.file_type', 'video')->where('media.status', '<>', 'deleted')
            ->when($mediaId !== null, fn ($query) => $query->where('media.id', $mediaId))
            ->distinct()->orderBy('media.id')->pluck('media.id');

        $counts = ['created' => 0, 'retried' => 0, 'skipped' => 0];
        foreach ($ids as $id) {
            $outcome = $orchestrator->requestVideoBoardPreview($customerId, (int) $id, $actorId);
            $counts[$outcome]++;
            $this->line("Media {$id}: {$outcome}");
        }
        $this->info("Created {$counts['created']}, retried {$counts['retried']}, skipped {$counts['skipped']}.");

        return self::SUCCESS;
    }
}
