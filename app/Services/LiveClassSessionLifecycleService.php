<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Canonical Session status machine.
 *
 * Values and transitions come from
 * `docs/database/liveclass/core_liveclass_sessions.md`
 * § Session Status And Time Convention Amendment — 2026-08-10, which retired
 * both `ended` and `draft`. `completed`, `cancelled` and `no_show` are terminal.
 *
 * Cancelled and no-show Sessions are retained forever: they keep any Origin and
 * continue to consume its occurrence identity, but they stop reserving their
 * time range for overlap validation.
 *
 * A Session status change is an operational act, so it requires an `active`
 * Cohort — the same boundary ADR-0001 puts on every other runtime operation.
 * Sessions in a `draft` Cohort are still setup data and are edited, not
 * transitioned.
 */
class LiveClassSessionLifecycleService
{
    private const TRANSITIONS = [
        'scheduled' => ['live', 'cancelled', 'no_show'],
        'live' => ['completed', 'cancelled', 'no_show'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    public function __construct(private readonly LiveClassWhiteboardLiveState $boards) {}

    public function start(int $customerId, int $cohortId, int $sessionId): void
    {
        $this->transition($customerId, $cohortId, $sessionId, 'live');
    }

    public function complete(int $customerId, int $cohortId, int $sessionId): void
    {
        $this->transition($customerId, $cohortId, $sessionId, 'completed');
    }

    /**
     * Ends every `live` Session whose scheduled end has passed (Owner 2026-11-11: the timetable decides when a
     * Session is over). The Session is recorded as ended at its scheduled end, not at the moment the sweep ran.
     * Only Sessions of an `active` Cohort: leaving `active` already closes the others' Whiteboards.
     *
     * @return int Sessions completed
     */
    public function completeOverdue(?int $customerId = null, ?CarbonInterface $now = null): int
    {
        $now ??= now();
        // `scheduled_end_at` is wall-clock in the Session's own timezone; the SQL bound only prefilters (UTC+14).
        $sessions = DB::table('core_liveclass_sessions as sessions')
            ->join('core_course_cohorts as cohorts', function ($join): void {
                $join->on('cohorts.id', '=', 'sessions.cohort_id')->on('cohorts.customer_id', '=', 'sessions.customer_id');
            })
            ->where('sessions.status', 'live')->where('cohorts.status', 'active')
            ->where('sessions.scheduled_end_at', '<=', $now->copy()->utc()->addHours(14)->format('Y-m-d H:i:s'))
            ->when($customerId !== null, fn ($query) => $query->where('sessions.customer_id', $customerId))
            ->orderBy('sessions.id')
            ->get(['sessions.id', 'sessions.customer_id', 'sessions.cohort_id', 'sessions.scheduled_end_at', 'sessions.timezone']);

        $completed = 0;
        foreach ($sessions as $session) {
            $end = Carbon::parse((string) $session->scheduled_end_at, $session->timezone ?: config('app.timezone'));
            if ($end->gt($now)) {
                continue;
            }
            try {
                $this->transition((int) $session->customer_id, (int) $session->cohort_id, (int) $session->id, 'completed',
                    endedAt: (string) $session->scheduled_end_at);
                $completed++;
            } catch (ValidationException|HttpException) {
                // Ended, cancelled or its Cohort left `active` since the read: nothing left to do for it.
            }
        }

        return $completed;
    }

    public function cancel(int $customerId, int $cohortId, int $sessionId, ?string $reason = null): void
    {
        $this->transition($customerId, $cohortId, $sessionId, 'cancelled', $reason);
    }

    public function markNoShow(int $customerId, int $cohortId, int $sessionId, ?string $reason = null): void
    {
        $this->transition($customerId, $cohortId, $sessionId, 'no_show', $reason);
    }

    private function transition(
        int $customerId,
        int $cohortId,
        int $sessionId,
        string $targetStatus,
        ?string $reason = null,
        ?string $endedAt = null
    ): void {
        DB::transaction(function () use ($customerId, $cohortId, $sessionId, $targetStatus, $reason, $endedAt): void {
            $cohort = DB::table('core_course_cohorts')
                ->where('customer_id', $customerId)->where('id', $cohortId)
                ->lockForUpdate()->first(['id', 'status']);
            abort_if(! $cohort, 404);
            abort_unless($cohort->status === 'active', 422,
                __('lf.LF_course_cohort_runtime_requires_active'));

            $session = DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)->where('cohort_id', $cohortId)
                ->where('id', $sessionId)->lockForUpdate()->first();
            abort_if(! $session, 404);

            if (! in_array($targetStatus, self::TRANSITIONS[$session->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'lifecycle' => __('lf.LF_course_cohort_session_lifecycle_invalid_transition', [
                        'from' => __('lf.LF_course_cohort_session_status_'.$session->status),
                        'to' => __('lf.LF_course_cohort_session_status_'.$targetStatus),
                    ]),
                ]);
            }

            // An unassigned Session takes no runtime evidence (LF-Core-LiveClass), so it does not go live either.
            if ($targetStatus === 'live' && ! LiveClassAttendanceCheckIn::sessionHasTeacher($customerId, $sessionId)) {
                throw ValidationException::withMessages([
                    'lifecycle' => __('lf.LF_course_cohort_session_lifecycle_needs_teacher'),
                ]);
            }

            $now = now();
            $values = ['status' => $targetStatus, 'updated_at' => $now];

            // `actual_*` record what really happened and are written once by the
            // transition that observes it, never by the scheduling workflow. Like
            // `scheduled_*` they are wall-clock in the Session's own timezone
            // (LF-Core-LiveClass § Session Time Convention), not the server's.
            $sessionClock = $now->copy()->setTimezone($session->timezone ?: config('app.timezone'))->format('Y-m-d H:i:s');
            if ($targetStatus === 'live' && ! $session->actual_start_at) {
                $values['actual_start_at'] = $sessionClock;
            }
            if ($targetStatus === 'completed' && ! $session->actual_end_at) {
                // A Session ended by its timetable never ends before it started (a late start past the scheduled end).
                $values['actual_end_at'] = $endedAt !== null ? max($endedAt, (string) ($session->actual_start_at ?? $endedAt)) : $sessionClock;
            }
            if (in_array($targetStatus, ['cancelled', 'no_show'], true)) {
                $values['cancellation_reason'] = $reason;
            }

            DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)->where('id', $sessionId)
                ->update($values);

            // Every way out of `live` closes the Whiteboard from this one point
            // (ADR-0002 § LiveClass Whiteboard Amendment), once the change is committed.
            if ($session->status === 'live') {
                DB::afterCommit(fn () => $this->boards->end($customerId, $sessionId));
            }

            // A cancelled or no-show Session no longer counts as attended (ADR-0030 D3).
            if (in_array($targetStatus, ['cancelled', 'no_show'], true) && $session->version_activity_id) {
                $enrollmentIds = DB::table('core_liveclass_attendances')
                    ->where('customer_id', $customerId)->where('session_id', $sessionId)
                    ->whereIn('status', ['present', 'late'])->pluck('enrollment_id')->map(fn ($id): int => (int) $id)->all();
                $activityId = (int) $session->version_activity_id;
                DB::afterCommit(fn () => app(CourseProgressRecorder::class)->attendanceChanged($customerId, $enrollmentIds, $activityId));
            }
        }, 3);
    }
}
