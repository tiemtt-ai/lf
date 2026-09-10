<?php

namespace Tests\Support\Ai;

use App\Contracts\Ai\AiProviderAdapter;
use App\Support\Ai\AllowedExecution;
use RuntimeException;

/**
 * Stands in for a real provider adapter without any network access.
 *
 * `credentialResolved` is the assertion that matters: a credential is only
 * looked up here, inside the adapter, and only because the gate reached this
 * point. Every blocked path must leave it false.
 */
final class SpyProviderAdapter implements AiProviderAdapter
{
    public int $calls = 0;

    public bool $credentialResolved = false;

    public ?AllowedExecution $lastExecution = null;

    public function __construct(
        private readonly ?RuntimeException $failWith = null,
        private readonly string $provider = 'approved-provider',
        private readonly string $model = 'approved-model',
        private readonly ?array $measurements = null,
    ) {}

    public function supportsModel(string $model): bool
    {
        return $model === $this->model;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function execute(AllowedExecution $execution): array
    {
        $this->calls++;
        $this->lastExecution = $execution;

        // The only legitimate point of credential resolution.
        $this->credentialResolved = true;

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return $this->measurements
            ?? ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15, 'latency_ms' => 42];
    }
}
