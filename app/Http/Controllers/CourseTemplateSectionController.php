<?php

namespace App\Http\Controllers;

use App\Services\CourseTemplateLessonUnlockOrder;
use App\Support\CourseTemplateEditable;
use App\Support\CourseTemplateWorkingRevision;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CourseTemplateSectionController extends Controller
{
    public function __construct(
        private readonly CourseTemplateLessonUnlockOrder $unlockOrder
    ) {}

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
        abort_unless($request->ajax(), 404);

        $customerId = $this->customerId();
        $template = $this->findTemplate($customerId, $templateId);
        $parentSections = $this->parentSections($customerId, $templateId);
        $requestedParentId = $request->integer('parent_section_id') ?: null;
        $parentSectionId = $requestedParentId !== null
            && $parentSections->contains(
                fn (object $section): bool => (int) $section->id
                    === $requestedParentId
            )
                ? $requestedParentId
                : null;

        return view('course-template-sections.dialog', [
            'template' => $template,
            'parentSections' => $parentSections,
            'parentSectionId' => $parentSectionId,
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
        DB::transaction(function () use (
            $validated,
            $customerId,
            $templateId
        ): void {
            $template = CourseTemplateEditable::lock($customerId, $templateId);
            $this->assertParentUnderLock($customerId, $templateId, null, $validated['parent_section_id'] ?? null);

            $validated['display_order'] ??= $this->nextDisplayOrder(
                $customerId,
                $templateId,
                $validated['parent_section_id'] ?? null
            );
            $now = now();

            DB::table('core_course_template_sections')->insert(
                $this->sectionValues($validated, [
                    'customer_id' => $customerId,
                    'template_id' => $templateId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
            CourseTemplateWorkingRevision::bump($customerId, $templateId);
        });

        $structureUrl = route(
            $this->templateRoutePrefix($request).'.edit',
            $templateId
        ).'?tab=structure#course-template-sections';

        if ($request->expectsJson()) {
            // The structure tab is rendered by the page: the dialog reloads it, where the flashed message shows as a toast.
            $request->session()->flash('success', __('lf.LF_course_template_section_common_created'));

            return response()->json([
                'message' => __('lf.LF_course_template_section_common_created'),
                'redirect' => $structureUrl,
            ], 201);
        }

        return redirect()
            ->to($structureUrl)
            ->with('success', __('lf.LF_course_template_section_common_created'));
    }

    public function edit(
        Request $request,
        int $templateId,
        int $sectionId
    ): View {
        abort_unless($request->ajax(), 404);

        $customerId = $this->customerId();
        $template = $this->findTemplate($customerId, $templateId);
        $section = $this->findSection(
            $customerId,
            $templateId,
            $sectionId
        );

        return view('course-template-sections.dialog', [
            'template' => $template,
            'section' => $section,
            'parentSections' => $this->parentSections(
                $customerId,
                $templateId,
                array_merge(
                    [$sectionId],
                    $this->descendantIds($customerId, $templateId, $sectionId)
                )
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

        DB::transaction(function () use ($customerId, $templateId, $sectionId, $validated): void {
            $this->lockTemplate($customerId, $templateId);
            $current = $this->findSection($customerId, $templateId, $sectionId);
            // The form check ran before the lock: a move of another Section committed meanwhile can close a cycle.
            $this->assertParentUnderLock($customerId, $templateId, $sectionId, $validated['parent_section_id'] ?? null);
            CourseTemplateWorkingRevision::bump($customerId, $templateId);
            $parentChanged = (int) $current->parent_section_id !== (int) ($validated['parent_section_id'] ?? 0);
            // Without an order a Section stays where it is, or goes last under a new parent.
            $validated['display_order'] ??= $parentChanged
                ? $this->nextDisplayOrder($customerId, $templateId, $validated['parent_section_id'] ?? null)
                : $current->display_order;
            $violationsBefore = $this->unlockOrder->violations($customerId, $templateId, true);
            DB::table('core_course_template_sections')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('id', $sectionId)
                ->update($this->sectionValues($validated, [
                    'updated_at' => now(),
                ]));

            // Moving a Section moves all its Lessons against the Lessons of every other Section.
            $newViolations = array_diff_key(
                $this->unlockOrder->violations($customerId, $templateId, true),
                $violationsBefore
            );

            if ($newViolations !== []) {
                throw ValidationException::withMessages([
                    $parentChanged ? 'parent_section_id' : 'display_order' => reset($newViolations),
                ]);
            }
        });

        if ($request->expectsJson()) {
            $request->session()->flash('success', __('lf.LF_course_template_section_common_updated'));

            return response()->json([
                'message' => __('lf.LF_course_template_section_common_updated'),
                'redirect' => route(
                    $this->templateRoutePrefix($request).'.edit',
                    $templateId
                ).'?tab=structure#course-template-sections',
            ]);
        }

        return redirect()
            ->route(
                $this->routePrefix($request).'.edit',
                [$templateId, $sectionId]
            )
            ->with('success', __('lf.LF_course_template_section_common_updated'));
    }

    /**
     * The order of the Sections under one parent. A Section carries its branch and its Lessons, so the Sectioned lane can
     * end up breaking an unlock rule: that is refused here as it is on every other change of order.
     */
    public function reorder(Request $request, int $templateId): JsonResponse
    {
        abort_unless($request->expectsJson(), 404);

        $customerId = $this->customerId();
        $this->findTemplate($customerId, $templateId);

        $validated = $request->validate([
            'parent_section_id' => ['nullable', 'integer'],
            'section_ids' => ['required', 'array', 'min:1'],
            'section_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $parentId = isset($validated['parent_section_id']) ? (int) $validated['parent_section_id'] : null;
        $sectionIds = array_map('intval', array_values($validated['section_ids']));

        $applied = DB::transaction(function () use ($customerId, $templateId, $parentId, $sectionIds): bool {
            $this->lockTemplate($customerId, $templateId);

            $group = fn () => DB::table('core_course_template_sections')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->when(
                    $parentId === null,
                    fn ($query) => $query->whereNull('parent_section_id'),
                    fn ($query) => $query->where('parent_section_id', $parentId)
                );
            $currentOrders = $group()->pluck('display_order', 'id');

            if ($currentOrders->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all()
                !== collect($sectionIds)->sort()->values()->all()) {
                return false;
            }
            CourseTemplateWorkingRevision::bump($customerId, $templateId);

            $violationsBefore = $this->unlockOrder->violations($customerId, $templateId, true);
            $now = now();

            foreach ($sectionIds as $index => $sectionId) {
                if ((int) $currentOrders[$sectionId] !== $index + 1) {
                    $group()->where('id', $sectionId)->update([
                        'display_order' => $index + 1,
                        'updated_at' => $now,
                    ]);
                }
            }

            $newViolations = array_diff_key(
                $this->unlockOrder->violations($customerId, $templateId, true),
                $violationsBefore
            );

            if ($newViolations !== []) {
                throw ValidationException::withMessages([
                    'section_ids' => reset($newViolations),
                ]);
            }

            return true;
        });

        if (! $applied) {
            return response()->json([
                'message' => __('lf.LF_course_template_section_reorder_stale'),
            ], 409);
        }

        return response()->json([
            'message' => __('lf.LF_course_template_section_reorder_saved'),
        ]);
    }

    public function destroy(
        Request $request,
        int $templateId,
        int $sectionId
    ) {
        $customerId = $this->customerId();
        $this->findTemplate($customerId, $templateId);
        $this->findSection($customerId, $templateId, $sectionId);

        $deleted = DB::transaction(function () use ($customerId, $templateId, $sectionId): bool {
            $this->lockTemplate($customerId, $templateId);
            if ($this->deleteBlockReason($customerId, $templateId, $sectionId) !== null) {
                return false;
            }
            CourseTemplateWorkingRevision::bump($customerId, $templateId);
            DB::table('core_course_template_sections')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('id', $sectionId)
                ->delete();

            return true;
        });
        $structureUrl = route(
            $this->templateRoutePrefix($request).'.edit',
            $templateId
        ).'?tab=structure#course-template-sections';

        if (! $deleted) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => match ($this->deleteBlockReason($customerId, $templateId, $sectionId)) {
                        'children' => __('lf.LF_course_template_section_delete_blocked_children'),
                        'lessons' => __('lf.LF_course_template_section_delete_blocked_lessons'),
                        default => __('lf.LF_course_template_section_common_delete_blocked'),
                    },
                ], 409);
            }

            return back()->withErrors([
                'section' => __('lf.LF_course_template_section_common_delete_blocked'),
            ]);
        }

        if ($request->expectsJson()) {
            $request->session()->flash('success', __('lf.LF_course_template_section_common_deleted'));

            return response()->json([
                'message' => __('lf.LF_course_template_section_common_deleted'),
                'redirect' => $structureUrl,
            ]);
        }

        return redirect()
            ->to($structureUrl)
            ->with('success', __('lf.LF_course_template_section_common_deleted'));
    }

    private function lockTemplate(int $customerId, int $templateId): void
    {
        CourseTemplateEditable::lock($customerId, $templateId);
    }

    /**
     * Re-check the parent on the locked Template: every Section write takes that lock, so the tree read here is
     * the one the write lands on. The parent must still exist and must not be the Section or one of its descendants.
     */
    private function assertParentUnderLock(int $customerId, int $templateId, ?int $sectionId, mixed $parentId): void
    {
        $parentId = (int) $parentId;

        if ($parentId === 0) {
            return;
        }

        $parentExists = DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('id', $parentId)
            ->exists();
        $closesCycle = $sectionId !== null && (
            $parentId === $sectionId
            || in_array($parentId, $this->descendantIds($customerId, $templateId, $sectionId), true)
        );

        if (! $parentExists || $closesCycle) {
            throw ValidationException::withMessages([
                'parent_section_id' => __('lf.LF_course_template_section_common_invalid_parent'),
            ]);
        }
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

                if (
                    $parentId !== 0
                    && (
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
                    )
                ) {
                    $validator->errors()->add(
                        'parent_section_id',
                        __('lf.LF_course_template_section_common_invalid_parent')
                    );
                }

                if (
                    ! $request->boolean('allows_lessons')
                    && DB::table('core_course_template_lessons')
                        ->where('customer_id', $customerId)
                        ->where('template_id', $templateId)
                        ->where('template_section_id', $sectionId)
                        ->exists()
                ) {
                    $validator->errors()->add(
                        'allows_lessons',
                        __('lf.LF_course_template_section_common_has_lessons')
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
            'title' => ['required', 'string', 'max:255'],
            // TEXT holds 65,535 bytes; 20,000 characters stays inside it even for 3-byte UTF-8.
            'description' => ['nullable', 'string', 'max:20000'],
            'allows_lessons' => ['required', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
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
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'allows_lessons' => (bool) $validated['allows_lessons'],
            'display_order' => $validated['display_order'],
        ], $extra);
    }

    private function parentSections(
        int $customerId,
        int $templateId,
        array $excludedIds = []
    ) {
        $sections = DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->when(
                $excludedIds !== [],
                fn ($query) => $query->whereNotIn('id', $excludedIds)
            )
            ->orderBy('parent_section_id')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return collect($this->flattenSections($sections));
    }

    private function nextDisplayOrder(
        int $customerId,
        int $templateId,
        ?int $parentSectionId
    ): int {
        return (int) DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->when(
                $parentSectionId === null,
                fn ($query) => $query->whereNull('parent_section_id'),
                fn ($query) => $query->where(
                    'parent_section_id',
                    $parentSectionId
                )
            )
            ->max('display_order') + 1;
    }

    private function flattenSections($sections, ?int $parentId = null, int $depth = 0): array
    {
        $flattened = [];

        foreach (
            $sections->filter(
                fn (object $section): bool => (int) ($section->parent_section_id ?? 0)
                    === (int) ($parentId ?? 0)
            ) as $section
        ) {
            $section->depth = $depth;
            $flattened[] = $section;
            $flattened = array_merge(
                $flattened,
                $this->flattenSections($sections, (int) $section->id, $depth + 1)
            );
        }

        return $flattened;
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
                ->filter(fn (object $section): bool => in_array(
                    (int) $section->parent_section_id,
                    $parentIds,
                    true
                ))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->diff($descendantIds)
                ->values()
                ->all();

            $descendantIds = array_merge($descendantIds, $children);
            $parentIds = $children;
        } while ($parentIds !== []);

        return $descendantIds;
    }

    /** Why the section cannot be deleted: 'children' (it has sub-sections), 'lessons' (it has lessons) or null. */
    private function deleteBlockReason(
        int $customerId,
        int $templateId,
        int $sectionId
    ): ?string {
        if (DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('parent_section_id', $sectionId)
            ->exists()) {
            return 'children';
        }

        return Schema::hasTable('core_course_template_lessons')
            && DB::table('core_course_template_lessons')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('template_section_id', $sectionId)
                ->exists()
            ? 'lessons'
            : null;
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
            && DB::table('core_course_template_teachers')
                ->where('customer_id', $customerId)
                ->where('template_id', $template->id)
                ->where('teacher_id', $user->id)
                ->where('status', 'active')
                ->exists();

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
