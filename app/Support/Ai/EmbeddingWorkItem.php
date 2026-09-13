<?php

namespace App\Support\Ai;

/**
 * One chunk queued for embedding.
 *
 * The text lives here and nowhere else in the gate path: `ProviderGateRequest`
 * is hashed into audit and must stay non-sensitive, so the payload travels
 * directly from the worker to the adapter and never through the ledger.
 */
final readonly class EmbeddingWorkItem
{
    public function __construct(
        public int $embeddingId,
        public int $knowledgeChunkId,
        public int $knowledgeSourceId,
        public string $collection,
        public string $vectorKey,
        public string $text,
        public string $sourceFingerprint,
        public string $processingVersion,
    ) {}
}
