<?php

namespace App\Http\Controllers\Assessment;

use App\Http\Controllers\Controller;
use App\Services\Assessment\AssessmentCategoryService;
use App\Services\Assessment\AssessmentPassageService;
use App\Services\Assessment\AssessmentPromptMediaService;
use App\Services\Assessment\AssessmentQuestionService as Questions;
use App\Services\Assessment\AssessmentRubricService;
use App\Services\MediaService;
use App\Services\MediaThumbnailPresenter;
use App\Support\TenantContext;
use App\Support\UploadLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Câu hỏi of the Kiểm tra module, for admins (whole tenant) and teachers (their own questions).
 * Prompt, explanation and answers are plain text in phase 1; the page escapes them on output.
 */
final class AssessmentQuestionController extends Controller
{
    public function __construct(
        private readonly Questions $questions,
        private readonly AssessmentPromptMediaService $promptMedia,
        private readonly AssessmentPassageService $passages,
        private readonly MediaService $mediaService,
        private readonly MediaThumbnailPresenter $thumbnails,
        private readonly AssessmentRubricService $rubrics,
    ) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $user = $request->user();
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'question_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);
        $filters = [
            'keyword' => trim($validated['keyword'] ?? ''),
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'question_type' => in_array($validated['question_type'] ?? null, Questions::TYPES, true) ? $validated['question_type'] : null,
            'status' => in_array($validated['status'] ?? null, Questions::STATUSES, true) ? $validated['status'] : null,
        ];
        $count = fn (string $table) => fn ($query) => $query->from($table)->selectRaw('count(*)')
            ->whereColumn($table.'.question_id', 'q.id')->where($table.'.customer_id', $customerId);

        $questions = $this->questions->scoped($customerId, $user, 'q')
            ->leftJoin('core_assessment_question_contents as c', function ($join) {
                $join->on('c.question_id', '=', 'q.id')->on('c.customer_id', '=', 'q.customer_id')
                    ->where('c.locale', '=', $this->questions->locale());
            })
            ->leftJoin(AssessmentCategoryService::TABLE.' as cat', function ($join): void {
                $join->on('cat.id', '=', 'q.category_id')->on('cat.customer_id', '=', 'q.customer_id');
            })
            ->leftJoin('users as author', 'author.id', '=', 'q.created_by')
            ->leftJoin(AssessmentPassageService::TABLE.' as psg', function ($join): void {
                $join->on('psg.id', '=', 'q.passage_id')->on('psg.customer_id', '=', 'q.customer_id');
            })
            ->when($filters['keyword'] !== '', fn ($query) => $query->where('c.prompt', 'like', '%'.$filters['keyword'].'%'))
            ->when($filters['category_id'], fn ($query, $id) => $query->where('q.category_id', $id))
            ->when($filters['question_type'], fn ($query, $type) => $query->where('q.question_type', $type))
            ->when($filters['status'], fn ($query, $status) => $query->where('q.status', $status))
            ->select('q.*', 'c.prompt', 'cat.name as category_name', 'author.name as author_name', 'psg.title as passage_title')
            ->selectSub($count('core_assessment_quiz_version_questions'), 'version_count')
            ->selectSub($count('core_assessment_quiz_questions'), 'quiz_count')
            ->selectSub($count(AssessmentPromptMediaService::TABLE), 'media_count')
            ->orderByDesc('q.created_at')
            ->orderByDesc('q.id')
            ->paginate(10)
            ->withQueryString();

        return view('assessments.questions.index', [
            'questions' => $questions,
            'filters' => $filters,
            'categories' => $this->categoryOptions($customerId),
            'routePrefix' => $this->routePrefix($request),
            'showAuthor' => $user->role === 'customer_admin',
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->ajax(), 404);
        // Opened from a passage page: the new question becomes a child of that passage.
        $passageId = (int) $request->query('passage_id');
        $passage = $passageId > 0 ? $this->passages->find($this->customerId(), $request->user(), $passageId) : null;

        return $this->dialog($request, null, null, collect(), $passage);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validated($request);
        $library = $this->libraryChoices($request);
        $id = $this->questions->save($this->customerId(), $request->user(), null, $data);
        $this->syncMedia($request, $id, $data['prompt'], $library);

        return $this->saved($request, __('lf.LF_assessment_question_created'), 201);
    }

    public function edit(Request $request, int $id): View
    {
        abort_unless($request->ajax(), 404);
        ['question' => $question, 'content' => $content, 'options' => $options] = $this->questions->load($this->customerId(), $request->user(), $id);

        $passage = $question->passage_id
            ? DB::table(AssessmentPassageService::TABLE)->where('customer_id', $this->customerId())->where('id', $question->passage_id)->first()
            : null;

        return $this->dialog($request, $question, $content, $options, $passage);
    }

    /**
     * Files are stored after the question is saved (as for course categories): Media writes to storage, which a
     * database rollback could not undo. The question itself is already valid without its media.
     */
    private function syncMedia(Request $request, int $questionId, string $prompt, array $library): void
    {
        $files = [];
        $remove = [];
        foreach (AssessmentPromptMediaService::roles('question') as $role) {
            $files[$role] = $request->file($role.'_file');
            $remove[$role] = $request->boolean('remove_'.$role);
        }

        $this->promptMedia->sync(
            'question', $this->customerId(), (int) $request->user()->id, $questionId, $files, $remove, Str::limit($prompt, 120, '…'), $library,
        );
    }

    /** Media picked from the library, checked before the question is saved so a bad pick saves nothing. */
    private function libraryChoices(Request $request): array
    {
        $roles = AssessmentPromptMediaService::roles('question');

        return $this->promptMedia->libraryChoices(
            'question',
            $this->customerId(),
            $request->user(),
            collect($roles)->mapWithKeys(fn (string $role) => [$role => $request->input($role.'_library_id')])->all(),
            collect($roles)->mapWithKeys(fn (string $role) => [$role => $request->file($role.'_file')])->all(),
        );
    }

    public function update(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $data = $this->validated($request);
        $library = $this->libraryChoices($request);
        $this->questions->save($this->customerId(), $request->user(), $id, $data);
        $this->syncMedia($request, $id, $data['prompt'], $library);

        return $this->saved($request, __('lf.LF_assessment_question_updated'));
    }

    public function bulkDestroy(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ids = array_map('intval', $validated['ids']);
        $blocked = $this->questions->deleteMany($this->customerId(), $request->user(), $ids);

        if ($blocked !== null) {
            $message = __('lf.LF_assessment_question_number', ['id' => $blocked['id']]).' — '.trans_choice(
                'lf.LF_assessment_question_delete_blocked_'.$blocked['reason'], $blocked['count'], ['count' => $blocked['count']]
            );

            return $request->expectsJson()
                ? response()->json(['message' => $message], 409)
                : back()->withErrors(['question' => $message]);
        }

        $message = trans_choice('lf.LF_assessment_question_bulk_deleted', count($ids), ['count' => count($ids)]);

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : redirect()->route($this->routePrefix($request).'.index')->with('success', $message);
    }

    private function dialog(Request $request, ?object $question, ?object $content, Collection $options, ?object $passage = null): View
    {
        $type = $question?->question_type ?? 'single_choice';
        $isChoice = in_array($type, ['single_choice', 'multiple_choice'], true);

        return view('assessments.questions.dialog', [
            'question' => $question,
            'content' => $content,
            // A form always starts with two empty choices and one empty accepted answer to type into.
            'choiceOptions' => ($isChoice ? $options : collect())
                ->map(fn ($option) => ['text' => $option->option_text, 'correct' => (bool) $option->is_correct])->values()
                ->pad(2, ['text' => '', 'correct' => false]),
            'acceptedAnswers' => $type === 'short_answer' && $options->isNotEmpty() ? $options->pluck('option_text')->values() : collect(['']),
            // True/false is stored as two options, Đúng first; the correct one's position gives the answer.
            'trueFalseAnswer' => match ($type === 'true_false' ? (int) $options->first(fn ($option) => (bool) $option->is_correct)?->sort_order : 0) {
                1 => 'true',
                2 => 'false',
                default => '',
            },
            'categories' => $this->categoryOptions($this->customerId(), $question?->category_id),
            'rubrics' => $this->rubrics->options($this->customerId(), $request->user(), $question?->rubric_id),
            'promptMedia' => $this->mediaSlots($question),
            'passage' => $passage,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'question_type' => ['required', 'string', Rule::in(Questions::TYPES)],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'skill_type' => ['nullable', 'string', Rule::in(Questions::SKILLS)],
            'difficulty_level' => ['nullable', 'string', Rule::in(Questions::LEVELS)],
            'default_points' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'status' => ['required', 'string', Rule::in(Questions::STATUSES)],
            'prompt' => ['required', 'string', 'max:20000'],
            'explanation' => ['nullable', 'string', 'max:20000'],
            'reference_answer' => ['nullable', 'string', 'max:20000'],
            'options' => ['nullable', 'array', 'max:20'],
            'options.*.text' => ['nullable', 'string', 'max:2000'],
            'options.*.correct' => ['nullable', 'boolean'],
            'accepted_answers' => ['nullable', 'array', 'max:20'],
            'accepted_answers.*' => ['nullable', 'string', 'max:500'],
            'true_false_answer' => ['nullable', 'string', Rule::in(['true', 'false'])],
            'passage_id' => ['nullable', 'integer', 'min:1'],
            'rubric_id' => ['nullable', 'integer', 'min:1'],
            'prompt_image_file' => ['nullable', 'file', 'image', 'max:'.UploadLimit::effectiveKilobytes()],
            'prompt_audio_file' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,ogg', 'max:'.UploadLimit::effectiveKilobytes()],
            'remove_prompt_image' => ['nullable', 'boolean'],
            'remove_prompt_audio' => ['nullable', 'boolean'],
            'prompt_image_library_id' => ['nullable', 'integer', 'min:1'],
            'prompt_audio_library_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $type = $validated['question_type'];
        // Empty rows are left out, so a half-filled draft saves; publishing then checks what is left.
        $options = collect($validated['options'] ?? [])
            ->map(fn (array $option) => ['text' => trim((string) ($option['text'] ?? '')), 'correct' => (bool) ($option['correct'] ?? false)])
            ->filter(fn (array $option) => $option['text'] !== '')
            ->values()->all();
        $accepted = collect($validated['accepted_answers'] ?? [])
            ->map(fn ($text) => trim((string) $text))->filter(fn (string $text) => $text !== '')
            ->unique(fn (string $text) => mb_strtolower($text))->values()->all();

        if ($type === 'single_choice' && count(array_filter($options, fn ($option) => $option['correct'])) > 1) {
            throw ValidationException::withMessages(['options' => __('lf.LF_assessment_question_error_publish_single')]);
        }

        $nullable = fn (?string $value) => ($value = trim((string) $value)) === '' ? null : $value;

        return [
            'question_type' => $type,
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'skill_type' => $validated['skill_type'] ?? null,
            'difficulty_level' => $validated['difficulty_level'] ?? null,
            'default_points' => number_format((float) $validated['default_points'], 2, '.', ''),
            'status' => $validated['status'],
            'prompt' => trim($validated['prompt']),
            'explanation' => $nullable($validated['explanation'] ?? null),
            'reference_answer' => $nullable($validated['reference_answer'] ?? null),
            'options' => $options,
            'accepted_answers' => $accepted,
            'true_false_answer' => $validated['true_false_answer'] ?? null,
            'passage_id' => isset($validated['passage_id']) ? (int) $validated['passage_id'] : null,
            'rubric_id' => isset($validated['rubric_id']) ? (int) $validated['rubric_id'] : null,
        ];
    }

    /** @return array<string, array{presentation: array, name: string, url: string|null}|null> role => saved media for the slot */
    private function mediaSlots(?object $question): array
    {
        $saved = $question ? $this->promptMedia->forOwner('question', $this->customerId(), (int) $question->id) : collect();

        return collect(AssessmentPromptMediaService::roles('question'))->mapWithKeys(function (string $role) use ($saved): array {
            $media = $saved->get($role);
            if (! $media) {
                return [$role => null];
            }

            $media->signed_url = $media->status === 'ready' ? $this->mediaService->generateSignedUrl((int) $media->id) : null;

            return [$role => [
                'presentation' => $role === 'prompt_image' ? $this->thumbnails->image($media) : $this->thumbnails->audio($media),
                'name' => (string) $media->display_name,
                'url' => $media->signed_url,
            ]];
        })->all();
    }

    private function categoryOptions(int $customerId, mixed $keepId = null): Collection
    {
        return DB::table(AssessmentCategoryService::TABLE)
            ->where('customer_id', $customerId)
            ->where(fn ($query) => $query->where('status', 'active')->when($keepId, fn ($query, $id) => $query->orWhere('id', $id)))
            ->orderBy('name')
            ->get(['id', 'name']);
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
