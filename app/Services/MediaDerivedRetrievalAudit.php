<?php

namespace App\Services;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Media-owned append-only access evidence; never stores text or query vectors. */
final class MediaDerivedRetrievalAudit
{
    /**
     * Retrieval of an AI Vision Interpretation built from a Media region is an
     * access event on Media-derived content (ai_vision_interpretations.md v1.1,
     * media_access_logs § Vision interpretation retrieval). Same guarantees as
     * knowledge retrieval: tenant-resolved references, failures are not
     * swallowed, and the interpretation text never enters the audit.
     */
    public function appendVisionInterpretation(int $actorId, object $interpretation, string $decision, string $retrievalUuid, ?string $errorCode = null): void
    {
        [$customerId, $mediaId, $userId] = $this->resolve($actorId, (int) $interpretation->media_file_id, $decision);

        DB::table('media_access_logs')->insert([
            'customer_id' => $customerId, 'media_file_id' => $mediaId, 'user_id' => $userId,
            'action' => 'read_derived', 'source_type' => 'ai', 'source_id' => null,
            'accessed_at' => now(), 'metadata' => json_encode([
                'operation' => 'vision_interpretation_retrieval', 'retrieval_uuid' => $retrievalUuid,
                'owner_type' => $interpretation->source_type, 'owner_id' => (int) $interpretation->source_id,
                'interpretation_uuid' => $interpretation->interpretation_uuid,
                'usage_type' => $interpretation->usage_type, 'content_type' => $interpretation->content_type,
                'processing_version' => $interpretation->processing_version,
                'source_fingerprint' => $interpretation->source_fingerprint,
                'locator' => ['type' => $interpretation->locator_type, 'start' => $interpretation->locator_start],
                'page' => (int) $interpretation->page,
                'decision' => $decision, 'error_code' => $decision === 'denied' ? ($errorCode ?? 'unauthorized') : null,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function append(int $actorId, object $unit, string $decision, string $retrievalUuid, ?string $errorCode = null): void
    {
        [$customerId, $mediaId, $userId] = $this->resolve($actorId, (int) $unit->media_file_id, $decision);

        // Do not swallow insertion failures: the caller must not disclose
        // derived content if its mandatory access evidence cannot be persisted.
        DB::table('media_access_logs')->insert([
            'customer_id' => $customerId, 'media_file_id' => $mediaId, 'user_id' => $userId,
            'action' => 'read_derived', 'source_type' => 'ai', 'source_id' => null,
            'accessed_at' => now(), 'metadata' => json_encode([
                'operation' => 'knowledge_retrieval', 'retrieval_uuid' => $retrievalUuid,
                'owner_type' => $unit->source_type, 'owner_id' => (int) $unit->source_id,
                'knowledge_source_id' => (int) $unit->knowledge_source_id,
                'knowledge_chunk_id' => (int) $unit->knowledge_chunk_id,
                'chunk_uuid' => $unit->chunk_uuid, 'usage_type' => $unit->usage_type,
                'content_type' => $unit->content_type, 'processing_version' => $unit->identity_version,
                'source_fingerprint' => $unit->identity_fingerprint,
                'locator' => ['type' => $unit->locator_type, 'start' => $unit->locator_start,
                    'end' => $unit->locator_end, 'part_index' => (int) $unit->part_index],
                'decision' => $decision, 'error_code' => $decision === 'denied' ? ($errorCode ?? 'unauthorized') : null,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Tenant, Media and actor resolution shared by every retrieval audit.
     *
     * @return array{0:int,1:int,2:?int}
     */
    private function resolve(int $actorId, int $mediaFileId, string $decision): array
    {
        $customerId = TenantContext::customerId() ?? throw new RuntimeException('LF_RETRIEVAL_AUDIT_TENANT_REQUIRED');
        if (! in_array($decision, ['allowed', 'denied'], true)) {
            throw new RuntimeException('LF_RETRIEVAL_AUDIT_INVALID_DECISION');
        }
        $mediaId = DB::table('media_files')->where('customer_id', $customerId)
            ->where('id', $mediaFileId)->value('id');
        if ($mediaId === null) {
            throw new RuntimeException('LF_RETRIEVAL_AUDIT_MEDIA_UNAVAILABLE');
        }
        $userId = DB::table('users')->where('customer_id', $customerId)->where('id', $actorId)->value('id');
        if ($decision === 'allowed' && $userId === null) {
            throw new RuntimeException('LF_RETRIEVAL_AUDIT_ACTOR_UNAVAILABLE');
        }

        return [(int) $customerId, (int) $mediaId, $userId === null ? null : (int) $userId];
    }
}
