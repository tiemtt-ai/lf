<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AuthoringProposalProvider;
use App\Support\Ai\AuthoringProposalInput;
use RuntimeException;

/**
 * Shipped binding: no authoring provider is approved (ADR-0018). It supports no
 * model, so the gate refuses it as AI_ADAPTER_MISMATCH before any call.
 */
final class UnavailableAuthoringProposalProvider implements AuthoringProposalProvider
{
    public function provider(): string
    {
        return 'unavailable';
    }

    public function supportsModel(string $model): bool
    {
        return false;
    }

    public function propose(string $model, AuthoringProposalInput $input): string
    {
        throw new RuntimeException('LF_AUTHORING_PROVIDER_UNAVAILABLE');
    }
}
