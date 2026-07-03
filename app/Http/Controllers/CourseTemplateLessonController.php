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
            ."#course-template-section-{$sectionId}-lessons"
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
                : $this->findSection(
                    $customerId,
                    $templateId,
                    $sectionId
                ),
            'prerequisiteLessons' => $this->prerequisiteLessons(
                $customerId,
                $templateId
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
            $this->findSection($customerId, $templateId, $sectionId);
        }

        $validated = $this->validatedData(
            $request,
            $customerId,
            $templateId
        );

        DB::transaction(function () use (
            $request,
            $validated,
            $customerId,
            $templateId,
            $sectionId
        ): void {
            $template = DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $templateId)
                ->lockForUpdate()
                ->first();

            abort_if(! $template, 404);

            if (
                $template->max_lessons !== null
                && DB::table('core_course_template_lessons')
                    ->where('customer_id', $customerId)
                    ->where('template_id', $templateId)
                    ->count() >= (int) $template->max_lessons
            ) {
                throw ValidationException::withMessages([
                    'title' => __(
                        'lf.LF_course_template_lesson_common_max_lessons_reached'
                    ),
                ]);
            }

            $now = now();

            DB::table('core_course_template_lessons')->insert(
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
            'prerequisiteLessons' => $this->prerequisiteLessons(
                $customerId,
                $templateId,
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
            $lessonId
        );

        DB::table('core_course_template_lessons')
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
            ->update($this->lessonValues($validated, [
                'updated_at' => now(),
            ]));

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

        if ($this->hasReferences($customerId, $templateId, $lessonId)) {
            return back()->withErrors([
                'lesson' => __(
                    'lf.LF_course_template_lesson_common_delete_blocked'
                ),
            ]);
        }

        DB::table('core_course_template_lessons')
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
            ->delete();

        return redirect()
            ->to(
                route(
                    $this->templateRoutePrefix($request).'.edit',
                    $templateId
                ).$this->lessonAnchor($sectionId)
            )
            ->with('success', __('lf.LF_course_template_lesson_common_deleted'));
    }

    private function validatedData(
        Request $request,
        int $customerId,
        int $templateId,
        ?int $lessonId = null
    ): array {
        $validator = Validator::make(
            $request->all(),
            $this->validationRules($customerId, $templateId, $lessonId)
        );

        if ($lessonId !== null) {
            $validator->after(function ($validator) use (
                $request,
                $customerId,
                $templateId,
                $lessonId
            ): void {
                $prerequisiteId = $request->integer(
                    'unlock_after_lesson_id'
                );

                if (
                    $prerequisiteId !== 0
                    && (
                        $prerequisiteId === $lessonId
                        || $this->dependencyWouldCycle(
                            $customerId,
                            $templateId,
                            $lessonId,
                            $prerequisiteId
                        )
                    )
                ) {
                    $validator->errors()->add(
                        'unlock_after_lesson_id',
                        __('lf.LF_course_template_lesson_common_invalid_prerequisite')
                    );
                }
            });
        }

        return $validator->validate();
    }

    private function validationRules(
        int $customerId,
        int $templateId,
        ?int $lessonId = null
    ): array {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('core_course_template_lessons', 'slug')
                    ->where(fn ($query) => $query
                        ->where('customer_id', $customerId)
                        ->where('template_id', $templateId))
                    ->ignore($lessonId),
            ],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_preview' => ['required', 'boolean'],
            'learning_objective' => ['nullable', 'string'],
            'unlock_rule' => [
                'required',
                Rule::in([
                    'none',
                    'previous_lesson_completed',
                    'date_based',
                ]),
            ],
            'unlock_after_lesson_id' => [
                'nullable',
                'integer',
                'required_if:unlock_rule,previous_lesson_completed',
                'prohibited_unless:unlock_rule,previous_lesson_completed',
                Rule::exists('core_course_template_lessons', 'id')
                    ->where(fn ($query) => $query
                        ->where('customer_id', $customerId)
                        ->where('template_id', $templateId)),
            ],
            'unlock_at' => [
                'nullable',
                'date',
                'required_if:unlock_rule,date_based',
                'prohibited_unless:unlock_rule,date_based',
            ],
            'status' => [
                'required',
                Rule::in(['draft', 'active', 'inactive', 'archived']),
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
            'slug' => $validated['slug'] ?? null,
            'short_description' => $validated['short_description'] ?? null,
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'],
            'is_preview' => (bool) $validated['is_preview'],
            'learning_objective' => $validated['learning_objective'] ?? null,
            'unlock_rule' => $validated['unlock_rule'],
            'unlock_after_lesson_id' => $validated['unlock_after_lesson_id'] ?? null,
            'unlock_at' => isset($validated['unlock_at'])
                ? Carbon::parse($validated['unlock_at'])->format('Y-m-d H:i:s')
                : null,
            'status' => $validated['status'],
        ], $extra);
    }

    private function prerequisiteLessons(
        int $customerId,
        int $templateId,
        ?int $excludedLessonId = null
    ) {
        return DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->when(
                $excludedLessonId !== null,
                fn ($query) => $query->where('id', '!=', $excludedLessonId)
            )
            ->orderBy('template_section_id')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
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

        return $template;
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
            ? '#course-template-direct-lessons'
            : "#course-template-section-{$sectionId}-lessons";
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
