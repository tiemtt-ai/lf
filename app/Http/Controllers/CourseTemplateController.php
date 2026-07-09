<?php

namespace App\Http\Controllers;

use App\Services\CourseTemplatePublishingService;
use App\Services\CourseTemplateVersionDuplicatingService;
use App\Services\MediaService;
use App\Support\TenantContext;
use App\Support\UploadLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseTemplateController extends Controller
{
    public function __construct(
        private readonly CourseTemplatePublishingService $publishingService,
        private readonly CourseTemplateVersionDuplicatingService $duplicatingService,
        private readonly MediaService $mediaService
    ) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $keyword = trim((string) $request->query('keyword', ''));
        $status = $request->query('status');

        if (! in_array($status, ['draft', 'active', 'archived'], true)) {
            $status = null;
        }

        $templates = DB::table('core_course_templates as templates')
            ->leftJoin('core_course_categories as categories', function ($join) use ($customerId): void {
                $join->on('categories.id', '=', 'templates.category_id')
                    ->where('categories.customer_id', '=', $customerId);
            })
            ->where('templates.customer_id', $customerId)
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($query) use ($keyword): void {
                    $query->where('templates.title', 'like', '%'.$keyword.'%')
                        ->orWhere('templates.slug', 'like', '%'.$keyword.'%');
                });
            })
            ->when($status, function ($query) use ($status): void {
                $query->where('templates.status', $status);
            })
            ->when(
                $request->user()?->role === 'teacher',
                function ($query) use ($customerId, $request): void {
                    $query->where(function ($query) use ($customerId, $request): void {
                        $query->where('templates.created_by', $request->user()->id)
                            ->orWhereExists(function ($query) use ($customerId, $request): void {
                                $query->selectRaw('1')
                                    ->from('core_course_template_teachers as assignments')
                                    ->whereColumn('assignments.template_id', 'templates.id')
                                    ->where('assignments.customer_id', $customerId)
                                    ->where('assignments.teacher_id', $request->user()->id)
                                    ->where('assignments.status', 'active');
                            });
                    });
                }
            )
            ->orderByDesc('templates.updated_at')
            ->orderBy('templates.title')
            ->select('templates.*', 'categories.name as category_name')
            ->paginate(10)
            ->withQueryString();

        return view('course-templates.index', [
            'templates' => $templates,
            'keyword' => $keyword,
            'status' => $status,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function create(Request $request): View
    {
        $customerId = $this->customerId();

        return view('course-templates.create', [
            'categories' => $this->categories(),
            'requiredFields' => $this->requiredFields($customerId),
            'coverImageMedia' => null,
            'introVideoMedia' => null,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function store(Request $request)
    {
        $customerId = $this->customerId();
        $validated = $this->validatedData($request, $customerId);
        $now = now();

        $templateId = DB::table('core_course_templates')->insertGetId(
            $this->templateValues($validated, [
                'customer_id' => $customerId,
                'lesson_count' => 0,
                'working_revision' => 1,
                'created_by' => $request->user()?->id,
                'last_version_published_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
        );

        $this->syncCoverMedia($request, $templateId, $validated);

        return redirect()
            ->route($this->routePrefix($request).'.index')
            ->with('success', __('lf.LF_course_template_common_created'));
    }

    public function show(Request $request, int $id): RedirectResponse
    {
        $this->findTemplate($this->customerId(), $id);

        return redirect()->route(
            $this->routePrefix($request).'.edit',
            $id
        );
    }

    public function edit(Request $request, int $id): View
    {
        $customerId = $this->customerId();
        $routePrefix = $this->routePrefix($request);
        $template = $this->findTemplate($customerId, $id);
        $versions = $this->versions($customerId, $id);

        return view('course-templates.edit', [
            'template' => $template,
            'versions' => $versions,
            'latestVersion' => $versions->first(),
            'currentVersion' => $versions->first(
                fn (object $version): bool => (bool) $version->is_current
            ),
            'categories' => $this->categories(),
            'sections' => $this->sections($customerId, $id),
            'directLessons' => $this->directLessons($customerId, $id),
            'lessonsBySection' => $this->lessonsBySection($customerId, $id),
            'activitiesByLesson' => $this->activitiesByLesson($customerId, $id),
            'coverImageMedia' => $this->mediaFile(
                $template->cover_image_media_file_id
            ),
            'introVideoMedia' => $this->mediaFile(
                $template->intro_video_media_file_id
            ),
            'teacherAssignments' => $this->teacherAssignments(
                $customerId,
                $id
            ),
            'requiredFields' => $this->requiredFields($customerId, $id),
            'routePrefix' => $routePrefix,
            'teacherRoutePrefix' => $routePrefix.'.teachers',
            'sectionRoutePrefix' => $routePrefix.'.sections',
            'directLessonRoutePrefix' => $routePrefix.'.lessons',
            'directActivityRoutePrefix' => $routePrefix
                .'.lessons.activities',
            'lessonRoutePrefix' => $routePrefix.'.sections.lessons',
            'activityRoutePrefix' => $routePrefix
                .'.sections.lessons.activities',
        ]);
    }

    public function publish(Request $request, int $id)
    {
        abort_unless($request->user()?->role === 'customer_admin', 403);

        $customerId = $this->customerId();
        $this->findTemplate($customerId, $id);
        $version = $this->publishingService->publish(
            $customerId,
            $id,
            (int) $request->user()->id
        );

        return redirect()
            ->route(
                $this->routePrefix($request).'.edit',
                ['id' => $id, 'tab' => 'publish']
            )
            ->with(
                'success',
                __(
                    'lf.LF_course_template_publish_success',
                    ['version' => $version->version_number]
                )
            );
    }

    public function showVersion(
        Request $request,
        int $templateId,
        int $versionId
    ): View {
        abort_unless($request->user()?->role === 'customer_admin', 403);

        $customerId = $this->customerId();
        $template = $this->findTemplate($customerId, $templateId);
        $version = DB::table('core_course_template_versions as versions')
            ->leftJoin('users as publishers', function ($join) use (
                $customerId
            ): void {
                $join->on('publishers.id', '=', 'versions.published_by')
                    ->where('publishers.customer_id', '=', $customerId);
            })
            ->where('versions.customer_id', $customerId)
            ->where('versions.template_id', $templateId)
            ->where('versions.id', $versionId)
            ->select(
                'versions.*',
                'publishers.name as published_by_name'
            )
            ->first();

        abort_if(! $version, 404);

        $sections = DB::table(
            'core_course_template_version_sections as sections'
        )
            ->leftJoin(
                'core_course_template_version_sections as parent',
                function ($join) use ($customerId, $versionId): void {
                    $join->on(
                        'parent.id',
                        '=',
                        'sections.parent_version_section_id'
                    )
                        ->where('parent.customer_id', '=', $customerId)
                        ->where(
                            'parent.template_version_id',
                            '=',
                            $versionId
                        );
                }
            )
            ->where('sections.customer_id', $customerId)
            ->where('sections.template_version_id', $versionId)
            ->orderByRaw(
                'sections.parent_version_section_id IS NOT NULL'
            )
            ->orderBy('sections.parent_version_section_id')
            ->orderBy('sections.sort_order')
            ->orderBy('sections.id')
            ->select('sections.*', 'parent.title_snapshot as parent_title')
            ->get();

        $lessons = DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $versionId)
            ->orderByRaw('version_section_id IS NOT NULL')
            ->orderBy('version_section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $activitiesByLesson = DB::table(
            'core_course_template_version_activities'
        )
            ->where('customer_id', $customerId)
            ->where('template_version_id', $versionId)
            ->orderBy('version_lesson_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('version_lesson_id');

        return view('course-template-versions.show', [
            'template' => $template,
            'version' => $version,
            'sections' => $sections,
            'directLessons' => $lessons->filter(
                fn (object $lesson): bool => $lesson->version_section_id === null
            ),
            'lessonsBySection' => $lessons
                ->filter(
                    fn (object $lesson): bool => $lesson->version_section_id !== null
                )
                ->groupBy('version_section_id'),
            'activitiesByLesson' => $activitiesByLesson,
        ]);
    }

    public function duplicateVersionToDraft(
        Request $request,
        int $templateId,
        int $versionId
    ) {
        abort_unless($request->user()?->role === 'customer_admin', 403);

        $customerId = $this->customerId();
        $this->duplicatingService->duplicateToDraft(
            $customerId,
            $templateId,
            $versionId,
            (int) $request->user()->id,
            $request->ip()
        );

        return redirect()
            ->route(
                'admin.course-templates.edit',
                ['id' => $templateId, 'tab' => 'structure']
            )
            ->with(
                'success',
                __('lf.LF_course_template_duplicate_success')
            );
    }

    public function update(Request $request, int $id)
    {
        $customerId = $this->customerId();
        $template = $this->findTemplate($customerId, $id);
        $validated = $this->validatedData($request, $customerId, $id, $template);

        $values = $this->withoutMissingSeoValues(
            $request,
            $this->templateValues($validated, [
                'working_revision' => (int) $template->working_revision + 1,
                'updated_at' => now(),
            ])
        );

        DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->update($values);

        $this->syncCoverMedia($request, $id, $validated);

        return redirect()
            ->route($this->routePrefix($request).'.edit', $id)
            ->with('success', __('lf.LF_course_template_common_updated'));
    }

    public function destroy(Request $request, int $id)
    {
        $customerId = $this->customerId();
        $this->findTemplate($customerId, $id);

        if ($this->hasReferences($customerId, $id)) {
            return back()->withErrors([
                'template' => __(
                    'lf.LF_course_template_common_delete_blocked'
                ),
            ]);
        }

        DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->delete();

        return redirect()
            ->route($this->routePrefix($request).'.index')
            ->with('success', __('lf.LF_course_template_common_deleted'));
    }

    private function validatedData(
        Request $request,
        int $customerId,
        ?int $templateId = null,
        ?object $template = null
    ): array {
        $fields = [
            'category_id',
            'title',
            'slug',
            'short_description',
            'description',
            'publisher_name',
            'cover_type',
            'cover_image_media_file_id',
            'intro_video_media_file_id',
            'remove_preview_media',
            'difficulty_level',
            'estimated_duration_minutes',
            'max_lessons',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'status',
        ];

        $input = array_intersect_key(
            $request->request->all(),
            array_flip($fields)
        );

        foreach (['cover_image_file', 'intro_video_file'] as $field) {
            if ($request->hasFile($field)) {
                $input[$field] = $request->file($field);
            }
        }

        $submittedImagePreview = (
            isset($input['cover_image_media_file_id'])
            && trim((string) $input['cover_image_media_file_id']) !== ''
        ) || $request->hasFile('cover_image_file');
        $submittedVideoPreview = (
            isset($input['intro_video_media_file_id'])
            && trim((string) $input['intro_video_media_file_id']) !== ''
        ) || $request->hasFile('intro_video_file');
        $coverType = (string) ($input['cover_type'] ?? '');

        if ($coverType === 'video') {
            unset($input['cover_image_media_file_id']);
        }

        if ($coverType === 'image') {
            unset($input['intro_video_media_file_id']);
        }

        $input['slug'] = $this->systemSlug(
            (string) $request->input('title', ''),
            $template,
            'title'
        );

        $validator = Validator::make(
            $input,
            $this->validationRules($customerId, $templateId)
        );

        $validator->after(function ($validator) use (
            $input,
            $request,
            $submittedImagePreview,
            $submittedVideoPreview
        ): void {
            $coverType = (string) ($input['cover_type'] ?? '');

            if ($submittedImagePreview && $submittedVideoPreview) {
                $validator->errors()->add(
                    'cover_type',
                    __('lf.LF_course_template_preview_media_exclusive')
                );
            }

            if ($coverType === 'image') {
                if ($request->hasFile('intro_video_file')) {
                    $validator->errors()->add(
                        'intro_video_media_file_id',
                        __('validation.prohibited', [
                            'attribute' => 'intro video',
                        ])
                    );
                }

            }

            if ($coverType === 'video') {
                if ($request->hasFile('cover_image_file')) {
                    $validator->errors()->add(
                        'cover_image_media_file_id',
                        __('validation.prohibited', [
                            'attribute' => 'cover image',
                        ])
                    );
                }

            }
        });

        return $validator->validate();
    }

    private function validationRules(int $customerId, ?int $templateId = null): array
    {
        return [
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('core_course_categories', 'id')
                    ->where(fn ($query) => $query->where('customer_id', $customerId)),
            ],
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('core_course_templates', 'slug')
                    ->where(fn ($query) => $query->where('customer_id', $customerId))
                    ->ignore($templateId),
            ],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'publisher_name' => ['nullable', 'string', 'max:255'],
            'cover_type' => ['required', Rule::in(['image', 'video'])],
            'cover_image_media_file_id' => [
                'nullable',
                'integer',
                Rule::exists('media_files', 'id')
                    ->where(fn ($query) => $query
                        ->where('customer_id', $customerId)
                        ->where('file_type', 'image')
                        ->where('status', 'ready')),
            ],
            'intro_video_media_file_id' => [
                'nullable',
                'integer',
                Rule::exists('media_files', 'id')
                    ->where(fn ($query) => $query
                        ->where('customer_id', $customerId)
                        ->where('file_type', 'video')
                        ->where('status', 'ready')),
            ],
            'remove_preview_media' => ['nullable', 'boolean'],
            'difficulty_level' => [
                'nullable',
                Rule::in(['beginner', 'intermediate', 'advanced']),
            ],
            'estimated_duration_minutes' => [
                'required',
                'integer',
                'min:0',
            ],
            'max_lessons' => ['nullable', 'integer', 'min:0'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords' => ['nullable', 'string', 'max:500'],
            'status' => [
                'required',
                Rule::in(['draft', 'active', 'archived']),
            ],
            'cover_image_file' => [
                'nullable',
                'file',
                'max:'.UploadLimit::effectiveKilobytes(),
            ],
            'intro_video_file' => [
                'nullable',
                'file',
                'max:'.UploadLimit::effectiveKilobytes(),
            ],
        ];
    }

    private function requiredFields(int $customerId, ?int $templateId = null): array
    {
        return array_values(array_diff(array_keys(array_filter(
            $this->validationRules($customerId, $templateId),
            fn (array $rules): bool => in_array('required', $rules, true)
        )), ['slug']));
    }

    private function templateValues(array $validated, array $extra = []): array
    {
        return array_merge([
            'category_id' => $validated['category_id'] ?? null,
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'short_description' => $validated['short_description'] ?? null,
            'description' => $validated['description'] ?? null,
            'publisher_name' => $validated['publisher_name'] ?? null,
            'cover_type' => $validated['cover_type'],
            'cover_image_media_file_id' => $validated['cover_type'] === 'image'
                ? (($validated['remove_preview_media'] ?? false)
                    ? null
                    : ($validated['cover_image_media_file_id'] ?? null))
                : null,
            'intro_video_media_file_id' => $validated['cover_type'] === 'video'
                ? (($validated['remove_preview_media'] ?? false)
                    ? null
                    : ($validated['intro_video_media_file_id'] ?? null))
                : null,
            'difficulty_level' => $validated['difficulty_level'] ?? null,
            'estimated_duration_minutes' => $validated['estimated_duration_minutes'],
            'max_lessons' => $validated['max_lessons'] ?? null,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
            'meta_keywords' => $validated['meta_keywords'] ?? null,
            'status' => $validated['status'],
        ], $extra);
    }

    private function systemSlug(
        string $source,
        ?object $existingRecord = null,
        string $sourceField = 'title'
    ): string {
        if ($existingRecord === null) {
            return Str::slug($source);
        }

        $currentAutoSlug = Str::slug((string) $existingRecord->{$sourceField});

        if ((string) $existingRecord->slug !== $currentAutoSlug) {
            return (string) $existingRecord->slug;
        }

        return Str::slug($source);
    }

    private function withoutMissingSeoValues(Request $request, array $values): array
    {
        foreach (['meta_title', 'meta_description', 'meta_keywords'] as $field) {
            if (! $request->has($field)) {
                unset($values[$field]);
            }
        }

        return $values;
    }

    private function categories()
    {
        return DB::table('core_course_categories')
            ->where('customer_id', $this->customerId())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    private function sections(int $customerId, int $templateId)
    {
        return DB::table('core_course_template_sections as sections')
            ->leftJoin(
                'core_course_template_sections as parent',
                function ($join) use ($customerId, $templateId): void {
                    $join->on('parent.id', '=', 'sections.parent_section_id')
                        ->where('parent.customer_id', '=', $customerId)
                        ->where('parent.template_id', '=', $templateId);
                }
            )
            ->where('sections.customer_id', $customerId)
            ->where('sections.template_id', $templateId)
            ->orderByRaw('sections.parent_section_id IS NOT NULL')
            ->orderBy('sections.parent_section_id')
            ->orderBy('sections.sort_order')
            ->orderBy('sections.title')
            ->select('sections.*', 'parent.title as parent_title')
            ->get();
    }

    private function lessonsBySection(int $customerId, int $templateId)
    {
        return DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->whereNotNull('template_section_id')
            ->orderBy('template_section_id')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->groupBy('template_section_id');
    }

    private function directLessons(int $customerId, int $templateId)
    {
        return DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->whereNull('template_section_id')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
    }

    private function activitiesByLesson(int $customerId, int $templateId)
    {
        return DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->orderBy('template_lesson_id')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->groupBy('template_lesson_id');
    }

    private function teacherAssignments(int $customerId, int $templateId)
    {
        return DB::table(
            'core_course_template_teachers as assignments'
        )
            ->join('users as teachers', function ($join) use (
                $customerId
            ): void {
                $join->on('teachers.id', '=', 'assignments.teacher_id')
                    ->where('teachers.customer_id', '=', $customerId)
                    ->where('teachers.role', '=', 'teacher');
            })
            ->where('assignments.customer_id', $customerId)
            ->where('assignments.template_id', $templateId)
            ->orderBy('assignments.sort_order')
            ->orderBy('teachers.name')
            ->select(
                'assignments.*',
                'teachers.name as teacher_name',
                'teachers.email as teacher_email'
            )
            ->get();
    }

    private function versions(int $customerId, int $templateId)
    {
        return DB::table('core_course_template_versions as versions')
            ->leftJoin('users as publishers', function ($join) use (
                $customerId
            ): void {
                $join->on('publishers.id', '=', 'versions.published_by')
                    ->where('publishers.customer_id', '=', $customerId);
            })
            ->where('versions.customer_id', $customerId)
            ->where('versions.template_id', $templateId)
            ->orderByDesc('versions.version_number')
            ->select(
                'versions.*',
                'publishers.name as published_by_name'
            )
            ->get();
    }

    private function syncCoverMedia(
        Request $request,
        int $templateId,
        array $validated
    ): void {
        $removePreviewMedia = (bool) ($validated['remove_preview_media'] ?? false);

        if ($removePreviewMedia) {
            $this->detachOwnerMedia($templateId, 'cover_image');
            $this->detachOwnerMedia($templateId, 'video');
        }

        if ($validated['cover_type'] === 'image') {
            $this->detachOwnerMedia($templateId, 'video');

            $mediaFileId = $removePreviewMedia
                ? null
                : ($validated['cover_image_media_file_id'] ?? null);

            if ($request->hasFile('cover_image_file')) {
                $this->detachOwnerMedia($templateId, 'cover_image');
                $mediaFileId = $this->uploadCoverMedia(
                    $request,
                    $templateId,
                    'cover_image_file',
                    'image',
                    'cover',
                    'cover_image'
                );
            }

            if ($mediaFileId) {
                $this->mediaService->attachUsage(
                    (int) $mediaFileId,
                    'course_template',
                    $templateId,
                    'cover_image'
                );
            }

            DB::table('core_course_templates')
                ->where('customer_id', $this->customerId())
                ->where('id', $templateId)
                ->update([
                    'cover_image_media_file_id' => $mediaFileId,
                    'intro_video_media_file_id' => null,
                    'updated_at' => now(),
                ]);

            return;
        }

        $this->detachOwnerMedia($templateId, 'cover_image');

        $mediaFileId = $removePreviewMedia
            ? null
            : ($validated['intro_video_media_file_id'] ?? null);

        if ($request->hasFile('intro_video_file')) {
            $this->detachOwnerMedia($templateId, 'video');
            $mediaFileId = $this->uploadCoverMedia(
                $request,
                $templateId,
                'intro_video_file',
                'video',
                'intro-video',
                'video'
            );
        }

        if ($mediaFileId) {
            $this->mediaService->attachUsage(
                (int) $mediaFileId,
                'course_template',
                $templateId,
                'video'
            );
        }

        DB::table('core_course_templates')
            ->where('customer_id', $this->customerId())
            ->where('id', $templateId)
            ->update([
                'cover_image_media_file_id' => null,
                'intro_video_media_file_id' => $mediaFileId,
                'updated_at' => now(),
            ]);
    }

    private function uploadCoverMedia(
        Request $request,
        int $templateId,
        string $field,
        string $fileType,
        string $purpose,
        string $usageType
    ): int {
        $mediaFile = $this->mediaService->upload(
            $request->file($field),
            [
                'file_type' => $fileType,
                'module' => 'course',
                'entity_type' => 'templates',
                'entity_id' => $templateId,
                'purpose' => $purpose,
                'display_name' => $request->input('title'),
            ],
            (int) $request->user()->id
        );

        $this->mediaService->attachUsage(
            (int) $mediaFile->id,
            'course_template',
            $templateId,
            $usageType
        );

        return (int) $mediaFile->id;
    }

    private function detachOwnerMedia(int $templateId, string $usageType): void
    {
        foreach (
            $this->mediaService->getOwnerMedia(
                'course_template',
                $templateId,
                $usageType
            ) as $media
        ) {
            $this->mediaService->detachUsage(
                (int) $media->id,
                'course_template',
                $templateId,
                $usageType
            );
        }
    }

    private function mediaFile(?int $mediaFileId): ?object
    {
        if (! $mediaFileId) {
            return null;
        }

        $media = DB::table('media_files')
            ->where('customer_id', $this->customerId())
            ->where('id', $mediaFileId)
            ->first();

        if (! $media) {
            return null;
        }

        $media->signed_url = $this->mediaService->generateSignedUrl(
            (int) $media->id
        );

        return $media;
    }

    private function findTemplate(int $customerId, int $id): object
    {
        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->first();

        abort_if(! $template, 404);
        $this->authorizeTemplateAccess($customerId, $template);

        return $template;
    }

    private function authorizeTemplateAccess(int $customerId, object $template): void
    {
        $user = request()->user();

        if ($user?->role === 'customer_admin') {
            return;
        }

        $isAssignedTeacher = $user?->role === 'teacher'
            && (
                (int) $template->created_by === (int) $user->id
                || DB::table('core_course_template_teachers')
                    ->where('customer_id', $customerId)
                    ->where('template_id', $template->id)
                    ->where('teacher_id', $user->id)
                    ->where('status', 'active')
                    ->exists()
            );

        abort_unless($isAssignedTeacher, 404);
    }

    private function hasReferences(int $customerId, int $templateId): bool
    {
        foreach ([
            'core_course_template_sections',
            'core_course_template_teachers',
            'core_course_template_lessons',
            'core_course_template_activities',
            'core_course_template_versions',
        ] as $table) {
            if (
                Schema::hasTable($table)
                && DB::table($table)
                    ->where('customer_id', $customerId)
                    ->where('template_id', $templateId)
                    ->exists()
            ) {
                return true;
            }
        }

        return false;
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        return $customerId;
    }

    private function routePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => 'admin.course-templates',
            'teacher' => 'teacher.course-templates',
            default => abort(403),
        };
    }
}
