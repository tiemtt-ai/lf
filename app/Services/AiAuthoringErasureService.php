<?php

namespace App\Services;

use App\Exceptions\AiAuthoringProposalException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Step 7 source-loss and owner-loss erasure (contract § Historical references
 * and cleanup, § Immutability and erasure enforcement).
 *
 * Reads keep denying content on their own; this service removes the content
 * that would otherwise remain. For a proposal whose source Media File was
 * deleted, or whose working Activity no longer exists: lock the proposal, move
 * it to deletion_pending, cancel its unapplied receipts as system cancellations,
 * erase revision payloads, source excerpts, review reasons and target/context
 * snapshots in bounded batches, and only then mark it deleted. IDs, hashes,
 * decisions and applied receipts remain; no Node, Intent or Mapping is undone
 * and no quota is refunded. The database triggers refuse any other change.
 */
final class AiAuthoringErasureService
{
    public function __construct(
        private readonly MediaService $media,
        private readonly CourseAuthoringExistenceService $course,
    ) {}

    /**
     * Proposals anchored to a Media File that Media confirms deleted.
     * Fail-closed against a spurious event: nothing happens otherwise.
     */
    public function requestForDeletedMediaFile(int $mediaFileId): int
    {
        if ($this->media->deletedMediaFileIds([$mediaFileId]) === []) {
            return 0;
        }
        $proposalIds = DB::table('ai_authoring_proposal_sources')->where('customer_id', $this->tenant())
            ->where('media_file_id', $mediaFileId)->distinct()->orderBy('proposal_id')->pluck('proposal_id')->all();

        return $this->requestMany($proposalIds, 'source_deleted');
    }

    /**
     * Reconciliation for a tenant: deleted Media anchors and missing working
     * Activities, walked by key so a long run of healthy rows cannot starve a
     * missing one. Then finish every pending erasure.
     *
     * @return array{requested:int,deleted:int}
     */
    public function reconcile(int $chunk = 500): array
    {
        $customerId = $this->tenant();
        $requested = 0;

        $after = 0;
        do {
            $mediaIds = DB::table('ai_authoring_proposal_sources as s')
                ->join('ai_authoring_proposals as p', function ($join): void {
                    $join->on('p.id', '=', 's.proposal_id')->on('p.customer_id', '=', 's.customer_id');
                })
                ->where('s.customer_id', $customerId)->whereNotIn('p.status', ['deletion_pending', 'deleted'])
                ->where('s.media_file_id', '>', $after)->distinct()->orderBy('s.media_file_id')
                ->limit(max(1, $chunk))->pluck('s.media_file_id')->map(fn ($id): int => (int) $id)->all();
            foreach ($this->media->deletedMediaFileIds($mediaIds) as $mediaFileId) {
                $requested += $this->requestForDeletedMediaFile($mediaFileId);
            }
            $after = $mediaIds === [] ? $after : end($mediaIds);
        } while (count($mediaIds) === max(1, $chunk));

        $after = 0;
        do {
            $activityIds = DB::table('ai_authoring_proposals')->where('customer_id', $customerId)
                ->whereNotIn('status', ['deletion_pending', 'deleted'])->where('activity_id', '>', $after)
                ->distinct()->orderBy('activity_id')->limit(min(max(1, $chunk), CourseAuthoringExistenceService::MAX_BATCH))
                ->pluck('activity_id')->map(fn ($id): int => (int) $id)->all();
            $missing = array_diff($activityIds, $this->course->existingActivityIds($activityIds));
            if ($missing !== []) {
                $proposalIds = DB::table('ai_authoring_proposals')->where('customer_id', $customerId)
                    ->whereIn('activity_id', $missing)->orderBy('id')->pluck('id')->all();
                $requested += $this->requestMany($proposalIds, 'owner_missing');
            }
            $after = $activityIds === [] ? $after : end($activityIds);
        } while (count($activityIds) === min(max(1, $chunk), CourseAuthoringExistenceService::MAX_BATCH));

        $deleted = 0;
        do {
            $batch = $this->finalize(100);
            $deleted += $batch;
        } while ($batch > 0);

        return ['requested' => $requested, 'deleted' => $deleted];
    }

    /**
     * Erase and tombstone up to $limit deletion_pending proposals. A crash
     * between batches resumes the remaining rows; nothing is ever rehydrated.
     */
    public function finalize(int $limit = 100): int
    {
        $customerId = $this->tenant();
        $ids = DB::table('ai_authoring_proposals')->where('customer_id', $customerId)
            ->where('status', 'deletion_pending')->orderBy('id')->limit(max(1, $limit))->pluck('id')->all();

        $deleted = 0;
        foreach ($ids as $id) {
            $deleted += DB::transaction(function () use ($customerId, $id): int {
                $proposal = DB::table('ai_authoring_proposals')->where('customer_id', $customerId)->where('id', $id)->lockForUpdate()->first();
                if ($proposal === null || $proposal->status !== 'deletion_pending') {
                    return 0;
                }
                $now = $this->now();
                DB::table('ai_authoring_proposal_revisions')->where('customer_id', $customerId)->where('proposal_id', $id)
                    ->whereNull('erased_at')->update(['payload' => null, 'erased_at' => $now]);
                DB::table('ai_authoring_proposal_sources')->where('customer_id', $customerId)->where('proposal_id', $id)
                    ->whereNull('erased_at')->update(['excerpt' => null, 'erased_at' => $now]);
                DB::table('ai_authoring_proposal_reviews')->where('customer_id', $customerId)->where('proposal_id', $id)
                    ->whereNull('erased_at')->update(['reason' => null, 'target_snapshot' => null, 'context_snapshot' => null, 'erased_at' => $now]);
                // The parent trigger re-verifies that every child is erased.
                DB::table('ai_authoring_proposals')->where('id', $id)->update([
                    'status' => 'deleted', 'deleted_at' => $now, 'lock_version' => (int) $proposal->lock_version + 1, 'updated_at' => $now,
                ]);

                return 1;
            }, 3);
        }

        return $deleted;
    }

    /** @param array<int,int|string> $proposalIds */
    private function requestMany(array $proposalIds, string $reason): int
    {
        $customerId = $this->tenant();
        $requested = 0;
        sort($proposalIds);
        foreach ($proposalIds as $id) {
            $requested += DB::transaction(function () use ($customerId, $id, $reason): int {
                $proposal = DB::table('ai_authoring_proposals')->where('customer_id', $customerId)->where('id', $id)->lockForUpdate()->first();
                if ($proposal === null || in_array($proposal->status, ['deletion_pending', 'deleted'], true)) {
                    return 0;
                }
                $now = $this->now();
                DB::table('ai_authoring_proposals')->where('id', $id)->update([
                    'status' => 'deletion_pending', 'deletion_requested_at' => $now,
                    'lock_version' => (int) $proposal->lock_version + 1, 'updated_at' => $now,
                ]);
                // System cancellation: no fake human decision, no undo of applied work.
                DB::table('ai_authoring_proposal_applications')->where('customer_id', $customerId)->where('proposal_id', $id)
                    ->whereIn('status', ['awaiting_publication', 'ready_to_apply', 'failed'])
                    ->update([
                        'status' => 'cancelled', 'cancelled_at' => $now, 'cancellation_kind' => 'system', 'cancelled_by' => null,
                        'cancel_reason_code' => $reason, 'error_code' => null, 'updated_at' => $now,
                    ]);

                return 1;
            }, 3);
        }

        return $requested;
    }

    private function tenant(): int
    {
        return TenantContext::customerId() ?? throw new AiAuthoringProposalException('proposal_not_found');
    }

    private function now(): string
    {
        return now()->utc()->format('Y-m-d H:i:s.u');
    }
}
