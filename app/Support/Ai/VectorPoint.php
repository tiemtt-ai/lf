<?php

namespace App\Support\Ai;

/**
 * One point as it is written to the vector store.
 *
 * The payload is deliberately narrow. ADR-0006 v1.0.2 forbids raw chunk text,
 * PII and signed URLs in a point: the store is a derived index, and anything
 * put there escapes the relational retention and deletion machinery that
 * governs the real data. What remains is identity, tenant and revision — enough
 * to post-validate a hit, and useless to anyone who obtains the index alone.
 */
final readonly class VectorPoint
{
    /** @param array<int,float> $vector */
    public function __construct(
        public string $collection,
        public string $vectorKey,
        public array $vector,
        public int $customerId,
        public int $knowledgeChunkId,
        public int $knowledgeSourceId,
        public string $sourceFingerprint,
        public string $processingVersion,
    ) {}

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return [
            'customer_id' => $this->customerId,
            'is_tenant' => true,
            'knowledge_chunk_id' => $this->knowledgeChunkId,
            'knowledge_source_id' => $this->knowledgeSourceId,
            'source_fingerprint' => $this->sourceFingerprint,
            'processing_version' => $this->processingVersion,
        ];
    }
}
