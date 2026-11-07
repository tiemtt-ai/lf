<?php

namespace App\Http\Controllers\Assessment;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Assessment\AssessmentAttemptService as Attempts;
use App\Services\Assessment\AssessmentGraderService as Graders;
use App\Services\Assessment\AssessmentGradingService as Grading;
use App\Services\Assessment\AssessmentQuizPublishService;
use App\Services\Assessment\AssessmentQuizService as Quizzes;
use App\Services\Assessment\AssessmentStatisticsService;
use App\Services\MediaService;
use App\Support\TenantContext;
use App\Support\XlsxDownload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kết quả & Chấm bài of the Kiểm tra module: the attempts list (with the "Chờ chấm" view), one attempt read from its
 * frozen version, grading of essay / speaking answers, and who holds an attempt (Giao chấm / Nhận chấm / Trả lại,
 * ADR-0027). Which attempts each user reaches is AssessmentGradingService::scoped.
 */
final class AssessmentResultController extends Controller
{
    public function __construct(
        private readonly Grading $grading,
        private readonly Attempts $attempts,
        private readonly MediaService $media,
        private readonly AssessmentStatisticsService $statistics,
        private readonly Graders $graders,
    ) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $user = $request->user();
        $filters = $this->listFilters($request);

        $attempts = $this->listQuery($customerId, $user, $filters)
            ->paginate(10)
            ->withQueryString();

        return view('assessments.results.index', [
            'attempts' => $attempts,
            'filters' => $filters,
            'pendingCount' => $this->pendingCount($customerId, $user),
            // Quizzes the user can reach attempts of (their own, and those they grade), for the quiz filter.
            'quizzes' => $this->grading->scoped($customerId, $user)->whereNotNull('z.current_version_id')
                ->groupBy('z.id', 'z.title')->orderBy('z.title')->get(['z.id', 'z.title']),
            'teachers' => $user->role === 'customer_admin' ? $this->graders->teachers($customerId)->orderBy('name')->get(['id', 'name']) : collect(),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    /** The list as .xlsx: the tab and filters on screen, every page, only the attempts this user may see. */
    public function export(Request $request): Response
    {
        $this->routePrefix($request);
        $attempts = $this->listQuery($this->customerId(), $request->user(), $this->listFilters($request))
            ->limit(XlsxDownload::MAX_ROWS)
            ->get();

        return XlsxDownload::response(
            [
                __('lf.table_no'), __('lf.LF_assessment_registration_student'), __('lf.LF_common_label_email'),
                __('lf.LF_assessment_registration_quiz'), __('lf.LF_export_col_version'), __('lf.LF_export_col_attempt_no'),
                __('lf.LF_assessment_result_started_at'), __('lf.LF_assessment_result_submitted_at'),
                __('lf.LF_assessment_result_score'), __('lf.LF_export_col_max_score'), __('lf.LF_export_col_score_percentage'),
                __('lf.LF_export_col_result'), __('lf.LF_assessment_category_status'),
            ],
            $attempts->values()->map(fn (object $attempt, int $index) => [
                $index + 1, $attempt->student_name, $attempt->student_email, $attempt->quiz_title, $attempt->version_no,
                $attempt->attempt_no, XlsxDownload::dateTime($attempt->started_at), XlsxDownload::dateTime($attempt->submitted_at),
                XlsxDownload::number($attempt->score), XlsxDownload::number($attempt->max_score),
                $attempt->score_percentage === null ? '' : XlsxDownload::number($attempt->score_percentage).'%',
                $attempt->passed === null ? '' : __($attempt->passed ? 'lf.LF_assessment_result_passed' : 'lf.LF_assessment_result_failed'),
                $attempt->status === 'in_progress'
                    ? __('lf.LF_assessment_result_status_in_progress')
                    : __('lf.LF_assessment_result_grading_'.$attempt->grading_status),
            ]),
            __('lf.LF_export_file_results'),
            __('lf.LF_navigation_menu_assessment_results'),
        );
    }

    /**
     * `grader`: all, mine, unassigned, mine_unassigned, or a teacher id (admins). A teacher opening Chờ chấm starts on
     * "mine + unassigned" (ADR-0027 D4); `all` shows everything they may see.
     *
     * @return array{keyword: string, quiz_id: ?int, status: ?string, tab: string, grader: string}
     */
    private function listFilters(Request $request): array
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'quiz_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'tab' => ['nullable', 'string'],
            'grader' => ['nullable', 'string', 'max:20'],
        ]);
        $tab = ($validated['tab'] ?? null) === 'pending' ? 'pending' : 'all';
        $isAdmin = $request->user()->role === 'customer_admin';
        $grader = (string) ($validated['grader'] ?? '');
        $allowed = $isAdmin ? ['all', 'unassigned'] : ['all', 'mine', 'unassigned', 'mine_unassigned'];
        if (! in_array($grader, $allowed, true) && ! ($isAdmin && ctype_digit($grader))) {
            $grader = ! $isAdmin && $tab === 'pending' ? 'mine_unassigned' : 'all';
        }

        return [
            'keyword' => trim($validated['keyword'] ?? ''),
            'quiz_id' => isset($validated['quiz_id']) ? (int) $validated['quiz_id'] : null,
            'status' => in_array($validated['status'] ?? null, ['in_progress', 'submitted', 'graded'], true) ? $validated['status'] : null,
            'tab' => $tab,
            'grader' => $grader,
        ];
    }

    /** Chờ chấm count on the tab: for a teacher, what is theirs to grade (held by them or by nobody). */
    private function pendingCount(int $customerId, User $user): int
    {
        return $this->withHolder($this->grading->scoped($customerId, $user)->where('a.grading_status', 'pending'))
            ->when($user->role !== 'customer_admin', fn ($query) => $this->graderFilter($query, $user, 'mine_unassigned'))
            ->count();
    }

    /** Joins `ga` / `gu`: the teacher who holds the attempt, or the last one who held it to completion. */
    private function withHolder($query)
    {
        return $query
            ->leftJoin(Graders::ASSIGNMENTS_TABLE.' as ga', fn ($join) => $join->on('ga.attempt_id', '=', 'a.id')
                ->on('ga.customer_id', '=', 'a.customer_id')
                ->whereRaw('ga.id = (select max(gx.id) from '.Graders::ASSIGNMENTS_TABLE." gx where gx.attempt_id = a.id and gx.status in ('active', 'completed'))"))
            ->leftJoin('users as gu', 'gu.id', '=', 'ga.assigned_to_user_id');
    }

    private function graderFilter($query, User $user, string $grader)
    {
        return match (true) {
            $grader === 'mine' => $query->where('ga.assigned_to_user_id', $user->id),
            $grader === 'unassigned' => $query->whereNull('ga.id'),
            $grader === 'mine_unassigned' => $query->where(fn ($query) => $query->whereNull('ga.id')->orWhere('ga.assigned_to_user_id', $user->id)),
            ctype_digit($grader) => $query->where('ga.assigned_to_user_id', (int) $grader),
            default => $query,
        };
    }

    /** @param  array{keyword: string, quiz_id: ?int, status: ?string, tab: string, grader: string}  $filters */
    private function listQuery(int $customerId, User $user, array $filters)
    {
        return $this->withHolder($this->grading->scoped($customerId, $user))
            ->leftJoin(Quizzes::VERSIONS_TABLE.' as v', 'v.id', '=', 'a.quiz_version_id')
            ->when($filters['tab'] === 'pending', fn ($query) => $query->where('a.grading_status', 'pending'))
            ->tap(fn ($query) => $this->graderFilter($query, $user, $filters['grader']))
            ->when($filters['keyword'] !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('s.name', 'like', '%'.$filters['keyword'].'%')
                ->orWhere('s.email', 'like', '%'.$filters['keyword'].'%')))
            ->when($filters['quiz_id'], fn ($query, $id) => $query->where('a.quiz_id', $id))
            ->when($filters['status'], fn ($query, $status) => $query->where('a.status', $status))
            ->select('a.*', 'z.title as quiz_title', 'z.created_by as quiz_created_by', 's.name as student_name', 's.email as student_email',
                'v.version_no', 'v.pass_score_percentage', 'ga.assigned_to_user_id as holder_id', 'ga.status as holder_status', 'gu.name as holder_name')
            ->orderByRaw("CASE WHEN a.grading_status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('a.submitted_at')
            ->orderByDesc('a.id');
    }

    /** Thống kê of one quiz: totals and per-question correct rates of the attempts this user may see. */
    public function statistics(Request $request): View
    {
        $customerId = $this->customerId();
        $user = $request->user();
        $validated = $request->validate(['quiz_id' => ['nullable', 'integer']]);

        // Quizzes that have at least one submitted attempt this user may see; the newest submission comes first.
        $quizzes = $this->grading->scoped($customerId, $user)->whereIn('a.status', ['submitted', 'graded'])
            ->groupBy('z.id', 'z.title')->orderByRaw('max(a.submitted_at) desc')->get(['z.id', 'z.title']);
        $quizId = isset($validated['quiz_id']) && $quizzes->contains('id', (int) $validated['quiz_id'])
            ? (int) $validated['quiz_id']
            : (int) ($quizzes->first()->id ?? 0);

        return view('assessments.results.statistics', [
            'quizzes' => $quizzes,
            'quizId' => $quizId,
            'stats' => $quizId > 0 ? $this->statistics->forQuiz($customerId, $user, $quizId) : null,
            'pendingCount' => $this->pendingCount($customerId, $user),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function show(Request $request, int $id): View
    {
        $customerId = $this->customerId();
        $user = $request->user();
        $attempt = $this->grading->find($customerId, $user, $id);
        [$canGrade, $holder] = $this->grading->gradeRight($customerId, $user, $attempt);
        $version = DB::table(Quizzes::VERSIONS_TABLE)->where('customer_id', $customerId)->where('id', $attempt->quiz_version_id)->first();
        $answers = DB::table(Attempts::ANSWERS_TABLE)->where('customer_id', $customerId)->where('attempt_id', $id)
            ->get()->keyBy('quiz_version_question_id');
        $history = DB::table(Attempts::GRADINGS_TABLE.' as g')->leftJoin('users as u', 'u.id', '=', 'g.grader_user_id')
            ->where('g.customer_id', $customerId)->where('g.attempt_id', $id)->orderByDesc('g.id')
            ->get(['g.answer_id', 'g.score', 'g.feedback', 'g.rubric_result', 'g.is_current', 'g.graded_at', 'g.grading_type', 'u.name as grader_name'])->groupBy('answer_id');
        // Speaking recordings that are ready to play.
        $files = DB::table(Attempts::FILES_TABLE.' as f')->join('media_files as m', 'm.id', '=', 'f.media_file_id')
            ->where('f.customer_id', $customerId)->whereIn('f.answer_id', $answers->pluck('id'))
            ->where('f.file_role', 'speaking_recording')->where('m.status', 'ready')
            ->get(['f.answer_id', 'f.media_file_id'])->keyBy('answer_id');
        // Essay files (ADR-0027 D5): read under the right to view the attempt, through short-lived signed URLs.
        $attachments = $this->attempts->attachments($customerId, $answers->pluck('id'))
            ->map(fn ($files) => $files->map(fn (object $file) => [
                'id' => (int) $file->media_file_id,
                'name' => (string) ($file->display_name ?: $file->original_name),
                'kind' => Attempts::attachmentKind($file),
                'url' => $file->status === 'ready' ? $this->media->generateSignedUrl((int) $file->media_file_id) : null,
            ])->values());

        return view('assessments.results.show', [
            'attempt' => $attempt,
            'version' => $version,
            'sections' => collect(json_decode((string) $version->sections_snapshot, true) ?: [])->keyBy('key'),
            'passages' => collect(json_decode((string) $version->passages_snapshot, true) ?: []),
            'questions' => $this->attempts->versionQuestions($customerId, (int) $version->id),
            'answers' => $answers,
            'history' => $history,
            'mediaUrls' => $this->mediaUrls($version),
            'fileUrls' => $files->map(fn (object $file) => $this->media->generateSignedUrl((int) $file->media_file_id)),
            'attachments' => $attachments,
            'canGrade' => $canGrade,
            'holder' => $this->graders->holders($customerId, [$id])->get($id),
            'activeHolder' => $holder,
            'canClaim' => $user->role === 'teacher' && ! $holder && $attempt->grading_status === 'pending' && $attempt->status !== 'in_progress',
            'canRelease' => $holder && ((int) $holder->user_id === (int) $user->id
                || $this->graders->managesQuiz($user, (object) ['created_by' => $attempt->quiz_created_by])),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function claim(Request $request, int $id): RedirectResponse
    {
        $customerId = $this->customerId();
        $this->grading->find($customerId, $request->user(), $id);
        $this->graders->claim($customerId, $request->user(), $id);

        return back()->with('success', __('lf.LF_assessment_grader_claimed'));
    }

    public function release(Request $request, int $id): RedirectResponse
    {
        $customerId = $this->customerId();
        $this->grading->find($customerId, $request->user(), $id);
        $this->graders->release($customerId, $request->user(), $id);

        return back()->with('success', __('lf.LF_assessment_grader_released'));
    }

    public function grade(Request $request, int $id, int $answerId): RedirectResponse
    {
        $validated = $request->validate([
            // A question with a rubric sends one score per criterion instead (the service decides which applies).
            'score' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'criteria' => ['nullable', 'array', 'max:50'],
            'criteria.*' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);
        $feedback = trim((string) ($validated['feedback'] ?? ''));
        $this->grading->grade(
            $this->customerId(), $request->user(), $id, $answerId,
            isset($validated['score']) ? (string) $validated['score'] : null,
            $feedback === '' ? null : $feedback,
            $validated['criteria'] ?? null,
        );

        return redirect()->to(route($this->routePrefix($request).'.show', $id).'#answer-'.$answerId)
            ->with('success', __('lf.LF_assessment_result_graded'));
    }

    /** Signed URLs of every media file the version snapshot shows (question and passage media), by media id. */
    private function mediaUrls(object $version): array
    {
        $ids = DB::table(AssessmentQuizPublishService::VERSION_QUESTIONS_TABLE)
            ->where('quiz_version_id', $version->id)->pluck('question_snapshot')
            ->flatMap(fn ($json) => collect((json_decode((string) $json, true) ?: [])['media'] ?? [])->pluck('media_file_id'))
            ->merge(collect(json_decode((string) $version->passages_snapshot, true) ?: [])->flatMap(fn ($p) => collect($p['media'] ?? [])->pluck('media_file_id')))
            ->unique();
        $ready = DB::table('media_files')->whereIn('id', $ids)->where('status', 'ready')->pluck('id');

        return $ready->mapWithKeys(fn ($id) => [(int) $id => $this->media->generateSignedUrl((int) $id)])->all();
    }

    private function routePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => 'admin.assessments.results',
            'teacher' => 'teacher.assessments.results',
            default => abort(403),
        };
    }

    private function customerId(): int
    {
        $customerId = TenantContext::customerId();
        abort_if(! $customerId, 404);

        return $customerId;
    }
}
