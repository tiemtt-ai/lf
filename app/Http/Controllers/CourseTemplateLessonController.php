<?php

namespace App\Http\Controllers;

use App\Services\CourseTemplateLessonUnlockOrder;
use App\Support\CourseTemplateEditable;
use App\Support\CourseTemplateWorkingRevision;
use App\Support\LessonUnlockTime;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CourseTemplateLessonController extends Controller
{
    public function __construct(
        private readonly CourseTemplateLessonUnlockOrder $unlockOrder
    ) {}

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

    public function reorder(
        Request $request,
        int $templateId,
        int $sectionId
    ): JsonResponse {
        return $this->reorderLessons($request, $templateId, $sectionId);
    }

    public function reorderDirect(
        Request $request,
        int $templateId
    ): JsonResponse {
        return $this->reorderLessons($request, $templateId, null);
    }

    private function createView(
        Request $request,
        int $templateId,
        ?int $sectionId
    ): View {
        abort_unless($request->ajax(), 404);

        $customerId = $this->customerId();

        return view('course-template-lessons.dialog', [
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
            $template = CourseTemplateEditable::lock($customerId, $templateId);

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
            CourseTemplateWorkingRevision::bump($customerId, $templateId);

            return $lessonId;
        });

        $structureUrl = route(
            $this->templateRoutePrefix($request).'.edit',
            $templateId
        ).$this->lessonAnchor($sectionId);

        if ($request->expectsJson()) {
            // The structure tab is rendered by the page: the dialog reloads it, where the flashed message shows as a toast.
            $request->session()->flash('success', __('lf.LF_course_template_lesson_common_created'));
            // The new lesson may land beyond the first page of the list: the pager opens the page that holds it.
            $request->session()->flash('course_template_lesson_focus', $lessonId);

            return response()->json([
                'message' => __('lf.LF_course_template_lesson_common_created'),
                'redirect' => $structureUrl,
            ], 201);
        }

        return redirect()
            ->to($structureUrl)
            ->with('success', __('lf.LF_course_template_lesson_common_created'))
            ->with('course_template_lesson_focus', $lessonId);
    }

    private function editView(
        Request $request,
        int $templateId,
        int $lessonId,
        ?int $sectionId
    ): View {
        abort_unless($request->ajax(), 404);

        $customerId = $this->customerId();

        return view('course-template-lessons.dialog', [
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
            CourseTemplateWorkingRevision::bump($customerId, $templateId);
            $violationsBefore = $this->unlockOrder->violations($customerId, $templateId, $sectionId !== null);
            $validated['sort_order'] ??= $this->findLesson($customerId, $templateId, $sectionId, $lessonId)->sort_order;
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

            // The form checks this lesson's own rule; a new position can also break the lessons that depend on it.
            $newViolations = array_diff_key(
                $this->unlockOrder->violations($customerId, $templateId, $sectionId !== null),
                $violationsBefore
            );

            if ($newViolations !== []) {
                throw ValidationException::withMessages([
                    'sort_order' => reset($newViolations),
                ]);
            }
        });

        if ($request->expectsJson()) {
            $request->session()->flash('success', __('lf.LF_course_template_lesson_common_updated'));

            return response()->json([
                'message' => __('lf.LF_course_template_lesson_common_updated'),
                'redirect' => route(
                    $this->templateRoutePrefix($request).'.edit',
                    $templateId
                ).$this->lessonAnchor($sectionId),
            ]);
        }

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
            if ($this->deleteBlockReason($customerId, $templateId, $lessonId, $sectionId) !== null) {
                return false;
            }
            CourseTemplateWorkingRevision::bump($customerId, $templateId);
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
        $structureUrl = route(
            $this->templateRoutePrefix($request).'.edit',
            $templateId
        ).$this->lessonAnchor($sectionId);

        if (! $deleted) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $this->deleteBlockMessage(
                        $this->deleteBlockReason($customerId, $templateId, $lessonId, $sectionId),
                        $customerId,
                        $templateId,
                        $lessonId,
                        $sectionId
                    ),
                ], 409);
            }

            return back()->withErrors([
                'lesson' => __(
                    'lf.LF_course_template_lesson_common_delete_blocked'
                ),
            ]);
        }

        if ($request->expectsJson()) {
            $request->session()->flash('success', __('lf.LF_course_template_lesson_common_deleted'));

            return response()->json([
                'message' => __('lf.LF_course_template_lesson_common_deleted'),
                'redirect' => $structureUrl,
            ]);
        }

        return redirect()
            ->to($structureUrl)
            ->with('success', __('lf.LF_course_template_lesson_common_deleted'));
    }

    /**
     * Saves the order of one group (the direct lessons or one Section's lessons) sent as the complete list of its ids.
     * A list that is not exactly that group changes nothing (409), and so does an order that would break an unlock rule
     * which holds today (422, naming the lessons). Older problems the move does not cause never block it.
     */
    private function reorderLessons(
        Request $request,
        int $templateId,
        ?int $sectionId
    ): JsonResponse {
        abort_unless($request->expectsJson(), 404);

        $customerId = $this->customerId();
        $this->findTemplate($customerId, $templateId);

        if ($sectionId !== null) {
            $this->findSection($customerId, $templateId, $sectionId);
        }

        $validated = $request->validate([
            'lesson_ids' => ['required', 'array', 'min:1'],
            'lesson_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $lessonIds = array_map('intval', array_values($validated['lesson_ids']));

        $applied = DB::transaction(function () use ($customerId, $templateId, $sectionId, $lessonIds): bool {
            $this->lockTemplate($customerId, $templateId);

            $group = fn () => DB::table('core_course_template_lessons')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->when(
                    $sectionId === null,
                    fn ($query) => $query->whereNull('template_section_id'),
                    fn ($query) => $query->where('template_section_id', $sectionId)
                );
            $currentOrders = $group()->pluck('sort_order', 'id');

            if ($currentOrders->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all()
                !== collect($lessonIds)->sort()->values()->all()) {
                return false;
            }
            CourseTemplateWorkingRevision::bump($customerId, $templateId);

            $violationsBefore = $this->unlockOrder->violations($customerId, $templateId, $sectionId !== null);
            $now = now();

            foreach ($lessonIds as $index => $lessonId) {
                if ((int) $currentOrders[$lessonId] !== $index + 1) {
                    $group()->where('id', $lessonId)->update([
                        'sort_order' => $index + 1,
                        'updated_at' => $now,
                    ]);
                }
            }

            $newViolations = array_diff_key(
                $this->unlockOrder->violations($customerId, $templateId, $sectionId !== null),
                $violationsBefore
            );

            if ($newViolations !== []) {
                throw ValidationException::withMessages([
                    'lesson_ids' => reset($newViolations),
                ]);
            }

            return true;
        });

        if (! $applied) {
            return response()->json([
                'message' => __('lf.LF_course_template_lesson_reorder_stale'),
            ], 409);
        }

        return response()->json([
            'message' => __('lf.LF_course_template_lesson_reorder_saved'),
        ]);
    }

    private function lockTemplate(int $customerId, int $templateId): void
    {
        CourseTemplateEditable::lock($customerId, $templateId);
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
            // Without an order a new lesson goes last and an existing one stays where it is.
            $prospectiveSortOrder = $request->filled('sort_order')
                ? $request->integer('sort_order')
                : ($lessonId === null
                    ? $this->nextSortOrder($customerId, $templateId, $sectionId)
                    : null);
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
            'sort_order' => ['nullable', 'integer', 'min:0'],
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
                ? LessonUnlockTime::fromLocalInput((string) $validated['unlock_at'])
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
        return $this->unlockOrder->orderedLessons(
            $customerId,
            $templateId,
            $sectionId,
            $excludedLessonId,
            $prospectiveSortOrder
        );
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

    /** Why the lesson cannot be deleted: 'activities', 'referenced' (another lesson unlocks after it), 'first' (the next lesson needs one before it) or null. */
    private function deleteBlockReason(
        int $customerId,
        int $templateId,
        int $lessonId,
        ?int $sectionId
    ): ?string {
        if (Schema::hasTable('core_course_template_activities')
            && DB::table('core_course_template_activities')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('template_lesson_id', $lessonId)
                ->exists()) {
            return 'activities';
        }

        if (DB::table('core_course_template_lesson_prerequisites')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('prerequisite_lesson_id', $lessonId)
            ->exists()
            || DB::table('core_course_template_lessons')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('unlock_after_lesson_id', $lessonId)
                ->exists()) {
            return 'referenced';
        }

        return $this->unlockOrder->deleteViolation($customerId, $templateId, $lessonId, $sectionId !== null) !== null
            ? 'first'
            : null;
    }

    private function deleteBlockMessage(
        ?string $reason,
        int $customerId,
        int $templateId,
        int $lessonId,
        ?int $sectionId
    ): string {
        return match ($reason) {
            'first' => (string) $this->unlockOrder->deleteViolation($customerId, $templateId, $lessonId, $sectionId !== null),
            'activities' => __('lf.LF_course_template_lesson_delete_blocked_activities'),
            'referenced' => __('lf.LF_course_template_lesson_delete_blocked_referenced'),
            default => __('lf.LF_course_template_lesson_common_delete_blocked'),
        };
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
