<?php

namespace App\Services;

use App\Support\ActivityAccessDecision;
use App\Support\CourseType;
use App\Support\LessonType;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
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
     * A Session-bound Activity (other than `live_class`) opens by the class schedule (Amendment 1.3, Đợt 2), and
     * so does every Activity of a self-study lesson, from the start of its Session (Đợt 4, Bước 3).
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
            ->first(['cohorts.id', 'cohorts.start_date', 'cohorts.end_date']);
        if (! $cohort) {
            return $this->deny('no_active_cohort');
        }

        // A self-study lesson has a date on the class schedule: its content opens at that Session's start, like "Trong".
        if ($activity->activity_type !== 'live_class' && LessonType::normalize(DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)->where('id', $activity->version_lesson_id)->value('lesson_type')) === LessonType::SELF_STUDY) {
            return $this->scheduleAccess($customerId, (int) $cohort->id, (int) $activity->version_lesson_id, ['during']);
        }

        if (CourseType::normalize($courseType) === CourseType::BLENDED && (bool) $activity->available_anytime && $activity->activity_type !== 'live_class') {
            $today = Carbon::now(config('app.timezone'))->toDateString();
            if (! $cohort->start_date || ! $cohort->end_date
                || $today < substr((string) $cohort->start_date, 0, 10) || $today > substr((string) $cohort->end_date, 0, 10)) {
                return $this->deny('outside_cohort_period');
            }
        }

        $phases = array_values(array_filter(['before', 'during', 'after'], fn (string $phase): bool => (bool) $activity->{'available_'.$phase.'_session'}));
        if ($activity->activity_type !== 'live_class' && ! (bool) $activity->available_anytime && $phases !== []) {
            return $this->scheduleAccess($customerId, (int) $cohort->id, (int) $activity->version_lesson_id, $phases);
        }

        return null;
    }

    /**
     * Amendment 1.3, Đợt 2 (MK 3.2): only the planned times count. "Trước" opens when the class's previous
     * programme Session (of a led or a self-study lesson) ends (00:00 of the first Session's day when there is none), "Trong" at the start of the
     * Lesson's first Session, "Sau" at the end of its last one. Cancelled Sessions do not count (the Lesson moved
     * to another Session), nor do draft ones; `no_show` does. An Activity of several phases (older Versions) opens at the earliest.
     *
     * @param  list<string>  $phases
     */
    private function scheduleAccess(int $customerId, int $cohortId, int $versionLessonId, array $phases): ?ActivityAccessDecision
    {
        $sessions = DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohortId)
            ->whereIn('session_type', ['curriculum', LiveClassSelfStudySession::TYPE])
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->get(['version_lesson_id', 'scheduled_start_at', 'scheduled_end_at', 'timezone'])
            ->map(fn (object $s): object => (object) [
                'lesson' => (int) $s->version_lesson_id,
                'start' => LiveClassSessionTime::utc((string) $s->scheduled_start_at, $s->timezone),
                'end' => LiveClassSessionTime::utc((string) $s->scheduled_end_at, $s->timezone),
                'timezone' => $s->timezone ?: config('app.timezone'),
            ])
            ->sortBy(fn (object $s): int => $s->start->getTimestamp())
            ->values();
        $own = $sessions->where('lesson', $versionLessonId)->values();
        if ($own->isEmpty()) {
            return $this->deny('session_unscheduled');
        }
        $first = $own->first();

        $opensAt = collect($phases)->map(function (string $phase) use ($sessions, $own, $first): CarbonImmutable {
            if ($phase === 'during') {
                return $first->start;
            }
            if ($phase === 'after') {
                return $own->sortBy(fn (object $s): int => $s->end->getTimestamp())->last()->end;
            }
            // The Session just before the Lesson's first one, whichever Lesson it carries.
            $previous = $sessions->filter(fn (object $s): bool => $s->start->lt($first->start))->last();
            $opens = $previous
                ? $previous->end
                : CarbonImmutable::parse($first->start->setTimezone($first->timezone)->toDateString(), $first->timezone)->utc();

            // A previous Session overlapping this one never holds "Trước" back past the start.
            return $opens->gt($first->start) ? $first->start : $opens;
        })->sortBy(fn (CarbonImmutable $at): int => $at->getTimestamp())->first();

        if (CarbonImmutable::now('UTC')->lt($opensAt)) {
            // Shown in the Lesson's own Session timezone, the class clock the learner sees on the schedule.
            return new ActivityAccessDecision(false, 'session_not_open', null, $opensAt->setTimezone($first->timezone)->toIso8601String());
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
