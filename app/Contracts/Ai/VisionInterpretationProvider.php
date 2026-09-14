<?php

namespace App\Contracts\Ai;

use App\Support\Ai\VisionImageInput;

/**
 * Turns one region image into a textual interpretation.
 *
 * Provider-agnostic on purpose. The output schema belongs to the provider
 * decision (ADR-0020 D7), so this port returns plain text and the adapter —
 * not the provider — decides whether it is acceptable.
 *
 * Implementations resolve credentials themselves, at call time, and only
 * because the gate already returned `allowed`.
 */
interface VisionInterpretationProvider
{
    public function provider(): string;

    public function supportsModel(string $model): bool;

    public function interpret(string $model, VisionImageInput $image): string;
}
