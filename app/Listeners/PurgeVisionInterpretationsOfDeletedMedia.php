<?php

namespace App\Listeners;

use App\Events\MediaFileDeleted;
use App\Services\AiVisionInterpretationService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0020 D5 on the AI side: deleting a source Media File erases every Vision
 * Interpretation built from it.
 *
 * Queued after the OUTERMOST transaction commits. No current caller nests
 * Media deletion in its own transaction (Course Activity defers it with
 * DB::afterCommit), but one that did would otherwise let a worker run before
 * the tombstone is visible and finish without erasing anything; a rolled-back
 * deletion enqueues nothing.
 *
 * It must be ShouldQueueAfterCommit. ShouldHandleEventsAfterCommit is read only
 * for listeners that run in-process; for a queued listener Laravel copies the
 * after-commit flag onto the job solely from ShouldQueueAfterCommit or an
 * `$afterCommit` property.
 *
 * A slow or failing AI side never holds up Media. A missed or exhausted job is
 * picked up by `ai:vision-reconcile-media-deletion`.
 */
final class PurgeVisionInterpretationsOfDeletedMedia implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [10, 60];

    public function __construct(private readonly AiVisionInterpretationService $interpretations) {}

    public function handle(MediaFileDeleted $event): void
    {
        $customer = DB::table('saas_customers')->where('id', $event->customerId)->first();
        if ($customer === null) {
            return;
        }

        // A queue worker is long-lived: restore whatever tenant was set before.
        $previous = TenantContext::customer();
        TenantContext::set($customer);
        try {
            $this->interpretations->purgeForDeletedMediaFile($event->mediaFileId);
        } finally {
            TenantContext::set($previous);
        }
    }
}
