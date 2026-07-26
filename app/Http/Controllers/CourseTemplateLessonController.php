<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CourseTemplateLessonController extends Controller
{
    public function index(
        Request $request,
        int $templateId,
        int $sectionId
    ) {
        $customerId = $this->customerId();
        $this->findTemplate($customerId, $templateId);
        $this->findSection($customerId, $templateId, $sectionId);

        return redirect()->to(
            route($this->templateRoutePrefix($request).'.edit', $templateId)
            .$this->lessonAnchor($sectionId)
        );
    }

    public function create(
        Request $request,
        int $templateId,
        int $sectionId
    ): View {
        return $this->createView($request, $templateId, $sectionId);
    }

    public function createDirect(
        Request $request,
        int $templateId
    ): View {
        return $this->createView($request, $templateId, null);
    }

    public function store(
        Request $request,
        int $templateId,
        int $sectionId
    ) {
        return $this->storeLesson($request, $templateId, $sectionId);
    }

    public function storeDirect(Request $request, int $templateId)
    {
        return $this->storeLesson($request, $templateId, null);
    }

    public function edit(
        Request $request,
        int $templateId,
        int $sectionId,
        int $lessonId
    ): View {
        return $this->editView(
            $request,
            $templateId,
            $lessonId,
            $sectionId
        );
    }

    public function editDirect(
        Request $request,
        int $templateId,
        int $lessonId
    ): View {
        return $this->editView($request, $templateId, $lessonId, null);
    }

    public function update(
        Request $request,
        int $templateId,
        int $sectionId,
        int $lessonId
    ) {
        return $this->updateLesson(
            $request,
            $templateId,
            $lessonId,
            $sectionId
        );
    }

    public function updateDirect(
        Request $request,
        int $templateId,
        int $lessonId
    ) {
        return $this->updateLesson($request, $templateId, $lessonId, null);
    }

    public function destroy(
        Request $request,
        int $templateId,
        int $sectionId,
        int $lessonId
    ) {
        return $this->destroyLesson(
            $request,
            $templateId,
            $lessonId,
            $sectionId
        );
    }

    public function destroyDirect(
        Request $request,
        int $templateId,
        int $lessonId
    ) {
        return $this->destroyLesson($request, $templateId, $lessonId, null);
    }

    private function createView(
        Request $request,
        int $templateId,
        ?int $sectionId
    ): View {
        $customerId = $this->customerId();

        return view('course-template-lessons.create', [
            'template' => $this->findTemplate($customerId, $templateId),
            'section' => $sectionId === null
                ? null
                : $this->findLessonContainerSection(
                    $customerId,
                    $templateId,
                    $sectionId
                ),
            'prerequisiteLessons' => $this->prerequisiteLessons(
                $customerId,
                $templateId,
                $sectionId
            ),
            'requiredFields' => $this->requiredFields(
                $customerId,
                $templateId
            ),
            'suggestedSortOrder' => $this->nextSortOrder(
                $customerId,
                $templateId,
                $sectionId
            ),
            'routePrefix' => $this->routePrefix($request, $sectionId),
            'templateRoutePrefix' => $this->templateRoutePrefix($request),
        ]);
    }

    private function storeLesson(
        Request $request,
        int $templateId,
        ?int $sectionId
    ) {
        $customerId = $this->customerId();
        $this->findTemplate($customerId, $templateId);

        if ($sectionId !== null) {
            $this->findLessonContainerSection($customerId, $templateId, $sectionId);
        }

        $validated = $this->validatedData(
            $request,
            $customerId,
            $templateId,
            null,
            $sectionId
        );

        $lessonId = DB::transaction(function () use (
            $request,
            $validated,
            $customerId,
            $templateId,
            $sectionId
        ): int {
            $template = DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $templateId)
                ->lockForUpdate()
                ->first();

            abort_if(! $template, 404);

            if ($sectionId !== null) {
                $sectionAllowsLessons = DB::table('core_course_template_sections')
                    ->where('customer_id', $customerId)
                    ->where('template_id', $templateId)
                    ->where('id', $sectionId)
                    ->lockForUpdate()
                    ->value('allows_lessons');

                if (! $sectionAllowsLessons) {
                    throw ValidationException::withMessages([
                        'template_section_id' => __(
                            'lf.LF_course_template_section_common_lessons_not_allowed'
                        ),
                    ]);
                }
            }

            $validated['sort_order'] ??= $this->nextSortOrder(
                $customerId,
                $templateId,
                $sectionId
            );
            $now = now();

            $lessonId = DB::table('core_course_template_lessons')->insertGetId(
                $this->lessonValues($validated, [
                    'customer_id' => $customerId,
                    'template_id' => $templateId,
                    'template_section_id' => $sectionId,
                    'duration_seconds' => 0,
                    'activity_count' => 0,
                    'created_by' => $request->user()?->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
            $this->syncPrerequisites(
                $customerId,
                $templateId,
                $lessonId,
                $validated
            );

            return $lessonId;
        });

        return redirect()
            ->to(
                route(
                    $this->templateRoutePrefix($request).'.edit',
                    $templateId
                ).$this->lessonAnchor($sectionId)
            )
            ->with('success', __('lf.LF_course_template_lesson_common_created'));
    }

    private function editView(
        Request $request,
        int $templateId,
        int $lessonId,
        ?int $sectionId
    ): View {
        $customerId = $this->customerId();

        return view('course-template-lessons.edit', [
            'template' => $this->findTemplate($customerId, $templateId),
            'section' => $sectionId === null
                ? null
                : $this->findSection(
                    $customerId,
                    $templateId,
                    $sectionId
                ),
            'lesson' => $this->findLesson(
                $customerId,
                $templateId,
                $sectionId,
                $lessonId
            ),
            'selectedPrerequisiteIds' => DB::table(
                'core_course_template_lesson_prerequisites'
            )
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('lesson_id', $lessonId)
                ->orderBy('sort_order')
                ->pluck('prerequisite_lesson_id')
                ->map(fn ($id): string => (string) $id)
                ->all(),
            'prerequisiteLessons' => $this->prerequisiteLessons(
                $customerId,
                $templateId,
                $sectionId,
                $lessonId
            ),
            'requiredFields' => $this->requiredFields(
                $customerId,
                $templateId,
                $lessonId
            ),
            'routePrefix' => $this->routePrefix($request, $sectionId),
            'templateRoutePrefix' => $this->templateRoutePrefix($request),
        ]);
    }

    private function updateLesson(
        Request $request,
        int $templateId,
        int $lessonId,
        ?int $sectionId
    ) {
        $customerId = $this->customerId();
        $this->findTemplate($customerId, $templateId);

        if ($sectionId !== null) {
            $this->findSection($customerId, $templateId, $sectionId);
        }

        $this->findLesson(
            $customerId,
            $templateId,
            $sectionId,
            $lessonId
        );
        $validated = $this->validatedData(
            $request,
            $customerId,
            $templateId,
            $lessonId,
            $sectionId
        );

        DB::transaction(function () use ($customerId, $templateId, $sectionId, $lessonId, $validated): void {
            $this->lockTemplate($customerId, $templateId);
            DB::table('core_course_template_lessons')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->when(
                    $sectionId === null,
                    fn ($query) => $query->whereNull('template_section_id'),
                    fn ($query) => $query->where('template_section_id', $sectionId)
                )
                ->where('id', $lessonId)
                ->update($this->lessonValues($validated, ['updated_at' => now()]));
            $this->syncPrerequisites(
                $customerId,
                $templateId,
                $lessonId,
                $validated
            );
        });

        return redirect()
            ->route(
                $this->routePrefix($request, $sectionId).'.edit',
                $this->lessonRouteParameters(
                    $templateId,
                    $lessonId,
                    $sectionId
                )
            )
            ->with('success', __('lf.LF_course_template_lesson_common_updated'));
    }

    private function destroyLesson(
        Request $request,
        int $templateId,
        int $lessonId,
        ?int $sectionId
    ) {
        $customerId = $this->customerId();
        $this->findTemplate($customerId, $templateId);

        if ($sectionId !== null) {
            $this->findSection($customerId, $templateId, $sectionId);
        }

        $this->findLesson(
            $customerId,
            $templateId,
            $sectionId,
            $lessonId
        );

        $deleted = DB::transaction(function () use ($customerId, $templateId, $sectionId, $lessonId): bool {
            $this->lockTemplate($customerId, $templateId);
            if ($this->hasReferences($customerId, $templateId, $lessonId)) {
                return false;
            }
            DB::table('core_course_template_lesson_prerequisites')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('lesson_id', $lessonId)
                ->delete();
            DB::table('core_course_template_lessons')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->when(
                    $sectionId === null,
                    fn ($query) => $query->whereNull('template_section_id'),
                    fn ($query) => $query->where('template_section_id', $sectionId)
                )
                ->where('id', $lessonId)
                ->delete();

            return true;
        });
        if (! $deleted) {
            return back()->withErrors([
                'lesson' => __(
                    'lf.LF_course_template_lesson_common_delete_blocked'
                ),
            ]);
        }

        return redirect()
            ->to(
                route(
                    $this->templateRoutePrefix($request).'.edit',
                    $templateId
                ).$this->lessonAnchor($sectionId)
            )
            ->with('success', __('lf.LF_course_template_lesson_common_deleted'));
    }

    private function lockTemplate(int $customerId, int $templateId): void
    {
        abort_if(! DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->lockForUpdate()
            ->exists(), 404);
    }

    private function validatedData(
        Request $request,
        int $customerId,
        int $templateId,
        ?int $lessonId = null,
        ?int $sectionId = null
    ): array {
        $validator = Validator::make(
            $this->validationInput($request),
            $this->validationRules($customerId, $templateId, $lessonId, $sectionId)
        );

        $validator->after(function ($validator) use (
            $request,
            $customerId,
            $templateId,
            $lessonId,
            $sectionId
        ): void {
            $rule = $request->input('unlock_rule');
            $prospectiveSortOrder = $request->filled('sort_order')
                ? $request->integer('sort_order')
                : $this->nextSortOrder(
                    $customerId,
                    $templateId,
                    $sectionId
                );
            $prospectivePrerequisites = $this->prerequisiteLessons(
                $customerId,
                $templateId,
                $sectionId,
                $lessonId,
                $prospectiveSortOrder
            );

            if ($rule === 'all_previous_lessons_completed'
                && $prospectivePrerequisites->isEmpty()) {
                $validator->errors()->add(
                    'unlock_rule',
                    __('lf.LF_course_template_lesson_common_invalid_prerequisite')
                );

                return;
            }
            $selected = $rule === 'previous_lesson_completed'
                ? collect([$request->integer('unlock_after_lesson_id')])->filter()
                : collect($request->input('prerequisite_lesson_ids', []))
                    ->map(fn ($id): int => (int) $id)->filter()->unique();

            if (! in_array($rule, [
                'selected_lessons_completed',
                'previous_lesson_completed',
            ], true)) {
                return;
            }

            $validIds = $prospectivePrerequisites
                ->pluck('id')
                ->map(fn ($id): int => (int) $id);

            if ($selected->isEmpty() || $selected->diff($validIds)->isNotEmpty()) {
                $validator->errors()->add(
                    $rule === 'previous_lesson_completed'
                        ? 'unlock_after_lesson_id'
                        : 'prerequisite_lesson_ids',
                    __('lf.LF_course_template_lesson_common_invalid_prerequisite')
                );
            }

            if ($rule === 'previous_lesson_completed'
                && $lessonId !== null
                && $selected->isNotEmpty()
                && $this->dependencyWouldCycle(
                    $customerId,
                    $templateId,
                    $lessonId,
                    (int) $selected->first()
                )) {
                $validator->errors()->add(
                    'unlock_after_lesson_id',
                    __('lf.LF_course_template_lesson_common_invalid_prerequisite')
                );
            }
        });

        return $validator->validate();
    }

    private function validationInput(Request $request): array
    {
        $fields = [
            'title',
            'short_description',
            'description',
            'sort_order',
            'is_preview',
            'lesson_type',
            'unlock_rule',
            'unlock_after_lesson_id',
            'prerequisite_match',
            'prerequisite_lesson_ids',
            'unlock_at',
        ];

        $input = array_intersect_key(
            $request->request->all(),
            array_flip($fields)
        );

        if (($input['unlock_rule'] ?? null) === 'previous_lesson_completed') {
            $input['unlock_rule'] = 'selected_lessons_completed';
            $input['prerequisite_match'] = 'all';
            $input['prerequisite_lesson_ids'] = array_filter([
                $input['unlock_after_lesson_id'] ?? null,
            ]);
        }
        if (($input['unlock_rule'] ?? null) !== 'selected_lessons_completed') {
            unset($input['unlock_after_lesson_id']);
            unset($input['prerequisite_match'], $input['prerequisite_lesson_ids']);
        }
        if (($input['unlock_rule'] ?? null) !== 'date_based') {
            unset($input['unlock_at']);
        }

        return $input;
    }

    private function validationRules(
        int $customerId,
        int $templateId,
        ?int $lessonId = null,
        ?int $sectionId = null
    ): array {
        return [
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'sort_order' => [
                $lessonId === null ? 'nullable' : 'required',
                'integer',
                'min:0',
            ],
            'is_preview' => ['required', 'boolean'],
            'lesson_type' => ['required', Rule::in([
                'regular', 'review', 'midterm_exam', 'final_exam', 'other_exam',
            ])],
            'unlock_rule' => [
                'required',
                Rule::in([
                    'none',
                    'selected_lessons_completed',
                    'all_previous_lessons_completed',
                    'date_based',
                ]),
            ],
            'prerequisite_match' => [
                'nullable',
                'required_if:unlock_rule,selected_lessons_completed',
                Rule::in(['all', 'any']),
            ],
            'prerequisite_lesson_ids' => [
                'nullable',
                'array',
                'min:1',
                'required_if:unlock_rule,selected_lessons_completed',
            ],
            'prerequisite_lesson_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('core_course_template_lessons', 'id')
                    ->where(fn ($query) => $query
                        ->where('customer_id', $customerId)
                        ->where('template_id', $templateId)),
            ],
            'unlock_after_lesson_id' => [
                'nullable',
                'integer',
                Rule::exists('core_course_template_lessons', 'id')
                    ->where(fn ($query) => $query
                        ->where('customer_id', $customerId)
                        ->where('template_id', $templateId)
                        ->when(
                            $sectionId === null,
                            fn ($query) => $query->whereNull('template_section_id'),
                            fn ($query) => $query->whereNotNull('template_section_id')
                        )),
            ],
            'unlock_at' => [
                'nullable',
                'date',
                'required_if:unlock_rule,date_based',
            ],
        ];
    }

    private function requiredFields(
        int $customerId,
        int $templateId,
        ?int $lessonId = null
    ): array {
        return array_keys(array_filter(
            $this->validationRules($customerId, $templateId, $lessonId),
            fn (array $rules): bool => in_array('required', $rules, true)
        ));
    }

    private function lessonValues(array $validated, array $extra = []): array
    {
        return array_merge([
            'title' => $validated['title'],
            'short_description' => $validated['short_description'] ?? null,
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'],
            'is_preview' => (bool) $validated['is_preview'],
            'lesson_type' => $validated['lesson_type'],
            'unlock_rule' => $validated['unlock_rule'],
            'prerequisite_match' => $validated['prerequisite_match'] ?? null,
            'unlock_after_lesson_id' => null,
            'unlock_at' => isset($validated['unlock_at'])
                ? Carbon::parse($validated['unlock_at'])->format('Y-m-d H:i:s')
                : null,
        ], $extra);
    }

    private function prerequisiteLessons(
        int $customerId,
        int $templateId,
        ?int $sectionId,
        ?int $excludedLessonId = null,
        ?int $prospectiveSortOrder = null
    ) {
        $lessons = DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->when(
                $sectionId === null,
                fn ($query) => $query->whereNull('template_section_id'),
                fn ($query) => $query->whereNotNull('template_section_id')
            )
            ->orderBy('template_section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $targetLessonId = $excludedLessonId;
        if ($prospectiveSortOrder !== null) {
            if ($targetLessonId === null) {
                $targetLessonId = PHP_INT_MAX;
                $lessons->push((object) [
                    'id' => $targetLessonId,
                    'template_section_id' => $sectionId,
                    'title' => '',
                    'sort_order' => $prospectiveSortOrder,
                ]);
            } else {
                $target = $lessons->firstWhere('id', $targetLessonId);
                if ($target) {
                    $target->sort_order = $prospectiveSortOrder;
                }
            }
        }

        if ($sectionId === null) {
            $ordered = $lessons->sortBy(fn (object $lesson): string => sprintf(
                '%010d:%020d',
                $lesson->sort_order,
                $lesson->id
            ))->values();

            return $targetLessonId === null
                ? $ordered
                : $ordered->takeUntil(
                    fn (object $lesson): bool => (int) $lesson->id === $targetLessonId
                )->values();
        }

        $sections = DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->get()->keyBy('id');

        $sectionOrderKeys = [];
        $children = $sections->groupBy(
            fn (object $section): int => (int) ($section->parent_section_id ?? 0)
        );
        $walk = function (int $parentId, array $parentKey = []) use (
            &$walk,
            &$sectionOrderKeys,
            $children
        ): void {
            $siblings = $children->get($parentId, collect())->sortBy(
                fn (object $section): string => sprintf(
                    '%010d:%020d',
                    $section->display_order,
                    $section->id
                )
            );
            foreach ($siblings as $section) {
                $key = [...$parentKey, sprintf(
                    'S%010d:%020d',
                    $section->display_order,
                    $section->id
                )];
                $sectionOrderKeys[$section->id] = implode('/', $key);
                $walk((int) $section->id, $key);
            }
        };
        $walk(0);

        foreach ($lessons as $lesson) {
            $labels = [];
            $currentId = (int) $lesson->template_section_id;
            $visited = [];
            while ($currentId && isset($sections[$currentId]) && ! isset($visited[$currentId])) {
                $visited[$currentId] = true;
                array_unshift($labels, $sections[$currentId]->title);
                $currentId = (int) ($sections[$currentId]->parent_section_id ?? 0);
            }
            $lesson->option_label = implode(' › ', [...$labels, $lesson->title]);
        }

        $ordered = $lessons->sortBy(function (object $lesson) use ($sectionOrderKeys): string {
            return ($sectionOrderKeys[$lesson->template_section_id] ?? '')
                .sprintf('/L%010d:%020d', $lesson->sort_order, $lesson->id);
        })->values();

        return $targetLessonId === null
            ? $ordered
            : $ordered->takeUntil(
                fn (object $lesson): bool => (int) $lesson->id === $targetLessonId
            )->values();
    }

    private function nextSortOrder(
        int $customerId,
        int $templateId,
        ?int $sectionId
    ): int {
        return (int) DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->when(
                $sectionId === null,
                fn ($query) => $query->whereNull('template_section_id'),
                fn ($query) => $query->where(
                    'template_section_id',
                    $sectionId
                )
            )
            ->max('sort_order') + 1;
    }

    private function syncPrerequisites(
        int $customerId,
        int $templateId,
        int $lessonId,
        array $validated
    ): void {
        DB::table('core_course_template_lesson_prerequisites')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('lesson_id', $lessonId)
            ->delete();

        if (($validated['unlock_rule'] ?? null) !== 'selected_lessons_completed') {
            return;
        }

        $now = now();
        foreach (array_values($validated['prerequisite_lesson_ids'] ?? []) as $order => $prerequisiteId) {
            DB::table('core_course_template_lesson_prerequisites')->insert([
                'customer_id' => $customerId,
                'template_id' => $templateId,
                'lesson_id' => $lessonId,
                'prerequisite_lesson_id' => (int) $prerequisiteId,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function dependencyWouldCycle(
        int $customerId,
        int $templateId,
        int $lessonId,
        int $prerequisiteId
    ): bool {
        $dependencies = DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->pluck('unlock_after_lesson_id', 'id');
        $visited = [];
        $currentId = $prerequisiteId;

        while ($currentId !== 0 && ! in_array($currentId, $visited, true)) {
            if ($currentId === $lessonId) {
                return true;
            }

            $visited[] = $currentId;
            $currentId = (int) ($dependencies[$currentId] ?? 0);
        }

        return false;
    }

    private function hasReferences(
        int $customerId,
        int $templateId,
        int $lessonId
    ): bool {
        if (
            DB::table('core_course_template_lesson_prerequisites')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('prerequisite_lesson_id', $lessonId)
                ->exists()
            ||
            DB::table('core_course_template_lessons')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('unlock_after_lesson_id', $lessonId)
                ->exists()
        ) {
            return true;
        }

        return Schema::hasTable('core_course_template_activities')
            && DB::table('core_course_template_activities')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('template_lesson_id', $lessonId)
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

    private function findLessonContainerSection(
        int $customerId,
        int $templateId,
        int $sectionId
    ): object {
        $section = $this->findSection($customerId, $templateId, $sectionId);

        if (! $section->allows_lessons) {
            throw ValidationException::withMessages([
                'template_section_id' => __(
                    'lf.LF_course_template_section_common_lessons_not_allowed'
                ),
            ]);
        }

        return $section;
    }

    private function findLesson(
        int $customerId,
        int $templateId,
        ?int $sectionId,
        int $lessonId
    ): object {
        $lesson = DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->when(
                $sectionId === null,
                fn ($query) => $query->whereNull('template_section_id'),
                fn ($query) => $query->where(
                    'template_section_id',
                    $sectionId
                )
            )
            ->where('id', $lessonId)
            ->first();

        abort_if(! $lesson, 404);

        return $lesson;
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        return $customerId;
    }

    private function routePrefix(
        Request $request,
        ?int $sectionId
    ): string {
        return $this->templateRoutePrefix($request).(
            $sectionId === null ? '.lessons' : '.sections.lessons'
        );
    }

    private function lessonRouteParameters(
        int $templateId,
        int $lessonId,
        ?int $sectionId
    ): array {
        return $sectionId === null
            ? [$templateId, $lessonId]
            : [$templateId, $sectionId, $lessonId];
    }

    private function lessonAnchor(?int $sectionId): string
    {
        return $sectionId === null
            ? '?tab=structure#course-template-direct-lessons'
            : "?tab=structure#course-template-section-{$sectionId}-lessons";
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
