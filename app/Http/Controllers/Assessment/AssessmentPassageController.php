<?php

namespace App\Http\Controllers\Assessment;

use App\Http\Controllers\Controller;
use App\Services\Assessment\AssessmentCategoryService;
use App\Services\Assessment\AssessmentPassageService as Passages;
use App\Services\Assessment\AssessmentPromptMediaService;
use App\Services\Assessment\AssessmentQuestionService as Questions;
use App\Services\MediaService;
use App\Services\MediaThumbnailPresenter;
use App\Support\TenantContext;
use App\Support\UploadLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ngữ liệu of the Kiểm tra module: the list and form of passages (step 1) and the passage page that manages its
 * child questions and their order (step 2). Admins see the tenant, teachers their own passages.
 */
final class AssessmentPassageController extends Controller
{
    public function __construct(
        private readonly Passages $passages,
        private readonly Questions $questions,
        private readonly AssessmentPromptMediaService $promptMedia,
        private readonly MediaService $mediaService,
        private readonly MediaThumbnailPresenter $thumbnails,
    ) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $user = $request->user();
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'skill_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);
        $filters = [
            'keyword' => trim($validated['keyword'] ?? ''),
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'skill_type' => in_array($validated['skill_type'] ?? null, Questions::SKILLS, true) ? $validated['skill_type'] : null,
            'status' => in_array($validated['status'] ?? null, Passages::STATUSES, true) ? $validated['status'] : null,
        ];
        $children = fn ($query) => $query->from(Questions::TABLE.' as child')->whereColumn('child.passage_id', 'p.id')
            ->where('child.customer_id', $customerId);

        $passages = $this->passages->scoped($customerId, $user, 'p')
            ->leftJoin(AssessmentCategoryService::TABLE.' as cat', function ($join): void {
                $join->on('cat.id', '=', 'p.category_id')->on('cat.customer_id', '=', 'p.customer_id');
            })
            ->leftJoin('users as author', 'author.id', '=', 'p.created_by')
            ->when($filters['keyword'] !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('p.title', 'like', '%'.$filters['keyword'].'%')
                ->orWhere('p.content', 'like', '%'.$filters['keyword'].'%')))
            ->when($filters['category_id'], fn ($query, $id) => $query->where('p.category_id', $id))
            ->when($filters['skill_type'], fn ($query, $skill) => $query->where('p.skill_type', $skill))
            ->when($filters['status'], fn ($query, $status) => $query->where('p.status', $status))
            ->select('p.*', 'cat.name as category_name', 'author.name as author_name')
            ->selectSub(fn ($query) => $children($query)->selectRaw('count(*)'), 'question_count')
            ->selectSub(fn ($query) => $query->from(AssessmentPromptMediaService::TABLE.' as pm')->selectRaw('count(*)')
                ->whereColumn('pm.passage_id', 'p.id')->where('pm.customer_id', $customerId), 'media_count')
            ->orderByDesc('p.created_at')
            ->orderByDesc('p.id')
            ->paginate(10)
            ->withQueryString();
        // What blocks deleting each row: a quiz draft or a published version that uses one of its questions.
        $passages->getCollection()->each(function (object $passage) use ($customerId): void {
            $passage->usage = $this->passages->usageCounts($customerId, (int) $passage->id);
        });

        return view('assessments.passages.index', [
            'passages' => $passages,
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
        $data = $this->validated($request);
        $library = $this->libraryChoices($request);
        $id = $this->passages->save($this->customerId(), $request->user(), null, $data);
        $this->syncMedia($request, $id, $data['title'], $library);
        $message = __('lf.LF_assessment_passage_created');

        // A new passage continues on its own page, where its questions are added.
        return $request->expectsJson()
            ? response()->json(['message' => $message, 'redirect' => route($this->routePrefix($request).'.show', $id)], 201)
            : redirect()->route($this->routePrefix($request).'.show', $id)->with('success', $message);
    }

    public function edit(Request $request, int $id): View
    {
        abort_unless($request->ajax(), 404);

        return $this->dialog($request, $this->passages->find($this->customerId(), $request->user(), $id));
    }

    public function update(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $data = $this->validated($request);
        $library = $this->libraryChoices($request);
        $this->passages->save($this->customerId(), $request->user(), $id, $data);
        $this->syncMedia($request, $id, $data['title'], $library);
        $message = __('lf.LF_assessment_passage_updated');

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : back()->with('success', $message);
    }

    public function show(Request $request, int $id): View
    {
        $customerId = $this->customerId();
        $passage = $this->passages->find($customerId, $request->user(), $id);

        return view('assessments.passages.show', [
            'passage' => $passage,
            'category' => $passage->category_id
                ? DB::table(AssessmentCategoryService::TABLE)->where('customer_id', $customerId)->where('id', $passage->category_id)->value('name')
                : null,
            'media' => $this->mediaSlots($passage),
            'children' => $this->passages->children($customerId, $id, $this->questions->locale()),
            'routePrefix' => $this->routePrefix($request),
            'questionRoute' => str_replace('passages', 'questions', $this->routePrefix($request)),
        ]);
    }

    public function moveQuestion(Request $request, int $id, int $questionId): RedirectResponse
    {
        $validated = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        $this->passages->moveChild($this->customerId(), $request->user(), $id, $questionId, $validated['direction'] === 'up' ? -1 : 1);

        return back();
    }

    public function bulkDestroy(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ids = array_map('intval', $validated['ids']);
        $blocked = $this->passages->deleteMany($this->customerId(), $request->user(), $ids);

        if ($blocked !== null) {
            $message = '«'.$blocked['name'].'» — '.trans_choice(
                'lf.LF_assessment_passage_delete_blocked_'.$blocked['reason'], $blocked['count'], ['count' => $blocked['count']]
            );

            return $request->expectsJson()
                ? response()->json(['message' => $message], 409)
                : back()->withErrors(['passage' => $message]);
        }

        $message = trans_choice('lf.LF_assessment_passage_bulk_deleted', count($ids), ['count' => count($ids)]);

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : redirect()->route($this->routePrefix($request).'.index')->with('success', $message);
    }

    private function dialog(Request $request, ?object $passage): View
    {
        return view('assessments.passages.dialog', [
            'passage' => $passage,
            'categories' => $this->categoryOptions($this->customerId(), $passage?->category_id),
            'promptMedia' => $this->mediaSlots($passage),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $max = 'max:'.UploadLimit::effectiveKilobytes();
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:50000'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'skill_type' => ['nullable', 'string', Rule::in(Questions::SKILLS)],
            'difficulty_level' => ['nullable', 'string', Rule::in(Questions::LEVELS)],
            'status' => ['required', 'string', Rule::in(Passages::STATUSES)],
            'prompt_image_file' => ['nullable', 'file', 'image', $max],
            'prompt_audio_file' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,ogg', $max],
            'prompt_video_file' => ['nullable', 'file', 'mimes:mp4,webm,mov', 'max:'.UploadLimit::MAX_VIDEO_KILOBYTES],
            'remove_prompt_image' => ['nullable', 'boolean'],
            'remove_prompt_audio' => ['nullable', 'boolean'],
            'remove_prompt_video' => ['nullable', 'boolean'],
            'prompt_image_library_id' => ['nullable', 'integer', 'min:1'],
            'prompt_audio_library_id' => ['nullable', 'integer', 'min:1'],
            'prompt_video_library_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $content = trim((string) ($validated['content'] ?? ''));

        return [
            'title' => trim($validated['title']),
            'content' => $content === '' ? null : $content,
            'category_id' => isset($validated['category_id']) ? (int) $validated['category_id'] : null,
            'skill_type' => $validated['skill_type'] ?? null,
            'difficulty_level' => $validated['difficulty_level'] ?? null,
            'status' => $validated['status'],
        ];
    }

    /** Files are stored after the passage is saved: storage writes cannot be rolled back with the database. */
    private function syncMedia(Request $request, int $passageId, string $title, array $library): void
    {
        $files = [];
        $remove = [];
        foreach (AssessmentPromptMediaService::roles('passage') as $role) {
            $files[$role] = $request->file($role.'_file');
            $remove[$role] = $request->boolean('remove_'.$role);
        }

        $this->promptMedia->sync('passage', $this->customerId(), (int) $request->user()->id, $passageId, $files, $remove, $title, $library);
    }

    /** Media picked from the library, checked before the passage is saved so a bad pick saves nothing. */
    private function libraryChoices(Request $request): array
    {
        $roles = AssessmentPromptMediaService::roles('passage');

        return $this->promptMedia->libraryChoices(
            'passage',
            $this->customerId(),
            $request->user(),
            collect($roles)->mapWithKeys(fn (string $role) => [$role => $request->input($role.'_library_id')])->all(),
            collect($roles)->mapWithKeys(fn (string $role) => [$role => $request->file($role.'_file')])->all(),
        );
    }

    /** @return array<string, array{presentation: array, name: string, url: string|null}|null> */
    private function mediaSlots(?object $passage): array
    {
        $saved = $passage ? $this->promptMedia->forOwner('passage', $this->customerId(), (int) $passage->id) : collect();

        return collect(AssessmentPromptMediaService::roles('passage'))->mapWithKeys(function (string $role) use ($saved): array {
            $media = $saved->get($role);
            if (! $media) {
                return [$role => null];
            }

            $media->signed_url = $media->status === 'ready' ? $this->mediaService->generateSignedUrl((int) $media->id) : null;

            return [$role => [
                'presentation' => match ($role) {
                    'prompt_image' => $this->thumbnails->image($media),
                    'prompt_video' => $this->thumbnails->uploadedVideo($media),
                    default => $this->thumbnails->audio($media),
                },
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

    private function routePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => 'admin.assessments.passages',
            'teacher' => 'teacher.assessments.passages',
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
