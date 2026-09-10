<?php

namespace Tests\Support\Ai;

use App\Contracts\Ai\UsageQuotaReserver;
use App\Support\Ai\QuotaReservationHandle;
use Closure;
use DateTimeImmutable;
use RuntimeException;

/**
 * In-memory reserver used to exercise the gate's quota lifecycle.
 *
 * It models the one property that matters: a reservation is taken from the
 * balance the moment it is granted, not when it is committed. Anything that
 * "checks then calls" would therefore be able to oversubscribe here, and the
 * tests would catch it.
 *
 * `onReserve` lets a test re-enter the gate from inside a reservation, which is
 * how a concurrent second request is simulated without threads.
 */
final class FakeUsageQuotaReserver implements UsageQuotaReserver
{
    /** @var array<string,array{customer_id:int,model_run_id:int,run_uuid:string,quantity:float,expires_at:DateTimeImmutable,status:string}> */
    public array $reservations = [];

    public int $reserveCalls = 0;

    public int $commitCalls = 0;

    public int $releaseCalls = 0;

    private ?Closure $onReserve = null;

    private ?RuntimeException $commitFailure = null;

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
        float $quantity,
        string $unit,
    ): ?QuotaReservationHandle {
        $this->reserveCalls++;

        if ($this->onReserve !== null) {
            $callback = $this->onReserve;
            $this->onReserve = null;
            $callback();
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
            'quantity' => $quantity,
            'expires_at' => new DateTimeImmutable('+5 minutes'),
            'status' => 'reserved',
        ];

        return new QuotaReservationHandle(
            $id,
            $customerId,
            $modelRunId,
            $runUuid,
            $featureKey,
            $quantity,
            $unit,
            $this->reservations[$id]['expires_at'],
        );
    }

    public function markExecuting(QuotaReservationHandle $handle): void
    {
        $this->reservations[$handle->reservationId]['status'] = 'executing';
    }

    public function markSettling(QuotaReservationHandle $handle): void
    {
        $this->reservations[$handle->reservationId]['status'] = 'settling';
    }

    public function commit(QuotaReservationHandle $handle, float $actualQuantity): void
    {
        $this->commitCalls++;
        if ($this->commitFailure !== null) {
            throw $this->commitFailure;
        }

        // Mirrors the Commercial contract: the true quantity is always
        // recorded, and overshooting the hold changes the terminal status
        // rather than the number.
        $this->reservations[$handle->reservationId]['committed_quantity'] = $actualQuantity;
        $this->reservations[$handle->reservationId]['status'] = $actualQuantity > $handle->quantity
            ? 'committed_over_limit'
            : 'committed';
    }

    public function release(QuotaReservationHandle $handle): void
    {
        // Mirrors the Commercial contract: a hold past the provider boundary is
        // refused outright, so a gate that released one would fail loudly here
        // instead of silently undercounting usage.
        if (in_array($this->reservations[$handle->reservationId]['status'] ?? 'reserved', ['executing', 'settling'], true)) {
            throw new RuntimeException('release() on a hold that crossed the provider boundary');
        }

        $this->releaseCalls++;
        if (($this->reservations[$handle->reservationId]['status'] ?? 'committed') === 'reserved') {
            $this->reservations[$handle->reservationId]['status'] = 'released';
            $this->balance += $handle->quantity;
        }
    }

    /** Simulates the process dying before commit or release. */
    public function expire(string $reservationId): void
    {
        $this->reservations[$reservationId]['expires_at'] = new DateTimeImmutable('-1 minute');
    }

    public function reconcileExpired(int $customerId): int
    {
        $now = new DateTimeImmutable;
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
     * the boundary only when a test states positively that nothing was
     * consumed — never from elapsed time.
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
                $this->reservations[$id]['status'] = $consumed > $reservation['quantity']
                    ? 'committed_over_limit'
                    : 'committed';
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
}
