<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CourseTemplateTeacherController extends Controller
{
    private const ASSIGNMENT_ROLES = [
        'primary',
        'assistant',
        'reviewer',
    ];

    public function index(Request $request, int $templateId)
    {
        $this->findTemplate($this->customerId(), $templateId);

        return redirect()->to(
            route($this->templateRoutePrefix($request).'.edit', $templateId)
            .'?tab=teachers#course-template-teachers'
        );
    }

    public function create(Request $request, int $templateId): View
    {
        $customerId = $this->customerId();

        return view($request->ajax() ? 'course-template-teachers.dialog' : 'course-template-teachers.create', [
            'template' => $this->findTemplate($customerId, $templateId),
            'teachers' => $this->availableTeachers($customerId, $templateId),
            'assignmentRoles' => self::ASSIGNMENT_ROLES,
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
        DB::transaction(function () use ($customerId, $templateId, $validated, $request): void {
            DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $templateId)
                ->lockForUpdate()
                ->first();
            $now = now();
            // A teacher removed earlier keeps their row (one per Template and teacher): assigning them again
            // reactivates it with the new role, keeping its place in the list.
            $removed = DB::table('core_course_template_teachers')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('teacher_id', $validated['teacher_id'])
                ->where('status', 'inactive')
                ->lockForUpdate()
                ->first();
            if ($removed) {
                DB::table('core_course_template_teachers')
                    ->where('customer_id', $customerId)
                    ->where('id', $removed->id)
                    ->update([
                        'role' => $validated['role'],
                        'status' => 'active',
                        'assigned_by' => $request->user()?->id,
                        'assigned_at' => $now,
                        'updated_at' => $now,
                    ]);

                return;
            }
            $maximumOrder = DB::table('core_course_template_teachers')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->max('sort_order');

            DB::table('core_course_template_teachers')->insert([
                'customer_id' => $customerId,
                'template_id' => $templateId,
                'teacher_id' => $validated['teacher_id'],
                'role' => $validated['role'],
                'sort_order' => $maximumOrder === null ? 0 : (int) $maximumOrder + 1,
                'status' => 'active',
                'assigned_by' => $request->user()?->id,
                'assigned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return $this->backToTeachers($request, $templateId, 'lf.LF_course_template_teacher_common_created');
    }

    public function edit(
        Request $request,
        int $templateId,
        int $assignmentId
    ): View {
        $customerId = $this->customerId();

        return view($request->ajax() ? 'course-template-teachers.dialog' : 'course-template-teachers.edit', [
            'template' => $this->findTemplate($customerId, $templateId),
            'assignment' => $this->findActiveAssignment(
                $customerId,
                $templateId,
                $assignmentId
            ),
            'assignmentRoles' => self::ASSIGNMENT_ROLES,
            'requiredFields' => $this->requiredFields(
                $customerId,
                $templateId,
                $assignmentId
            ),
            'routePrefix' => $this->routePrefix($request),
            'templateRoutePrefix' => $this->templateRoutePrefix($request),
        ]);
    }

    public function update(
        Request $request,
        int $templateId,
        int $assignmentId
    ) {
        $customerId = $this->customerId();
        $this->findTemplate($customerId, $templateId);
        $this->findActiveAssignment($customerId, $templateId, $assignmentId);
        $validated = $this->validatedData(
            $request,
            $customerId,
            $templateId,
            $assignmentId
        );

        DB::table('core_course_template_teachers')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('id', $assignmentId)
            ->update([
                'role' => $validated['role'],
                'updated_at' => now(),
            ]);

        if ($request->expectsJson()) {
            return $this->backToTeachers($request, $templateId, 'lf.LF_course_template_teacher_common_updated');
        }

        return redirect()
            ->route(
                $this->routePrefix($request).'.edit',
                [$templateId, $assignmentId]
            )
            ->with('success', __(
                'lf.LF_course_template_teacher_common_updated'
            ));
    }

    public function destroy(
        Request $request,
        int $templateId,
        int $assignmentId
    ) {
        $customerId = $this->customerId();
        $this->findTemplate($customerId, $templateId);
        $this->findActiveAssignment($customerId, $templateId, $assignmentId);

        DB::table('core_course_template_teachers')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('id', $assignmentId)
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

        return $this->backToTeachers($request, $templateId, 'lf.LF_course_template_teacher_common_removed');
    }

    private function validatedData(
        Request $request,
        int $customerId,
        int $templateId,
        ?int $assignmentId = null
    ): array {
        return Validator::make(
            $request->all(),
            $this->validationRules(
                $customerId,
                $templateId,
                $assignmentId
            )
        )->validate();
    }

    private function validationRules(
        int $customerId,
        int $templateId,
        ?int $assignmentId = null
    ): array {
        $rules = ['role' => ['required', Rule::in(self::ASSIGNMENT_ROLES)]];

        if ($assignmentId === null) {
            $rules['teacher_id'] = [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query
                        ->where('customer_id', $customerId)
                        ->where('role', 'teacher')
                ),
                // Only an active assignment blocks; a removed teacher can be assigned again.
                Rule::unique(
                    'core_course_template_teachers',
                    'teacher_id'
                )->where(
                    fn ($query) => $query
                        ->where('customer_id', $customerId)
                        ->where('template_id', $templateId)
                        ->where('status', 'active')
                ),
            ];
        }

        return $rules;
    }

    private function requiredFields(
        int $customerId,
        int $templateId,
        ?int $assignmentId = null
    ): array {
        return array_keys(array_filter(
            $this->validationRules(
                $customerId,
                $templateId,
                $assignmentId
            ),
            fn (array $rules): bool => in_array('required', $rules, true)
        ));
    }

    private function availableTeachers(int $customerId, int $templateId)
    {
        $assignedTeacherIds = DB::table('core_course_template_teachers')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('status', 'active')
            ->pluck('teacher_id');

        return DB::table('users')
            ->where('customer_id', $customerId)
            ->where('role', 'teacher')
            ->whereNotIn('id', $assignedTeacherIds)
            ->orderBy('name')
            ->orderBy('email')
            ->get(['id', 'name', 'email']);
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

    private function findAssignment(
        int $customerId,
        int $templateId,
        int $assignmentId
    ): object {
        $assignment = DB::table(
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
            ->where('assignments.id', $assignmentId)
            ->select(
                'assignments.*',
                'teachers.name as teacher_name',
                'teachers.email as teacher_email'
            )
            ->first();

        abort_if(! $assignment, 404);

        return $assignment;
    }

    /** Only an active assignment can be edited or removed; a removed one is assigned again from Add. */
    private function findActiveAssignment(int $customerId, int $templateId, int $assignmentId): object
    {
        $assignment = $this->findAssignment($customerId, $templateId, $assignmentId);

        abort_if($assignment->status !== 'active', 404);

        return $assignment;
    }

    /** Back to the Teachers tab: JSON for the popup and the row actions (the page reloads there), a redirect otherwise. */
    private function backToTeachers(Request $request, int $templateId, string $messageKey)
    {
        $url = route($this->templateRoutePrefix($request).'.edit', $templateId).'?tab=teachers#course-template-teachers';
        $message = __($messageKey);

        if ($request->expectsJson()) {
            $request->session()->flash('success', $message);

            return response()->json(['message' => $message, 'redirect' => $url]);
        }

        return redirect()->to($url)->with('success', $message);
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        return $customerId;
    }

    private function routePrefix(Request $request): string
    {
        return $this->templateRoutePrefix($request).'.teachers';
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
