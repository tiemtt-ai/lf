<?php

namespace App\Http\Requests;

use App\Exceptions\AiAuthoringProposalException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Strict JSON command boundary; services retain all domain validation. */
final class AiAuthoringInput
{
    public function validated(Request $request, string $operation): array
    {
        $get = $request->isMethod('GET');
        if (! $get && (! $request->isJson() || $request->query->count() !== 0)) {
            throw new AiAuthoringProposalException('invalid_proposal');
        }
        try {
            // Laravel's global TrimStrings/ConvertEmptyStringsToNull must not
            // alter a versioned JSON payload (empty rationale is meaningful).
            $input = $get ? $request->query->all() : json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AiAuthoringProposalException('invalid_proposal');
        }
        if (! is_array($input)) {
            throw new AiAuthoringProposalException('invalid_proposal');
        }
        $integer = static function ($attribute, $value, $fail) use ($get): void {
            if ($get ? (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1 || strlen($value) > 18) : (! is_int($value) || $value < 1)) {
                $fail('invalid_integer');
            }
        };
        $id = ['required', $integer];
        $hash = ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'];
        $lock = ['expected_lock_version' => $id];
        $review = $lock + ['expected_revision_no' => $id];
        $page = ['cursor' => ['sometimes', 'string', 'max:4096'], 'limit' => ['sometimes', $integer, 'integer', 'max:100']];
        $decision = $review + ['action' => 'required|in:accept,reject', 'reason' => 'sometimes|nullable|string|max:2000'];
        $rules = match ($operation) {
            'proposals.index' => $page + ['status' => 'sometimes|in:pending_review,accepted,rejected,stale,deletion_pending,deleted', 'kind' => 'sometimes|in:summary,concept,learning_objective,competency,node_mapping'],
            'generation-requests.store' => [
                'requested_kinds' => 'required|array|min:1|max:5', 'requested_kinds.*' => 'required|distinct|in:summary,concept,learning_objective,competency,node_mapping',
                'framework_id' => ['sometimes', $integer], 'framework_version_id' => ['sometimes', $integer],
            ],
            'proposals.update' => $review + ['payload_schema_version' => $id, 'payload' => 'required|array'],
            'proposals.decide' => $decision,
            'proposals.bulk-decide' => [
                'items' => 'required|array|list|min:1|max:100',
                'items.*' => 'required|array:proposal_uuid,request_id,expected_lock_version,expected_revision_no,action,reason',
                'items.*.proposal_uuid' => 'required|uuid|distinct:ignore_case',
                'items.*.request_id' => 'required|uuid|distinct:ignore_case',
                ...collect($decision)->mapWithKeys(fn ($rule, $key) => ['items.*.'.$key => $rule])->all(),
            ],
            'proposals.confirm-target', 'proposals.reject-target' => $lock + ['expected_target_hash' => $hash],
            'proposals.reconfirm-context' => $lock + ['expected_course_context_hash' => $hash],
            'proposals.approve-node' => $review + ['framework_id' => $id, 'framework_version_id' => $id],
            'proposals.apply-intent', 'proposals.applications.retry' => $lock,
            'proposals.applications.cancel' => $lock + ['reason_code' => 'required|string|regex:/\A[a-z][a-z0-9_]{0,63}\z/'],
            'proposals.successor-source-scope' => $page,
            'proposals.successors.store' => [
                'reason' => 'required|in:source_revision_changed,context_changed,target_changed,intent_removed,human_correction',
                'payload' => 'present|nullable|array', 'selected_anchor_hashes' => 'required|array|list|min:1|max:200',
                'selected_anchor_hashes.*' => [...$hash, 'distinct'],
            ],
            'proposals.inherited-draft-preview' => ['base_version_id' => $id],
            'proposals.inherit-draft' => ['base_version_id' => $id, 'version_code' => 'required|string|max:100', 'title' => 'required|string|max:255', 'expected_source_graph_hash' => $hash, 'expected_plan_hash' => $hash],
            'proposals.rebase-preview' => ['target_version_id' => $id],
            'proposals.rebase-selection' => [
                'target_version_id' => $id, 'expected_preview_hash' => $hash,
                'dispositions' => 'present|array', 'dispositions.*' => 'required|array:disposition,node_id,reason',
                'dispositions.*.disposition' => 'required|in:map,remove_explicit,cancel_rebase',
                'dispositions.*.node_id' => ['sometimes', $integer], 'dispositions.*.reason' => 'sometimes|string|max:2000',
            ],
            default => [],
        };
        if (! $get) {
            $rules['request_id'] = 'required|uuid';
        }
        $allowed = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
        if (array_diff(array_keys($input), $allowed) !== [] || Validator::make($input, $rules)->fails()) {
            throw new AiAuthoringProposalException('invalid_proposal');
        }
        if (isset($input['payload']) && (strlen(json_encode($input['payload'], JSON_THROW_ON_ERROR)) > 65536
            || $this->depth($input['payload']) > 8)) {
            throw new AiAuthoringProposalException('invalid_proposal');
        }
        foreach (['request_id', 'proposal_uuid'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = strtolower($input[$key]);
            }
        }
        foreach ($input['dispositions'] ?? [] as $key => $plan) {
            if (preg_match('/\A[1-9][0-9]*\z/', (string) $key) !== 1) {
                throw new AiAuthoringProposalException('invalid_proposal');
            }
        }

        return $input;
    }

    private function depth(array $value): int
    {
        return 1 + max([0, ...array_map(fn ($item) => is_array($item) ? $this->depth($item) : 0, $value)]);
    }
}
