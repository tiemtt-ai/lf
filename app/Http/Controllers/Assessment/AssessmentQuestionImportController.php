<?php

namespace App\Http\Controllers\Assessment;

use App\Http\Controllers\Controller;
use App\Services\Assessment\AssessmentCategoryService;
use App\Services\Assessment\AssessmentQuestionImportService;
use App\Services\Assessment\AssessmentQuestionService;
use App\Support\SimpleXlsx;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Nhập / Xuất Excel on the Câu hỏi page, for admins and teachers. Imported questions belong to the person
 * who imports them, exactly as if they had been typed into the form.
 */
final class AssessmentQuestionImportController extends Controller
{
    public function __construct(private readonly AssessmentQuestionImportService $import) {}

    public function create(Request $request): View
    {
        abort_unless($request->ajax(), 404);

        return view('assessments.questions.import-dialog', [
            'categories' => DB::table(AssessmentCategoryService::TABLE)->where('customer_id', $this->customerId())
                ->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'routePrefix' => $this->routePrefix($request),
            'maxRows' => AssessmentQuestionImportService::MAX_ROWS,
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'string', Rule::in(['draft', 'published'])],
        ]);

        $rows = $this->import->read($request->file('file'));
        $count = $this->import->import(
            $this->customerId(),
            $request->user(),
            $rows,
            isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            $validated['status'],
        );
        $message = trans_choice('lf.LF_assessment_question_import_done', $count, ['count' => $count]);

        return $request->expectsJson()
            ? response()->json(['message' => $message], 201)
            : redirect()->route($this->routePrefix($request).'.index')->with('success', $message);
    }

    public function template(): Response
    {
        return $this->xlsx($this->import->templateRows(), __('lf.LF_assessment_question_import_template_file'));
    }

    /** Xuất Excel: the questions of the current list filters, in the import columns. */
    public function export(Request $request): Response
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'question_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);
        $rows = $this->import->exportRows($this->customerId(), $request->user(), [
            'keyword' => trim($validated['keyword'] ?? ''),
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'question_type' => in_array($validated['question_type'] ?? null, AssessmentQuestionService::TYPES, true) ? $validated['question_type'] : null,
            'status' => in_array($validated['status'] ?? null, AssessmentQuestionService::STATUSES, true) ? $validated['status'] : null,
        ]);

        return $this->xlsx($rows, __('lf.LF_assessment_question_export_file').'-'.now()->format('Ymd-His'));
    }

    /** @param  list<list<string>>  $rows */
    private function xlsx(array $rows, string $filename): Response
    {
        return response(SimpleXlsx::write($rows, __('lf.LF_navigation_menu_assessment_questions')), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.xlsx"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function routePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => 'admin.assessments.questions',
            'teacher' => 'teacher.assessments.questions',
            default => abort(403),
        };
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);

        return $customerId;
    }
}
