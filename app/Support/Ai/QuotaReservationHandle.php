<?php

namespace App\Support\Ai;

use DateTimeImmutable;

/**
 * Receipt for one atomic usage reservation.
 *
 * The handle carries an explicit expiry so a process that dies between
 * reserving and calling the provider cannot leak quota forever: whoever owns
 * the usage store reclaims expired reservations during reconciliation.
 */
final readonly class QuotaReservationHandle
{
    public function __construct(
        public string $reservationId,
        public int $customerId,
        public int $modelRunId,
        public string $runUuid,
        public string $featureKey,
        public string $usageType,
        public float $quantity,
        public string $unit,
        public DateTimeImmutable $expiresAt,
    ) {}
}
