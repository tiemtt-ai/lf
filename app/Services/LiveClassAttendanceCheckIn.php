<?php

namespace App\Services;

use App\Support\LiveClassBoardContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * QR check-in for learners in the room (ADR-0002 § Attendance Check-In
 * Amendment). The presenter's board shows a code that changes every
 * WINDOW_SECONDS; a learner scans it with their own signed-in phone and one
 * Attendance row is written for their Enrollment. The code is an HMAC over
 * tenant, Session and time window, so nothing is stored for it.
 */
final class LiveClassAttendanceCheckIn
{
    public const WINDOW_SECONDS = 30;

    /** A code is accepted for two windows (at most 60 seconds) plus this margin for slow networks. */
    public const GRACE_SECONDS = 5;

    public const OPENS_BEFORE_MINUTES = 15;

    public const DELIVERY_MODES = ['offline', 'hybrid'];

    public static function enabled(): bool
    {
        return (bool) config('liveclass.attendance_enabled');
    }

    /** Whether this board offers the QR code: the presenter of an in-room Session that has a teacher, with attendance on. */
    public static function offeredOn(LiveClassBoardContext $context): bool
    {
        return self::enabled() && $context->canPresent && in_array($context->deliveryMode, self::DELIVERY_MODES, true)
            && self::sessionHasTeacher($context->customerId, $context->sessionId);
    }

    /**
     * An unassigned Session in an active Cohort takes no runtime evidence such as
     * Attendance until a teacher is assigned (LF-Core-LiveClass, Session setup).
     */
    public static function sessionHasTeacher(int $customerId, int $sessionId): bool
    {
        return DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->where('id', $sessionId)
            ->whereNotNull('primary_teacher_id')->exists()
            || DB::table('core_liveclass_session_teachers')->where('customer_id', $customerId)
                ->where('session_id', $sessionId)->exists();
    }

    /**
     * The current code and how long until the board should fetch the next one.
     *
     * @return array{code: string, refresh_in: int}
     */
    public function issue(LiveClassBoardContext $context, ?CarbonImmutable $now = null): array
    {
        abort_unless(self::offeredOn($context), 404);
        $seconds = ($now ?? CarbonImmutable::now())->getTimestamp();
        $window = intdiv($seconds, self::WINDOW_SECONDS);

        // Short on purpose: fewer characters make a QR code with fewer, bigger cells, readable from further away.
        return [
            'code' => base_convert((string) $window, 10, 36).'.'.$this->signature($context->customerId, $context->sessionId, $window),
            'refresh_in' => self::WINDOW_SECONDS - ($seconds % self::WINDOW_SECONDS),
        ];
    }

    public function accepts(int $customerId, int $sessionId, string $code, ?CarbonImmutable $now = null): bool
    {
        if (! preg_match('/^([0-9a-z]{1,10})\.([A-Za-z0-9_-]{14})$/', $code, $parts)) {
            return false;
        }
        $window = (int) base_convert($parts[1], 36, 10);
        $seconds = ($now ?? CarbonImmutable::now())->getTimestamp();
        $issuedAt = $window * self::WINDOW_SECONDS;
        $fresh = $seconds >= $issuedAt
            && $seconds < $issuedAt + 2 * self::WINDOW_SECONDS + self::GRACE_SECONDS;

        return $fresh && hash_equals($this->signature($customerId, $sessionId, $window), $parts[2]);
    }

    /** Learners who have checked in so far, and the Cohort's active learners, for the board. */
    public function counts(LiveClassBoardContext $context): array
    {
        return [
            'checked_in' => DB::table('core_liveclass_attendances')
                ->where('customer_id', $context->customerId)->where('session_id', $context->sessionId)
                ->whereIn('status', ['present', 'late'])->count(),
            'learners' => DB::table('core_course_cohort_students')
                ->where('customer_id', $context->customerId)->where('cohort_id', $context->cohortId)
                ->where('status', 'active')->whereNull('left_at')->count(),
        ];
    }

    /**
     * Records the check-in of an authorized learner (`learnerContext` already
     * checked membership, account, Enrollment and that the Session is live).
     * `$receivedAt` is when the server received the code, even when the learner
     * signed in afterwards. `result` is recorded, already, not_offered,
     * too_early or too_late.
     *
     * @return array{result: string, status?: string, joined_at?: string|null}
     */
    public function record(LiveClassBoardContext $context, CarbonImmutable $receivedAt): array
    {
        $session = DB::table('core_liveclass_sessions')
            ->where('customer_id', $context->customerId)->where('id', $context->sessionId)
            ->first(['id', 'delivery_mode', 'scheduled_start_at', 'scheduled_end_at', 'actual_start_at', 'timezone', 'version_activity_id']);
        if (! $session || ! in_array($session->delivery_mode, self::DELIVERY_MODES, true)
            || ! self::sessionHasTeacher($context->customerId, (int) $session->id)) {
            return ['result' => 'not_offered'];
        }

        $plannedStart = LiveClassSessionTime::utc((string) $session->scheduled_start_at, $session->timezone);
        $plannedEnd = LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone);
        $at = $receivedAt->utc();
        if ($at->lt($plannedStart->subMinutes(self::OPENS_BEFORE_MINUTES))) {
            return ['result' => 'too_early'];
        }
        if ($at->gt($plannedEnd)) {
            return ['result' => 'too_late'];
        }

        $start = $plannedStart;
        if ($session->actual_start_at) {
            $actual = LiveClassSessionTime::utc((string) $session->actual_start_at, $session->timezone);
            $start = $actual->gt($start) ? $actual : $start;
        }
        $status = $at->gt($start->addMinutes(max(0, (int) config('liveclass.late_after_minutes', 10)))) ? 'late' : 'present';

        $membership = LearnerCohortSeat::current($context->customerId, $context->cohortId, $context->userId);
        abort_unless($membership, 404);

        $result = DB::transaction(function () use ($context, $session, $membership, $status, $at): array {
            $key = [
                'customer_id' => $context->customerId,
                'session_id' => $context->sessionId,
                'enrollment_id' => (int) $membership->enrollment_id,
            ];
            $existing = DB::table('core_liveclass_attendances')->where($key)->lockForUpdate()
                ->first(['id', 'status', 'joined_at', 'attendance_source']);
            if ($existing && ($existing->joined_at !== null
                || ($existing->attendance_source === 'manual' && $existing->status !== 'registered'))) {
                return ['result' => 'already', 'status' => (string) $existing->status,
                    'joined_at' => $existing->joined_at ? (string) $existing->joined_at : null];
            }

            $joinedAt = $at->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            $values = [
                'user_id' => $context->userId,
                'version_activity_id' => $session->version_activity_id,
                'status' => $status,
                'attendance_mode' => 'offline',
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
                    // A second scan of the same learner won the race: the first one counts.
                    return ['result' => 'already', 'status' => $status, 'joined_at' => null];
                }
            }

            return ['result' => 'recorded', 'status' => $status, 'joined_at' => $joinedAt];
        });
        if ($result['result'] === 'recorded') {
            // A `join` Activity completes when the learner is present (ADR-0030 D3).
            app(CourseProgressRecorder::class)->attendanceChanged($context->customerId,
                [(int) $membership->enrollment_id], $session->version_activity_id ? (int) $session->version_activity_id : null);
        }

        return $result;
    }

    private function signature(int $customerId, int $sessionId, int $window): string
    {
        $mac = hash_hmac('sha256', 'lf-attendance-qr|'.$customerId.'|'.$sessionId.'|'.$window, (string) config('app.key'), true);

        // 80 bits: guessing one within the 65 seconds a code lives is out of reach, and the URL stays short.
        return rtrim(strtr(base64_encode(substr($mac, 0, 10)), '+/', '-_'), '=');
    }
}
