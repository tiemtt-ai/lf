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
        $data = $request->validate(['framework_id' => ['required', 'integer'], 'framework_version_id' => ['required', 'integer']]);
        $this->intents->select((int) $request->user()->id, $this->customerId(), $templateId, (int) $data['framework_id'], (int) $data['framework_version_id']);

        return $this->back($request, $templateId, 'Learning Framework Version selected.');
    }

    public function store(Request $request, int $templateId): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'source_type' => ['required', Rule::in(['course_template_lesson', 'course_template_activity'])],
            'source_id' => ['required', 'integer'], 'learning_node_id' => ['required', 'integer'],
            'mapping_role' => ['required', Rule::in(['teaches', 'practices', 'assesses'])],
            'weight' => ['nullable', 'numeric', 'between:0,1'],
        ]);
        $this->intents->store((int) $request->user()->id, $this->customerId(), $templateId, $data);

        return $this->back($request, $templateId, 'Node mapping added to the working Template.');
    }

    public function destroy(Request $request, int $templateId, int $intentId): RedirectResponse
    {
        $this->admin($request);
        $this->intents->destroy($this->customerId(), $templateId, $intentId);

        return $this->back($request, $templateId, 'Node mapping removed.');
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
