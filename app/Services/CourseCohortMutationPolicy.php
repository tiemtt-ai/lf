<?php

namespace App\Services;

/**
 * Single source of truth for "may this Cohort still be changed".
 *
 * `draft` and `active` accept authorized setup operations; `completed` and
 * `archived` are read-only. The rule comes from
 * `docs/database/course/core_course_cohorts.md` § Cohort Draft Setup And Tab UX
 * Amendment and applies uniformly to Overview, Students, Teachers, Schedules and
 * Sessions — it is not a Schedule-specific rule, which is why it no longer lives
 * in a Schedule-named class.
 *
 * Runtime operations are a stricter, separate gate: they require `active` and
 * are checked where they occur, not here.
 *
 * Views must never re-express this predicate. Controllers decorate the Cohort
 * row with `is_mutable` so a template can only read the decision, never restate
 * it and drift from the backend.
 */
final class CourseCohortMutationPolicy
{
    public static function canMutate(object $cohort): bool
    {
        return in_array($cohort->status, ['draft', 'active'], true);
    }

    public static function isReadOnly(object $cohort): bool
    {
        return ! self::canMutate($cohort);
    }

    /**
     * Decorate a Cohort row for presentation. Returns the same instance.
     */
    public static function decorate(object $cohort): object
    {
        $cohort->is_mutable = self::canMutate($cohort);

        return $cohort;
    }
}
