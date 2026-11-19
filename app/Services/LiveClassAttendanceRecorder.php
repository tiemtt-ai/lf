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
 *
 * ADR-0022 Amendment 1.3, Đợt 3: when the Lesson of a Session has Activities ticked "Tính vào điểm danh" in its
 * "Trong" part, an entry gives a result only once the learner did them all by the planned end; until then the row
 * waits as `registered` with its `joined_at`. Doing every ticked "Sau" Activity after the Session is a make-up:
 * `present` with `attendance_source = makeup` ({@see contentChanged()}).
 *
 * Đợt 4, Bước 3: a self-study Session has no entry to time. Doing every ticked Activity of its lesson by the planned
 * end is `present`; later, within the make-up time, `present` by make-up. With nothing ticked the learner presses
 * "Điểm danh" during the Session's hours ({@see enterSelfStudy()}).
 */
final class LiveClassAttendanceRecorder
{
    /** The ways the system records an entry, with the attendance mode each one writes. */
    public const WAYS = ['qr' => 'offline', 'button' => 'offline', 'meeting' => 'online', 'board' => 'online'];

    /** What a teacher or administrator may record by hand. `registered` (no result yet) is not one of them. */
    public const MANUAL_STATUSES = ['present', 'late', 'absent', 'excused'];

    /**
     * Class statuses that still take manual attendance. A completed class is corrected to match what happened in
     * its Sessions; an archived one only reads (ADR-0022 Amendment 1.4, addendum 2026-11-19). Entry, QR, the
     * button and make-up stay with an active class.
     */
    public const MANUAL_COHORT_STATUSES = ['active', 'completed'];

    /** Automatic absence looks this far back, so a scheduler that was down for a while still catches up. */
    public const AUTO_ABSENT_LOOKBACK_DAYS = 7;

    /** Session statuses in which a learner may still enter. */
    private const OPEN_SESSION_STATUSES = ['scheduled', 'live'];

    public function __construct(
        private readonly LiveClassAttendancePolicies $policies,
        private readonly CourseProgressRecorder $progress,
        private readonly LiveClassAttendanceContent $content,
    ) {}

    /** The Session columns the recorder reads. */
    public function session(int $customerId, int $sessionId): ?object
    {
        return DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->where('id', $sessionId)
            ->first(['id', 'customer_id', 'cohort_id', 'delivery_mode', 'attendance_tool', 'status', 'scheduled_start_at',
                'scheduled_end_at', 'actual_start_at', 'actual_end_at', 'timezone', 'version_activity_id', 'version_lesson_id',
                'session_type']);
    }

    /**
     * `board` or `button`: how learners in the room of this Session check in. NULL in the row means the board.
     * A self-study Session has neither: `self_study`, recorded by the content or by its own button.
     */
    public static function tool(object $session): string
    {
        if (LiveClassSelfStudySession::is($session)) {
            return LiveClassSelfStudySession::TYPE;
        }

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
     * time of the Session is over); absent_closed (a QR scan past the absent threshold, refused). A recorded entry
     * that still waits for ticked content has `status` `registered` and `pending` true.
     *
     * @param  object  $membership  with enrollment_id and student_id
     * @return array{result: string, status?: string, joined_at?: string|null, mode?: string, pending?: bool}
     */
    public function enter(object $session, object $membership, string $way, CarbonImmutable $at): array
    {
        if (LiveClassSelfStudySession::is($session)) {
            return $this->enterSelfStudy($session, $membership, $way, $at);
        }
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
                || (in_array($existing->attendance_source, ['manual', 'makeup'], true) && $existing->status !== 'registered'))) {
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
            // The entry is kept; the result waits until the ticked "Trong" content is done (Đợt 3).
            $pending = $status !== 'absent' && ! $this->duringDone($session, (int) $membership->enrollment_id);
            if ($pending) {
                $status = 'registered';
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

            return ['result' => 'recorded', 'status' => $status, 'joined_at' => $joinedAt, 'mode' => $mode, 'pending' => $pending];
        });
        if ($result['result'] === 'recorded') {
            // A `join` Activity completes when the learner is present (ADR-0030 D3).
            $this->progress->attendanceChanged($customerId, [(int) $membership->enrollment_id],
                $session->version_activity_id ? (int) $session->version_activity_id : null);
        }

        return $result;
    }

    /**
     * The "Điểm danh" button of a self-study Session whose lesson ticks no Activity: pressed between the planned
     * start and end it records `present`; there is no late (MK 3.4). With ticked content the content decides, so the
     * button is not offered. `result` values as {@see enter()}.
     *
     * @return array{result: string, status?: string, joined_at?: string|null}
     */
    private function enterSelfStudy(object $session, object $membership, string $way, CarbonImmutable $at): array
    {
        $customerId = (int) $session->customer_id;
        $lessonId = (int) ($session->version_lesson_id ?? DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)->where('id', $session->id)->value('version_lesson_id'));
        if ($way !== 'button' || $session->status !== 'scheduled' || $this->content->tickedSelfStudy($customerId, $lessonId)->isNotEmpty()) {
            return ['result' => 'not_offered'];
        }
        $at = $at->utc();
        if ($at->lt(LiveClassSessionTime::utc((string) $session->scheduled_start_at, $session->timezone ?? null))) {
            return ['result' => 'too_early'];
        }
        if ($at->gt(LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone ?? null))) {
            return ['result' => 'too_late'];
        }

        return DB::transaction(function () use ($customerId, $session, $membership, $at): array {
            $key = ['customer_id' => $customerId, 'session_id' => (int) $session->id, 'enrollment_id' => (int) $membership->enrollment_id];
            $existing = DB::table('core_liveclass_attendances')->where($key)->lockForUpdate()->first(['id', 'status', 'joined_at']);
            if ($existing && ($existing->joined_at !== null || $existing->status !== 'registered')) {
                return ['result' => 'already', 'status' => (string) $existing->status,
                    'joined_at' => $existing->joined_at ? (string) $existing->joined_at : null];
            }
            $joinedAt = $at->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            $values = [
                'user_id' => (int) $membership->student_id, 'version_activity_id' => null, 'status' => 'present',
                'attendance_mode' => null, 'attendance_source' => 'system', 'joined_at' => $joinedAt,
                'recorded_by' => null, 'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('core_liveclass_attendances')->where('id', $existing->id)->update($values);
            } else {
                try {
                    DB::table('core_liveclass_attendances')->insert($key + $values + ['created_at' => now()]);
                } catch (UniqueConstraintViolationException) {
                    return ['result' => 'already', 'status' => 'present', 'joined_at' => null];
                }
            }

            return ['result' => 'recorded', 'status' => 'present', 'joined_at' => $joinedAt];
        });
    }

    /**
     * The roster of one Session: every active member of the Cohort with their result so far.
     *
     * @return Collection<int, object>
     */
    public function roster(int $customerId, int $cohortId, int $sessionId): Collection
    {
        $roster = $this->rosterRows($customerId, $cohortId, $sessionId);
        // ADR-0034: a Session outside a learner's time in the class takes no result; the row says why (`outside`).
        $session = DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->where('id', $sessionId)
            ->first(['scheduled_end_at', 'timezone']);
        $gaps = CourseCohortMembershipHistory::gaps($customerId, $cohortId, $roster->pluck('enrollment_id')->all());
        $end = $session ? LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone) : null;

        return $roster->each(function (object $row) use ($gaps, $end): void {
            $row->outside = $end ? CourseCohortMembershipHistory::outside($row->member_joined_at, null, $gaps->get($row->enrollment_id), $end) : null;
        });
    }

    /** @return Collection<int, object> */
    private function rosterRows(int $customerId, int $cohortId, int $sessionId): Collection
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
                'attendance.joined_at', 'attendance.attendance_source', 'memberships.joined_at as member_joined_at',
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
            ->get(['memberships.student_id', 'memberships.enrollment_id', 'memberships.joined_at', 'enrollments.status as enrollment_status'])
            ->keyBy('enrollment_id');
        $skipped = 0;
        $changed = [];
        $gaps = CourseCohortMembershipHistory::gaps($customerId, $cohortId, $memberships->keys()->map(fn ($id): int => (int) $id)->all());
        $sessionEnd = LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone ?? null);

        DB::transaction(function () use ($customerId, $session, $items, $memberships, $actorId, $gaps, $sessionEnd, &$skipped, &$changed): void {
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
                // ADR-0034: a Session outside the learner's time in the class takes no result, by hand either.
                $member = $memberships->get($item['enrollment_id']);
                if (CourseCohortMembershipHistory::outside($member->joined_at, null, $gaps->get($item['enrollment_id']), $sessionEnd) !== null) {
                    $skipped++;

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
                if ($status !== 'absent' && ! $this->duringDone($session, (int) $row->enrollment_id)) {
                    // No longer absent by the entry, but the ticked content is not done: the result waits again.
                    DB::table('core_liveclass_attendances')->where('id', $row->id)->update(['status' => 'registered', 'updated_at' => now()]);
                    $changed[] = (int) $row->enrollment_id;

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
                'sessions.timezone', 'sessions.version_activity_id', 'sessions.session_type']);

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
            // Nobody teaches a self-study Session and nobody records it by hand: the system always closes it.
            $taught = ! LiveClassSelfStudySession::is($session);
            if ($plannedEnd->lt($earliest) || $now->lt($plannedEnd->addMinutes((int) $policy->auto_absent_after_minutes))
                || ($taught && ($byHand || ! LiveClassAttendanceCheckIn::sessionHasTeacher($tenant, (int) $session->id)))) {
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
                    ->get(['memberships.enrollment_id', 'memberships.student_id', 'memberships.joined_at', 'attendance.id as attendance_id']);
                // ADR-0034: a learner who was out of the class when the Session ended is not marked absent.
                $gaps = CourseCohortMembershipHistory::gaps($tenant, (int) $session->cohort_id, $members->pluck('enrollment_id')->all());
                $end = LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone ?? null);
                $changed = [];
                foreach ($members as $member) {
                    if (CourseCohortMembershipHistory::outside($member->joined_at, null, $gaps->get($member->enrollment_id), $end) !== null) {
                        continue;
                    }
                    $values = [
                        'user_id' => (int) $member->student_id, 'version_activity_id' => $session->version_activity_id,
                        'status' => 'absent', 'attendance_mode' => null, 'attendance_source' => 'system', 'joined_at' => null,
                        'recorded_by' => null, 'updated_at' => now(),
                    ];
                    if ($member->attendance_id) {
                        // A row waiting for ticked content keeps the entry it recorded (Đợt 3).
                        DB::table('core_liveclass_attendances')->where('id', $member->attendance_id)
                            ->where('status', 'registered')
                            ->update(['status' => 'absent', 'attendance_source' => 'system', 'recorded_by' => null, 'updated_at' => now()]);
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
     * The learner's ticked content changed (an Activity completed, a quiz handed in or graded): each Session of
     * that Lesson in the learner's class is looked at again. A waiting entry becomes present or late, by the entry
     * time it kept, once every ticked "Trong" Activity was done by the planned end. Otherwise, after the planned end,
     * every ticked "Sau" Activity done within the policy's make-up time gives `present` with source `makeup`,
     * for a learner with no result, a waiting entry or an absence the system wrote; a result set by hand, an
     * excused absence and an earned present or late are never replaced.
     */
    public function contentChanged(int $customerId, int $enrollmentId, int $versionLessonId): void
    {
        if (! LiveClassAttendanceCheckIn::enabled()) {
            return;
        }
        $this->selfStudyContentChanged($customerId, $enrollmentId, $versionLessonId);
        $during = $this->content->ticked($customerId, $versionLessonId, 'during');
        $after = $this->content->ticked($customerId, $versionLessonId, 'after');
        if ($during->isEmpty() && $after->isEmpty()) {
            return;
        }

        $sessions = DB::table('core_liveclass_sessions as sessions')
            ->join('core_course_cohort_students as memberships', function ($join) use ($customerId): void {
                $join->on('memberships.cohort_id', '=', 'sessions.cohort_id')->where('memberships.customer_id', '=', $customerId);
            })
            ->join('core_course_cohorts as cohorts', function ($join) use ($customerId): void {
                $join->on('cohorts.id', '=', 'sessions.cohort_id')->where('cohorts.customer_id', '=', $customerId);
            })
            ->where('sessions.customer_id', $customerId)->where('sessions.session_type', 'curriculum')
            ->where('sessions.version_lesson_id', $versionLessonId)
            ->whereIn('sessions.status', ['scheduled', 'live', 'completed'])->whereNull('sessions.superseded_by_session_id')
            ->where('memberships.enrollment_id', $enrollmentId)->where('memberships.status', 'active')->whereNull('memberships.left_at')
            ->orderBy('sessions.id')
            ->get(['sessions.id', 'sessions.customer_id', 'sessions.cohort_id', 'sessions.delivery_mode', 'sessions.attendance_tool',
                'sessions.status', 'sessions.scheduled_start_at', 'sessions.scheduled_end_at', 'sessions.actual_start_at',
                'sessions.actual_end_at', 'sessions.timezone', 'sessions.version_activity_id', 'sessions.version_lesson_id',
                'memberships.student_id', 'memberships.joined_at as member_joined_at', 'cohorts.status as cohort_status']);

        $now = CarbonImmutable::now('UTC');
        foreach ($sessions as $session) {
            $policy = $this->policies->forCohort($customerId, (int) $session->cohort_id);
            $plannedEnd = LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone ?? null);
            $key = ['customer_id' => $customerId, 'session_id' => (int) $session->id, 'enrollment_id' => $enrollmentId];

            $changed = DB::transaction(function () use ($customerId, $enrollmentId, $session, $policy, $plannedEnd, $key, $during, $after, $now): bool {
                $row = DB::table('core_liveclass_attendances')->where($key)->lockForUpdate()
                    ->first(['id', 'status', 'joined_at', 'attendance_mode', 'attendance_source', 'notes']);

                if ($row && $row->status === 'registered' && $row->joined_at !== null && $row->attendance_source === 'system'
                    && $during->isNotEmpty() && $this->content->allDoneBy($customerId, $enrollmentId, $during, $plannedEnd)) {
                    // The button always counts from the planned start, as when the entry was taken.
                    $timing = LiveClassAttendanceTiming::of($policy, $session,
                        fromPlannedStart: $row->attendance_mode === 'offline' && self::tool($session) === 'button');
                    $status = $timing->statusAt(CarbonImmutable::parse((string) $row->joined_at, config('app.timezone'))->utc());
                    DB::table('core_liveclass_attendances')->where('id', $row->id)->update(['status' => $status, 'updated_at' => now()]);

                    return true;
                }

                $open = ! $row || $row->status === 'registered' || ($row->status === 'absent' && $row->attendance_source === 'system');
                if (! $open || $after->isEmpty() || ! $policy->makeup_enabled || $session->cohort_status !== 'active' || $now->lt($plannedEnd)
                    // A Session outside the learner's time in the class has no result and no make-up (ADR-0034).
                    || $this->outsideMembership($customerId, $session, $enrollmentId, $plannedEnd)
                    || ! LiveClassAttendanceCheckIn::sessionHasTeacher($customerId, (int) $session->id)
                    || ! $this->content->allDoneBy($customerId, $enrollmentId, $after, $this->makeupClosesAt($policy, $session) ?? $now)) {
                    return false;
                }

                $values = ['status' => 'present', 'attendance_source' => 'makeup', 'recorded_by' => null, 'updated_at' => now()];
                if ($row) {
                    DB::table('core_liveclass_attendances')->where('id', $row->id)->update($values);
                    $attendanceId = (int) $row->id;
                } else {
                    $attendanceId = (int) DB::table('core_liveclass_attendances')->insertGetId($key + $values + [
                        'user_id' => (int) $session->student_id, 'version_activity_id' => $session->version_activity_id,
                        'attendance_mode' => null, 'joined_at' => null, 'created_at' => now(),
                    ]);
                }
                $this->logChange($key, $attendanceId, $row ? (string) $row->status : null, 'present',
                    $row->attendance_mode ?? null, $row->attendance_mode ?? null, $row->notes ?? null, null);

                return true;
            });
            if ($changed) {
                $this->progress->attendanceChanged($customerId, [$enrollmentId],
                    $session->version_activity_id ? (int) $session->version_activity_id : null);
            }
        }
    }

    /**
     * {@see contentChanged()} for the Sessions of a self-study lesson (Đợt 4, Bước 3). Every ticked Activity done by
     * the planned end is `present`, recorded by the system at the moment the last one was done; done later, within the
     * policy's make-up time, `present` with source `makeup`. A result set by hand, an excused absence and an earned
     * present are never replaced; an absence the system wrote is.
     */
    private function selfStudyContentChanged(int $customerId, int $enrollmentId, int $versionLessonId): void
    {
        $ticked = $this->content->tickedSelfStudy($customerId, $versionLessonId);
        if ($ticked->isEmpty()) {
            return;
        }
        $doneAt = $this->content->lastDoneAt($customerId, $enrollmentId, $ticked);
        if ($doneAt === null) {
            return;
        }

        $sessions = DB::table('core_liveclass_sessions as sessions')
            ->join('core_course_cohort_students as memberships', function ($join) use ($customerId): void {
                $join->on('memberships.cohort_id', '=', 'sessions.cohort_id')->where('memberships.customer_id', '=', $customerId);
            })
            ->join('core_course_cohorts as cohorts', function ($join) use ($customerId): void {
                $join->on('cohorts.id', '=', 'sessions.cohort_id')->where('cohorts.customer_id', '=', $customerId);
            })
            ->where('sessions.customer_id', $customerId)->where('sessions.session_type', LiveClassSelfStudySession::TYPE)
            ->where('sessions.version_lesson_id', $versionLessonId)
            ->whereIn('sessions.status', ['scheduled', 'completed'])->whereNull('sessions.superseded_by_session_id')
            ->where('memberships.enrollment_id', $enrollmentId)->where('memberships.status', 'active')->whereNull('memberships.left_at')
            ->orderBy('sessions.id')
            ->get(['sessions.id', 'sessions.cohort_id', 'sessions.scheduled_end_at', 'sessions.timezone',
                'memberships.student_id', 'memberships.joined_at as member_joined_at', 'cohorts.status as cohort_status']);

        $now = CarbonImmutable::now('UTC');
        foreach ($sessions as $session) {
            $policy = $this->policies->forCohort($customerId, (int) $session->cohort_id);
            $plannedEnd = LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone ?? null);
            // A Session outside the learner's time in the class has no result (ADR-0034).
            if ($this->outsideMembership($customerId, $session, $enrollmentId, $plannedEnd)) {
                continue;
            }
            $inTime = $doneAt->lte($plannedEnd);
            $madeUp = ! $inTime && $policy->makeup_enabled && $session->cohort_status === 'active'
                && $doneAt->lte($this->makeupClosesAt($policy, $session) ?? $now);
            if (! $inTime && ! $madeUp) {
                continue;
            }
            $key = ['customer_id' => $customerId, 'session_id' => (int) $session->id, 'enrollment_id' => $enrollmentId];

            DB::transaction(function () use ($session, $key, $inTime, $doneAt): void {
                $row = DB::table('core_liveclass_attendances')->where($key)->lockForUpdate()
                    ->first(['id', 'status', 'attendance_source', 'notes']);
                $open = ! $row || $row->status === 'registered' || ($row->status === 'absent' && $row->attendance_source === 'system');
                if (! $open) {
                    return;
                }
                $values = [
                    'status' => 'present', 'attendance_source' => $inTime ? 'system' : 'makeup', 'attendance_mode' => null,
                    'joined_at' => $inTime ? $doneAt->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null,
                    'recorded_by' => null, 'updated_at' => now(),
                ];
                if ($row) {
                    DB::table('core_liveclass_attendances')->where('id', $row->id)->update($values);
                    $attendanceId = (int) $row->id;
                } else {
                    $attendanceId = (int) DB::table('core_liveclass_attendances')->insertGetId($key + $values + [
                        'user_id' => (int) $session->student_id, 'version_activity_id' => null, 'created_at' => now(),
                    ]);
                }
                // The first result the system writes is not a change; a make-up, and an absence turned present, are.
                if (! $inTime || ($row && $row->status === 'absent')) {
                    $this->logChange($key, $attendanceId, $row ? (string) $row->status : null, 'present', null, null, $row->notes ?? null, null);
                }
            });
        }
    }

    /**
     * The end of the make-up time of a Session: 23:59:59 of the Nth day after the day of the Session, in the Session's
     * own timezone. Null when the policy sets no limit: a make-up then counts until the class ends.
     */
    public function makeupClosesAt(object $policy, object $session): ?CarbonImmutable
    {
        if ($policy->makeup_within_days === null) {
            return null;
        }
        $timezone = $session->timezone ?: config('app.timezone');

        return LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone ?? null)
            ->setTimezone($timezone)->addDays((int) $policy->makeup_within_days)->endOfDay()->utc();
    }

    /** Whether the Session, ending at `$plannedEnd`, is outside the learner's time in the class (ADR-0034). */
    private function outsideMembership(int $customerId, object $session, int $enrollmentId, CarbonImmutable $plannedEnd): bool
    {
        $gaps = CourseCohortMembershipHistory::gaps($customerId, (int) $session->cohort_id, [$enrollmentId]);

        return CourseCohortMembershipHistory::outside($session->member_joined_at, null, $gaps->get($enrollmentId), $plannedEnd) !== null;
    }

    /** Whether the learner did every ticked "Trong" Activity of the Session's Lesson by the planned end. */
    private function duringDone(object $session, int $enrollmentId): bool
    {
        $customerId = (int) $session->customer_id;
        $lessonId = property_exists($session, 'version_lesson_id')
            ? $session->version_lesson_id
            : DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->where('id', $session->id)->value('version_lesson_id');
        if (! $lessonId) {
            return true;
        }
        $ticked = $this->content->ticked($customerId, (int) $lessonId, 'during');

        return $ticked->isEmpty() || $this->content->allDoneBy($customerId, $enrollmentId, $ticked,
            LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone ?? null));
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
