<?php

namespace App\Services\Assessment;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kết quả & Chấm bài (ADR-0025 D11): who may read attempts and grade essay / speaking answers. A grade is a new
 * core_assessment_gradings row; the previous one stays as history (is_current = false). The answer carries the
 * current score and feedback, and the attempt totals are recalculated in the same transaction.
 *
 * Readers and graders: customer_admin (whole tenant); a teacher for attempts of quizzes they created or of
 * registrations they granted.
 */
final class AssessmentGradingService
{
    public function __construct(private readonly AssessmentAttemptService $attempts) {}

    /** Attempts this user may see, joined to quiz `z`, registration `reg` and student `s`. */
    public function scoped(int $customerId, User $user, string $alias = 'a'): Builder
    {
        return DB::table(AssessmentAttemptService::TABLE.' as '.$alias)
            ->join(AssessmentQuizService::TABLE.' as z', function ($join) use ($alias): void {
                $join->on('z.id', '=', $alias.'.quiz_id')->on('z.customer_id', '=', $alias.'.customer_id');
            })
            ->leftJoin(AssessmentRegistrationService::TABLE.' as reg', function ($join) use ($alias): void {
                $join->on('reg.id', '=', $alias.'.quiz_registration_id')->on('reg.customer_id', '=', $alias.'.customer_id');
            })
            ->leftJoin('users as s', 's.id', '=', $alias.'.user_id')
            ->where($alias.'.customer_id', $customerId)
            ->when($user->role !== 'customer_admin', fn ($query) => $query->where(fn ($query) => $query
                ->where('z.created_by', $user->id)
                ->orWhere(fn ($query) => $query->where('reg.source', 'granted')->where('reg.granted_by', $user->id))));
    }

    public function find(int $customerId, User $user, int $attemptId, bool $lock = false): object
    {
        $attempt = $this->scoped($customerId, $user)->where('a.id', $attemptId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first(['a.*', 'z.title as quiz_title', 's.name as student_name', 's.email as student_email']);
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
            }
            $this->attempts->recalculate($customerId, $attemptId);
        });
    }
}
