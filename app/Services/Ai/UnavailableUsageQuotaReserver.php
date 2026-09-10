<?php

namespace App\Services\Ai;

use App\Contracts\Ai\UsageQuotaReserver;
use App\Support\Ai\QuotaReservationHandle;

/**
 * Fail-closed default for gate step 4.
 *
 * Two independent reasons, both recorded for the Owner decision:
 *
 * 1. `saas_usage_counters` is `not_implemented` — no table exists.
 * 2. More fundamentally, no domain currently owns usage *reservation*. ADR-0009
 *    splits the concern into "allowed quota/limit belongs to Commercial" and
 *    "Usage owns quantity consumed"; neither owns a reservation ledger, and
 *    `saas_usage_counters` explicitly forbids a source Domain from writing to
 *    the counter and states the counter is a derived projection, not a Source
 *    Of Truth. AI is a Consumer Domain and cannot create that authority.
 *
 * Reserving nothing is therefore the only defensible behaviour, and the gate
 * records AI_QUOTA_EXCEEDED instead of proceeding on an unmeasured budget.
 */
final class UnavailableUsageQuotaReserver implements UsageQuotaReserver
{
    public function reserve(
        int $customerId,
        int $modelRunId,
        string $runUuid,
        string $featureKey,
        float $quantity,
        string $unit,
    ): ?QuotaReservationHandle {
        return null;
    }

    public function markExecuting(QuotaReservationHandle $handle): void {}

    public function markSettling(QuotaReservationHandle $handle): void {}

    public function commit(QuotaReservationHandle $handle, float $actualQuantity): void {}

    public function release(QuotaReservationHandle $handle): void {}

    public function reconcileExpired(int $customerId): int
    {
        return 0;
    }

    public function reconcileUnsettled(int $customerId): array
    {
        return ['released' => 0, 'settled' => 0];
    }
}
