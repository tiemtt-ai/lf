<?php

namespace App\Http\Controllers\Assessment;

use App\Http\Controllers\Controller;
use App\Services\Assessment\AssessmentCategoryService;
use App\Services\Assessment\AssessmentGraderService;
use App\Services\Assessment\AssessmentQuestionService;
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
    public function __construct(
        private readonly Quizzes $quizzes,
        private readonly AssessmentGraderService $graders,
    ) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $user = $request->user();
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'assessment_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'question_type' => ['nullable', 'string'],
        ]);
        $questionType = $validated['question_type'] ?? null;
        $filters = [
            'keyword' => trim($validated['keyword'] ?? ''),
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'assessment_type' => in_array($validated['assessment_type'] ?? null, Quizzes::TYPES, true) ? $validated['assessment_type'] : null,
            'status' => in_array($validated['status'] ?? null, Quizzes::STATUSES, true) ? $validated['status'] : null,
            // A question type in the draft, or "manual": something to grade by hand (draft or a published version).
            'question_type' => $questionType === 'manual' || in_array($questionType, AssessmentQuestionService::TYPES, true) ? $questionType : null,
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
            ->when($filters['question_type'] === 'manual', fn ($query) => $this->graders->whereNeedsGrading($query, $customerId, 'z.id'))
            ->when($filters['question_type'] && $filters['question_type'] !== 'manual', fn ($query) => $query->whereExists(fn ($exists) => $exists
                ->selectRaw('1')->from(Quizzes::QUESTIONS_TABLE.' as fq')
                ->join(AssessmentQuestionService::TABLE.' as fqq', function ($join): void {
                    $join->on('fqq.id', '=', 'fq.question_id')->on('fqq.customer_id', '=', 'fq.customer_id');
                })
                ->whereColumn('fq.quiz_id', 'z.id')->where('fq.customer_id', $customerId)->where('fqq.question_type', $filters['question_type'])))
            ->select('z.*', 'cat.name as category_name', 'author.name as author_name')
            ->selectSub($count(Quizzes::QUESTIONS_TABLE), 'question_count')
            ->selectSub($count(Quizzes::VERSIONS_TABLE), 'version_count')
            ->selectSub($count(AssessmentGraderService::SCOPES_TABLE), 'grader_count')
            ->orderByDesc('z.created_at')
            ->orderByDesc('z.id')
            ->paginate(10)
            ->withQueryString();

        return view('assessments.quizzes.index', [
            'quizzes' => $quizzes,
            'gradable' => $this->graders->gradableQuizIds($customerId, collect($quizzes->items())->pluck('id')->map(fn ($id) => (int) $id)->all()),
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

    // ---- Giao chấm (ADR-0027 Amendment 1.2) ----

    /**
     * The Giao chấm dialog for the chosen quizzes (`ids[]`). One quiz: its teachers are ticked and saving replaces them.
     * Several: nobody is ticked and saving adds to each quiz, unless "Thay thế" is chosen.
     */
    public function gradersDialog(Request $request): View
    {
        abort_unless($request->ajax(), 404);
        $customerId = $this->customerId();
        $ids = $this->manageableIds($customerId, $request, $request->query('ids', []));
        abort_if($ids === [], 404);
        $single = count($ids) === 1;

        return view('assessments.quizzes.graders-dialog', [
            'ids' => $ids,
            'titles' => DB::table(Quizzes::TABLE)->where('customer_id', $customerId)->whereIn('id', $ids)->orderBy('title')->limit(3)->pluck('title'),
            'teachers' => $this->graders->suggestions($customerId, $ids),
            'chosen' => $single ? $this->graders->quizGraders($customerId, $ids[0])->pluck('id')->map(fn ($v) => (int) $v)->all() : [],
            'mode' => $single ? 'replace' : 'add',
            // Opened on the quiz page: saving returns to its Người chấm tab.
            'returnTo' => $single && $request->boolean('from_quiz') ? $ids[0] : null,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function saveGraders(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
            'user_ids' => ['nullable', 'array', 'max:200'],
            'user_ids.*' => ['integer', 'distinct'],
            'mode' => ['required', Rule::in(['add', 'replace'])],
            'return_to' => ['nullable', 'integer'],
        ]);
        $customerId = $this->customerId();
        $ids = $this->manageableIds($customerId, $request, $validated['ids']);
        $result = $this->graders->setQuizGraders($customerId, $request->user(), $ids, $validated['user_ids'] ?? [], $validated['mode']);
        $skipped = $result['skipped'] + count(array_unique($validated['ids'])) - count($ids);

        $message = trans_choice('lf.LF_assessment_grader_quizzes_saved', $result['saved'], ['count' => $result['saved']]);
        if ($skipped > 0) {
            $message .= ' '.trans_choice('lf.LF_assessment_grader_quizzes_skipped', $skipped, ['count' => $skipped]);
        }
        $returnTo = isset($validated['return_to']) && in_array((int) $validated['return_to'], $ids, true)
            ? route($this->routePrefix($request).'.show', ['id' => (int) $validated['return_to'], 'tab' => 'graders'])
            : null;
        if ($returnTo !== null) {
            session()->flash('success', $message);
        }

        return $request->expectsJson()
            ? response()->json(array_filter(['message' => $message, 'redirect' => $returnTo]))
            : ($returnTo ? redirect()->to($returnTo) : back())->with('success', $message);
    }

    /**
     * The chosen quizzes this user sees in the list (admins: the tenant; teachers: their own) that have something to grade
     * by hand; others are dropped.
     *
     * @return list<int>
     */
    private function manageableIds(int $customerId, Request $request, mixed $ids): array
    {
        $ids = collect(is_array($ids) ? $ids : [])->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->take(100)->values()->all();

        $mine = $ids === [] ? [] : $this->quizzes->scoped($customerId, $request->user(), 'z')->whereIn('z.id', $ids)
            ->orderBy('z.id')->pluck('z.id')->map(fn ($id) => (int) $id)->all();

        return $this->graders->gradableQuizIds($customerId, $mine);
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
