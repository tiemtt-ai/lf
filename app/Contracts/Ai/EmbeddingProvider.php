<?php

namespace App\Contracts\Ai;

/**
 * Turns chunk text into a vector.
 *
 * Provider-agnostic on purpose: the worker names a provider and model, the gate
 * approves that pair, and this interface is the only thing either side needs to
 * agree on. Swapping vendors must not reach the worker or the ledger.
 *
 * Implementations resolve credentials themselves, at call time, and only
 * because the gate already returned `allowed`.
 */
interface EmbeddingProvider
{
    public function provider(): string;

    public function supportsModel(string $model): bool;

    /**
     * @param  array<int,string>  $texts
     * @return array<int,array<int,float>> One vector per input, same order.
     */
    public function embed(string $model, array $texts): array;
}
