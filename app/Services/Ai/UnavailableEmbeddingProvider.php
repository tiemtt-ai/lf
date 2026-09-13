<?php

namespace App\Services\Ai;

use App\Contracts\Ai\EmbeddingProvider;
use RuntimeException;

/**
 * Fail-closed default. No embedding provider is approved: `config('ai.providers')`
 * ships empty and ADR-0018 requires a per-provider decision before anything
 * leaves the tenant boundary.
 *
 * It throws rather than returning empty vectors. A silent empty result would
 * travel down the pipeline and be written to the index as a legitimate-looking
 * point; refusing loudly keeps an unapproved provider from ever producing data.
 */
final class UnavailableEmbeddingProvider implements EmbeddingProvider
{
    public function provider(): string
    {
        return (string) config('ai.embedding.provider', 'unconfigured');
    }

    public function supportsModel(string $model): bool
    {
        return false;
    }

    public function embed(string $model, array $texts): array
    {
        throw new RuntimeException('LF_EMBEDDING_PROVIDER_UNAVAILABLE');
    }
}
