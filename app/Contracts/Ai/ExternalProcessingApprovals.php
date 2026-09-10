<?php

namespace App\Contracts\Ai;

use App\Support\Ai\ProviderGateRequest;

/**
 * Step 2 of the provider execution gate.
 *
 * Answers one question for a tenant: does the setting
 * `ai.external_processing.<provider>.<purpose>` approve exactly this provider,
 * purpose, data classes, execution region and retention class?
 *
 * ADR-0018 is explicit that a provider running inside the LF-managed boundary
 * must not be inferred as external approval, so implementations must not treat
 * `managed` as an exemption.
 */
interface ExternalProcessingApprovals
{
    public function approves(int $customerId, ProviderGateRequest $request): bool;
}
