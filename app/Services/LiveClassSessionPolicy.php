<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Every gate below compares normalized UTC instants, never the raw
 * `scheduled_*` column values: those are wall-clock time in the Session's own
 * `timezone`, so a Cohort mixing a Tokyo Schedule with a manually created
 * Ho Chi Minh Session would otherwise be evaluated in two different frames.
 */
class LiveClassSessionPolicy
{
    public function canEdit(object $session, bool $hasEvidence, CarbonInterface $now): bool
    {
        return ! $hasEvidence
            && $session->status === 'scheduled'
            && ! $session->actual_start_at
            && $this->startsAt($session)->gt($now);
    }

    /**
     * A draft Cohort cannot activate while an open Session has no Teacher, and
     * the general edit closes once the start time passes. Without this narrow
     * exception such a Session could never be released. Owner decision 2026-11-04.
     */
    public function canAssignLateTeachers(
        object $session,
        string $cohortStatus,
        bool $hasTeacher,
        bool $hasEvidence,
        CarbonInterface $now
    ): bool {
        return $cohortStatus === 'draft'
            && ! $hasTeacher
            && ! $hasEvidence
            && $session->status === 'scheduled'
            && ! $session->actual_start_at
            && $this->startsAt($session)->lte($now);
    }

    /** A Session whose start has passed stays where it is. Owner decision 2026-11-05. */
    public function canReschedule(object $session, CarbonInterface $now): bool
    {
        return $session->status === 'scheduled'
            && ! $session->actual_start_at
            && $this->startsAt($session)->gt($now);
    }

    /** ADR-0023 §4: this Session has happened, whatever its outcome, so its Activity keeps its place in the Cohort's order. */
    public function locksPosition(object $session, bool $hasEvidence, CarbonInterface $now): bool
    {
        return $this->positionLockReason($session, $hasEvidence, $now) !== null;
    }

    /** Why the Session locks its place, as the suffix of `LF_course_cohort_order_lock_*`; null when it does not. */
    public function positionLockReason(object $session, bool $hasEvidence, CarbonInterface $now): ?string
    {
        return match (true) {
            $hasEvidence => 'evidence',
            $session->status === 'live' => 'live',
            $session->status === 'completed' => 'completed',
            $session->status === 'no_show' => 'no_show',
            (bool) $session->actual_start_at => 'started',
            ! $this->startsAt($session)->lte($now) => null,
            $session->status === 'cancelled' => 'cancelled',
            default => 'past',
        };
    }

    public function canRecordAttendance(object $session, CarbonInterface $now): bool
    {
        return in_array($session->status, ['live', 'completed'], true)
            || ($session->status === 'scheduled' && $this->startsAt($session)->lte($now));
    }

    public function canCreateRecording(object $session, CarbonInterface $now): bool
    {
        return $session->status === 'completed'
            || ($session->status === 'scheduled' && $this->endsAt($session)->lte($now));
    }

    private function startsAt(object $session): CarbonImmutable
    {
        return LiveClassSessionTime::utc($session->scheduled_start_at, $session->timezone ?? null);
    }

    private function endsAt(object $session): CarbonImmutable
    {
        return LiveClassSessionTime::utc($session->scheduled_end_at, $session->timezone ?? null);
    }
}
