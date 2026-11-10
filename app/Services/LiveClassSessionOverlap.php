<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Whether an interval collides with a Session of the Cohort that still holds its time
 * (not cancelled, not no-show). One rule for every Session write: the admin forms and a
 * drag or cancel-shift saving a new Session on a Schedule date (ADR-0023 §3, §9).
 */
final class LiveClassSessionOverlap
{
    public static function exists(
        int $customerId,
        int $cohortId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $excludeSessionId = null
    ): bool {
        // Stored `scheduled_*` values are wall-clock in each row's own timezone,
        // so they cannot be compared in SQL. The bounds below are a coarse
        // prefilter only: a two-day margin exceeds the widest possible IANA
        // offset spread (-12..+14, i.e. 26 hours), so no genuinely overlapping
        // Session can fall outside it. The authoritative comparison happens in
        // PHP against normalized instants.
        return DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohortId)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->where('scheduled_start_at', '<', $end->addDays(2)->format('Y-m-d H:i:s'))
            ->where('scheduled_end_at', '>', $start->subDays(2)->format('Y-m-d H:i:s'))
            ->when($excludeSessionId !== null, fn ($query) => $query->where('id', '!=', $excludeSessionId))
            ->get(['scheduled_start_at', 'scheduled_end_at', 'timezone'])
            ->contains(fn (object $candidate): bool => LiveClassSessionTime::overlaps(
                LiveClassSessionTime::utc($candidate->scheduled_start_at, $candidate->timezone),
                LiveClassSessionTime::utc($candidate->scheduled_end_at, $candidate->timezone),
                $start,
                $end
            ));
    }
}
