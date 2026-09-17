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

/**
 * Step 7 inherited draft and explicit Course rebase, as admin commands
 * initiated by an accepted proposal (contract § P1-1).
 *
 * AI records the decisions (inherit_draft, rebase_target per AI Intent,
 * rebase_selection) and nothing else; Learning copies the graph, Course
 * replaces the Intents. Owner writes and AI decisions share one transaction
 * under Template -> proposals (sorted) -> Learning locks. Exact replay returns
 * the recorded result without copying or rebasing again.
 */
final class AiAuthoringRebaseService
{
    public function __construct(
        private readonly AuthoringProposalRecords $records,
        private readonly CourseAuthoringContextService $course,
        private readonly CourseAuthoringRebaseService $rebase,
        private readonly LearningAuthoringInheritanceService $inheritance,
        private readonly LearningAuthoringTargetService $learning,
    ) {}

    /** @return array<string,mixed> */
    public function inheritDraft(int $adminId, string $proposalUuid, int $baseVersionId, string $versionCode, string $title, string $expectedSourceGraphHash, string $expectedPlanHash, string $requestUuid): array
    {
        $commandHash = CanonicalJson::hash([
            'action' => 'inherit_draft', 'actor_id' => $adminId, 'proposal_uuid' => strtolower($proposalUuid),
            'base_version_id' => $baseVersionId, 'version_code' => $versionCode, 'title' => $title,
            'source_graph_hash' => $expectedSourceGraphHash, 'plan_hash' => $expectedPlanHash,
        ]);

        return $this->admin($adminId, $proposalUuid, $requestUuid, $commandHash, function (object $proposal, string $now) use ($adminId, $baseVersionId, $versionCode, $title, $expectedSourceGraphHash, $expectedPlanHash, $requestUuid, $commandHash): array {
            $created = $this->inheritance->createInheritedDraft($adminId, (int) $proposal->framework_id, $baseVersionId, $versionCode, $title, $expectedSourceGraphHash, $expectedPlanHash);
            $revision = $this->records->acceptedRevision($proposal);
            $this->records->insertReview($proposal, (int) $revision->id, $adminId, $requestUuid, $commandHash, 'inherit_draft', 'accepted', 'accepted', $now, [
                'target_snapshot' => CanonicalJson::encode(['base_version_id' => $baseVersionId] + $created['plan']),
                'target_hash' => $created['plan']['plan_hash'],
                'result_version_id' => $created['result_version_id'], 'result_framework_id' => (int) $proposal->framework_id,
            ]);
            $this->records->bump($proposal, 'accepted', $now);

            return $this->records->outcome(null, ['result_version_id' => $created['result_version_id'], 'node_map' => $created['node_map']]);
        });
    }

    /**
     * The complete plan an admin must review: every Intent with its proposed
     * replacement and hashes, plus the current Course context of each AI Intent.
     *
     * @return array<string,mixed>
     */
    public function rebasePreview(int $adminId, string $proposalUuid, int $targetVersionId): array
    {
        $proposal = $this->records->proposal($proposalUuid);
        if ($proposal === null || $proposal->framework_id === null) {
            return $this->records->outcome('proposal_not_found');
        }
        try {
            $preview = $this->rebase->preview($adminId, (int) $proposal->activity_id, (int) $proposal->framework_id, $targetVersionId);
            $preview['intents'] = $this->withContexts($adminId, $preview['intents']);
        } catch (CourseAuthoringContextException $refused) {
            return $this->records->outcome($this->code($refused->errorCode));
        }

        return $this->records->outcome(null, ['preview' => $preview, 'preview_hash' => CanonicalJson::hash($preview)]);
    }

    /**
     * @param  array<int,array{disposition:string,node_id?:int,reason?:string}>  $dispositions  keyed by Intent id
     * @return array<string,mixed>
     */
    public function rebaseSelection(int $adminId, string $proposalUuid, int $targetVersionId, array $dispositions, string $expectedPreviewHash, string $requestUuid): array
    {
        ksort($dispositions);
        $commandHash = CanonicalJson::hash([
            'action' => 'rebase_selection', 'actor_id' => $adminId, 'proposal_uuid' => strtolower($proposalUuid),
            'target_version_id' => $targetVersionId, 'dispositions' => $dispositions, 'preview_hash' => $expectedPreviewHash,
        ]);

        return $this->admin($adminId, $proposalUuid, $requestUuid, $commandHash, function (object $initiating, string $now) use ($adminId, $targetVersionId, $dispositions, $expectedPreviewHash, $requestUuid, $commandHash): array {
            $frameworkId = (int) $initiating->framework_id;
            $preview = $this->rebase->preview($adminId, (int) $initiating->activity_id, $frameworkId, $targetVersionId);
            $preview['intents'] = $this->withContexts($adminId, $preview['intents']);
            if (! hash_equals(CanonicalJson::hash($preview), $expectedPreviewHash)) {
                // Something moved since the admin reviewed the plan.
                return $this->records->outcome('proposal_revision_conflict', ['detail' => 'preview_changed']);
            }

            // Lock every affected proposal in ascending id order, after the Template.
            $proposalIds = array_values(array_unique(array_filter(array_column($preview['intents'], 'ai_proposal_id'))));
            sort($proposalIds);
            $locked = [];
            foreach ($proposalIds as $id) {
                $locked[$id] = DB::table('ai_authoring_proposals')->where('customer_id', $initiating->customer_id)->where('id', $id)->lockForUpdate()->first();
            }

            $plans = [];
            foreach ($preview['intents'] as $row) {
                $plan = $dispositions[$row['intent_id']] ?? null;
                if ($plan === null) {
                    return $this->records->outcome('invalid_proposal', ['detail' => 'rebase_plan_incomplete']);
                }
                if ($row['origin'] === 'ai_proposal' && ($plan['disposition'] ?? null) === 'map') {
                    $proposal = $locked[$row['ai_proposal_id']];
                    $revision = $this->records->acceptedRevision($proposal);
                    if ($proposal->status !== 'accepted' || $revision === null || (int) $revision->id !== $row['ai_proposal_revision_id']) {
                        return $this->records->outcome('proposal_stale', ['detail' => 'intent_'.$row['intent_id']]);
                    }
                    $course = $this->course->proposalContext($adminId, (int) $proposal->activity_id, true);
                    $sources = $this->records->sources($proposal);
                    if ($this->records->freshness($adminId, $proposal, $course, $sources)['sources'] !== 'current') {
                        return $this->records->outcome('proposal_stale', ['detail' => 'intent_'.$row['intent_id']]);
                    }
                    $new = $this->learning->targetSnapshot($frameworkId, $targetVersionId, (int) $plan['node_id']);
                    $plan['ai_review_id'] = $this->records->insertReview($proposal, (int) $revision->id, $adminId,
                        Str::uuid()->toString(), $commandHash, 'rebase_target', 'accepted', 'accepted', $now, [
                            'target_snapshot' => CanonicalJson::encode($new['snapshot'] + ['rebased_from' => [
                                'intent_id' => $row['intent_id'], 'node_id' => $row['old_node_id'], 'target_hash' => $row['old_target_hash'],
                            ]]),
                            'target_hash' => $new['target_hash'],
                            'context_snapshot' => CanonicalJson::encode($course['dto']),
                            'context_hash' => $course['course_context_hash'],
                        ]);
                }
                $plans[$row['intent_id']] = $plan;
            }

            $result = $this->rebase->rebase($adminId, (int) $initiating->activity_id, $frameworkId, $targetVersionId, $preview['working_revision'], $plans);

            $revision = $this->records->acceptedRevision($initiating);
            $this->records->insertReview($initiating, (int) $revision->id, $adminId, $requestUuid, $commandHash, 'rebase_selection', 'accepted', 'accepted', $now, [
                'target_snapshot' => CanonicalJson::encode(['preview_hash' => $expectedPreviewHash, 'dispositions' => $dispositions, 'result' => $result]),
                'target_hash' => $expectedPreviewHash,
                'result_version_id' => $targetVersionId, 'result_framework_id' => $frameworkId,
            ]);
            foreach (array_unique(array_merge([(int) $initiating->id], $proposalIds)) as $id) {
                $row = $id === (int) $initiating->id ? $initiating : $locked[$id];
                $this->records->bump($row, $row->status, $now);
            }

            return $this->records->outcome(null, ['replacements' => $result]);
        });
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function withContexts(int $adminId, array $rows): array
    {
        foreach ($rows as &$row) {
            $row['course_context_hash'] = null;
            if ($row['origin'] === 'ai_proposal') {
                $proposal = DB::table('ai_authoring_proposals')->where('customer_id', TenantContext::customerId())
                    ->where('id', $row['ai_proposal_id'])->first(['activity_id', 'course_context_hash']);
                $row['course_context_hash'] = $this->course->proposalContext($adminId, (int) $proposal->activity_id)['course_context_hash'];
                $row['accepted_course_context_hash'] = $proposal->course_context_hash;
            }
        }

        return $rows;
    }

    /**
     * Admin command frame on an accepted initiating node_mapping proposal.
     *
     * @return array<string,mixed>
     */
    private function admin(int $adminId, string $proposalUuid, string $requestUuid, string $commandHash, Closure $action): array
    {
        if (! Str::isUuid($requestUuid)) {
            return $this->records->outcome('invalid_proposal');
        }
        $proposal = $this->records->proposal($proposalUuid);
        if ($proposal === null) {
            return $this->records->outcome('proposal_not_found');
        }
        try {
            $course = $this->course->proposalContext($adminId, (int) $proposal->activity_id);
        } catch (CourseAuthoringContextException) {
            return $this->records->outcome('proposal_not_found');
        }
        if ($course['actor_role'] !== 'admin') {
            return $this->records->outcome('proposal_forbidden');
        }
        $previous = $this->records->review($proposal, $requestUuid);
        if ($previous !== null) {
            return hash_equals($previous->command_hash, $commandHash)
                ? $this->records->outcome(null, ['replayed' => true, 'result_version_id' => $previous->result_version_id === null ? null : (int) $previous->result_version_id])
                : $this->records->outcome('proposal_idempotency_conflict');
        }

        try {
            return DB::transaction(function () use ($adminId, $proposal, $action): array {
                $this->course->proposalContext($adminId, (int) $proposal->activity_id, true);
                $locked = $this->records->lockProposal($proposal);
                if ($locked->status !== 'accepted' || $locked->kind !== 'node_mapping' || $locked->framework_id === null) {
                    return $this->records->outcome('proposal_revision_conflict');
                }
                $result = $action($locked, $this->records->now());
                if ($result['error_code'] !== null) {
                    throw new AiAuthoringProposalException($result['error_code'], json_encode($result));
                }

                return $result;
            }, 3);
        } catch (AiAuthoringProposalException $declined) {
            return json_decode((string) $declined->detail, true) ?? $this->records->outcome($declined->errorCode);
        } catch (CourseAuthoringContextException $refused) {
            return $this->records->outcome($this->code($refused->errorCode));
        } catch (LearningAuthoringBasisException $refused) {
            return $this->records->outcome(match ($refused->errorCode) {
                'inheritance_empty' => 'proposal_inheritance_empty',
                'inheritance_plan_changed' => 'proposal_revision_conflict',
                default => 'framework_selection_conflict',
            }, ['detail' => $refused->errorCode]);
        } catch (UniqueConstraintViolationException) {
            return $this->records->outcome('proposal_idempotency_conflict');
        }
    }

    private function code(string $courseCode): string
    {
        return match ($courseCode) {
            'forbidden' => 'proposal_forbidden',
            'framework_selection_conflict' => 'framework_selection_conflict',
            'target_unavailable' => 'proposal_target_changed',
            'rebase_requires_successor' => 'proposal_successor_required',
            'rebase_plan_incomplete' => 'invalid_proposal',
            'confirmation_conflict' => 'proposal_revision_conflict',
            default => 'proposal_not_found',
        };
    }
}
