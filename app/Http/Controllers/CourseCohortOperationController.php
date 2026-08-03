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
        $validated = $this->canonicalSessionBinding(
            $customerId,
            (int) $cohortRow->version_id,
            $validated
        );

        DB::transaction(function () use ($customerId, $cohort, $cohortRow, $validated, $request): void {
            $sessionNo = (int) DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)->where('cohort_id', $cohort)
                ->lockForUpdate()->max('session_no') + 1;

            $sessionId = DB::table('core_liveclass_sessions')->insertGetId(array_merge([
                'customer_id' => $customerId,
                'cohort_id' => $cohort,
                'template_version_id' => $cohortRow->version_id,
                'room_id' => null,
                'session_no' => $sessionNo,
                'timezone' => config('app.timezone'),
                'status' => 'scheduled',
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ], $this->sessionPayload($validated)));

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

    public function updateSession(Request $request, int $cohort, int $session): RedirectResponse
    {
        $customerId = $this->authorizeAdmin($request);
        $cohortRow = $this->setupCohort($customerId, $cohort);
        abort_if(! $cohortRow->version_id, 422);

        $validated = $request->validate($this->sessionRules($customerId, (int) $cohortRow->version_id));
        $validated = $this->canonicalSessionBinding(
            $customerId,
            (int) $cohortRow->version_id,
            $validated
        );

        DB::transaction(function () use ($customerId, $cohort, $session, $validated, $request): void {
            $sessionRow = DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)
                ->where('cohort_id', $cohort)
                ->where('id', $session)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(
                $this->sessionPolicy->canEdit(
                    $sessionRow,
                    $this->sessionHasEvidence($customerId, $session),
                    now()
                ),
                422,
                __('lf.LF_course_cohort_session_edit_locked')
            );

            DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)
                ->where('id', $session)
                ->update(array_merge($this->sessionPayload($validated), [
                    'updated_at' => now(),
                ]));

            DB::table('core_liveclass_session_teachers')
                ->where('customer_id', $customerId)
                ->where('session_id', $session)
                ->delete();

            if (! empty($validated['primary_teacher_id'])) {
                DB::table('core_liveclass_session_teachers')->insert([
                    'customer_id' => $customerId,
                    'session_id' => $session,
                    'teacher_id' => $validated['primary_teacher_id'],
                    'role' => 'primary_teacher',
                    'created_by' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return $this->tab($cohort, 'sessions')
            ->with('success', __('lf.LF_course_cohort_session_updated'));
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
            'session_type' => ['required', Rule::in(['curriculum', 'operational'])],
            'version_lesson_id' => ['nullable', 'required_if:session_type,curriculum', 'integer', Rule::exists('core_course_template_version_lessons', 'id')
                ->where(fn ($query) => $query->where('customer_id', $customerId)->where('template_version_id', $versionId))],
            'version_activity_id' => ['nullable', 'required_if:session_type,curriculum', 'integer'],
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

    private function canonicalSessionBinding(int $customerId, int $versionId, array $validated): array
    {
        if ($validated['session_type'] === 'operational') {
            $validated['version_lesson_id'] = null;
            $validated['version_activity_id'] = null;

            return $validated;
        }

        $lessonId = (int) $validated['version_lesson_id'];
        $activityId = (int) $validated['version_activity_id'];
        abort_unless(DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $versionId)
            ->where('id', $lessonId)
            ->exists(), 422);
        abort_unless(DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $versionId)
            ->where('version_lesson_id', $lessonId)
            ->where('activity_type', 'live_class')
            ->where('id', $activityId)->exists(), 422);

        $validated['version_lesson_id'] = $lessonId;
        $validated['version_activity_id'] = $activityId;

        return $validated;
    }

    private function sessionPayload(array $validated): array
    {
        $online = in_array($validated['delivery_mode'], ['online', 'hybrid'], true);
        $offline = in_array($validated['delivery_mode'], ['offline', 'hybrid'], true);

        return [
            'session_type' => $validated['session_type'],
            'version_lesson_id' => $validated['version_lesson_id'],
            'version_activity_id' => $validated['version_activity_id'],
            'primary_teacher_id' => $validated['primary_teacher_id'] ?? null,
            'title' => trim($validated['title']),
            'delivery_mode' => $validated['delivery_mode'],
            'scheduled_start_at' => $validated['scheduled_start_at'],
            'scheduled_end_at' => $validated['scheduled_end_at'],
            'online_provider' => $online ? ($validated['online_provider'] ?? null) : null,
            'meeting_url_snapshot' => $online ? ($validated['meeting_url'] ?? null) : null,
            'facility_name_snapshot' => $offline ? ($validated['facility_name'] ?? null) : null,
            'room_name_snapshot' => $offline ? ($validated['room_name'] ?? null) : null,
            'address_snapshot' => $offline ? ($validated['address'] ?? null) : null,
        ];
    }

    private function sessionHasEvidence(int $customerId, int $sessionId): bool
    {
        return DB::table('core_liveclass_attendances')
            ->where('customer_id', $customerId)->where('session_id', $sessionId)->exists()
            || DB::table('core_liveclass_recordings')
                ->where('customer_id', $customerId)->where('session_id', $sessionId)->exists();
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
