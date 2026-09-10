<?php

namespace App\Services\Ai;

use App\Contracts\Ai\CommercialEntitlements;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gate step 3 — reads Commercial's answer to "Can Use?" from
 * `saas_entitlements`. AI reads and never writes: ADR-0006 forbids AI owning
 * Subscription or Billing state, and the table forbids consumer Domains from
 * updating it.
 *
 * The table is still `Review / Not Implemented`; an absent table reads as "not
 * entitled", which is the same answer the fail-closed default gives.
 */
final class DatabaseCommercialEntitlements implements CommercialEntitlements
{
    public function hasActiveEntitlement(int $customerId, string $featureKey): bool
    {
        if (! Schema::hasTable('saas_entitlements')) {
            return false;
        }

        return self::effectiveQuery($customerId, $featureKey)->exists();
    }

    /**
     * The single effective row for a feature, as the contract defines it:
     * `active`, started, and not yet ended.
     *
     * Shared with the reservation store so both agree on what "effective"
     * means. Two definitions of effective would let a hold be granted against a
     * row the gate never approved.
     */
    public static function effectiveQuery(int $customerId, string $featureKey): Builder
    {
        $now = now();

        return DB::table('saas_entitlements')
            ->where('customer_id', $customerId)
            ->where('feature_key', $featureKey)
            ->where('status', 'active')
            ->where('effective_from', '<=', $now)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now));
    }
}
