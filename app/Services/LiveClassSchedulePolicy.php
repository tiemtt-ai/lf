<?php

namespace App\Services;

class LiveClassSchedulePolicy
{
    public function canMutate(object $cohort): bool
    {
        return in_array($cohort->status, ['draft', 'active'], true);
    }

    public function isReadOnly(object $cohort): bool
    {
        return in_array($cohort->status, ['completed', 'archived'], true);
    }
}
