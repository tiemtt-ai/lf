<?php

namespace App\Http\Controllers;

use App\Exceptions\AiAuthoringProposalException;
use App\Exceptions\CourseAuthoringContextException;
use App\Exceptions\LearningAuthoringBasisException;
use App\Http\Requests\AiAuthoringInput;
use App\Services\AiAuthoringApplicationService;
use App\Services\AiAuthoringHttpReadService;
use App\Services\AiAuthoringProposalService;
use App\Services\AiAuthoringRebaseService;
use App\Services\AiAuthoringSuccessorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class AiAuthoringController extends Controller
{
    public function __construct(
        private readonly AiAuthoringInput $input,
        private readonly AiAuthoringHttpReadService $reads,
        private readonly AiAuthoringProposalService $proposals,
        private readonly AiAuthoringApplicationService $applications,
        private readonly AiAuthoringSuccessorService $successors,
        private readonly AiAuthoringRebaseService $rebase,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $actor = (int) $request->user()->id;
            $template = $this->pathId($request, 'templateId');
            $activity = $this->pathId($request, 'activityId');
            $uuid = $request->route('proposalUuid');
            $application = $request->route('applicationUuid');
            $op = $request->route('authoringOperation');
            $auditOperation = match ($op) {
                'proposals.successor-preview' => 'authoring_successor_preview',
                'proposals.show', 'proposals.target-preview', 'proposals.context-preview', 'proposals.inherited-draft-preview' => 'authoring_proposal_retrieval',
                default => null,
            };
            [$context, $proposal] = $this->reads->scope($actor, $template, $activity, $uuid, $application, $auditOperation);
            $v = $this->input->validated($request, $op);
            $requestId = $v['request_id'] ?? '';
            $lock = $v['expected_lock_version'] ?? 0;
            $revision = $v['expected_revision_no'] ?? 0;
            $out = match ($op) {
                'proposals.index' => $this->reads->listing($actor, $context, $v),
                'generation-requests.store' => $this->proposals->generate($actor, $activity, $v['requested_kinds'], $v['framework_id'] ?? null, $v['framework_version_id'] ?? null, $requestId),
                'generation-requests.show' => $this->reads->requestStatus($context, $request->route('generationRequestUuid')),
                'proposals.show' => $this->reads->detail($actor, $proposal),
                'proposals.update' => $this->proposals->edit($actor, $uuid, $revision, $lock, $v['payload'], $v['payload_schema_version'], $requestId),
                'proposals.decide' => $this->proposals->decide($actor, $uuid, $v['action'], $revision, $lock, $requestId, $v['reason'] ?? null),
                'proposals.bulk-decide' => $this->bulk($actor, $template, $activity, $v['items']),
                'proposals.target-preview' => $this->applications->targetPreview($actor, $uuid),
                'proposals.confirm-target' => $this->applications->confirmTarget($actor, $uuid, $lock, $v['expected_target_hash'], $requestId),
                'proposals.reject-target' => $this->applications->rejectTarget($actor, $uuid, $lock, $v['expected_target_hash'], $requestId),
                'proposals.context-preview' => $this->reads->contentPreview($actor, $proposal, $context),
                'proposals.reconfirm-context' => $this->applications->reconfirmContext($actor, $uuid, $lock, $v['expected_course_context_hash'], $requestId),
                'proposals.approve-node' => $this->applications->approveNode($actor, $uuid, $v['framework_id'], $v['framework_version_id'], $revision, $lock, $requestId),
                'proposals.apply-intent' => $this->applications->applyIntent($actor, $uuid, $lock, $requestId),
                'proposals.applications.retry' => $this->applications->retryApplication($actor, $uuid, $application, $lock, $requestId),
                'proposals.applications.cancel' => $this->applications->cancelApplication($actor, $application, $v['reason_code'], $lock, $requestId),
                'proposals.successor-source-scope' => $this->reads->sourceScope($actor, $context, $uuid, $v),
                'proposals.successor-preview' => $this->successors->preview($actor, $uuid),
                'proposals.successors.store' => $this->successors->create($actor, $uuid, $v['reason'], $v['payload'], $requestId, $v['selected_anchor_hashes']),
                'proposals.inherited-draft-preview' => $this->reads->contentPreview($actor, $proposal, $context, (int) $v['base_version_id']),
                'proposals.inherit-draft' => $this->rebase->inheritDraft($actor, $uuid, $v['base_version_id'], $v['version_code'], $v['title'], $v['expected_source_graph_hash'], $v['expected_plan_hash'], $requestId),
                'proposals.rebase-preview' => $this->rebase->rebasePreview($actor, $uuid, (int) $v['target_version_id']),
                'proposals.rebase-selection' => $this->rebase->rebaseSelection($actor, $uuid, $v['target_version_id'], $v['dispositions'], $v['expected_preview_hash'], $requestId),
                default => ['error_code' => 'proposal_not_found'],
            };
            if (! $request->isMethod('GET')) {
                $out = $this->reads->commandVersions($actor, $template, $activity, $out, $uuid);
            }
            if (in_array($out['request_status'] ?? null, ['pending', 'running'], true)) {
                $base = preg_replace('~/ai-authoring/.*$~', '/ai-authoring/generation-requests', $request->url());
                $out['status_url'] = $base.'/'.($request->route('generationRequestUuid') ?? $requestId);
            }

            return $this->response($out);
        } catch (AiAuthoringProposalException $e) {
            return $this->response(['error_code' => $e->errorCode]);
        } catch (CourseAuthoringContextException) {
            return $this->response(['error_code' => 'proposal_not_found']);
        } catch (LearningAuthoringBasisException) {
            return $this->response(['error_code' => 'framework_selection_conflict']);
        } catch (Throwable $e) {
            // Do not log exception payload/SQL bindings at this disclosure boundary.
            return $this->response(['error_code' => 'service_unavailable']);
        }
    }

    private function bulk(int $actor, int $template, int $activity, array $items): array
    {
        $outcomes = [];
        foreach ($items as $item) {
            try {
                $this->reads->scope($actor, $template, $activity, $item['proposal_uuid']);
                $out = $this->proposals->decide($actor, $item['proposal_uuid'], $item['action'], $item['expected_revision_no'],
                    $item['expected_lock_version'], strtolower($item['request_id']), $item['reason'] ?? null);
                $out = $this->reads->commandVersions($actor, $template, $activity, $out, $item['proposal_uuid']);
            } catch (AiAuthoringProposalException|CourseAuthoringContextException) {
                $out = ['error_code' => 'proposal_not_found'];
            } catch (Throwable) {
                $out = ['error_code' => 'service_unavailable'];
            }
            $response = $this->response($out);
            $outcomes[] = ['proposal_uuid' => strtolower($item['proposal_uuid']), 'request_id' => strtolower($item['request_id']),
                'http_status' => $response->getStatusCode()] + $response->getData(true);
        }

        return ['items' => $outcomes];
    }

    private function response(array $out): JsonResponse
    {
        $code = $out['error_code'] ?? null;
        unset($out['error_code']);
        if ($code !== null) {
            $status = match ($code) {
                'proposal_not_found' => 404,
                'proposal_forbidden' => 403,
                'invalid_proposal' => 422,
                'service_unavailable' => 503,
                default => 409,
            };

            // These codes originate only from bounded service vocabularies. Details
            // are deliberately empty: provider output and SQL never become HTTP errors.
            return response()->json(['data' => null, 'error' => ['code' => $code, 'details' => (object) []]], $status)
                ->header('Cache-Control', 'no-store');
        }
        $status = in_array($out['request_status'] ?? null, ['pending', 'running'], true) ? 202 : 200;
        $out = array_intersect_key($out, array_flip([
            'items', 'next_cursor', 'request_uuid', 'request_status', 'item_count', 'proposals', 'status_url',
            'proposal_uuid', 'kind', 'status', 'creation_mode', 'lock_version', 'revision_no', 'payload', 'content_denied',
            'context_changed', 'citations', 'reviews', 'applications', 'allowed_actions', 'replayed',
            'application_uuid', 'application_status', 'node_id', 'reused_node', 'target_snapshot', 'target_hash',
            'version_status', 'node_status', 'context', 'course_context_hash', 'anchors', 'selection_limit', 'scope',
            'inherited_decision_draft', 'successor_reason', 'preview', 'preview_hash', 'result_version_id', 'node_map', 'replacements',
        ]));

        return response()->json(['data' => $out, 'error' => null], $status)->header('Cache-Control', 'no-store');
    }

    private function pathId(Request $request, string $key): int
    {
        $raw = (string) $request->route($key);
        if (strlen($raw) > 18 || preg_match('/\A[1-9][0-9]*\z/', $raw) !== 1) {
            throw new AiAuthoringProposalException('proposal_not_found');
        }

        return (int) $raw;
    }
}
