<?php

namespace App\Http\Controllers;

use App\Services\CourseTemplateLearningMappingIntentService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CourseTemplateLearningMappingController extends Controller
{
    public function __construct(private readonly CourseTemplateLearningMappingIntentService $intents) {}

    public function select(Request $request, int $templateId): RedirectResponse
    {
        $this->admin($request);
        // The tab sends one "frameworkId:versionId" choice; the two-field form stays accepted.
        if (is_string($request->input('framework')) && preg_match('/^(\d+):(\d+)$/', $request->input('framework'), $choice)) {
            $request->merge(['framework_id' => $choice[1], 'framework_version_id' => $choice[2]]);
        }
        $data = $request->validate(['framework_id' => ['required', 'integer'], 'framework_version_id' => ['required', 'integer']]);
        $this->intents->select((int) $request->user()->id, $this->customerId(), $templateId, (int) $data['framework_id'], (int) $data['framework_version_id']);

        return $this->back($request, $templateId, __('lf.LF_course_template_learning_flash_selected'));
    }

    public function upgrade(Request $request, int $templateId): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'framework_version_id' => ['required', 'integer', 'min:1'],
            'working_revision' => ['required', 'integer', 'min:0'],
        ]);
        $kept = $this->intents->upgrade((int) $request->user()->id, $this->customerId(), $templateId, (int) $data['framework_version_id'], (int) $data['working_revision']);

        return $this->back($request, $templateId, trans_choice('lf.LF_course_template_learning_flash_upgraded', $kept, ['count' => $kept]));
    }

    public function store(Request $request, int $templateId): RedirectResponse
    {
        $this->admin($request);
        // The tab's picker sends one "type:id" target and several Nodes; the
        // single source_type/source_id/learning_node_id form stays accepted.
        if (is_string($request->input('source')) && preg_match('/^(course_template_lesson|course_template_activity):(\d+)$/', $request->input('source'), $target)) {
            $request->merge(['source_type' => $target[1], 'source_id' => $target[2]]);
        }
        $data = $request->validate([
            'source_type' => ['required', Rule::in(['course_template_lesson', 'course_template_activity'])],
            'source_id' => ['required', 'integer'],
            'learning_node_id' => ['required_without:learning_node_ids', 'integer'],
            'learning_node_ids' => ['required_without:learning_node_id', 'array', 'min:1', 'max:100'],
            'learning_node_ids.*' => ['integer', 'distinct'],
            'mapping_role' => ['required', Rule::in(['teaches', 'practices', 'assesses'])],
            'weight' => ['nullable', 'numeric', 'between:0,1'],
        ], [
            'learning_node_ids.required_without' => __('lf.LF_course_template_learning_error_nodes_required'),
            'learning_node_id.required_without' => __('lf.LF_course_template_learning_error_nodes_required'),
        ]);
        $nodeIds = $data['learning_node_ids'] ?? [$data['learning_node_id']];
        unset($data['learning_node_ids'], $data['learning_node_id']);
        $this->intents->storeMany((int) $request->user()->id, $this->customerId(), $templateId, $data, $nodeIds);

        return $this->back($request, $templateId, trans_choice('lf.LF_course_template_learning_flash_added', count($nodeIds), ['count' => count($nodeIds)]));
    }

    public function destroy(Request $request, int $templateId, int $intentId): RedirectResponse
    {
        $this->admin($request);
        $this->intents->destroy($this->customerId(), $templateId, $intentId);

        return $this->back($request, $templateId, __('lf.LF_course_template_learning_flash_removed'));
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()?->role === 'customer_admin', 403);
    }

    private function customerId(): int
    {
        return TenantContext::customerId() ?? abort(404);
    }

    private function back(Request $request, int $templateId, string $message): RedirectResponse
    {
        return redirect()->route('admin.course-templates.edit', ['id' => $templateId, 'tab' => 'learning'])->with('success', $message);
    }
}
