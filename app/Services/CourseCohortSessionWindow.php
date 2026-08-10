<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * The window a Cohort Session may be scheduled into.
 *
 * `core_liveclass_sessions.md` § Cohort Schedule Boundary Amendment — 2026-08-04
 * fixes the rule: the minimum selectable start is
 * `MAX(cohort.start_date, CURRENT_DATE)` and the maximum end is the end of
 * `cohort.end_date`, enforced identically in the UI controls and in
 * authoritative backend validation.
 *
 * The Sessions tab used to re-derive this in a Blade `@php` block, which both
 * duplicated the rule and crashed conceptually on legacy Cohorts: parsing a
 * `NULL` operating date yields "now", so an incomplete Cohort silently got a
 * plausible-looking window instead of none. Returning `null` makes that case
 * explicit for every caller.
 */
final class CourseCohortSessionWindow
{
    /**
     * @return array{min: CarbonImmutable, max: CarbonImmutable}|null null when the
     *                                                                Cohort has no complete operating period
     */
    public static function for(object $cohort, ?string $timezone = null): ?array
    {
        if (! $cohort->start_date || ! $cohort->end_date) {
            return null;
        }

        $timezone ??= config('app.timezone');
        $cohortStart = CarbonImmutable::parse($cohort->start_date, $timezone)->startOfDay();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return [
            'min' => $today->greaterThan($cohortStart) ? $today : $cohortStart,
            'max' => CarbonImmutable::parse($cohort->end_date, $timezone)->endOfDay(),
        ];
    }
}
