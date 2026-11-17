<?php

namespace App\Services;

use App\Support\LiveClassBoardContext;
use Carbon\CarbonImmutable;
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

    public const DELIVERY_MODES = ['offline', 'hybrid'];

    public function __construct(
        private readonly LiveClassAttendancePolicies $policies,
        private readonly LiveClassAttendanceRecorder $recorder,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('liveclass.attendance_enabled');
    }

    /**
     * Whether this board offers the QR code: the presenter of an in-room Session that has a teacher, with attendance
     * on, whose attendance tool is the board, in a Cohort whose policy lets the system record in-room entries.
     */
    public static function offeredOn(LiveClassBoardContext $context): bool
    {
        if (! self::enabled() || ! $context->canPresent || ! in_array($context->deliveryMode, self::DELIVERY_MODES, true)
            || ! self::sessionHasTeacher($context->customerId, $context->sessionId)) {
            return false;
        }
        $recorder = app(LiveClassAttendanceRecorder::class);
        $session = $recorder->session($context->customerId, $context->sessionId);

        return $session !== null && $recorder->offers(
            app(LiveClassAttendancePolicies::class)->forCohort($context->customerId, $context->cohortId), $session, 'qr');
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
     * The current code and how long until the board should fetch the next one. Past the policy's absent threshold
     * no code is issued (`closed`): a learner arriving that late is recorded by the teacher by hand.
     *
     * @return array{code: string|null, refresh_in: int, closed: bool}
     */
    public function issue(LiveClassBoardContext $context, ?CarbonImmutable $now = null): array
    {
        abort_unless(self::offeredOn($context), 404);
        $now ??= CarbonImmutable::now();
        $seconds = $now->getTimestamp();
        $window = intdiv($seconds, self::WINDOW_SECONDS);
        $session = $this->session($context);
        if ($session && $this->timing($context, $session)->isPastAbsentThreshold($now->utc())) {
            return ['code' => null, 'refresh_in' => 60, 'closed' => true];
        }

        // Short on purpose: fewer characters make a QR code with fewer, bigger cells, readable from further away.
        return [
            'code' => base_convert((string) $window, 10, 36).'.'.$this->signature($context->customerId, $context->sessionId, $window),
            'refresh_in' => self::WINDOW_SECONDS - ($seconds % self::WINDOW_SECONDS),
            'closed' => false,
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
     * signed in afterwards. The rules and the `result` values are those of
     * {@see LiveClassAttendanceRecorder::enter()}.
     *
     * @return array{result: string, status?: string, joined_at?: string|null}
     */
    public function record(LiveClassBoardContext $context, CarbonImmutable $receivedAt): array
    {
        $session = $this->session($context);
        if (! $session) {
            return ['result' => 'not_offered'];
        }
        $membership = LearnerCohortSeat::current($context->customerId, $context->cohortId, $context->userId);
        abort_unless($membership, 404);

        return $this->recorder->enter($session, $membership, 'qr', $receivedAt);
    }

    private function session(LiveClassBoardContext $context): ?object
    {
        return $this->recorder->session($context->customerId, $context->sessionId);
    }

    private function timing(LiveClassBoardContext $context, object $session): LiveClassAttendanceTiming
    {
        return LiveClassAttendanceTiming::of($this->policies->forCohort($context->customerId, $context->cohortId), $session);
    }

    private function signature(int $customerId, int $sessionId, int $window): string
    {
        $mac = hash_hmac('sha256', 'lf-attendance-qr|'.$customerId.'|'.$sessionId.'|'.$window, (string) config('app.key'), true);

        // 80 bits: guessing one within the 65 seconds a code lives is out of reach, and the URL stays short.
        return rtrim(strtr(base64_encode(substr($mac, 0, 10)), '+/', '-_'), '=');
    }
}
