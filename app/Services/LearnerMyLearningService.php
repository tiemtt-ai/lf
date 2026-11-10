<?php

namespace App\Services;

use App\Support\CourseType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model of "Khóa học của tôi" (LF-Navigation § My Courses): the learner's self-paced
 * Enrollments and Cohort seats, read from the frozen Version tables (No Runtime Course).
 * It never writes: progress is shown as stored, and access is decided by the runtime
 * access services (Enrollment Runtime Authority).
 */
class LearnerMyLearningService
{
    /** Enrollments the learner never sees in the list. */
    private const HIDDEN_ENROLLMENT_STATUSES = ['cancelled'];

    /** Session statuses that never count as a class meeting. */
    private const VOID_SESSION_STATUSES = ['cancelled', 'no_show', 'draft'];

    public function __construct(
        private readonly CourseEnrollmentLifecycleService $enrollmentPolicy,
        private readonly VersionLessonAccessService $lessonAccess,
        private readonly VersionActivityAccessService $activityAccess,
        private readonly CourseCohortContentOrder $cohortOrder,
        private readonly TrustedVideoUrlService $trustedVideos,
    ) {}

    /** The Version difficulty as the learner reads it; unknown values are shown as stored. */
    public static function levelLabel(?string $level): ?string
    {
        return in_array($level, ['beginner', 'intermediate', 'advanced'], true) ? __('lf.LF_course_template_common_'.$level) : $level;
    }

    /**
     * Self-paced Enrollments grouped as current / completed / expired.
     *
     * @return array{current: Collection<int, object>, completed: Collection<int, object>, expired: Collection<int, object>}
     */
    public function courses(int $customerId, int $studentId): array
    {
        $rows = $this->enrollmentQuery($customerId, $studentId)
            ->where('versions.course_type_snapshot', CourseType::SELF_PACED)
            ->orderByDesc('enrollments.enrolled_at')->orderByDesc('enrollments.id')
            ->limit(200)
            ->get();

        $teachers = $this->templateTeachers($customerId, $rows->pluck('template_id')->all());
        $progress = DB::table('core_course_progress')
            ->where('customer_id', $customerId)->where('student_id', $studentId)
            ->whereIn('enrollment_id', $rows->pluck('id'))
            ->get(['enrollment_id', 'progress_percentage', 'status'])->keyBy('enrollment_id');
        $lessonCounts = $this->lessonCounts($customerId, $rows->pluck('version_id')->all());

        $now = Carbon::now();
        $rows->each(function (object $row) use ($teachers, $progress, $lessonCounts, $now): void {
            $row->teachers = $teachers->get($row->template_id, collect())->all();
            $row->progress = (int) round((float) ($progress->get($row->id)->progress_percentage ?? 0));
            $row->progress_completed = ($progress->get($row->id)->status ?? null) === 'completed';
            $row->lesson_total = (int) ($lessonCounts[$row->version_id] ?? 0);
            $row->phase = $this->phase($row, $now);
            $row->group = match (true) {
                // ADR-0030 Q2: finishing every Lesson completes the Course Progress, not the Enrollment.
                $row->status === 'completed', $row->progress_completed && $row->status === 'active' => 'completed',
                $row->status === 'expired', $row->phase === 'ended' => 'expired',
                default => 'current',
            };
        });

        return [
            'current' => $rows->where('group', 'current')->values(),
            'completed' => $rows->where('group', 'completed')->values(),
            'expired' => $rows->where('group', 'expired')->values(),
        ];
    }

    /**
     * One Enrollment of the learner with its Version outline and the access decision of each Lesson.
     * Null when the Enrollment is not the learner's (the caller answers 404).
     */
    public function course(int $customerId, int $studentId, int $enrollmentId): ?object
    {
        $course = $this->enrollmentQuery($customerId, $studentId)->where('enrollments.id', $enrollmentId)->first();
        if (! $course) {
            return null;
        }

        $now = Carbon::now();
        $course->teachers = $this->templateTeachers($customerId, [$course->template_id])->get($course->template_id, collect())->all();
        $course->phase = $this->phase($course, $now);
        $course->is_class = in_array($course->course_type, CourseType::COHORT_VALUES, true);
        $course->cohort_id = $course->is_class ? $this->seatCohortId($customerId, $studentId, (int) $course->id) : null;
        $stored = DB::table('core_course_progress')
            ->where('customer_id', $customerId)->where('enrollment_id', $course->id)->where('student_id', $studentId)
            ->first(['progress_percentage', 'status', 'last_version_lesson_id']);
        $course->progress = (int) round((float) ($stored->progress_percentage ?? 0));
        $course->progress_completed = ($stored->status ?? null) === 'completed';

        $lessonProgress = DB::table('core_course_lesson_progress')
            ->where('customer_id', $customerId)->where('enrollment_id', $course->id)->where('student_id', $studentId)
            ->get(['version_lesson_id', 'status', 'progress_percentage'])->keyBy('version_lesson_id');

        $lessons = DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)->where('template_version_id', $course->version_id)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'version_section_id', 'title_snapshot as title', 'short_description_snapshot as summary',
                'lesson_type', 'duration_seconds', 'activity_count', 'is_preview', 'sort_order']);
        // A class course shows the class's own order (ADR-0023).
        if ($course->cohort_id) {
            $lessons = $this->cohortOrder->lessons($customerId, $course->cohort_id, $lessons);
        }

        // Only a learning Enrollment inside its window gets a per-Lesson decision; otherwise every Lesson is closed for the same reason.
        $open = $course->status === 'active' && $this->enrollmentPolicy->allowsLearningAccessAt($course, $now);
        $closedReason = $course->status !== 'active' ? 'inactive_or_invalid_enrollment' : 'outside_enrollment_access_window';
        $number = 0;
        $lessons->each(function (object $lesson) use ($course, $studentId, $open, $closedReason, $lessonProgress, &$number): void {
            $lesson->number = ++$number;
            $decision = $open ? $this->lessonAccess->decide($studentId, (int) $course->id, (int) $lesson->id) : null;
            $lesson->allowed = $decision?->allowed ?? false;
            $lesson->reason = $decision?->reason ?? $closedReason;
            $lesson->unlock_at = $decision?->unlockAt;
            $stored = $lessonProgress->get($lesson->id);
            $lesson->progress_status = $stored->status ?? 'not_started';
            $lesson->progress = (int) round((float) ($stored->progress_percentage ?? 0));
        });

        $course->lesson_total = $lessons->count();
        $course->lesson_completed = $lessons->where('progress_status', 'completed')->count();
        $course->lesson_open = $lessons->where('allowed', true)->count();
        $course->duration_seconds = (int) $lessons->sum('duration_seconds');
        $course->sections = $this->outline($customerId, (int) $course->version_id, $lessons);
        // "Học tiếp": the last Lesson learned while it is open and unfinished; once it is finished, the next
        // open unfinished Lesson after it; otherwise the first open unfinished Lesson, then the first open one.
        $ordered = $course->sections->flatMap(fn (object $section) => $section->lessons)->values();
        $unfinished = fn (object $l): bool => $l->allowed && $l->progress_status !== 'completed';
        $lastAt = $ordered->search(fn (object $l): bool => (int) $l->id === (int) ($stored->last_version_lesson_id ?? 0));
        $resume = null;
        if ($lastAt !== false) {
            $resume = $unfinished($ordered[$lastAt]) ? $ordered[$lastAt] : $ordered->slice($lastAt + 1)->first($unfinished);
        }
        $course->resume_lesson_id = ($resume ?? $ordered->first($unfinished) ?? $ordered->firstWhere('allowed', true))?->id;

        return $course;
    }

    /**
     * One Lesson of the learner's Enrollment with its Activities, each with its runtime access decision
     * and stored progress (ADR-0030 D1). Null when the Enrollment or the Lesson is not the learner's.
     */
    public function lesson(int $customerId, int $studentId, int $enrollmentId, int $versionLessonId): ?object
    {
        $course = $this->course($customerId, $studentId, $enrollmentId);
        $lesson = $course?->sections->flatMap(fn (object $section) => $section->lessons)->firstWhere('id', $versionLessonId);
        if (! $lesson) {
            return null;
        }
        $lesson->course = $course;
        $lesson->section_title = $course->sections->first(fn (object $s): bool => $s->lessons->contains('id', $versionLessonId))?->title;
        $all = $course->sections->flatMap(fn (object $section) => $section->lessons)->values();
        $position = $all->search(fn (object $l): bool => (int) $l->id === $versionLessonId);
        $lesson->previous_id = $position > 0 ? $all[$position - 1]->id : null;
        $lesson->next_id = $all[$position + 1]->id ?? null;
        $lesson->next_allowed = (bool) ($all[$position + 1]->allowed ?? false);
        if (! $lesson->allowed) {
            $lesson->activities = collect();

            return $lesson;
        }

        $activities = DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)->where('template_version_id', $course->version_id)->where('version_lesson_id', $versionLessonId)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'title_snapshot as title', 'description_snapshot as description', 'activity_type', 'media_file_id',
                'external_video_url_snapshot', 'duration_seconds', 'estimated_duration_seconds_snapshot as estimated_seconds',
                'is_required', 'completion_rule', 'completion_threshold', 'sort_order']);
        if ($course->cohort_id) {
            $activities = $this->cohortOrder->activities($customerId, $course->cohort_id, $activities);
        }
        $progress = DB::table('core_course_activity_progress')
            ->where('customer_id', $customerId)->where('enrollment_id', $course->id)->whereIn('version_activity_id', $activities->pluck('id'))
            ->get(['version_activity_id', 'status', 'progress_percentage', 'last_position_seconds'])->keyBy('version_activity_id');
        $sessions = $course->cohort_id
            ? $this->cohortSessions($customerId, [$course->cohort_id])->get($course->cohort_id, collect())
            : collect();

        $activities->each(function (object $activity) use ($studentId, $course, $progress, $sessions): void {
            $decision = $this->activityAccess->decide($studentId, (int) $course->id, (int) $activity->id);
            $activity->allowed = $decision->allowed;
            $activity->reason = $decision->reason;
            $stored = $progress->get($activity->id);
            $activity->progress_status = $stored->status ?? 'not_started';
            $activity->progress = (int) round((float) ($stored->progress_percentage ?? 0));
            $activity->resume_at = (int) ($stored->last_position_seconds ?? 0);
            $activity->progress_rule = CourseProgressRecorder::progressRule($activity);
            $activity->embed_url = null;
            if ($activity->activity_type === 'embedded_video' && $activity->external_video_url_snapshot) {
                try {
                    $activity->embed_url = $this->trustedVideos->embedUrl($activity->external_video_url_snapshot);
                } catch (\Throwable) {
                    $activity->embed_url = null;
                }
            }
            $activity->sessions = $activity->activity_type === 'live_class'
                ? $sessions->filter(fn (object $s): bool => (int) ($s->version_activity_id ?? 0) === (int) $activity->id)->values()
                : collect();
        });
        $lesson->activities = $activities;

        return $lesson;
    }

    /**
     * Cohort seats of the learner grouped as current / completed.
     *
     * @return array{current: Collection<int, object>, completed: Collection<int, object>}
     */
    public function classes(int $customerId, int $studentId): array
    {
        $rows = $this->seatQuery($customerId, $studentId)
            ->orderByDesc('cohorts.start_date')->orderByDesc('cohorts.id')
            ->limit(200)
            ->get();

        $teachers = $this->cohortTeachers($customerId, $rows->pluck('cohort_id')->all());
        $sessions = $this->cohortSessions($customerId, $rows->pluck('cohort_id')->all());
        $attended = $this->attendedCounts($customerId, $rows->pluck('enrollment_id')->all());
        $now = Carbon::now();

        $rows->each(function (object $row) use ($teachers, $sessions, $attended, $now): void {
            $row->teachers = $teachers->get($row->cohort_id, collect())->all();
            $this->summarizeSessions($row, $sessions->get($row->cohort_id, collect()), (int) ($attended[$row->enrollment_id] ?? 0), $now);
            $row->group = in_array($row->cohort_status, ['completed', 'archived'], true) ? 'completed' : 'current';
        });

        return [
            'current' => $rows->where('group', 'current')->values(),
            'completed' => $rows->where('group', 'completed')->values(),
        ];
    }

    /** One Cohort of the learner with every Session and the learner's attendance; null when they have no seat. */
    public function cohort(int $customerId, int $studentId, int $cohortId): ?object
    {
        $class = $this->seatQuery($customerId, $studentId)->where('cohorts.id', $cohortId)->first();
        if (! $class) {
            return null;
        }

        $sessions = $this->cohortSessions($customerId, [$cohortId])->get($cohortId, collect());
        $attendance = DB::table('core_liveclass_attendances')
            ->where('customer_id', $customerId)->where('enrollment_id', $class->enrollment_id)
            ->whereIn('session_id', $sessions->pluck('id'))
            ->get(['session_id', 'status', 'attendance_mode', 'joined_at'])->keyBy('session_id');
        $now = Carbon::now();

        $sessions->each(function (object $session) use ($attendance): void {
            $mark = $attendance->get($session->id);
            $session->attendance_status = $mark->status ?? null;
            $session->attendance_mode = $mark->attendance_mode ?? null;
        });

        $class->teachers = $this->cohortTeachers($customerId, [$cohortId])->get($cohortId, collect())->all();
        $attendedCount = $sessions->filter(fn (object $s): bool => in_array($s->attendance_status, ['present', 'late'], true))->count();
        $this->summarizeSessions($class, $sessions, $attendedCount, $now);
        $class->sessions = $sessions;

        return $class;
    }

    private function enrollmentQuery(int $customerId, int $studentId)
    {
        return DB::table('core_course_enrollments as enrollments')
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'enrollments.version_id')->where('versions.customer_id', $customerId);
            })
            ->join('core_course_products as products', function ($join) use ($customerId): void {
                $join->on('products.id', '=', 'enrollments.product_id')->where('products.customer_id', $customerId);
            })
            ->where('enrollments.customer_id', $customerId)
            ->where('enrollments.student_id', $studentId)
            ->whereNotIn('enrollments.status', self::HIDDEN_ENROLLMENT_STATUSES)
            ->select('enrollments.id', 'enrollments.product_id', 'enrollments.version_id', 'enrollments.status',
                'enrollments.enrolled_at', 'enrollments.access_starts_at', 'enrollments.access_ends_at',
                'enrollments.review_starts_at', 'enrollments.review_ends_at', 'enrollments.completed_at',
                'products.title as product_title', 'versions.title_snapshot as version_title',
                'versions.short_description_snapshot as summary', 'versions.category_name_snapshot as category',
                'versions.difficulty_level_snapshot as level', 'versions.course_type_snapshot as course_type',
                'versions.template_id');
    }

    private function seatQuery(int $customerId, int $studentId)
    {
        return DB::table('core_course_cohort_students as seats')
            ->join('core_course_cohorts as cohorts', function ($join) use ($customerId): void {
                $join->on('cohorts.id', '=', 'seats.cohort_id')->where('cohorts.customer_id', $customerId);
            })
            ->join('core_course_enrollments as enrollments', function ($join) use ($customerId, $studentId): void {
                $join->on('enrollments.id', '=', 'seats.enrollment_id')->where('enrollments.customer_id', $customerId)
                    ->where('enrollments.student_id', $studentId);
            })
            ->leftJoin('core_course_products as products', function ($join) use ($customerId): void {
                $join->on('products.id', '=', 'cohorts.product_id')->where('products.customer_id', $customerId);
            })
            ->leftJoin('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'cohorts.version_id')->where('versions.customer_id', $customerId);
            })
            ->where('seats.customer_id', $customerId)
            ->where('seats.student_id', $studentId)
            ->where('seats.status', 'active')
            ->whereNull('seats.left_at')
            ->whereNotIn('enrollments.status', self::HIDDEN_ENROLLMENT_STATUSES)
            ->whereNotIn('cohorts.status', ['draft'])
            ->select('seats.id as seat_id', 'seats.cohort_id', 'seats.enrollment_id', 'seats.joined_at',
                'cohorts.name', 'cohorts.code', 'cohorts.status as cohort_status', 'cohorts.start_date', 'cohorts.end_date',
                'cohorts.default_meeting_url', 'products.title as product_title', 'versions.title_snapshot as version_title',
                'versions.category_name_snapshot as category', 'versions.difficulty_level_snapshot as level',
                'enrollments.status as enrollment_status');
    }

    /** @return Collection<int, Collection<int, object>> Sessions per Cohort, in schedule order. */
    private function cohortSessions(int $customerId, array $cohortIds): Collection
    {
        if ($cohortIds === []) {
            return collect();
        }

        return DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)
            ->whereIn('cohort_id', $cohortIds)
            ->whereNull('superseded_by_session_id')
            ->where('status', '!=', 'draft')
            ->orderBy('scheduled_start_at')->orderBy('id')
            ->get(['id', 'cohort_id', 'version_activity_id', 'title', 'session_no', 'session_type', 'delivery_mode', 'status',
                'scheduled_start_at', 'scheduled_end_at', 'timezone', 'meeting_url_snapshot',
                'facility_name_snapshot', 'room_name_snapshot', 'address_snapshot', 'cancellation_reason'])
            ->each(function (object $session): void {
                // Session times are wall-clock in the row's own timezone (Canonical Time Convention).
                $session->starts_at = Carbon::parse($session->scheduled_start_at, $session->timezone);
                $session->ends_at = Carbon::parse($session->scheduled_end_at, $session->timezone);
            })
            ->groupBy('cohort_id');
    }

    /** Adds the Session counts, the next Session and the attendance rate to a Cohort row. */
    private function summarizeSessions(object $row, Collection $sessions, int $attended, Carbon $now): void
    {
        $counted = $sessions->reject(fn (object $s): bool => in_array($s->status, self::VOID_SESSION_STATUSES, true));
        $held = $counted->filter(fn (object $s): bool => $s->status === 'completed' || ($s->status === 'live')
            || ($s->status === 'scheduled' && $s->ends_at->lte($now)));

        $row->session_total = $counted->count();
        $row->session_held = $held->count();
        $row->session_attended = $attended;
        $row->attendance_rate = $held->count() > 0 ? (int) round(100 * min($attended, $held->count()) / $held->count()) : 0;
        $row->schedule_rate = $counted->count() > 0 ? (int) round(100 * $held->count() / $counted->count()) : 0;
        $row->live_session = $sessions->firstWhere('status', 'live');
        $row->next_session = $sessions->first(fn (object $s): bool => $s->status === 'scheduled' && $s->ends_at->gt($now));
        $row->delivery_modes = $counted->pluck('delivery_mode')->unique()->values()->all();
    }

    /** @return array<int, int> attended (present or late) Session count per Enrollment */
    private function attendedCounts(int $customerId, array $enrollmentIds): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        return DB::table('core_liveclass_attendances as attendances')
            ->join('core_liveclass_sessions as sessions', function ($join) use ($customerId): void {
                $join->on('sessions.id', '=', 'attendances.session_id')->where('sessions.customer_id', $customerId);
            })
            ->where('attendances.customer_id', $customerId)
            ->whereIn('attendances.enrollment_id', $enrollmentIds)
            ->whereIn('attendances.status', ['present', 'late'])
            ->whereNotIn('sessions.status', self::VOID_SESSION_STATUSES)
            ->groupBy('attendances.enrollment_id')
            ->selectRaw('attendances.enrollment_id, COUNT(DISTINCT attendances.session_id) as total')
            ->pluck('total', 'enrollment_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /** @return Collection<int, Collection<int, string>> active Template teachers per Template */
    private function templateTeachers(int $customerId, array $templateIds): Collection
    {
        if ($templateIds === []) {
            return collect();
        }

        return DB::table('core_course_template_teachers as assignments')
            ->join('users', function ($join) use ($customerId): void {
                $join->on('users.id', '=', 'assignments.teacher_id')->where('users.customer_id', $customerId);
            })
            ->where('assignments.customer_id', $customerId)
            ->whereIn('assignments.template_id', array_unique($templateIds))
            ->where('assignments.status', 'active')
            ->orderBy('assignments.sort_order')->orderBy('assignments.id')
            ->get(['assignments.template_id', 'users.name'])
            ->groupBy('template_id')
            ->map(fn (Collection $rows): Collection => $rows->pluck('name')->unique()->values());
    }

    /** @return Collection<int, Collection<int, string>> active Cohort teachers per Cohort */
    private function cohortTeachers(int $customerId, array $cohortIds): Collection
    {
        if ($cohortIds === []) {
            return collect();
        }

        return DB::table('core_course_cohort_teachers as assignments')
            ->join('users', function ($join) use ($customerId): void {
                $join->on('users.id', '=', 'assignments.teacher_id')->where('users.customer_id', $customerId);
            })
            ->where('assignments.customer_id', $customerId)
            ->whereIn('assignments.cohort_id', array_unique($cohortIds))
            ->where('assignments.status', 'active')
            ->orderByRaw("CASE assignments.role WHEN 'primary' THEN 0 ELSE 1 END")->orderBy('assignments.id')
            ->get(['assignments.cohort_id', 'users.name'])
            ->groupBy('cohort_id')
            ->map(fn (Collection $rows): Collection => $rows->pluck('name')->unique()->values());
    }

    /** @return array<int, int> Lesson count per Version */
    private function lessonCounts(int $customerId, array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }

        return DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)->whereIn('template_version_id', array_unique($versionIds))
            ->groupBy('template_version_id')
            ->selectRaw('template_version_id, COUNT(*) as total')
            ->pluck('total', 'template_version_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    private function seatCohortId(int $customerId, int $studentId, int $enrollmentId): ?int
    {
        $cohortId = DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)->where('student_id', $studentId)->where('enrollment_id', $enrollmentId)
            ->where('status', 'active')->whereNull('left_at')
            ->orderByDesc('id')->value('cohort_id');

        return $cohortId === null ? null : (int) $cohortId;
    }

    /**
     * Where the learner stands in the learning cycle: open (no window), not_started, access, review or ended.
     */
    private function phase(object $enrollment, Carbon $now): string
    {
        if (! $enrollment->access_starts_at && ! $enrollment->access_ends_at) {
            return 'open';
        }
        if ($enrollment->access_starts_at && $now->lt(Carbon::parse($enrollment->access_starts_at))) {
            return 'not_started';
        }
        if ($enrollment->access_ends_at && $now->lt(Carbon::parse($enrollment->access_ends_at))) {
            return 'access';
        }
        if ($enrollment->review_ends_at && $now->lt(Carbon::parse($enrollment->review_ends_at))) {
            return 'review';
        }

        return 'ended';
    }

    /**
     * Sections in display order, each with its own Lessons; Lessons whose Section is missing go to a last, untitled group.
     *
     * @return Collection<int, object>
     */
    private function outline(int $customerId, int $versionId, Collection $lessons): Collection
    {
        $sections = DB::table('core_course_template_version_sections')
            ->where('customer_id', $customerId)->where('template_version_id', $versionId)
            ->orderBy('display_order')->orderBy('id')
            ->get(['id', 'parent_version_section_id', 'title_snapshot as title']);
        $bySection = $lessons->groupBy('version_section_id');

        $groups = $sections
            ->map(function (object $section) use ($bySection): object {
                $section->lessons = $bySection->get($section->id, collect())->values();

                return $section;
            })
            ->filter(fn (object $section): bool => $section->lessons->isNotEmpty())
            ->values();

        $orphans = $lessons->reject(fn (object $lesson): bool => $sections->contains('id', $lesson->version_section_id))->values();
        if ($orphans->isNotEmpty()) {
            $groups->push((object) ['id' => null, 'parent_version_section_id' => null, 'title' => null, 'lessons' => $orphans]);
        }

        return $groups;
    }
}
