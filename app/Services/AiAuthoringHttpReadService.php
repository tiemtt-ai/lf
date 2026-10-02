<?php

namespace App\Services;

use App\Exceptions\AiAuthoringProposalException;
use App\Exceptions\CourseAuthoringContextException;
use App\Exceptions\LearningAuthoringBasisException;
use App\Services\Ai\AuthoringAllowedActions;
use App\Services\Ai\AuthoringProposalRecords;
use App\Support\Ai\CanonicalJson;
use App\Support\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** AI-owned read models. Course/Learning authority is obtained only through ports. */
final class AiAuthoringHttpReadService
{
    public function __construct(
        private readonly AuthoringProposalRecords $records,
        private readonly CourseAuthoringContextService $course,
        private readonly AiAuthoringProposalService $proposals,
        private readonly AiAuthoringSuccessorService $successors,
        private readonly LearningAuthoringInheritanceService $inheritance,
        private readonly LearningAuthoringBasisService $learning,
        private readonly CourseAuthoringRebaseService $rebase,
    ) {}

    public function scope(int $actor, int $template, int $activity, ?string $uuid = null, ?string $application = null, ?string $auditOperation = null): array
    {
        try {
            $context = $this->course->proposalContext($actor, $activity);
        } catch (CourseAuthoringContextException $denied) {
            $candidate = $uuid === null ? null : $this->records->proposal($uuid);
            if ($auditOperation !== null && $candidate !== null && (int) $candidate->activity_id === $activity && (int) $candidate->template_id === $template) {
                $this->records->auditDisclosure($actor, $candidate, $this->records->currentRevision($candidate), $auditOperation, 'unauthorized');
            }
            throw $denied;
        }
        if ($context['template_id'] !== $template) {
            throw new AiAuthoringProposalException('proposal_not_found');
        }
        $proposal = $uuid === null ? null : $this->records->proposal($uuid);
        if ($uuid !== null && ($proposal === null || (int) $proposal->activity_id !== $activity || (int) $proposal->template_id !== $template)) {
            throw new AiAuthoringProposalException('proposal_not_found');
        }
        if ($application !== null && ($proposal === null || ! DB::table('ai_authoring_proposal_applications')
            ->where('customer_id', TenantContext::customerId())->where('proposal_id', $proposal->id)
            ->where('application_uuid', strtolower($application))->exists())) {
            throw new AiAuthoringProposalException('proposal_not_found');
        }

        return [$context, $proposal];
    }

    public function listing(int $actor, array $context, array $input): array
    {
        $binding = $this->binding($actor, $context, 'proposals', array_intersect_key($input, array_flip(['status', 'kind'])));
        $after = $this->decode($input['cursor'] ?? null, $binding);
        $limit = (int) ($input['limit'] ?? 25);
        $query = DB::table('ai_authoring_proposals')->where('customer_id', TenantContext::customerId())
            ->where('activity_id', $context['activity_id'])->where('template_id', $context['template_id']);
        foreach (['status', 'kind'] as $filter) {
            if (isset($input[$filter])) {
                $query->where($filter, $input[$filter]);
            }
        }
        $rows = $query->when($after !== null, fn ($q) => $q->where('id', '>', $after))->orderBy('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $items = [];
        foreach ($rows->take($limit) as $proposal) {
            $sources = $this->records->sources($proposal);
            $revision = $this->records->currentRevision($proposal);
            $fresh = $this->records->freshness($actor, $proposal, $context, $sources);
            $visible = $this->records->intact($proposal, $sources, $revision) && $revision?->payload !== null
                && in_array($proposal->status, ['pending_review', 'accepted', 'rejected'], true)
                && $fresh['sources'] === 'current'
                && ($proposal->status !== 'pending_review' || ($fresh['context'] === 'current' && $fresh['prompt'] === 'current'));
            $items[] = ['proposal_uuid' => $proposal->proposal_uuid, 'kind' => $proposal->kind, 'status' => $proposal->status,
                'lock_version' => (int) $proposal->lock_version, 'revision_no' => $revision === null ? null : (int) $revision->revision_no,
                'content_denied' => $visible ? null : 'unavailable',
                'allowed_actions' => AuthoringAllowedActions::pending($visible, $proposal->status)];
        }

        return ['items' => $items, 'next_cursor' => $more ? $this->encode($binding, $rows[$limit - 1]->id) : null];
    }

    public function requestStatus(array $context, string $uuid): array
    {
        $row = $this->records->request($uuid);
        if ($row === null || (int) $row->activity_id !== $context['activity_id'] || (int) $row->template_id !== $context['template_id']) {
            throw new AiAuthoringProposalException('proposal_not_found');
        }

        return ['request_uuid' => $row->request_uuid, 'request_status' => $row->status, 'item_count' => $row->item_count,
            'proposals' => DB::table('ai_authoring_proposals')->where('customer_id', TenantContext::customerId())
                ->where('generation_request_uuid', $row->request_uuid)->orderBy('item_ordinal')->limit(100)
                ->get(['proposal_uuid', 'status'])->map(fn ($item) => (array) $item)->all()];
    }

    public function sourceScope(int $actor, array $context, string $uuid, array $input): array
    {
        $out = $this->successors->sourceScope($actor, $uuid);
        if ($out['error_code'] !== null) {
            return $out;
        }
        $anchors = $out['anchors'];
        ksort($anchors, SORT_STRING);
        $binding = $this->binding($actor, $context, 'sources:'.$uuid, []);
        $scopeHash = CanonicalJson::hash(array_keys($anchors));
        $after = $this->decode($input['cursor'] ?? null, $binding, $scopeHash);
        $keys = array_values(array_filter(array_keys($anchors), fn ($key) => $after === null || strcmp($key, (string) $after) > 0));
        $limit = (int) ($input['limit'] ?? 25);
        $page = array_slice($keys, 0, $limit);

        return ['anchors' => array_map(fn ($key) => ['anchor_hash' => $key, 'anchor' => $anchors[$key]], $page),
            'selection_limit' => $out['selection_limit'], 'scope' => $out['scope'],
            'next_cursor' => count($keys) > $limit ? $this->encode($binding, end($page), $scopeHash) : null];
    }

    public function contentPreview(int $actor, object $proposal, array $context, ?int $baseVersion = null): array
    {
        $shown = $this->proposals->show($actor, $proposal->proposal_uuid);
        if ($shown['error_code'] !== null || ($shown['content_denied'] ?? null) !== null) {
            throw new AiAuthoringProposalException('proposal_stale');
        }
        if ($baseVersion === null) {
            return ['context' => $context['dto'], 'course_context_hash' => $context['course_context_hash']];
        }
        if ($context['actor_role'] !== 'admin') {
            throw new AiAuthoringProposalException('proposal_forbidden');
        }
        if ($proposal->framework_id === null) {
            throw new AiAuthoringProposalException('framework_selection_conflict');
        }

        $plan = $this->inheritance->preview((int) $proposal->framework_id, $baseVersion);
        $lost = $this->inheritance->excludedDisplay((int) $proposal->framework_id, $baseVersion, $plan['excluded_node_ids']);
        $labels = array_column($lost, 'label', 'node_id');
        // Outside the plan, so the hashes the admin must send back are the same with or without it.
        $display = [
            'eligible_count' => count($plan['eligible_node_ids']),
            'excluded_nodes' => $lost,
            'affected_intents' => array_map(fn (array $intent): array => [
                'intent_id' => $intent['intent_id'], 'source_label' => $intent['source_label'], 'node_label' => $labels[$intent['node_id']] ?? '',
            ], $this->rebase->intentsOnNodes((int) $proposal->template_id, $plan['excluded_node_ids'])),
        ];

        return ['preview' => $plan, 'display' => $display];
    }

    public function detail(int $actor, object $proposal): array
    {
        $shown = $this->proposals->show($actor, $proposal->proposal_uuid);
        if ($shown['error_code'] !== null) {
            return $shown;
        }
        // Never serialize receipt target/context snapshots: they can retain old content.
        $receipts = DB::table('ai_authoring_proposal_applications')
            ->where('customer_id', TenantContext::customerId())->where('proposal_id', $proposal->id)
            ->orderByDesc('id')->limit(100)->get(['application_uuid', 'operation', 'status', 'approved_by', 'revision_id']);
        $this->addAllowedActions($actor, $proposal, $shown, $receipts);
        // Whether this is a human successor with an inherited, still unapproved decision, and why (contract
        // "Restricted decision inheritance", item 4): shown to the next reviewer.
        $shown['inherited_decision_draft'] = (bool) $proposal->inherited_decision_draft;
        $shown['successor_reason'] = $proposal->successor_reason;
        $shown['applications'] = $receipts->map(fn ($row) => [
            'application_uuid' => $row->application_uuid, 'operation' => $row->operation, 'status' => $row->status,
            'approved_by' => $row->approved_by, 'allowed_actions' => $row->allowed_actions,
        ])->all();

        // A reviewer cannot judge an existing-Node mapping from two numbers. The Node's
        // display text is disclosed exactly where the payload is (contract "Owner
        // amendment — P3-B"): a hidden payload discloses no Node either.
        $mapping = is_array($shown['payload'] ?? null) ? ($shown['payload']['mapping'] ?? null) : null;
        if (is_array($mapping) && ($mapping['mode'] ?? null) === 'reuse_existing') {
            $shown['mapping_node'] = $this->mappingNode($proposal, $mapping);
        }

        return $shown;
    }

    /**
     * Sets `allowed_actions` on the proposal and on each receipt of its accepted
     * revision, through the one definition of them. Receipts of other revisions
     * offer nothing.
     *
     * @param  array<string,mixed>  $shown
     * @param  Collection<int,object>  $receipts
     */
    private function addAllowedActions(int $actor, object $proposal, array &$shown, Collection $receipts): void
    {
        foreach ($receipts as $receipt) {
            $receipt->allowed_actions = [];
        }
        $shown['allowed_actions'] = [];
        $visible = ($shown['payload'] ?? null) !== null && ($shown['content_denied'] ?? null) === null;
        if (! $visible) {
            // Hidden content offers no review action; a once-accepted stale proposal offers a successor.
            if (($shown['status'] ?? null) === 'stale') {
                $accepted = $this->records->acceptedRevision($proposal);
                $shown['allowed_actions'] = AuthoringAllowedActions::stale('stale', $accepted !== null && $accepted->payload !== null && $accepted->erased_at === null);
            }

            return;
        }
        if ($shown['status'] !== 'accepted') {
            $shown['allowed_actions'] = AuthoringAllowedActions::pending(true, (string) $shown['status']);

            return;
        }
        try {
            $course = $this->course->proposalContext($actor, (int) $proposal->activity_id);
        } catch (CourseAuthoringContextException) {
            return;
        }
        $accepted = $this->records->acceptedRevision($proposal);
        if ($accepted === null || $accepted->payload === null) {
            return;
        }
        $current = $receipts->filter(fn (object $receipt): bool => (int) $receipt->revision_id === (int) $accepted->id)->values()->all();
        // The context counts as changed until a human reconfirmation covers what it is now.
        $confirmed = DB::table('ai_authoring_proposal_reviews')->where('customer_id', $proposal->customer_id)
            ->where('proposal_id', $proposal->id)->where('revision_id', $accepted->id)->where('action', 'reconfirm_context')
            ->orderByDesc('id')->value('context_hash');
        $unconfirmed = ! hash_equals((string) ($confirmed ?? $proposal->course_context_hash), $course['course_context_hash']);
        // Said the same way to the page: a reconfirmed context is no longer "changed".
        $shown['context_changed'] = $unconfirmed;
        $mapping = json_decode((string) $accepted->payload, true)['mapping'] ?? null;
        $proposalView = (object) ['status' => $shown['status'], 'kind' => $shown['kind'], 'framework_id' => $proposal->framework_id];

        $shown['allowed_actions'] = AuthoringAllowedActions::forProposal($proposalView, true, $course['actor_role'], is_array($mapping) ? $mapping : null, $current, $unconfirmed);
        foreach ($current as $receipt) {
            $receipt->allowed_actions = AuthoringAllowedActions::forReceipt($receipt, $course['actor_role'], $unconfirmed);
        }
    }

    /** @param array<string,mixed> $mapping @return array<string,mixed>|null */
    private function mappingNode(object $proposal, array $mapping): ?array
    {
        if ($proposal->framework_id === null || $proposal->framework_version_id === null
            || ! is_int($mapping['node_id'] ?? null) || ! is_int($mapping['definition_id'] ?? null)) {
            return null;
        }
        try {
            return $this->learning->nodeDisplay((int) $proposal->framework_id, (int) $proposal->framework_version_id, $mapping['node_id'], $mapping['definition_id']);
        } catch (LearningAuthoringBasisException) {
            return null;
        }
    }

    public function commandVersions(int $actor, int $template, int $activity, array $out, ?string $uuid): array
    {
        if (($out['error_code'] ?? null) !== null) {
            return $out;
        }
        $this->scope($actor, $template, $activity);
        $uuid = $out['proposal_uuid'] ?? $uuid;
        if ($uuid !== null) {
            [, $proposal] = $this->scope($actor, $template, $activity, $uuid);
            $revision = $this->records->currentRevision($proposal);
            $out['lock_version'] = (int) $proposal->lock_version;
            $out['revision_no'] = $revision === null ? null : (int) $revision->revision_no;
        }
        foreach ($out['proposals'] ?? [] as $index => $item) {
            [, $proposal] = $this->scope($actor, $template, $activity, $item['proposal_uuid']);
            $out['proposals'][$index]['lock_version'] = (int) $proposal->lock_version;
            $out['proposals'][$index]['revision_no'] = (int) $this->records->currentRevision($proposal)->revision_no;
        }

        return $out;
    }

    private function binding(int $actor, array $context, string $kind, array $filters): string
    {
        return CanonicalJson::hash(['customer' => TenantContext::customerId(), 'actor' => $actor,
            'template' => $context['template_id'], 'activity' => $context['activity_id'], 'kind' => $kind, 'filters' => $filters, 'order' => 'asc']);
    }

    private function encode(string $binding, int|string $after, ?string $scope = null): string
    {
        return Crypt::encryptString(json_encode(compact('binding', 'after', 'scope'), JSON_THROW_ON_ERROR));
    }

    private function decode(?string $cursor, string $binding, ?string $scope = null): int|string|null
    {
        if ($cursor === null) {
            return null;
        }
        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new AiAuthoringProposalException('invalid_proposal');
        }
        if (! is_array($decoded) || ($decoded['binding'] ?? null) !== $binding
            || ! isset($decoded['after']) || (! is_string($decoded['after']) && ! is_int($decoded['after']))) {
            throw new AiAuthoringProposalException('invalid_proposal');
        }
        if (($decoded['scope'] ?? null) !== $scope) {
            throw new AiAuthoringProposalException('proposal_stale');
        }

        return $decoded['after'];
    }
}
