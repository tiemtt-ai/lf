<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseTemplateSectionController extends Controller
{
    public function index(Request $request, int $templateId)
    {
        $this->findTemplate($this->customerId(), $templateId);

        return redirect()->to(
            route($this->templateRoutePrefix($request).'.edit', $templateId)
            .'?tab=structure#course-template-sections'
        );
    }

    public function create(Request $request, int $templateId): View
    {
        $customerId = $this->customerId();

        return view('course-template-sections.create', [
            'template' => $this->findTemplate($customerId, $templateId),
            'parentSections' => $this->parentSections($customerId, $templateId),
            'requiredFields' => $this->requiredFields(
                $customerId,
                $templateId
            ),
            'routePrefix' => $this->routePrefix($request),
            'templateRoutePrefix' => $this->templateRoutePrefix($request),
        ]);
    }

    public function store(Request $request, int $templateId)
    {
        $customerId = $this->customerId();
        $this->findTemplate($customerId, $templateId);
        $validated = $this->validatedData(
            $request,
            $customerId,
            $templateId
        );
        $now = now();

        DB::table('core_course_template_sections')->insert(
            $this->sectionValues($validated, [
                'customer_id' => $customerId,
                'template_id' => $templateId,
                'total_lessons' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])
        );

        return redirect()
            ->to(
                route(
                    $this->templateRoutePrefix($request).'.edit',
                    $templateId
                ).'?tab=structure#course-template-sections'
            )
            ->with('success', __('lf.LF_course_template_section_common_created'));
    }

    public function edit(
        Request $request,
        int $templateId,
        int $sectionId
    ): View {
        $customerId = $this->customerId();
        $template = $this->findTemplate($customerId, $templateId);
        $section = $this->findSection(
            $customerId,
            $templateId,
            $sectionId
        );
        $excludedIds = array_merge(
            [$sectionId],
            $this->descendantIds($customerId, $templateId, $sectionId)
        );

        return view('course-template-sections.edit', [
            'template' => $template,
            'section' => $section,
            'parentSections' => $this->parentSections(
                $customerId,
                $templateId,
                $excludedIds
            ),
            'requiredFields' => $this->requiredFields(
                $customerId,
                $templateId,
                $sectionId
            ),
            'routePrefix' => $this->routePrefix($request),
            'templateRoutePrefix' => $this->templateRoutePrefix($request),
        ]);
    }

    public function update(
        Request $request,
        int $templateId,
        int $sectionId
    ) {
        $customerId = $this->customerId();
        $this->findTemplate($customerId, $templateId);
        $this->findSection($customerId, $templateId, $sectionId);
        $validated = $this->validatedData(
            $request,
            $customerId,
            $templateId,
            $sectionId
        );

        DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('id', $sectionId)
            ->update($this->sectionValues($validated, [
                'updated_at' => now(),
            ]));

        return redirect()
            ->route(
                $this->routePrefix($request).'.edit',
                [$templateId, $sectionId]
            )
            ->with('success', __('lf.LF_course_template_section_common_updated'));
    }

    public function destroy(
        Request $request,
        int $templateId,
        int $sectionId
    ) {
        $customerId = $this->customerId();
        $this->findTemplate($customerId, $templateId);
        $this->findSection($customerId, $templateId, $sectionId);

        if ($this->hasReferences($customerId, $templateId, $sectionId)) {
            return back()->withErrors([
                'section' => __(
                    'lf.LF_course_template_section_common_delete_blocked'
                ),
            ]);
        }

        DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('id', $sectionId)
            ->delete();

        return redirect()
            ->to(
                route(
                    $this->templateRoutePrefix($request).'.edit',
                    $templateId
                ).'?tab=structure#course-template-sections'
            )
            ->with('success', __('lf.LF_course_template_section_common_deleted'));
    }

    private function validatedData(
        Request $request,
        int $customerId,
        int $templateId,
        ?int $sectionId = null
    ): array {
        $validator = Validator::make(
            $request->all(),
            $this->validationRules(
                $customerId,
                $templateId,
                $sectionId
            )
        );

        if ($sectionId !== null) {
            $validator->after(function ($validator) use (
                $request,
                $customerId,
                $templateId,
                $sectionId
            ): void {
                $parentId = $request->integer('parent_section_id');

                if ($parentId === 0) {
                    return;
                }

                if (
                    $parentId === $sectionId
                    || in_array(
                        $parentId,
                        $this->descendantIds(
                            $customerId,
                            $templateId,
                            $sectionId
                        ),
                        true
                    )
                ) {
                    $validator->errors()->add(
                        'parent_section_id',
                        __('lf.LF_course_template_section_common_invalid_parent')
                    );
                }
            });
        }

        return $validator->validate();
    }

    private function validationRules(
        int $customerId,
        int $templateId,
        ?int $sectionId = null
    ): array {
        return [
            'parent_section_id' => [
                'nullable',
                'integer',
                Rule::exists('core_course_template_sections', 'id')
                    ->where(fn ($query) => $query
                        ->where('customer_id', $customerId)
                        ->where('template_id', $templateId)),
            ],
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('core_course_template_sections', 'code')
                    ->where(fn ($query) => $query
                        ->where('customer_id', $customerId)
                        ->where('template_id', $templateId))
                    ->ignore($sectionId),
            ],
            'title' => ['required', 'string', 'max:255'],
            'short_title' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'thumbnail_file_id' => ['nullable', 'integer', 'min:1'],
            'sort_order' => [
                'required',
                'integer',
                'min:0',
                Rule::unique('core_course_template_sections', 'sort_order')
                    ->where(function ($query) use (
                        $customerId,
                        $templateId
                    ) {
                        $query
                            ->where('customer_id', $customerId)
                            ->where('template_id', $templateId);

                        $parentId = request()->integer('parent_section_id');

                        return $parentId === 0
                            ? $query->whereNull('parent_section_id')
                            : $query->where('parent_section_id', $parentId);
                    })
                    ->ignore($sectionId),
            ],
            'is_required' => ['required', 'boolean'],
            'unlock_rule' => [
                'required',
                Rule::in([
                    'immediate',
                    'after_previous_section',
                    'manual',
                ]),
            ],
            'estimated_duration_minutes' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'status' => [
                'required',
                Rule::in(['active', 'inactive', 'archived']),
            ],
            'metadata' => ['nullable', 'json'],
        ];
    }

    private function requiredFields(
        int $customerId,
        int $templateId,
        ?int $sectionId = null
    ): array {
        return array_keys(array_filter(
            $this->validationRules($customerId, $templateId, $sectionId),
            fn (array $rules): bool => in_array('required', $rules, true)
        ));
    }

    private function sectionValues(array $validated, array $extra = []): array
    {
        return array_merge([
            'parent_section_id' => $validated['parent_section_id'] ?? null,
            'code' => $validated['code'] ?? null,
            'title' => $validated['title'],
            'short_title' => $validated['short_title'] ?? null,
            'description' => $validated['description'] ?? null,
            'thumbnail_file_id' => $validated['thumbnail_file_id'] ?? null,
            'sort_order' => $validated['sort_order'],
            'is_required' => (bool) $validated['is_required'],
            'unlock_rule' => $validated['unlock_rule'],
            'estimated_duration_minutes' => $validated['estimated_duration_minutes'] ?? null,
            'status' => $validated['status'],
            'metadata' => $validated['metadata'] ?? null,
        ], $extra);
    }

    private function parentSections(
        int $customerId,
        int $templateId,
        array $excludedIds = []
    ) {
        return DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->when(
                $excludedIds !== [],
                fn ($query) => $query->whereNotIn('id', $excludedIds)
            )
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
    }

    private function descendantIds(
        int $customerId,
        int $templateId,
        int $sectionId
    ): array {
        $sections = DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->select('id', 'parent_section_id')
            ->get();
        $descendantIds = [];
        $parentIds = [$sectionId];

        do {
            $children = $sections
                ->filter(fn ($section) => in_array(
                    (int) $section->parent_section_id,
                    $parentIds,
                    true
                ))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->diff($descendantIds)
                ->values()
                ->all();

            $descendantIds = array_merge($descendantIds, $children);
            $parentIds = $children;
        } while ($parentIds !== []);

        return $descendantIds;
    }

    private function hasReferences(
        int $customerId,
        int $templateId,
        int $sectionId
    ): bool {
        if (
            DB::table('core_course_template_sections')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('parent_section_id', $sectionId)
                ->exists()
        ) {
            return true;
        }

        return Schema::hasTable('core_course_template_lessons')
            && DB::table('core_course_template_lessons')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('template_section_id', $sectionId)
                ->exists();
    }

    private function findTemplate(int $customerId, int $templateId): object
    {
        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
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

    private function findSection(
        int $customerId,
        int $templateId,
        int $sectionId
    ): object {
        $section = DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('id', $sectionId)
            ->first();

        abort_if(! $section, 404);

        return $section;
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        return $customerId;
    }

    private function routePrefix(Request $request): string
    {
        return $this->templateRoutePrefix($request).'.sections';
    }

    private function templateRoutePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => 'admin.course-templates',
            'teacher' => 'teacher.course-templates',
            default => abort(403),
        };
    }
}
