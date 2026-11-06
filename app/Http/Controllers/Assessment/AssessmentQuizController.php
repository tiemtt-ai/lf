<?php

namespace App\Http\Controllers\Assessment;

use App\Http\Controllers\Controller;
use App\Services\Assessment\AssessmentCategoryService;
use App\Services\Assessment\AssessmentQuizService as Quizzes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Đề / Bài tập of the Kiểm tra module: the list and the settings form of a quiz draft. Admins see the tenant, teachers
 * their own quizzes. The quiz page (sections, questions, publishing) is AssessmentQuizBuilderController.
 */
final class AssessmentQuizController extends Controller
{
    public function __construct(private readonly Quizzes $quizzes) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $user = $request->user();
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'assessment_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);
        $filters = [
            'keyword' => trim($validated['keyword'] ?? ''),
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'assessment_type' => in_array($validated['assessment_type'] ?? null, Quizzes::TYPES, true) ? $validated['assessment_type'] : null,
            'status' => in_array($validated['status'] ?? null, Quizzes::STATUSES, true) ? $validated['status'] : null,
        ];
        $count = fn (string $table) => fn ($query) => $query->from($table)->selectRaw('count(*)')
            ->whereColumn($table.'.quiz_id', 'z.id')->where($table.'.customer_id', $customerId);

        $quizzes = $this->quizzes->scoped($customerId, $user, 'z')
            ->leftJoin(AssessmentCategoryService::TABLE.' as cat', function ($join): void {
                $join->on('cat.id', '=', 'z.category_id')->on('cat.customer_id', '=', 'z.customer_id');
            })
            ->leftJoin('users as author', 'author.id', '=', 'z.created_by')
            ->when($filters['keyword'] !== '', fn ($query) => $query->where('z.title', 'like', '%'.$filters['keyword'].'%'))
            ->when($filters['category_id'], fn ($query, $id) => $query->where('z.category_id', $id))
            ->when($filters['assessment_type'], fn ($query, $type) => $query->where('z.assessment_type', $type))
            ->when($filters['status'], fn ($query, $status) => $query->where('z.status', $status))
            ->select('z.*', 'cat.name as category_name', 'author.name as author_name')
            ->selectSub($count(Quizzes::QUESTIONS_TABLE), 'question_count')
            ->selectSub($count(Quizzes::VERSIONS_TABLE), 'version_count')
            ->orderByDesc('z.created_at')
            ->orderByDesc('z.id')
            ->paginate(10)
            ->withQueryString();

        return view('assessments.quizzes.index', [
            'quizzes' => $quizzes,
            'filters' => $filters,
            'categories' => $this->categoryOptions($customerId),
            'routePrefix' => $this->routePrefix($request),
            'showAuthor' => $user->role === 'customer_admin',
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->ajax(), 404);

        return $this->dialog($request, null);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $id = $this->quizzes->save($this->customerId(), $request->user(), null, $this->validated($request));
        $message = __('lf.LF_assessment_quiz_created');

        // A new quiz continues on its own page, where its questions are chosen.
        return $request->expectsJson()
            ? response()->json(['message' => $message, 'redirect' => route($this->routePrefix($request).'.show', $id)], 201)
            : redirect()->route($this->routePrefix($request).'.show', $id)->with('success', $message);
    }

    public function edit(Request $request, int $id): View
    {
        abort_unless($request->ajax(), 404);

        return $this->dialog($request, $this->quizzes->find($this->customerId(), $request->user(), $id));
    }

    public function update(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $this->quizzes->save($this->customerId(), $request->user(), $id, $this->validated($request));
        $message = __('lf.LF_assessment_quiz_updated');

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : back()->with('success', $message);
    }

    public function bulkDestroy(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ids = array_map('intval', $validated['ids']);
        $blocked = $this->quizzes->deleteMany($this->customerId(), $request->user(), $ids);

        if ($blocked !== null) {
            $message = '«'.$blocked['name'].'» — '.trans_choice(
                'lf.LF_assessment_quiz_delete_blocked_'.$blocked['reason'], $blocked['count'], ['count' => $blocked['count']]
            );

            return $request->expectsJson()
                ? response()->json(['message' => $message], 409)
                : back()->withErrors(['quiz' => $message]);
        }

        $message = trans_choice('lf.LF_assessment_quiz_bulk_deleted', count($ids), ['count' => count($ids)]);

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : redirect()->route($this->routePrefix($request).'.index')->with('success', $message);
    }

    private function dialog(Request $request, ?object $quiz): View
    {
        return view('assessments.quizzes.dialog', [
            'quiz' => $quiz,
            'categories' => $this->categoryOptions($this->customerId(), $quiz?->category_id),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'assessment_type' => ['required', 'string', Rule::in(Quizzes::TYPES)],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:100'],
            'pass_score_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'show_result_mode' => ['required', 'string', Rule::in(Quizzes::RESULT_MODES)],
        ]);
        $description = trim((string) ($validated['description'] ?? ''));

        return [
            'title' => trim($validated['title']),
            'description' => $description === '' ? null : $description,
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'assessment_type' => $validated['assessment_type'],
            'duration_minutes' => isset($validated['duration_minutes']) ? (int) $validated['duration_minutes'] : null,
            'max_attempts' => (int) $validated['max_attempts'],
            'pass_score_percentage' => isset($validated['pass_score_percentage']) ? (string) $validated['pass_score_percentage'] : null,
            'show_result_mode' => $validated['show_result_mode'],
        ];
    }

    private function categoryOptions(int $customerId, mixed $keepId = null): Collection
    {
        return DB::table(AssessmentCategoryService::TABLE)
            ->where('customer_id', $customerId)
            ->where(fn ($query) => $query->where('status', 'active')->when($keepId, fn ($query, $id) => $query->orWhere('id', $id)))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function routePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => 'admin.assessments.quizzes',
            'teacher' => 'teacher.assessments.quizzes',
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
