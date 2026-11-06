<?php

namespace App\Services\Assessment;

use App\Services\MediaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attempts of the Kiểm tra module (ADR-0025 D8, D10): starting from a registration, saving answers, submitting with
 * server-side automatic grading, and the score totals that manual grading recalculates. Everything is graded from the
 * frozen version snapshot, never from the editable bank.
 *
 * The learner pages (built later) call start / saveAnswer / submit; the results pages call recalculate.
 */
final class AssessmentAttemptService
{
    public const TABLE = 'core_assessment_attempts';

    public const ANSWERS_TABLE = 'core_assessment_answers';

    public const GRADINGS_TABLE = 'core_assessment_gradings';

    public const FILES_TABLE = 'core_assessment_answer_files';

    /**
     * Starts (or resumes) an attempt on the quiz's current version. Locks the registration so attempts are counted once.
     */
    public function start(int $customerId, int $registrationId): object
    {
        return DB::transaction(function () use ($customerId, $registrationId): object {
            $registration = DB::table(AssessmentRegistrationService::TABLE)
                ->where('customer_id', $customerId)->where('id', $registrationId)->lockForUpdate()->first();
            abort_if(! $registration, 404);
            if ($registration->status !== 'active') {
                throw ValidationException::withMessages(['attempt' => __('lf.LF_assessment_attempt_error_registration')]);
            }

            $open = DB::table(self::TABLE)->where('customer_id', $customerId)
                ->where('quiz_registration_id', $registrationId)->where('status', 'in_progress')->first();
            if ($open) {
                return $open;
            }

            $quiz = DB::table(AssessmentQuizService::TABLE)->where('customer_id', $customerId)->where('id', $registration->quiz_id)->first();
            $version = $quiz?->current_version_id
                ? DB::table(AssessmentQuizService::VERSIONS_TABLE)->where('customer_id', $customerId)->where('id', $quiz->current_version_id)->first()
                : null;
            if (! $version || $quiz->status === 'archived') {
                throw ValidationException::withMessages(['attempt' => __('lf.LF_assessment_attempt_error_quiz')]);
            }
            if ((int) $registration->attempts_used >= (int) $version->max_attempts) {
                throw ValidationException::withMessages(['attempt' => __('lf.LF_assessment_attempt_error_no_attempts')]);
            }

            $now = now();
            $id = DB::table(self::TABLE)->insertGetId([
                'customer_id' => $customerId,
                'quiz_id' => $quiz->id,
                'quiz_version_id' => $version->id,
                'quiz_registration_id' => $registrationId,
                'user_id' => $registration->student_id,
                'attempt_no' => (int) $registration->attempts_used + 1,
                'status' => 'in_progress',
                'grading_status' => 'not_required',
                'started_at' => $now,
                'deadline_at' => $version->duration_minutes ? $now->copy()->addMinutes((int) $version->duration_minutes) : null,
                'max_score' => $version->total_points,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table(AssessmentRegistrationService::TABLE)->where('id', $registrationId)
                ->update(['attempts_used' => (int) $registration->attempts_used + 1, 'updated_at' => $now]);

            return DB::table(self::TABLE)->find($id);
        });
    }

    /**
     * Saves (upserts) one answer while the attempt is open. Option keys that are not in the snapshot are dropped.
     *
     * @param  array{option_keys?: list<int>, text?: string|null}  $answer
     */
    public function saveAnswer(int $customerId, int $attemptId, int $versionQuestionId, array $answer): void
    {
        DB::transaction(function () use ($customerId, $attemptId, $versionQuestionId, $answer): void {
            $attempt = $this->lockOpen($customerId, $attemptId);
            $question = DB::table(AssessmentQuizPublishService::VERSION_QUESTIONS_TABLE)
                ->where('customer_id', $customerId)->where('quiz_version_id', $attempt->quiz_version_id)->where('id', $versionQuestionId)->first();
            abort_if(! $question, 404);

            $values = ['answer_type' => $this->answerType($question->question_type), 'answered_at' => now(), 'updated_at' => now()];
            if (in_array($question->question_type, AssessmentQuestionService::CHOICE_TYPES, true)) {
                $valid = collect(json_decode((string) $question->options_snapshot, true) ?: [])->pluck('key')->map(fn ($k) => (int) $k);
                $keys = collect($answer['option_keys'] ?? [])->map(fn ($k) => (int) $k)->filter(fn (int $k) => $valid->contains($k))->unique()->sort()->values();
                if ($question->question_type !== 'multiple_choice') {
                    $keys = $keys->take(1);
                }
                $values['selected_option_keys'] = json_encode($keys->all());
            } else {
                $values['answer_text'] = isset($answer['text']) ? trim((string) $answer['text']) : null;
            }

            $this->upsertAnswer($customerId, $attempt, $question, $values);
        });
    }

    /**
     * Throws when the attempt can no longer take answers (ended, or past its deadline and grace). Lets callers refuse
     * before doing slow work such as storing an upload.
     */
    public function assertOpen(int $customerId, int $attemptId): void
    {
        DB::transaction(fn () => $this->lockOpen($customerId, $attemptId));
    }

    /**
     * Links an uploaded speaking recording to the answer of a speaking question, replacing an earlier one. Returns the
     * media id it replaced (its usage already detached), so the caller can delete the file if nothing else uses it.
     */
    public function saveRecording(int $customerId, int $attemptId, int $versionQuestionId, int $mediaFileId, MediaService $media): ?int
    {
        return DB::transaction(function () use ($customerId, $attemptId, $versionQuestionId, $mediaFileId, $media): ?int {
            $attempt = $this->lockOpen($customerId, $attemptId);
            $question = DB::table(AssessmentQuizPublishService::VERSION_QUESTIONS_TABLE)
                ->where('customer_id', $customerId)->where('quiz_version_id', $attempt->quiz_version_id)->where('id', $versionQuestionId)->first();
            abort_if(! $question || $question->question_type !== 'speaking', 404);

            $this->upsertAnswer($customerId, $attempt, $question, ['answer_type' => 'audio', 'answered_at' => now(), 'updated_at' => now()]);
            $answerId = (int) DB::table(self::ANSWERS_TABLE)->where('customer_id', $customerId)
                ->where('attempt_id', $attemptId)->where('quiz_version_question_id', $versionQuestionId)->value('id');
            $old = DB::table(self::FILES_TABLE)->where('customer_id', $customerId)->where('answer_id', $answerId)
                ->where('file_role', 'speaking_recording')->first();

            if ($old) {
                if ((int) $old->media_file_id === $mediaFileId) {
                    return null;
                }
                DB::table(self::FILES_TABLE)->where('id', $old->id)->delete();
                $media->detachUsage((int) $old->media_file_id, 'assessment_answer', $answerId, 'recording');
            }

            DB::table(self::FILES_TABLE)->insert([
                'customer_id' => $customerId,
                'answer_id' => $answerId,
                'media_file_id' => $mediaFileId,
                'file_role' => 'speaking_recording',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $media->attachUsage($mediaFileId, 'assessment_answer', $answerId, 'recording');

            return $old ? (int) $old->media_file_id : null;
        });
    }

    /** Ends the attempt: every question gets an answer row, objective ones are graded, totals computed. */
    public function submit(int $customerId, int $attemptId, string $reason = 'student'): object
    {
        return DB::transaction(function () use ($customerId, $attemptId, $reason): object {
            $attempt = $this->lockOpen($customerId, $attemptId, false);
            $now = now();
            $questions = DB::table(AssessmentQuizPublishService::VERSION_QUESTIONS_TABLE)
                ->where('customer_id', $customerId)->where('quiz_version_id', $attempt->quiz_version_id)->orderBy('sort_order')->get();
            $answers = DB::table(self::ANSWERS_TABLE)->where('customer_id', $customerId)->where('attempt_id', $attemptId)
                ->get()->keyBy('quiz_version_question_id');

            foreach ($questions as $question) {
                $answer = $answers->get($question->id);
                $manual = (bool) $question->requires_manual_grading;
                $values = ['updated_at' => $now];

                if ($manual) {
                    $values += ['grading_status' => 'pending', 'score' => null, 'is_correct' => null];
                } else {
                    $correct = $this->isCorrect($question, $answer);
                    $values += ['grading_status' => 'not_required', 'is_correct' => $correct, 'score' => $correct ? $question->points : 0];
                    if ($answer && in_array($question->question_type, AssessmentQuestionService::CHOICE_TYPES, true)) {
                        $chosen = collect(json_decode((string) $answer->selected_option_keys, true) ?: []);
                        $values['selected_options_snapshot'] = json_encode(collect(json_decode((string) $question->options_snapshot, true) ?: [])
                            ->filter(fn (array $option) => $chosen->contains($option['key']))->values()->all(), JSON_UNESCAPED_UNICODE);
                    }
                }

                $this->upsertAnswer($customerId, $attempt, $question, $values + ['answer_type' => $this->answerType($question->question_type)]);
            }

            $pending = $questions->contains(fn (object $question) => (bool) $question->requires_manual_grading);
            DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $attemptId)->update([
                'status' => $pending ? 'submitted' : 'graded',
                'grading_status' => $pending ? 'pending' : 'not_required',
                'submit_reason' => $reason,
                'submitted_at' => $now,
                'graded_at' => $pending ? null : $now,
                'time_spent_seconds' => max(0, $now->getTimestamp() - strtotime((string) $attempt->started_at)),
                'updated_at' => $now,
            ]);
            $this->recalculate($customerId, $attemptId);
            $this->markExhausted($customerId, (int) $attempt->quiz_registration_id);

            return DB::table(self::TABLE)->find($attemptId);
        });
    }

    /** score, score_percentage and passed from the current answer scores. Call inside a transaction. */
    public function recalculate(int $customerId, int $attemptId): void
    {
        $attempt = DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $attemptId)->first();
        $version = DB::table(AssessmentQuizService::VERSIONS_TABLE)->where('id', $attempt->quiz_version_id)->first();
        $score = (float) DB::table(self::ANSWERS_TABLE)->where('customer_id', $customerId)->where('attempt_id', $attemptId)->sum('score');
        $max = (float) $attempt->max_score;
        $percentage = $max > 0 ? round(min(100, $score / $max * 100), 2) : 0.0;

        DB::table(self::TABLE)->where('id', $attemptId)->update([
            'score' => number_format($score, 2, '.', ''),
            'score_percentage' => number_format($percentage, 2, '.', ''),
            'passed' => $attempt->status === 'graded' && $version->pass_score_percentage !== null
                ? $percentage >= (float) $version->pass_score_percentage
                : null,
            'updated_at' => now(),
        ]);
    }

    /** Snapshot-based automatic grading of one objective answer (no partial credit). */
    public function isCorrect(object $question, ?object $answer): bool
    {
        $correct = json_decode((string) $question->correct_answer_snapshot, true) ?: [];

        if ($question->question_type === 'short_answer') {
            $normalize = fn (?string $text) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $text)));
            $given = $normalize($answer?->answer_text);

            return $given !== '' && collect($correct['accepted'] ?? [])->contains(fn ($accepted) => $normalize((string) $accepted) === $given);
        }

        $chosen = collect(json_decode((string) ($answer?->selected_option_keys ?? '[]'), true) ?: [])->map(fn ($k) => (int) $k)->sort()->values()->all();
        $expected = collect($correct['option_keys'] ?? [])->map(fn ($k) => (int) $k)->sort()->values()->all();

        return $chosen !== [] && $chosen === $expected;
    }

    private function lockOpen(int $customerId, int $attemptId, bool $checkDeadline = true): object
    {
        $attempt = DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $attemptId)->lockForUpdate()->first();
        abort_if(! $attempt, 404);
        $grace = (int) config('assessment.deadline_grace_seconds', 30);
        $late = $attempt->deadline_at !== null && now()->getTimestamp() > strtotime((string) $attempt->deadline_at) + $grace;

        if ($attempt->status !== 'in_progress' || ($checkDeadline && $late)) {
            throw ValidationException::withMessages(['attempt' => __('lf.LF_assessment_attempt_error_closed')]);
        }

        return $attempt;
    }

    /** @param  array<string, mixed>  $values */
    private function upsertAnswer(int $customerId, object $attempt, object $question, array $values): void
    {
        $match = ['customer_id' => $customerId, 'attempt_id' => $attempt->id, 'quiz_version_question_id' => $question->id];
        $exists = DB::table(self::ANSWERS_TABLE)->where($match)->exists();

        if ($exists) {
            DB::table(self::ANSWERS_TABLE)->where($match)->update($values);

            return;
        }

        DB::table(self::ANSWERS_TABLE)->insert($match + $values + [
            'question_id' => $question->question_id,
            'max_score' => $question->points,
            'created_at' => now(),
            'updated_at' => $values['updated_at'] ?? now(),
        ]);
    }

    private function markExhausted(int $customerId, int $registrationId): void
    {
        $registration = DB::table(AssessmentRegistrationService::TABLE)->where('customer_id', $customerId)->where('id', $registrationId)->lockForUpdate()->first();
        $quiz = DB::table(AssessmentQuizService::TABLE)->where('id', $registration->quiz_id)->first();
        $version = DB::table(AssessmentQuizService::VERSIONS_TABLE)->where('id', $quiz->current_version_id)->first();

        if ($registration->status === 'active' && $version && (int) $registration->attempts_used >= (int) $version->max_attempts) {
            DB::table(AssessmentRegistrationService::TABLE)->where('id', $registrationId)->update(['status' => 'exhausted', 'updated_at' => now()]);
        }
    }

    private function answerType(string $questionType): string
    {
        return match ($questionType) {
            'single_choice', 'multiple_choice', 'true_false' => 'option',
            'speaking' => 'audio',
            default => 'text',
        };
    }

    /** @return Collection<int, object> */
    public function versionQuestions(int $customerId, int $versionId): Collection
    {
        return DB::table(AssessmentQuizPublishService::VERSION_QUESTIONS_TABLE)
            ->where('customer_id', $customerId)->where('quiz_version_id', $versionId)->orderBy('sort_order')->get();
    }
}
