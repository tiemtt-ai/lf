<?php

namespace App\Services;

use App\Support\CourseType;
use App\Support\ExternalLinkUrl;
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
        // ADR-0022 Amendment 1.4: the class has ended, so the learner only reviews.
        $course->review_only = $course->cohort_id !== null && CourseClassReview::only($customerId, (int) $course->id);
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
        // What the lesson list shows of each Lesson's Activities (MK "learning course" row): how much of its video or
        // audio was played, when it was last opened, and its Quiz with the attempts made.
        $activities = DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)->where('template_version_id', $course->version_id)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'version_lesson_id', 'activity_type', 'duration_seconds', 'is_required'])->groupBy('version_lesson_id');
        $activityProgress = DB::table('core_course_activity_progress')
            ->where('customer_id', $customerId)->where('enrollment_id', $course->id)->where('student_id', $studentId)
            ->get(['version_activity_id', 'progress_percentage', 'attempt_count', 'last_accessed_at'])->keyBy('version_activity_id');
        $number = 0;
        $lessons->each(function (object $lesson) use ($course, $studentId, $open, $closedReason, $lessonProgress, $activities, $activityProgress, &$number): void {
            $lesson->number = ++$number;
            $this->summarizeLessonActivities($lesson, $activities->get($lesson->id) ?? collect(), $activityProgress);
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
        // The course-wide figure beside the rows (MK "Tỷ lệ xem video"): the mean of the rows' played share over the
        // Lessons with video or audio, an unplayed one counting 0. Lessons completed stay a separate count, by LF's
        // own rule (all required content, Quiz included), so the two are never the same figure.
        $withMedia = $lessons->whereNotNull('media');
        $course->watch_ratio = $withMedia->isEmpty() ? null : (int) floor($withMedia->avg('watched'));
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
     * The video or audio of a Lesson as the lesson list shows it: `media` (video, audio or null), the share played
     * (`watched`, the mean of its required media Activities, else of all), the seconds played and the last opening; and its first Quiz with
     * the attempts made on its Quizzes. Read only; the recorded progress is the source.
     *
     * @param  Collection<int, object>  $activities
     * @param  Collection<int|string, object>  $progress
     */
    private function summarizeLessonActivities(object $lesson, Collection $activities, Collection $progress): void
    {
        $videos = $activities->whereIn('activity_type', ['video', 'embedded_video']);
        $media = $videos->merge($activities->where('activity_type', 'audio'))->values();
        // An optional video or audio does not hold the share down once the required ones are played.
        $required = $media->filter(fn (object $a): bool => (bool) $a->is_required)->values();
        $media = $required->isNotEmpty() ? $required : $media;
        $played = fn (object $a): float => min(100, (float) ($progress->get($a->id)->progress_percentage ?? 0));
        $lesson->media = $videos->isNotEmpty() ? 'video' : ($media->isNotEmpty() ? 'audio' : null);
        $lesson->watched = $media->isEmpty() ? null : (int) floor($media->avg($played));
        $lesson->watched_seconds = (int) round($media->sum(fn (object $a): float => $played($a) * (int) $a->duration_seconds / 100));
        $last = $media->map(fn (object $a) => $progress->get($a->id)->last_accessed_at ?? null)->filter()->max();
        $lesson->last_viewed_at = $last ? Carbon::parse($last) : null;
        $quizzes = $activities->where('activity_type', 'quiz');
        $lesson->quiz_activity_id = $quizzes->first()?->id;
        $lesson->quiz_attempts = (int) $quizzes->sum(fn (object $a): int => (int) ($progress->get($a->id)->attempt_count ?? 0));
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
        $lesson->activities = $lesson->allowed
            ? $this->lessonActivities($customerId, $studentId, $course, $versionLessonId)
            : collect();

        return $lesson;
    }

    /**
     * The Activities of one Lesson of the learner's Enrollment, each with its runtime access decision, stored progress
     * and the part of the Session it belongs to (`phase`: before, during, after or anytime).
     *
     * @return Collection<int, object>
     */
    private function lessonActivities(int $customerId, int $studentId, object $course, int $versionLessonId, ?Collection $sessions = null): Collection
    {
        $activities = DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)->where('template_version_id', $course->version_id)->where('version_lesson_id', $versionLessonId)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'title_snapshot as title', 'description_snapshot as description', 'activity_type', 'media_file_id',
                'external_video_url_snapshot', 'external_link_url_snapshot', 'duration_seconds', 'estimated_duration_seconds_snapshot as estimated_seconds',
                'is_required', 'counts_for_attendance', 'completion_rule', 'completion_threshold', 'sort_order',
                'available_anytime', 'available_before_session', 'available_during_session', 'available_after_session']);
        if ($course->cohort_id) {
            $activities = $this->cohortOrder->activities($customerId, $course->cohort_id, $activities);
        }
        $progress = DB::table('core_course_activity_progress')
            ->where('customer_id', $customerId)->where('enrollment_id', $course->id)->whereIn('version_activity_id', $activities->pluck('id'))
            ->get(['version_activity_id', 'status', 'progress_percentage', 'last_position_seconds', 'last_accessed_at'])->keyBy('version_activity_id');
        $sessions ??= $course->cohort_id
            ? $this->cohortSessions($customerId, [$course->cohort_id])->get($course->cohort_id, collect())
            : collect();

        $activities->each(function (object $activity) use ($studentId, $course, $progress, $sessions): void {
            $activity->phase = match (true) {
                (bool) $activity->available_before_session => 'before',
                (bool) $activity->available_during_session => 'during',
                (bool) $activity->available_after_session => 'after',
                default => 'anytime',
            };
            $decision = $this->activityAccess->decide($studentId, (int) $course->id, (int) $activity->id);
            $activity->allowed = $decision->allowed;
            $activity->reason = $decision->reason;
            $activity->unlock_at = $decision->unlockAt ? Carbon::parse($decision->unlockAt) : null;
            $stored = $progress->get($activity->id);
            $activity->progress_status = $stored->status ?? 'not_started';
            $activity->progress = (int) round((float) ($stored->progress_percentage ?? 0));
            $activity->resume_at = (int) ($stored->last_position_seconds ?? 0);
            $activity->last_viewed_at = isset($stored->last_accessed_at) ? Carbon::parse($stored->last_accessed_at) : null;
            // Played share as the lesson list shows it: whole percents, never rounded up to a finish not reached.
            $activity->played = (int) floor(min(100, (float) ($stored->progress_percentage ?? 0)));
            $activity->played_seconds = (int) round(min(100, (float) ($stored->progress_percentage ?? 0)) * (int) $activity->duration_seconds / 100);
            $activity->progress_rule = CourseProgressRecorder::progressRule($activity);
            $activity->embed_url = null;
            if ($activity->activity_type === 'embedded_video' && $activity->external_video_url_snapshot) {
                try {
                    $activity->embed_url = $this->trustedVideos->embedUrl($activity->external_video_url_snapshot);
                } catch (\Throwable) {
                    $activity->embed_url = null;
                }
            }
            // ADR-0035: the link reaches the page only for an Activity the learner may open.
            $activity->link_url = $decision->allowed && $activity->activity_type === 'external_link'
                && ExternalLinkUrl::isValid($activity->external_link_url_snapshot)
                ? $activity->external_link_url_snapshot
                : null;
            unset($activity->external_link_url_snapshot);
            $activity->sessions = $activity->activity_type === 'live_class'
                ? $sessions->filter(fn (object $s): bool => (int) ($s->version_activity_id ?? 0) === (int) $activity->id)->values()
                : collect();
        });

        return $activities;
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
        $attended = $this->attendedSessions($customerId, $rows->pluck('enrollment_id')->all());
        $now = Carbon::now();

        $rows->each(function (object $row) use ($customerId, $teachers, $sessions, $attended, $now): void {
            $row->awaiting_class = false;
            $this->markAccessEnded($row, $now);
            $row->teachers = $teachers->get($row->cohort_id, collect())->all();
            $this->summarizeSessions($customerId, $row, $sessions->get($row->cohort_id, collect()), $attended[$row->enrollment_id] ?? [], $now);
            $row->group = in_array($row->cohort_status, ['completed', 'archived'], true) ? 'completed' : 'current';
        });

        return [
            'current' => $this->awaitingClass($customerId, $studentId)->concat($rows->where('group', 'current'))->values(),
            'completed' => $rows->where('group', 'completed')->values(),
        ];
    }

    /**
     * Class-course Enrollments still in their cycle but without a seat in a visible Cohort: the learner sees
     * them as "Đang chờ xếp lớp" instead of nowhere (Owner 2026-11-11).
     *
     * @return Collection<int, object>
     */
    private function awaitingClass(int $customerId, int $studentId): Collection
    {
        return $this->enrollmentQuery($customerId, $studentId)
            ->whereIn('versions.course_type_snapshot', CourseType::COHORT_VALUES)
            ->whereIn('enrollments.status', EnrollmentEligibilityPolicy::NON_TERMINAL)
            ->whereNotExists(fn ($query) => $query->from('core_course_cohort_students as seats')
                ->join('core_course_cohorts as cohorts', function ($join) use ($customerId): void {
                    $join->on('cohorts.id', '=', 'seats.cohort_id')->where('cohorts.customer_id', $customerId);
                })
                ->whereColumn('seats.enrollment_id', 'enrollments.id')
                ->where('seats.customer_id', $customerId)
                ->where('seats.status', 'active')->whereNull('seats.left_at')
                ->where('cohorts.status', '!=', 'draft'))
            ->orderByDesc('enrollments.enrolled_at')->orderByDesc('enrollments.id')
            ->limit(50)
            ->get()
            ->each(function (object $row): void {
                $row->awaiting_class = true;
                $row->enrollment_id = $row->id;
                $row->name = null;
                $row->group = 'current';
            });
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
            ->get(['session_id', 'status', 'attendance_mode', 'attendance_source', 'joined_at'])->keyBy('session_id');
        $now = Carbon::now();

        $sessions->each(function (object $session) use ($attendance, $now, $class): void {
            $mark = $attendance->get($session->id);
            $session->attendance_status = $mark->status ?? null;
            // A self-study Session has no "Bắt đầu": it stays `scheduled` until it ends, so inside its planned time
            // the learner sees it as running, as the block of sessions to act on now already does (Đợt 4, Bước 2).
            $session->shown_status = $session->session_type === LiveClassSelfStudySession::TYPE && $session->status === 'scheduled'
                && $session->starts_at->lte($now) && $session->ends_at->gt($now) ? 'live' : $session->status;
            $session->attendance_mode = $mark->attendance_mode ?? null;
            $session->attendance_source = $mark->attendance_source ?? null;
            // When the learner entered, in the Session's timezone (MK 3.1); `joined_at` is stored in the application's.
            $session->attendance_entered_at = ($mark->joined_at ?? null) === null ? null
                : Carbon::parse((string) $mark->joined_at, config('app.timezone'))->setTimezone($session->timezone);
            // Waiting for a result (MK 3.1): an entry waiting for ticked content (ADR-0022 Amendment 1.3, Đợt 3), or
            // a Session that took place and is past its planned end with no result yet, until it is marked absent or
            // marked by hand. A Session nobody started stays `scheduled` and waits for nothing, and so does one that
            // ended longer ago than automatic absence looks back: nothing will record it any more.
            $unmarked = $mark === null || $mark->status === 'registered';
            $ended = in_array($session->status, ['live', 'completed'], true) && $session->ends_at->lte($now)
                && $session->ends_at->gte($now->copy()->subDays(LiveClassAttendanceRecorder::AUTO_ABSENT_LOOKBACK_DAYS));
            // Only an active Enrollment is still recorded (by hand or as automatic absence): a Session held while the
            // Enrollment is suspended or expired waits for nothing.
            $session->attendance_pending = $unmarked && $class->enrollment_status === 'active'
                && (($mark->joined_at ?? null) !== null || $ended);
            $session->attendance_makeup = $mark !== null && $mark->status === 'present' && $mark->attendance_source === 'makeup';
        });

        $this->markAccessEnded($class, $now);
        $class->teachers = $this->cohortTeachers($customerId, [$cohortId])->get($cohortId, collect())->all();
        $attendedIds = $sessions->filter(fn (object $s): bool => in_array($s->attendance_status, ['present', 'late'], true))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->summarizeSessions($customerId, $class, $sessions, $attendedIds, $now);
        $class->sessions = $sessions;

        return $class;
    }

    /**
     * The learning board of a class page (MK Live "Lịch học theo tuần"): on each Session the Activities of its Lesson
     * split into before / during / after, the learner's progress on the required ones, and the class totals. Reads only;
     * what opens when is still decided by the runtime access service.
     */
    public function cohortBoard(int $customerId, int $studentId, object $class): void
    {
        $course = $this->course($customerId, $studentId, (int) $class->enrollment_id);
        $class->course = $course;
        $lessons = $course
            ? $course->sections->flatMap(fn (object $section) => $section->lessons)->keyBy('id')
            : collect();
        $boards = [];
        foreach ($class->sessions->pluck('version_lesson_id')->filter()->unique() as $lessonId) {
            $lesson = $lessons->get((int) $lessonId);
            $boards[(int) $lessonId] = $lesson && $lesson->allowed
                ? $this->lessonActivities($customerId, $studentId, $course, (int) $lessonId, $class->sessions)
                : collect();
        }

        // A Lesson taught over several Sessions: "Trước" opens with its first Session and "Sau" with its last
        // (ADR-0022 Amendment 1.3, Đợt 2), so each phase shows under that Session only. A cancelled Session has no board.
        $taught = $class->sessions->reject(fn (object $s): bool => in_array($s->status, ['draft', 'cancelled'], true))
            ->filter(fn (object $s): bool => $s->version_lesson_id !== null)->groupBy('version_lesson_id');

        $done = fn (object $a): bool => $a->progress_status === 'completed';
        $ids = fn ($activities): array => $activities->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $class->sessions->each(function (object $session) use ($boards, $lessons, $done, $taught, $ids): void {
            $activities = $boards[(int) $session->version_lesson_id] ?? collect();
            $own = $taught->get($session->version_lesson_id) ?? collect();
            if ($session->status === 'cancelled') {
                $activities = collect();
            } elseif ($own->count() > 1) {
                $first = (int) $own->first()->id === (int) $session->id;
                $last = (int) $own->last()->id === (int) $session->id;
                $activities = $activities->filter(fn (object $a): bool => match ($a->phase) {
                    'before' => $first,
                    'after' => $last,
                    default => true,
                })->values();
            }
            $required = $activities->where('is_required', true);
            $session->lesson = $lessons->get((int) $session->version_lesson_id);
            $session->activities = $activities;
            $session->completion = $required->isEmpty() ? null
                : ['done' => $required->filter($done)->count(), 'total' => $required->count()];
            // The same count by Activity, so content completed on the page counts at once (learner-lesson.js).
            $session->completion_ids = ['required' => $ids($required), 'done' => $ids($required->filter($done))];
            // What the learner must finish for the Session's attendance (or the make-up), shown as "Điều kiện điểm danh".
            $session->attendance_requirements = $activities->where('counts_for_attendance', true)->values();
        });

        $required = collect($boards)->flatten(1)->where('is_required', true);
        $class->completion_done = $required->filter($done)->count();
        $class->completion_total = $required->count();
        $class->completion_ids = ['required' => $ids($required), 'done' => $ids($required->filter($done))];
        $class->completion_rate = $required->isEmpty() ? null : (int) round(100 * $class->completion_done / $required->count());

        // The learner's results over the Sessions held while they were in the class (ADR-0034).
        $held = $class->sessions->filter(fn (object $s): bool => $s->status === 'completed' && ($s->membership_outside ?? null) === null);
        $class->attendance_counts = [
            'present' => $held->filter(fn (object $s): bool => $s->attendance_status === 'present' && ! $s->attendance_makeup)->count(),
            'late' => $held->where('attendance_status', 'late')->count(),
            'makeup' => $held->where('attendance_makeup', true)->count(),
            'absent' => $held->where('attendance_status', 'absent')->count(),
            'excused' => $held->where('attendance_status', 'excused')->count(),
        ];
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
                'versions.short_description_snapshot as summary', 'versions.description_snapshot as description',
                'versions.category_name_snapshot as category',
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
                'enrollments.status as enrollment_status', 'enrollments.access_starts_at', 'enrollments.access_ends_at',
                'enrollments.review_ends_at', 'enrollments.expired_at');
    }

    /**
     * Why the learner cannot learn in a class for now (`access_hold`): `ended` when the Enrollment has run out
     * (`expired`, or its window is over before the job ran), `suspended` while the centre has paused it, otherwise
     * null; with the date it ran out. Display only: what opens is still decided by the runtime access service.
     */
    private function markAccessEnded(object $row, Carbon $now): void
    {
        $row->access_ended = $row->enrollment_status === 'expired' || $this->phase($row, $now) === 'ended';
        $endsAt = $row->review_ends_at ?? $row->access_ends_at ?? $row->expired_at;
        $row->access_ended_at = $row->access_ended && $endsAt !== null ? Carbon::parse((string) $endsAt) : null;
        $row->access_hold = match (true) {
            $row->access_ended => 'ended',
            $row->enrollment_status === 'suspended' => 'suspended',
            default => null,
        };
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
            ->get(['id', 'cohort_id', 'version_lesson_id', 'version_activity_id', 'title', 'session_no', 'session_type', 'delivery_mode', 'attendance_tool', 'status',
                'scheduled_start_at', 'scheduled_end_at', 'timezone', 'meeting_url_snapshot',
                'facility_name_snapshot', 'room_name_snapshot', 'address_snapshot', 'cancellation_reason'])
            ->each(function (object $session): void {
                // Session times are wall-clock in the row's own timezone (Canonical Time Convention).
                $session->starts_at = Carbon::parse($session->scheduled_start_at, $session->timezone);
                $session->ends_at = Carbon::parse($session->scheduled_end_at, $session->timezone);
            })
            ->groupBy('cohort_id');
    }

    /**
     * Adds the Session counts, the next Session and the attendance rate to a Cohort row, and marks on each Session
     * whether it is outside the learner's time in the class (`membership_outside`, ADR-0034): such a Session shows
     * no result and does not count toward the attendance rate.
     *
     * @param  array<int, int>  $attendedIds  the Sessions the learner was present or late at
     */
    private function summarizeSessions(int $customerId, object $row, Collection $sessions, array $attendedIds, Carbon $now): void
    {
        $gaps = CourseCohortMembershipHistory::gaps($customerId, (int) $row->cohort_id, [(int) $row->enrollment_id])->get((int) $row->enrollment_id);
        $sessions->each(function (object $s) use ($row, $gaps): void {
            $s->membership_outside = CourseCohortMembershipHistory::outside($row->joined_at, null, $gaps, $s->ends_at);
        });
        $counted = $sessions->reject(fn (object $s): bool => in_array($s->status, self::VOID_SESSION_STATUSES, true));
        // Held means taught to the end: a Session still running, or left `scheduled` after its time, was not
        // learned yet and must not raise the progress or lower the attendance rate (Owner 2026-11-18).
        $held = $counted->filter(fn (object $s): bool => $s->status === 'completed' && $s->membership_outside === null);
        $attended = $held->filter(fn (object $s): bool => in_array((int) $s->id, $attendedIds, true))->count();

        $row->session_total = $counted->count();
        $row->session_held = $held->count();
        $row->session_attended = $attended;
        $row->attendance_rate = $held->count() > 0 ? (int) round(100 * min($attended, $held->count()) / $held->count()) : 0;
        $row->schedule_rate = $counted->count() > 0 ? (int) round(100 * $held->count() / $counted->count()) : 0;
        $row->live_session = $sessions->firstWhere('status', 'live');
        $row->next_session = $sessions->first(fn (object $s): bool => $s->status === 'scheduled' && $s->ends_at->gt($now));
        // A self-study Session has no delivery mode.
        $row->delivery_modes = $counted->pluck('delivery_mode')->filter()->unique()->values()->all();
    }

    /** @return array<int, array<int, int>> the Sessions each Enrollment was present or late at */
    private function attendedSessions(int $customerId, array $enrollmentIds): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        return DB::table('core_liveclass_attendances')
            ->where('customer_id', $customerId)
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereIn('status', ['present', 'late'])
            ->get(['enrollment_id', 'session_id'])
            ->groupBy('enrollment_id')
            ->map(fn (Collection $rows): array => $rows->pluck('session_id')->map(fn ($id): int => (int) $id)->unique()->values()->all())
            ->all();
    }

    /** @return Collection<int, Collection<int, string>> active Template teachers per Template */
    public function templateTeachers(int $customerId, array $templateIds): Collection
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
            ->orderByRaw("CASE assignments.role WHEN 'primary_teacher' THEN 0 WHEN 'teacher' THEN 1 ELSE 2 END")->orderBy('assignments.id')
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

    /**
     * The class page a class Enrollment's lesson and Quiz pages lead back to, at the Session of the Lesson the learner
     * was in (the latest one already started, else the first); null outside a class the learner can open. Content shown
     * only "Trước buổi học" or only "Sau buổi học" is on the Lesson's first or last Session (ADR-0022 Amendment 1.3).
     */
    public function classReturnUrl(int $customerId, int $studentId, int $enrollmentId, ?int $versionLessonId = null, ?string $phase = null): ?string
    {
        $cohortId = $this->seatQuery($customerId, $studentId)->where('seats.enrollment_id', $enrollmentId)
            ->orderByDesc('seats.id')->first()?->cohort_id;
        if ($cohortId === null) {
            return null;
        }
        $sessions = $versionLessonId === null ? collect() : DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)->where('cohort_id', $cohortId)->where('version_lesson_id', $versionLessonId)
            ->where('status', '!=', 'cancelled')->orderBy('scheduled_start_at')->orderBy('id')->get(['id', 'scheduled_start_at']);
        $now = Carbon::now();
        $session = match ($phase) {
            'before' => $sessions->first(),
            'after' => $sessions->last(),
            default => $sessions->last(fn (object $s): bool => $s->scheduled_start_at !== null && Carbon::parse($s->scheduled_start_at)->lte($now))
                ?? $sessions->first(),
        };

        return route('student.courses.classes.show', (int) $cohortId).($session ? '#live-lesson-'.$session->id : '');
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
