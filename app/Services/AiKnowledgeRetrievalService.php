<?php

namespace App\Services;

use App\Contracts\Ai\VectorStore;
use App\Exceptions\AiEmbeddingException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Turns vector hits into citable chunks — or into nothing.
 *
 * The index is a candidate generator and holds no authority (ADR-0006 v1.0.2).
 * It cannot know that a chunk was archived, that a source was superseded, that
 * a deletion is in flight, or that this actor may not read the Media the text
 * came from. So a hit survives only if the relational side and Media
 * authorization both still say yes, and the order of those checks does not
 * matter because every one of them must pass.
 *
 * Over-fetching from the store is what makes the filtering affordable: drops
 * are expected, so the store is asked for more than the caller wants.
 */
final class AiKnowledgeRetrievalService
{
    public function __construct(
        private readonly VectorStore $store,
        private readonly CourseMediaOwnerContextAuthorizer $authorizer,
    ) {}

    /**
     * @param  array<int,float>  $queryVector
     * @return array<int,array<string,mixed>> Citable chunks, best first.
     */
    public function retrieve(int $actorId, array $queryVector, int $limit = 10): array
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiEmbeddingException('unauthorized');

        $model = (string) config('ai.embedding.model', '');
        $provider = (string) config('ai.embedding.provider', '');
        $dimensions = (int) config('ai.embedding.dimensions', 0);

        if ($provider === '' || $model === '' || $dimensions < 1 || ! $this->store->isConfigured()) {
            return [];
        }

        // A query vector of the wrong width is not a search, it is a bug. It
        // must not reach the store, where it would either error or, worse, be
        // padded into a meaningless neighbourhood.
        if (count($queryVector) !== $dimensions) {
            throw new AiEmbeddingException('LF_EMBEDDING_DIMENSION_MISMATCH');
        }

        $collection = $this->store->collectionFor($model);
        $overfetch = max(1, (int) config('ai.embedding.retrieval_overfetch', 4));

        $keys = $this->store->search($customerId, $collection, $queryVector, $limit * $overfetch);

        if ($keys === []) {
            return [];
        }

        $rank = array_flip($keys);

        $rows = DB::table('ai_embeddings as e')
            ->join('ai_knowledge_chunks as c', function ($join): void {
                $join->on('c.id', '=', 'e.knowledge_chunk_id')->on('c.customer_id', '=', 'e.customer_id');
            })
            ->join('ai_knowledge_sources as s', function ($join): void {
                $join->on('s.id', '=', 'c.knowledge_source_id')->on('s.customer_id', '=', 'c.customer_id');
            })
            // Tenant scope is applied here as well as in the store filter. The
            // two are independent defences: a payload filter that silently
            // stopped matching would otherwise be the only thing standing
            // between tenants.
            ->where('e.customer_id', $customerId)
            ->where('e.vector_index', $collection)
            ->whereIn('e.vector_key', $keys)
            // Enumerated by name. `ready` is the only status whose point is
            // both present and current; `stale` and `deletion_pending` points
            // may still be in the index precisely because deletion has not been
            // acknowledged yet, which is exactly when this filter matters.
            ->where('e.status', 'ready')
            ->where('e.provider', $provider)
            ->where('e.model', $model)
            ->where('c.status', 'active')
            ->where('s.status', 'active')
            ->whereNull('c.deletion_requested_at')
            ->whereNull('s.deletion_requested_at')
            ->get([
                'e.vector_key', 'e.embedding_hash', 'e.knowledge_chunk_id',
                'c.chunk_uuid', 'c.content', 'c.content_hash', 'c.locator_type',
                'c.locator_start', 'c.locator_end', 'c.part_index', 'c.knowledge_source_id',
                's.source_uuid', 's.source_type', 's.source_id', 's.usage_type',
                's.content_type', 's.media_file_id', 's.title',
                's.identity_fingerprint', 's.identity_version',
            ]);

        $results = [];
        $authorization = [];

        foreach ($rows as $row) {
            // Revision guard. The index key encodes the revision that was
            // embedded; if the chunk or its source has moved on without the
            // stale sweep catching up, the vector is answering for text that no
            // longer exists at that citation.
            $current = 'sha256:'.hash('sha256', implode('|', [
                (string) $row->content_hash,
                (string) $row->identity_fingerprint,
                (string) $row->identity_version,
                $provider,
                $model,
                (string) $dimensions,
            ]));

            if (! hash_equals((string) $row->embedding_hash, $current)) {
                continue;
            }

            $ownerKey = $row->source_type.':'.$row->source_id;

            if (! array_key_exists($ownerKey, $authorization)) {
                // Media authorization is re-entered per source, not inherited
                // from ingestion. `media_file_id` on the source is citation
                // provenance and grants nothing (ADR-0006 v1.0.3): the actor
                // asking now may not be the actor who ingested then.
                //
                // A source with no Media binding has no authorization path
                // defined for it here, so it is refused rather than assumed
                // readable.
                $authorization[$ownerKey] = $row->content_type !== null
                    && $this->authorizer->authorized(
                        $customerId,
                        (string) $row->source_type,
                        (int) $row->source_id,
                        $actorId,
                    );
            }

            if ($authorization[$ownerKey] !== true) {
                continue;
            }

            $results[] = [
                'rank' => $rank[$row->vector_key] ?? PHP_INT_MAX,
                'knowledge_chunk_id' => (int) $row->knowledge_chunk_id,
                'chunk_uuid' => (string) $row->chunk_uuid,
                'knowledge_source_id' => (int) $row->knowledge_source_id,
                'source_uuid' => (string) $row->source_uuid,
                'title' => (string) $row->title,
                'content' => (string) $row->content,
                'locator' => [
                    'type' => (string) $row->locator_type,
                    'start' => (string) $row->locator_start,
                    'end' => (string) $row->locator_end,
                    'part_index' => (int) $row->part_index,
                ],
                'media_file_id' => $row->media_file_id === null ? null : (int) $row->media_file_id,
                'source_fingerprint' => (string) $row->identity_fingerprint,
                'processing_version' => (string) $row->identity_version,
            ];
        }

        // The store ranked these by distance; the relational join did not
        // preserve that order, so it is restored rather than approximated.
        usort($results, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return array_slice(array_map(static function (array $hit): array {
            unset($hit['rank']);

            return $hit;
        }, $results), 0, $limit);
    }
}
