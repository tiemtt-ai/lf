<?php

namespace App\Services\Ai;

use App\Contracts\Ai\VisionInterpretationProvider;
use App\Support\Ai\VisionImageInput;
use RuntimeException;

/**
 * The bound default. No vision provider is approved (ADR-0018, ADR-0020 D6).
 *
 * It supports no model, so the gate refuses the adapter with
 * AI_ADAPTER_MISMATCH before anything is called; and it throws rather than
 * returning text, so an unapproved provider can never produce an
 * interpretation that looks legitimate.
 */
final class UnavailableVisionInterpretationProvider implements VisionInterpretationProvider
{
    public function provider(): string
    {
        return (string) config('ai.vision.provider', 'unconfigured');
    }

    public function supportsModel(string $model): bool
    {
        return false;
    }

    public function interpret(string $model, VisionImageInput $image): string
    {
        throw new RuntimeException('LF_VISION_PROVIDER_UNAVAILABLE');
    }
}
