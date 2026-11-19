<?php

namespace App\Http\Controllers;

use App\Services\LiveClassAttendanceCheckIn;
use App\Services\LiveClassAttendancePolicies;
use App\Services\LiveClassAttendanceRecorder;
use App\Services\LiveClassSelfStudySession;
use App\Services\LiveClassSessionPolicy;
use App\Services\TeacherPermissions;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Manual attendance by a teacher (ADR-0002 § Attendance Policy And Recording Amendment, part B): every teacher with
 * an active assignment to the Cohort records and corrects results, from the start of a Session until the policy's
 * edit window closes, in an active or a completed class. The administrator keeps the Cohort attendance tab, without a
 * time limit.
 */
final class TeacherCohortAttendanceController extends Controller
{
    public function __construct(
        private readonly LiveClassAttendanceRecorder $recorder,
        private readonly LiveClassAttendancePolicies $policies,
        private readonly LiveClassSessionPolicy $sessionPolicy,
    ) {}

    public function show(Request $request, int $cohort): View
    {
        [$customerId, $cohortRow] = $this->authorize($request, $cohort);
        $sessions = $this->sessions($customerId, $cohort);
        $selected = $sessions->firstWhere('id', $request->integer('session_id')) ?? $sessions->last();
        $policy = $this->policies->forCohort($customerId, $cohort);
        $closesAt = $selected ? $this->recorder->teacherEditClosesAt($policy, $selected) : null;

        return view('teacher-cohorts.attendance', [
            'cohort' => $cohortRow,
            'sessions' => $sessions,
            'selected' => $selected,
            'policy' => $policy,
            'attendance' => $selected ? $this->recorder->roster($customerId, $cohort, (int) $selected->id) : collect(),
            'history' => $selected ? $this->recorder->history($customerId, (int) $selected->id) : collect(),
            'attendanceTool' => $selected ? LiveClassAttendanceRecorder::tool($selected) : 'board',
            'closesAt' => $closesAt,
            // Without `class.operate` the page only reads (ADR-0033 D3).
            'mayOperate' => $mayOperate = app(TeacherPermissions::class)->may($request->user(), 'class.operate'),
            'editable' => $mayOperate && $selected !== null && in_array($cohortRow->status, LiveClassAttendanceRecorder::MANUAL_COHORT_STATUSES, true) && CarbonImmutable::now()->utc()->lte($closesAt),
        ]);
    }

    public function save(Request $request, int $cohort, int $session): RedirectResponse
    {
        [$customerId, $cohortRow] = $this->authorize($request, $cohort);
        abort_unless(in_array($cohortRow->status, LiveClassAttendanceRecorder::MANUAL_COHORT_STATUSES, true), 422, __('lf.LF_teacher_attendance_closed_cohort'));
        $sessionRow = $this->sessions($customerId, $cohort)->firstWhere('id', $session);
        abort_unless($sessionRow, 422, __('lf.LF_course_cohort_attendance_session_invalid'));
        abort_unless(CarbonImmutable::now()->utc()->lte($this->recorder->teacherEditClosesAt(
            $this->policies->forCohort($customerId, $cohort), $sessionRow)), 422, __('lf.LF_teacher_attendance_closed_error'));

        $validated = $request->validate([
            'attendance' => ['required', 'array'],
            'attendance.*.enrollment_id' => ['required', 'integer'],
            'attendance.*.status' => ['required', Rule::in(['registered', ...LiveClassAttendanceRecorder::MANUAL_STATUSES])],
            'attendance.*.attendance_mode' => ['nullable', Rule::in(['online', 'offline'])],
            'attendance.*.notes' => ['nullable', 'string', 'max:1000'],
            'attendance.*.loaded_status' => ['nullable', Rule::in(['registered', ...LiveClassAttendanceRecorder::MANUAL_STATUSES])],
            'attendance.*.loaded_mode' => ['nullable', Rule::in(['online', 'offline'])],
            'attendance.*.loaded_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        ['skipped' => $skipped] = $this->recorder->saveManual($customerId, $cohort, $sessionRow, $validated['attendance'],
            (int) $request->user()->id);

        $message = __('lf.LF_course_cohort_attendance_saved');
        if ($skipped > 0) {
            $message .= ' '.__('lf.LF_course_cohort_attendance_skipped_inactive', ['count' => $skipped]);
        }

        return redirect()->route('teacher.cohorts.attendance', ['cohort' => $cohort, 'session_id' => $session])->with('success', $message);
    }

    /** @return array{0: int, 1: object} the tenant and the Cohort, for a teacher with an active assignment to it */
    private function authorize(Request $request, int $cohort): array
    {
        abort_unless(LiveClassAttendanceCheckIn::enabled(), 404);
        $customerId = (int) TenantContext::customerId();
        abort_if(! $customerId, 404);
        $user = $request->user();
        abort_unless($user?->role === 'teacher' && (int) $user->customer_id === $customerId, 403);
        // A Cohort of another tenant, or one this teacher is not assigned to, does not exist for them.
        $row = DB::table('core_course_cohorts')->where('customer_id', $customerId)->where('id', $cohort)
            ->first(['id', 'name', 'code', 'status']);
        abort_unless($row && $this->recorder->teacherBelongsToCohort($customerId, (int) $user->id, $cohort), 404);

        return [$customerId, $row];
    }

    /** Sessions of the Cohort that take attendance: started ones with a teacher, oldest first. */
    private function sessions(int $customerId, int $cohort): Collection
    {
        $now = now();

        return DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->where('cohort_id', $cohort)
            ->whereNull('superseded_by_session_id')->whereIn('status', ['scheduled', 'live', 'completed'])
            ->orderBy('scheduled_start_at')->orderBy('id')
            ->get(['id', 'customer_id', 'cohort_id', 'title', 'session_no', 'delivery_mode', 'attendance_tool', 'status',
                'scheduled_start_at', 'scheduled_end_at', 'actual_start_at', 'actual_end_at', 'timezone', 'version_activity_id',
                'session_type'])
            // A self-study Session has no teacher of its own; the class's teachers record it.
            ->filter(fn (object $session): bool => $this->sessionPolicy->canRecordAttendance($session, $now)
                && (LiveClassSelfStudySession::is($session) || LiveClassAttendanceCheckIn::sessionHasTeacher($customerId, (int) $session->id)))
            ->values();
    }
}
