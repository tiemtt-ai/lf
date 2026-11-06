<?php

namespace App\Services\Assessment;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Thống kê of one quiz, read-only: totals of the submitted attempts this user may see (AssessmentGradingService
 * scope) and, per source question, how often it was answered correctly (objective) or the average score (essay /
 * speaking, graded answers only). A quiz has many versions and registrations, so everything is gathered across both
 * (ADR-0025 Consequences); a question is identified by its source question across versions.
 */
final class AssessmentStatisticsService
{
    /** At or above: most students get it right. */
    public const EASY_AT = 85.0;

    /** At or below: most students get it wrong. */
    public const HARD_AT = 30.0;

    public function __construct(private readonly AssessmentGradingService $grading) {}

    /**
     * @return array{summary: array<string, int|float|null>, questions: Collection<int, array<string, mixed>>}
     */
    public function forQuiz(int $customerId, User $user, int $quizId): array
    {
        $attempts = $this->grading->scoped($customerId, $user)
            ->where('a.quiz_id', $quizId)
            ->whereIn('a.status', ['submitted', 'graded'])
            ->get(['a.id', 'a.status', 'a.score_percentage', 'a.passed']);

        $percentages = $attempts->pluck('score_percentage')->map(fn ($value) => (float) $value);
        $judged = $attempts->filter(fn ($attempt) => $attempt->passed !== null);

        $summary = [
            'submitted' => $attempts->count(),
            'pending' => $attempts->where('status', 'submitted')->count(),
            'average' => $attempts->isEmpty() ? null : round($percentages->avg(), 1),
            'highest' => $attempts->isEmpty() ? null : round($percentages->max(), 1),
            'lowest' => $attempts->isEmpty() ? null : round($percentages->min(), 1),
            'pass_rate' => $judged->isEmpty() ? null : round($judged->filter(fn ($a) => (bool) $a->passed)->count() / $judged->count() * 100, 1),
        ];

        return ['summary' => $summary, 'questions' => $this->questions($customerId, $quizId, $attempts->pluck('id'))];
    }

    /**
     * @param  Collection<int, int>  $attemptIds
     * @return Collection<int, array<string, mixed>>
     */
    private function questions(int $customerId, int $quizId, Collection $attemptIds): Collection
    {
        // Every frozen question of the quiz's versions; the newest version gives the order and the wording.
        $versionQuestions = DB::table(AssessmentQuizPublishService::VERSION_QUESTIONS_TABLE.' as vq')
            ->join(AssessmentQuizService::VERSIONS_TABLE.' as v', 'v.id', '=', 'vq.quiz_version_id')
            ->where('vq.customer_id', $customerId)->where('v.customer_id', $customerId)->where('v.quiz_id', $quizId)
            ->orderByDesc('v.version_no')->orderBy('vq.sort_order')
            ->get(['vq.id', 'vq.question_id', 'vq.question_type', 'vq.requires_manual_grading', 'vq.question_snapshot', 'v.version_no']);

        $answers = $attemptIds->isEmpty() ? collect() : DB::table(AssessmentAttemptService::ANSWERS_TABLE)
            ->where('customer_id', $customerId)
            ->whereIn('attempt_id', $attemptIds)
            ->whereIn('quiz_version_question_id', $versionQuestions->pluck('id'))
            ->get(['quiz_version_question_id', 'is_correct', 'score', 'max_score', 'grading_status']);
        $sourceOf = $versionQuestions->pluck('question_id', 'id');
        $answersBySource = $answers->groupBy(fn ($answer) => $sourceOf[$answer->quiz_version_question_id]);

        $newestVersion = $versionQuestions->max('version_no');

        return $versionQuestions->groupBy('question_id')
            ->map(function (Collection $rows, $questionId) use ($answersBySource, $newestVersion): array {
                $latest = $rows->first();
                $snapshot = json_decode((string) $latest->question_snapshot, true) ?: [];
                $given = $answersBySource->get($questionId, collect());
                $manual = (bool) $latest->requires_manual_grading;

                if ($manual) {
                    $graded = $given->where('grading_status', 'graded');
                    $max = $graded->sum(fn ($a) => (float) $a->max_score);
                    $rate = $max > 0 ? round($graded->sum(fn ($a) => (float) $a->score) / $max * 100, 1) : null;
                    $counted = $graded->count();
                } else {
                    $counted = $given->count();
                    $rate = $counted > 0 ? round($given->filter(fn ($a) => (bool) $a->is_correct)->count() / $counted * 100, 1) : null;
                }

                return [
                    'question_id' => (int) $questionId,
                    'prompt' => (string) ($snapshot['prompt'] ?? ''),
                    'type' => (string) $latest->question_type,
                    'manual' => $manual,
                    'answered' => $given->count(),
                    'counted' => $counted,
                    'pending' => $manual ? $given->where('grading_status', 'pending')->count() : 0,
                    'rate' => $rate,
                    'level' => $rate === null ? null : ($rate >= self::EASY_AT ? 'easy' : ($rate <= self::HARD_AT ? 'hard' : 'medium')),
                    'in_current' => (int) $latest->version_no === (int) $newestVersion,
                ];
            })
            ->values();
    }
}
