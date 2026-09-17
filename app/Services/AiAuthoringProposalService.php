<?php

namespace App\Services;

use App\Contracts\Ai\AuthoringProposalProvider;
use App\Exceptions\AiAuthoringProposalException;
use App\Exceptions\AiProviderGateException;
use App\Exceptions\CourseAuthoringContextException;
use App\Exceptions\LearningAuthoringBasisException;
use App\Services\Ai\AuthoringPayloadValidator;
use App\Services\Ai\AuthoringProposalAdapter;
use App\Services\Ai\AuthoringProposalRecords;
use App\Support\Ai\AuthoringPromptContract;
use App\Support\Ai\AuthoringProposalInput;
use App\Support\Ai\CanonicalJson;
use App\Support\Ai\ProviderGateRequest;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Step 7 AI Authoring Proposal — generation, read and review
 * (platform/LF-AI-Authoring-Proposal-Contract.md v0.8).
 *
 * AI owns requests, proposals, revisions, sources and reviews and writes only
 * `ai_authoring_*`. Course authority and context come from
 * CourseAuthoringContextService, Framework basis from
 * LearningAuthoringBasisService, source authorization and revisions from
 * MediaReadService. No Course, Learning or Media table is read or written here.
 *
 * Expected refusals are returned as outcomes carrying an application-local
 * code, never thrown, so a caller can tell blocked, conflicting and stale apart.
 * Lock order is the contract's: Course Template -> generation request ->
 * proposal. No provider call happens inside a transaction.
 */
final class AiAuthoringProposalService
{
    public const CONTEXT_SCHEMA = 'authoring-context-v1';

    public function __construct(
        private readonly AiProviderExecutionGate $gate,
        private readonly AuthoringProposalProvider $provider,
        private readonly MediaReadService $mediaRead,
        private readonly CourseAuthoringContextService $course,
        private readonly LearningAuthoringBasisService $learning,
        private readonly AuthoringPayloadValidator $validator,
        private readonly AuthoringPromptContract $prompt,
        private readonly AuthoringProposalRecords $records,
    ) {}

    // ------------------------------------------------------------------ Generate

    /**
     * @param  array<int,mixed>  $requestedKinds
     * @return array<string,mixed>
     */
    public function generate(int $actorId, int $activityId, array $requestedKinds, ?int $frameworkId, ?int $frameworkVersionId, string $requestUuid): array
    {
        if (TenantContext::customerId() === null) {
            return $this->records->outcome('proposal_not_found');
        }
        $requestUuid = strtolower($requestUuid);
        $kinds = $this->kinds($requestedKinds);
        if (! Str::isUuid($requestUuid) || $kinds === null || ($frameworkId === null) !== ($frameworkVersionId === null)) {
            return $this->records->outcome('invalid_proposal');
        }
        $needsBasis = array_intersect($kinds, ['competency', 'node_mapping']) !== [];
        if ($needsBasis && $frameworkId === null) {
            return $this->records->outcome('invalid_proposal', ['detail' => 'framework_basis']);
        }

        try {
            $course = $this->course->proposalContext($actorId, $activityId);
        } catch (CourseAuthoringContextException) {
            return $this->records->outcome('proposal_not_found');
        }
        // A basis must be the Template's own selection; nothing else can later
        // become a Course Intent without a rebase.
        if ($frameworkId !== null && ($course['selected_framework_id'] !== $frameworkId || $course['selected_framework_version_id'] !== $frameworkVersionId)) {
            return $this->records->outcome('framework_selection_conflict');
        }

        $commandHash = CanonicalJson::hash([
            'mode' => 'generated', 'actor_id' => $actorId, 'activity_id' => $activityId,
            'requested_kinds' => $kinds, 'framework_id' => $frameworkId, 'framework_version_id' => $frameworkVersionId,
        ]);

        $existing = $this->records->request($requestUuid);
        if ($existing !== null) {
            if (! hash_equals($existing->command_hash, $commandHash)) {
                return $this->records->outcome('proposal_idempotency_conflict');
            }
            if ($existing->status !== 'pending') {
                return $this->replay($existing);
            }
        }

        $providerName = (string) config('ai.authoring.provider', '');
        $model = (string) config('ai.authoring.model', '');
        if ($providerName === '' || $model === '') {
            // Stop before Media Read, the ledger and the gate: an unconfigured
            // deployment neither reads sources nor mints requests.
            return $existing !== null ? $this->replay($existing) : $this->records->outcome('AI_APPROVAL_REQUIRED', ['blocked_at' => 'configuration']);
        }

        $basis = null;
        if ($frameworkId !== null) {
            try {
                $basis = $this->learning->proposalBasis($frameworkId, (int) $frameworkVersionId);
            } catch (LearningAuthoringBasisException) {
                return $this->records->outcome('framework_selection_conflict');
            }
        }

        $units = $this->records->collectSources($actorId, $activityId, 'authoring_proposal_generation');
        if ($units === null) {
            return $this->records->outcome('proposal_not_found');
        }
        if ($units === []) {
            return $this->records->outcome('invalid_proposal', ['detail' => 'sources']);
        }

        if ($existing === null) {
            try {
                $this->insertRequest($requestUuid, $commandHash, 'generated', $actorId, $course);
            } catch (UniqueConstraintViolationException) {
                $winner = $this->records->request($requestUuid);
                if ($winner === null || ! hash_equals($winner->command_hash, $commandHash)) {
                    return $this->records->outcome('proposal_idempotency_conflict');
                }
                if ($winner->status !== 'pending') {
                    return $this->replay($winner);
                }
            }
        }

        // Claim under Template -> request locks. Only one caller moves the
        // request out of pending; everyone else replays its state.
        try {
            $claimed = DB::transaction(function () use ($actorId, $activityId, $requestUuid): ?object {
                $this->course->proposalContext($actorId, $activityId, true);
                $row = $this->records->request($requestUuid, true);
                if ($row === null || $row->status !== 'pending') {
                    return null;
                }
                DB::table('ai_authoring_generation_requests')->where('id', $row->id)->where('status', 'pending')
                    ->update(['status' => 'running', 'updated_at' => $this->records->now()]);

                return $this->records->request($requestUuid);
            });
        } catch (CourseAuthoringContextException) {
            return $this->records->outcome('proposal_not_found');
        }
        if ($claimed === null) {
            return $this->replay($this->records->request($requestUuid));
        }

        return $this->execute($claimed, $actorId, $course, $kinds, $basis, $units, $providerName, $model);
    }

    /**
     * @param  array<string,mixed>  $course
     * @param  array<int,string>  $kinds
     * @param  array<string,mixed>|null  $basis
     * @param  array<int,array<string,mixed>>  $units
     * @return array<string,mixed>
     */
    private function execute(object $request, int $actorId, array $course, array $kinds, ?array $basis, array $units, string $providerName, string $model): array
    {
        $gateRequest = new ProviderGateRequest(
            provider: $providerName,
            model: $model,
            purpose: 'authoring_proposal',
            dataClasses: (array) config('ai.authoring.data_classes', []),
            executionRegion: (string) config('ai.authoring.execution_region'),
            retentionClass: (string) config('ai.authoring.retention_class'),
            correlationId: $request->request_uuid,
            userId: $actorId,
            promptVersion: (int) $request->prompt_version,
            promptHash: 'sha256:'.$request->prompt_hash,
            runUuid: $request->run_uuid,
            quotaQuantity: 1.0,
            quotaUnit: 'call',
            usageType: 'provider_call',
        );

        $decision = $this->gate->authorize($gateRequest);
        if (! $decision->allowed) {
            $this->failRequest($request, (string) $decision->errorCode, $decision->modelRunId);

            return $this->records->outcome($decision->errorCode, ['blocked_at' => $decision->blockedStep, 'request_status' => 'failed']);
        }

        $candidates = $basis === null ? null : array_column($basis['candidates'], 'definition_id', 'node_id');
        $adapter = new AuthoringProposalAdapter(
            $this->provider,
            new AuthoringProposalInput(
                $this->prompt->id(), $this->prompt->version(), $this->prompt->template(), $kinds, $course['dto'],
                $basis === null ? null : ['framework_id' => $basis['framework_id'], 'framework_version_id' => $basis['framework_version_id'], 'candidates' => $basis['candidates']],
                array_map(fn (array $unit): array => [
                    'ordinal' => $unit['ordinal'], 'usage_type' => $unit['anchor']['usage_type'],
                    'content_type' => $unit['anchor']['content_type'], 'locale' => $unit['anchor']['locale'], 'text' => $unit['text'],
                ], $units),
            ),
            $this->validator,
            $candidates,
        );

        $execution = $decision->execution;
        try {
            $executed = $this->gate->execute($gateRequest, static fn (): AuthoringProposalAdapter => $adapter, $execution);
        } catch (AiProviderGateException $exception) {
            $this->failRequest($request, $exception->errorCode, $execution->modelRunId);

            return $this->records->outcome($exception->errorCode, ['request_status' => 'failed']);
        }
        // A second-authorization refusal is RETURNED, not thrown.
        if (! $executed->allowed) {
            $this->failRequest($request, (string) $executed->errorCode, $execution->modelRunId);

            return $this->records->outcome($executed->errorCode, ['blocked_at' => $executed->blockedStep, 'request_status' => 'failed']);
        }

        $items = $adapter->items();
        if ($items === null) {
            $this->failRequest($request, 'proposal_generation_output_unavailable', $execution->modelRunId);

            return $this->records->outcome('proposal_generation_output_unavailable', ['request_status' => 'failed']);
        }

        try {
            $this->complete($request, $actorId, $execution->modelRunId, $course, $basis, $units, $items);
        } catch (Throwable $exception) {
            // The run completed and usage is recorded; the validated output lives
            // only in this process and cannot be recovered later. Never re-execute.
            // QueryException includes SQL/bindings and may contain the entire
            // generated payload. Logs are outside the source-erasure lifecycle:
            // never pass the exception, message or trace to the logger.
            $sqlState = $exception instanceof QueryException ? ($exception->errorInfo[0] ?? null) : null;
            Log::error('ai_authoring_generation_persistence_failed', [
                'exception_class' => get_class($exception),
                'request_uuid' => $request->request_uuid,
                'sqlstate' => is_string($sqlState) && preg_match('/\A[A-Z0-9]{5}\z/', $sqlState) === 1 ? $sqlState : null,
            ]);
            $this->failRequest($request, 'proposal_generation_output_unavailable', $execution->modelRunId);

            return $this->records->outcome('proposal_generation_output_unavailable', ['request_status' => 'failed']);
        }

        return $this->replay($this->records->request($request->request_uuid));
    }

    /**
     * Items, revisions, sources, seals and request completion in ONE
     * transaction; the request trigger verifies the count and every seal.
     *
     * @param  array<string,mixed>  $course
     * @param  array<string,mixed>|null  $basis
     * @param  array<int,array<string,mixed>>  $units
     * @param  array<int,array<string,mixed>>  $items
     */
    private function complete(object $request, int $actorId, int $modelRunId, array $course, ?array $basis, array $units, array $items): void
    {
        $byOrdinal = [];
        foreach ($units as $unit) {
            $byOrdinal[$unit['ordinal']] = $unit;
        }

        DB::transaction(function () use ($request, $actorId, $modelRunId, $course, $basis, $byOrdinal, $items): void {
            $this->course->proposalContext($actorId, (int) $request->activity_id, true);
            $locked = $this->records->request($request->request_uuid, true);
            if ($locked === null || $locked->status !== 'running') {
                throw new AiAuthoringProposalException('proposal_generation_output_unavailable');
            }
            $customerId = (int) $locked->customer_id;
            $now = $this->records->now();

            foreach (array_values($items) as $index => $item) {
                $refs = $item['source_refs'];
                $anchors = array_map(fn (int $ref): array => $byOrdinal[$ref]['anchor'], $refs);
                $anchorHashes = array_map(fn (array $anchor): string => CanonicalJson::hash($anchor), $anchors);
                // Proposal-local ordinals: 1..n in generation order.
                $payload = $item;
                $payload['source_refs'] = range(1, count($refs));

                $proposalId = DB::table('ai_authoring_proposals')->insertGetId([
                    'customer_id' => $customerId,
                    'proposal_uuid' => (string) Str::uuid(),
                    'generation_request_uuid' => $locked->request_uuid,
                    'item_ordinal' => $index + 1,
                    'template_id' => $course['template_id'],
                    'activity_id' => $course['activity_id'],
                    'model_run_id' => $modelRunId,
                    'creation_mode' => 'generated',
                    'framework_id' => $basis['framework_id'] ?? null,
                    'framework_version_id' => $basis['framework_version_id'] ?? null,
                    'basis_hash' => $basis['content_hash'] ?? null,
                    'kind' => $item['kind'],
                    'context_schema_version' => self::CONTEXT_SCHEMA,
                    'context_hash' => $this->contextHash($course, $basis, $anchorHashes),
                    'course_context_hash' => $course['course_context_hash'],
                    'successor_reason' => null,
                    'inherited_decision_draft' => false,
                    'status' => 'pending_review',
                    'lock_version' => 1,
                    'created_by' => $actorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('ai_authoring_proposal_revisions')->insert([
                    'customer_id' => $customerId, 'proposal_id' => $proposalId, 'revision_no' => 1,
                    'origin' => 'generated', 'payload_schema_version' => AuthoringPayloadValidator::SCHEMA_VERSION,
                    'payload' => CanonicalJson::encode($payload), 'payload_hash' => CanonicalJson::hash($payload),
                    'created_by' => $actorId, 'created_at' => $now,
                ]);
                $this->records->sealSources((object) ['id' => $proposalId, 'customer_id' => $customerId], $anchors, $now);
            }

            DB::table('ai_authoring_generation_requests')->where('id', $locked->id)->update([
                'status' => 'completed', 'item_count' => count($items), 'model_run_id' => $modelRunId,
                'completed_at' => $now, 'updated_at' => $now,
            ]);
        }, 3);
    }

    // ---------------------------------------------------------------------- Read

    /** @return array<string,mixed> */
    public function show(int $actorId, string $proposalUuid): array
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
        $revision = $this->records->currentRevision($proposal);
        if (! $this->records->intact($proposal, $sources, $revision)) {
            $this->records->auditDisclosure($actorId, $proposal, $revision, 'authoring_proposal_retrieval', 'proposal_not_found');

            return $this->records->outcome('proposal_not_found');
        }

        $freshness = $this->records->freshness($actorId, $proposal, $course, $sources);
        $proposal = $this->records->applyStaleness($actorId, $proposal, $freshness) ?? $proposal;

        $visible = in_array($proposal->status, ['pending_review', 'accepted', 'rejected'], true)
            && $freshness['sources'] === 'current' && $revision->payload !== null;

        $this->records->auditDisclosure($actorId, $proposal, $revision, 'authoring_proposal_retrieval',
            $visible ? null : ($freshness['denied'] ?? ($freshness['sources'] === 'drift' ? 'revision_mismatch' : $proposal->status)));

        return $this->records->outcome(null, [
            'proposal_uuid' => $proposal->proposal_uuid,
            'kind' => $proposal->kind,
            'status' => $proposal->status,
            'creation_mode' => $proposal->creation_mode,
            'lock_version' => (int) $proposal->lock_version,
            'revision_no' => (int) $revision->revision_no,
            'payload' => $visible ? json_decode((string) $revision->payload, true) : null,
            'content_denied' => $visible ? null : ($freshness['denied'] ?? $proposal->status),
            'context_changed' => $freshness['context'] === 'changed',
            'citations' => ! $visible ? [] : $sources->map(fn (object $source): array => [
                'ordinal' => (int) $source->source_ordinal, 'media_file_id' => (int) $source->media_file_id,
                'usage_type' => $source->usage_type, 'content_type' => $source->content_type,
                'locale' => $source->locale, 'locator' => json_decode((string) $source->locator, true),
            ])->values()->all(),
            'reviews' => DB::table('ai_authoring_proposal_reviews as r')
                ->join('ai_authoring_proposal_revisions as v', function ($join): void {
                    $join->on('v.id', '=', 'r.revision_id')->on('v.customer_id', '=', 'r.customer_id');
                })
                ->where('r.customer_id', $proposal->customer_id)->where('r.proposal_id', $proposal->id)
                ->orderBy('r.id')->limit(100)
                ->get(['r.action', 'r.actor_id', 'v.revision_no', 'r.from_status', 'r.to_status', 'r.created_at'])
                ->map(fn (object $row): array => (array) $row + [])->all(),
            'allowed_actions' => $visible && $proposal->status === 'pending_review' ? ['edit', 'accept', 'reject'] : [],
        ]);
    }

    // -------------------------------------------------------------------- Review

    /**
     * Append a human revision to a pending proposal.
     *
     * @return array<string,mixed>
     */
    public function edit(int $actorId, string $proposalUuid, int $expectedRevisionNo, int $expectedLockVersion, mixed $payload, int $payloadSchemaVersion, string $requestUuid): array
    {
        if ($payloadSchemaVersion !== AuthoringPayloadValidator::SCHEMA_VERSION) {
            return $this->records->outcome('invalid_proposal', ['detail' => 'payload_schema_version']);
        }
        $commandHash = CanonicalJson::hash([
            'action' => 'edit', 'actor_id' => $actorId, 'proposal_uuid' => strtolower($proposalUuid),
            'expected_revision_no' => $expectedRevisionNo, 'expected_lock_version' => $expectedLockVersion,
            'payload_hash' => CanonicalJson::hash($payload),
        ]);

        return $this->mutate($actorId, $proposalUuid, $requestUuid, $commandHash, $expectedRevisionNo, $expectedLockVersion,
            function (object $proposal, object $revision, int $sourceCount, string $now) use ($actorId, $payload, $requestUuid, $commandHash): array {
                try {
                    $normalized = $this->validator->validate($payload, $proposal->kind, $sourceCount, $this->candidatesFor($proposal), $proposal->creation_mode === 'human_successor');
                } catch (AiAuthoringProposalException $exception) {
                    return $this->records->outcome($exception->errorCode, ['detail' => $exception->detail]);
                } catch (LearningAuthoringBasisException) {
                    return $this->records->outcome('framework_selection_conflict');
                }
                $revisionId = DB::table('ai_authoring_proposal_revisions')->insertGetId([
                    'customer_id' => $proposal->customer_id, 'proposal_id' => $proposal->id,
                    'revision_no' => (int) $revision->revision_no + 1, 'origin' => 'human',
                    'payload_schema_version' => AuthoringPayloadValidator::SCHEMA_VERSION,
                    'payload' => CanonicalJson::encode($normalized), 'payload_hash' => CanonicalJson::hash($normalized),
                    'created_by' => $actorId, 'created_at' => $now,
                ]);
                $this->records->insertReview($proposal, $revisionId, $actorId, $requestUuid, $commandHash, 'edit', 'pending_review', 'pending_review', $now);
                $this->records->bump($proposal, 'pending_review', $now);

                return $this->records->outcome(null, ['status' => 'pending_review', 'revision_no' => (int) $revision->revision_no + 1, 'lock_version' => (int) $proposal->lock_version + 1]);
            });
    }

    /**
     * Accept or reject the exact current revision. Accept never publishes and
     * creates no receipt or Course/Learning state.
     *
     * @return array<string,mixed>
     */
    public function decide(int $actorId, string $proposalUuid, string $action, int $expectedRevisionNo, int $expectedLockVersion, string $requestUuid, ?string $reason = null): array
    {
        if (! in_array($action, ['accept', 'reject'], true) || ($reason !== null && mb_strlen($reason) > 2000)) {
            return $this->records->outcome('invalid_proposal', ['detail' => 'action']);
        }
        $commandHash = CanonicalJson::hash([
            'action' => $action, 'actor_id' => $actorId, 'proposal_uuid' => strtolower($proposalUuid),
            'expected_revision_no' => $expectedRevisionNo, 'expected_lock_version' => $expectedLockVersion,
            'reason_hash' => $reason === null ? null : hash('sha256', $reason),
        ]);
        $to = $action === 'accept' ? 'accepted' : 'rejected';

        return $this->mutate($actorId, $proposalUuid, $requestUuid, $commandHash, $expectedRevisionNo, $expectedLockVersion,
            function (object $proposal, object $revision, int $sourceCount, string $now) use ($actorId, $action, $to, $reason, $requestUuid, $commandHash): array {
                if ($action === 'accept' && (json_decode((string) $revision->payload, true)['source_refs'] ?? []) === []) {
                    // A successor draft starts without citations; it cannot be accepted until
                    // it cites its own current sources.
                    return $this->records->outcome('invalid_proposal', ['detail' => 'source_refs']);
                }
                if ($action === 'accept' && $proposal->framework_id !== null) {
                    try {
                        $this->candidatesFor($proposal);
                    } catch (LearningAuthoringBasisException) {
                        return $this->records->outcome('framework_selection_conflict');
                    }
                }
                $this->records->insertReview($proposal, (int) $revision->id, $actorId, $requestUuid, $commandHash, $action, 'pending_review', $to, $now, ['reason' => $reason]);
                $this->records->bump($proposal, $to, $now);

                return $this->records->outcome(null, ['status' => $to, 'revision_no' => (int) $revision->revision_no, 'lock_version' => (int) $proposal->lock_version + 1]);
            });
    }

    /**
     * Shared review transaction: replay, authority, freshness, Template ->
     * proposal locks, optimistic versions, then the action.
     *
     * @param  Closure(object,object,int,string):array<string,mixed>  $action
     * @return array<string,mixed>
     */
    private function mutate(int $actorId, string $proposalUuid, string $requestUuid, string $commandHash, int $expectedRevisionNo, int $expectedLockVersion, Closure $action): array
    {
        $requestUuid = strtolower($requestUuid);
        if (! Str::isUuid($requestUuid) || $expectedRevisionNo < 1 || $expectedLockVersion < 1) {
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

        $replay = $this->replayReview($proposal, $requestUuid, $commandHash);
        if ($replay !== null) {
            return $replay;
        }

        $sources = $this->records->sources($proposal);
        if (! $this->records->intact($proposal, $sources, $this->records->currentRevision($proposal))) {
            return $this->records->outcome('proposal_not_found');
        }
        // Media Read runs before the locks; the decision below re-reads state
        // under them and only acts on a proposal still in the checked state.
        $freshness = $this->records->freshness($actorId, $proposal, $course, $sources);
        if ($freshness['sources'] !== 'current' || $freshness['context'] !== 'current' || $freshness['prompt'] !== 'current') {
            $this->records->applyStaleness($actorId, $proposal, $freshness);

            return $this->records->outcome('proposal_stale', ['detail' => $freshness['denied'] ?? 'stale']);
        }

        try {
            return DB::transaction(function () use ($actorId, $proposal, $expectedRevisionNo, $expectedLockVersion, $action): array {
                $lockedCourse = $this->course->proposalContext($actorId, (int) $proposal->activity_id, true);
                $locked = DB::table('ai_authoring_proposals')->where('customer_id', $proposal->customer_id)
                    ->where('id', $proposal->id)->lockForUpdate()->first();
                $revision = $this->records->currentRevision($locked);
                if ($locked->status !== 'pending_review') {
                    return $this->records->outcome($locked->status === 'stale' ? 'proposal_stale' : 'proposal_revision_conflict');
                }
                if ((int) $locked->lock_version !== $expectedLockVersion || $revision === null || (int) $revision->revision_no !== $expectedRevisionNo) {
                    return $this->records->outcome('proposal_revision_conflict');
                }
                if (! hash_equals($locked->course_context_hash, $lockedCourse['course_context_hash'])) {
                    $this->records->bump($locked, 'stale', $this->records->now());

                    return $this->records->outcome('proposal_stale', ['detail' => 'context']);
                }

                return $action($locked, $revision, (int) $locked->source_count, $this->records->now());
            }, 3);
        } catch (CourseAuthoringContextException) {
            return $this->records->outcome('proposal_not_found');
        } catch (UniqueConstraintViolationException) {
            // A concurrent command with the same request UUID committed first.
            return $this->replayReview($proposal, $requestUuid, $commandHash) ?? $this->records->outcome('proposal_idempotency_conflict');
        }
    }

    /** @return array<string,mixed>|null */
    private function replayReview(object $proposal, string $requestUuid, string $commandHash): ?array
    {
        $review = DB::table('ai_authoring_proposal_reviews as r')
            ->join('ai_authoring_proposal_revisions as v', function ($join): void {
                $join->on('v.id', '=', 'r.revision_id')->on('v.customer_id', '=', 'r.customer_id');
            })
            ->where('r.customer_id', $proposal->customer_id)->where('r.request_uuid', $requestUuid)
            ->first(['r.proposal_id', 'r.command_hash', 'r.to_status', 'v.revision_no']);
        if ($review === null) {
            return null;
        }
        if ((int) $review->proposal_id !== (int) $proposal->id || ! hash_equals($review->command_hash, $commandHash)) {
            return $this->records->outcome('proposal_idempotency_conflict');
        }

        return $this->records->outcome(null, ['status' => $review->to_status, 'revision_no' => (int) $review->revision_no, 'replayed' => true]);
    }

    // ---------------------------------------------------------------- Freshness

    // ------------------------------------------------------------------ Helpers

    /**
     * @param  array<string,mixed>  $course
     * @param  array<string,mixed>|null  $basis
     * @param  array<int,string>  $anchorHashes
     */
    private function contextHash(array $course, ?array $basis, array $anchorHashes): string
    {
        sort($anchorHashes, SORT_STRING);

        return CanonicalJson::hash([
            'context_schema_version' => self::CONTEXT_SCHEMA,
            'course' => $course['dto'],
            'framework_basis' => $basis === null ? null : [
                'framework_id' => $basis['framework_id'],
                'framework_version_id' => $basis['framework_version_id'],
                'content_hash' => $basis['content_hash'],
            ],
            'source_anchor_hashes' => $anchorHashes,
            'prompt' => ['id' => $this->prompt->id(), 'version' => $this->prompt->version(), 'hash' => $this->prompt->hash()],
        ]);
    }

    /** @return array<int,int>|null node_id => definition_id of the proposal's own basis */
    private function candidatesFor(object $proposal): ?array
    {
        if ($proposal->framework_id === null) {
            return null;
        }
        $basis = $this->learning->proposalBasis((int) $proposal->framework_id, (int) $proposal->framework_version_id);

        return array_column($basis['candidates'], 'definition_id', 'node_id');
    }

    /** @param array<string,mixed> $course */
    private function insertRequest(string $requestUuid, string $commandHash, string $mode, int $actorId, array $course): void
    {
        $now = $this->records->now();
        DB::transaction(fn () => DB::table('ai_authoring_generation_requests')->insert([
            'customer_id' => TenantContext::customerId(),
            'request_uuid' => $requestUuid,
            'command_hash' => $commandHash,
            'mode' => $mode,
            'actor_id' => $actorId,
            'template_id' => $course['template_id'],
            'activity_id' => $course['activity_id'],
            'run_uuid' => $mode === 'generated' ? (string) Str::uuid() : null,
            'prompt_contract_id' => $mode === 'generated' ? $this->prompt->id() : null,
            'prompt_version' => $mode === 'generated' ? $this->prompt->version() : null,
            'prompt_hash' => $mode === 'generated' ? $this->prompt->hash() : null,
            'status' => 'pending',
            'item_count' => null,
            'error_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'completed_at' => null,
        ]));
    }

    private function failRequest(object $request, string $errorCode, int $modelRunId): void
    {
        DB::transaction(function () use ($request, $errorCode, $modelRunId): void {
            $locked = $this->records->request($request->request_uuid, true);
            if ($locked === null || ! in_array($locked->status, ['pending', 'running'], true)) {
                return;
            }
            $now = $this->records->now();
            $update = ['status' => 'failed', 'error_code' => mb_substr($errorCode, 0, 100), 'completed_at' => $now, 'updated_at' => $now];
            // Bind only the run this request actually minted.
            if ($locked->model_run_id === null && $modelRunId > 0 && $locked->run_uuid !== null && DB::table('ai_model_runs')
                ->where('customer_id', $locked->customer_id)->where('id', $modelRunId)->where('run_uuid', $locked->run_uuid)->exists()) {
                $update['model_run_id'] = $modelRunId;
            }
            DB::table('ai_authoring_generation_requests')->where('id', $locked->id)->update($update);
        });
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
        $proposals = $request->status !== 'completed' ? [] : DB::table('ai_authoring_proposals')
            ->where('customer_id', $request->customer_id)->where('generation_request_uuid', $request->request_uuid)
            ->orderBy('item_ordinal')->get(['id', 'proposal_uuid', 'kind', 'status'])
            ->map(fn (object $p): array => [
                'proposal_uuid' => $p->proposal_uuid, 'kind' => $p->kind, 'status' => $p->status,
                'revision_no' => (int) DB::table('ai_authoring_proposal_revisions')->where('customer_id', $request->customer_id)
                    ->where('proposal_id', $p->id)->max('revision_no'),
            ])->all();

        return $this->records->outcome(null, ['request_status' => $request->status, 'item_count' => $request->item_count === null ? null : (int) $request->item_count, 'proposals' => $proposals]);
    }

    /** @param array<int,mixed> $kinds @return array<int,string>|null */
    private function kinds(array $kinds): ?array
    {
        if ($kinds === [] || ! array_is_list($kinds)) {
            return null;
        }
        foreach ($kinds as $kind) {
            if (! is_string($kind) || ! in_array($kind, AuthoringPayloadValidator::KINDS, true)) {
                return null;
            }
        }
        $kinds = array_values(array_unique($kinds));
        sort($kinds, SORT_STRING);

        return $kinds;
    }
}
