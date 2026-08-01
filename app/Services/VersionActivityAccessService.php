<?php

namespace App\Services;

use App\Support\ActivityAccessDecision;
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
