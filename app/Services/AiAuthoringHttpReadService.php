<?php

namespace App\Services;

use App\Exceptions\AiAuthoringProposalException;
use App\Exceptions\CourseAuthoringContextException;
use App\Services\Ai\AuthoringProposalRecords;
use App\Support\Ai\CanonicalJson;
use App\Support\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
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
                'allowed_actions' => $visible && $proposal->status === 'pending_review' ? ['edit', 'accept', 'reject'] : []];
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

        return ['preview' => $this->inheritance->preview((int) $proposal->framework_id, $baseVersion)];
    }

    public function detail(int $actor, object $proposal): array
    {
        $shown = $this->proposals->show($actor, $proposal->proposal_uuid);
        if ($shown['error_code'] !== null) {
            return $shown;
        }
        // Never serialize receipt target/context snapshots: they can retain old content.
        $shown['applications'] = DB::table('ai_authoring_proposal_applications')
            ->where('customer_id', TenantContext::customerId())->where('proposal_id', $proposal->id)
            ->orderByDesc('id')->limit(100)->get(['application_uuid', 'operation', 'status', 'approved_by'])
            ->map(fn ($row) => (array) $row)->all();

        return $shown;
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
