<?php

namespace App\Services\Assessment;

use App\Services\CourseProgressRecorder;
use App\Services\MediaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Attempts of the Kiểm tra module (ADR-0025 D8, D10): starting from a registration or, for a Quiz Activity of a course,
 * from the Enrollment (ADR-0032 D2), saving answers, submitting with
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

    /** Files per essay answer when the question allows them (ADR-0027 D5). */
    public const MAX_ATTACHMENTS = 3;

    /**
     * Starts (or resumes) a course attempt on a Quiz Activity (ADR-0032 D2), on the quiz version frozen when the course
     * was published. Locks the Enrollment so attempts are counted once. The caller has already checked that the learner
     * may learn the Activity now (VersionActivityAccessService / VersionLessonAccessService).
     */
    public function startForActivity(int $customerId, int $studentId, int $enrollmentId, int $versionActivityId): object
    {
        $attempt = DB::transaction(function () use ($customerId, $studentId, $enrollmentId, $versionActivityId): object {
            $enrollment = DB::table('core_course_enrollments')->where('customer_id', $customerId)
                ->where('student_id', $studentId)->where('id', $enrollmentId)->lockForUpdate()->first();
            abort_if(! $enrollment, 404);
            $activity = DB::table('core_course_template_version_activities')->where('customer_id', $customerId)
                ->where('template_version_id', $enrollment->version_id)->where('id', $versionActivityId)
                ->where('activity_type', 'quiz')->first();
            abort_if(! $activity || ! $activity->assessment_quiz_version_id_snapshot, 404);
            if ($enrollment->status === 'cancelled') {
                throw ValidationException::withMessages(['attempt' => __('lf.LF_assessment_attempt_error_registration')]);
            }

            $open = DB::table(self::TABLE)->where('customer_id', $customerId)->where('enrollment_id', $enrollmentId)
                ->where('version_activity_id', $versionActivityId)->where('status', 'in_progress')->first();
            if ($open) {
                return $open;
            }

            $version = DB::table(AssessmentQuizService::VERSIONS_TABLE)->where('customer_id', $customerId)
                ->where('id', $activity->assessment_quiz_version_id_snapshot)->where('quiz_id', $activity->assessment_quiz_id_snapshot)->first();
            if (! $version) {
                throw ValidationException::withMessages(['attempt' => __('lf.LF_assessment_attempt_error_quiz')]);
            }
            $used = DB::table(self::TABLE)->where('customer_id', $customerId)->where('enrollment_id', $enrollmentId)
                ->where('version_activity_id', $versionActivityId)->count();
            if ($used >= (int) $version->max_attempts) {
                throw ValidationException::withMessages(['attempt' => __('lf.LF_assessment_attempt_error_no_attempts')]);
            }

            $now = now();
            $id = DB::table(self::TABLE)->insertGetId([
                'customer_id' => $customerId,
                'quiz_id' => $version->quiz_id,
                'quiz_version_id' => $version->id,
                'enrollment_id' => $enrollmentId,
                'product_id' => $enrollment->product_id,
                'template_version_id' => $enrollment->version_id,
                'version_activity_id' => $versionActivityId,
                'user_id' => $enrollment->student_id,
                'attempt_no' => $used + 1,
                'status' => 'in_progress',
                'grading_status' => 'not_required',
                'started_at' => $now,
                'deadline_at' => $version->duration_minutes ? $now->copy()->addMinutes((int) $version->duration_minutes) : null,
                'max_score' => $version->total_points,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return DB::table(self::TABLE)->find($id);
        });
        $this->courseProgress($attempt);

        return $attempt;
    }

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

    /**
     * Adds an uploaded file to the answer of an essay question that allows files (ADR-0027 D5), at most
     * MAX_ATTACHMENTS per answer. The same file twice is kept once. Throws on the `file` field when the answer is full.
     */
    public function saveAttachment(int $customerId, int $attemptId, int $versionQuestionId, int $mediaFileId, MediaService $media): void
    {
        DB::transaction(function () use ($customerId, $attemptId, $versionQuestionId, $mediaFileId, $media): void {
            $attempt = $this->lockOpen($customerId, $attemptId);
            $question = $this->fileQuestion($customerId, $attempt, $versionQuestionId);
            $answerId = $this->answerId($customerId, $attempt, $question);
            $files = DB::table(self::FILES_TABLE)->where('customer_id', $customerId)->where('answer_id', $answerId)
                ->where('file_role', 'essay_attachment')->pluck('media_file_id')->map(fn ($id) => (int) $id);

            if ($files->contains($mediaFileId)) {
                return;
            }
            if ($files->count() >= self::MAX_ATTACHMENTS) {
                throw ValidationException::withMessages(['file' => __('lf.LF_assessment_learner_files_full', ['max' => self::MAX_ATTACHMENTS])]);
            }

            DB::table(self::FILES_TABLE)->insert([
                'customer_id' => $customerId,
                'answer_id' => $answerId,
                'media_file_id' => $mediaFileId,
                'file_role' => 'essay_attachment',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $media->attachUsage($mediaFileId, 'assessment_answer', $answerId, 'attachment');
        });
    }

    /** Removes one file from an essay answer while the attempt is open. Returns false when it was not there. */
    public function removeAttachment(int $customerId, int $attemptId, int $versionQuestionId, int $mediaFileId, MediaService $media): bool
    {
        return DB::transaction(function () use ($customerId, $attemptId, $versionQuestionId, $mediaFileId, $media): bool {
            $attempt = $this->lockOpen($customerId, $attemptId);
            $question = $this->fileQuestion($customerId, $attempt, $versionQuestionId);
            $answerId = (int) DB::table(self::ANSWERS_TABLE)->where('customer_id', $customerId)
                ->where('attempt_id', $attemptId)->where('quiz_version_question_id', $question->id)->value('id');
            $deleted = $answerId > 0 && DB::table(self::FILES_TABLE)->where('customer_id', $customerId)->where('answer_id', $answerId)
                ->where('file_role', 'essay_attachment')->where('media_file_id', $mediaFileId)->delete() > 0;

            if ($deleted) {
                $media->detachUsage($mediaFileId, 'assessment_answer', $answerId, 'attachment');
                DB::table(self::ANSWERS_TABLE)->where('id', $answerId)->update(['updated_at' => now()]);
            }

            return $deleted;
        });
    }

    /**
     * The files of essay answers, oldest first, keyed by answer id.
     *
     * @param  iterable<int>  $answerIds
     * @return Collection<int, Collection<int, object>> each: media_file_id, display_name, original_name, mime_type, extension, status
     */
    public function attachments(int $customerId, iterable $answerIds): Collection
    {
        return DB::table(self::FILES_TABLE.' as f')->join('media_files as m', 'm.id', '=', 'f.media_file_id')
            ->where('f.customer_id', $customerId)->whereIn('f.answer_id', collect($answerIds)->all())
            ->where('f.file_role', 'essay_attachment')->where('m.status', '!=', 'deleted')
            ->orderBy('f.id')
            ->get(['f.answer_id', 'f.media_file_id', 'm.display_name', 'm.original_name', 'm.mime_type', 'm.extension', 'm.status'])
            ->groupBy('answer_id');
    }

    /** How a page shows an essay file: `image` inline (JPG / PNG / WEBP), else `pdf`, `docx` or `file` (HEIC) as a link. */
    public static function attachmentKind(object $file): string
    {
        return match (true) {
            in_array($file->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true) => 'image',
            $file->mime_type === 'application/pdf' => 'pdf',
            $file->extension === 'docx' => 'docx',
            default => 'file',
        };
    }

    /** A question of this attempt's version that takes files, or 404. */
    private function fileQuestion(int $customerId, object $attempt, int $versionQuestionId): object
    {
        $question = DB::table(AssessmentQuizPublishService::VERSION_QUESTIONS_TABLE)
            ->where('customer_id', $customerId)->where('quiz_version_id', $attempt->quiz_version_id)->where('id', $versionQuestionId)->first();
        abort_if(! $question || ! self::allowsFiles($question), 404);

        return $question;
    }

    /** Cho nộp tệp, as frozen in the version (a snapshot without the key means no). */
    public static function allowsFiles(object $versionQuestion): bool
    {
        return $versionQuestion->question_type === 'essay'
            && (bool) (json_decode((string) $versionQuestion->question_snapshot, true)['allow_file_answer'] ?? false);
    }

    /** The answer row of the question, created when missing (the text may come later). */
    private function answerId(int $customerId, object $attempt, object $question): int
    {
        $match = ['customer_id' => $customerId, 'attempt_id' => $attempt->id, 'quiz_version_question_id' => $question->id];
        if (! DB::table(self::ANSWERS_TABLE)->where($match)->exists()) {
            $this->upsertAnswer($customerId, $attempt, $question, ['answer_type' => 'text', 'answered_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table(self::ANSWERS_TABLE)->where($match)->update(['answered_at' => now(), 'updated_at' => now()]);
        }

        return (int) DB::table(self::ANSWERS_TABLE)->where($match)->value('id');
    }

    /**
     * Ends the attempt: every question gets an answer row, objective ones are graded, totals computed. A blank essay /
     * speaking answer is graded 0 by the system (ADR-0025 D11) so it never waits in Chờ chấm; graders may still regrade it.
     * An essay with files but no text is not blank (ADR-0027 D5).
     */
    public function submit(int $customerId, int $attemptId, string $reason = 'student'): object
    {
        return DB::transaction(function () use ($customerId, $attemptId, $reason): object {
            $attempt = $this->lockOpen($customerId, $attemptId, false);
            if ($reason === 'student') {
                $this->assertEnrollmentKept($customerId, $attempt);
            }
            $now = now();
            $questions = DB::table(AssessmentQuizPublishService::VERSION_QUESTIONS_TABLE)
                ->where('customer_id', $customerId)->where('quiz_version_id', $attempt->quiz_version_id)->orderBy('sort_order')->get();
            $answers = DB::table(self::ANSWERS_TABLE)->where('customer_id', $customerId)->where('attempt_id', $attemptId)
                ->get()->keyBy('quiz_version_question_id');

            // An answer counts as given with its file: a recording for speaking, an attachment for essay (ADR-0027 D5).
            $withFile = DB::table(self::FILES_TABLE)->where('customer_id', $customerId)
                ->whereIn('answer_id', $answers->pluck('id'))->distinct()->pluck('answer_id')
                ->map(fn ($id) => (int) $id);
            $blank = [];
            $pending = false;

            foreach ($questions as $question) {
                $answer = $answers->get($question->id);
                $manual = (bool) $question->requires_manual_grading;
                $values = ['updated_at' => $now];

                if ($manual) {
                    $hasFile = $answer && $withFile->contains((int) $answer->id);
                    $empty = $question->question_type === 'speaking'
                        ? ! $hasFile
                        : trim((string) $answer?->answer_text) === '' && ! $hasFile;
                    if ($empty) {
                        $blank[] = $question->id;
                        $values += ['grading_status' => 'graded', 'score' => 0, 'is_correct' => null];
                    } else {
                        $pending = true;
                        $values += ['grading_status' => 'pending', 'score' => null, 'is_correct' => null];
                    }
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

            $this->gradeBlank($customerId, $attemptId, $blank, $now);
            // A time-out ends the attempt at its deadline, however late it is closed (ADR-0025 Amendment 1.3, D10a).
            $submittedAt = $reason === 'timeout' && $attempt->deadline_at !== null
                ? $now->copy()->setTimestamp(min($now->getTimestamp(), strtotime((string) $attempt->deadline_at)))
                : $now;
            DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $attemptId)->update([
                'status' => $pending ? 'submitted' : 'graded',
                'grading_status' => $pending ? 'pending' : 'not_required',
                'submit_reason' => $reason,
                'submitted_at' => $submittedAt,
                'graded_at' => $pending ? null : $now,
                'time_spent_seconds' => max(0, $submittedAt->getTimestamp() - strtotime((string) $attempt->started_at)),
                'updated_at' => $now,
            ]);
            $this->recalculate($customerId, $attemptId);
            if ($attempt->quiz_registration_id) {
                $this->markExhausted($customerId, (int) $attempt->quiz_registration_id);
            }

            return DB::table(self::TABLE)->find($attemptId);
        });
    }

    /**
     * Ends (time out) every open attempt past its deadline and grace, so an attempt the student left never stays
     * "in progress" (ADR-0025 Amendment 1.3, D10a). Run by assessment:close-overdue; one attempt failing does not stop
     * the rest, and an attempt closed meanwhile by the student is skipped.
     *
     * @return int attempts closed
     */
    public function closeOverdue(?int $customerId = null, int $limit = 500): int
    {
        $cutoff = now()->subSeconds((int) config('assessment.deadline_grace_seconds', 30));
        $closed = 0;

        DB::table(self::TABLE)->where('status', 'in_progress')->whereNotNull('deadline_at')->where('deadline_at', '<', $cutoff)
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->orderBy('id')->limit($limit)->get(['id', 'customer_id'])
            ->each(function (object $attempt) use (&$closed): void {
                try {
                    $this->submit((int) $attempt->customer_id, (int) $attempt->id, 'timeout');
                    $closed++;
                } catch (ValidationException) {
                    // Closed by the student or a page load in the meantime.
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $closed;
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
        $this->courseProgress($attempt);
    }

    /**
     * A course attempt moves the learner's Activity Progress (ADR-0032 D3) once its own transaction has committed, so
     * the Course Progress locks are never taken while the attempt row is held.
     */
    private function courseProgress(object $attempt): void
    {
        if (! $attempt->enrollment_id || ! $attempt->version_activity_id) {
            return;
        }
        DB::afterCommit(fn () => app(CourseProgressRecorder::class)
            ->quizChanged((int) $attempt->customer_id, (int) $attempt->enrollment_id, (int) $attempt->version_activity_id));
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

    /**
     * The system grading (0, no grader) of blank essay / speaking answers, so their history reads like any other grade.
     *
     * @param  list<int>  $versionQuestionIds
     */
    private function gradeBlank(int $customerId, int $attemptId, array $versionQuestionIds, mixed $now): void
    {
        if ($versionQuestionIds === []) {
            return;
        }

        $answerIds = DB::table(self::ANSWERS_TABLE)->where('customer_id', $customerId)->where('attempt_id', $attemptId)
            ->whereIn('quiz_version_question_id', $versionQuestionIds)->pluck('id');
        DB::table(self::GRADINGS_TABLE)->where('customer_id', $customerId)->whereIn('answer_id', $answerIds)
            ->where('is_current', true)->update(['is_current' => false, 'updated_at' => $now]);
        DB::table(self::GRADINGS_TABLE)->insert($answerIds->map(fn ($answerId) => [
            'customer_id' => $customerId,
            'attempt_id' => $attemptId,
            'answer_id' => $answerId,
            'grader_user_id' => null,
            'grading_type' => 'system_auto',
            'score' => 0,
            'feedback' => null,
            'rubric_result' => null,
            'is_current' => true,
            'graded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
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
        if ($checkDeadline) {
            $this->assertEnrollmentKept($customerId, $attempt);
        }

        return $attempt;
    }

    /** A course attempt takes no answer once its Enrollment is cancelled; only the timeout still closes it (ADR-0032 D2). */
    private function assertEnrollmentKept(int $customerId, object $attempt): void
    {
        if ($attempt->enrollment_id && DB::table('core_course_enrollments')->where('customer_id', $customerId)
            ->where('id', $attempt->enrollment_id)->value('status') === 'cancelled') {
            throw ValidationException::withMessages(['attempt' => __('lf.LF_assessment_attempt_error_registration')]);
        }
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
