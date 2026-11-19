<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the Activities ticked "Tính vào điểm danh" and when a learner did each one (ADR-0022 Amendment 1.3, Đợt 3).
 * It writes nothing: {@see LiveClassAttendanceRecorder} decides what the result of a Session becomes.
 */
final class LiveClassAttendanceContent
{
    /**
     * The ticked Activities of one Version Lesson in the "Trong" (`during`) or "Sau" (`after`) part.
     *
     * @return Collection<int, object>
     */
    public function ticked(int $customerId, int $versionLessonId, string $phase): Collection
    {
        return DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)->where('version_lesson_id', $versionLessonId)
            ->where('counts_for_attendance', true)->where('activity_type', '!=', 'live_class')
            ->where('available_'.$phase.'_session', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'activity_type', 'completion_rule', 'completion_threshold']);
    }

    /**
     * The ticked Activities of a self-study lesson, which has no parts (ADR-0022 Amendment 1.3, Đợt 4, Bước 3).
     *
     * @return Collection<int, object>
     */
    public function tickedSelfStudy(int $customerId, int $versionLessonId): Collection
    {
        return DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)->where('version_lesson_id', $versionLessonId)
            ->where('counts_for_attendance', true)->where('activity_type', '!=', 'live_class')
            ->where('available_anytime', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'activity_type', 'completion_rule', 'completion_threshold']);
    }

    /** The moment (UTC) the learner did the last of these Activities; null while one of them is not done. */
    public function lastDoneAt(int $customerId, int $enrollmentId, Collection $activities): ?CarbonImmutable
    {
        $last = null;
        foreach ($activities as $activity) {
            $at = $this->doneAt($customerId, $enrollmentId, $activity);
            if ($at === null) {
                return null;
            }
            $last = $last === null || $at->gt($last) ? $at : $last;
        }

        return $last;
    }

    /** Whether the learner did every one of these Activities no later than `$by`. */
    public function allDoneBy(int $customerId, int $enrollmentId, Collection $activities, CarbonImmutable $by): bool
    {
        return $activities->every(function (object $activity) use ($customerId, $enrollmentId, $by): bool {
            $at = $this->doneAt($customerId, $enrollmentId, $activity);

            return $at !== null && $at->lte($by);
        });
    }

    /**
     * The moment (UTC) the Activity counts as done for attendance; null while it does not. A quiz counts from the
     * moment it was handed in, so slow grading never costs the learner the Session: with the `submit` rule any
     * finished attempt counts, with `pass` the first attempt whose graded score reaches the threshold. Every other
     * Activity counts from its completion.
     */
    public function doneAt(int $customerId, int $enrollmentId, object $activity): ?CarbonImmutable
    {
        if ($activity->activity_type === 'quiz' && in_array($activity->completion_rule, ['submit', 'pass'], true)) {
            $attempts = DB::table('core_assessment_attempts')
                ->where('customer_id', $customerId)->where('enrollment_id', $enrollmentId)
                ->where('version_activity_id', $activity->id)->whereNotNull('submitted_at');
            $attempts = $activity->completion_rule === 'pass'
                ? $attempts->where('status', 'graded')->where('score_percentage', '>=', (float) ($activity->completion_threshold ?? 100))
                : $attempts->where('status', '!=', 'in_progress');
            $at = $attempts->min('submitted_at');
        } else {
            $at = DB::table('core_course_activity_progress')
                ->where('customer_id', $customerId)->where('enrollment_id', $enrollmentId)
                ->where('version_activity_id', $activity->id)->where('status', 'completed')
                ->value('completed_at');
        }

        return $at === null ? null : CarbonImmutable::parse((string) $at, config('app.timezone'))->utc();
    }
}
