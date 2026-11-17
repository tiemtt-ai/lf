<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes Attendance (ADR-0002 § Attendance Policy And Recording Amendment, part B). Every entry the system records
 * — a QR scan, the "Điểm danh" button, "Vào phòng họp", opening the board — goes through {@see enter()}, so the four
 * ways share one set of rules. A result set by hand is never replaced here.
 */
final class LiveClassAttendanceRecorder
{
    /** The ways the system records an entry, with the attendance mode each one writes. */
    public const WAYS = ['qr' => 'offline', 'button' => 'offline', 'meeting' => 'online', 'board' => 'online'];

    /** What a teacher or administrator may record by hand. `registered` (no result yet) is not one of them. */
    public const MANUAL_STATUSES = ['present', 'late', 'absent', 'excused'];

    /** Automatic absence looks this far back, so a scheduler that was down for a while still catches up. */
    public const AUTO_ABSENT_LOOKBACK_DAYS = 7;

    /** Session statuses in which a learner may still enter. */
    private const OPEN_SESSION_STATUSES = ['scheduled', 'live'];

    public function __construct(
        private readonly LiveClassAttendancePolicies $policies,
        private readonly CourseProgressRecorder $progress,
    ) {}

    /** The Session columns the recorder reads. */
    public function session(int $customerId, int $sessionId): ?object
    {
        return DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->where('id', $sessionId)
            ->first(['id', 'customer_id', 'cohort_id', 'delivery_mode', 'attendance_tool', 'status', 'scheduled_start_at',
                'scheduled_end_at', 'actual_start_at', 'actual_end_at', 'timezone', 'version_activity_id']);
    }

    /** `board` or `button`: how learners in the room of this Session check in. NULL in the row means the board. */
    public static function tool(object $session): string
    {
        return ($session->attendance_tool ?? null) === 'button' ? 'button' : 'board';
    }

    /**
     * Whether this way of entering is offered for the Session at all: the delivery mode, the Session's tool and the
     * policy's "recorded by the system" setting for that mode. Time is not looked at here.
     */
    public function offers(object $policy, object $session, string $way): bool
    {
        $mode = (string) $session->delivery_mode;
        $inRoom = in_array($mode, ['offline', 'hybrid'], true) && $policy->offline_recording === 'system';
        $remote = in_array($mode, ['online', 'hybrid'], true) && $policy->online_recording === 'system';

        return match ($way) {
            'qr' => $inRoom && self::tool($session) === 'board',
            'button' => $inRoom && self::tool($session) === 'button',
            'meeting' => $remote,
            // Opening the board counts only where nobody is in the room. In a hybrid Session a learner in the room
            // may watch the board on their own laptop, so it proves nothing there (Owner 2026-11-17).
            'board' => $remote && $mode === 'online',
            default => false,
        };
    }

    /**
     * Records the entry of a learner the caller has already authorized (seat, account and Enrollment). `result` is:
     * recorded; already (the first entry or a manual result stands); mode_changed (a hybrid learner recorded as
     * online who now checks in inside the room: only the mode changes); not_offered; too_early; too_late (the entry
     * time of the Session is over); absent_closed (a QR scan past the absent threshold, refused).
     *
     * @param  object  $membership  with enrollment_id and student_id
     * @return array{result: string, status?: string, joined_at?: string|null, mode?: string}
     */
    public function enter(object $session, object $membership, string $way, CarbonImmutable $at): array
    {
        $customerId = (int) $session->customer_id;
        $policy = $this->policies->forCohort($customerId, (int) $session->cohort_id);
        if (! isset(self::WAYS[$way]) || ! $this->offers($policy, $session, $way)
            || ! in_array($session->status, self::OPEN_SESSION_STATUSES, true)
            || ! LiveClassAttendanceCheckIn::sessionHasTeacher($customerId, (int) $session->id)) {
            return ['result' => 'not_offered'];
        }

        // The "Điểm danh" button never waits for the teacher: it always counts from the planned start.
        $timing = LiveClassAttendanceTiming::of($policy, $session, fromPlannedStart: $way === 'button');
        $at = $at->utc();
        if ($timing->isBeforeOpening($at)) {
            return ['result' => 'too_early'];
        }
        if ($timing->isAfterClosing($at)) {
            return ['result' => 'too_late'];
        }
        $mode = self::WAYS[$way];
        $status = $timing->statusAt($at);

        $result = DB::transaction(function () use ($customerId, $session, $membership, $way, $mode, $status, $at): array {
            $key = ['customer_id' => $customerId, 'session_id' => (int) $session->id, 'enrollment_id' => (int) $membership->enrollment_id];
            $existing = DB::table('core_liveclass_attendances')->where($key)->lockForUpdate()
                ->first(['id', 'status', 'joined_at', 'attendance_mode', 'attendance_source', 'notes']);

            if ($existing && ($existing->joined_at !== null
                || ($existing->attendance_source === 'manual' && $existing->status !== 'registered'))) {
                // An in-room check-in proves presence in the room: a hybrid learner the system recorded as online
                // keeps the entry time and the result, and only the mode changes.
                if ($mode === 'offline' && $session->delivery_mode === 'hybrid' && $existing->attendance_source === 'system'
                    && $existing->attendance_mode === 'online' && in_array($existing->status, self::MANUAL_STATUSES, true)) {
                    DB::table('core_liveclass_attendances')->where('id', $existing->id)
                        ->update(['attendance_mode' => 'offline', 'updated_at' => now()]);
                    $this->logChange($key, (int) $existing->id, (string) $existing->status, (string) $existing->status,
                        'online', 'offline', $existing->notes, null);

                    return ['result' => 'mode_changed', 'status' => (string) $existing->status,
                        'joined_at' => (string) $existing->joined_at, 'mode' => 'offline'];
                }

                return ['result' => 'already', 'status' => (string) $existing->status,
                    'joined_at' => $existing->joined_at ? (string) $existing->joined_at : null];
            }
            // Past the absent threshold the board no longer shows the code; a scan of a code shown just before is
            // refused, and the teacher records that learner by hand. The other ways let the learner in and record absent.
            if ($way === 'qr' && $status === 'absent') {
                return ['result' => 'absent_closed'];
            }

            $joinedAt = $at->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            $values = [
                'user_id' => (int) $membership->student_id,
                'version_activity_id' => $session->version_activity_id,
                'status' => $status,
                'attendance_mode' => $mode,
                'attendance_source' => 'system',
                'joined_at' => $joinedAt,
                'recorded_by' => null,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('core_liveclass_attendances')->where('id', $existing->id)->update($values);
            } else {
                try {
                    DB::table('core_liveclass_attendances')->insert($key + $values + ['created_at' => now()]);
                } catch (UniqueConstraintViolationException) {
                    // A second entry of the same learner won the race: the first one counts.
                    return ['result' => 'already', 'status' => $status, 'joined_at' => null];
                }
            }

            return ['result' => 'recorded', 'status' => $status, 'joined_at' => $joinedAt, 'mode' => $mode];
        });
        if ($result['result'] === 'recorded') {
            // A `join` Activity completes when the learner is present (ADR-0030 D3).
            $this->progress->attendanceChanged($customerId, [(int) $membership->enrollment_id],
                $session->version_activity_id ? (int) $session->version_activity_id : null);
        }

        return $result;
    }

    /**
     * The roster of one Session: every active member of the Cohort with their result so far.
     *
     * @return Collection<int, object>
     */
    public function roster(int $customerId, int $cohortId, int $sessionId): Collection
    {
        return DB::table('core_course_cohort_students as memberships')
            ->join('core_course_enrollments as enrollments', function ($join) use ($customerId): void {
                $join->on('enrollments.id', '=', 'memberships.enrollment_id')->where('enrollments.customer_id', '=', $customerId);
            })
            ->join('users as students', function ($join) use ($customerId): void {
                $join->on('students.id', '=', 'memberships.student_id')->where('students.customer_id', '=', $customerId);
            })
            ->leftJoin('core_liveclass_attendances as attendance', function ($join) use ($customerId, $sessionId): void {
                $join->on('attendance.enrollment_id', '=', 'memberships.enrollment_id')
                    ->where('attendance.customer_id', '=', $customerId)->where('attendance.session_id', '=', $sessionId);
            })
            ->where('memberships.customer_id', $customerId)->where('memberships.cohort_id', $cohortId)
            ->where('memberships.status', 'active')
            ->orderBy('students.name')
            ->get([
                'enrollments.id as enrollment_id', 'students.name as student_name', 'students.email as student_email',
                'attendance.status as attendance_status', 'attendance.attendance_mode', 'attendance.notes',
                'attendance.joined_at', 'attendance.attendance_source',
            ]);
    }

    /**
     * The edit history of one Session, newest first.
     *
     * @return Collection<int, object>
     */
    public function history(int $customerId, int $sessionId): Collection
    {
        return DB::table('core_liveclass_attendance_changes as changes')
            ->join('core_course_enrollments as enrollments', function ($join) use ($customerId): void {
                $join->on('enrollments.id', '=', 'changes.enrollment_id')->where('enrollments.customer_id', '=', $customerId);
            })
            ->join('users as students', function ($join) use ($customerId): void {
                $join->on('students.id', '=', 'enrollments.student_id')->where('students.customer_id', '=', $customerId);
            })
            ->leftJoin('users as actors', function ($join) use ($customerId): void {
                $join->on('actors.id', '=', 'changes.changed_by')->where('actors.customer_id', '=', $customerId);
            })
            ->where('changes.customer_id', $customerId)->where('changes.session_id', $sessionId)
            ->orderByDesc('changes.created_at')->orderByDesc('changes.id')
            ->limit(200)
            ->get([
                'changes.old_status', 'changes.new_status', 'changes.old_attendance_mode', 'changes.new_attendance_mode',
                'changes.notes', 'changes.change_source', 'changes.created_at',
                'students.name as student_name', 'actors.name as actor_name',
            ]);
    }

    /**
     * Whether this teacher holds an active assignment to the Cohort: every such teacher may record attendance by
     * hand, not only the teachers of the Session (Owner decision 2026-11-17).
     */
    public function teacherBelongsToCohort(int $customerId, int $teacherId, int $cohortId): bool
    {
        return DB::table('core_course_cohort_teachers as assignments')
            ->join('users as teachers', function ($join) use ($customerId): void {
                $join->on('teachers.id', '=', 'assignments.teacher_id')->where('teachers.customer_id', '=', $customerId)
                    ->where('teachers.role', '=', 'teacher')->where('teachers.status', '=', 'active');
            })
            ->where('assignments.customer_id', $customerId)->where('assignments.cohort_id', $cohortId)
            ->where('assignments.teacher_id', $teacherId)->where('assignments.status', 'active')
            ->exists();
    }

    /**
     * The moment a teacher's manual recording closes: `teacher_edit_days` × 24 hours after the planned end of the
     * Session. An administrator has no limit.
     */
    public function teacherEditClosesAt(object $policy, object $session): CarbonImmutable
    {
        return LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone ?? null)
            ->addHours(24 * max(0, (int) $policy->teacher_edit_days));
    }

    /**
     * Saves what a teacher or administrator set by hand. A row they did not touch is left alone, so an entry the
     * system recorded after the page was opened is never overwritten by a stale form; an unchanged row keeps its
     * source, mode and entry time. A result is never set back to "not recorded yet". Every row written adds a
     * history row.
     *
     * @param  array<int, array<string, mixed>>  $items  enrollment_id, status, attendance_mode, notes and the loaded_* values the page showed
     * @return array{changed: array<int, int>, skipped: int} changed Enrollment ids; rows skipped because their Enrollment is not active
     */
    public function saveManual(int $customerId, int $cohortId, object $session, array $items, int $actorId): array
    {
        $memberships = DB::table('core_course_cohort_students as memberships')
            ->join('core_course_enrollments as enrollments', function ($join) use ($customerId): void {
                $join->on('enrollments.id', '=', 'memberships.enrollment_id')->where('enrollments.customer_id', '=', $customerId);
            })
            ->where('memberships.customer_id', $customerId)->where('memberships.cohort_id', $cohortId)
            ->where('memberships.status', 'active')
            ->get(['memberships.student_id', 'memberships.enrollment_id', 'enrollments.status as enrollment_status'])
            ->keyBy('enrollment_id');
        $skipped = 0;
        $changed = [];

        DB::transaction(function () use ($customerId, $session, $items, $memberships, $actorId, &$skipped, &$changed): void {
            $existing = DB::table('core_liveclass_attendances')
                ->where('customer_id', $customerId)->where('session_id', $session->id)->lockForUpdate()
                ->get(['id', 'enrollment_id', 'status', 'attendance_mode', 'notes'])->keyBy('enrollment_id');
            foreach ($items as $index => $item) {
                abort_unless($memberships->has($item['enrollment_id']), 422);
                $values = [
                    'status' => $item['status'],
                    'attendance_mode' => $item['attendance_mode'] ?? null,
                    'notes' => ($item['notes'] ?? '') === '' ? null : $item['notes'],
                ];
                if (array_key_exists('loaded_status', $item)
                    && $values['status'] === ($item['loaded_status'] ?? 'registered')
                    && $values['attendance_mode'] === ($item['loaded_mode'] ?? null)
                    && $values['notes'] === (($item['loaded_notes'] ?? '') === '' ? null : $item['loaded_notes'])) {
                    continue;
                }
                // Only an active Enrollment is recorded (DOC-CONFLICT-0011); the others are left as they are.
                if ($memberships->get($item['enrollment_id'])->enrollment_status !== 'active') {
                    $skipped++;

                    continue;
                }
                $row = $existing->get($item['enrollment_id']);
                $current = $row ? (string) $row->status : 'registered';
                // An unchanged row keeps its source, mode and entry time (a QR scan stays a scan).
                if ($current === $values['status'] && ($row->attendance_mode ?? null) === $values['attendance_mode']
                    && ((($row->notes ?? '') === '') ? null : $row->notes) === $values['notes']) {
                    continue;
                }
                if ($values['status'] === 'registered') {
                    // No result yet and still none: nothing to write. A recorded result cannot be taken back.
                    if ($current === 'registered') {
                        continue;
                    }
                    throw ValidationException::withMessages([
                        "attendance.{$index}.status" => __('lf.LF_course_cohort_attendance_no_reset'),
                    ]);
                }

                $write = $values + [
                    'user_id' => $memberships->get($item['enrollment_id'])->student_id,
                    'version_activity_id' => $session->version_activity_id,
                    'attendance_source' => 'manual',
                    'recorded_by' => $actorId,
                    'updated_at' => now(),
                ];
                $key = ['customer_id' => $customerId, 'session_id' => (int) $session->id, 'enrollment_id' => (int) $item['enrollment_id']];
                if ($row) {
                    DB::table('core_liveclass_attendances')->where('id', $row->id)->update($write);
                    $attendanceId = (int) $row->id;
                } else {
                    $attendanceId = (int) DB::table('core_liveclass_attendances')->insertGetId($key + $write + ['created_at' => now()]);
                }
                $this->logChange($key, $attendanceId, $row ? (string) $row->status : null, $values['status'],
                    $row->attendance_mode ?? null, $values['attendance_mode'], $values['notes'], $actorId);
                $changed[] = (int) $item['enrollment_id'];
            }
        });
        // A `join` Activity follows Attendance both ways (ADR-0030 D3).
        $this->progress->attendanceChanged($customerId, $changed,
            $session->version_activity_id ? (int) $session->version_activity_id : null);

        return ['changed' => $changed, 'skipped' => $skipped];
    }

    /**
     * The teacher started later than planned: results the system wrote for online entries are evaluated once more
     * against the new start, so a learner who entered before the teacher is not left late or absent. Manual results
     * and in-room entries (the button always counts from the planned start; a QR scan needs a live Session) are not
     * touched. Each changed result adds a system history row.
     *
     * @return int results changed
     */
    public function reevaluateAfterStart(int $customerId, int $sessionId): int
    {
        $session = $this->session($customerId, $sessionId);
        if (! $session || empty($session->actual_start_at)) {
            return 0;
        }
        $policy = $this->policies->forCohort($customerId, (int) $session->cohort_id);
        $timing = LiveClassAttendanceTiming::of($policy, $session);
        if (! $policy->count_from_actual_start
            || ! $timing->start->gt(LiveClassSessionTime::utc((string) $session->scheduled_start_at, $session->timezone ?? null))) {
            return 0;
        }

        $changed = DB::transaction(function () use ($customerId, $session, $timing): array {
            $changed = [];
            $rows = DB::table('core_liveclass_attendances')->where('customer_id', $customerId)->where('session_id', $session->id)
                ->where('attendance_source', 'system')->where('attendance_mode', 'online')->whereNotNull('joined_at')
                ->whereIn('status', ['late', 'absent'])->lockForUpdate()
                ->get(['id', 'enrollment_id', 'status', 'joined_at', 'notes']);
            foreach ($rows as $row) {
                $status = $timing->statusAt(CarbonImmutable::parse((string) $row->joined_at, config('app.timezone'))->utc());
                if ($status === $row->status) {
                    continue;
                }
                DB::table('core_liveclass_attendances')->where('id', $row->id)->update(['status' => $status, 'updated_at' => now()]);
                $this->logChange(['customer_id' => $customerId, 'session_id' => (int) $session->id, 'enrollment_id' => (int) $row->enrollment_id],
                    (int) $row->id, (string) $row->status, $status, 'online', 'online', $row->notes, null);
                $changed[] = (int) $row->enrollment_id;
            }

            return $changed;
        });
        $this->progress->attendanceChanged($customerId, $changed, $session->version_activity_id ? (int) $session->version_activity_id : null);

        return count($changed);
    }

    /**
     * Automatic absence: once the planned end of a Session plus the policy's delay has passed, every active member
     * with an active Enrollment and no result gets `absent`, recorded by the system. Skipped: a Session that ended
     * before `liveclass.auto_absent_from` (results are not invented for the time before the feature existed) or more
     * than AUTO_ABSENT_LOOKBACK_DAYS ago, a cancelled or unassigned Session, a member who joined the Cohort after
     * the Session ended, and a Session whose delivery mode the policy leaves to the teacher to record by hand.
     *
     * @return int results written
     */
    public function markAbsent(?int $customerId = null, ?CarbonInterface $now = null): int
    {
        $from = config('liveclass.auto_absent_from');
        if (! LiveClassAttendanceCheckIn::enabled() || ! $from) {
            return 0;
        }
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $from = CarbonImmutable::parse((string) $from, 'UTC');
        $earliest = $now->subDays(self::AUTO_ABSENT_LOOKBACK_DAYS);
        $earliest = $from->gt($earliest) ? $from : $earliest;

        // `scheduled_end_at` is wall-clock in the Session's own timezone; the SQL bounds only prefilter (UTC±14).
        $sessions = DB::table('core_liveclass_sessions as sessions')
            ->join('core_course_cohorts as cohorts', function ($join): void {
                $join->on('cohorts.id', '=', 'sessions.cohort_id')->on('cohorts.customer_id', '=', 'sessions.customer_id');
            })
            ->whereIn('sessions.status', ['scheduled', 'live', 'completed'])->whereNull('sessions.superseded_by_session_id')
            ->whereIn('cohorts.status', ['active', 'completed'])
            ->whereBetween('sessions.scheduled_end_at', [$earliest->subHours(14)->format('Y-m-d H:i:s'), $now->addHours(14)->format('Y-m-d H:i:s')])
            ->when($customerId !== null, fn ($query) => $query->where('sessions.customer_id', $customerId))
            ->orderBy('sessions.id')
            ->get(['sessions.id', 'sessions.customer_id', 'sessions.cohort_id', 'sessions.delivery_mode', 'sessions.scheduled_end_at',
                'sessions.timezone', 'sessions.version_activity_id']);

        $written = 0;
        foreach ($sessions as $session) {
            $tenant = (int) $session->customer_id;
            $plannedEnd = LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone ?? null);
            $policy = $this->policies->forCohort($tenant, (int) $session->cohort_id);
            $manual = ['offline' => $policy->offline_recording === 'manual', 'online' => $policy->online_recording === 'manual'];
            $byHand = match ($session->delivery_mode) {
                'offline' => $manual['offline'],
                'online' => $manual['online'],
                default => $manual['offline'] && $manual['online'],
            };
            if ($plannedEnd->lt($earliest) || $now->lt($plannedEnd->addMinutes((int) $policy->auto_absent_after_minutes))
                || $byHand || ! LiveClassAttendanceCheckIn::sessionHasTeacher($tenant, (int) $session->id)) {
                continue;
            }

            $joinedBefore = $plannedEnd->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            $changed = DB::transaction(function () use ($tenant, $session, $joinedBefore): array {
                $members = DB::table('core_course_cohort_students as memberships')
                    ->join('core_course_enrollments as enrollments', function ($join) use ($tenant): void {
                        $join->on('enrollments.id', '=', 'memberships.enrollment_id')->where('enrollments.customer_id', '=', $tenant)
                            ->where('enrollments.status', '=', 'active');
                    })
                    ->leftJoin('core_liveclass_attendances as attendance', function ($join) use ($tenant, $session): void {
                        $join->on('attendance.enrollment_id', '=', 'memberships.enrollment_id')
                            ->where('attendance.customer_id', '=', $tenant)->where('attendance.session_id', '=', $session->id);
                    })
                    ->where('memberships.customer_id', $tenant)->where('memberships.cohort_id', $session->cohort_id)
                    ->where('memberships.status', 'active')->whereNull('memberships.left_at')
                    ->where('memberships.joined_at', '<=', $joinedBefore)
                    ->where(fn ($query) => $query->whereNull('attendance.id')->orWhere('attendance.status', 'registered'))
                    ->get(['memberships.enrollment_id', 'memberships.student_id', 'attendance.id as attendance_id']);
                $changed = [];
                foreach ($members as $member) {
                    $values = [
                        'user_id' => (int) $member->student_id, 'version_activity_id' => $session->version_activity_id,
                        'status' => 'absent', 'attendance_mode' => null, 'attendance_source' => 'system', 'joined_at' => null,
                        'recorded_by' => null, 'updated_at' => now(),
                    ];
                    if ($member->attendance_id) {
                        DB::table('core_liveclass_attendances')->where('id', $member->attendance_id)
                            ->where('status', 'registered')->update($values);
                    } else {
                        // A learner entering at this very moment wins: their row is left alone.
                        DB::table('core_liveclass_attendances')->insertOrIgnore($values + [
                            'customer_id' => $tenant, 'session_id' => (int) $session->id, 'enrollment_id' => (int) $member->enrollment_id,
                            'created_at' => now(),
                        ]);
                    }
                    $changed[] = (int) $member->enrollment_id;
                }

                return $changed;
            });
            $this->progress->attendanceChanged($tenant, $changed, $session->version_activity_id ? (int) $session->version_activity_id : null);
            $written += count($changed);
        }

        return $written;
    }

    /**
     * One append-only history row (core_liveclass_attendance_changes). `$actorId` null is a change by the system.
     *
     * @param  array{customer_id: int, session_id: int, enrollment_id: int}  $key
     */
    public function logChange(array $key, int $attendanceId, ?string $oldStatus, string $newStatus, ?string $oldMode,
        ?string $newMode, ?string $notes, ?int $actorId): void
    {
        DB::table('core_liveclass_attendance_changes')->insert($key + [
            'attendance_id' => $attendanceId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'old_attendance_mode' => $oldMode,
            'new_attendance_mode' => $newMode,
            'notes' => $notes === null || $notes === '' ? null : mb_substr($notes, 0, 1000),
            'change_source' => $actorId === null ? 'system' : 'manual',
            'changed_by' => $actorId,
            'created_at' => now(),
        ]);
    }
}
