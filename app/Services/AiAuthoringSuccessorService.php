<?php

namespace App\Services;

use App\Exceptions\AiAuthoringProposalException;
use App\Exceptions\CourseAuthoringContextException;
use App\Exceptions\LearningAuthoringBasisException;
use App\Exceptions\MediaReadException;
use App\Services\Ai\AuthoringPayloadValidator;
use App\Services\Ai\AuthoringProposalRecords;
use App\Support\Ai\CanonicalJson;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Step 7 human successor (contract § P1-2) with restricted decision
 * inheritance (§ Restricted decision inheritance — P1-N1).
 *
 * A successor is a new pending_review proposal created by a human through the
 * generation-request ledger in `human_successor` mode. It never executes a
 * provider: its request has no run, and the proposal carries the predecessor's
 * original Model Run only as lineage.
 *
 * Only for `source_revision_changed`, and only when every one of the six
 * conditions passes for EVERY predecessor source, the server builds an
 * allow-listed projection of the previously accepted decision — title, body,
 * kind, Node selection, role, weight — as an unapproved inherited draft.
 * Rationale, citations, excerpts and confidence are never carried. Every other
 * reason requires the actor's own payload against current sources.
 */
final class AiAuthoringSuccessorService
{
    public const REASONS = ['source_revision_changed', 'context_changed', 'target_changed', 'intent_removed', 'human_correction'];

    public function __construct(
        private readonly AuthoringProposalRecords $records,
        private readonly MediaReadService $mediaRead,
        private readonly CourseAuthoringContextService $course,
        private readonly LearningAuthoringBasisService $basis,
        private readonly LearningAuthoringTargetService $learning,
        private readonly AuthoringPayloadValidator $validator,
    ) {}

    /** Offer identity-only anchors for explicit human selection; never claim a truncated list is complete. */
    public function sourceScope(int $actorId, string $predecessorUuid): array
    {
        $predecessor = $this->records->proposal($predecessorUuid);
        if ($predecessor === null) {
            return $this->records->outcome('proposal_not_found');
        }
        try {
            $this->course->proposalContext($actorId, (int) $predecessor->activity_id);
        } catch (CourseAuthoringContextException) {
            return $this->records->outcome('proposal_not_found');
        }
        if (in_array($predecessor->status, ['deletion_pending', 'deleted'], true)) {
            return $this->records->outcome('proposal_stale');
        }
        $anchors = $this->records->successorAnchors($actorId, (int) $predecessor->activity_id);
        if ($anchors === null) {
            return $this->records->outcome('proposal_not_found');
        }

        return $this->records->outcome(null, ['anchors' => $anchors, 'available_count' => count($anchors),
            'selection_limit' => AuthoringProposalRecords::MAX_SOURCES, 'scope' => 'current_supported_nonempty_units']);
    }

    /**
     * What the successor command would inherit, if anything. Returns no old
     * content unless all inheritance conditions pass for this actor now.
     *
     * @return array<string,mixed>
     */
    public function preview(int $actorId, string $predecessorUuid): array
    {
        $predecessor = $this->records->proposal($predecessorUuid);
        if ($predecessor === null) {
            return $this->records->outcome('proposal_not_found');
        }
        try {
            $course = $this->course->proposalContext($actorId, (int) $predecessor->activity_id);
        } catch (CourseAuthoringContextException) {
            $this->records->auditDisclosure($actorId, $predecessor, $this->records->acceptedRevision($predecessor), 'authoring_successor_preview', 'unauthorized');

            return $this->records->outcome('proposal_not_found');
        }
        $sourceDenial = null;
        $projection = $this->inheritedProjection($actorId, $predecessor, $course, null, $sourceDenial);
        $this->records->auditDisclosure($actorId, $predecessor, $this->records->acceptedRevision($predecessor), 'authoring_successor_preview', is_string($projection) ? ($sourceDenial ?? $projection) : null);
        if (is_string($projection)) {
            return $this->records->outcome($projection);
        }

        return $this->records->outcome(null, ['inherited_decision_draft' => true, 'successor_reason' => 'source_revision_changed', 'payload' => $projection['payload']]);
    }

    /**
     * @param  array<string,mixed>|null  $payload  required unless reason is source_revision_changed
     * @return array<string,mixed>
     */
    public function create(int $actorId, string $predecessorUuid, string $reason, ?array $payload, string $requestUuid, ?array $selectedAnchorHashes = null): array
    {
        $requestUuid = strtolower($requestUuid);
        if (! Str::isUuid($requestUuid) || ! in_array($reason, self::REASONS, true)
            || (($reason === 'source_revision_changed') !== ($payload === null))) {
            return $this->records->outcome('invalid_proposal', ['detail' => 'reason']);
        }
        $predecessor = $this->records->proposal($predecessorUuid);
        if ($predecessor === null) {
            return $this->records->outcome('proposal_not_found');
        }
        try {
            $course = $this->course->proposalContext($actorId, (int) $predecessor->activity_id);
        } catch (CourseAuthoringContextException) {
            return $this->records->outcome('proposal_not_found');
        }
        $accepted = $this->records->acceptedRevision($predecessor);
        if ($accepted === null || in_array($predecessor->status, ['deletion_pending', 'deleted'], true)) {
            return $this->records->outcome('invalid_proposal', ['detail' => 'predecessor']);
        }

        if ($selectedAnchorHashes === null || $selectedAnchorHashes === [] || count($selectedAnchorHashes) > AuthoringProposalRecords::MAX_SOURCES
            || count(array_filter($selectedAnchorHashes, fn ($hash): bool => is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1)) !== count($selectedAnchorHashes)
            || count(array_unique($selectedAnchorHashes)) !== count($selectedAnchorHashes)) {
            return $this->records->outcome('invalid_proposal', ['detail' => 'source_scope']);
        }
        sort($selectedAnchorHashes, SORT_STRING);

        $commandHash = CanonicalJson::hash([
            'mode' => 'human_successor', 'actor_id' => $actorId, 'activity_id' => (int) $predecessor->activity_id,
            'predecessor_proposal_uuid' => $predecessor->proposal_uuid, 'predecessor_revision_id' => (int) $accepted->id,
            'successor_reason' => $reason, 'inherited_decision_draft' => $reason === 'source_revision_changed',
            'payload_hash' => $payload === null ? null : CanonicalJson::hash($payload),
            'selected_anchor_hashes' => $selectedAnchorHashes,
        ]);
        $existing = $this->records->request($requestUuid);
        if ($existing !== null) {
            return hash_equals($existing->command_hash, $commandHash) ? $this->replay($existing) : $this->records->outcome('proposal_idempotency_conflict');
        }

        $available = $this->records->successorAnchors($actorId, (int) $predecessor->activity_id);
        if ($available === null) {
            return $this->records->outcome('proposal_not_found');
        }
        if (array_diff($selectedAnchorHashes, array_keys($available)) !== []) {
            return $this->records->outcome('invalid_proposal', ['detail' => 'source_scope_changed']);
        }
        $units = array_map(fn (string $hash): array => ['anchor' => $available[$hash]], $selectedAnchorHashes);

        $basis = null;
        if (in_array($predecessor->kind, ['competency', 'node_mapping'], true)) {
            if ($course['selected_framework_id'] === null) {
                return $this->records->outcome('framework_selection_conflict');
            }
            try {
                $basis = $this->basis->proposalBasis($course['selected_framework_id'], (int) $course['selected_framework_version_id']);
            } catch (LearningAuthoringBasisException) {
                return $this->records->outcome('framework_selection_conflict');
            }
        }
        $candidates = $basis === null ? null : array_column($basis['candidates'], 'definition_id', 'node_id');

        if ($reason === 'source_revision_changed') {
            $projection = $this->inheritedProjection($actorId, $predecessor, $course, $candidates);
            if (is_string($projection)) {
                return $this->records->outcome($projection);
            }
            $payload = $projection['payload'];
        }
        try {
            $normalized = $this->validator->validate($payload, $predecessor->kind, count($units), $candidates, true);
        } catch (AiAuthoringProposalException $exception) {
            return $this->records->outcome($exception->errorCode, ['detail' => $exception->detail]);
        }

        try {
            DB::transaction(fn () => DB::table('ai_authoring_generation_requests')->insert([
                'customer_id' => $predecessor->customer_id, 'request_uuid' => $requestUuid, 'command_hash' => $commandHash,
                'mode' => 'human_successor', 'actor_id' => $actorId, 'template_id' => $course['template_id'],
                'activity_id' => $course['activity_id'], 'run_uuid' => null, 'prompt_contract_id' => null,
                'prompt_version' => null, 'prompt_hash' => null, 'status' => 'pending',
                'created_at' => $this->records->now(), 'updated_at' => $this->records->now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            $winner = $this->records->request($requestUuid);

            return $winner !== null && hash_equals($winner->command_hash, $commandHash) ? $this->replay($winner) : $this->records->outcome('proposal_idempotency_conflict');
        }

        try {
            DB::transaction(function () use ($actorId, $predecessor, $accepted, $reason, $requestUuid, $basis, $normalized, $units, $selectedAnchorHashes): void {
                // Template -> request -> predecessor; never request/predecessor first.
                $locked = $this->course->proposalContext($actorId, (int) $predecessor->activity_id, true);
                $currentAnchors = $this->records->successorAnchors($actorId, (int) $predecessor->activity_id);
                if ($currentAnchors === null || array_diff($selectedAnchorHashes, array_keys($currentAnchors)) !== []) {
                    throw new AiAuthoringProposalException('proposal_stale');
                }
                if ($basis !== null && ($locked['selected_framework_id'] !== $basis['framework_id']
                    || $locked['selected_framework_version_id'] !== $basis['framework_version_id'])) {
                    throw new AiAuthoringProposalException('framework_selection_conflict');
                }
                $request = $this->records->request($requestUuid, true);
                if ($request === null || $request->status !== 'pending') {
                    throw new AiAuthoringProposalException('proposal_idempotency_conflict');
                }
                $now = $this->records->now();
                DB::table('ai_authoring_generation_requests')->where('id', $request->id)->update(['status' => 'running', 'updated_at' => $now]);

                $lockedPredecessor = $this->records->lockProposal($predecessor);
                $stillAccepted = $this->records->acceptedRevision($lockedPredecessor);
                if (in_array($lockedPredecessor->status, ['deletion_pending', 'deleted'], true)
                    || $stillAccepted === null || (int) $stillAccepted->id !== (int) $accepted->id || $stillAccepted->payload === null) {
                    throw new AiAuthoringProposalException('invalid_proposal', 'predecessor');
                }
                if ($reason === 'source_revision_changed') {
                    $checked = $this->inheritedProjection($actorId, $lockedPredecessor, $locked);
                    if (is_string($checked) || CanonicalJson::hash($checked['payload']) !== CanonicalJson::hash($normalized)) {
                        throw new AiAuthoringProposalException(is_string($checked) ? $checked : 'proposal_stale');
                    }
                }

                $anchors = array_map(fn (array $unit): array => $unit['anchor'], $units);
                $anchorHashes = array_map(fn (array $anchor): string => CanonicalJson::hash($anchor), $anchors);
                sort($anchorHashes, SORT_STRING);
                $proposalId = DB::table('ai_authoring_proposals')->insertGetId([
                    'customer_id' => $predecessor->customer_id, 'proposal_uuid' => (string) Str::uuid(),
                    'generation_request_uuid' => $requestUuid, 'item_ordinal' => 1,
                    'template_id' => $locked['template_id'], 'activity_id' => $locked['activity_id'],
                    // Lineage only: this command executed no model.
                    'model_run_id' => $predecessor->model_run_id,
                    'creation_mode' => 'human_successor',
                    'framework_id' => $basis['framework_id'] ?? null, 'framework_version_id' => $basis['framework_version_id'] ?? null,
                    'basis_hash' => $basis['content_hash'] ?? null,
                    'kind' => $predecessor->kind,
                    'context_schema_version' => AiAuthoringProposalService::CONTEXT_SCHEMA,
                    'context_hash' => CanonicalJson::hash([
                        'context_schema_version' => AiAuthoringProposalService::CONTEXT_SCHEMA, 'course' => $locked['dto'],
                        'framework_basis' => $basis === null ? null : ['framework_id' => $basis['framework_id'], 'framework_version_id' => $basis['framework_version_id'], 'content_hash' => $basis['content_hash']],
                        'source_anchor_hashes' => $anchorHashes, 'prompt' => null,
                    ]),
                    'course_context_hash' => $locked['course_context_hash'],
                    'supersedes_proposal_id' => $predecessor->id, 'predecessor_revision_id' => $accepted->id,
                    'successor_reason' => $reason, 'inherited_decision_draft' => $reason === 'source_revision_changed',
                    'status' => 'pending_review', 'lock_version' => 1, 'created_by' => $actorId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('ai_authoring_proposal_revisions')->insert([
                    'customer_id' => $predecessor->customer_id, 'proposal_id' => $proposalId, 'revision_no' => 1,
                    'origin' => 'human_successor', 'payload_schema_version' => AuthoringPayloadValidator::SCHEMA_VERSION,
                    'payload' => CanonicalJson::encode($normalized), 'payload_hash' => CanonicalJson::hash($normalized),
                    'created_by' => $actorId, 'created_at' => $now,
                ]);
                $this->records->sealSources((object) ['id' => $proposalId, 'customer_id' => $predecessor->customer_id], $anchors, $now);
                DB::table('ai_authoring_generation_requests')->where('id', $request->id)->update([
                    'status' => 'completed', 'item_count' => 1, 'completed_at' => $now, 'updated_at' => $now,
                ]);
            }, 3);
        } catch (AiAuthoringProposalException $exception) {
            $this->failRequest($requestUuid, $exception->errorCode);

            return $this->records->outcome($exception->errorCode, ['detail' => $exception->detail]);
        } catch (CourseAuthoringContextException) {
            $this->failRequest($requestUuid, 'proposal_not_found');

            return $this->records->outcome('proposal_not_found');
        }

        return $this->replay($this->records->request($requestUuid));
    }

    /**
     * Conditions 1–6 of the restricted inheritance. Returns the projection or
     * the refusal code; on refusal nothing of the old decision is exposed.
     *
     * @param  array<string,mixed>  $course
     * @param  array<int,int>|null  $candidates
     * @return array{payload:array<string,mixed>}|string
     */
    private function inheritedProjection(int $actorId, object $predecessor, array $course, ?array $candidates = null, ?string &$sourceDenial = null): array|string
    {
        // 2. Not being erased, exact accepted revision with its payload intact.
        if (in_array($predecessor->status, ['deletion_pending', 'deleted'], true)) {
            return 'proposal_stale';
        }
        $revision = $this->records->acceptedRevision($predecessor);
        if ($revision === null || $revision->payload === null || $revision->erased_at !== null) {
            return 'proposal_stale';
        }

        // 1. Every source: same file identity and bytes, active usage, current
        //    authority of THIS actor. Only the processing version or locale may
        //    have moved. One failure refuses the whole projection.
        $sources = $this->records->sources($predecessor);
        if ($sources->isEmpty()) {
            return 'proposal_stale';
        }
        foreach ($sources->groupBy(fn (object $s): string => $s->usage_type.'|'.$s->content_type) as $group) {
            $first = $group->first();
            try {
                $revisions = $this->mediaRead->currentRevision($actorId, 'course_activity', (int) $predecessor->activity_id, $first->usage_type, $first->content_type, null);
            } catch (MediaReadException $exception) {
                $sourceDenial = $exception->errorCode;

                return 'proposal_stale';
            }
            foreach ($group as $source) {
                $sameBytes = collect($revisions)->contains(fn (array $r): bool => $r['media_file_id'] === (int) $source->media_file_id
                    && hash_equals((string) $r['source_fingerprint'], (string) $source->source_fingerprint));
                if (! $sameBytes) {
                    $sourceDenial = 'revision_mismatch';

                    return 'proposal_stale';
                }
            }
        }

        // 3. Allow-listed decision fields only; nothing auxiliary is serialized.
        $old = json_decode((string) $revision->payload, true);
        $payload = [
            'kind' => $predecessor->kind, 'title' => $old['title'], 'body' => $old['body'],
            'confidence' => null, 'rationale' => '', 'source_refs' => [],
        ];

        // 5. A propose_new whose Node already exists becomes reuse of that exact Node.
        if ($predecessor->kind === 'node_mapping') {
            $mapping = $old['mapping'];
            if ($mapping['mode'] === 'propose_new') {
                $created = DB::table('ai_authoring_proposal_applications')->where('customer_id', $predecessor->customer_id)
                    ->where('revision_id', $revision->id)->where('operation', 'create_node')->where('status', 'applied')->first();
                if ($created === null) {
                    return 'proposal_successor_node_conflict';
                }
                [$nodeId, $frameworkId, $versionId] = [(int) $created->node_id, (int) $created->framework_id, (int) $created->framework_version_id];
            } else {
                [$nodeId, $frameworkId, $versionId] = [(int) $mapping['node_id'], (int) $predecessor->framework_id, (int) $predecessor->framework_version_id];
            }
            try {
                $target = $this->learning->targetSnapshot($frameworkId, $versionId, $nodeId);
            } catch (LearningAuthoringBasisException) {
                return 'proposal_successor_node_conflict';
            }
            if ($target['node_status'] !== 'active' || ($candidates !== null && ($candidates[$nodeId] ?? null) !== $target['definition_id'])) {
                return 'proposal_successor_node_conflict';
            }
            $payload['mapping'] = [
                'mode' => 'reuse_existing', 'node_id' => $nodeId, 'definition_id' => $target['definition_id'],
                'role' => $mapping['role'], 'weight' => $mapping['weight'],
            ];
        }

        return ['payload' => $payload];
    }

    /** @return array<string,mixed> */
    private function replay(?object $request): array
    {
        if ($request === null) {
            return $this->records->outcome('proposal_not_found');
        }
        if ($request->status === 'failed') {
            return $this->records->outcome($request->error_code, ['request_status' => 'failed', 'replayed' => true]);
        }
        $proposal = DB::table('ai_authoring_proposals')->where('customer_id', $request->customer_id)
            ->where('generation_request_uuid', $request->request_uuid)->first(['proposal_uuid', 'status', 'inherited_decision_draft', 'successor_reason']);

        return $this->records->outcome(null, [
            'request_status' => $request->status,
            'proposal_uuid' => $proposal?->proposal_uuid,
            'status' => $proposal?->status,
            'inherited_decision_draft' => $proposal === null ? null : (bool) $proposal->inherited_decision_draft,
            'successor_reason' => $proposal?->successor_reason,
        ]);
    }

    private function failRequest(string $requestUuid, string $errorCode): void
    {
        DB::transaction(function () use ($requestUuid, $errorCode): void {
            $request = $this->records->request($requestUuid, true);
            if ($request !== null && $request->status === 'pending') {
                $now = $this->records->now();
                DB::table('ai_authoring_generation_requests')->where('id', $request->id)
                    ->update(['status' => 'failed', 'error_code' => $errorCode, 'completed_at' => $now, 'updated_at' => $now]);
            }
        });
    }
}
