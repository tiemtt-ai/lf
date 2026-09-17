<?php

namespace App\Services;

use App\Exceptions\AiAuthoringProposalException;
use App\Exceptions\CourseAuthoringContextException;
use App\Exceptions\LearningAuthoringBasisException;
use App\Services\Ai\AuthoringProposalRecords;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * AI port assertPublishableIntents (contract § Hash and dependency definitions).
 *
 * Called by the trusted Course publisher inside the publish transaction, which
 * already holds the Template lock. Course hands over its AI-origin Intent rows;
 * AI never reads Course tables. Every Intent must still be backed by an
 * accepted, unerased revision whose payload matches the Intent, a live human
 * target confirmation (not a rejection) whose hash equals Learning's current
 * snapshot, unchanged (or reconfirmed) Course context and current sources.
 * Any failure throws, so publish rolls back — an Intent is never dropped.
 *
 * Returns content-free lineage per Intent for the canonical Mapping snapshot.
 */
final class AiAuthoringPublicationService
{
    private const CONFIRMATIONS = ['confirm_target', 'reconfirm_target', 'rebase_target'];

    public function __construct(
        private readonly AuthoringProposalRecords $records,
        private readonly CourseAuthoringContextService $course,
        private readonly LearningAuthoringTargetService $learning,
    ) {}

    /**
     * @param  array<int,object>  $intents  Course Intent rows with origin ai_proposal
     * @return array<int,array<string,mixed>> lineage keyed by Intent id
     *
     * @throws AiAuthoringProposalException
     */
    public function assertPublishableIntents(int $publisherId, int $templateId, array $intents): array
    {
        $customerId = TenantContext::customerId() ?? throw new AiAuthoringProposalException('proposal_not_found');
        usort($intents, fn (object $a, object $b): int => [(int) $a->ai_proposal_id, (int) $a->id] <=> [(int) $b->ai_proposal_id, (int) $b->id]);

        $lineage = [];
        foreach ($intents as $intent) {
            if ($intent->origin !== 'ai_proposal' || $intent->source_type !== 'course_template_activity') {
                throw new AiAuthoringProposalException('invalid_proposal', 'intent_source');
            }
            // Template (held by publish) -> proposals in ascending id order.
            $proposal = DB::table('ai_authoring_proposals')->where('customer_id', $customerId)
                ->where('id', $intent->ai_proposal_id)->lockForUpdate()->first();
            if ($proposal === null || (int) $proposal->template_id !== $templateId || (int) $proposal->activity_id !== (int) $intent->source_id) {
                throw new AiAuthoringProposalException('proposal_not_found');
            }
            if ($proposal->status !== 'accepted') {
                throw new AiAuthoringProposalException('proposal_stale');
            }
            $revision = $this->records->acceptedRevision($proposal);
            if ($revision === null || (int) $revision->id !== (int) $intent->ai_proposal_revision_id || $revision->payload === null) {
                throw new AiAuthoringProposalException('proposal_revision_conflict');
            }
            $mapping = json_decode((string) $revision->payload, true)['mapping'] ?? null;
            if (! is_array($mapping) || $mapping['role'] !== $intent->mapping_role || ! $this->sameWeight($mapping['weight'], $intent->weight)) {
                throw new AiAuthoringProposalException('proposal_revision_conflict', 'payload');
            }

            $target = $this->review($proposal, (int) $intent->ai_target_review_id, (int) $revision->id);
            if ($target === null || ! in_array($target->action, self::CONFIRMATIONS, true)) {
                // A reject_target pointer blocks publication until a fresh confirmation.
                throw new AiAuthoringProposalException('proposal_target_changed', 'target_rejected');
            }
            try {
                $live = $this->learning->targetSnapshot((int) $intent->framework_id, (int) $intent->framework_version_id, (int) $intent->learning_node_id);
            } catch (LearningAuthoringBasisException) {
                throw new AiAuthoringProposalException('proposal_target_changed');
            }
            $confirmedNode = (int) (json_decode((string) $target->target_snapshot, true)['node']['id'] ?? 0);
            if (! hash_equals($target->target_hash, $live['target_hash']) || $live['node_status'] !== 'active'
                || $live['version_status'] !== 'published' || ($target->target_snapshot !== null && $confirmedNode !== (int) $intent->learning_node_id)) {
                throw new AiAuthoringProposalException('proposal_target_changed');
            }

            try {
                $course = $this->course->proposalContext($publisherId, (int) $proposal->activity_id, true);
            } catch (CourseAuthoringContextException) {
                throw new AiAuthoringProposalException('proposal_not_found');
            }
            $contextReview = $intent->ai_context_review_id === null ? null
                : $this->review($proposal, (int) $intent->ai_context_review_id, (int) $revision->id);
            if ($intent->ai_context_review_id !== null && ($contextReview === null || ! in_array($contextReview->action, ['reconfirm_context', 'rebase_target'], true))) {
                throw new AiAuthoringProposalException('proposal_context_changed');
            }
            $effectiveContext = $contextReview?->context_hash ?? $proposal->course_context_hash;
            if (! hash_equals($effectiveContext, $course['course_context_hash'])) {
                throw new AiAuthoringProposalException('proposal_context_changed');
            }

            $sources = $this->records->sources($proposal);
            if (! $this->records->intact($proposal, $sources, $revision)
                || $this->records->freshness($publisherId, $proposal, $course, $sources)['sources'] !== 'current') {
                throw new AiAuthoringProposalException('proposal_stale');
            }

            $lineage[(int) $intent->id] = [
                'proposal_id' => (int) $proposal->id,
                'proposal_uuid' => $proposal->proposal_uuid,
                'revision_id' => (int) $revision->id,
                'payload_hash' => $revision->payload_hash,
                'target_review_id' => (int) $target->id,
                'target_hash' => $target->target_hash,
                'context_review_id' => $contextReview === null ? null : (int) $contextReview->id,
                'context_hash' => $effectiveContext,
            ];
        }

        return $lineage;
    }

    private function review(object $proposal, int $reviewId, int $revisionId): ?object
    {
        return DB::table('ai_authoring_proposal_reviews')->where('customer_id', $proposal->customer_id)
            ->where('proposal_id', $proposal->id)->where('revision_id', $revisionId)->where('id', $reviewId)->first();
    }

    private function sameWeight(mixed $payloadWeight, mixed $intentWeight): bool
    {
        if ($payloadWeight === null || $intentWeight === null) {
            return $payloadWeight === null && $intentWeight === null;
        }

        return abs((float) $payloadWeight - (float) $intentWeight) < 0.0000005;
    }
}
