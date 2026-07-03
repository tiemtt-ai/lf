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

    private const STATUSES = [
        'active',
        'inactive',
    ];

    public function index(Request $request, int $templateId)
    {
        $this->findTemplate($this->customerId(), $templateId);

        return redirect()->to(
            route($this->templateRoutePrefix($request).'.edit', $templateId)
            .'#course-template-teachers'
        );
    }

    public function create(Request $request, int $templateId): View
    {
        $customerId = $this->customerId();

        return view('course-template-teachers.create', [
            'template' => $this->findTemplate($customerId, $templateId),
            'teachers' => $this->availableTeachers($customerId, $templateId),
            'assignmentRoles' => self::ASSIGNMENT_ROLES,
            'statuses' => self::STATUSES,
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

        DB::table('core_course_template_teachers')->insert([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'teacher_id' => $validated['teacher_id'],
            'role' => $validated['role'],
            'sort_order' => $validated['sort_order'],
            'status' => $validated['status'],
            'assigned_by' => $request->user()?->id,
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()
            ->to(
                route(
                    $this->templateRoutePrefix($request).'.edit',
                    $templateId
                ).'#course-template-teachers'
            )
            ->with('success', __(
                'lf.LF_course_template_teacher_common_created'
            ));
    }

    public function edit(
        Request $request,
        int $templateId,
        int $assignmentId
    ): View {
        $customerId = $this->customerId();

        return view('course-template-teachers.edit', [
            'template' => $this->findTemplate($customerId, $templateId),
            'assignment' => $this->findAssignment(
                $customerId,
                $templateId,
                $assignmentId
            ),
            'assignmentRoles' => self::ASSIGNMENT_ROLES,
            'statuses' => self::STATUSES,
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
        $this->findAssignment($customerId, $templateId, $assignmentId);
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
                'sort_order' => $validated['sort_order'],
                'status' => $validated['status'],
                'updated_at' => now(),
            ]);

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
        $this->findAssignment($customerId, $templateId, $assignmentId);

        DB::table('core_course_template_teachers')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('id', $assignmentId)
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

        return redirect()
            ->to(
                route(
                    $this->templateRoutePrefix($request).'.edit',
                    $templateId
                ).'#course-template-teachers'
            )
            ->with('success', __(
                'lf.LF_course_template_teacher_common_removed'
            ));
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
        $rules = [
            'role' => ['required', Rule::in(self::ASSIGNMENT_ROLES)],
            'sort_order' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ];

        if ($assignmentId === null) {
            $rules['teacher_id'] = [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query
                        ->where('customer_id', $customerId)
                        ->where('role', 'teacher')
                ),
                Rule::unique(
                    'core_course_template_teachers',
                    'teacher_id'
                )->where(
                    fn ($query) => $query
                        ->where('customer_id', $customerId)
                        ->where('template_id', $templateId)
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

        return $template;
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
