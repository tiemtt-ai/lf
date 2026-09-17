<?php

namespace App\Services;

use App\Exceptions\AiAuthoringProposalException;
use App\Exceptions\CourseAuthoringContextException;
use App\Exceptions\LearningAuthoringBasisException;
use App\Services\Ai\AuthoringProposalRecords;
use App\Support\Ai\CanonicalJson;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Step 7 owner-service handoff: admin Node approval, human target
 * confirmation, Intent application and human receipt cancellation
 * (contract § Human approval and publication, § P1-4).
 *
 * AI writes its receipts and decisions only. Node creation goes through
 * LearningAuthoringTargetService (Learning's own admin guard), Intents through
 * CourseAuthoringIntentService. Each owner write shares ONE transaction with
 * the AI receipt and decision, so a crash leaves either both or neither.
 *
 * Nothing here publishes, promotes a canonical Mapping or calls a provider.
 */
final class AiAuthoringApplicationService
{
    private const UUID_NAMESPACE = '2f7c3a1e-8d4b-5c6f-9a0b-1c2d3e4f5a6b';

    public function __construct(
        private readonly AuthoringProposalRecords $records,
        private readonly CourseAuthoringContextService $course,
        private readonly CourseAuthoringIntentService $intents,
        private readonly LearningAuthoringTargetService $learning,
    ) {}

    // ------------------------------------------------------------- Approve Node

    /**
     * An actual active customer_admin creates (or reuses by exact code) the
     * Node of an accepted propose_new mapping in an explicit draft Version. A
     * still-pending proposal is accepted and approved in one command, as two
     * distinct decisions.
     *
     * @return array<string,mixed>
     */
    public function approveNode(int $adminId, string $proposalUuid, int $frameworkId, int $draftVersionId, int $expectedRevisionNo, int $expectedLockVersion, string $requestUuid): array
    {
        $commandHash = CanonicalJson::hash([
            'action' => 'approve_node', 'actor_id' => $adminId, 'proposal_uuid' => strtolower($proposalUuid),
            'framework_id' => $frameworkId, 'framework_version_id' => $draftVersionId,
            'expected_revision_no' => $expectedRevisionNo, 'expected_lock_version' => $expectedLockVersion,
        ]);

        return $this->command($adminId, $proposalUuid, $requestUuid, $commandHash, ['pending_review', 'accepted'],
            function (object $proposal, array $course, string $now) use ($adminId, $frameworkId, $draftVersionId, $expectedRevisionNo, $expectedLockVersion, $requestUuid, $commandHash): array {
                if ($course['actor_role'] !== 'admin') {
                    return $this->records->outcome('proposal_forbidden');
                }
                $revision = $proposal->status === 'accepted' ? $this->records->acceptedRevision($proposal) : $this->records->currentRevision($proposal);
                $payload = $revision?->payload === null ? null : json_decode((string) $revision->payload, true);
                if ($proposal->kind !== 'node_mapping' || ($payload['mapping']['mode'] ?? null) !== 'propose_new') {
                    return $this->records->outcome('invalid_proposal', ['detail' => 'mapping.mode']);
                }
                if ((int) $proposal->framework_id !== $frameworkId) {
                    return $this->records->outcome('framework_selection_conflict');
                }
                if ((int) $proposal->lock_version !== $expectedLockVersion || (int) $revision->revision_no !== $expectedRevisionNo) {
                    return $this->records->outcome('proposal_revision_conflict');
                }
                if ($this->receipt($proposal, (int) $revision->id, 'create_node') !== null) {
                    // One creation per accepted revision, whatever the request UUID.
                    return $this->records->outcome('proposal_idempotency_conflict');
                }

                $status = $proposal->status;
                if ($status === 'pending_review') {
                    $this->records->insertReview($proposal, (int) $revision->id, $adminId, $this->derivedUuid($requestUuid, 'accept'),
                        $commandHash, 'accept', 'pending_review', 'accepted', $now);
                    $status = 'accepted';
                }
                $this->records->insertReview($proposal, (int) $revision->id, $adminId, $requestUuid, $commandHash, 'approve_node', 'accepted', 'accepted', $now);

                $applicationId = $this->insertReceipt($proposal, (int) $revision->id, 'create_node', $commandHash, $frameworkId, $draftVersionId, null, 'ready_to_apply', $now, $adminId);
                $created = $this->learning->approveProposedNode($adminId, $frameworkId, $draftVersionId, [
                    'code' => $payload['mapping']['code'], 'label' => $payload['mapping']['label'],
                    'node_type' => $payload['mapping']['node_type'], 'criteria' => $payload['mapping']['criteria'],
                ]);
                DB::table('ai_authoring_proposal_applications')->where('id', $applicationId)->update([
                    'status' => 'applied', 'node_id' => $created['node_id'], 'result_basis_hash' => $created['result_basis_hash'],
                    'applied_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('ai_authoring_proposals')->where('id', $proposal->id)->update([
                    'status' => $status, 'lock_version' => (int) $proposal->lock_version + 1, 'updated_at' => $now,
                ]);

                return $this->records->outcome(null, [
                    'status' => $status, 'node_id' => $created['node_id'], 'reused_node' => $created['reused_node'],
                    'application_uuid' => DB::table('ai_authoring_proposal_applications')->where('id', $applicationId)->value('application_uuid'),
                ]);
            });
    }

    // --------------------------------------------------------- Target confirmation

    /**
     * The current target snapshot a human must look at before confirming.
     *
     * @return array<string,mixed>
     */
    public function targetPreview(int $actorId, string $proposalUuid): array
    {
        $proposal = $this->records->proposal($proposalUuid);
        if ($proposal === null) {
            return $this->records->outcome('proposal_not_found');
        }
        try {
            $course = $this->course->proposalContext($actorId, (int) $proposal->activity_id);
        } catch (CourseAuthoringContextException) {
            $this->records->auditDisclosure($actorId, $proposal, $this->records->currentRevision($proposal), 'authoring_proposal_retrieval', 'unauthorized');

            return $this->records->outcome('proposal_not_found');
        }
        $sources = $this->records->sources($proposal);
        $revision = $this->records->acceptedRevision($proposal);
        $freshness = $this->records->freshness($actorId, $proposal, $course, $sources);
        if ($proposal->status !== 'accepted' || $revision?->payload === null
            || ! $this->records->intact($proposal, $sources, $revision) || $freshness['sources'] !== 'current') {
            $this->records->auditDisclosure($actorId, $proposal, $revision, 'authoring_proposal_retrieval', $freshness['denied'] ?? 'proposal_stale');

            return $this->records->outcome('proposal_stale');
        }
        $target = $this->target($proposal);
        if (is_string($target)) {
            $this->records->auditDisclosure($actorId, $proposal, $revision, 'authoring_proposal_retrieval', $target);

            return $this->records->outcome($target === 'awaiting_admin' ? 'invalid_proposal' : 'proposal_target_changed', ['detail' => $target]);
        }

        $this->records->auditDisclosure($actorId, $proposal, $revision, 'authoring_proposal_retrieval');

        return $this->records->outcome(null, [
            'target_snapshot' => $target['snapshot']['snapshot'], 'target_hash' => $target['snapshot']['target_hash'],
            'version_status' => $target['snapshot']['version_status'], 'node_status' => $target['snapshot']['node_status'],
        ]);
    }

    /**
     * Append confirm_target (first) or reconfirm_target (same Node identity) for
     * the exact snapshot the actor saw. Creates or re-points the unapplied
     * apply_intent receipt; never calls a provider or creates a Node.
     *
     * @return array<string,mixed>
     */
    public function confirmTarget(int $actorId, string $proposalUuid, int $expectedLockVersion, string $expectedTargetHash, string $requestUuid): array
    {
        $commandHash = CanonicalJson::hash([
            'action' => 'confirm_target', 'actor_id' => $actorId, 'proposal_uuid' => strtolower($proposalUuid),
            'expected_lock_version' => $expectedLockVersion, 'target_hash' => $expectedTargetHash,
        ]);

        return $this->command($actorId, $proposalUuid, $requestUuid, $commandHash, ['accepted'],
            function (object $proposal, array $course, string $now) use ($actorId, $expectedLockVersion, $expectedTargetHash, $requestUuid, $commandHash): array {
                if ((int) $proposal->lock_version !== $expectedLockVersion) {
                    return $this->records->outcome('proposal_revision_conflict');
                }
                $target = $this->target($proposal);
                if (is_string($target)) {
                    return $this->records->outcome($target === 'awaiting_admin' ? 'invalid_proposal' : 'proposal_target_changed', ['detail' => $target]);
                }
                $snapshot = $target['snapshot'];
                if (! hash_equals($snapshot['target_hash'], $expectedTargetHash)) {
                    return $this->records->outcome('proposal_target_changed');
                }
                $receipt = $this->receipt($proposal, (int) $target['revision']->id, 'apply_intent', true);
                if ($receipt !== null && $receipt->status === 'cancelled') {
                    return $this->records->outcome('proposal_revision_conflict', ['detail' => 'receipt_cancelled']);
                }
                if ($receipt !== null && $receipt->status === 'applied') {
                    // After apply the receipt pointer is frozen history; Course
                    // Intent owns the effective confirmation from here on.
                    return $this->repointIntent($actorId, $proposal, $target['revision'], $requestUuid, $commandHash, 'reconfirm_target', $now, $snapshot, null);
                }

                $reviewId = $this->records->insertReview($proposal, (int) $target['revision']->id, $actorId, $requestUuid, $commandHash,
                    $receipt === null ? 'confirm_target' : 'reconfirm_target', 'accepted', 'accepted', $now, [
                        'target_snapshot' => CanonicalJson::encode($snapshot['snapshot']), 'target_hash' => $snapshot['target_hash'],
                    ]);
                if ($receipt === null) {
                    $status = $snapshot['version_status'] === 'published' ? 'ready_to_apply' : 'awaiting_publication';
                    $applicationId = $this->insertReceipt($proposal, (int) $target['revision']->id, 'apply_intent', $commandHash,
                        $target['framework_id'], $target['framework_version_id'], $target['node_id'], $status, $now, $actorId, $reviewId);
                } else {
                    DB::table('ai_authoring_proposal_applications')->where('id', $receipt->id)
                        ->update(['target_review_id' => $reviewId, 'updated_at' => $now]);
                    $applicationId = (int) $receipt->id;
                }
                $this->records->bump($proposal, 'accepted', $now);

                return $this->records->outcome(null, [
                    'target_hash' => $snapshot['target_hash'],
                    'application_uuid' => DB::table('ai_authoring_proposal_applications')->where('id', $applicationId)->value('application_uuid'),
                    'application_status' => DB::table('ai_authoring_proposal_applications')->where('id', $applicationId)->value('status'),
                ]);
            });
    }

    // ------------------------------------------------------------------ Apply

    /** Retry the SAME failed receipt and target; no provider or new approval identity. */
    public function retryApplication(int $actorId, string $proposalUuid, string $applicationUuid, int $expectedLockVersion, string $requestUuid): array
    {
        $commandHash = CanonicalJson::hash([
            'action' => 'retry_application', 'actor_id' => $actorId, 'proposal_uuid' => strtolower($proposalUuid),
            'application_uuid' => strtolower($applicationUuid), 'expected_lock_version' => $expectedLockVersion,
        ]);

        return $this->command($actorId, $proposalUuid, $requestUuid, $commandHash, ['accepted'],
            function (object $proposal, array $course, string $now) use ($actorId, $applicationUuid, $expectedLockVersion, $requestUuid, $commandHash): array {
                $revision = $this->records->acceptedRevision($proposal);
                $receipt = DB::table('ai_authoring_proposal_applications')->where('customer_id', $proposal->customer_id)
                    ->where('proposal_id', $proposal->id)->where('revision_id', $revision?->id)
                    ->where('application_uuid', strtolower($applicationUuid))->lockForUpdate()->first();
                if ((int) $proposal->lock_version !== $expectedLockVersion || $revision?->payload === null
                    || $receipt === null || $receipt->status !== 'failed' || $receipt->approved_by === null) {
                    return $this->records->outcome('proposal_revision_conflict');
                }
                $freshness = $this->records->freshness($actorId, $proposal, $course, $this->records->sources($proposal));
                if ($freshness['sources'] !== 'current') {
                    return $this->records->outcome('proposal_stale');
                }
                $contextReview = $this->latestReview($proposal, (int) $revision->id, ['reconfirm_context']);
                if (! hash_equals($contextReview?->context_hash ?? $proposal->course_context_hash, $course['course_context_hash'])) {
                    return $this->records->outcome('proposal_context_changed');
                }
                $payload = json_decode((string) $revision->payload, true);
                if ($receipt->operation === 'create_node' && $course['actor_role'] !== 'admin') {
                    return $this->records->outcome('proposal_forbidden');
                }
                if ($receipt->operation === 'apply_intent') {
                    $target = $this->learning->targetSnapshot((int) $receipt->framework_id, (int) $receipt->framework_version_id, (int) $receipt->node_id);
                    $review = DB::table('ai_authoring_proposal_reviews')->where('customer_id', $proposal->customer_id)
                        ->where('proposal_id', $proposal->id)->where('revision_id', $revision->id)
                        ->where('id', $receipt->target_review_id)->whereIn('action', ['confirm_target', 'reconfirm_target'])->first();
                    if ($review === null || ! hash_equals($review->target_hash, $target['target_hash']) || $target['node_status'] !== 'active') {
                        return $this->records->outcome('proposal_target_changed');
                    }
                    if ($target['version_status'] !== 'published') {
                        return $this->records->outcome('invalid_proposal', ['detail' => 'awaiting_publication']);
                    }
                }
                DB::table('ai_authoring_proposal_applications')->where('id', $receipt->id)
                    ->update(['status' => 'ready_to_apply', 'error_code' => null, 'updated_at' => $now]);
                if ($receipt->operation === 'create_node') {
                    $created = $this->learning->approveProposedNode($actorId, (int) $receipt->framework_id, (int) $receipt->framework_version_id, [
                        'code' => $payload['mapping']['code'], 'label' => $payload['mapping']['label'],
                        'node_type' => $payload['mapping']['node_type'], 'criteria' => $payload['mapping']['criteria'],
                    ]);
                    $result = ['node_id' => $created['node_id'], 'result_basis_hash' => $created['result_basis_hash']];
                } else {
                    $intentId = $this->intents->applyReviewedIntent($actorId, (int) $proposal->activity_id,
                        (int) $receipt->framework_id, (int) $receipt->framework_version_id, (int) $receipt->node_id,
                        $payload['mapping']['role'], $payload['mapping']['weight'], (int) $proposal->id,
                        (int) $revision->id, (int) $receipt->target_review_id, $contextReview === null ? null : (int) $contextReview->id);
                    $result = ['intent_id' => $intentId];
                }
                $this->records->insertReview($proposal, (int) $revision->id, $actorId, $requestUuid, $commandHash,
                    'retry_application', 'accepted', 'accepted', $now, ['application_id' => $receipt->id]);
                DB::table('ai_authoring_proposal_applications')->where('id', $receipt->id)
                    ->update($result + ['status' => 'applied', 'applied_at' => $now, 'updated_at' => $now]);
                $this->records->bump($proposal, 'accepted', $now);

                return $this->records->outcome(null, $result + ['application_status' => 'applied']);
            });
    }

    /**
     * Resume/apply the confirmed apply_intent receipt: the Course Intent is
     * written by Course, the receipt and apply_intent decision by AI, atomically.
     * It waits for publication; it never publishes.
     *
     * @return array<string,mixed>
     */
    public function applyIntent(int $actorId, string $proposalUuid, int $expectedLockVersion, string $requestUuid): array
    {
        $commandHash = CanonicalJson::hash([
            'action' => 'apply_intent', 'actor_id' => $actorId, 'proposal_uuid' => strtolower($proposalUuid),
            'expected_lock_version' => $expectedLockVersion,
        ]);

        return $this->command($actorId, $proposalUuid, $requestUuid, $commandHash, ['accepted'],
            function (object $proposal, array $course, string $now) use ($actorId, $expectedLockVersion, $requestUuid, $commandHash): array {
                if ((int) $proposal->lock_version !== $expectedLockVersion) {
                    return $this->records->outcome('proposal_revision_conflict');
                }
                $revision = $this->records->acceptedRevision($proposal);
                $contextReview = $revision === null ? null : $this->latestReview($proposal, (int) $revision->id, ['reconfirm_context']);
                if (! hash_equals($contextReview?->context_hash ?? $proposal->course_context_hash, $course['course_context_hash'])) {
                    return $this->records->outcome('proposal_context_changed');
                }
                $receipt = $revision === null ? null : $this->receipt($proposal, (int) $revision->id, 'apply_intent', true);
                if ($receipt === null || ! in_array($receipt->status, ['awaiting_publication', 'ready_to_apply'], true)) {
                    return $this->records->outcome('proposal_revision_conflict', ['detail' => 'receipt']);
                }
                $confirmation = DB::table('ai_authoring_proposal_reviews')->where('customer_id', $proposal->customer_id)
                    ->where('id', $receipt->target_review_id)->first(['target_hash']);
                try {
                    $live = $this->learning->targetSnapshot((int) $receipt->framework_id, (int) $receipt->framework_version_id, (int) $receipt->node_id);
                } catch (LearningAuthoringBasisException) {
                    return $this->records->outcome('proposal_target_changed');
                }
                if ($confirmation === null || ! hash_equals($confirmation->target_hash, $live['target_hash']) || $live['node_status'] !== 'active') {
                    return $this->records->outcome('proposal_target_changed');
                }
                if ($live['version_status'] !== 'published') {
                    return $this->records->outcome('invalid_proposal', ['detail' => 'awaiting_publication']);
                }

                if ($receipt->status === 'awaiting_publication') {
                    DB::table('ai_authoring_proposal_applications')->where('id', $receipt->id)->update(['status' => 'ready_to_apply', 'updated_at' => $now]);
                }
                $payload = json_decode((string) $revision->payload, true);
                $intentId = $this->intents->applyReviewedIntent(
                    $actorId, (int) $proposal->activity_id, (int) $receipt->framework_id, (int) $receipt->framework_version_id,
                    (int) $receipt->node_id, $payload['mapping']['role'], $payload['mapping']['weight'],
                    (int) $proposal->id, (int) $revision->id, (int) $receipt->target_review_id, $contextReview === null ? null : (int) $contextReview->id,
                );
                $this->records->insertReview($proposal, (int) $revision->id, $actorId, $requestUuid, $commandHash, 'apply_intent', 'accepted', 'accepted', $now, [
                    'application_id' => $receipt->id,
                ]);
                DB::table('ai_authoring_proposal_applications')->where('id', $receipt->id)->update([
                    'status' => 'applied', 'intent_id' => $intentId, 'applied_at' => $now, 'updated_at' => $now,
                ]);
                $this->records->bump($proposal, 'accepted', $now);

                return $this->records->outcome(null, ['intent_id' => $intentId, 'application_status' => 'applied']);
            });
    }

    // ------------------------------------------------- Context and rejection

    /**
     * A human confirms that the accepted decision still means the same thing in
     * the changed Course context they were shown. Appends reconfirm_context;
     * an applied Intent's context pointer moves through Course. The accepted
     * payload and the sealed sources are untouched; no provider is called.
     *
     * @return array<string,mixed>
     */
    public function reconfirmContext(int $actorId, string $proposalUuid, int $expectedLockVersion, string $expectedCourseContextHash, string $requestUuid): array
    {
        $commandHash = CanonicalJson::hash([
            'action' => 'reconfirm_context', 'actor_id' => $actorId, 'proposal_uuid' => strtolower($proposalUuid),
            'expected_lock_version' => $expectedLockVersion, 'course_context_hash' => $expectedCourseContextHash,
        ]);

        return $this->command($actorId, $proposalUuid, $requestUuid, $commandHash, ['accepted'],
            function (object $proposal, array $course, string $now) use ($actorId, $expectedLockVersion, $expectedCourseContextHash, $requestUuid, $commandHash): array {
                if ((int) $proposal->lock_version !== $expectedLockVersion) {
                    return $this->records->outcome('proposal_revision_conflict');
                }
                if (! hash_equals($course['course_context_hash'], $expectedCourseContextHash)) {
                    // The actor confirmed a context that is not the current one.
                    return $this->records->outcome('proposal_context_changed');
                }
                $revision = $this->records->acceptedRevision($proposal);
                $receipt = $revision === null ? null : $this->receipt($proposal, (int) $revision->id, 'apply_intent', true);
                $context = ['context_snapshot' => CanonicalJson::encode($course['dto']), 'context_hash' => $course['course_context_hash']];
                if ($receipt !== null && $receipt->status === 'applied') {
                    return $this->repointIntent($actorId, $proposal, $revision, $requestUuid, $commandHash, 'reconfirm_context', $now, null, $context);
                }
                $this->records->insertReview($proposal, (int) $revision->id, $actorId, $requestUuid, $commandHash, 'reconfirm_context', 'accepted', 'accepted', $now, $context);
                $this->records->bump($proposal, 'accepted', $now);

                return $this->records->outcome(null, ['context_hash' => $course['course_context_hash']]);
            });
    }

    /**
     * Reject the current target of an already applied Intent. Appends
     * reject_target and points the Intent at it, which blocks future publication
     * until a fresh confirmation, successor, rebase or removal. The applied
     * receipt and any existing Mapping stay as they are. An unapplied receipt is
     * withdrawn with cancelApplication instead.
     *
     * @return array<string,mixed>
     */
    public function rejectTarget(int $actorId, string $proposalUuid, int $expectedLockVersion, string $expectedTargetHash, string $requestUuid): array
    {
        $commandHash = CanonicalJson::hash([
            'action' => 'reject_target', 'actor_id' => $actorId, 'proposal_uuid' => strtolower($proposalUuid),
            'expected_lock_version' => $expectedLockVersion, 'target_hash' => $expectedTargetHash,
        ]);

        return $this->command($actorId, $proposalUuid, $requestUuid, $commandHash, ['accepted'],
            function (object $proposal, array $course, string $now) use ($actorId, $expectedLockVersion, $expectedTargetHash, $requestUuid, $commandHash): array {
                if ((int) $proposal->lock_version !== $expectedLockVersion) {
                    return $this->records->outcome('proposal_revision_conflict');
                }
                $target = $this->target($proposal);
                if (is_string($target)) {
                    return $this->records->outcome('proposal_target_changed', ['detail' => $target]);
                }
                if (! hash_equals($target['snapshot']['target_hash'], $expectedTargetHash)) {
                    return $this->records->outcome('proposal_target_changed');
                }
                $receipt = $this->receipt($proposal, (int) $target['revision']->id, 'apply_intent', true);
                if ($receipt === null || $receipt->status !== 'applied') {
                    return $this->records->outcome('invalid_proposal', ['detail' => 'use_cancel_application']);
                }

                return $this->repointIntent($actorId, $proposal, $target['revision'], $requestUuid, $commandHash, 'reject_target', $now, $target['snapshot'], null);
            }, false);
    }

    // ----------------------------------------------------------------- Cancel

    /**
     * Human cancellation of an unapplied receipt, with actor, time and reason
     * code. Applied work is never undone here.
     *
     * @return array<string,mixed>
     */
    public function cancelApplication(int $actorId, string $applicationUuid, string $reasonCode, int $expectedLockVersion, string $requestUuid): array
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $reasonCode) !== 1 || ! Str::isUuid($applicationUuid)) {
            return $this->records->outcome('invalid_proposal', ['detail' => 'reason_code']);
        }
        $receipt = DB::table('ai_authoring_proposal_applications')->where('customer_id', TenantContext::customerId())
            ->where('application_uuid', strtolower($applicationUuid))->first();
        $proposal = $receipt === null ? null : DB::table('ai_authoring_proposals')->where('customer_id', $receipt->customer_id)
            ->where('id', $receipt->proposal_id)->first();
        if ($proposal === null) {
            return $this->records->outcome('proposal_not_found');
        }
        $commandHash = CanonicalJson::hash([
            'action' => 'cancel_application', 'actor_id' => $actorId, 'application_uuid' => strtolower($applicationUuid),
            'reason_code' => $reasonCode, 'expected_lock_version' => $expectedLockVersion,
        ]);

        return $this->command($actorId, $proposal->proposal_uuid, $requestUuid, $commandHash, ['accepted', 'stale'],
            function (object $locked, array $course, string $now) use ($actorId, $receipt, $reasonCode, $expectedLockVersion, $requestUuid, $commandHash): array {
                if ((int) $locked->lock_version !== $expectedLockVersion) {
                    return $this->records->outcome('proposal_revision_conflict');
                }
                $current = DB::table('ai_authoring_proposal_applications')->where('id', $receipt->id)->lockForUpdate()->first();
                if (in_array($current->status, ['applied', 'cancelled'], true)) {
                    return $this->records->outcome('proposal_revision_conflict', ['detail' => 'receipt_'.$current->status]);
                }
                $this->records->insertReview($locked, (int) $current->revision_id, $actorId, $requestUuid, $commandHash, 'cancel_application',
                    $locked->status, $locked->status, $now, ['application_id' => $current->id, 'reason_code' => $reasonCode]);
                DB::table('ai_authoring_proposal_applications')->where('id', $current->id)->update([
                    'status' => 'cancelled', 'cancelled_at' => $now, 'cancellation_kind' => 'human',
                    'cancelled_by' => $actorId, 'cancel_reason_code' => $reasonCode, 'error_code' => null, 'updated_at' => $now,
                ]);
                $this->records->bump($locked, $locked->status, $now);

                return $this->records->outcome(null, ['application_status' => 'cancelled']);
            }, false);
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * Shared command frame: authority, exact replay, intact records, source
     * freshness, then Template -> proposal locks and the action. Owner-port
     * refusals thrown inside roll the whole transaction back and become outcomes.
     *
     * @param  array<int,string>  $statuses
     * @param  Closure(object,array<string,mixed>,string):array<string,mixed>  $action
     * @return array<string,mixed>
     */
    private function command(int $actorId, string $proposalUuid, string $requestUuid, string $commandHash, array $statuses, Closure $action, bool $requireFreshSources = true): array
    {
        if (! Str::isUuid($requestUuid)) {
            return $this->records->outcome('invalid_proposal');
        }
        $proposal = $this->records->proposal($proposalUuid);
        if ($proposal === null) {
            return $this->records->outcome('proposal_not_found');
        }
        try {
            $course = $this->course->proposalContext($actorId, (int) $proposal->activity_id);
        } catch (CourseAuthoringContextException) {
            return $this->records->outcome('proposal_not_found');
        }

        $previous = $this->records->review($proposal, $requestUuid);
        if ($previous !== null) {
            return (int) $previous->proposal_id === (int) $proposal->id && hash_equals($previous->command_hash, $commandHash)
                ? $this->records->outcome(null, ['status' => $previous->to_status, 'replayed' => true])
                : $this->records->outcome('proposal_idempotency_conflict');
        }

        $sources = $this->records->sources($proposal);
        if (! $this->records->intact($proposal, $sources, $this->records->currentRevision($proposal))) {
            return $this->records->outcome('proposal_not_found');
        }
        if ($requireFreshSources) {
            $freshness = $this->records->freshness($actorId, $proposal, $course, $sources);
            if ($freshness['sources'] !== 'current' || ($proposal->status === 'pending_review' && ($freshness['context'] !== 'current' || $freshness['prompt'] !== 'current'))) {
                $this->records->applyStaleness($actorId, $proposal, $freshness);

                return $this->records->outcome('proposal_stale', ['detail' => $freshness['denied'] ?? 'stale']);
            }
        }

        try {
            return DB::transaction(function () use ($actorId, $proposal, $statuses, $action): array {
                $lockedCourse = $this->course->proposalContext($actorId, (int) $proposal->activity_id, true);
                $locked = $this->records->lockProposal($proposal);
                if ($locked === null || ! in_array($locked->status, $statuses, true)) {
                    return $this->records->outcome($locked?->status === 'stale' ? 'proposal_stale' : 'proposal_revision_conflict');
                }

                $result = $action($locked, $lockedCourse, $this->records->now());
                if ($result['error_code'] !== null) {
                    // Decline without writing: nothing above may have written yet.
                    throw new AiAuthoringProposalException($result['error_code'], json_encode($result));
                }

                return $result;
            }, 3);
        } catch (AiAuthoringProposalException $declined) {
            return json_decode((string) $declined->detail, true) ?? $this->records->outcome($declined->errorCode);
        } catch (CourseAuthoringContextException $refused) {
            return $this->records->outcome(match ($refused->errorCode) {
                'framework_selection_conflict' => 'framework_selection_conflict',
                'intent_conflict' => 'proposal_intent_conflict',
                'intent_missing' => 'proposal_intent_missing',
                'confirmation_conflict' => 'proposal_revision_conflict',
                'target_unavailable' => 'proposal_target_changed',
                default => 'proposal_not_found',
            });
        } catch (LearningAuthoringBasisException $refused) {
            return $this->records->outcome($refused->errorCode === 'definition_conflict' ? 'invalid_proposal' : 'framework_selection_conflict', ['detail' => $refused->errorCode]);
        } catch (UniqueConstraintViolationException) {
            $previous = $this->records->review($proposal, $requestUuid);

            return $previous !== null && hash_equals($previous->command_hash, $commandHash)
                ? $this->records->outcome(null, ['status' => $previous->to_status, 'replayed' => true])
                : $this->records->outcome('proposal_idempotency_conflict');
        }
    }

    /**
     * The Node this accepted mapping targets: its reuse Node, or the result of
     * its applied create_node receipt. A string names why there is none.
     *
     * @return array{revision:object,framework_id:int,framework_version_id:int,node_id:int,snapshot:array<string,mixed>}|string
     */
    private function target(object $proposal): array|string
    {
        $revision = $this->records->acceptedRevision($proposal);
        if ($proposal->kind !== 'node_mapping' || $revision === null || $revision->payload === null) {
            return 'not_a_mapping';
        }
        $mapping = json_decode((string) $revision->payload, true)['mapping'];
        if ($mapping['mode'] === 'reuse_existing') {
            [$frameworkId, $versionId, $nodeId] = [(int) $proposal->framework_id, (int) $proposal->framework_version_id, (int) $mapping['node_id']];
        } else {
            $created = $this->receipt($proposal, (int) $revision->id, 'create_node');
            if ($created === null || $created->status !== 'applied') {
                return 'awaiting_admin';
            }
            [$frameworkId, $versionId, $nodeId] = [(int) $created->framework_id, (int) $created->framework_version_id, (int) $created->node_id];
        }
        try {
            $snapshot = $this->learning->targetSnapshot($frameworkId, $versionId, $nodeId);
        } catch (LearningAuthoringBasisException $exception) {
            return $exception->errorCode;
        }
        if ($mapping['mode'] === 'reuse_existing' && $snapshot['definition_id'] !== (int) $mapping['definition_id']) {
            return 'definition_changed';
        }

        return ['revision' => $revision, 'framework_id' => $frameworkId, 'framework_version_id' => $versionId, 'node_id' => $nodeId, 'snapshot' => $snapshot];
    }

    /**
     * Append the AI decision and move the applied Intent's pointer through the
     * Course port, in the caller's transaction. Compare-and-set in Course refuses
     * a lost update; a missing Intent is reported, never recreated.
     *
     * @param  array<string,mixed>|null  $snapshot  Learning target snapshot for target decisions
     * @param  array<string,mixed>|null  $context  context_snapshot/context_hash for context decisions
     * @return array<string,mixed>
     */
    private function repointIntent(int $actorId, object $proposal, object $revision, string $requestUuid, string $commandHash, string $action, string $now, ?array $snapshot, ?array $context): array
    {
        $intent = $this->intents->aiIntentForRevision($actorId, (int) $proposal->activity_id, (int) $proposal->id, (int) $revision->id);
        if ($intent === null) {
            return $this->records->outcome('proposal_intent_missing');
        }
        $extra = $snapshot === null ? [] : ['target_snapshot' => CanonicalJson::encode($snapshot['snapshot']), 'target_hash' => $snapshot['target_hash']];
        $reviewId = $this->records->insertReview($proposal, (int) $revision->id, $actorId, $requestUuid, $commandHash, $action, 'accepted', 'accepted', $now, $extra + ($context ?? []));
        $this->intents->updateProposalConfirmations(
            $actorId, (int) $proposal->activity_id, $intent['intent_id'],
            $intent['ai_target_review_id'], $intent['ai_context_review_id'],
            $snapshot === null ? $intent['ai_target_review_id'] : $reviewId,
            $context === null ? $intent['ai_context_review_id'] : $reviewId,
        );
        $this->records->bump($proposal, 'accepted', $now);

        return $this->records->outcome(null, ['intent_id' => $intent['intent_id'], 'review_action' => $action]);
    }

    /** @param array<int,string> $actions */
    private function latestReview(object $proposal, int $revisionId, array $actions): ?object
    {
        return DB::table('ai_authoring_proposal_reviews')->where('customer_id', $proposal->customer_id)
            ->where('proposal_id', $proposal->id)->where('revision_id', $revisionId)
            ->whereIn('action', $actions)->orderByDesc('id')->first();
    }

    private function receipt(object $proposal, int $revisionId, string $operation, bool $lock = false): ?object
    {
        return DB::table('ai_authoring_proposal_applications')->where('customer_id', $proposal->customer_id)
            ->where('revision_id', $revisionId)->where('operation', $operation)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    /** approved_by is immutable receipt identity: the human who approved this operation, set once. */
    private function insertReceipt(object $proposal, int $revisionId, string $operation, string $commandHash, int $frameworkId, int $versionId, ?int $nodeId, string $status, string $now, int $approvedBy, ?int $targetReviewId = null): int
    {
        return (int) DB::table('ai_authoring_proposal_applications')->insertGetId([
            'customer_id' => $proposal->customer_id, 'proposal_id' => $proposal->id, 'revision_id' => $revisionId,
            'application_uuid' => (string) Str::uuid(),
            // Identity only: tenant, revision, operation, exact target; never content.
            'target_hash' => CanonicalJson::hash([
                'customer_id' => (int) $proposal->customer_id, 'revision_id' => $revisionId, 'operation' => $operation,
                'framework_id' => $frameworkId, 'framework_version_id' => $versionId, 'node_id' => $nodeId,
            ]),
            'command_hash' => $commandHash,
            'expected_basis_hash' => (string) $proposal->basis_hash,
            'target_review_id' => $targetReviewId, 'operation' => $operation, 'status' => $status,
            'framework_id' => $frameworkId, 'framework_version_id' => $versionId, 'node_id' => $operation === 'apply_intent' ? $nodeId : null,
            'approved_by' => $approvedBy,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function derivedUuid(string $requestUuid, string $suffix): string
    {
        return Uuid::uuid5(self::UUID_NAMESPACE, strtolower($requestUuid).':'.$suffix)->toString();
    }
}
