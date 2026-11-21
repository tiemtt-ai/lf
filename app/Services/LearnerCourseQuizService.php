<?php

namespace App\Services;

use App\Services\Assessment\AssessmentAttemptService;
use App\Services\Assessment\AssessmentLearnerService;
use App\Services\Assessment\AssessmentQuizService;
use App\Support\CourseQuizVerdict;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quiz Activities of a course for the learner (ADR-0032): the quiz card of the lesson page and starting an attempt. The
 * course runtime decides access (as for every Activity); the Kiểm tra module owns the attempt itself.
 */
class LearnerCourseQuizService
{
    public function __construct(
        private readonly VersionActivityAccessService $activityAccess,
        private readonly VersionLessonAccessService $lessonAccess,
        private readonly AssessmentAttemptService $attempts,
        private readonly AssessmentLearnerService $learner,
    ) {}

    /** Starts or resumes the attempt; null when the learner may not take this Activity now (the caller answers 403). */
    public function start(int $customerId, int $studentId, int $enrollmentId, int $versionActivityId): ?object
    {
        $lessonId = DB::table('core_course_template_version_activities')->where('customer_id', $customerId)
            ->where('id', $versionActivityId)->where('activity_type', 'quiz')->value('version_lesson_id');
        abort_if($lessonId === null, 404);
        if (! $this->activityAccess->decide($studentId, $enrollmentId, $versionActivityId)->allowed
            || ! $this->lessonAccess->decide($studentId, $enrollmentId, (int) $lessonId)->allowed) {
            return null;
        }
        $this->learner->closeOverdue($customerId, $studentId);
        // A class that has ended starts no new attempt; one already open may still be finished (Amendment 1.4).
        if (CourseClassReview::only($customerId, $enrollmentId)
            && ! DB::table(AssessmentAttemptService::TABLE)->where('customer_id', $customerId)->where('user_id', $studentId)
                ->where('enrollment_id', $enrollmentId)->where('version_activity_id', $versionActivityId)
                ->where('status', 'in_progress')->exists()) {
            return null;
        }

        return $this->attempts->startForActivity($customerId, $studentId, $enrollmentId, $versionActivityId);
    }

    /**
     * The quiz card of each Quiz Activity, keyed by Version Activity id: the frozen version, attempts used / allowed, the
     * open attempt and the finished ones with what the learner may see of them (ADR-0025 D12).
     *
     * @param  Collection<int, object>  $activities  Version Activities of one Lesson
     * @return Collection<int, object>
     */
    /**
     * Whether a class learner is offered another attempt (MK Live 3.1): attempts are left, and either none was handed in
     * yet or the attempt that counts is "Chưa đạt". The class page and the result page of a class attempt both ask this.
     */
    public static function classOffersAttempt(object $card): bool
    {
        return $card->left > 0
            && ($card->finished->isEmpty() || ($card->best !== null && ! $card->best->verdict['passed']));
    }

    public function cards(int $customerId, int $studentId, int $enrollmentId, Collection $activities): Collection
    {
        $quizActivities = $activities->where('activity_type', 'quiz');
        if ($quizActivities->isEmpty()) {
            return collect();
        }
        $this->learner->closeOverdue($customerId, $studentId);
        $snapshots = DB::table('core_course_template_version_activities')->where('customer_id', $customerId)
            ->whereIn('id', $quizActivities->pluck('id'))->pluck('assessment_quiz_version_id_snapshot', 'id');
        $versions = DB::table(AssessmentQuizService::VERSIONS_TABLE)->where('customer_id', $customerId)
            ->whereIn('id', $snapshots->filter()->values())->get()->keyBy('id');
        $attempts = DB::table(AssessmentAttemptService::TABLE)->where('customer_id', $customerId)
            ->where('user_id', $studentId)->where('enrollment_id', $enrollmentId)->whereIn('version_activity_id', $quizActivities->pluck('id'))
            ->orderBy('attempt_no')->get()->groupBy('version_activity_id');

        return $quizActivities->mapWithKeys(function (object $activity) use ($snapshots, $versions, $attempts): array {
            $version = $versions->get($snapshots->get($activity->id));
            $mine = $attempts->get($activity->id, collect())->values();
            $finished = $mine->where('status', '!=', 'in_progress')->values()
                ->each(function (object $attempt) use ($version, $activity): void {
                    $attempt->visible = $version ? $this->learner->visibility($attempt, $version) : ['score' => false];
                    // Đạt / Chưa đạt against the Activity's threshold (ADR-0032 Amendment 1.1).
                    $attempt->verdict = CourseQuizVerdict::of($activity, $attempt, $attempt->visible);
                });
            $max = (int) ($version->max_attempts ?? 0);

            return [$activity->id => (object) [
                'version' => $version,
                'open' => $mine->firstWhere('status', 'in_progress'),
                'finished' => $finished,
                // The attempt that counts: the graded one with the highest percentage (ADR-0032 D3), when it has a verdict.
                'best' => $finished->filter(fn (object $attempt): bool => $attempt->verdict !== null)
                    ->sortByDesc(fn (object $attempt): float => (float) $attempt->score_percentage)->first(),
                'used' => $mine->count(),
                'max' => $max,
                'left' => max(0, $max - $mine->count()),
            ]];
        });
    }
}
