<?php

namespace App\Support\Ai;

/**
 * Outcome of the gate. Always paired with exactly one `ai_model_runs` row,
 * whether the attempt was allowed or blocked.
 */
final readonly class ProviderGateDecision
{
    private function __construct(
        public bool $allowed,
        public int $modelRunId,
        public string $runUuid,
        public ?string $errorCode,
        public ?string $blockedStep,
        public ?AllowedExecution $execution,
    ) {}

    public static function allowed(AllowedExecution $execution): self
    {
        return new self(true, $execution->modelRunId, $execution->runUuid, null, null, $execution);
    }

    public static function blocked(int $modelRunId, string $runUuid, string $errorCode, string $step): self
    {
        return new self(false, $modelRunId, $runUuid, $errorCode, $step, null);
    }
}
