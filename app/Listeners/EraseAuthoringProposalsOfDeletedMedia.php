<?php

namespace App\Listeners;

use App\Events\MediaFileDeleted;
use App\Services\AiAuthoringErasureService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 7: deleting a source Media File erases the content of every Authoring
 * Proposal anchored to it. Queued after the outermost commit (see the Vision
 * listener for why ShouldQueueAfterCommit, not ShouldHandleEventsAfterCommit).
 * The erasure service re-checks the tombstone; a missed or exhausted job is
 * picked up by `ai:authoring-reconcile-erasure`.
 */
final class EraseAuthoringProposalsOfDeletedMedia implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [10, 60];

    public function __construct(private readonly AiAuthoringErasureService $erasure) {}

    public function handle(MediaFileDeleted $event): void
    {
        // The Step 7 packet exists only on MariaDB/MySQL; elsewhere there is nothing to erase.
        if (! Schema::hasTable('ai_authoring_proposal_sources')) {
            return;
        }
        $customer = DB::table('saas_customers')->where('id', $event->customerId)->first();
        if ($customer === null) {
            return;
        }

        $previous = TenantContext::customer();
        TenantContext::set($customer);
        try {
            if ($this->erasure->requestForDeletedMediaFile($event->mediaFileId) > 0) {
                do {
                    $batch = $this->erasure->finalize(100);
                } while ($batch > 0);
            }
        } finally {
            TenantContext::set($previous);
        }
    }
}
