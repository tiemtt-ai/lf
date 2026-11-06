<?php

namespace App\Http\Controllers\Assessment;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Assessment\AssessmentAttemptService as Attempts;
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
 * frozen version, and grading of essay / speaking answers. Admins reach the tenant; teachers the attempts of their
 * quizzes or of registrations they granted.
 */
final class AssessmentResultController extends Controller
{
    public function __construct(
        private readonly Grading $grading,
        private readonly Attempts $attempts,
        private readonly Quizzes $quizzes,
        private readonly MediaService $media,
        private readonly AssessmentStatisticsService $statistics,
    ) {}

    public function index(Request $request): View
    {
        $customerId = $this->customerId();
        $user = $request->user();
        $filters = $this->listFilters($request);
        $base = fn () => $this->grading->scoped($customerId, $user);

        $attempts = $this->listQuery($customerId, $user, $filters)
            ->paginate(10)
            ->withQueryString();

        return view('assessments.results.index', [
            'attempts' => $attempts,
            'filters' => $filters,
            'pendingCount' => $base()->where('a.grading_status', 'pending')->count(),
            'quizzes' => $this->quizzes->scoped($customerId, $user)->whereNotNull('current_version_id')->orderBy('title')->get(['id', 'title']),
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

    /** @return array{keyword: string, quiz_id: ?int, status: ?string, tab: string} */
    private function listFilters(Request $request): array
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'quiz_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'tab' => ['nullable', 'string'],
        ]);

        return [
            'keyword' => trim($validated['keyword'] ?? ''),
            'quiz_id' => isset($validated['quiz_id']) ? (int) $validated['quiz_id'] : null,
            'status' => in_array($validated['status'] ?? null, ['in_progress', 'submitted', 'graded'], true) ? $validated['status'] : null,
            'tab' => ($validated['tab'] ?? null) === 'pending' ? 'pending' : 'all',
        ];
    }

    /** @param  array{keyword: string, quiz_id: ?int, status: ?string, tab: string}  $filters */
    private function listQuery(int $customerId, User $user, array $filters)
    {
        return $this->grading->scoped($customerId, $user)
            ->leftJoin(Quizzes::VERSIONS_TABLE.' as v', 'v.id', '=', 'a.quiz_version_id')
            ->when($filters['tab'] === 'pending', fn ($query) => $query->where('a.grading_status', 'pending'))
            ->when($filters['keyword'] !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('s.name', 'like', '%'.$filters['keyword'].'%')
                ->orWhere('s.email', 'like', '%'.$filters['keyword'].'%')))
            ->when($filters['quiz_id'], fn ($query, $id) => $query->where('a.quiz_id', $id))
            ->when($filters['status'], fn ($query, $status) => $query->where('a.status', $status))
            ->select('a.*', 'z.title as quiz_title', 's.name as student_name', 's.email as student_email',
                'v.version_no', 'v.pass_score_percentage')
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
            'pendingCount' => $this->grading->scoped($customerId, $user)->where('a.grading_status', 'pending')->count(),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function show(Request $request, int $id): View
    {
        $customerId = $this->customerId();
        $attempt = $this->grading->find($customerId, $request->user(), $id);
        $version = DB::table(Quizzes::VERSIONS_TABLE)->where('customer_id', $customerId)->where('id', $attempt->quiz_version_id)->first();
        $answers = DB::table(Attempts::ANSWERS_TABLE)->where('customer_id', $customerId)->where('attempt_id', $id)
            ->get()->keyBy('quiz_version_question_id');
        $history = DB::table(Attempts::GRADINGS_TABLE.' as g')->leftJoin('users as u', 'u.id', '=', 'g.grader_user_id')
            ->where('g.customer_id', $customerId)->where('g.attempt_id', $id)->orderByDesc('g.id')
            ->get(['g.answer_id', 'g.score', 'g.feedback', 'g.rubric_result', 'g.is_current', 'g.graded_at', 'u.name as grader_name'])->groupBy('answer_id');
        // Speaking recordings that are ready to play.
        $files = DB::table(Attempts::FILES_TABLE.' as f')->join('media_files as m', 'm.id', '=', 'f.media_file_id')
            ->where('f.customer_id', $customerId)->whereIn('f.answer_id', $answers->pluck('id'))->where('m.status', 'ready')
            ->get(['f.answer_id', 'f.media_file_id'])->keyBy('answer_id');

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
            'routePrefix' => $this->routePrefix($request),
        ]);
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
