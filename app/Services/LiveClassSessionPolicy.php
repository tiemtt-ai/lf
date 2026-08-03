<?php

namespace App\Services;

use Carbon\CarbonInterface;

class LiveClassSessionPolicy
{
    public function canEdit(object $session, bool $hasEvidence, CarbonInterface $now): bool
    {
        return ! $hasEvidence
            && in_array($session->status, ['draft', 'scheduled'], true)
            && ! $session->actual_start_at
            && $session->scheduled_start_at > $now->format('Y-m-d H:i:s');
    }

    public function canReschedule(object $session): bool
    {
        return $session->status === 'scheduled';
    }

    public function canRecordAttendance(object $session, CarbonInterface $now): bool
    {
        return in_array($session->status, ['live', 'completed'], true)
            || ($session->status === 'scheduled'
                && $session->scheduled_start_at <= $now->format('Y-m-d H:i:s'));
    }

    public function canCreateRecording(object $session, CarbonInterface $now): bool
    {
        return $session->status === 'completed'
            || ($session->status === 'scheduled'
                && $session->scheduled_end_at <= $now->format('Y-m-d H:i:s'));
    }
}
