<?php

namespace App\Services;

/**
 * The class Session of a self-study lesson (ADR-0022 Amendment 1.3, Đợt 4, Bước 2; ADR-0023 §10): a date on the
 * class schedule bound to the Lesson, not to an Activity. Nobody teaches it, so it has no delivery mode, link,
 * room, address or attendance tool, and no board. It ends by its timetable and can only be moved or cancelled.
 */
final class LiveClassSelfStudySession
{
    public const TYPE = 'self_study';

    /** What every writer stores for such a Session, besides its Lesson, title and time. */
    public const BOX = [
        'session_type' => self::TYPE,
        'version_activity_id' => null,
        'delivery_mode' => null,
        'online_provider' => null,
        'meeting_url_snapshot' => null,
        'facility_name_snapshot' => null,
        'room_name_snapshot' => null,
        'address_snapshot' => null,
        'attendance_tool' => null,
        'room_id' => null,
    ];

    public static function is(object $session): bool
    {
        return ($session->session_type ?? null) === self::TYPE;
    }
}
