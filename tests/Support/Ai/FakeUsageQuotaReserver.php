<?php

namespace Tests\Support\Ai;

use App\Contracts\Ai\UsageQuotaReserver;
use App\Support\Ai\QuotaReservationHandle;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;

/**
 * In-memory reserver used to exercise the gate's quota lifecycle.
 *
 * It follows the behavioural contract of DatabaseUsageQuotaReserver for what
 * UsageQuotaReserverContractTest asserts: attempt idempotency, the
 * `reserved → executing → settling` order, settlement (including zero and
 * over-limit measurements), settlement replay, capacity accounting and the
 * refusal codes. That test runs every scenario against both implementations,
 * so a divergence fails there rather than being discovered by hand.
 *
 * It deliberately does NOT model what only the store needs a database for:
 * period boundaries, lease renewal caps, entitlement resolution and the
 * provider-evidence reader. Capacity is a single balance; tests that depend on
 * those properties belong with DatabaseUsageQuotaReserverTest.
 *
 * `onReserve` lets a test re-enter the gate from inside a reservation, which is
 * how a concurrent second request is simulated without threads.
 */
final class FakeUsageQuotaReserver implements UsageQuotaReserver
{
    /** Same lease as the store, on the same (travellable) clock. */
    private const LEASE_MINUTES = 15;

    private const SETTLED = ['committed', 'committed_over_limit'];

    private const CLOSED_UNUSED = ['released', 'expired', 'reconciled_released'];

    /** @var array<string,array<string,mixed>> */
    public array $reservations = [];

    public int $reserveCalls = 0;

    public int $commitCalls = 0;

    public int $releaseCalls = 0;

    private ?Closure $onReserve = null;

    private ?RuntimeException $commitFailure = null;

    private int $nextUsageEventId = 1;

    public function __construct(private float $balance = 10.0) {}

    public function balance(): float
    {
        return $this->balance;
    }

    public function onReserve(Closure $callback): void
    {
        $this->onReserve = $callback;
    }

    public function failCommitWith(RuntimeException $exception): void
    {
        $this->commitFailure = $exception;
    }

    public function reserve(
        int $customerId,
        int $modelRunId,
        string $runUuid,
        string $featureKey,
        string $usageType,
        float $quantity,
        string $unit,
    ): ?QuotaReservationHandle {
        $this->reserveCalls++;
        $this->assertQuantity($quantity, false);

        if ($this->onReserve !== null) {
            $callback = $this->onReserve;
            $this->onReserve = null;
            $callback();
        }

        // Idempotent on the attempt identity and checked BEFORE capacity, as the
        // store is. The gate authorizes twice per execution; minting a second
        // hold here double-charged the balance and left one hold `reserved`.
        $existingId = $this->attemptReservation($customerId, $runUuid, $featureKey, $usageType, $unit);
        if ($existingId !== null) {
            $existing = $this->reservations[$existingId];
            if ($existing['model_run_id'] !== $modelRunId
                || $this->decimal($existing['quantity']) !== $this->decimal($quantity)) {
                throw new RuntimeException('LF_USAGE_RESERVATION_CONFLICT');
            }
            if (in_array($existing['status'], self::CLOSED_UNUSED, true)) {
                return null;   // a new execution needs a new attempt identity
            }

            // Held or settled: the same reservation, reporting its real state.
            return $this->handleFor($existingId);
        }

        if ($this->balance < $quantity) {
            return null;
        }

        $this->balance -= $quantity;
        $id = 'res-'.bin2hex(random_bytes(6));
        $this->reservations[$id] = [
            'customer_id' => $customerId,
            'model_run_id' => $modelRunId,
            'run_uuid' => $runUuid,
            'feature_key' => $featureKey,
            'unit' => $unit,
            'quantity' => $quantity,
            'expires_at' => CarbonImmutable::now()->addMinutes(self::LEASE_MINUTES)->toDateTimeImmutable(),
            'status' => 'reserved',
            'usage_type' => $usageType,
            'committed_quantity' => null,
            'usage_event_id' => null,
            'provider_completed' => false,
        ];

        return $this->handleFor($id);
    }

    /** Holds keyed by attempt identity, as the store's lookup is. */
    public function attemptReservation(int $customerId, string $runUuid, string $featureKey, string $usageType, string $unit): ?string
    {
        foreach ($this->reservations as $id => $reservation) {
            if ($reservation['customer_id'] === $customerId
                && $reservation['run_uuid'] === $runUuid
                && ($reservation['feature_key'] ?? null) === $featureKey
                && $reservation['usage_type'] === $usageType
                && ($reservation['unit'] ?? null) === $unit) {
                return $id;
            }
        }

        return null;
    }

    public function markExecuting(QuotaReservationHandle $handle): void
    {
        $reservation = $this->reservations[$handle->reservationId] ?? null;

        // Only a live `reserved` hold may cross the provider boundary; an expired
        // lease must not, or expiry reconciliation could refund a call in flight.
        if ($reservation === null || $reservation['status'] !== 'reserved'
            || $reservation['expires_at'] <= CarbonImmutable::now()->toDateTimeImmutable()) {
            throw new RuntimeException('LF_RESERVATION_UNEXPECTED_STATUS');
        }

        $this->reservations[$handle->reservationId]['status'] = 'executing';
    }

    public function markSettling(QuotaReservationHandle $handle): void
    {
        if (($this->reservations[$handle->reservationId]['status'] ?? null) !== 'executing') {
            throw new RuntimeException('LF_RESERVATION_UNEXPECTED_STATUS');
        }

        $this->reservations[$handle->reservationId]['status'] = 'settling';
        $this->reservations[$handle->reservationId]['provider_completed'] = true;
    }

    public function commit(QuotaReservationHandle $handle, float $actualQuantity): void
    {
        $this->commitCalls++;
        if ($this->commitFailure !== null) {
            throw $this->commitFailure;
        }

        $this->assertQuantity($actualQuantity, true);
        $actualQuantity = (float) $this->decimal($actualQuantity);
        $id = $handle->reservationId;
        $reservation = $this->reservations[$id] ?? null;

        // Replays of an already-recorded settlement are no-ops; a different
        // quantity for a settled attempt is a conflict, never an overwrite.
        if ($reservation !== null && $reservation['status'] === 'reconciled_released'
            && $actualQuantity === 0.0 && $reservation['provider_completed'] === true) {
            return;
        }
        if ($reservation !== null && in_array($reservation['status'], self::SETTLED, true)) {
            if ($this->decimal((float) $reservation['committed_quantity']) !== $this->decimal($actualQuantity)) {
                throw new RuntimeException('LF_USAGE_SETTLEMENT_CONFLICT');
            }

            return;
        }
        if ($reservation === null || $reservation['status'] !== 'settling') {
            throw new RuntimeException('LF_RESERVATION_NOT_SETTLING');
        }

        if ($actualQuantity === 0.0) {
            // Positive evidence nothing was consumed: closed unused, no usage
            // event, and the whole hold returns to capacity.
            $this->reservations[$id]['status'] = 'reconciled_released';
            $this->balance += $reservation['quantity'];

            return;
        }

        // The true quantity is recorded even when it overshoots the hold;
        // capacity is consumed by what was measured, not by what was held.
        $this->reservations[$id]['committed_quantity'] = $actualQuantity;
        $this->reservations[$id]['usage_event_id'] = $this->nextUsageEventId++;
        $this->reservations[$id]['status'] = $actualQuantity > $reservation['quantity']
            ? 'committed_over_limit'
            : 'committed';
        $this->balance += $reservation['quantity'] - $actualQuantity;
    }

    public function release(QuotaReservationHandle $handle): void
    {
        $status = $this->reservations[$handle->reservationId]['status'] ?? null;

        // A hold past the provider boundary is refused outright, so a gate that
        // released one fails loudly instead of silently undercounting usage.
        if (in_array($status, ['executing', 'settling'], true)) {
            throw new RuntimeException('LF_RESERVATION_PAST_PROVIDER_BOUNDARY');
        }

        $this->releaseCalls++;
        if ($status === 'reserved') {
            $this->reservations[$handle->reservationId]['status'] = 'released';
            $this->balance += $handle->quantity;
        }
    }

    /** Simulates the process dying before commit or release. */
    public function expire(string $reservationId): void
    {
        $this->reservations[$reservationId]['expires_at'] = CarbonImmutable::now()->subMinute()->toDateTimeImmutable();
    }

    public function reconcileExpired(int $customerId): int
    {
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $reclaimed = 0;
        foreach ($this->reservations as $id => $reservation) {
            if ($reservation['status'] !== 'reserved' || $reservation['customer_id'] !== $customerId || $reservation['expires_at'] > $now) {
                continue;
            }
            $this->reservations[$id]['status'] = 'expired';
            $this->balance += $reservation['quantity'];
            $reclaimed++;
        }

        return $reclaimed;
    }

    /**
     * Stands in for provider-aware reconciliation. It terminates a hold past
     * the boundary only when a test states positively what was consumed —
     * never from elapsed time.
     */
    public function reconcileUnsettled(int $customerId): array
    {
        $outcome = ['released' => 0, 'settled' => 0];

        foreach ($this->reservations as $id => $reservation) {
            if ($reservation['customer_id'] !== $customerId
                || ! in_array($reservation['status'], ['executing', 'settling'], true)) {
                continue;
            }

            // Evidence the provider consumed nothing: hand the hold back.
            if (($reservation['provider_consumed_nothing'] ?? false) === true) {
                $this->reservations[$id]['status'] = 'reconciled_released';
                $this->balance += $reservation['quantity'];
                $outcome['released']++;

                continue;
            }

            // Evidence the provider did consume: settle it forward with the
            // true quantity. Releasing here would lose a real measurement whose
            // producer died before it could settle.
            $consumed = $reservation['provider_consumed_quantity'] ?? null;
            if ($consumed !== null) {
                $this->reservations[$id]['committed_quantity'] = $consumed;
                $this->reservations[$id]['usage_event_id'] = $this->nextUsageEventId++;
                $this->reservations[$id]['status'] = $consumed > $reservation['quantity']
                    ? 'committed_over_limit'
                    : 'committed';
                $this->balance += $reservation['quantity'] - $consumed;
                $outcome['settled']++;
            }

            // No evidence either way: the hold stays held.
        }

        return $outcome;
    }

    /** A test asserting positive evidence that the provider consumed nothing. */
    public function proveNothingConsumed(string $reservationId): void
    {
        $this->reservations[$reservationId]['provider_consumed_nothing'] = true;
    }

    /** A test asserting positive evidence of how much the provider consumed. */
    public function proveConsumed(string $reservationId, float $quantity): void
    {
        $this->reservations[$reservationId]['provider_consumed_quantity'] = $quantity;
    }

    private function handleFor(string $id): QuotaReservationHandle
    {
        $reservation = $this->reservations[$id];

        return new QuotaReservationHandle(
            $id,
            $reservation['customer_id'],
            $reservation['model_run_id'],
            $reservation['run_uuid'],
            $reservation['feature_key'],
            $reservation['usage_type'],
            $reservation['quantity'],
            $reservation['unit'],
            $reservation['expires_at'],
            $reservation['status'],
            $reservation['committed_quantity'] ?? null,
            $reservation['usage_event_id'] ?? null,
        );
    }

    /** Same acceptance rule as the store's quantity() guard. */
    private function assertQuantity(float $quantity, bool $allowZero): void
    {
        if (! is_finite($quantity) || $quantity < 0 || (! $allowZero && $quantity === 0.0)
            || round($quantity, 6) >= 100000000000000 || ($quantity > 0 && round($quantity, 6) === 0.0)) {
            throw new RuntimeException('LF_USAGE_INVALID_QUANTITY');
        }
    }

    private function decimal(float $value): string
    {
        return number_format($value, 6, '.', '');
    }
}
