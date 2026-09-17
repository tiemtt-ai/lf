<?php

namespace App\Console\Commands;

use App\Services\AiAuthoringErasureService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Backstop for Step 7 erasure: proposals anchored to deleted Media Files or to
 * working Activities that no longer exist, plus unfinished erasures. Local
 * database only; never calls a provider, never touches Media storage, Course
 * Intents, Learning Nodes or canonical Mappings.
 */
class AiAuthoringReconcileErasure extends Command
{
    protected $signature = 'ai:authoring-reconcile-erasure {--customer= : Limit reconciliation to one tenant}';

    protected $description = 'Erase Authoring Proposal content whose source Media or working Activity is gone; local database only.';

    public function handle(AiAuthoringErasureService $erasure): int
    {
        $customer = $this->option('customer');
        if ($customer !== null && (! ctype_digit((string) $customer) || (int) $customer < 1)) {
            $this->error('Invalid customer.');

            return self::INVALID;
        }

        if (! Schema::hasTable('ai_authoring_proposals')) {
            $this->info('Authoring Proposal packet is not installed on this connection.');

            return self::SUCCESS;
        }

        $customerIds = DB::table('ai_authoring_proposals')->whereNotIn('status', ['deleted'])
            ->when($customer !== null, fn ($query) => $query->where('customer_id', (int) $customer))
            ->distinct()->orderBy('customer_id')->pluck('customer_id');

        $requested = 0;
        $deleted = 0;
        $errors = 0;
        $previous = TenantContext::customer();
        try {
            foreach ($customerIds as $customerId) {
                $tenant = DB::table('saas_customers')->where('id', $customerId)->first();
                if ($tenant === null) {
                    continue;
                }
                TenantContext::set($tenant);
                try {
                    $result = $erasure->reconcile();
                    $requested += $result['requested'];
                    $deleted += $result['deleted'];
                } catch (Throwable $exception) {
                    $errors++;
                    $this->error('Tenant '.$customerId.' failed: '.$exception::class);
                }
            }
        } finally {
            TenantContext::set($previous);
        }

        $this->info('Proposals queued for erasure: '.$requested.'; erased: '.$deleted.'; tenant errors: '.$errors.'.');

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
