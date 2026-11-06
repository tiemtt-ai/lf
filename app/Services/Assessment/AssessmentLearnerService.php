<?php

namespace App\Services\Assessment;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The learner side of Kiểm tra on the tenant website (ADR-0025 D2, D7, D9, D12): the public list of quizzes open for
 * registration, free self-registration, "Đề thi của tôi", and what a student may see of their own attempts. Public data
 * comes from the published version only (never questions or answers); every attempt read is bound to its student.
 */
final class AssessmentLearnerService
{
    public function __construct(private readonly AssessmentAttemptService $attempts) {}

    /** Quizzes open for registration, introduced by their current version. */
    public function catalog(int $customerId, string $keyword = '', ?int $categoryId = null): Builder
    {
        return DB::table(AssessmentQuizService::TABLE.' as z')
            ->join(AssessmentQuizService::VERSIONS_TABLE.' as v', function ($join): void {
                $join->on('v.id', '=', 'z.current_version_id')->on('v.customer_id', '=', 'z.customer_id');
            })
            ->leftJoin(AssessmentCategoryService::TABLE.' as c', function ($join): void {
                $join->on('c.id', '=', 'z.category_id')->on('c.customer_id', '=', 'z.customer_id');
            })
            ->where('z.customer_id', $customerId)
            ->where('z.status', 'published')
            ->where('z.registration_open', true)
            ->when($keyword !== '', fn ($query) => $query->where('v.title', 'like', '%'.$keyword.'%'))
            ->when($categoryId, fn ($query, $id) => $query->where('z.category_id', $id))
            ->select('z.id', 'v.title', 'v.description', 'v.assessment_type', 'v.duration_minutes', 'v.max_attempts',
                'v.question_count', 'v.total_points', 'v.pass_score_percentage', 'c.name as category_name')
            ->orderBy('v.title');
    }

    /** Categories that have at least one open quiz, for the catalog filter. */
    public function catalogCategories(int $customerId): Collection
    {
        return DB::table(AssessmentCategoryService::TABLE.' as c')
            ->where('c.customer_id', $customerId)
            ->whereExists(fn ($query) => $query->from(AssessmentQuizService::TABLE.' as z')
                ->whereColumn('z.category_id', 'c.id')->whereColumn('z.customer_id', 'c.customer_id')
                ->where('z.status', 'published')->where('z.registration_open', true))
            ->orderBy('c.name')
            ->get(['c.id', 'c.name']);
    }

    /** @return Collection<int, object> the student's active registrations keyed by quiz id */
    public function activeRegistrations(int $customerId, int $studentId): Collection
    {
        return DB::table(AssessmentRegistrationService::TABLE)
            ->where('customer_id', $customerId)->where('student_id', $studentId)->where('status', 'active')
            ->get(['id', 'quiz_id'])->keyBy('quiz_id');
    }

    /**
     * Free self-registration to a quiz that is published and open (D7). Returns the registration; an existing active
     * one is returned as is, so registering twice is harmless.
     */
    public function register(int $customerId, User $student, int $quizId): int
    {
        abort_unless($student->role === 'student' && (int) $student->customer_id === $customerId, 403);

        return DB::transaction(function () use ($customerId, $student, $quizId): int {
            $quiz = DB::table(AssessmentQuizService::TABLE)->where('customer_id', $customerId)->where('id', $quizId)->lockForUpdate()->first();
            abort_if(! $quiz, 404);
            if ($quiz->status !== 'published' || ! $quiz->registration_open || $quiz->current_version_id === null) {
                throw ValidationException::withMessages(['quiz' => __('lf.LF_assessment_learner_error_closed')]);
            }

            $active = DB::table(AssessmentRegistrationService::TABLE)->where('customer_id', $customerId)
                ->where('quiz_id', $quizId)->where('student_id', $student->id)->where('status', 'active')->value('id');
            if ($active) {
                return (int) $active;
            }

            $now = now();

            return DB::table(AssessmentRegistrationService::TABLE)->insertGetId([
                'customer_id' => $customerId,
                'quiz_id' => $quizId,
                'student_id' => $student->id,
                'source' => 'self_registered',
                'status' => 'active',
                'attempts_used' => 0,
                'registered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * "Đề thi của tôi": every registration of the student with its quiz (as currently published) and its attempts,
     * active ones first.
     *
     * @return Collection<int, object>
     */
    public function myRegistrations(int $customerId, int $studentId): Collection
    {
        $registrations = DB::table(AssessmentRegistrationService::TABLE.' as r')
            ->join(AssessmentQuizService::TABLE.' as z', function ($join): void {
                $join->on('z.id', '=', 'r.quiz_id')->on('z.customer_id', '=', 'r.customer_id');
            })
            ->leftJoin(AssessmentQuizService::VERSIONS_TABLE.' as v', function ($join): void {
                $join->on('v.id', '=', 'z.current_version_id')->on('v.customer_id', '=', 'z.customer_id');
            })
            ->where('r.customer_id', $customerId)
            ->where('r.student_id', $studentId)
            ->select('r.*', 'z.status as quiz_status', 'z.registration_open', 'v.title', 'v.assessment_type',
                'v.duration_minutes', 'v.max_attempts', 'v.question_count', 'v.total_points')
            ->orderByRaw("CASE r.status WHEN 'active' THEN 0 WHEN 'exhausted' THEN 1 ELSE 2 END")
            ->orderByDesc('r.registered_at')
            ->orderByDesc('r.id')
            ->get();

        $attempts = DB::table(AssessmentAttemptService::TABLE.' as a')
            ->join(AssessmentQuizService::VERSIONS_TABLE.' as v', 'v.id', '=', 'a.quiz_version_id')
            ->where('a.customer_id', $customerId)->where('a.user_id', $studentId)
            ->whereIn('a.quiz_registration_id', $registrations->pluck('id'))->orderBy('a.attempt_no')
            ->get(['a.*', 'v.show_result_mode'])
            ->each(fn (object $attempt) => $attempt->visible = $this->visibility($attempt, $attempt))
            ->groupBy('quiz_registration_id');

        return $registrations->each(function (object $registration) use ($attempts): void {
            $registration->attempts = $attempts->get($registration->id, collect())->values();
        });
    }

    public function registration(int $customerId, int $studentId, int $registrationId): object
    {
        $registration = DB::table(AssessmentRegistrationService::TABLE)->where('customer_id', $customerId)
            ->where('student_id', $studentId)->where('id', $registrationId)->first();
        abort_if(! $registration, 404);

        return $registration;
    }

    /** One of the student's own attempts. An open attempt past its deadline is ended (time out) before it is returned. */
    public function attempt(int $customerId, int $studentId, int $attemptId): object
    {
        $attempt = DB::table(AssessmentAttemptService::TABLE)->where('customer_id', $customerId)
            ->where('user_id', $studentId)->where('id', $attemptId)->first();
        abort_if(! $attempt, 404);

        return $this->isOverdue($attempt) ? $this->timeOut($customerId, $attempt) : $attempt;
    }

    /** Ends every open attempt of the student whose time is up, so lists never show a stale "in progress". */
    public function closeOverdue(int $customerId, int $studentId): void
    {
        DB::table(AssessmentAttemptService::TABLE)->where('customer_id', $customerId)->where('user_id', $studentId)
            ->where('status', 'in_progress')->whereNotNull('deadline_at')->get()
            ->filter(fn (object $attempt) => $this->isOverdue($attempt))
            ->each(fn (object $attempt) => $this->timeOut($customerId, $attempt));
    }

    /** Closes an overdue attempt; a concurrent request that closed it first is not an error. */
    private function timeOut(int $customerId, object $attempt): object
    {
        try {
            return $this->attempts->submit($customerId, (int) $attempt->id, 'timeout');
        } catch (ValidationException) {
            return DB::table(AssessmentAttemptService::TABLE)->where('id', $attempt->id)->first();
        }
    }

    /** Past the deadline and its grace: saving is refused, so the attempt can only be closed. */
    public function isOverdue(object $attempt): bool
    {
        return $attempt->status === 'in_progress' && $attempt->deadline_at !== null
            && now()->getTimestamp() > strtotime((string) $attempt->deadline_at) + (int) config('assessment.deadline_grace_seconds', 30);
    }

    /**
     * What the student may see of a finished attempt (D12): `score` (their total), `answers` (correct answers,
     * explanations and per-question scores) and `feedback` (teacher comments).
     *
     * @return array{score: bool, answers: bool, feedback: bool}
     */
    public function visibility(object $attempt, object $version): array
    {
        $graded = $attempt->status === 'graded';

        return match ($version->show_result_mode) {
            'immediately' => ['score' => true, 'answers' => true, 'feedback' => true],
            'after_grading' => ['score' => $graded, 'answers' => $graded, 'feedback' => $graded],
            'score_only' => ['score' => $graded, 'answers' => false, 'feedback' => $graded],
            default => ['score' => false, 'answers' => false, 'feedback' => false],
        };
    }
}
