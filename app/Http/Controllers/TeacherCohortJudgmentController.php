<?php

namespace App\Http\Controllers;

use App\Services\LearningCourseCompetencyReadService;
use App\Services\TeacherCohortReadService;
use App\Services\TeacherJudgmentService;
use App\Support\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Teacher Judgment surface (External Surface authorised 2026-08-17; course scope
 * amended 2026-10-11). The form carries only what the teacher chooses; tenant,
 * teacher, assignment, enrollment and student come from the session and the
 * teacher's own cohort. TeacherJudgmentService remains the decision.
 */
final class TeacherCohortJudgmentController extends Controller
{
    public function __construct(
        private readonly TeacherCohortReadService $cohorts,
        private readonly LearningCourseCompetencyReadService $competencies,
        private readonly TeacherJudgmentService $judgments,
    ) {}

    public function index(Request $request): View
    {
        return view('teacher-cohorts.index', ['cohorts' => $this->cohorts->cohorts((int) $request->user()->id)]);
    }

    public function show(Request $request, int $cohort): View
    {
        $cohortRow = $this->cohorts->cohort((int) $request->user()->id, $cohort);
        $basis = $cohortRow->bases->firstWhere('id', (int) $request->query('basis')) ?? $cohortRow->bases->first();
        $competencies = $basis ? $this->competencies->competencies($this->tenant(), (int) $cohortRow->version_id, (int) $basis->id) : collect();
        $node = $competencies->firstWhere('id', (int) $request->query('node')) ?? $competencies->first();
        $students = $this->cohorts->students($cohort);
        $latest = $basis ? $this->cohorts->latestJudgments($cohort, (int) $basis->id) : collect();
        // Count only students still in the class, so "x/y judged" never exceeds the class size.
        $activeIds = $students->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return view('teacher-cohorts.show', [
            'cohort' => $cohortRow,
            'basis' => $basis,
            'competencies' => $competencies,
            'node' => $node,
            'students' => $students,
            'latest' => $latest,
            'judgedCounts' => $latest->filter(fn (object $row): bool => in_array((int) $row->cohort_student_membership_id, $activeIds, true))
                ->groupBy('learning_node_id')->map->count(),
        ]);
    }

    public function store(Request $request, int $cohort): RedirectResponse
    {
        $teacherId = (int) $request->user()->id;
        $cohortRow = $this->cohorts->cohort($teacherId, $cohort);
        $data = $request->validate([
            'submission_uuid' => ['required', 'uuid'],
            'membership_id' => ['required', 'integer', 'min:1'],
            'basis_id' => ['required', 'integer', 'min:1'],
            'node_id' => ['required', 'integer', 'min:1'],
            'level' => ['required', 'string', 'max:100'],
            'score_percent' => ['nullable', 'numeric', 'between:0,100'],
            'reason' => ['required', 'string', 'max:5000'],
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ], [], [
            'reason' => __('lf.LF_teacher_judgment_reason'),
            'occurred_at' => __('lf.LF_teacher_judgment_occurred_at'),
            'score_percent' => __('lf.LF_teacher_judgment_score'),
        ]);
        $back = redirect()->route('teacher.cohorts.show', ['cohort' => $cohort, 'basis' => $data['basis_id'], 'node' => $data['node_id']]);

        $member = $this->cohorts->students($cohort)->firstWhere('id', (int) $data['membership_id']);
        if ($member === null) {
            return $back->withInput()->withErrors(['membership_id' => __('lf.LF_teacher_judgment_error_student')]);
        }

        try {
            $this->judgments->submit($teacherId, [
                'submission_uuid' => $data['submission_uuid'],
                'cohort_id' => $cohort,
                'cohort_teacher_assignment_id' => (int) $cohortRow->assignment->id,
                'cohort_student_membership_id' => (int) $member->id,
                'enrollment_id' => (int) $member->enrollment_id,
                'student_id' => (int) $member->student_id,
                'basis_framework_version_id' => (int) $data['basis_id'],
                'learning_node_id' => (int) $data['node_id'],
                'mastery_level_key' => $data['level'],
                'mastery_score' => $data['score_percent'] === null ? null : round((float) $data['score_percent'] / 100, 6),
                'reason' => $data['reason'],
                // The teacher picks a wall-clock time in the application timezone; the service needs an explicit offset.
                'occurred_at' => Carbon::createFromFormat('Y-m-d\TH:i', $data['occurred_at'], config('app.timezone'))->format('Y-m-d\TH:i:sP'),
            ]);
        } catch (AuthorizationException|DomainException|RecordsNotFoundException $exception) {
            return $back->withInput()->withErrors(['judgment' => $this->message($exception->getMessage())]);
        }

        return $back->with('success', __('lf.LF_teacher_judgment_saved', ['name' => $member->name]));
    }

    private function message(string $code): string
    {
        $suffix = Str::after($code, 'LF_TEACHER_JUDGMENT_');
        $key = 'lf.LF_teacher_judgment_error_'.strtolower($suffix);

        return Lang::has($key) ? __($key) : __('lf.LF_teacher_judgment_error_generic', ['code' => $code]);
    }

    private function tenant(): int
    {
        return TenantContext::customerId() ?? abort(404);
    }
}
