<?php

namespace App\Listeners;

use App\Events\MediaRevisionReady;
use App\Services\AiKnowledgeSyncService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;

/**
 * Knowledge Sync Contract, ingest accelerator: a new `ready` revision is
 * registered for every eligible owner of that Media File, and the older
 * revision of the same logical key becomes stale in the same transaction.
 * Eligibility and content come from Media Read's system principal; a missed
 * event is recovered by `ai:knowledge-sync`.
 */
final class SyncKnowledgeOfReadyMedia implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [10, 60];

    public function __construct(private readonly AiKnowledgeSyncService $sync) {}

    public function handle(MediaRevisionReady $event): void
    {
        $customer = DB::table('saas_customers')->where('id', $event->customerId)
            ->where('status', 'active')->first();
        if ($customer === null) {
            return;
        }

        $previous = TenantContext::customer();
        TenantContext::set($customer);
        try {
            $this->sync->syncForMedia($event->mediaFileId);
        } finally {
            TenantContext::set($previous);
        }
    }
}
