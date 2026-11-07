<?php

namespace App\Http\Controllers\Assessment;

use App\Http\Controllers\Controller;
use App\Services\Assessment\AssessmentCategoryService;
use App\Services\Assessment\AssessmentGraderService;
use App\Services\Assessment\AssessmentQuestionService as Questions;
use App\Services\Assessment\AssessmentQuizDraftService as Drafts;
use App\Services\Assessment\AssessmentQuizPublishService as Publisher;
use App\Services\Assessment\AssessmentQuizService as Quizzes;
use App\Services\Assessment\AssessmentRegistrationService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The quiz page of the Kiểm tra module (ADR-0025 D6–D7). Step 2: sections and the questions chosen into them (points,
 * order). Step 3: publishing a version, archiving, opening registration. Admins reach every quiz of the tenant,
 * teachers their own (the services scope each query).
 */
final class AssessmentQuizBuilderController extends Controller
{
    public function __construct(
        private readonly Quizzes $quizzes,
        private readonly Drafts $drafts,
        private readonly Publisher $publisher,
        private readonly Questions $questions,
        private readonly AssessmentGraderService $graders,
    ) {}

    public function show(Request $request, int $id): View
    {
        $customerId = $this->customerId();
        $quiz = $this->quizzes->find($customerId, $request->user(), $id);
        $items = $this->drafts->items($customerId, $id, $this->questions->locale());
        $versions = $this->publisher->versions($customerId, $id);
        $current = $versions->firstWhere('id', $quiz->current_version_id);

        return view('assessments.quizzes.show', [
            'quiz' => $quiz,
            'category' => $quiz->category_id
                ? DB::table(AssessmentCategoryService::TABLE)->where('customer_id', $customerId)->where('id', $quiz->category_id)->value('name')
                : null,
            'sections' => $this->drafts->sections($customerId, $id),
            'items' => $items->groupBy('quiz_section_id'),
            'itemCount' => $items->count(),
            'totalPoints' => $items->sum(fn (object $item) => (float) $item->points),
            'versions' => $versions,
            'currentVersion' => $current,
            // The draft changed after the current version was published (settings, sections or questions).
            'hasChanges' => $current !== null && $quiz->content_updated_at !== null
                && strtotime((string) $quiz->content_updated_at) > strtotime((string) $current->published_at),
            'activeRegistrations' => DB::table(AssessmentRegistrationService::TABLE)
                ->where('customer_id', $customerId)->where('quiz_id', $id)->where('status', 'active')->count(),
            'quizGraders' => $this->graders->quizGraders($customerId, $id),
            'inheritedGraders' => $this->graders->inheritedQuizGraders($customerId, $quiz->category_id ? (int) $quiz->category_id : null),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    // ---- Sections ----

    public function createSection(Request $request, int $id): View
    {
        abort_unless($request->ajax(), 404);
        $this->quizzes->find($this->customerId(), $request->user(), $id);

        return $this->sectionDialog($request, $id, null);
    }

    public function storeSection(Request $request, int $id): JsonResponse
    {
        $this->drafts->saveSection($this->customerId(), $request->user(), $id, null, $this->validatedSection($request));

        return response()->json(['message' => __('lf.LF_assessment_quiz_section_created')], 201);
    }

    public function editSection(Request $request, int $id, int $sectionId): View
    {
        abort_unless($request->ajax(), 404);
        $customerId = $this->customerId();
        $this->quizzes->find($customerId, $request->user(), $id);

        return $this->sectionDialog($request, $id, $this->drafts->section($customerId, $id, $sectionId));
    }

    public function updateSection(Request $request, int $id, int $sectionId): JsonResponse
    {
        $this->drafts->saveSection($this->customerId(), $request->user(), $id, $sectionId, $this->validatedSection($request));

        return response()->json(['message' => __('lf.LF_assessment_quiz_section_updated')]);
    }

    public function destroySection(Request $request, int $id, int $sectionId): JsonResponse
    {
        try {
            $this->drafts->deleteSection($this->customerId(), $request->user(), $id, $sectionId);
        } catch (ValidationException $exception) {
            // The row-delete button shows a 409 reason as it is.
            return response()->json(['message' => collect($exception->errors())->flatten()->first()], 409);
        }

        return response()->json(['message' => __('lf.LF_assessment_quiz_section_deleted')]);
    }

    public function moveSection(Request $request, int $id, int $sectionId): RedirectResponse
    {
        $this->drafts->moveSection($this->customerId(), $request->user(), $id, $sectionId, $this->direction($request));

        return back();
    }

    // ---- Chosen questions ----

    public function pickQuestions(Request $request, int $id): View
    {
        abort_unless($request->ajax(), 404);
        $customerId = $this->customerId();
        $user = $request->user();
        $this->quizzes->find($customerId, $user, $id);

        return view('assessments.quizzes.picker', [
            'quizId' => $id,
            'sections' => $this->drafts->sections($customerId, $id),
            'sectionId' => (int) $request->query('section_id'),
            'questions' => $this->drafts->availableQuestions($customerId, $user, $id, $this->questions->locale()),
            'passages' => $this->drafts->availablePassages($customerId, $user, $id),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    /** Ghép tự động (ADR-0025 D6a): category and the skill × level table; the preview fills the list to confirm. */
    public function autoQuestions(Request $request, int $id): View
    {
        abort_unless($request->ajax(), 404);
        $customerId = $this->customerId();
        $this->quizzes->find($customerId, $request->user(), $id);

        return view('assessments.quizzes.auto', [
            'quizId' => $id,
            'sections' => $this->drafts->sections($customerId, $id),
            'sectionId' => (int) $request->query('section_id'),
            'categories' => DB::table(AssessmentCategoryService::TABLE)->where('customer_id', $customerId)
                ->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'skills' => Drafts::AUTO_SKILLS,
            'levels' => Drafts::AUTO_LEVELS,
            'max' => Drafts::AUTO_MAX,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function autoPreview(Request $request, int $id): JsonResponse
    {
        $rules = [
            'category_id' => ['nullable', 'integer', 'min:1'],
            'with_children' => ['nullable', 'boolean'],
            'counts' => ['nullable', 'array'],
        ];
        foreach (Drafts::AUTO_SKILLS as $skill) {
            foreach (Drafts::AUTO_LEVELS as $level) {
                $rules['counts.'.$skill.'.'.$level] = ['nullable', 'integer', 'min:0', 'max:'.Drafts::AUTO_MAX];
            }
        }
        $validated = $request->validate($rules);
        $counts = [];
        foreach (Drafts::AUTO_SKILLS as $skill) {
            foreach (Drafts::AUTO_LEVELS as $level) {
                $counts[$skill][$level] = (int) ($validated['counts'][$skill][$level] ?? 0);
            }
        }
        $total = array_sum(array_map('array_sum', $counts));
        if ($total === 0 || $total > Drafts::AUTO_MAX) {
            $message = $total === 0
                ? __('lf.LF_assessment_quiz_auto_error_none')
                : __('lf.LF_assessment_quiz_auto_error_max', ['max' => Drafts::AUTO_MAX]);

            return response()->json(['message' => $message, 'errors' => ['counts' => [$message]]], 422);
        }

        $result = $this->drafts->autoPick($this->customerId(), $request->user(), $id,
            isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            $request->boolean('with_children'), $counts, $this->questions->locale());
        $trim = fn ($points) => rtrim(rtrim(number_format((float) $points, 2, '.', ''), '0'), '.');

        return response()->json([
            'questions' => $result['questions']->map(fn (object $question) => [
                'id' => (int) $question->id,
                'prompt' => (string) $question->prompt,
                'meta' => implode(' · ', [
                    __('lf.LF_assessment_question_type_'.$question->question_type),
                    __('lf.LF_assessment_question_skill_'.$question->skill_type),
                    __('lf.LF_assessment_question_level_'.$question->difficulty_level),
                    $question->category_name ?? __('lf.LF_assessment_question_no_category'),
                ]),
                'points' => __('lf.LF_assessment_quiz_points_value', ['points' => $trim($question->default_points)]),
            ])->values(),
            // Cells that could not be filled, said in words: "Đọc · Dễ: cần 5, có 3".
            'short' => collect($result['cells'])->filter(fn (array $cell) => $cell['found'] < $cell['wanted'])
                ->map(fn (array $cell) => __('lf.LF_assessment_quiz_auto_short', [
                    'cell' => __('lf.LF_assessment_question_skill_'.$cell['skill']).' · '.__('lf.LF_assessment_question_level_'.$cell['level']),
                    'wanted' => $cell['wanted'],
                    'found' => $cell['found'],
                ]))->values(),
            'wanted' => $total,
        ]);
    }

    public function addQuestions(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'quiz_section_id' => ['required', 'integer'],
            'question_ids' => ['nullable', 'array', 'max:500'],
            'question_ids.*' => ['integer', 'distinct'],
            'passage_ids' => ['nullable', 'array', 'max:100'],
            'passage_ids.*' => ['integer', 'distinct'],
        ]);
        $questionIds = array_map('intval', $validated['question_ids'] ?? []);
        $passageIds = array_map('intval', $validated['passage_ids'] ?? []);

        if ($questionIds === [] && $passageIds === []) {
            return response()->json(['message' => __('lf.LF_assessment_quiz_add_error_none'), 'errors' => [
                'question_ids' => [__('lf.LF_assessment_quiz_add_error_none')],
            ]], 422);
        }

        $count = $this->drafts->addQuestions($this->customerId(), $request->user(), $id, (int) $validated['quiz_section_id'], $questionIds, $passageIds);

        return response()->json(['message' => trans_choice('lf.LF_assessment_quiz_questions_added', $count, ['count' => $count])], 201);
    }

    public function editItem(Request $request, int $id, int $itemId): View
    {
        abort_unless($request->ajax(), 404);
        $customerId = $this->customerId();
        $this->quizzes->find($customerId, $request->user(), $id);
        $item = $this->drafts->item($customerId, $id, $itemId);

        return view('assessments.quizzes.item-dialog', [
            'quizId' => $id,
            'item' => $item,
            'prompt' => DB::table('core_assessment_question_contents')->where('customer_id', $customerId)
                ->where('question_id', $item->question_id)->where('locale', $this->questions->locale())->value('prompt'),
            'sections' => $this->drafts->sections($customerId, $id),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function updateItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'points' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999'],
            'quiz_section_id' => ['required', 'integer'],
        ]);
        $this->drafts->updateItem($this->customerId(), $request->user(), $id, $itemId, (string) $validated['points'], (int) $validated['quiz_section_id']);

        return response()->json(['message' => __('lf.LF_assessment_quiz_item_updated')]);
    }

    public function moveItem(Request $request, int $id, int $itemId): RedirectResponse
    {
        $this->drafts->moveItem($this->customerId(), $request->user(), $id, $itemId, $this->direction($request));

        return back();
    }

    public function removeItems(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ids = array_map('intval', $validated['ids']);
        $this->drafts->removeItems($this->customerId(), $request->user(), $id, $ids);

        return response()->json(['message' => trans_choice('lf.LF_assessment_quiz_items_removed', count($ids), ['count' => count($ids)])]);
    }

    // ---- Publishing and lifecycle ----

    public function publish(Request $request, int $id): RedirectResponse
    {
        $versionId = $this->publisher->publish($this->customerId(), $request->user(), $id);
        $number = DB::table(Quizzes::VERSIONS_TABLE)->where('id', $versionId)->value('version_no');

        return back()->with('success', __('lf.LF_assessment_quiz_published', ['number' => $number]));
    }

    public function archive(Request $request, int $id): RedirectResponse
    {
        $this->publisher->archive($this->customerId(), $request->user(), $id);

        return back()->with('success', __('lf.LF_assessment_quiz_archived'));
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $this->publisher->restore($this->customerId(), $request->user(), $id);

        return back()->with('success', __('lf.LF_assessment_quiz_restored'));
    }

    public function registration(Request $request, int $id): RedirectResponse
    {
        $open = $request->validate(['open' => ['required', 'boolean']])['open'];
        $this->publisher->setRegistration($this->customerId(), $request->user(), $id, (bool) $open);

        return back()->with('success', __($open ? 'lf.LF_assessment_quiz_registration_opened' : 'lf.LF_assessment_quiz_registration_closed'));
    }

    private function sectionDialog(Request $request, int $quizId, ?object $section): View
    {
        return view('assessments.quizzes.section-dialog', [
            'quizId' => $quizId,
            'section' => $section,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    // ---- Giao chấm đề (ADR-0027 D2) ----

    public function editGraders(Request $request, int $id): View
    {
        abort_unless($request->ajax(), 404);
        $customerId = $this->customerId();
        $quiz = $this->quizzes->find($customerId, $request->user(), $id);

        return view('assessments.partials.graders-dialog', [
            'title' => __('lf.LF_assessment_grader_quiz_title'),
            'help' => __('lf.LF_assessment_grader_quiz_help'),
            'action' => route($this->routePrefix($request).'.graders.update', $id),
            'teachers' => $this->graders->teachers($customerId)->orderBy('name')->limit(1000)->get(['id', 'name', 'email']),
            'chosen' => $this->graders->quizGraders($customerId, $id)->pluck('id')->map(fn ($v) => (int) $v)->all(),
            'inherited' => $this->graders->inheritedQuizGraders($customerId, $quiz->category_id ? (int) $quiz->category_id : null),
        ]);
    }

    public function updateGraders(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $customerId = $this->customerId();
        $this->quizzes->find($customerId, $request->user(), $id);
        $validated = $request->validate(['user_ids' => ['nullable', 'array', 'max:200'], 'user_ids.*' => ['integer', 'distinct']]);
        $this->graders->setQuizGraders($customerId, $request->user(), $id, $validated['user_ids'] ?? []);

        return $request->expectsJson()
            ? response()->json(['message' => __('lf.LF_assessment_grader_saved')])
            : redirect()->route($this->routePrefix($request).'.show', ['id' => $id, 'tab' => 'graders'])->with('success', __('lf.LF_assessment_grader_saved'));
    }

    /** @return array{title: string, description: string|null, skill_type: string|null} */
    private function validatedSection(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'skill_type' => ['nullable', 'string', Rule::in(Questions::SKILLS)],
        ]);
        $description = trim((string) ($validated['description'] ?? ''));

        return [
            'title' => trim($validated['title']),
            'description' => $description === '' ? null : $description,
            'skill_type' => $validated['skill_type'] ?? null,
        ];
    }

    private function direction(Request $request): int
    {
        return $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'] === 'up' ? -1 : 1;
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
