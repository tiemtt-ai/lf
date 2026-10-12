<?php

namespace App\Services;

use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a teacher may see of the cohorts they teach, for the judgment surface.
 * Every read starts from the teacher's own active assignment in the current
 * tenant; a cohort without one is not found. TeacherJudgmentService stays the
 * authority on submissions.
 */
final class TeacherCohortReadService
{
    public function __construct(private readonly LearningCourseCompetencyReadService $competencies) {}

    /** @return Collection<int, object> the teacher's cohorts, each with why it can or cannot be judged now */
    public function cohorts(int $teacherId): Collection
    {
        $customerId = $this->tenant();
        $today = Carbon::today()->toDateString();

        return DB::table('core_course_cohorts as cohorts')
            ->join('core_course_products as products', function ($join): void {
                $join->on('products.id', '=', 'cohorts.product_id')->on('products.customer_id', '=', 'cohorts.customer_id');
            })
            ->where('cohorts.customer_id', $customerId)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('core_course_cohort_teachers as assignments')
                ->whereColumn('assignments.cohort_id', 'cohorts.id')->whereColumn('assignments.customer_id', 'cohorts.customer_id')
                ->where('assignments.teacher_id', $teacherId)->where('assignments.status', 'active'))
            ->orderByRaw("CASE WHEN cohorts.status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('cohorts.start_date')->orderBy('cohorts.name')
            ->get(['cohorts.id', 'cohorts.name', 'cohorts.status', 'cohorts.start_date', 'cohorts.end_date', 'cohorts.version_id', 'products.title as product_title'])
            ->map(function (object $cohort) use ($customerId, $teacherId, $today): object {
                $cohort->student_count = DB::table('core_course_cohort_students')->where('customer_id', $customerId)
                    ->where('cohort_id', $cohort->id)->where('status', 'active')->whereNull('left_at')->count();
                $cohort->bases = $this->competencies->bases($customerId, (int) $cohort->version_id);
                $cohort->blocked_reason = $this->blockedReason($cohort, $this->assignment($teacherId, (int) $cohort->id, $today), $today);

                return $cohort;
            });
    }

    /** The cohort with the teacher's assignment, or a 404 when the teacher does not teach it. */
    public function cohort(int $teacherId, int $cohortId): object
    {
        $customerId = $this->tenant();
        $today = Carbon::today()->toDateString();
        $cohort = DB::table('core_course_cohorts as cohorts')
            ->join('core_course_products as products', function ($join): void {
                $join->on('products.id', '=', 'cohorts.product_id')->on('products.customer_id', '=', 'cohorts.customer_id');
            })
            ->where('cohorts.customer_id', $customerId)->where('cohorts.id', $cohortId)
            ->first(['cohorts.id', 'cohorts.name', 'cohorts.status', 'cohorts.start_date', 'cohorts.end_date', 'cohorts.version_id', 'products.title as product_title']);
        $assignment = $cohort ? $this->assignment($teacherId, $cohortId, $today) : null;
        abort_if($cohort === null || $assignment === null, 404);

        $cohort->assignment = $assignment;
        $cohort->bases = $this->competencies->bases($customerId, (int) $cohort->version_id);
        $cohort->blocked_reason = $this->blockedReason($cohort, $assignment, $today);

        return $cohort;
    }

    /** @return Collection<int, object> active memberships with the student's name */
    public function students(int $cohortId): Collection
    {
        return DB::table('core_course_cohort_students as members')
            ->join('users', function ($join): void {
                $join->on('users.id', '=', 'members.student_id')->on('users.customer_id', '=', 'members.customer_id');
            })
            ->where('members.customer_id', $this->tenant())->where('members.cohort_id', $cohortId)
            ->where('members.status', 'active')->whereNull('members.left_at')
            ->orderBy('users.name')
            ->get(['members.id', 'members.enrollment_id', 'members.student_id', 'members.joined_at', 'users.name']);
    }

    /**
     * The latest judgment per student membership and competency for the basis,
     * newest observation first; a correction's predecessor is skipped.
     *
     * @return Collection<string, object> keyed "membershipId:nodeId"
     */
    public function latestJudgments(int $cohortId, int $basisId): Collection
    {
        $customerId = $this->tenant();
        $superseded = DB::table('core_liveclass_teacher_judgments')->where('customer_id', $customerId)
            ->whereNotNull('supersedes_judgment_id')->pluck('supersedes_judgment_id')->all();

        return DB::table('core_liveclass_teacher_judgments as judgments')
            ->join('users', function ($join): void {
                $join->on('users.id', '=', 'judgments.teacher_id')->on('users.customer_id', '=', 'judgments.customer_id');
            })
            ->where('judgments.customer_id', $customerId)->where('judgments.cohort_id', $cohortId)
            ->where('judgments.basis_framework_version_id', $basisId)
            ->when($superseded !== [], fn ($query) => $query->whereNotIn('judgments.id', $superseded))
            ->orderByDesc('judgments.occurred_at')->orderByDesc('judgments.id')
            ->get(['judgments.cohort_student_membership_id', 'judgments.learning_node_id', 'judgments.mastery_level_key',
                'judgments.mastery_score', 'judgments.occurred_at', 'users.name as teacher_name'])
            ->unique(fn (object $row): string => $row->cohort_student_membership_id.':'.$row->learning_node_id)
            ->keyBy(fn (object $row): string => $row->cohort_student_membership_id.':'.$row->learning_node_id);
    }

    /** The teacher's active assignment, preferring one whose range covers the given day. */
    private function assignment(int $teacherId, int $cohortId, string $day): ?object
    {
        $assignments = DB::table('core_course_cohort_teachers')->where('customer_id', $this->tenant())
            ->where('cohort_id', $cohortId)->where('teacher_id', $teacherId)->where('status', 'active')
            ->orderBy('id')->get();

        return $assignments->first(fn (object $a): bool => ($a->assigned_from === null || $a->assigned_from <= $day)
            && ($a->assigned_to === null || $a->assigned_to >= $day)) ?? $assignments->first();
    }

    /** Null when judging is possible today; otherwise a translation-key suffix explaining why not. */
    private function blockedReason(object $cohort, ?object $assignment, string $today): ?string
    {
        // "No competencies" comes before the date reasons: otherwise an ended class without any
        // would promise "earlier results stay visible" when there is nothing to see.
        return match (true) {
            $cohort->status !== 'active' => 'cohort_not_active',
            $cohort->bases->isEmpty() => 'no_competencies',
            $cohort->start_date === null || $cohort->end_date === null || $today < $cohort->start_date => 'cohort_not_started',
            $today > $cohort->end_date => 'cohort_ended',
            $assignment === null
                || ($assignment->assigned_from !== null && $assignment->assigned_from > $today)
                || ($assignment->assigned_to !== null && $assignment->assigned_to < $today) => 'assignment_out_of_range',
            default => null,
        };
    }

    private function tenant(): int
    {
        return TenantContext::customerId() ?? abort(404);
    }
}
