<?php

namespace App\Console\Commands;

use App\Services\AiKnowledgeSyncService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Source of truth for Media → Knowledge sync (Knowledge Sync Contract):
 * erase sources of deleted Media, purge vectors, finalize past the barrier,
 * archive sources whose owner no longer holds the Media, then register new
 * revisions of published Course Version Activities. Never calls a model.
 */
class AiKnowledgeSync extends Command
{
    protected $signature = 'ai:knowledge-sync
        {--customer= : Limit the pass to one tenant}
        {--dry-run : Report what one pass would do; writes nothing and reads no Media content}';

    protected $description = 'Reconcile Knowledge Source/Chunk with Media: create, stale/archive and delete; no provider calls.';

    public function handle(AiKnowledgeSyncService $sync): int
    {
        $customer = $this->option('customer');
        if ($customer !== null && (! ctype_digit((string) $customer) || (int) $customer < 1)) {
            $this->error('Invalid customer.');

            return self::INVALID;
        }

        $tenants = DB::table('saas_customers')
            ->when($customer !== null, fn ($query) => $query->where('id', (int) $customer))
            ->orderBy('id')->get();

        $totals = [];
        $errors = 0;
        $previous = TenantContext::customer();
        try {
            foreach ($tenants as $tenant) {
                TenantContext::set($tenant);
                try {
                    // Erasure runs for every tenant; new content only for active ones.
                    $result = $this->option('dry-run')
                        ? $sync->planTenant($tenant->status === 'active')
                        : $sync->reconcileTenant($tenant->status === 'active');
                    foreach ($result as $key => $value) {
                        $totals[$key] = ($totals[$key] ?? 0) + $value;
                    }
                } catch (Throwable $exception) {
                    // One tenant failing must not leave the others unsynced or un-erased.
                    $errors++;
                    $this->error('Tenant '.$tenant->id.' failed: '.$exception::class);
                }
            }
        } finally {
            TenantContext::set($previous);
        }

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '').collect($totals)->map(fn ($value, $key) => $key.'='.$value)->implode('; ').'; tenant_errors='.$errors);
        if (($totals['stuck_deletions'] ?? 0) > 0) {
            $this->warn('Knowledge sources waiting on deletion longer than the configured window: '.$totals['stuck_deletions']);
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
