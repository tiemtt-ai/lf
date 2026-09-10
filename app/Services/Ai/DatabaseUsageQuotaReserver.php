<?php

namespace App\Services\Ai;

use App\Contracts\Ai\UsageQuotaReserver;
use App\Support\Ai\QuotaReservationHandle;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gate step 4 against Commercial's `saas_usage_reservations` ledger.
 *
 * Implements the contract in database/saas-commercial/saas_usage_reservations.md.
 * The parts that are easy to get subtly wrong, and why they are written the way
 * they are:
 *
 *  - Capacity enumerates every status by name. Grouping them ("reserved +
 *    committed", "non-terminal") is what broke this design twice already: a
 *    status added later silently drops out of enforcement.
 *  - The idempotency lookup ignores `period_key`, and runs inside the same
 *    transaction as the entitlement lock. Looking up outside the lock is a
 *    read-then-write two callers can both win.
 *  - `release()` refuses a hold past the provider boundary rather than
 *    refunding it.
 *
 * The tables are still `Review / Not Implemented`; every entry point degrades to
 * the fail-closed answer while they are absent, so the gate keeps producing
 * clean `blocked` runs instead of QueryExceptions.
 */
final class DatabaseUsageQuotaReserver implements UsageQuotaReserver
{
    /** Statuses that still hold capacity. */
    private const HELD = ['reserved', 'executing', 'settling'];

    /** Statuses whose quantity has been consumed. */
    private const CONSUMED = ['committed', 'committed_over_limit'];

    /** An attempt that already settled: return it, never re-reserve. */
    private const SETTLED = ['committed', 'committed_over_limit'];

    /** An attempt that ended without consuming: a new run needs a new attempt. */
    private const CLOSED_UNUSED = ['released', 'expired', 'reconciled_released'];

    private const LEASE_MINUTES = 15;

    private const MAX_LEASE_HOURS = 2;

    public function reserve(
        int $customerId,
        int $modelRunId,
        string $runUuid,
        string $featureKey,
        string $usageType,
        float $quantity,
        string $unit,
    ): ?QuotaReservationHandle {
        if (! $this->ready()) {
            return null;
        }

        return DB::transaction(function () use ($customerId, $modelRunId, $runUuid, $featureKey, $usageType, $quantity, $unit): ?QuotaReservationHandle {
            // Serialization point. Exactly one effective entitlement, locked,
            // before anything is read or counted.
            $entitlement = DatabaseCommercialEntitlements::effectiveQuery($customerId, $featureKey)
                ->lockForUpdate()
                ->get(['id', 'entitlement_type', 'entitlement_value', 'quota_unit', 'quota_period_type', 'quota_timezone']);

            if ($entitlement->count() !== 1) {
                return null;
            }
            $entitlement = $entitlement->first();

            if ($entitlement->quota_unit !== $unit) {
                return null;
            }

            $period = $this->period($entitlement->quota_period_type, $entitlement->quota_timezone);

            $existing = DB::table('saas_usage_reservations')
                ->where('customer_id', $customerId)
                ->where('source_type', 'ai_model_run')
                ->where('source_uuid', $runUuid)
                ->where('feature_key', $featureKey)
                ->where('usage_type', $usageType)
                ->where('unit', $unit)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (in_array($existing->status, self::SETTLED, true)) {
                    return null;   // already billed; the caller gets its settlement, not a hold
                }
                if (in_array($existing->status, self::CLOSED_UNUSED, true)) {
                    return null;   // a new execution needs a new attempt identity
                }

                return $this->handle($existing, $featureKey, $unit);
            }

            if ($this->available($customerId, $featureKey, $period['key'], $unit, $entitlement) < $quantity) {
                return null;
            }

            $now = now();
            $id = DB::table('saas_usage_reservations')->insertGetId([
                'customer_id' => $customerId,
                'reservation_uuid' => (string) Str::uuid(),
                'entitlement_id' => $entitlement->id,
                'source_type' => 'ai_model_run',
                'source_id' => $modelRunId,
                'source_uuid' => $runUuid,
                'feature_key' => $featureKey,
                'usage_type' => $usageType,
                'period_type' => $entitlement->quota_period_type,
                'period_key' => $period['key'],
                'period_start_at' => $period['start'],
                'period_end_at' => $period['end'],
                'timezone_snapshot' => $entitlement->quota_timezone,
                'unit' => $unit,
                'reserved_quantity' => $quantity,
                'status' => 'reserved',
                'lease_expires_at' => $now->copy()->addMinutes(self::LEASE_MINUTES),
                'max_lease_expires_at' => $this->maxLease($now, $period['end']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->handle(
                DB::table('saas_usage_reservations')->where('id', $id)->first(),
                $featureKey,
                $unit,
            );
        }, 3);
    }

    public function markExecuting(QuotaReservationHandle $handle): void
    {
        $this->advance($handle, 'reserved', [
            'status' => 'executing',
            'execution_started_at' => now(),
        ]);
    }

    public function markSettling(QuotaReservationHandle $handle): void
    {
        $this->advance($handle, 'executing', [
            'status' => 'settling',
            'provider_completed_at' => now(),
        ]);
    }

    public function commit(QuotaReservationHandle $handle, float $actualQuantity): void
    {
        if (! $this->ready()) {
            return;
        }

        DB::transaction(function () use ($handle, $actualQuantity): void {
            $row = $this->lock($handle);
            if ($row === null || $row->status !== 'settling') {
                throw new RuntimeException('LF_RESERVATION_NOT_SETTLING');
            }

            $now = now();
            // The Usage Event and the reservation move together. A settlement
            // that recorded one without the other would leave Usage — the
            // Source Of Truth — disagreeing with the ledger that authorised it.
            $eventId = DB::table('saas_usage_events')->insertGetId([
                'customer_id' => $row->customer_id,
                'event_uuid' => $this->eventUuid($row->reservation_uuid),
                'event_kind' => 'measurement',
                'reservation_uuid' => $row->reservation_uuid,
                'feature_key' => $row->feature_key,
                'usage_type' => $row->usage_type,
                'quantity' => $actualQuantity,
                'unit' => $row->unit,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);

            DB::table('saas_usage_reservations')->where('id', $row->id)->update([
                'status' => $actualQuantity > (float) $row->reserved_quantity
                    ? 'committed_over_limit'
                    : 'committed',
                'committed_quantity' => $actualQuantity,
                'usage_event_id' => $eventId,
                'settled_at' => $now,
                'updated_at' => $now,
            ]);
        }, 3);
    }

    public function release(QuotaReservationHandle $handle): void
    {
        if (! $this->ready()) {
            return;
        }

        DB::transaction(function () use ($handle): void {
            $row = $this->lock($handle);
            if ($row === null) {
                return;
            }
            // Contract: valid only before the provider boundary. Refusing is the
            // point — a refund here would erase usage that may have happened.
            if (in_array($row->status, ['executing', 'settling'], true)) {
                throw new RuntimeException('LF_RESERVATION_PAST_PROVIDER_BOUNDARY');
            }
            if ($row->status !== 'reserved') {
                return;
            }

            DB::table('saas_usage_reservations')->where('id', $row->id)
                ->update(['status' => 'released', 'settled_at' => now(), 'updated_at' => now()]);
        }, 3);
    }

    public function reconcileExpired(int $customerId): int
    {
        if (! $this->ready()) {
            return 0;
        }

        // Only holds that never crossed the boundary. Elapsed time says nothing
        // about `executing` or `settling`.
        return DB::table('saas_usage_reservations')
            ->where('customer_id', $customerId)
            ->where('status', 'reserved')
            ->where('lease_expires_at', '<=', now())
            ->update(['status' => 'expired', 'settled_at' => now(), 'updated_at' => now()]);
    }

    public function reconcileUnsettled(int $customerId): array
    {
        // Terminating a hold past the provider boundary needs positive evidence
        // from the provider, which this class cannot obtain on its own. It is
        // deliberately inert rather than guessing: a wrong guess either refunds
        // real usage or bills for usage that never happened.
        return ['released' => 0, 'settled' => 0];
    }

    // ---- internals ------------------------------------------------------

    private function ready(): bool
    {
        return Schema::hasTable('saas_usage_reservations')
            && Schema::hasTable('saas_entitlements')
            && Schema::hasTable('saas_usage_events');
    }

    /** Capacity, enumerating every status by name — never by group. */
    private function available(int $customerId, string $featureKey, string $periodKey, string $unit, object $entitlement): float
    {
        if ($entitlement->entitlement_type === 'unlimited') {
            return INF;
        }

        $scope = fn () => DB::table('saas_usage_reservations')
            ->where('customer_id', $customerId)
            ->where('feature_key', $featureKey)
            ->where('period_key', $periodKey)
            ->where('unit', $unit);

        $held = (float) $scope()->whereIn('status', self::HELD)->sum('reserved_quantity');
        $consumed = (float) $scope()->whereIn('status', self::CONSUMED)->sum('committed_quantity');

        return (float) $entitlement->entitlement_value - $held - $consumed;
    }

    private function advance(QuotaReservationHandle $handle, string $from, array $changes): void
    {
        if (! $this->ready()) {
            return;
        }

        $updated = DB::table('saas_usage_reservations')
            ->where('customer_id', $handle->customerId)
            ->where('reservation_uuid', $handle->reservationId)
            ->where('status', $from)
            ->update($changes + ['updated_at' => now()]);

        if ($updated !== 1) {
            throw new RuntimeException('LF_RESERVATION_UNEXPECTED_STATUS');
        }
    }

    private function lock(QuotaReservationHandle $handle): ?object
    {
        return DB::table('saas_usage_reservations')
            ->where('customer_id', $handle->customerId)
            ->where('reservation_uuid', $handle->reservationId)
            ->lockForUpdate()
            ->first();
    }

    private function handle(object $row, string $featureKey, string $unit): QuotaReservationHandle
    {
        return new QuotaReservationHandle(
            $row->reservation_uuid,
            (int) $row->customer_id,
            (int) $row->source_id,
            $row->source_uuid,
            $featureKey,
            (float) $row->reserved_quantity,
            $unit,
            new DateTimeImmutable((string) $row->lease_expires_at),
        );
    }

    /** Deterministic, so a retried commit cannot append a second measurement. */
    private function eventUuid(string $reservationUuid): string
    {
        $hex = substr(hash('sha256', 'usage-event|'.$reservationUuid), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3)
            .'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    /** @return array{key:string,start:CarbonInterface,end:?CarbonInterface} */
    private function period(string $periodType, string $timezone): array
    {
        $now = now()->setTimezone($timezone);

        return match ($periodType) {
            'daily' => ['key' => $now->format('Y-m-d'), 'start' => $now->copy()->startOfDay()->utc(), 'end' => $now->copy()->startOfDay()->addDay()->utc()],
            'monthly' => ['key' => $now->format('Y-m'), 'start' => $now->copy()->startOfMonth()->utc(), 'end' => $now->copy()->startOfMonth()->addMonth()->utc()],
            'yearly' => ['key' => $now->format('Y'), 'start' => $now->copy()->startOfYear()->utc(), 'end' => $now->copy()->startOfYear()->addYear()->utc()],
            default => ['key' => 'lifetime', 'start' => now()->utc(), 'end' => null],
        };
    }

    private function maxLease(CarbonInterface $now, ?CarbonInterface $periodEnd): CarbonInterface
    {
        $cap = $now->copy()->addHours(self::MAX_LEASE_HOURS);

        return $periodEnd !== null && $periodEnd->lessThan($cap) ? $periodEnd : $cap;
    }
}
