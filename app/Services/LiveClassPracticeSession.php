<?php

namespace App\Services;

use App\Support\LessonType;
use Illuminate\Support\Facades\DB;

/**
 * The class Session of a `practice` lesson ("Thực hành, ngoại khóa"): led by someone, in a room or at a place, with
 * no Whiteboard and no meeting link (ADR-0022 Amendment 1.3, Đợt 4, Bước 1). Learners check in with the "Điểm danh"
 * button, since without a board there is no QR code. Every writer of a Session reads these values here.
 */
final class LiveClassPracticeSession
{
    /** What the server stores for such a Session, whatever the request sent. */
    public const BOX = [
        'delivery_mode' => 'offline',
        'online_provider' => null,
        'meeting_url_snapshot' => null,
        'attendance_tool' => 'button',
    ];

    public static function isType(?string $lessonType): bool
    {
        return LessonType::normalize($lessonType) === LessonType::PRACTICE;
    }

    /** Whether the Version Lesson is a practice session. An operational Session has no Lesson, so it never is. */
    public static function lesson(int $customerId, ?int $versionLessonId): bool
    {
        return $versionLessonId !== null && self::isType(DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)->where('id', $versionLessonId)->value('lesson_type'));
    }
}
