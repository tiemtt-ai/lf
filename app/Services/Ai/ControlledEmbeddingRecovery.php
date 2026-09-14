<?php

namespace App\Services\Ai;

use App\Exceptions\AiEmbeddingException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** Operations-only recovery: confirmations are attestations, not crash detection. */
final class ControlledEmbeddingRecovery
{
    public function recover(int $actorId, string $runUuid, string $evidenceReference, bool $writerStopped, bool $storeQuiesced): array
    {
        $customerId = TenantContext::customerId()
            ?? throw new AiEmbeddingException('unauthorized');
        if (! $writerStopped || ! $storeQuiesced) {
            throw new AiEmbeddingException('LF_RECOVERY_CONFIRMATION_REQUIRED');
        }
        // A short ticket/reference, never a free-form prompt, URL or secret.
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,79}\z/D', $evidenceReference)
            || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/Di', $runUuid)) {
            throw new AiEmbeddingException('LF_RECOVERY_INVALID_REFERENCE');
        }

        return DB::transaction(function () use ($customerId, $actorId, $runUuid, $evidenceReference): array {
            $actor = DB::table('users')->where('customer_id', $customerId)->where('id', $actorId)
                ->lockForUpdate()->first();
            if ($actor === null || $actor->status !== 'active' || $actor->role !== 'customer_admin') {
                throw new AiEmbeddingException('unauthorized');
            }
            $run = DB::table('ai_model_runs')->where('customer_id', $customerId)->where('run_uuid', $runUuid)
                ->lockForUpdate()->first();
            if ($run === null || $run->purpose !== 'knowledge_embedding') {
                throw new AiEmbeddingException('LF_RECOVERY_RUN_NOT_FOUND');
            }
            $metadata = json_decode($run->metadata ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            if ($run->status === 'cancelled' && isset($metadata['controlled_recovery'])) {
                return ['run_id' => (int) $run->id, 'already_recovered' => true, 'deletion_requested' => 0];
            }
            if ($run->status !== 'running') {
                throw new AiEmbeddingException('AI_RUN_TRANSITION_CONFLICT');
            }

            $now = now();
            $metadata['controlled_recovery'] = [
                'actor_id' => $actorId,
                'at' => $now->toIso8601String(),
                'evidence_reference' => $evidenceReference,
                'writer_stopped' => true,
                'store_writes_quiesced' => true,
                'quota_action' => 'unchanged',
            ];
            // Run lock serializes recovery attempts. No external I/O is done
            // under it. Historical identifiers/measurements are not rewritten.
            DB::table('ai_model_runs')->where('customer_id', $customerId)->where('id', $run->id)
                ->where('status', 'running')->update([
                    'status' => 'cancelled', 'completed_at' => $now, 'updated_at' => $now,
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                ]);
            $count = DB::table('ai_embeddings')->where('customer_id', $customerId)->where('model_run_id', $run->id)
                ->whereIn('status', ['pending', 'ready', 'failed', 'stale'])
                ->update(['status' => 'deletion_pending', 'deletion_requested_at' => $now, 'updated_at' => $now]);

            return ['run_id' => (int) $run->id, 'already_recovered' => false, 'deletion_requested' => $count];
        }, 3);
    }
}
