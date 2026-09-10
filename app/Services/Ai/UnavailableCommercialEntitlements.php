<?php

namespace App\Services\Ai;

use App\Contracts\Ai\CommercialEntitlements;

/**
 * Fail-closed default for gate step 3.
 *
 * `saas_entitlements` is documented (database/saas-commercial/saas_entitlements.md)
 * but `not_implemented`. Commercial is the Source Of Truth for "Can Use?", and
 * AI must not answer that question on Commercial's behalf, so every purpose is
 * treated as un-entitled until Commercial can answer.
 */
final class UnavailableCommercialEntitlements implements CommercialEntitlements
{
    public function hasActiveEntitlement(int $customerId, string $featureKey): bool
    {
        return false;
    }
}
