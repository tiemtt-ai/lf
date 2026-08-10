<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Canonical reader for LiveClass Session planned/actual timestamps.
 *
 * `core_liveclass_sessions.scheduled_*` and `actual_*` store local wall-clock
 * time interpreted in that row's own `timezone` column — not UTC instants. Two
 * Sessions in one Cohort may therefore carry different timezones, so comparing
 * the raw column values (in PHP or in SQL) is incorrect.
 *
 * Every comparison must go through this class. See
 * `docs/database/liveclass/core_liveclass_sessions.md`
 * § Session Status And Time Convention Amendment — 2026-08-10.
 *
 * Deliberately not used for Teacher availability: `core_course_cohort_teachers`
 * stores plain local DATE ranges, and the rule there compares the Session's
 * local calendar date — which the stored wall-clock string already is.
 */
final class LiveClassSessionTime
{
    public static function utc(string $localValue, ?string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($localValue, $timezone ?: config('app.timezone'))->utc();
    }

    /**
     * Half-open interval overlap: touching boundaries do not overlap.
     */
    public static function overlaps(
        CarbonImmutable $startA,
        CarbonImmutable $endA,
        CarbonImmutable $startB,
        CarbonImmutable $endB
    ): bool {
        return $startA->lt($endB) && $endA->gt($startB);
    }
}
