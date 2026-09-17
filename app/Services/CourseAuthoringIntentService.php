<?php

namespace App\Services;

use App\Exceptions\CourseAuthoringContextException;
use App\Exceptions\LearningAuthoringBasisException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Course owner port applyReviewedIntent (contract § Owner-service ports).
 *
 * Only Course writes `core_course_template_learning_mapping_intents`. The AI
 * caller has already verified the accepted revision and the human target
 * confirmation; Course re-checks what it owns — current authority, the
 * Template's exact Framework selection and an existing Intent with the same
 * key — and asks Learning, through its port, whether the Node is still an
 * active Node of a published Version. It never widens manual authoring: this
 * path only stores an `ai_proposal` Intent carrying exact provenance.
 */
final class CourseAuthoringIntentService
{
    public function __construct(
        private readonly CourseAuthoringContextService $context,
        private readonly LearningAuthoringTargetService $learning,
    ) {}

    /**
     * Runs inside the caller's transaction, which already holds the Template
     * lock; the lock is re-acquired here so the port is safe on its own.
     */
    public function applyReviewedIntent(
        int $actorId,
        int $activityId,
        int $frameworkId,
        int $frameworkVersionId,
        int $nodeId,
        string $mappingRole,
        ?float $weight,
        int $aiProposalId,
        int $aiProposalRevisionId,
        int $aiTargetReviewId,
        ?int $aiContextReviewId,
    ): int {
        $customerId = TenantContext::customerId() ?? throw new CourseAuthoringContextException('not_found');
        $context = $this->context->proposalContext($actorId, $activityId, true);

        if ($context['selected_framework_id'] !== $frameworkId || $context['selected_framework_version_id'] !== $frameworkVersionId) {
            throw new CourseAuthoringContextException('framework_selection_conflict');
        }
        try {
            $target = $this->learning->targetSnapshot($frameworkId, $frameworkVersionId, $nodeId);
        } catch (LearningAuthoringBasisException) {
            throw new CourseAuthoringContextException('target_unavailable');
        }
        if ($target['node_status'] !== 'active' || $target['version_status'] !== 'published') {
            throw new CourseAuthoringContextException('target_unavailable');
        }

        $key = [
            'customer_id' => $customerId, 'template_id' => $context['template_id'],
            'source_type' => 'course_template_activity', 'source_id' => $activityId,
            'learning_node_id' => $nodeId, 'mapping_role' => $mappingRole,
        ];
        // A manual Intent with the same key is never relabelled as AI.
        if (DB::table('core_course_template_learning_mapping_intents')->where($key)->exists()) {
            throw new CourseAuthoringContextException('intent_conflict');
        }

        $now = now();
        $intentId = (int) DB::table('core_course_template_learning_mapping_intents')->insertGetId($key + [
            'framework_id' => $frameworkId, 'framework_version_id' => $frameworkVersionId,
            'weight' => $weight, 'origin' => 'ai_proposal',
            'ai_proposal_id' => $aiProposalId, 'ai_proposal_revision_id' => $aiProposalRevisionId,
            'ai_target_review_id' => $aiTargetReviewId, 'ai_context_review_id' => $aiContextReviewId,
            'created_by' => $actorId, 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('core_course_templates')->where('customer_id', $customerId)->where('id', $context['template_id'])
            ->increment('working_revision', 1, ['updated_at' => $now]);

        return $intentId;
    }

    /**
     * The mutable AI-origin Intent currently carrying this accepted revision,
     * found by provenance rather than by the historical receipt intent_id, so a
     * rebased replacement is found too. Locked for the caller's transaction.
     *
     * @return array{intent_id:int,ai_target_review_id:int,ai_context_review_id:?int}|null
     */
    public function aiIntentForRevision(int $actorId, int $activityId, int $aiProposalId, int $aiProposalRevisionId): ?array
    {
        $customerId = TenantContext::customerId() ?? throw new CourseAuthoringContextException('not_found');
        $context = $this->context->proposalContext($actorId, $activityId, true);

        $intent = DB::table('core_course_template_learning_mapping_intents')->where('customer_id', $customerId)
            ->where('template_id', $context['template_id'])->where('origin', 'ai_proposal')
            ->where('ai_proposal_id', $aiProposalId)->where('ai_proposal_revision_id', $aiProposalRevisionId)
            ->lockForUpdate()->first(['id', 'ai_target_review_id', 'ai_context_review_id']);

        return $intent === null ? null : [
            'intent_id' => (int) $intent->id,
            'ai_target_review_id' => (int) $intent->ai_target_review_id,
            'ai_context_review_id' => $intent->ai_context_review_id === null ? null : (int) $intent->ai_context_review_id,
        ];
    }

    /**
     * Course port updateProposalConfirmations: move an AI-origin Intent's
     * effective target/context confirmation pointers to newly appended AI
     * decisions. Compare-and-set on the expected pointers refuses a lost update;
     * the FK proves the reviews belong to the same proposal revision. It never
     * touches a canonical Mapping or an already published snapshot.
     */
    public function updateProposalConfirmations(
        int $actorId,
        int $activityId,
        int $intentId,
        int $expectedTargetReviewId,
        ?int $expectedContextReviewId,
        int $newTargetReviewId,
        ?int $newContextReviewId,
    ): void {
        $customerId = TenantContext::customerId() ?? throw new CourseAuthoringContextException('not_found');
        $context = $this->context->proposalContext($actorId, $activityId, true);

        $intent = DB::table('core_course_template_learning_mapping_intents')->where('customer_id', $customerId)
            ->where('template_id', $context['template_id'])->where('id', $intentId)->lockForUpdate()->first();
        if ($intent === null || $intent->origin !== 'ai_proposal') {
            throw new CourseAuthoringContextException('intent_missing');
        }
        if ((int) $intent->ai_target_review_id !== $expectedTargetReviewId
            || ($intent->ai_context_review_id === null ? null : (int) $intent->ai_context_review_id) !== $expectedContextReviewId) {
            throw new CourseAuthoringContextException('confirmation_conflict');
        }

        $now = now();
        DB::table('core_course_template_learning_mapping_intents')->where('id', $intent->id)->update([
            'ai_target_review_id' => $newTargetReviewId, 'ai_context_review_id' => $newContextReviewId,
            'updated_by' => $actorId, 'updated_at' => $now,
        ]);
        DB::table('core_course_templates')->where('customer_id', $customerId)->where('id', $context['template_id'])
            ->increment('working_revision', 1, ['updated_at' => $now]);
    }
}
