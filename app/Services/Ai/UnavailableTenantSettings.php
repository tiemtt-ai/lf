<?php

namespace App\Services\Ai;

use App\Contracts\Ai\TenantSettingSource;

/**
 * Fail-closed default for gate step 2.
 *
 * `saas_customer_settings` is documented (database/saas/saas_customer_settings.md)
 * but `not_implemented`: it has no migration and no table. There is therefore
 * no authority that can approve external processing for a tenant, and the only
 * correct answer is "no approval on record" — which the gate turns into
 * AI_APPROVAL_REQUIRED rather than into a silent pass.
 *
 * Replace this binding with a real reader when the SaaS Tenant packet lands.
 */
final class UnavailableTenantSettings implements TenantSettingSource
{
    public function get(int $customerId, string $settingKey): ?array
    {
        return null;
    }
}
