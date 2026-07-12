<?php

namespace App\Services;

use App\Support\LessonAccessDecision;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VersionLessonAccessService
{
    public function decide(int $studentId, int $enrollmentId, int $versionLessonId): LessonAccessDecision
    {
        $customerId = TenantContext::customerId();
        if (! $customerId) return $this->deny('invalid_context');

        $enrollment = DB::table('core_course_enrollments')
            ->where('id', $enrollmentId)->where('customer_id', $customerId)
            ->where('student_id', $studentId)->first();
        if (! $enrollment || $enrollment->status !== 'active') {
            return $this->deny('inactive_or_invalid_enrollment');
        }

        $lesson = DB::table('core_course_template_version_lessons')
            ->where('id', $versionLessonId)->where('customer_id', $customerId)
            ->where('template_version_id', $enrollment->version_id)->first();
        if (! $lesson) return $this->deny('invalid_lesson_context');

        return match ($lesson->unlock_rule_snapshot) {
            'none' => new LessonAccessDecision(true, 'unlocked'),
            'previous_lesson_completed' => $this->manualPrerequisite(
                $customerId, $studentId, $enrollment, $lesson
            ),
            'date_based' => $this->dateBased($lesson),
            default => $this->invalidRule($lesson->unlock_rule_snapshot),
        };
    }

    private function manualPrerequisite(int $customerId, int $studentId, object $enrollment, object $lesson): LessonAccessDecision
    {
        $prerequisiteId = $lesson->unlock_after_version_lesson_id;
        if (! $prerequisiteId) return $this->deny('missing_prerequisite');

        $belongsToVersion = DB::table('core_course_template_version_lessons')
            ->where('id', $prerequisiteId)->where('customer_id', $customerId)
            ->where('template_version_id', $enrollment->version_id)->exists();
        if (! $belongsToVersion) return $this->deny('invalid_prerequisite_context');

        $completed = DB::table('core_course_lesson_progress')
            ->where('customer_id', $customerId)
            ->where('enrollment_id', $enrollment->id)
            ->where('product_id', $enrollment->product_id)
            ->where('version_id', $enrollment->version_id)
            ->where('student_id', $studentId)
            ->where('version_lesson_id', $prerequisiteId)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->exists();

        return new LessonAccessDecision(
            $completed,
            $completed ? 'prerequisite_completed' : 'prerequisite_incomplete',
            (int) $prerequisiteId
        );
    }

    private function dateBased(object $lesson): LessonAccessDecision
    {
        if (! $lesson->unlock_at_snapshot) return $this->deny('missing_unlock_at');
        $unlockAt = Carbon::parse($lesson->unlock_at_snapshot, 'UTC')->utc();
        $allowed = Carbon::now('UTC')->greaterThanOrEqualTo($unlockAt);
        return new LessonAccessDecision($allowed, $allowed ? 'unlock_time_reached' : 'unlock_time_pending', null, $unlockAt->toIso8601String());
    }

    private function invalidRule(string $rule): LessonAccessDecision
    {
        Log::warning('Version Lesson has an unsupported unlock rule.', ['rule' => $rule]);
        return $this->deny('invalid_unlock_rule');
    }

    private function deny(string $reason): LessonAccessDecision
    {
        return new LessonAccessDecision(false, $reason);
    }
}
