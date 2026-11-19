<?php

namespace App\Support;

/**
 * The course type owned by the Course Template and frozen into each Version (ADR-0022).
 * It decides which learning availability an Activity may declare.
 *
 * ADR-0022 Amendment 1.1: two types remain, Self-paced and Class-based (code `blended`).
 * `scheduled` survives only in Versions published before; it reads and runs as Class-based.
 */
final class CourseType
{
    public const SELF_PACED = 'self_paced';

    /** Legacy: no longer chosen; read as {@see self::BLENDED}. */
    public const SCHEDULED = 'scheduled';

    /** Class-based ("Theo lớp"). */
    public const BLENDED = 'blended';

    /** Every value stored data may hold. */
    public const VALUES = [self::SELF_PACED, self::SCHEDULED, self::BLENDED];

    /** The types a Template may be given, and the options of every type filter. */
    public const SELECTABLE = [self::SELF_PACED, self::BLENDED];

    /** Types a Cohort may run: both learn in a class. */
    public const COHORT_VALUES = [self::SCHEDULED, self::BLENDED];

    /** The type a stored value means today: legacy `scheduled` is Class-based. */
    public static function normalize(?string $type): ?string
    {
        return $type === self::SCHEDULED ? self::BLENDED : $type;
    }

    /** The stored values a filter on one selectable type matches. */
    public static function storedValues(string $type): array
    {
        return self::normalize($type) === self::BLENDED ? self::COHORT_VALUES : [$type];
    }

    public static function same(?string $first, ?string $second): bool
    {
        return self::normalize($first) === self::normalize($second);
    }

    public const SESSION_PHASES = ['before_session', 'during_session', 'after_session'];

    /** @return list<string> */
    public static function allowedPhases(string $type): array
    {
        return $type === self::SELF_PACED ? ['anytime'] : ['anytime', ...self::SESSION_PHASES];
    }

    public static function allowsLiveClass(string $type): bool
    {
        return $type !== self::SELF_PACED;
    }

    public static function usesAccessDuration(?string $type): bool
    {
        return $type === self::SELF_PACED;
    }

    /**
     * Lesson fields by type (ADR-0022 Amendment 1.2). A class opens its lessons by the class schedule, so
     * preview and unlock rules belong to a self-paced course only; a Template without a type keeps them.
     */
    public static function usesLessonPreviewAndUnlock(?string $type): bool
    {
        return ! in_array($type, self::COHORT_VALUES, true);
    }

    /** The lesson type labels a session on the class schedule; a self-paced course has no schedule. */
    public static function usesLessonType(?string $type): bool
    {
        return $type !== self::SELF_PACED;
    }

    /** A class-based course: its Activities follow the session rules of ADR-0022 Amendment 1.3. */
    public static function isClassBased(?string $type): bool
    {
        return in_array($type, self::COHORT_VALUES, true);
    }

    /** Default watch or listen share of a video or audio Activity of a class (ADR-0022 Amendment 1.3). */
    public const CLASS_WATCH_PERCENT = 80;

    /**
     * The completion rules an Activity type may use. A class-based course has one criterion per type and no
     * manual completion (ADR-0022 Amendment 1.3); the other types keep the full list.
     *
     * @return list<string>
     */
    public static function completionRules(?string $type, ?string $activityType): array
    {
        if (self::isClassBased($type)) {
            return match ($activityType) {
                'video', 'audio' => ['watch_percent'],
                'document', 'embedded_video', 'external_link' => ['view'],
                'quiz' => ['submit', 'pass'],
                'live_class' => ['join'],
                default => [],
            };
        }

        return match ($activityType) {
            'video', 'audio' => ['view', 'watch_percent', 'manual'],
            'document', 'embedded_video', 'external_link' => ['view', 'manual'],
            'quiz' => ['submit', 'pass', 'manual'],
            'live_class' => ['join', 'manual'],
            default => [],
        };
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

        return $type !== self::SELF_PACED || ($anytime
            && ! $activity->available_before_session && ! $activity->available_during_session && ! $activity->available_after_session);
    }

    public static function labelKey(string $type): string
    {
        return 'lf.LF_course_type_'.self::normalize($type);
    }
}
