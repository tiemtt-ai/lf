<?php

namespace App\Support\Ai;

/**
 * Proof that all five gate steps passed, handed to the adapter.
 *
 * The constructor is internal to the gate by convention: an adapter receives
 * this object, it never builds one. It carries the run identity so the adapter
 * reports measurements back against exactly one `ai_model_runs` row, and it
 * carries no credential — the adapter resolves that itself, after this point.
 */
final readonly class AllowedExecution
{
    public function __construct(
        public int $customerId,
        public int $modelRunId,
        public string $runUuid,
        public ProviderGateRequest $request,
        public ?QuotaReservationHandle $reservation,
    ) {}
}
