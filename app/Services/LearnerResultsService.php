<?php

namespace App\Services;

use App\Services\Assessment\AssessmentLearnerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Kết quả học tập" of the signed-in learner (ADR-0031): self-paced courses, classes and tests, each with a summary
 * and one row per item. It reads what "Khóa học của tôi" already shows (same scope, same attendance rate) and never
 * writes. A test score is used only when ADR-0025 D12 lets the learner see it (D3).
 */
class LearnerResultsService
{
    public const VIEWS = ['courses', 'classes', 'exams'];

    public function __construct(
        private readonly LearnerMyLearningService $learning,
        private readonly AssessmentLearnerService $assessments,
    ) {}

    /** @return array{rows: Collection<int, object>, summary: array<string, mixed>} */
    public function courses(int $customerId, int $studentId): array
    {
        $rows = collect($this->learning->courses($customerId, $studentId))->flatten(1)->values();
        $progress = DB::table('core_course_progress')
            ->where('customer_id', $customerId)->where('student_id', $studentId)
            ->whereIn('enrollment_id', $rows->pluck('id'))
            ->get(['enrollment_id', 'completed_lessons', 'total_lessons', 'total_learning_seconds', 'last_accessed_at'])
            ->keyBy('enrollment_id');

        $rows->each(function (object $row) use ($progress): void {
            $mark = $progress->get($row->id);
            $row->lessons_done = (int) ($mark->completed_lessons ?? 0);
            $row->lessons_total = $mark && (int) $mark->total_lessons > 0 ? (int) $mark->total_lessons : (int) $row->lesson_total;
            $row->learning_seconds = (int) ($mark->total_learning_seconds ?? 0);
            $row->last_accessed_at = $mark?->last_accessed_at ? Carbon::parse($mark->last_accessed_at) : null;
        });
        $lessons = (int) $rows->sum('lessons_total');
        $done = (int) $rows->sum('lessons_done');

        return ['rows' => $rows, 'summary' => [
            'total' => $rows->count(),
            'completed' => $rows->where('group', 'completed')->count(),
            'current' => $rows->where('group', 'current')->count(),
            'expired' => $rows->where('group', 'expired')->count(),
            'lessons_done' => $done,
            'lessons_total' => $lessons,
            'lessons_rate' => $lessons > 0 ? (int) round(100 * $done / $lessons) : 0,
            'learning_seconds' => (int) $rows->sum('learning_seconds'),
            'last_accessed_at' => $rows->pluck('last_accessed_at')->filter()->max(),
        ]];
    }

    /** @return array{rows: Collection<int, object>, summary: array<string, mixed>} */
    public function classes(int $customerId, int $studentId): array
    {
        // A class course still waiting for its class has no attendance yet.
        $rows = collect($this->learning->classes($customerId, $studentId))->flatten(1)
            ->reject(fn (object $row): bool => (bool) $row->awaiting_class)->values();
        $rows->each(function (object $row): void {
            $row->session_absent = max(0, $row->session_held - $row->session_attended);
        });
        $held = (int) $rows->sum('session_held');
        $attended = (int) $rows->sum(fn (object $row): int => min($row->session_attended, $row->session_held));

        return ['rows' => $rows, 'summary' => [
            'total' => $rows->count(),
            'current' => $rows->where('group', 'current')->count(),
            'completed' => $rows->where('group', 'completed')->count(),
            'sessions_total' => (int) $rows->sum('session_total'),
            'sessions_held' => $held,
            'attended' => $attended,
            'absent' => (int) $rows->sum('session_absent'),
            'attendance_rate' => $held > 0 ? (int) round(100 * $attended / $held) : 0,
        ]];
    }

    /**
     * Registrations of "Bài thi của tôi", then the Quiz Activities of the learner's courses (ADR-0032 D4), one row per
     * Enrollment + Activity.
     *
     * @return array{rows: Collection<int, object>, summary: array<string, mixed>}
     */
    public function exams(int $customerId, int $studentId): array
    {
        $this->assessments->closeOverdue($customerId, $studentId);
        $registrations = $this->assessments->myRegistrations($customerId, $studentId)->each(function (object $registration): void {
            $registration->status_label = __('lf.LF_assessment_registration_status_'.$registration->status);
            $registration->course_title = null;
        });
        $rows = $registrations->concat($this->courseQuizzes($customerId, $studentId))->values()->each(function (object $registration): void {
            $done = $registration->attempts->where('status', '!=', 'in_progress')->values();
            $shown = $done->filter(fn (object $attempt): bool => (bool) $attempt->visible['score']);
            $registration->submitted = $done;
            $registration->latest = $done->last();
            $registration->latest_shown = $registration->latest && $registration->latest->visible['score'];
            $registration->best = $shown->sortByDesc(fn (object $attempt): float => (float) $attempt->score_percentage)->first();
            $registration->last_submitted_at = $done->pluck('submitted_at')->filter()->max();
        });
        $latest = $rows->filter(fn (object $registration): bool => $registration->latest_shown)
            ->map(fn (object $registration): float => (float) $registration->latest->score_percentage);

        return ['rows' => $rows, 'summary' => [
            'total' => $rows->count(),
            'submitted' => $rows->filter(fn (object $registration): bool => $registration->submitted->isNotEmpty())->count(),
            'attempts' => (int) $rows->sum(fn (object $registration): int => $registration->submitted->count()),
            'average' => $latest->isNotEmpty() ? round($latest->avg(), 1) : null,
        ]];
    }

    /** @return Collection<int, object> rows shaped like a registration: title, max_attempts, attempts (with `visible`) */
    private function courseQuizzes(int $customerId, int $studentId): Collection
    {
        $activities = DB::table('core_course_enrollments as e')
            ->join('core_course_template_version_activities as va', function ($join): void {
                $join->on('va.template_version_id', '=', 'e.version_id')->on('va.customer_id', '=', 'e.customer_id');
            })
            ->join('core_assessment_quiz_versions as v', function ($join): void {
                $join->on('v.id', '=', 'va.assessment_quiz_version_id_snapshot')->on('v.customer_id', '=', 'va.customer_id');
            })
            ->leftJoin('core_course_products as p', function ($join): void {
                $join->on('p.id', '=', 'e.product_id')->on('p.customer_id', '=', 'e.customer_id');
            })
            ->where('e.customer_id', $customerId)->where('e.student_id', $studentId)->where('e.status', '!=', 'cancelled')
            ->where('va.activity_type', 'quiz')
            ->orderBy('e.id')->orderBy('va.version_lesson_id')->orderBy('va.sort_order')->orderBy('va.id')
            ->get(['e.id as enrollment_id', 'va.id as activity_id', 'va.title_snapshot as activity_title', 'p.title as course_title',
                'v.title', 'v.max_attempts', 'v.show_result_mode']);
        if ($activities->isEmpty()) {
            return collect();
        }
        $attempts = DB::table('core_assessment_attempts')->where('customer_id', $customerId)->where('user_id', $studentId)
            ->whereIn('enrollment_id', $activities->pluck('enrollment_id')->unique())->orderBy('attempt_no')->get()
            ->groupBy(fn (object $attempt): string => $attempt->enrollment_id.'-'.$attempt->version_activity_id);

        return $activities->map(function (object $activity) use ($attempts): object {
            $mine = $attempts->get($activity->enrollment_id.'-'.$activity->activity_id, collect())->values()
                ->each(fn (object $attempt) => $attempt->visible = $this->assessments->visibility($attempt, $activity));

            return (object) [
                'id' => 'course-'.$activity->enrollment_id.'-'.$activity->activity_id,
                'title' => $activity->activity_title ?: $activity->title,
                'course_title' => $activity->course_title,
                'status_label' => __('lf.LF_my_learning_results_in_course'),
                'max_attempts' => $activity->max_attempts,
                'attempts' => $mine,
            ];
        });
    }
}
