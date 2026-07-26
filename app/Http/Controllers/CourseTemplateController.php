<?php

namespace App\Http\Controllers;

use App\Services\CourseTemplatePublishingService;
use App\Services\CourseTemplatePublishReadinessService;
use App\Services\CourseTemplateVersionDetailPresenter;
use App\Services\CourseTemplateVersionDuplicatingService;
use App\Services\CourseTemplateVersionMediaPresenter;
use App\Services\MediaService;
use App\Services\MediaThumbnailPresenter;
use App\Services\TrustedVideoUrlService;
use App\Support\AuditLog;
use App\Support\CourseTemplateStatus;
use App\Support\TenantContext;
use App\Support\UploadLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CourseTemplateController extends Controller
{
    public function __construct(
        private readonly CourseTemplatePublishingService $publishingService,
        private readonly CourseTemplatePublishReadinessService $readinessService,
        private readonly CourseTemplateVersionDetailPresenter $versionDetailPresenter,
        private readonly CourseTemplateVersionDuplicatingService $duplicatingService,
        private readonly CourseTemplateVersionMediaPresenter $versionMediaPresenter,
        private readonly MediaService $mediaService,
        private readonly MediaThumbnailPresenter $mediaThumbnails,
        private readonly TrustedVideoUrlService $trustedVideoUrls
    ) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $keyword = trim((string) $request->query('keyword', ''));
        $status = $request->query('status');

        if (! in_array($status, CourseTemplateStatus::VALUES, true)) {
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
                    $query->where('templates.title', 'like', '%'.$keyword.'%');
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
            ->orderBy('categories.sort_order')
            ->orderBy('categories.id')
            ->orderBy('templates.sort_order')
            ->orderBy('templates.id')
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
            'nextSortOrders' => $this->nextSortOrders($customerId),
            'initialSortOrder' => $this->nextTenantSortOrder($customerId),
            'requiredFields' => $this->requiredFields($customerId),
            'introImageMedia' => null,
            'introVideoMedia' => null,
            'introDocumentMedia' => null,
            'introVideoEmbedUrl' => null,
            'introImageThumbnail' => null,
            'introVideoThumbnail' => null,
            'introDocumentThumbnail' => null,
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function store(Request $request)
    {
        $customerId = $this->customerId();
        $validated = $this->validatedData($request, $customerId);
        $now = now();

        $templateId = DB::transaction(function () use ($customerId, $request, $validated, $now): int {
            $validated['sort_order'] = $this->nextSortOrder(
                $customerId,
                (int) $validated['category_id']
            );
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

            $this->syncIntroductionMedia($request, $templateId, $validated);

            return $templateId;
        });

        return redirect()
            ->route($this->routePrefix($request).'.edit', $templateId)
            ->with([
                'course_template_created_title' => __('lf.LF_course_template_common_created_title'),
                'course_template_created_guidance' => __('lf.LF_course_template_common_created_guidance'),
            ]);
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
        $versionsQuery = $this->versionsQuery($customerId, $id);
        $versions = (clone $versionsQuery)
            ->paginate(10, ['*'], 'history_page')
            ->withQueryString();
        $latestVersion = (clone $versionsQuery)->first();
        $currentVersion = (clone $versionsQuery)
            ->where('versions.is_current', true)
            ->first();
        $publishGraph = $this->readinessService->load($customerId, $id);
        $publishReadiness = $this->readinessService->evaluate(
            $customerId,
            $publishGraph,
        );

        $introImageMedia = $this->mediaFile($template->intro_image_media_file_id, $id, 'image', $routePrefix);
        $introVideoMedia = $this->mediaFile($template->intro_video_media_file_id, $id, 'video', $routePrefix);
        $introDocumentMedia = $this->mediaFile($template->intro_document_media_file_id, $id, 'document', $routePrefix);
        $introVideoEmbedUrl = $template->intro_video_source === 'embed'
            && $template->intro_video_embed_url
            && ! $publishReadiness->blockers()->contains(
                fn (object $issue): bool => $issue->code === 'video_state'
            )
                ? $this->trustedVideoUrls->embedUrl($template->intro_video_embed_url)
                : null;

        return view('course-templates.edit', [
            'template' => $template,
            'versions' => $versions,
            'latestVersion' => $latestVersion,
            'currentVersion' => $currentVersion,
            'publishReadiness' => $publishReadiness,
            'categories' => $this->categories(),
            'nextSortOrders' => $this->nextSortOrders($customerId),
            'initialSortOrder' => (int) $template->sort_order,
            'sections' => $publishGraph->sections,
            'directLessons' => $publishGraph->lessons->whereNull('template_section_id'),
            'lessonsBySection' => $publishGraph->lessons->whereNotNull('template_section_id')->groupBy('template_section_id'),
            'activitiesByLesson' => $this->activitiesByLesson($customerId, $id),
            'introImageMedia' => $introImageMedia,
            'introVideoMedia' => $introVideoMedia,
            'introDocumentMedia' => $introDocumentMedia,
            'introVideoEmbedUrl' => $introVideoEmbedUrl,
            'introImageThumbnail' => $this->mediaThumbnails->image($introImageMedia),
            'introVideoThumbnail' => $template->intro_video_source === 'embed'
                ? $this->mediaThumbnails->embeddedVideo(
                    $introVideoEmbedUrl ? $template->intro_video_embed_url : null
                )
                : $this->mediaThumbnails->uploadedVideo($introVideoMedia),
            'introDocumentThumbnail' => $this->mediaThumbnails->document($introDocumentMedia),
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
            ->where('sections.customer_id', $customerId)
            ->where('sections.template_version_id', $versionId)
            ->orderBy('sections.parent_version_section_id')
            ->orderBy('sections.display_order')
            ->orderBy('sections.id')
            ->select('sections.*')
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
        $presentation = $this->versionDetailPresenter->present($versionId, $lessons, $activitiesByLesson->flatten(1));
        $templateMedia = $this->versionMediaPresenter->present($version);

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
            'templateVersionMedia' => $templateMedia,
            ...$presentation,
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
        if ($template->status === CourseTemplateStatus::ARCHIVED) {
            throw ValidationException::withMessages([
                'status' => __('lf.LF_course_template_status_archived_readonly'),
            ]);
        }
        $validated = $this->validatedData($request, $customerId, $id, $template);

        DB::transaction(function () use ($customerId, $id, $request, $validated, $template): void {
            $categoryChanged = (int) $validated['category_id'] !== (int) $template->category_id;
            $sortOrderUnchanged = ! array_key_exists('sort_order', $validated)
                || (int) $validated['sort_order'] === (int) $template->sort_order;

            if ($categoryChanged && $sortOrderUnchanged) {
                $validated['sort_order'] = $this->nextSortOrder(
                    $customerId,
                    (int) $validated['category_id']
                );
            } else {
                $validated['sort_order'] ??= (int) $template->sort_order;
            }

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

            $this->syncIntroductionMedia($request, $id, $validated);
        });

        return redirect()
            ->route($this->routePrefix($request).'.edit', $id)
            ->with('success', __('lf.LF_course_template_common_updated'));
    }

    public function archive(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()?->role === 'customer_admin', 403);

        $customerId = $this->customerId();
        DB::transaction(function () use ($request, $customerId, $id): void {
            $template = DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            abort_if(! $template, 404);
            if ($template->status !== CourseTemplateStatus::INACTIVE) {
                throw ValidationException::withMessages([
                    'status' => __('lf.LF_course_template_status_archive_requires_inactive'),
                ]);
            }

            DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $id)
                ->update([
                    'status' => CourseTemplateStatus::ARCHIVED,
                    'updated_at' => now(),
                ]);

            AuditLog::record(
                $request,
                $customerId,
                'course_template_archive',
                null,
                ['template_id' => $id, 'status' => CourseTemplateStatus::INACTIVE],
                ['template_id' => $id, 'status' => CourseTemplateStatus::ARCHIVED]
            );
        });

        return redirect()
            ->route('admin.course-templates.index')
            ->with('success', __('lf.LF_course_template_status_archived_success'));
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
            'short_description',
            'description',
            'publisher_name',
            'intro_image_media_file_id',
            'intro_video_source',
            'intro_video_media_file_id',
            'intro_video_embed_url',
            'intro_document_media_file_id',
            'remove_intro_image', 'remove_intro_video', 'remove_intro_document',
            'difficulty_level',
            'estimated_minutes_per_lesson',
            'estimated_lesson_count',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'status',
            'sort_order',
        ];

        $input = array_intersect_key(
            $request->request->all(),
            array_flip($fields)
        );

        foreach (['intro_image_file', 'intro_video_file', 'intro_document_file'] as $field) {
            if ($request->hasFile($field)) {
                $input[$field] = $request->file($field);
            }
        }

        $source = $input['intro_video_source'] ?? null;
        if ($source === 'upload') {
            $input['intro_video_embed_url'] = null;
        } elseif ($source === 'embed') {
            $input['intro_video_media_file_id'] = null;
            try {
                $normalized = $this->trustedVideoUrls->normalize((string) ($input['intro_video_embed_url'] ?? ''));
                $input['intro_video_embed_url'] = $normalized['url'];
                $input['intro_video_provider'] = $normalized['provider'];
            } catch (\InvalidArgumentException) {
                // Validator reports the localized field error below.
            }
        } elseif ($source === null || $source === '') {
            $input['intro_video_source'] = null;
            $input['intro_video_media_file_id'] = null;
            $input['intro_video_embed_url'] = null;
            $input['intro_video_provider'] = null;
        } else {
            $input['intro_video_media_file_id'] = null;
            $input['intro_video_embed_url'] = null;
            $input['intro_video_provider'] = null;
        }

        $validator = Validator::make(
            $input,
            $this->validationRules($customerId, $templateId)
        );

        $validator->after(function ($validator) use ($input, $request): void {
            $source = $input['intro_video_source'] ?? null;
            if ($source === 'upload' && ! $request->hasFile('intro_video_file') && empty($input['intro_video_media_file_id'])) {
                $validator->errors()->add('intro_video_media_file_id', __('validation.required'));
            }
            if ($source === 'embed') {
                try {
                    $this->trustedVideoUrls->normalize((string) ($input['intro_video_embed_url'] ?? ''));
                } catch (\InvalidArgumentException) {
                    $validator->errors()->add('intro_video_embed_url', __('lf.LF_course_template_invalid_embed_url'));
                }
            }
        });

        $validated = $validator->validate();

        if (($validated['remove_intro_video'] ?? false) && $template) {
            if ($this->hasIntroductionVideoReplacement($request, $validated, $template)) {
                $validated['remove_intro_video'] = false;
            } else {
                $validated['intro_video_source'] = null;
                $validated['intro_video_media_file_id'] = null;
                $validated['intro_video_embed_url'] = null;
                $validated['intro_video_provider'] = null;
            }
        }

        return $validated;
    }

    private function hasIntroductionVideoReplacement(
        Request $request,
        array $validated,
        object $template
    ): bool {
        if ($request->hasFile('intro_video_file')) {
            return true;
        }

        $source = $validated['intro_video_source'] ?? null;
        if ($source !== $template->intro_video_source) {
            return $source !== null;
        }

        return match ($source) {
            'upload' => ! empty($validated['intro_video_media_file_id'])
                && (int) $validated['intro_video_media_file_id']
                    !== (int) $template->intro_video_media_file_id,
            'embed' => ($validated['intro_video_embed_url'] ?? null)
                !== $template->intro_video_embed_url,
            default => false,
        };
    }

    private function validationRules(int $customerId, ?int $templateId = null): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('core_course_categories', 'id')
                    ->where(fn ($query) => $query->where('customer_id', $customerId)),
            ],
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'publisher_name' => ['required', 'string', 'max:255'],
            'intro_video_source' => ['nullable', Rule::in(['upload', 'embed'])],
            'intro_image_media_file_id' => [
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
            'intro_video_embed_url' => ['nullable', 'string', 'max:2048'],
            'intro_video_provider' => [
                'nullable',
                Rule::in(['youtube', 'vimeo']),
            ],
            'intro_document_media_file_id' => ['nullable', 'integer', Rule::exists('media_files', 'id')->where(fn ($query) => $query->where('customer_id', $customerId)->where('file_type', 'document')->where('status', 'ready'))],
            'remove_intro_image' => ['nullable', 'boolean'],
            'remove_intro_video' => ['nullable', 'boolean'],
            'remove_intro_document' => ['nullable', 'boolean'],
            'difficulty_level' => [
                'nullable',
                Rule::in(['beginner', 'intermediate', 'advanced']),
            ],
            'estimated_minutes_per_lesson' => ['nullable', 'integer', 'min:1'],
            'estimated_lesson_count' => ['nullable', 'integer', 'min:1'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords' => ['nullable', 'string', 'max:500'],
            'status' => [
                'required',
                Rule::in(CourseTemplateStatus::EDITABLE_VALUES),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'intro_image_file' => [
                'nullable',
                'file',
                'max:'.UploadLimit::effectiveKilobytes(),
            ],
            'intro_video_file' => [
                'nullable',
                'file',
                'max:'.UploadLimit::effectiveKilobytes(),
            ],
            'intro_document_file' => ['nullable', 'file', 'max:'.UploadLimit::effectiveKilobytes()],
        ];
    }

    private function requiredFields(int $customerId, ?int $templateId = null): array
    {
        return array_values(array_diff(array_keys(array_filter(
            $this->validationRules($customerId, $templateId),
            fn (array $rules): bool => in_array('required', $rules, true)
        ))));
    }

    private function templateValues(array $validated, array $extra = []): array
    {
        return array_merge([
            'category_id' => $validated['category_id'] ?? null,
            'title' => $validated['title'],
            'short_description' => $validated['short_description'] ?? null,
            'description' => $validated['description'] ?? null,
            'publisher_name' => $validated['publisher_name'] ?? null,
            'intro_image_media_file_id' => ($validated['remove_intro_image'] ?? false) ? null : ($validated['intro_image_media_file_id'] ?? null),
            'intro_video_source' => ($validated['remove_intro_video'] ?? false) ? null : ($validated['intro_video_source'] ?? null),
            'intro_video_media_file_id' => ($validated['remove_intro_video'] ?? false) ? null : ($validated['intro_video_media_file_id'] ?? null),
            'intro_video_embed_url' => ($validated['remove_intro_video'] ?? false) ? null : ($validated['intro_video_embed_url'] ?? null),
            'intro_video_provider' => ($validated['remove_intro_video'] ?? false) ? null : ($validated['intro_video_provider'] ?? null),
            'intro_document_media_file_id' => ($validated['remove_intro_document'] ?? false) ? null : ($validated['intro_document_media_file_id'] ?? null),
            'difficulty_level' => $validated['difficulty_level'] ?? null,
            'estimated_minutes_per_lesson' => $validated['estimated_minutes_per_lesson'] ?? null,
            'estimated_lesson_count' => $validated['estimated_lesson_count'] ?? null,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
            'meta_keywords' => $validated['meta_keywords'] ?? null,
            'status' => $validated['status'],
            'sort_order' => $validated['sort_order'],
        ], $extra);
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

    private function nextSortOrders(int $customerId): array
    {
        return DB::table('core_course_categories as categories')
            ->leftJoin('core_course_templates as templates', function ($join): void {
                $join->on('templates.category_id', '=', 'categories.id')
                    ->on('templates.customer_id', '=', 'categories.customer_id');
            })
            ->where('categories.customer_id', $customerId)
            ->groupBy('categories.id')
            ->selectRaw('categories.id, COALESCE(MAX(templates.sort_order), 0) + 1 as next_sort_order')
            ->pluck('next_sort_order', 'id')
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    private function nextTenantSortOrder(int $customerId): int
    {
        $maximum = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->max('sort_order');

        return $maximum === null ? 1 : (int) $maximum + 1;
    }

    private function nextSortOrder(int $customerId, int $categoryId): int
    {
        $category = DB::table('core_course_categories')
            ->where('customer_id', $customerId)
            ->where('id', $categoryId)
            ->lockForUpdate()
            ->first(['id']);

        abort_if(! $category, 404);

        $maximum = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('category_id', $categoryId)
            ->max('sort_order');

        return $maximum === null ? 1 : (int) $maximum + 1;
    }

    private function sections(int $customerId, int $templateId)
    {
        return DB::table('core_course_template_sections as sections')
            ->where('sections.customer_id', $customerId)
            ->where('sections.template_id', $templateId)
            ->orderBy('sections.parent_section_id')
            ->orderBy('sections.display_order')
            ->orderBy('sections.id')
            ->select('sections.*')
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
            ->orderBy('id')
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
            ->orderBy('id')
            ->get();
    }

    private function activitiesByLesson(int $customerId, int $templateId)
    {
        return DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->orderBy('template_lesson_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (object $activity): object {
                $activity->view_kind = 'readonly';
                $activity->view_url = null;
                $activity->view_mime_type = null;
                $activity->view_behavior = 'readonly';

                if (in_array($activity->activity_type, ['embedded_video', 'live_class'], true)) {
                    $activity->view_url = $this->safeExternalUrl(
                        $activity->external_video_url ?? $activity->live_class_url
                    );
                    $activity->view_kind = $activity->view_url
                        ? 'external'
                        : 'none';
                    $activity->view_behavior = $activity->view_url
                        ? 'new_tab'
                        : 'none';

                    return $activity;
                }

                $media = $this->mediaService
                    ->getOwnerMedia('course_activity', (int) $activity->id)
                    ->first();

                if ($media) {
                    $activity->view_url = $this->mediaService
                        ->generateSignedUrl((int) $media->id);
                    $activity->view_kind = 'media';
                    $activity->view_mime_type = $media->mime_type;
                    $activity->view_behavior = in_array(
                        $activity->activity_type,
                        ['video', 'audio'],
                        true
                    )
                        ? 'popup'
                        : 'new_tab';
                } elseif (in_array(
                    $activity->activity_type,
                    ['video', 'audio', 'document'],
                    true
                )) {
                    $activity->view_kind = 'none';
                }

                return $activity;
            })
            ->groupBy('template_lesson_id');
    }

    private function safeExternalUrl(?string $url): ?string
    {
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            ? $url
            : null;
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
            ->orderBy('assignments.id')
            ->select(
                'assignments.*',
                'teachers.name as teacher_name',
                'teachers.email as teacher_email'
            )
            ->get();
    }

    private function versionsQuery(int $customerId, int $templateId)
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
            );
    }

    private function syncIntroductionMedia(
        Request $request,
        int $templateId,
        array $validated
    ): void {
        $values = [];
        foreach ([
            'intro_image' => ['field' => 'intro_image_file', 'type' => 'image', 'column' => 'intro_image_media_file_id'],
            'intro_video' => ['field' => 'intro_video_file', 'type' => 'video', 'column' => 'intro_video_media_file_id'],
            'intro_document' => ['field' => 'intro_document_file', 'type' => 'document', 'column' => 'intro_document_media_file_id'],
        ] as $usage => $definition) {
            $remove = (bool) ($validated['remove_'.$usage] ?? false);
            $mediaId = $remove ? null : ($validated[$definition['column']] ?? null);
            if ($request->hasFile($definition['field'])) {
                $mediaId = $this->uploadIntroductionMedia($request, $templateId, $definition['field'], $definition['type'], $usage);
            }
            if ($mediaId) {
                $this->mediaService->attachUsage((int) $mediaId, 'course_template', $templateId, $usage);
            }
            if ($remove || $request->hasFile($definition['field'])) {
                foreach ($this->mediaService->getOwnerMedia('course_template', $templateId, $usage) as $media) {
                    if ((int) $media->id !== (int) $mediaId) {
                        $this->mediaService->detachUsage((int) $media->id, 'course_template', $templateId, $usage);
                    }
                }
            }
            $values[$definition['column']] = $mediaId;
        }

        if (($validated['intro_video_source'] ?? null) !== 'upload') {
            $values['intro_video_media_file_id'] = null;
            $this->detachOwnerMedia($templateId, 'intro_video');
        }

        DB::table('core_course_templates')
            ->where('customer_id', $this->customerId())
            ->where('id', $templateId)
            ->update([
                ...$values,
                'updated_at' => now(),
            ]);
    }

    private function uploadIntroductionMedia(
        Request $request,
        int $templateId,
        string $field,
        string $fileType,
        string $usageType
    ): int {
        $mediaFile = $this->mediaService->upload(
            $request->file($field),
            [
                'file_type' => $fileType,
                'module' => 'course',
                'entity_type' => 'templates',
                'entity_id' => $templateId,
                'purpose' => $usageType,
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

    private function mediaFile(
        ?int $mediaFileId,
        int $templateId,
        string $slot,
        string $routePrefix
    ): ?object {
        if (! $mediaFileId) {
            return null;
        }

        $usageType = match ($slot) {
            'image' => 'intro_image',
            'video' => 'intro_video',
            'document' => 'intro_document',
        };
        $fileType = match ($slot) {
            'image' => 'image',
            'video' => 'video',
            'document' => 'document',
        };
        $customerId = $this->customerId();
        $media = DB::table('media_files as media')
            ->join('media_file_usages as usages', function ($join) use (
                $customerId,
                $templateId,
                $usageType
            ): void {
                $join->on('usages.media_file_id', '=', 'media.id')
                    ->where('usages.customer_id', $customerId)
                    ->where('usages.owner_type', 'course_template')
                    ->where('usages.owner_id', $templateId)
                    ->where('usages.usage_type', $usageType)
                    ->where('usages.status', 'active');
            })
            ->where('media.customer_id', $customerId)
            ->where('media.id', $mediaFileId)
            ->where('media.file_type', $fileType)
            ->where('media.status', 'ready')
            ->select('media.*')
            ->first();

        if (! $media) {
            return null;
        }

        $media->signed_url = route(
            $routePrefix.'.media.preview',
            [$templateId, $slot, $media->id]
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
