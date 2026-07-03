<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseTemplateController extends Controller
{
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
            ->orderByDesc('templates.updated_at')
            ->orderBy('templates.title')
            ->select('templates.*', 'categories.name as category_name')
            ->get();

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
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function store(Request $request)
    {
        $customerId = $this->customerId();
        $validated = $this->validatedData($request, $customerId);
        $now = now();

        DB::table('core_course_templates')->insert(
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

        return redirect()
            ->route($this->routePrefix($request).'.index')
            ->with('success', __('lf.LF_course_template_common_created'));
    }

    public function edit(Request $request, int $id): View
    {
        $customerId = $this->customerId();
        $routePrefix = $this->routePrefix($request);

        return view('course-templates.edit', [
            'template' => $this->findTemplate($customerId, $id),
            'categories' => $this->categories(),
            'sections' => $this->sections($customerId, $id),
            'lessonsBySection' => $this->lessonsBySection($customerId, $id),
            'activitiesByLesson' => $this->activitiesByLesson($customerId, $id),
            'teacherAssignments' => $this->teacherAssignments(
                $customerId,
                $id
            ),
            'requiredFields' => $this->requiredFields($customerId, $id),
            'routePrefix' => $routePrefix,
            'teacherRoutePrefix' => $routePrefix.'.teachers',
            'sectionRoutePrefix' => $routePrefix.'.sections',
            'lessonRoutePrefix' => $routePrefix.'.sections.lessons',
            'activityRoutePrefix' => $routePrefix
                .'.sections.lessons.activities',
        ]);
    }

    public function update(Request $request, int $id)
    {
        $customerId = $this->customerId();
        $template = $this->findTemplate($customerId, $id);
        $validated = $this->validatedData($request, $customerId, $id);

        DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->update($this->templateValues($validated, [
                'working_revision' => (int) $template->working_revision + 1,
                'updated_at' => now(),
            ]));

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
        ?int $templateId = null
    ): array {
        return Validator::make(
            $request->all(),
            $this->validationRules($customerId, $templateId)
        )->validate();
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
            'thumbnail_type' => ['required', Rule::in(['image', 'video'])],
            'thumbnail_image' => ['nullable', 'string', 'max:500'],
            'thumbnail_video_source' => [
                'nullable',
                Rule::in(['youtube', 'aws']),
            ],
            'thumbnail_video_url' => ['nullable', 'string', 'max:1000'],
            'thumbnail_video_media_id' => ['nullable', 'integer', 'min:1'],
            'difficulty_level' => [
                'nullable',
                Rule::in(['beginner', 'intermediate', 'advanced']),
            ],
            'language' => ['nullable', 'string', 'max:20'],
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
        ];
    }

    private function requiredFields(int $customerId, ?int $templateId = null): array
    {
        return array_keys(array_filter(
            $this->validationRules($customerId, $templateId),
            fn (array $rules): bool => in_array('required', $rules, true)
        ));
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
            'thumbnail_type' => $validated['thumbnail_type'],
            'thumbnail_image' => $validated['thumbnail_image'] ?? null,
            'thumbnail_video_source' => $validated['thumbnail_video_source'] ?? null,
            'thumbnail_video_url' => $validated['thumbnail_video_url'] ?? null,
            'thumbnail_video_media_id' => $validated['thumbnail_video_media_id'] ?? null,
            'difficulty_level' => $validated['difficulty_level'] ?? null,
            'language' => $validated['language'] ?? null,
            'estimated_duration_minutes' => $validated['estimated_duration_minutes'],
            'max_lessons' => $validated['max_lessons'] ?? null,
            'meta_title' => $validated['meta_title'] ?? null,
            'meta_description' => $validated['meta_description'] ?? null,
            'meta_keywords' => $validated['meta_keywords'] ?? null,
            'status' => $validated['status'],
        ], $extra);
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
            ->orderBy('template_section_id')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->groupBy('template_section_id');
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

    private function findTemplate(int $customerId, int $id): object
    {
        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $id)
            ->first();

        abort_if(! $template, 404);

        return $template;
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
