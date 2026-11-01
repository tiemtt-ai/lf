<?php

namespace App\Support;

/**
 * The course type owned by the Course Template and frozen into each Version (ADR-0022).
 * It decides which learning availability an Activity may declare.
 */
final class CourseType
{
    public const SELF_PACED = 'self_paced';

    public const SCHEDULED = 'scheduled';

    public const BLENDED = 'blended';

    public const VALUES = [self::SELF_PACED, self::SCHEDULED, self::BLENDED];

    /** Types a Cohort may run: both learn in a class. */
    public const COHORT_VALUES = [self::SCHEDULED, self::BLENDED];

    public const SESSION_PHASES = ['before_session', 'during_session', 'after_session'];

    /** @return list<string> */
    public static function allowedPhases(string $type): array
    {
        return match ($type) {
            self::SELF_PACED => ['anytime'],
            self::SCHEDULED => self::SESSION_PHASES,
            default => ['anytime', ...self::SESSION_PHASES],
        };
    }

    public static function allowsLiveClass(string $type): bool
    {
        return $type !== self::SELF_PACED;
    }

    public static function usesAccessDuration(?string $type): bool
    {
        return $type === self::SELF_PACED;
    }

    /** Whether the stored availability flags of one Activity fit the type; null type never fits. */
    public static function availabilityFits(?string $type, object|array $activity): bool
    {
        $activity = (object) $activity;
        if (! in_array($type, self::VALUES, true)) {
            return false;
        }
        if (($activity->activity_type ?? null) === 'live_class' && ! self::allowsLiveClass($type)) {
            return false;
        }
        $anytime = (bool) ($activity->available_anytime ?? false);

        return match ($type) {
            self::SELF_PACED => $anytime
                && ! $activity->available_before_session && ! $activity->available_during_session && ! $activity->available_after_session,
            self::SCHEDULED => ! $anytime,
            default => true,
        };
    }

    public static function labelKey(string $type): string
    {
        return 'lf.LF_course_type_'.$type;
    }
}
