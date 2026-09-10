<?php

namespace App\Contracts\Ai;

use App\Support\Ai\QuotaReservationHandle;

/**
 * Step 4 of the provider execution gate.
 *
 * Reservation must be atomic: "read the remaining balance, then call the
 * provider" is explicitly not acceptable, because two concurrent requests both
 * read the same balance and both proceed. Implementations therefore reserve
 * and check inside one transaction with appropriate locking.
 *
 * The stable Model Run identity is supplied so the Commercial ledger can
 * make retry idempotency physical instead of creating two holds for one
 * logical attempt.
 *
 * `usageType` is the metric this hold will settle as. Commercial snapshots it
 * and copies it verbatim into the Usage Event, whose `usage_type` is NOT NULL;
 * without it the settlement step would have to invent the value. It identifies
 * the metric — it does **not** partition the budget, which stays per feature and
 * unit.
 *
 * `reserve()` returns null when the quota is exhausted — it must not throw for
 * that ordinary outcome, so the gate can record AI_QUOTA_EXCEEDED.
 *
 * The caller must mark the provider boundary explicitly. Only a never-started
 * `reserved` hold may expire automatically; `executing` and `settling` require
 * provider-aware reconciliation because usage may already have occurred.
 */
interface UsageQuotaReserver
{
    public function reserve(
        int $customerId,
        int $modelRunId,
        string $runUuid,
        string $featureKey,
        string $usageType,
        float $quantity,
        string $unit,
    ): ?QuotaReservationHandle;

    public function markExecuting(QuotaReservationHandle $handle): void;

    public function markSettling(QuotaReservationHandle $handle): void;

    public function commit(QuotaReservationHandle $handle, float $actualQuantity): void;

    /**
     * Hand back a hold that never crossed the provider boundary.
     *
     * Valid only before `markExecuting()`. An implementation MUST refuse a hold
     * already marked `executing` or `settling` rather than silently refunding
     * it: at that point usage may have reached the provider, and a refund would
     * undercount it with no way to notice. The gate does not call this after the
     * boundary, but the contract cannot depend on every caller remembering that.
     */
    public function release(QuotaReservationHandle $handle): void;

    /** Reclaim expired reservations that never crossed the provider boundary. */
    public function reconcileExpired(int $customerId): int;

    /**
     * Provider-aware reconciliation for holds stuck in `executing`/`settling`.
     *
     * Only this path may terminate a hold that crossed the provider boundary,
     * and it has **two** outcomes, never one:
     *
     *  - evidence the provider consumed nothing  → `reconciled_released`;
     *  - evidence the provider did consume       → settle it forward to
     *    `committed` / `committed_over_limit`, appending the Usage Event with
     *    the true quantity exactly as a producer settlement would.
     *
     * Both require positive evidence; neither may be inferred from elapsed
     * time. Holds it cannot prove stay held. A release-only reconciliation
     * would strand real consumption whose producer died before settling it,
     * and that measurement would never reach Usage — the Source Of Truth —
     * with nothing to signal the loss.
     *
     * @return array{released:int,settled:int} Holds terminated by each outcome.
     */
    public function reconcileUnsettled(int $customerId): array;
}
