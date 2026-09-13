<?php

namespace App\Contracts\Ai;

use App\Support\Ai\QuotaReservationHandle;
use App\Support\Ai\UsageReconciliationEvidence;

/** Trusted, provider-specific receipts. Unknown is null, never inferred zero. */
interface UsageReconciliationEvidenceReader
{
    public function read(QuotaReservationHandle $handle): ?UsageReconciliationEvidence;
}
