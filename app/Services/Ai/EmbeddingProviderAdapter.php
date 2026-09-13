<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiProviderAdapter;
use App\Contracts\Ai\EmbeddingProvider;
use App\Contracts\Ai\VectorStore;
use App\Support\Ai\AllowedExecution;
use App\Support\Ai\EmbeddingWorkItem;
use App\Support\Ai\VectorPoint;
use RuntimeException;

/**
 * Bridges the embedding provider and the vector store into one gated call.
 *
 * Both halves sit behind the gate together on purpose. Writing to the index is
 * as much an external side effect as the provider call, and splitting them
 * would leave the store write outside the boundary that quota, approval and
 * safety protect.
 *
 * The worker keeps a reference to this object: `indexedItems()` reports what
 * actually reached the store, which is the only basis on which an embedding row
 * may be marked `ready` or, after a partial failure, queued for purge.
 */
final class EmbeddingProviderAdapter implements AiProviderAdapter
{
    /** @var array<int,EmbeddingWorkItem> */
    private array $indexed = [];

    /** @param array<int,EmbeddingWorkItem> $items */
    public function __construct(
        private readonly EmbeddingProvider $provider,
        private readonly VectorStore $store,
        private readonly array $items,
        private readonly int $expectedDimensions,
    ) {}

    public function provider(): string
    {
        return $this->provider->provider();
    }

    public function supportsModel(string $model): bool
    {
        return $this->provider->supportsModel($model);
    }

    /** @return array<int,EmbeddingWorkItem> Items provably written to the store. */
    public function indexedItems(): array
    {
        return $this->indexed;
    }

    /** @return array<string,mixed> */
    public function execute(AllowedExecution $execution): array
    {
        if ($this->items === []) {
            return ['quota_quantity' => 0.0, 'chunk_count' => 0, 'indexed_count' => 0];
        }

        if (! $this->store->isConfigured()) {
            // Refuse before the provider call. Embedding without a place to put
            // the result spends quota to produce nothing.
            throw new RuntimeException('LF_VECTOR_STORE_UNAVAILABLE');
        }

        $vectors = $this->provider->embed(
            $execution->request->model,
            array_map(static fn (EmbeddingWorkItem $item): string => $item->text, $this->items),
        );

        // Alignment is positional, so a short or re-keyed response would silently
        // attach one chunk's vector to another chunk's identity. Nothing
        // downstream could detect that, so it is refused here.
        if (count($vectors) !== count($this->items) || array_keys($vectors) !== range(0, count($this->items) - 1)) {
            throw new RuntimeException('LF_EMBEDDING_RESPONSE_MISALIGNED');
        }

        foreach ($this->items as $index => $item) {
            $vector = $vectors[$index];

            if (count($vector) !== $this->expectedDimensions) {
                throw new RuntimeException('LF_EMBEDDING_DIMENSION_MISMATCH');
            }

            $this->store->upsert(new VectorPoint(
                $item->collection,
                $item->vectorKey,
                array_values(array_map('floatval', $vector)),
                $execution->customerId,
                $item->knowledgeChunkId,
                $item->knowledgeSourceId,
                $item->sourceFingerprint,
                $item->processingVersion,
            ));

            // Appended only after the store acknowledged. A partial failure
            // leaves this list exact, which is what lets the worker purge the
            // points that did land instead of guessing.
            $this->indexed[] = $item;
        }

        return [
            'quota_quantity' => (float) count($this->items),
            'chunk_count' => count($this->items),
            'indexed_count' => count($this->indexed),
            'dimensions' => $this->expectedDimensions,
        ];
    }
}
