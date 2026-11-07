<?php

namespace App\Services\Assessment;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kết quả & Chấm bài (ADR-0025 D11, ADR-0027 D1): who may read attempts and grade essay / speaking answers. A grade is a
 * new core_assessment_gradings row; the previous one stays as history (is_current = false). The answer carries the
 * current score and feedback, and the attempt totals are recalculated in the same transaction.
 *
 * Readers: customer_admin (whole tenant); a teacher for attempts of quizzes they created, of registrations they granted,
 * of a quiz they are named on (Amendment 1.2: no longer categories), or that they hold / held to completion.
 * Graders: the same people, except that while a teacher holds an attempt only that teacher, the admin and the quiz creator
 * grade it.
 */
final class AssessmentGradingService
{
    public function __construct(
        private readonly AssessmentAttemptService $attempts,
        private readonly AssessmentGraderService $graders,
    ) {}

    /** Attempts this user may see, joined to quiz `z`, registration `reg` and student `s`. */
    public function scoped(int $customerId, User $user, string $alias = 'a'): Builder
    {
        $coverage = $user->role === 'customer_admin' ? null : $this->graders->coverage($customerId, (int) $user->id);

        return DB::table(AssessmentAttemptService::TABLE.' as '.$alias)
            ->join(AssessmentQuizService::TABLE.' as z', function ($join) use ($alias): void {
                $join->on('z.id', '=', $alias.'.quiz_id')->on('z.customer_id', '=', $alias.'.customer_id');
            })
            ->leftJoin(AssessmentRegistrationService::TABLE.' as reg', function ($join) use ($alias): void {
                $join->on('reg.id', '=', $alias.'.quiz_registration_id')->on('reg.customer_id', '=', $alias.'.customer_id');
            })
            ->leftJoin('users as s', 's.id', '=', $alias.'.user_id')
            ->where($alias.'.customer_id', $customerId)
            ->when($coverage !== null, fn ($query) => $query->where(fn ($query) => $query
                ->where('z.created_by', $user->id)
                ->orWhere(fn ($query) => $query->where('reg.source', 'granted')->where('reg.granted_by', $user->id))
                ->when($coverage !== [], fn ($query) => $query->orWhereIn($alias.'.quiz_id', $coverage))
                ->orWhereExists(fn ($query) => $query->selectRaw('1')->from(AssessmentGraderService::ASSIGNMENTS_TABLE.' as ga_scope')
                    ->whereColumn('ga_scope.attempt_id', $alias.'.id')->whereColumn('ga_scope.customer_id', $alias.'.customer_id')
                    ->where('ga_scope.assigned_to_user_id', $user->id)->whereIn('ga_scope.status', ['active', 'completed']))));
    }

    /**
     * Whether this user may grade the attempt now (they can already see it), and who holds it.
     *
     * @return array{0: bool, 1: ?object}
     */
    public function gradeRight(int $customerId, User $user, object $attempt): array
    {
        $holder = $this->graders->activeHolder($customerId, (int) $attempt->id);
        $always = $user->role === 'customer_admin' || (int) $attempt->quiz_created_by === (int) $user->id;

        return [$always || ! $holder || (int) $holder->user_id === (int) $user->id, $holder];
    }

    public function find(int $customerId, User $user, int $attemptId, bool $lock = false): object
    {
        $attempt = $this->scoped($customerId, $user)->where('a.id', $attemptId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first(['a.*', 'z.title as quiz_title', 'z.created_by as quiz_created_by', 'z.category_id as quiz_category_id',
                's.name as student_name', 's.email as student_email']);
        abort_if(! $attempt, 404);

        return $attempt;
    }

    /**
     * Grades one essay / speaking answer of a submitted attempt. A question with a frozen rubric takes one score per
     * criterion and its question score is worked out from them (ADR-0026 D5); `$score` is then ignored.
     *
     * @param  array<int|string, mixed>|null  $criteria  criterion key => score
     */
    public function grade(int $customerId, User $user, int $attemptId, int $answerId, ?string $score, ?string $feedback, ?array $criteria = null): void
    {
        DB::transaction(function () use ($customerId, $user, $attemptId, $answerId, $score, $feedback, $criteria): void {
            $attempt = $this->find($customerId, $user, $attemptId, true);
            if ($attempt->status === 'in_progress') {
                throw ValidationException::withMessages(['score' => __('lf.LF_assessment_result_error_in_progress')]);
            }
            [$may, $holder] = $this->gradeRight($customerId, $user, $attempt);
            if (! $may) {
                throw ValidationException::withMessages(['score' => __('lf.LF_assessment_grader_error_held', ['name' => $holder->name])]);
            }
            // ADR-0027 Amendment 1.1: a teacher grading a pending attempt nobody holds takes it, so no one else grades it
            // at the same time. The attempt row is locked above, so a second teacher waits here and then finds a holder.
            if (! $holder && $user->role === 'teacher' && (int) $attempt->quiz_created_by !== (int) $user->id
                && $attempt->grading_status === 'pending') {
                $this->graders->takeOnGrading($customerId, (int) $attemptId, (int) $user->id);
            }

            $answer = DB::table(AssessmentAttemptService::ANSWERS_TABLE)->where('customer_id', $customerId)
                ->where('attempt_id', $attemptId)->where('id', $answerId)->lockForUpdate()->first();
            abort_if(! $answer, 404);
            $question = DB::table(AssessmentQuizPublishService::VERSION_QUESTIONS_TABLE)->where('id', $answer->quiz_version_question_id)->first();
            if (! $question->requires_manual_grading) {
                throw ValidationException::withMessages(['score' => __('lf.LF_assessment_result_error_auto')]);
            }
            $rubric = json_decode((string) $question->rubric_snapshot, true);
            $rubricResult = null;
            if (is_array($rubric)) {
                ['score' => $value, 'result' => $rubricResult] = AssessmentRubricService::score($rubric, $criteria ?? [], (float) $answer->max_score);
                $score = number_format($value, 2, '.', '');
            } elseif ($score === null || $score === '') {
                throw ValidationException::withMessages(['score' => __('validation.required', ['attribute' => __('lf.LF_assessment_result_score')])]);
            }
            if ((float) $score > (float) $answer->max_score) {
                throw ValidationException::withMessages(['score' => __('lf.LF_assessment_result_error_max', [
                    'max' => rtrim(rtrim(number_format((float) $answer->max_score, 2, '.', ''), '0'), '.'),
                ])]);
            }

            $now = now();
            DB::table(AssessmentAttemptService::GRADINGS_TABLE)->where('customer_id', $customerId)->where('answer_id', $answerId)
                ->where('is_current', true)->update(['is_current' => false, 'updated_at' => $now]);
            DB::table(AssessmentAttemptService::GRADINGS_TABLE)->insert([
                'customer_id' => $customerId,
                'attempt_id' => $attemptId,
                'answer_id' => $answerId,
                'grader_user_id' => $user->id,
                'grading_type' => 'teacher',
                'score' => $score,
                'feedback' => $feedback,
                'rubric_result' => $rubricResult === null ? null : json_encode($rubricResult),
                'is_current' => true,
                'graded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table(AssessmentAttemptService::ANSWERS_TABLE)->where('id', $answerId)->update([
                'score' => $score, 'feedback' => $feedback, 'grading_status' => 'graded', 'updated_at' => $now,
            ]);

            $stillPending = DB::table(AssessmentAttemptService::ANSWERS_TABLE)->where('customer_id', $customerId)
                ->where('attempt_id', $attemptId)->where('grading_status', 'pending')->exists();
            if (! $stillPending && $attempt->status !== 'graded') {
                DB::table(AssessmentAttemptService::TABLE)->where('id', $attemptId)->update([
                    'status' => 'graded', 'grading_status' => 'completed', 'graded_at' => $now, 'updated_at' => $now,
                ]);
                $this->graders->complete($customerId, $attemptId);
            }
            $this->attempts->recalculate($customerId, $attemptId);
        });
    }
}
