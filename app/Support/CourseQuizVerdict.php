<?php

namespace App\Support;

/**
 * Đạt / Chưa đạt of one attempt made in a course, against the Activity's completion threshold, the same one the
 * course progress uses (ADR-0032 D3, Amendment 1.1). Only a `pass` Activity has a verdict, only for a graded attempt
 * whose score the learner may see (ADR-0025 D12). The quiz's own pass mark stays with standalone exams.
 */
final class CourseQuizVerdict
{
    /**
     * @param  object  $activity  Version Activity with `completion_rule` and `completion_threshold`
     * @param  object  $attempt  with `status` and `score_percentage`
     * @param  array{score: bool}  $visible  what the learner may see of the attempt
     * @return array{passed: bool, threshold: float}|null
     */
    public static function of(object $activity, object $attempt, array $visible): ?array
    {
        if (($activity->completion_rule ?? null) !== 'pass' || $attempt->status !== 'graded' || ! ($visible['score'] ?? false)
            || $attempt->score_percentage === null) {
            return null;
        }
        // Same default as CourseProgressRecorder::quizChanged().
        $threshold = (float) ($activity->completion_threshold ?? 100);

        return ['passed' => (float) $attempt->score_percentage >= $threshold, 'threshold' => $threshold];
    }
}
