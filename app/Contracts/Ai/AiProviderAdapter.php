<?php

namespace App\Contracts\Ai;

use App\Support\Ai\AllowedExecution;

/**
 * The only place a provider credential may be resolved, and only after the
 * gate has already returned `allowed`.
 *
 * An adapter never re-runs or second-guesses the gate; it is unreachable
 * unless the gate called it. Implementations must keep the credential inside
 * the call: it must not reach a command line, log line, exception message,
 * run metadata or any audit payload.
 */
interface AiProviderAdapter
{
    /**
     * The provider this adapter actually talks to.
     *
     * The gate compares it against the provider it approved. Without this an
     * adapter could pass the allow-list as provider A and then call provider B,
     * which would make every approval above it meaningless.
     */
    public function provider(): string;

    /**
     * Whether this adapter serves the model the gate approved.
     *
     * Pinning the provider alone is not enough: an adapter could pass the
     * allow-list as the approved provider and then call a model that was never
     * reviewed for this purpose, data classes or retention class.
     */
    public function supportsModel(string $model): bool;

    /** @return array<string,mixed> Non-sensitive measurements of the call. */
    public function execute(AllowedExecution $execution): array;
}
