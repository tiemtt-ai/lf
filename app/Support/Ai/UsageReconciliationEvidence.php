<?php

namespace App\Support\Ai;

use DateTimeImmutable;
use InvalidArgumentException;

/** Exact attempt/metric binding and a safe receipt digest, never raw provider payload. */
final readonly class UsageReconciliationEvidence
{
    public function __construct(
        public int $customerId,
        public string $reservationId,
        public string $runUuid,
        public string $usageType,
        public string $unit,
        public float $actualQuantity,
        public DateTimeImmutable $occurredAt,
        public string $receiptHash,
    ) {
        if (! is_finite($actualQuantity) || $actualQuantity < 0
            || ($actualQuantity > 0 && round($actualQuantity, 6) === 0.0)
            || ! preg_match('/^[a-f0-9]{64}$/D', $receiptHash)) {
            throw new InvalidArgumentException('LF_USAGE_INVALID_EVIDENCE');
        }
    }
}
