<?php

namespace App\Http\Controllers\Assessment;

use App\Http\Controllers\Controller;
use App\Services\Assessment\AssessmentQuestionService;
use App\Services\Assessment\AssessmentRubricService as Rubrics;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Tiêu chí chấm of the Kiểm tra module (ADR-0026), for admins and teachers. The list shows every rubric the user may
 * choose; only the ones they may edit get the edit, status and delete controls.
 */
final class AssessmentRubricController extends Controller
{
    public function __construct(private readonly Rubrics $rubrics) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $user = $request->user();
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string'],
        ]);
        $keyword = trim($validated['keyword'] ?? '');
        $status = in_array($validated['status'] ?? null, Rubrics::STATUSES, true) ? $validated['status'] : null;
        $count = fn (string $table, string $column) => fn ($query) => $query->from($table)->selectRaw('count(*)')
            ->whereColumn($table.'.'.$column, 'r.id')->where($table.'.customer_id', $customerId);

        $rubrics = $this->rubrics->usable($customerId, $user)
            ->leftJoin('users as author', 'author.id', '=', 'r.created_by')
            ->when($keyword !== '', fn ($query) => $query->where('r.title', 'like', '%'.$keyword.'%'))
            ->when($status, fn ($query) => $query->where('r.status', $status))
            ->select('r.*', 'author.name as author_name')
            ->selectSub($count(Rubrics::ITEMS_TABLE, 'rubric_id'), 'item_count')
            ->selectSub($count(AssessmentQuestionService::TABLE, 'rubric_id'), 'question_count')
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.id')
            ->paginate(10)
            ->withQueryString();

        return view('assessments.rubrics.index', [
            'rubrics' => $rubrics,
            'keyword' => $keyword,
            'status' => $status,
            'routePrefix' => $this->routePrefix($request),
            'canEdit' => fn (object $rubric) => $user->role === 'customer_admin' || (int) $rubric->created_by === (int) $user->id,
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->ajax(), 404);

        return $this->dialog($request, null);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $this->rubrics->save($this->customerId(), $request->user(), null, $this->validated($request));

        return $this->saved($request, __('lf.LF_assessment_rubric_created'), 201);
    }

    public function edit(Request $request, int $id): View
    {
        abort_unless($request->ajax(), 404);
        $rubric = $this->rubrics->editable($this->customerId(), $request->user())->where('id', $id)->first();
        abort_if(! $rubric, 404);

        return $this->dialog($request, $rubric);
    }

    public function update(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $this->rubrics->save($this->customerId(), $request->user(), $id, $this->validated($request));

        return $this->saved($request, __('lf.LF_assessment_rubric_updated'));
    }

    public function toggleStatus(Request $request, int $id): RedirectResponse
    {
        $status = $this->rubrics->toggleStatus($this->customerId(), $request->user(), $id);

        return back()->with('success', __('lf.LF_assessment_rubric_status_'.$status));
    }

    public function bulkDestroy(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ids = array_map('intval', $validated['ids']);
        $blocked = $this->rubrics->deleteMany($this->customerId(), $request->user(), $ids);

        if ($blocked !== null) {
            $message = '«'.$blocked['title'].'» — '.trans_choice('lf.LF_assessment_rubric_delete_blocked', $blocked['count'], ['count' => $blocked['count']]);

            return $request->expectsJson()
                ? response()->json(['message' => $message], 409)
                : back()->withErrors(['rubric' => $message]);
        }

        $message = trans_choice('lf.LF_assessment_rubric_bulk_deleted', count($ids), ['count' => count($ids)]);

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : redirect()->route($this->routePrefix($request).'.index')->with('success', $message);
    }

    private function dialog(Request $request, ?object $rubric): View
    {
        $items = $rubric ? $this->rubrics->items($this->customerId(), (int) $rubric->id)->map(fn ($item) => [
            'title' => (string) $item->title,
            'description' => (string) $item->description,
            'max_points' => Rubrics::points((float) $item->max_points),
        ])->values() : collect([['title' => '', 'description' => '', 'max_points' => '']]);

        return view('assessments.rubrics.dialog', [
            'rubric' => $rubric,
            'items' => $items,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'skill_type' => ['nullable', 'string', Rule::in(AssessmentQuestionService::SKILLS)],
            'status' => ['required', 'string', Rule::in(Rubrics::STATUSES)],
            'items' => ['nullable', 'array', 'max:'.Rubrics::MAX_ITEMS],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.max_points' => ['nullable', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],
        ], [], [
            'items.*.title' => __('lf.LF_assessment_rubric_item_title'),
            'items.*.max_points' => __('lf.LF_assessment_rubric_item_points'),
        ]);

        $nullable = fn (?string $value) => ($value = trim((string) $value)) === '' ? null : $value;
        $items = [];
        foreach (array_values($validated['items'] ?? []) as $index => $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $points = $item['max_points'] ?? null;
            // A fully empty row is a leftover of the form; a half-filled one is a mistake to point out.
            if ($title === '' && ($points === null || $points === '') && $nullable($item['description'] ?? null) === null) {
                continue;
            }
            if ($title === '' || $points === null || $points === '') {
                throw ValidationException::withMessages([
                    'items' => __('lf.LF_assessment_rubric_error_item_incomplete', ['row' => $index + 1]),
                ]);
            }
            $items[] = [
                'title' => $title,
                'description' => $nullable($item['description'] ?? null),
                'max_points' => number_format((float) $points, 2, '.', ''),
            ];
        }

        return [
            'title' => trim($validated['title']),
            'description' => $nullable($validated['description'] ?? null),
            'skill_type' => $validated['skill_type'] ?? null,
            'status' => $validated['status'],
            'items' => $items,
        ];
    }

    private function saved(Request $request, string $message, int $status = 200): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], $status)
            : redirect()->route($this->routePrefix($request).'.index')->with('success', $message);
    }

    private function routePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => 'admin.assessments.rubrics',
            'teacher' => 'teacher.assessments.rubrics',
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
