<?php

namespace App\Services;

use App\Support\ActivityAccessDecision;
use App\Support\CourseType;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VersionActivityAccessService
{
    public function __construct(private readonly CourseEnrollmentLifecycleService $enrollmentPolicy) {}

    public function decide(int $studentId, int $enrollmentId, int $versionActivityId): ActivityAccessDecision
    {
        $customerId = TenantContext::customerId();
        if (! $customerId) {
            return $this->deny('invalid_context');
        }

        $enrollment = DB::table('core_course_enrollments')
            ->where('id', $enrollmentId)
            ->where('customer_id', $customerId)
            ->where('student_id', $studentId)
            ->first();
        if (! $enrollment || $enrollment->status !== 'active') {
            return $this->deny('inactive_or_invalid_enrollment');
        }
        if (! $this->enrollmentPolicy->allowsLearningAccessAt($enrollment, Carbon::now())) {
            return $this->deny('outside_enrollment_access_window');
        }

        $activity = DB::table('core_course_template_version_activities')
            ->where('id', $versionActivityId)
            ->where('customer_id', $customerId)
            ->where('template_version_id', $enrollment->version_id)
            ->first();
        if (! $activity) {
            return $this->deny('invalid_activity_context');
        }
        $classDecision = $this->classAccess($customerId, $enrollment, $activity);
        if ($classDecision !== null) {
            return $classDecision;
        }

        return match ($activity->unlock_rule_snapshot) {
            'none' => new ActivityAccessDecision(true, 'unlocked'),
            'previous_activity_completed' => $this->prerequisite(
                $customerId,
                $studentId,
                $enrollment,
                $activity,
            ),
            default => $this->invalidRule((string) $activity->unlock_rule_snapshot),
        };
    }

    /**
     * ADR-0022: a scheduled or blended course is learned in a class. Without an active class membership
     * nothing opens; a blended "anytime" Activity opens from the class start date to the end of its end date.
     * Session-relative windows have no runtime yet (open question), so Session-bound Activities need only membership.
     */
    private function classAccess(int $customerId, object $enrollment, object $activity): ?ActivityAccessDecision
    {
        $courseType = $this->enrollmentPolicy->versionCourseType($customerId, (int) $enrollment->version_id);
        if (! in_array($courseType, CourseType::COHORT_VALUES, true)) {
            return null;
        }

        $cohort = DB::table('core_course_cohort_students as memberships')
            ->join('core_course_cohorts as cohorts', function ($join) use ($customerId): void {
                $join->on('cohorts.id', '=', 'memberships.cohort_id')->where('cohorts.customer_id', $customerId);
            })
            ->where('memberships.customer_id', $customerId)
            ->where('memberships.enrollment_id', $enrollment->id)
            ->where('memberships.status', 'active')
            ->whereIn('cohorts.status', ['active', 'completed'])
            ->orderByDesc('memberships.id')
            ->first(['cohorts.start_date', 'cohorts.end_date']);
        if (! $cohort) {
            return $this->deny('no_active_cohort');
        }

        if (CourseType::normalize($courseType) === CourseType::BLENDED && (bool) $activity->available_anytime && $activity->activity_type !== 'live_class') {
            $today = Carbon::now(config('app.timezone'))->toDateString();
            if (! $cohort->start_date || ! $cohort->end_date
                || $today < substr((string) $cohort->start_date, 0, 10) || $today > substr((string) $cohort->end_date, 0, 10)) {
                return $this->deny('outside_cohort_period');
            }
        }

        return null;
    }

    private function prerequisite(int $customerId, int $studentId, object $enrollment, object $activity): ActivityAccessDecision
    {
        $prerequisiteId = $activity->unlock_after_version_activity_id;
        if (! $prerequisiteId) {
            return $this->deny('missing_prerequisite');
        }

        $belongsToLesson = DB::table('core_course_template_version_activities')
            ->where('id', $prerequisiteId)
            ->where('customer_id', $customerId)
            ->where('template_version_id', $enrollment->version_id)
            ->where('version_lesson_id', $activity->version_lesson_id)
            ->exists();
        if (! $belongsToLesson) {
            return $this->deny('invalid_prerequisite_context');
        }

        $completed = DB::table('core_course_activity_progress')
            ->where('customer_id', $customerId)
            ->where('enrollment_id', $enrollment->id)
            ->where('product_id', $enrollment->product_id)
            ->where('version_id', $enrollment->version_id)
            ->where('student_id', $studentId)
            ->where('version_lesson_id', $activity->version_lesson_id)
            ->where('version_activity_id', $prerequisiteId)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->exists();

        return new ActivityAccessDecision(
            $completed,
            $completed ? 'prerequisite_completed' : 'prerequisite_incomplete',
            (int) $prerequisiteId,
        );
    }

    private function invalidRule(string $rule): ActivityAccessDecision
    {
        Log::warning('Version Activity has an unsupported unlock rule.', ['rule' => $rule]);

        return $this->deny('invalid_unlock_rule');
    }

    private function deny(string $reason): ActivityAccessDecision
    {
        return new ActivityAccessDecision(false, $reason);
    }
}
