<?php

namespace App\Http\Controllers\Assessment;

use App\Http\Controllers\Controller;
use App\Services\Assessment\AssessmentCategoryService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Danh mục of the Kiểm tra module. Admin only: the routes exist only in the admin group, and each action checks
 * the role again. Teachers pick an active category in the question, passage and quiz forms.
 */
final class AssessmentCategoryController extends Controller
{
    private const ROUTE = 'admin.assessments.categories';

    public function __construct(
        private readonly AssessmentCategoryService $categories,
    ) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId($request);
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string'],
        ]);
        $keyword = trim($validated['keyword'] ?? '');
        $status = in_array($validated['status'] ?? null, AssessmentCategoryService::STATUSES, true) ? $validated['status'] : null;
        $usage = fn (string $table) => fn ($query) => $query->from($table)->selectRaw('count(*)')
            ->whereColumn($table.'.category_id', 'categories.id')->where($table.'.customer_id', $customerId);

        $categories = DB::table(AssessmentCategoryService::TABLE.' as categories')
            ->leftJoin(AssessmentCategoryService::TABLE.' as parent', function ($join): void {
                $join->on('parent.id', '=', 'categories.parent_id')->on('parent.customer_id', '=', 'categories.customer_id');
            })
            ->where('categories.customer_id', $customerId)
            ->when($keyword !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('categories.name', 'like', '%'.$keyword.'%')
                ->orWhere('categories.slug', 'like', '%'.$keyword.'%')))
            ->when($status, fn ($query) => $query->where('categories.status', $status))
            ->select('categories.*', 'parent.name as parent_name')
            ->selectSub($usage('core_assessment_questions'), 'question_count')
            ->selectSub($usage('core_assessment_passages'), 'passage_count')
            ->selectSub($usage('core_assessment_quizzes'), 'quiz_count')
            ->selectSub(fn ($query) => $query->from(AssessmentCategoryService::TABLE.' as children')->selectRaw('count(*)')
                ->whereColumn('children.parent_id', 'categories.id')->where('children.customer_id', $customerId), 'child_count')
            ->orderByDesc('categories.created_at')
            ->orderByDesc('categories.id')
            ->paginate(10)
            ->withQueryString();

        return view('assessments.categories.index', [
            'categories' => $categories,
            'keyword' => $keyword,
            'status' => $status,
            'routePrefix' => self::ROUTE,
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->ajax(), 404);

        return view('assessments.categories.dialog', [
            'category' => null,
            'parentOptions' => $this->categories->parentOptions($this->customerId($request)),
            'routePrefix' => self::ROUTE,
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $customerId = $this->customerId($request);
        $this->categories->create($customerId, $this->validated($request));

        return $this->saved($request, __('lf.LF_assessment_category_created'), 201);
    }

    public function edit(Request $request, int $id): View
    {
        abort_unless($request->ajax(), 404);
        $customerId = $this->customerId($request);
        $category = $this->categories->find($customerId, $id);

        return view('assessments.categories.dialog', [
            'category' => $category,
            'parentOptions' => $this->categories->parentOptions($customerId, $category),
            'routePrefix' => self::ROUTE,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $customerId = $this->customerId($request);
        $this->categories->update($customerId, $id, $this->validated($request));

        return $this->saved($request, __('lf.LF_assessment_category_updated'));
    }

    public function toggleStatus(Request $request, int $id): RedirectResponse
    {
        $status = $this->categories->toggleStatus($this->customerId($request), $id);

        return back()->with('success', __('lf.LF_assessment_category_status_'.$status));
    }

    public function bulkDestroy(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ids = array_map('intval', $validated['ids']);
        $blocked = $this->categories->deleteMany($this->customerId($request), $ids);

        if ($blocked !== null) {
            $message = '«'.$blocked['name'].'» — '.trans_choice(
                'lf.LF_assessment_category_delete_blocked_'.$blocked['reason'], $blocked['count'], ['count' => $blocked['count']]
            );

            return $request->expectsJson()
                ? response()->json(['message' => $message], 409)
                : back()->withErrors(['category' => $message]);
        }

        $message = trans_choice('lf.LF_assessment_category_bulk_deleted', count($ids), ['count' => count($ids)]);

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : redirect()->route(self::ROUTE.'.index')->with('success', $message);
    }

    /** @return array{name: string, parent_id: int|null, description: string|null, status: string} */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'string', Rule::in(AssessmentCategoryService::STATUSES)],
        ]);

        return [
            'name' => trim($validated['name']),
            'parent_id' => isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
            'description' => isset($validated['description']) ? trim($validated['description']) : null,
            'status' => $validated['status'],
        ];
    }

    private function saved(Request $request, string $message, int $status = 200): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], $status)
            : redirect()->route(self::ROUTE.'.index')->with('success', $message);
    }

    private function customerId(Request $request): int
    {
        // Second lock behind the admin-only routes.
        abort_unless($request->user()?->role === 'customer_admin', 403);
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);

        return $customerId;
    }
}
