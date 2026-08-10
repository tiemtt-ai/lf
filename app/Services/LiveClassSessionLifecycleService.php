<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    public function start(int $customerId, int $cohortId, int $sessionId): void
    {
        $this->transition($customerId, $cohortId, $sessionId, 'live');
    }

    public function complete(int $customerId, int $cohortId, int $sessionId): void
    {
        $this->transition($customerId, $cohortId, $sessionId, 'completed');
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
        ?string $reason = null
    ): void {
        DB::transaction(function () use ($customerId, $cohortId, $sessionId, $targetStatus, $reason): void {
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

            $now = now();
            $values = ['status' => $targetStatus, 'updated_at' => $now];

            // `actual_*` record what really happened and are written once by the
            // transition that observes it, never by the scheduling workflow.
            if ($targetStatus === 'live' && ! $session->actual_start_at) {
                $values['actual_start_at'] = $now;
            }
            if ($targetStatus === 'completed' && ! $session->actual_end_at) {
                $values['actual_end_at'] = $now;
            }
            if (in_array($targetStatus, ['cancelled', 'no_show'], true)) {
                $values['cancellation_reason'] = $reason;
            }

            DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)->where('id', $sessionId)
                ->update($values);
        }, 3);
    }
}
