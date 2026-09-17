<?php

namespace App\Contracts\Ai;

use App\Support\Ai\AuthoringProposalInput;

/**
 * Produces Step 7 authoring suggestions for one activity.
 *
 * Returns the raw model answer; the adapter, not the provider, validates it
 * against the payload schema. Implementations resolve credentials at call time,
 * only because the gate already returned `allowed`.
 */
interface AuthoringProposalProvider
{
    public function provider(): string;

    public function supportsModel(string $model): bool;

    public function propose(string $model, AuthoringProposalInput $input): string;
}
