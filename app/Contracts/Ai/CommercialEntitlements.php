<?php

namespace App\Contracts\Ai;

/**
 * Step 3 of the provider execution gate.
 *
 * Commercial owns "Can Use?" (`saas_entitlements`). AI is a Consumer Domain and
 * may only read it — ADR-0006 forbids AI from owning Subscription or Billing
 * state, and `saas_entitlements` forbids consumer Domains from updating it.
 */
interface CommercialEntitlements
{
    public function hasActiveEntitlement(int $customerId, string $featureKey): bool;
}
