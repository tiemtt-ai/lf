<?php

namespace App\Services;

use App\Services\Assessment\AssessmentAttemptService;
use App\Services\Assessment\AssessmentLearnerService;
use App\Services\Assessment\AssessmentQuizService;
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

        return $this->attempts->startForActivity($customerId, $studentId, $enrollmentId, $versionActivityId);
    }

    /**
     * The quiz card of each Quiz Activity, keyed by Version Activity id: the frozen version, attempts used / allowed, the
     * open attempt and the finished ones with what the learner may see of them (ADR-0025 D12).
     *
     * @param  Collection<int, object>  $activities  Version Activities of one Lesson
     * @return Collection<int, object>
     */
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
                ->each(fn (object $attempt) => $attempt->visible = $version ? $this->learner->visibility($attempt, $version) : ['score' => false]);
            $max = (int) ($version->max_attempts ?? 0);

            return [$activity->id => (object) [
                'version' => $version,
                'open' => $mine->firstWhere('status', 'in_progress'),
                'finished' => $finished,
                'used' => $mine->count(),
                'max' => $max,
                'left' => max(0, $max - $mine->count()),
            ]];
        });
    }
}
