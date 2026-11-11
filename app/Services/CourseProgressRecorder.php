<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of Course, Lesson and Activity Progress (ADR-0030 D3). Every context value
 * comes from the Enrollment and the frozen Version Activity; a request carries only the event
 * and a playback position.
 */
class CourseProgressRecorder
{
    /** Version Activity completion rule → Activity Progress completion rule (ADR-0030 D3, ADR-0032 D3). */
    private const RULES = [
        'view' => 'viewed',
        'manual' => 'manual',
        'join' => 'attended',
        'submit' => 'submitted',
        'pass' => 'passed',
    ];

    public const EVENTS = ['opened', 'position', 'completed'];

    /** A position may move ahead of the time that passed since the last record by this factor plus a slack. */
    private const POSITION_SPEED = 1.5;

    private const POSITION_SLACK_SECONDS = 30;

    /** Learning time added per record never exceeds this, whatever the client says. */
    private const MAX_SECONDS_PER_RECORD = 90;

    public function __construct(
        private readonly VersionActivityAccessService $activityAccess,
        private readonly VersionLessonAccessService $lessonAccess,
    ) {}

    /**
     * Records a learner event on one Activity. Returns the Activity Progress row, or null when the
     * learner may not learn this Activity now (the caller answers 403).
     */
    public function record(int $customerId, int $studentId, int $enrollmentId, int $versionActivityId, string $event, ?float $position = null): ?object
    {
        if (! $this->activityAccess->decide($studentId, $enrollmentId, $versionActivityId)->allowed) {
            return null;
        }
        $activity = $this->activity($customerId, $versionActivityId);
        // A quiz moves through its attempts (quizChanged); only its manual rule takes a learner event.
        if (! $activity || ($activity->activity_type === 'quiz' && $activity->completion_rule !== 'manual')
            || ! $this->lessonAccess->decide($studentId, $enrollmentId, (int) $activity->version_lesson_id)->allowed) {
            return null;
        }

        return DB::transaction(function () use ($customerId, $enrollmentId, $activity, $event, $position): object {
            $enrollment = $this->enrollment($customerId, $enrollmentId);
            $course = $this->lockCourse($customerId, $enrollment);
            $lesson = $this->lessonRow($customerId, $enrollment, $course, (int) $activity->version_lesson_id);
            $row = $this->activityRow($customerId, $enrollment, $course, $lesson, $activity);
            $now = Carbon::now();

            $values = [
                'first_accessed_at' => $row->first_accessed_at ?? $now,
                'last_accessed_at' => $now,
                'started_at' => $row->started_at ?? $now,
                'updated_at' => $now,
            ];
            if ($row->status === 'not_started') {
                $values['status'] = 'in_progress';
            }
            $done = $row->status === 'completed';
            $rule = $row->completion_rule;

            if ($event === 'opened' && $rule === 'viewed') {
                $done = true;
            }
            if ($event === 'position' && $position !== null && in_array($rule, ['watch_percentage', 'listen_percentage'], true)) {
                $values += $this->position($row, $activity, $position, $now);
                $values['progress_percentage'] = max((float) $row->progress_percentage, $values['progress_percentage']);
                if ($values['progress_percentage'] >= (float) ($row->completion_threshold ?? 100)) {
                    $done = true;
                }
            }
            if ($event === 'completed' && ($rule === 'manual'
                || (in_array($rule, ['watch_percentage', 'listen_percentage'], true) && (int) $activity->duration_seconds <= 0))) {
                // Without a known duration the player's own end is the only signal.
                $done = true;
            }
            if ($done && $row->status !== 'completed') {
                $values = ['status' => 'completed', 'completed_at' => $now, 'progress_percentage' => 100] + $values;
            }

            DB::table('core_course_activity_progress')->where('id', $row->id)->update($values);
            DB::table('core_course_progress')->where('id', $course->id)->update([
                'last_version_activity_id' => $activity->id,
                'last_version_lesson_id' => $activity->version_lesson_id,
                'last_accessed_at' => $now,
                'started_at' => $course->started_at ?? $now,
            ]);
            $this->recalculate($customerId, $enrollment, $course->id);

            return DB::table('core_course_activity_progress')->where('id', $row->id)->first();
        }, 3);
    }

    /**
     * Opening a Lesson without Activities completes it (ADR-0030 D3); with Activities it only marks access.
     * Returns false when the learner may not open the Lesson.
     */
    public function openLesson(int $customerId, int $studentId, int $enrollmentId, int $versionLessonId): bool
    {
        if (! $this->lessonAccess->decide($studentId, $enrollmentId, $versionLessonId)->allowed) {
            return false;
        }

        DB::transaction(function () use ($customerId, $enrollmentId, $versionLessonId): void {
            $enrollment = $this->enrollment($customerId, $enrollmentId);
            $course = $this->lockCourse($customerId, $enrollment);
            $lesson = $this->lessonRow($customerId, $enrollment, $course, $versionLessonId);
            $now = Carbon::now();
            DB::table('core_course_lesson_progress')->where('id', $lesson->id)->update([
                'first_accessed_at' => $lesson->first_accessed_at ?? $now,
                'last_accessed_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('core_course_progress')->where('id', $course->id)->update([
                'last_version_lesson_id' => $versionLessonId,
                'last_accessed_at' => $now,
                'started_at' => $course->started_at ?? $now,
            ]);
            $this->recalculate($customerId, $enrollment, $course->id);
        }, 3);

        return true;
    }

    /**
     * LiveClass calls this after attendance changed for the given Enrollments. `attended` follows the
     * evidence both ways: present or late at a Session bound to the Activity that was not cancelled or no-show.
     *
     * @param  list<int>  $enrollmentIds
     */
    public function attendanceChanged(int $customerId, array $enrollmentIds, ?int $versionActivityId): void
    {
        if (! $versionActivityId || $enrollmentIds === []) {
            return;
        }
        $activity = $this->activity($customerId, $versionActivityId);
        if (! $activity || $activity->completion_rule !== 'join') {
            return;
        }

        foreach (array_unique($enrollmentIds) as $enrollmentId) {
            DB::transaction(function () use ($customerId, $enrollmentId, $activity): void {
                $enrollment = $this->enrollment($customerId, (int) $enrollmentId);
                if (! $enrollment || $enrollment->status !== 'active' || (int) $enrollment->version_id !== (int) $activity->template_version_id) {
                    return;
                }
                // Read Attendance only once the Course Progress lock is held, so the newest change wins.
                $course = $this->lockCourse($customerId, $enrollment);
                $attended = DB::table('core_liveclass_attendances as attendances')
                    ->join('core_liveclass_sessions as sessions', function ($join) use ($customerId): void {
                        $join->on('sessions.id', '=', 'attendances.session_id')->where('sessions.customer_id', $customerId);
                    })
                    ->where('attendances.customer_id', $customerId)
                    ->where('attendances.enrollment_id', $enrollment->id)
                    ->where('sessions.version_activity_id', $activity->id)
                    ->whereIn('attendances.status', ['present', 'late'])
                    ->whereNotIn('sessions.status', ['cancelled', 'no_show'])
                    ->exists();
                $lesson = $this->lessonRow($customerId, $enrollment, $course, (int) $activity->version_lesson_id);
                $row = $this->activityRow($customerId, $enrollment, $course, $lesson, $activity);
                if ($attended === ($row->status === 'completed')) {
                    return;
                }
                $now = Carbon::now();
                DB::table('core_course_activity_progress')->where('id', $row->id)->update($attended
                    // `started_at` marks a move to in_progress (DB docs); attendance goes straight to completed.
                    ? ['status' => 'completed', 'completed_at' => $now, 'progress_percentage' => 100, 'updated_at' => $now]
                    : ['status' => $row->started_at ? 'in_progress' : 'not_started', 'completed_at' => null, 'progress_percentage' => 0, 'updated_at' => $now]);
                $this->recalculate($customerId, $enrollment, $course->id);
            }, 3);
        }
    }

    /**
     * The Module Kiểm tra calls this after a course attempt started, was submitted or (re)graded (ADR-0032 D3). It reads
     * every attempt of the Enrollment on the Activity: `attempt_count`, the best graded score, and completion by the
     * Activity's rule. Completion is never taken back.
     */
    public function quizChanged(int $customerId, int $enrollmentId, int $versionActivityId): void
    {
        $activity = $this->activity($customerId, $versionActivityId);
        if (! $activity || $activity->activity_type !== 'quiz') {
            return;
        }

        DB::transaction(function () use ($customerId, $enrollmentId, $activity): void {
            $enrollment = $this->enrollment($customerId, $enrollmentId);
            if (! $enrollment || $enrollment->status === 'cancelled' || (int) $enrollment->version_id !== (int) $activity->template_version_id) {
                return;
            }
            // Read attempts only once the Course Progress lock is held, so the newest change wins.
            $course = $this->lockCourse($customerId, $enrollment);
            $attempts = DB::table('core_assessment_attempts')
                ->where('customer_id', $customerId)->where('enrollment_id', $enrollment->id)->where('version_activity_id', $activity->id)
                ->get(['status', 'started_at', 'submitted_at', 'graded_at', 'score', 'max_score', 'score_percentage']);
            if ($attempts->isEmpty()) {
                return;
            }
            $lesson = $this->lessonRow($customerId, $enrollment, $course, (int) $activity->version_lesson_id);
            $row = $this->activityRow($customerId, $enrollment, $course, $lesson, $activity);
            $done = $attempts->where('status', '!=', 'in_progress');
            $graded = $attempts->where('status', 'graded');
            $best = $graded->sortByDesc(fn (object $attempt): float => (float) $attempt->score_percentage)->first();
            $threshold = (float) ($activity->completion_threshold ?? 100);
            $passed = $best ? (float) $best->score_percentage >= $threshold : null;
            $complete = $row->status === 'completed' || match ($activity->completion_rule) {
                'submit' => $done->isNotEmpty(),
                'pass' => (bool) $passed,
                default => false,
            };
            $now = Carbon::now();
            $first = $attempts->min('started_at');

            DB::table('core_course_activity_progress')->where('id', $row->id)->update([
                'attempt_count' => $attempts->count(),
                'score' => $best?->score,
                'max_score' => $best?->max_score,
                'passed' => $passed,
                'submitted_at' => $done->max('submitted_at'),
                'graded_at' => $graded->max('graded_at'),
                'first_accessed_at' => $row->first_accessed_at ?? $first,
                'last_accessed_at' => $now,
                'started_at' => $row->started_at ?? $first,
                'status' => $complete ? 'completed' : 'in_progress',
                'completed_at' => $complete ? ($row->completed_at ?? $now) : null,
                'progress_percentage' => $complete ? 100 : $row->progress_percentage,
                'updated_at' => $now,
            ]);
            DB::table('core_course_progress')->where('id', $course->id)->update([
                'last_version_activity_id' => $activity->id,
                'last_version_lesson_id' => $activity->version_lesson_id,
                'last_accessed_at' => $now,
                'started_at' => $course->started_at ?? $first,
            ]);
            $this->recalculate($customerId, $enrollment, $course->id);
        }, 3);
    }

    /** Version Activity completion rule as the progress tables name it. */
    public static function progressRule(object $activity): string
    {
        if ($activity->completion_rule === 'watch_percent') {
            return $activity->activity_type === 'audio' ? 'listen_percentage' : 'watch_percentage';
        }

        return self::RULES[$activity->completion_rule] ?? 'manual';
    }

    /** @return array<string, mixed> position columns after a `position` event */
    private function position(object $row, object $activity, float $position, Carbon $now): array
    {
        $meta = json_decode((string) ($row->metadata ?? ''), true) ?: [];
        $duration = max(0, (int) $activity->duration_seconds);
        $position = max(0.0, $duration > 0 ? min($position, (float) $duration) : $position);
        $previousMax = (float) ($meta['max_position_seconds'] ?? 0);
        $last = isset($meta['position_recorded_at']) ? Carbon::parse($meta['position_recorded_at']) : null;
        $elapsed = $last ? max(0, $last->diffInSeconds($now, false)) : 0;
        $allowedMax = $previousMax + $elapsed * self::POSITION_SPEED + self::POSITION_SLACK_SECONDS;
        $max = max($previousMax, min($position, $allowedMax));

        $meta['max_position_seconds'] = round($max, 1);
        $meta['position_recorded_at'] = $now->toIso8601String();

        return [
            'last_position_seconds' => (int) floor($position),
            'total_learning_seconds' => (int) $row->total_learning_seconds + ($last ? min((int) $elapsed, self::MAX_SECONDS_PER_RECORD) : 0),
            'progress_percentage' => $duration > 0 ? round(min(100, 100 * $max / $duration), 2) : (float) $row->progress_percentage,
            'metadata' => json_encode($meta),
        ];
    }

    /** Recounts the Lessons and the Course of the Enrollment from Activity Progress. */
    private function recalculate(int $customerId, object $enrollment, int $courseProgressId): void
    {
        $now = Carbon::now();
        $lessons = DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)->where('template_version_id', $enrollment->version_id)
            ->get(['id', 'version_section_id', 'sort_order']);
        $activities = DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)->where('template_version_id', $enrollment->version_id)
            ->get(['id', 'version_lesson_id', 'is_required', 'activity_type'])
            ->groupBy('version_lesson_id');
        $done = DB::table('core_course_activity_progress')
            ->where('customer_id', $customerId)->where('enrollment_id', $enrollment->id)->where('status', 'completed')
            ->pluck('version_activity_id')->flip();
        $lessonRows = DB::table('core_course_lesson_progress')
            ->where('customer_id', $customerId)->where('enrollment_id', $enrollment->id)->get()->keyBy('version_lesson_id');
        $perLesson = DB::table('core_course_activity_progress')
            ->where('customer_id', $customerId)->where('enrollment_id', $enrollment->id)
            ->groupBy('lesson_progress_id')
            ->selectRaw("lesson_progress_id, SUM(total_learning_seconds) as seconds, SUM(CASE WHEN status <> 'not_started' THEN 1 ELSE 0 END) as started")
            ->get()->keyBy('lesson_progress_id');

        $totals = ['lessons' => 0, 'lessons_done' => 0, 'activities' => 0, 'activities_done' => 0, 'required' => 0, 'required_done' => 0, 'assessments' => 0, 'assessments_done' => 0, 'seconds' => 0];
        foreach ($lessons as $lesson) {
            /** @var Collection<int, object> $items */
            $items = $activities->get($lesson->id, collect());
            $required = $items->filter(fn (object $a): bool => (bool) $a->is_required);
            $counted = $required->isNotEmpty() ? $required : $items;
            $completedCount = $items->filter(fn (object $a): bool => $done->has($a->id))->count();
            $requiredDone = $required->filter(fn (object $a): bool => $done->has($a->id))->count();
            $countedDone = $counted->filter(fn (object $a): bool => $done->has($a->id))->count();
            $row = $lessonRows->get($lesson->id);
            $complete = $items->isEmpty() ? ($row?->first_accessed_at !== null) : $countedDone === $counted->count();

            $totals['lessons']++;
            $totals['lessons_done'] += $complete ? 1 : 0;
            $totals['activities'] += $items->count();
            $totals['activities_done'] += $completedCount;
            $totals['required'] += $required->count();
            $totals['required_done'] += $requiredDone;
            // Assessments = required Quiz Activities (ADR-0032).
            $quizzes = $required->filter(fn (object $a): bool => $a->activity_type === 'quiz');
            $totals['assessments'] += $quizzes->count();
            $totals['assessments_done'] += $quizzes->filter(fn (object $a): bool => $done->has($a->id))->count();

            if (! $row) {
                continue;
            }
            $seconds = (int) ($perLesson->get($row->id)->seconds ?? 0);
            $totals['seconds'] += $seconds;
            $started = $complete || (int) ($perLesson->get($row->id)->started ?? 0) > 0;
            DB::table('core_course_lesson_progress')->where('id', $row->id)->update([
                'completed_activities' => $completedCount,
                'total_activities' => $items->count(),
                'required_activities_completed' => $requiredDone,
                'required_activities_total' => $required->count(),
                'progress_percentage' => $counted->isEmpty() ? ($complete ? 100 : 0) : round(100 * $countedDone / $counted->count(), 2),
                'total_learning_seconds' => $seconds,
                'status' => $complete ? 'completed' : ($started ? 'in_progress' : 'not_started'),
                'started_at' => $row->started_at ?? ($started ? $now : null),
                'completed_at' => $complete ? ($row->completed_at ?? $now) : null,
                'recalculated_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $percentage = $totals['required'] > 0
            ? 100 * $totals['required_done'] / $totals['required']
            : ($totals['lessons'] > 0 ? 100 * $totals['lessons_done'] / $totals['lessons'] : 0);
        $complete = $totals['lessons'] > 0 && $totals['lessons_done'] === $totals['lessons'];
        $course = DB::table('core_course_progress')->where('id', $courseProgressId)->first(['completed_at']);
        DB::table('core_course_progress')->where('id', $courseProgressId)->update([
            'progress_percentage' => round($percentage, 2),
            'completed_lessons' => $totals['lessons_done'],
            'total_lessons' => $totals['lessons'],
            'completed_activities' => $totals['activities_done'],
            'total_activities' => $totals['activities'],
            'required_activities_completed' => $totals['required_done'],
            'required_activities_total' => $totals['required'],
            'assessment_completed' => $totals['assessments_done'],
            'assessment_total' => $totals['assessments'],
            'total_learning_seconds' => $totals['seconds'],
            'status' => $complete ? 'completed' : 'in_progress',
            'completed_at' => $complete ? ($course->completed_at ?? $now) : null,
            'recalculated_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * The first read of every write transaction. It is a locking read on purpose: on MariaDB
     * (REPEATABLE READ) the first plain read fixes the snapshot, and a snapshot taken before
     * waiting for the Course Progress lock would miss the rows a concurrent event just committed.
     */
    private function enrollment(int $customerId, int $enrollmentId): ?object
    {
        return DB::table('core_course_enrollments')->where('customer_id', $customerId)->where('id', $enrollmentId)->sharedLock()->first();
    }

    private function activity(int $customerId, int $versionActivityId): ?object
    {
        return DB::table('core_course_template_version_activities as activities')
            ->join('core_course_template_version_lessons as lessons', function ($join) use ($customerId): void {
                $join->on('lessons.id', '=', 'activities.version_lesson_id')->where('lessons.customer_id', $customerId);
            })
            ->where('activities.customer_id', $customerId)->where('activities.id', $versionActivityId)
            ->first(['activities.id', 'activities.template_version_id', 'activities.version_lesson_id', 'activities.activity_type',
                'activities.sort_order', 'activities.is_required', 'activities.completion_rule', 'activities.completion_threshold',
                'activities.duration_seconds', 'lessons.version_section_id']);
    }

    /** The Enrollment's Course Progress row, created on first use and locked for the rest of the transaction. */
    private function lockCourse(int $customerId, object $enrollment): object
    {
        $key = ['customer_id' => $customerId, 'enrollment_id' => $enrollment->id];
        $row = DB::table('core_course_progress')->where($key)->lockForUpdate()->first();
        if ($row) {
            return $row;
        }
        DB::table('core_course_progress')->insertOrIgnore($key + [
            'product_id' => $enrollment->product_id, 'version_id' => $enrollment->version_id,
            'student_id' => $enrollment->student_id, 'status' => 'not_started',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('core_course_progress')->where($key)->lockForUpdate()->first();
    }

    private function lessonRow(int $customerId, object $enrollment, object $course, int $versionLessonId): object
    {
        $key = ['customer_id' => $customerId, 'enrollment_id' => $enrollment->id, 'version_lesson_id' => $versionLessonId];
        $row = DB::table('core_course_lesson_progress')->where($key)->first();
        if ($row) {
            return $row;
        }
        $lesson = DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)->where('template_version_id', $enrollment->version_id)->where('id', $versionLessonId)
            ->first(['version_section_id', 'sort_order']);
        abort_if(! $lesson, 404);
        DB::table('core_course_lesson_progress')->insert($key + [
            'course_progress_id' => $course->id, 'product_id' => $enrollment->product_id, 'version_id' => $enrollment->version_id,
            'student_id' => $enrollment->student_id, 'version_section_id' => $lesson->version_section_id,
            'sort_order' => max(1, (int) $lesson->sort_order), 'status' => 'not_started',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('core_course_lesson_progress')->where($key)->first();
    }

    private function activityRow(int $customerId, object $enrollment, object $course, object $lesson, object $activity): object
    {
        $key = ['customer_id' => $customerId, 'enrollment_id' => $enrollment->id, 'version_activity_id' => $activity->id];
        $row = DB::table('core_course_activity_progress')->where($key)->first();
        if ($row) {
            return $row;
        }
        DB::table('core_course_activity_progress')->insert($key + [
            'course_progress_id' => $course->id, 'lesson_progress_id' => $lesson->id,
            'product_id' => $enrollment->product_id, 'version_id' => $enrollment->version_id, 'student_id' => $enrollment->student_id,
            'version_section_id' => $activity->version_section_id, 'version_lesson_id' => $activity->version_lesson_id,
            'activity_type' => $activity->activity_type, 'sort_order' => max(1, (int) $activity->sort_order),
            'is_required' => (bool) $activity->is_required, 'completion_rule' => self::progressRule($activity),
            'completion_threshold' => $activity->completion_threshold, 'status' => 'not_started',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('core_course_activity_progress')->where($key)->first();
    }
}
