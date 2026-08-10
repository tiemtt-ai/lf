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

    public function canReschedule(object $session): bool
    {
        return $session->status === 'scheduled';
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
