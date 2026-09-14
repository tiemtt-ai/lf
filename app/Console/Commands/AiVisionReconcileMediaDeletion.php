<?php

namespace App\Console\Commands;

use App\Services\AiVisionInterpretationService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Backstop for the MediaFileDeleted listener: erases Vision Interpretations of
 * Media Files already deleted and finishes rows left in `deletion_pending`.
 * Local database only; never calls a provider and never touches Media storage.
 */
class AiVisionReconcileMediaDeletion extends Command
{
    protected $signature = 'ai:vision-reconcile-media-deletion {--customer= : Limit reconciliation to one tenant}';

    protected $description = 'Erase Vision Interpretations whose source Media File is deleted; local database only.';

    public function handle(AiVisionInterpretationService $interpretations): int
    {
        $customer = $this->option('customer');
        if ($customer !== null && (! ctype_digit((string) $customer) || (int) $customer < 1)) {
            $this->error('Invalid customer.');

            return self::INVALID;
        }

        $customerIds = DB::table('ai_vision_interpretations')
            ->whereIn('status', ['ready', 'stale', 'deletion_pending'])
            ->when($customer !== null, fn ($query) => $query->where('customer_id', (int) $customer))
            ->distinct()
            ->orderBy('customer_id')
            ->pluck('customer_id');

        $mediaFiles = 0;
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
                    $result = $interpretations->reconcileDeletedMedia();
                    $mediaFiles += $result['media_files'];
                    $deleted += $result['deleted'];
                } catch (Throwable $exception) {
                    // One tenant failing must not leave the others un-erased.
                    $errors++;
                    $this->error('Tenant '.$customerId.' failed: '.$exception::class);
                }
            }
        } finally {
            TenantContext::set($previous);
        }

        $this->info('Media files reconciled: '.$mediaFiles.'; interpretations deleted: '.$deleted.'; tenant errors: '.$errors.'.');

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
