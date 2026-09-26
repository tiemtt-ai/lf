<?php

namespace App\Listeners;

use App\Events\MediaFileDeleted;
use App\Services\AiKnowledgeSyncService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;

/**
 * Knowledge Sync Contract, deletion accelerator (closes F1/O-6): deleting a
 * source Media File moves every Knowledge Source built from it — synced or
 * prepared by hand, any status short of deleted — onto the two-phase deletion
 * path, purges vectors and erases chunk content once the barrier allows.
 *
 * ShouldQueueAfterCommit, not ShouldHandleEventsAfterCommit: only the former
 * marks a queued listener's job after-commit. A rolled-back deletion enqueues
 * nothing; a missed or exhausted job is picked up by `ai:knowledge-sync`.
 */
final class EraseKnowledgeOfDeletedMedia implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [10, 60];

    public function __construct(private readonly AiKnowledgeSyncService $sync) {}

    public function handle(MediaFileDeleted $event): void
    {
        $customer = DB::table('saas_customers')->where('id', $event->customerId)->first();
        if ($customer === null) {
            return;
        }

        $previous = TenantContext::customer();
        TenantContext::set($customer);
        try {
            // The service re-checks the tombstone through MediaService.
            $this->sync->eraseForDeletedMedia($event->mediaFileId);
        } finally {
            TenantContext::set($previous);
        }
    }
}
