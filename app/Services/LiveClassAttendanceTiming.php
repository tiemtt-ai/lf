<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * When one Session accepts an entry and what that entry is worth, from the Cohort's attendance policy (ADR-0002
 * § Attendance Policy And Recording Amendment). Every way of recording an entry reads the same moments here.
 */
final class LiveClassAttendanceTiming
{
    private function __construct(
        /** Entries are accepted from here. */
        public readonly CarbonImmutable $opensAt,
        /** The start the thresholds count from: the planned start, or a later actual start when the policy says so. */
        public readonly CarbonImmutable $start,
        /** An entry after this moment is late. */
        public readonly CarbonImmutable $lateAfter,
        /** An entry after this moment is absent; null when the policy sets no limit. */
        public readonly ?CarbonImmutable $absentAfter,
        /** Entries are accepted until here: the planned end, or the moment the teacher ended the Session earlier. */
        public readonly CarbonImmutable $closesAt,
    ) {}

    /**
     * @param  object  $policy  a `core_liveclass_attendance_policies` row
     * @param  object  $session  with scheduled_start_at, scheduled_end_at, actual_start_at, actual_end_at, timezone
     * @param  bool  $fromPlannedStart  true for a way of entering that never waits for the teacher (the "Điểm danh" button)
     */
    public static function of(object $policy, object $session, bool $fromPlannedStart = false): self
    {
        $plannedStart = LiveClassSessionTime::utc((string) $session->scheduled_start_at, $session->timezone ?? null);
        $plannedEnd = LiveClassSessionTime::utc((string) $session->scheduled_end_at, $session->timezone ?? null);

        $start = $plannedStart;
        if (! $fromPlannedStart && $policy->count_from_actual_start && ! empty($session->actual_start_at)) {
            $actual = LiveClassSessionTime::utc((string) $session->actual_start_at, $session->timezone ?? null);
            $start = $actual->gt($start) ? $actual : $start;
        }
        $closesAt = $plannedEnd;
        if (! empty($session->actual_end_at)) {
            $ended = LiveClassSessionTime::utc((string) $session->actual_end_at, $session->timezone ?? null);
            $closesAt = $ended->lt($closesAt) ? $ended : $closesAt;
        }

        return new self(
            $plannedStart->subMinutes(max(0, (int) $policy->opens_before_minutes)),
            $start,
            $start->addMinutes(max(0, (int) $policy->late_after_minutes)),
            $policy->absent_after_minutes === null ? null : $start->addMinutes(max(0, (int) $policy->absent_after_minutes)),
            $closesAt,
        );
    }

    public function isBeforeOpening(CarbonImmutable $at): bool
    {
        return $at->lt($this->opensAt);
    }

    public function isAfterClosing(CarbonImmutable $at): bool
    {
        return $at->gt($this->closesAt);
    }

    public function isPastAbsentThreshold(CarbonImmutable $at): bool
    {
        return $this->absentAfter !== null && $at->gt($this->absentAfter);
    }

    /** `present`, `late` or `absent` for an entry at this moment. An early entry counts as on time. */
    public function statusAt(CarbonImmutable $at): string
    {
        return match (true) {
            $this->isPastAbsentThreshold($at) => 'absent',
            $at->gt($this->lateAfter) => 'late',
            default => 'present',
        };
    }
}
