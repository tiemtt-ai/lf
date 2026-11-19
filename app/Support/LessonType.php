<?php

namespace App\Support;

/**
 * The type of a session of a class-based course (ADR-0022 Amendment 1.3). Each type is one way the
 * system handles the session: who leads it and whether its Activities are split into session phases.
 */
final class LessonType
{
    /** A session led by a teacher, on a whiteboard or a link. The default. */
    public const REGULAR = 'regular';

    /** Practice or an extracurricular session: someone leads it, in a room or a place. */
    public const PRACTICE = 'practice';

    /** Learners study alone: nobody leads it, no live session, no phases. */
    public const SELF_STUDY = 'self_study';

    /** The types a lesson may be given. */
    public const SELECTABLE = [self::REGULAR, self::PRACTICE, self::SELF_STUDY];

    /** The type a stored value means: retired values (review, exams) and an empty value read as a regular session. */
    public static function normalize(?string $type): string
    {
        return in_array($type, self::SELECTABLE, true) ? $type : self::REGULAR;
    }

    /** Someone leads the session: it has exactly one live class and every other Activity has one phase. */
    public static function isLed(?string $type): bool
    {
        return self::normalize($type) !== self::SELF_STUDY;
    }

    public static function labelKey(?string $type): string
    {
        return 'lf.LF_course_template_lesson_common_role_'.self::normalize($type);
    }
}
