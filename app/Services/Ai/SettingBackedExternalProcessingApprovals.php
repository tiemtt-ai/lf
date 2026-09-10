<?php

namespace App\Services\Ai;

use App\Contracts\Ai\ExternalProcessingApprovals;
use App\Contracts\Ai\TenantSettingSource;
use App\Support\Ai\ProviderGateRequest;

/**
 * Gate step 2 — matches a request against the tenant's approved
 * `ai.external_processing.<provider>.<purpose>` setting.
 *
 * Expected setting payload:
 *   [
 *     'approved'          => true,
 *     'data_classes'      => ['derived_text', ...],   // the approved set
 *     'execution_regions' => ['lf_managed', ...],
 *     'retention_classes' => ['transient', ...],
 *   ]
 *
 * Matching is deliberately narrow. The request's data classes must be a subset
 * of what the tenant approved — approving `derived_text` never implies consent
 * to send `personal_data` to the same provider — and region and retention must
 * be listed explicitly. A missing key is a denial, never a wildcard.
 */
final class SettingBackedExternalProcessingApprovals implements ExternalProcessingApprovals
{
    public function __construct(private readonly TenantSettingSource $settings) {}

    public function approves(int $customerId, ProviderGateRequest $request): bool
    {
        $setting = $this->settings->get(
            $customerId,
            "ai.external_processing.{$request->provider}.{$request->purpose}"
        );

        if ($setting === null || ($setting['approved'] ?? false) !== true) {
            return false;
        }

        $approvedClasses = $this->stringList($setting['data_classes'] ?? null);
        $requested = $request->normalizedDataClasses();
        if ($requested === [] || array_diff($requested, $approvedClasses) !== []) {
            return false;
        }

        return in_array($request->executionRegion, $this->stringList($setting['execution_regions'] ?? null), true)
            && in_array($request->retentionClass, $this->stringList($setting['retention_classes'] ?? null), true);
    }

    /** @return array<int,string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
