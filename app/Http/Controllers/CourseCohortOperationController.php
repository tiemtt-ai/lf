<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesCourseCohortAdmin;
use App\Services\LiveClassSessionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CourseCohortOperationController extends Controller
{
    use AuthorizesCourseCohortAdmin;

    public function __construct(
        private readonly LiveClassSessionPolicy $sessionPolicy
    ) {}

    public function storeTeacher(Request $request, int $cohort): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->setupCohort($customerId, $cohort);
        $assignedTeacherIds = DB::table('core_course_cohort_teachers')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohort)
            ->where('status', 'active')
            ->pluck('teacher_id')
            ->all();
        $assignedFromRules = ['nullable', 'date'];
        $assignedToRules = ['nullable', 'date', 'after_or_equal:assigned_from'];

        if ($cohortRow->start_date) {
            $assignedFromRules[] = 'after_or_equal:'.$cohortRow->start_date;
            $assignedToRules[] = 'after_or_equal:'.$cohortRow->start_date;
        }

        if ($cohortRow->end_date) {
            $assignedFromRules[] = 'before_or_equal:'.$cohortRow->end_date;
            $assignedToRules[] = 'before_or_equal:'.$cohortRow->end_date;
        }

        $validated = $request->validate([
            'teacher_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('customer_id', $customerId)
                    ->where('role', 'teacher')
                    ->where('status', 'active')),
                Rule::notIn($assignedTeacherIds),
            ],
            'role' => ['required', Rule::in(['primary_teacher', 'teacher', 'assistant'])],
            'assigned_from' => $assignedFromRules,
            'assigned_to' => $assignedToRules,
        ], [
            'teacher_id.required' => __('lf.LF_course_cohort_teacher_validation_teacher_required'),
            'teacher_id.integer' => __('lf.LF_course_cohort_teacher_validation_teacher_invalid'),
            'teacher_id.exists' => __('lf.LF_course_cohort_teacher_validation_teacher_invalid'),
            'teacher_id.not_in' => __('lf.LF_course_cohort_teacher_validation_teacher_assigned'),
            'role.required' => __('lf.LF_course_cohort_teacher_validation_role_required'),
            'role.in' => __('lf.LF_course_cohort_teacher_validation_role_invalid'),
            'assigned_from.date' => __('lf.LF_course_cohort_teacher_validation_date_invalid'),
            'assigned_from.after_or_equal' => __('lf.LF_course_cohort_teacher_validation_period_outside'),
            'assigned_from.before_or_equal' => __('lf.LF_course_cohort_teacher_validation_period_outside'),
            'assigned_to.date' => __('lf.LF_course_cohort_teacher_validation_date_invalid'),
            'assigned_to.after_or_equal' => __('lf.LF_course_cohort_teacher_validation_end_before_start'),
            'assigned_to.before_or_equal' => __('lf.LF_course_cohort_teacher_validation_period_outside'),
        ]);

        DB::transaction(function () use ($customerId, $cohort, $validated, $request): void {
            if ($validated['role'] === 'primary_teacher') {
                DB::table('core_course_cohort_teachers')
                    ->where('customer_id', $customerId)
                    ->where('cohort_id', $cohort)
                    ->where('role', 'primary_teacher')
                    ->update(['role' => 'teacher', 'updated_at' => now()]);
            }

            DB::table('core_course_cohort_teachers')->updateOrInsert(
                [
                    'customer_id' => $customerId,
                    'cohort_id' => $cohort,
                    'teacher_id' => $validated['teacher_id'],
                ],
                [
                    'role' => $validated['role'],
                    'assigned_from' => $validated['assigned_from'] ?? null,
                    'assigned_to' => $validated['assigned_to'] ?? null,
                    'status' => 'active',
                    'created_by' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        });

        return $this->tab($cohort, 'teachers')
            ->with('success', __('lf.LF_course_cohort_teacher_saved'));
    }

    public function removeTeacher(Request $request, int $cohort, int $assignment): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $this->setupCohort($customerId, $cohort);
        abort_unless(DB::table('core_course_cohort_teachers')
            ->where('customer_id', $customerId)->where('cohort_id', $cohort)
            ->where('id', $assignment)->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]), 404);

        return $this->tab($cohort, 'teachers')
            ->with('success', __('lf.LF_course_cohort_teacher_removed'));
    }

    public function storeSession(Request $request, int $cohort): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->setupCohort($customerId, $cohort);
        abort_if(! $cohortRow->version_id, 422);

        $validated = $request->validate($this->sessionRules($customerId, (int) $cohortRow->version_id));
        $lesson = DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $cohortRow->version_id)
            ->where('id', $validated['version_lesson_id'])
            ->firstOrFail();
        $this->validateActivity($customerId, (int) $cohortRow->version_id, (int) $lesson->id, $validated['version_activity_id'] ?? null);

        DB::transaction(function () use ($customerId, $cohort, $cohortRow, $validated, $request): void {
            $sessionNo = (int) DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                ->lockForUpdate()->max('session_no') + 1;

            $sessionId = DB::table('core_liveclass_sessions')->insertGetId([
                'customer_id' => $customerId,
                'cohort_id' => $cohort,
                'template_version_id' => $cohortRow->version_id,
                'version_lesson_id' => $validated['version_lesson_id'],
                'version_activity_id' => $validated['version_activity_id'] ?? null,
                'room_id' => null,
                'primary_teacher_id' => $validated['primary_teacher_id'] ?? null,
                'title' => trim($validated['title']),
                'session_no' => $sessionNo,
                'delivery_mode' => $validated['delivery_mode'],
                'scheduled_start_at' => $validated['scheduled_start_at'],
                'scheduled_end_at' => $validated['scheduled_end_at'],
                'timezone' => config('app.timezone'),
                'status' => 'scheduled',
                'online_provider' => $validated['online_provider'] ?? null,
                'meeting_url_snapshot' => $validated['meeting_url'] ?? null,
                'facility_name_snapshot' => $validated['facility_name'] ?? null,
                'room_name_snapshot' => $validated['room_name'] ?? null,
                'address_snapshot' => $validated['address'] ?? null,
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (! empty($validated['primary_teacher_id'])) {
                DB::table('core_liveclass_session_teachers')->insert([
                    'customer_id' => $customerId,
                    'session_id' => $sessionId,
                    'teacher_id' => $validated['primary_teacher_id'],
                    'role' => 'primary_teacher',
                    'created_by' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return $this->tab($cohort, 'sessions')
            ->with('success', __('lf.LF_course_cohort_session_created'));
    }

    public function updateSchedule(Request $request, int $cohort, int $session): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $this->setupCohort($customerId, $cohort);
        $validated = $request->validate([
            'scheduled_start_at' => ['required', 'date'],
            'scheduled_end_at' => ['required', 'date', 'after:scheduled_start_at'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($customerId, $cohort, $session, $validated, $request): void {
            $sessionRow = DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                ->where('id', $session)->lockForUpdate()->firstOrFail();
            abort_unless($this->sessionPolicy->canReschedule($sessionRow), 422,
                __('lf.LF_course_cohort_session_reschedule_status_invalid'));

            DB::table('core_liveclass_session_schedule_changes')->insert([
                'customer_id' => $customerId,
                'session_id' => $session,
                'previous_start_at' => $sessionRow->scheduled_start_at,
                'previous_end_at' => $sessionRow->scheduled_end_at,
                'new_start_at' => $validated['scheduled_start_at'],
                'new_end_at' => $validated['scheduled_end_at'],
                'reason' => $validated['reason'] ?? null,
                'changed_by' => $request->user()->id,
                'created_at' => now(),
            ]);
            DB::table('core_liveclass_sessions')->where('customer_id', $customerId)
                ->where('id', $session)->update([
                    'scheduled_start_at' => $validated['scheduled_start_at'],
                    'scheduled_end_at' => $validated['scheduled_end_at'],
                    'status' => 'scheduled',
                    'updated_at' => now(),
                ]);
        });

        return $this->tab($cohort, 'sessions')
            ->with('success', __('lf.LF_course_cohort_session_rescheduled'));
    }

    public function saveAttendance(Request $request, int $cohort, int $session): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $this->activeCohort($customerId, $cohort);
        $sessionRow = DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)->where('cohort_id', $cohort)
            ->where('id', $session)->firstOrFail();
        abort_unless($this->sessionPolicy->canRecordAttendance($sessionRow, now()), 422,
            __('lf.LF_course_cohort_attendance_session_invalid'));
        $validated = $request->validate([
            'attendance' => ['required', 'array'],
            'attendance.*.enrollment_id' => ['required', 'integer'],
            'attendance.*.status' => ['required', Rule::in(['registered', 'present', 'late', 'absent', 'excused'])],
            'attendance.*.attendance_mode' => ['nullable', Rule::in(['online', 'offline'])],
            'attendance.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $memberships = DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)->where('cohort_id', $cohort)
            ->where('status', 'active')->pluck('student_id', 'enrollment_id');

        DB::transaction(function () use ($validated, $memberships, $customerId, $session, $sessionRow, $request): void {
            foreach ($validated['attendance'] as $item) {
                abort_unless($memberships->has($item['enrollment_id']), 422);
                DB::table('core_liveclass_attendances')->updateOrInsert(
                    [
                        'customer_id' => $customerId,
                        'session_id' => $session,
                        'enrollment_id' => $item['enrollment_id'],
                    ],
                    [
                        'user_id' => $memberships->get($item['enrollment_id']),
                        'version_activity_id' => $sessionRow->version_activity_id,
                        'status' => $item['status'],
                        'attendance_mode' => $item['attendance_mode'] ?? null,
                        'attendance_source' => 'manual',
                        'notes' => $item['notes'] ?? null,
                        'recorded_by' => $request->user()->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        });

        return $this->tab($cohort, 'attendance', ['session_id' => $session])
            ->with('success', __('lf.LF_course_cohort_attendance_saved'));
    }

    public function storeRecording(Request $request, int $cohort, int $session): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $this->activeCohort($customerId, $cohort);
        $sessionRow = DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)->where('cohort_id', $cohort)
            ->where('id', $session)->firstOrFail();
        abort_unless($this->sessionPolicy->canCreateRecording($sessionRow, now()), 422,
            __('lf.LF_course_cohort_recording_session_invalid'));
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'recording_url' => ['required', 'url', 'max:2000'],
            'replay_available_from' => ['nullable', 'date'],
            'replay_available_until' => ['nullable', 'date', 'after:replay_available_from'],
        ]);

        DB::table('core_liveclass_recordings')->insert([
            'customer_id' => $customerId,
            'session_id' => $session,
            'title' => trim($validated['title']),
            'recording_url' => $validated['recording_url'] ?? null,
            'replay_available_from' => $validated['replay_available_from'] ?? null,
            'replay_available_until' => $validated['replay_available_until'] ?? null,
            'visibility' => 'cohort',
            'status' => 'processing',
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->tab($cohort, 'recordings')
            ->with('success', __('lf.LF_course_cohort_recording_created'));
    }

    private function sessionRules(int $customerId, int $versionId): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'version_lesson_id' => ['required', 'integer', Rule::exists('core_course_template_version_lessons', 'id')
                ->where(fn ($query) => $query->where('customer_id', $customerId)->where('template_version_id', $versionId))],
            'version_activity_id' => ['nullable', 'integer'],
            'primary_teacher_id' => ['nullable', 'integer', Rule::exists('users', 'id')
                ->where(fn ($query) => $query->where('customer_id', $customerId)->where('role', 'teacher')->where('status', 'active'))],
            'delivery_mode' => ['required', Rule::in(['online', 'offline', 'hybrid'])],
            'scheduled_start_at' => ['required', 'date'],
            'scheduled_end_at' => ['required', 'date', 'after:scheduled_start_at'],
            'online_provider' => ['nullable', 'string', 'max:50', 'required_if:delivery_mode,online,hybrid'],
            'meeting_url' => ['nullable', 'url', 'max:2000', 'required_if:delivery_mode,online,hybrid'],
            'facility_name' => ['nullable', 'string', 'max:255'],
            'room_name' => ['nullable', 'string', 'max:255', 'required_if:delivery_mode,offline,hybrid'],
            'address' => ['nullable', 'string', 'max:2000', 'required_if:delivery_mode,offline,hybrid'],
        ];
    }

    private function validateActivity(int $customerId, int $versionId, int $lessonId, ?int $activityId): void
    {
        if (! $activityId) {
            return;
        }
        abort_unless(DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $versionId)
            ->where('version_lesson_id', $lessonId)
            ->where('activity_type', 'live_class')
            ->where('id', $activityId)->exists(), 422);
    }

    private function cohort(int $customerId, int $id): object
    {
        return DB::table('core_course_cohorts')
            ->where('customer_id', $customerId)->where('id', $id)->firstOrFail();
    }

    private function setupCohort(int $customerId, int $id): object
    {
        $cohort = $this->cohort($customerId, $id);
        abort_unless(in_array($cohort->status, ['draft', 'active'], true), 422,
            __('lf.LF_course_cohort_setup_status_invalid'));

        return $cohort;
    }

    private function activeCohort(int $customerId, int $id): object
    {
        $cohort = $this->cohort($customerId, $id);
        abort_unless($cohort->status === 'active', 422,
            __('lf.LF_course_cohort_runtime_requires_active'));

        return $cohort;
    }

    private function tab(int $cohort, string $tab, array $query = []): RedirectResponse
    {
        return redirect()->route('admin.course-cohorts.show', array_merge([
            'id' => $cohort,
            'tab' => $tab,
        ], $query));
    }
}
