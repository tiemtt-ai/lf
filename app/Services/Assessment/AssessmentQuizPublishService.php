<?php

namespace App\Services\Assessment;

use App\Models\User;
use App\Services\MediaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishing and the lifecycle of a quiz (ADR-0025 D6, D7). Publishing freezes the draft into an immutable
 * core_assessment_quiz_versions row and its core_assessment_quiz_version_questions: settings, sections, points, order
 * and a snapshot of every question, option, correct answer and passage. Learners only ever take a version.
 *
 * Snapshotted media gets its own usage (owner assessment_quiz_version) so a file the author later removes from a
 * question is never released while a version still shows it.
 */
final class AssessmentQuizPublishService
{
    public const VERSION_QUESTIONS_TABLE = 'core_assessment_quiz_version_questions';

    private const MEDIA_USAGE = ['prompt_image' => 'image', 'prompt_audio' => 'audio', 'prompt_video' => 'video'];

    public function __construct(
        private readonly AssessmentQuizService $quizzes,
        private readonly AssessmentQuizDraftService $drafts,
        private readonly AssessmentQuestionService $questions,
        private readonly MediaService $media,
    ) {}

    /** @return int the new version id */
    public function publish(int $customerId, User $user, int $quizId): int
    {
        return DB::transaction(function () use ($customerId, $user, $quizId): int {
            $quiz = $this->quizzes->find($customerId, $user, $quizId, true);
            if ($quiz->status === 'archived') {
                throw ValidationException::withMessages(['publish' => __('lf.LF_assessment_quiz_publish_error_archived')]);
            }

            $locale = $this->questions->locale();
            $sections = $this->drafts->sections($customerId, $quizId)->values();
            $sectionKeys = $sections->mapWithKeys(fn (object $section, int $i) => [(int) $section->id => $i + 1]);
            $items = $this->drafts->items($customerId, $quizId, $locale);
            $this->assertPublishable($customerId, $items, $locale);

            $questionIds = $items->pluck('question_id')->map(fn ($id) => (int) $id)->all();
            $contents = DB::table('core_assessment_question_contents')->where('customer_id', $customerId)
                ->whereIn('question_id', $questionIds)->where('locale', $locale)->get()->keyBy('question_id');
            $options = DB::table('core_assessment_question_options')->where('customer_id', $customerId)
                ->whereIn('question_id', $questionIds)->where('locale', $locale)->orderBy('sort_order')->get()->groupBy('question_id');
            $questions = DB::table(AssessmentQuestionService::TABLE)->where('customer_id', $customerId)
                ->whereIn('id', $questionIds)->get()->keyBy('id');
            $passageIds = $items->pluck('passage_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $media = DB::table(AssessmentPromptMediaService::TABLE)->where('customer_id', $customerId)
                ->where(fn ($query) => $query->whereIn('question_id', $questionIds)->orWhereIn('passage_id', $passageIds))
                ->orderBy('media_role')->orderBy('sort_order')->get();

            $passages = DB::table(AssessmentPassageService::TABLE)->where('customer_id', $customerId)->whereIn('id', $passageIds)->get()
                ->mapWithKeys(fn (object $passage) => [(string) $passage->id => [
                    'title' => $passage->title,
                    'content' => $passage->content,
                    'skill_type' => $passage->skill_type,
                    'media' => $this->mediaRefs($media->where('passage_id', $passage->id)),
                ]]);

            $now = now();
            $versionNo = (int) DB::table(AssessmentQuizService::VERSIONS_TABLE)
                ->where('customer_id', $customerId)->where('quiz_id', $quizId)->max('version_no') + 1;
            $versionId = DB::table(AssessmentQuizService::VERSIONS_TABLE)->insertGetId([
                'customer_id' => $customerId,
                'quiz_id' => $quizId,
                'version_no' => $versionNo,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'assessment_type' => $quiz->assessment_type,
                'duration_minutes' => $quiz->duration_minutes,
                'max_attempts' => $quiz->max_attempts,
                'pass_score_percentage' => $quiz->pass_score_percentage,
                'show_result_mode' => $quiz->show_result_mode,
                'total_points' => number_format($items->sum(fn (object $item) => (float) $item->points), 2, '.', ''),
                'question_count' => $items->count(),
                'sections_snapshot' => json_encode($sections->map(fn (object $section, int $i) => [
                    'key' => $i + 1,
                    'title' => $section->title,
                    'description' => $section->description,
                    'skill_type' => $section->skill_type,
                ])->all(), JSON_UNESCAPED_UNICODE),
                'passages_snapshot' => $passages->isEmpty() ? null : json_encode($passages->all(), JSON_UNESCAPED_UNICODE),
                'published_by' => $user->id,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table(self::VERSION_QUESTIONS_TABLE)->insert($items->values()->map(function (object $item, int $i) use (
                $customerId, $versionId, $sectionKeys, $questions, $contents, $options, $media, $now
            ): array {
                $question = $questions->get($item->question_id);
                $content = $contents->get($item->question_id);
                $rows = $options->get($item->question_id, collect())->values();
                $type = $question->question_type;
                $choice = in_array($type, AssessmentQuestionService::CHOICE_TYPES, true);

                return [
                    'customer_id' => $customerId,
                    'quiz_version_id' => $versionId,
                    'question_id' => (int) $question->id,
                    'passage_id' => $question->passage_id,
                    'section_key' => $sectionKeys[(int) $item->quiz_section_id],
                    'sort_order' => $i + 1,
                    'question_type' => $type,
                    'skill_type' => $question->skill_type,
                    'points' => $item->points,
                    'requires_manual_grading' => in_array($type, AssessmentQuestionService::GRADER_TYPES, true),
                    'question_snapshot' => json_encode([
                        'title' => $content?->title,
                        'prompt' => $content?->prompt,
                        'explanation' => $content?->explanation,
                        'reference_answer' => $content?->reference_answer,
                        'media' => $this->mediaRefs($media->where('question_id', $question->id)),
                    ], JSON_UNESCAPED_UNICODE),
                    'options_snapshot' => $choice ? json_encode($rows->map(fn (object $option, int $n) => [
                        'key' => (int) $option->sort_order,
                        'label' => $option->option_label ?: chr(65 + $n),
                        'text' => $option->option_text,
                    ])->all(), JSON_UNESCAPED_UNICODE) : null,
                    'correct_answer_snapshot' => match (true) {
                        $choice => json_encode(['option_keys' => $rows->where('is_correct', true)->pluck('sort_order')->map(fn ($k) => (int) $k)->values()->all()]),
                        $type === 'short_answer' => json_encode(['accepted' => $rows->pluck('option_text')->values()->all()], JSON_UNESCAPED_UNICODE),
                        default => null,
                    },
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all());

            // The version keeps its own hold on every file it shows.
            foreach ($media->unique(fn (object $row) => $row->media_file_id.':'.$row->media_role) as $row) {
                $this->media->attachUsage((int) $row->media_file_id, 'assessment_quiz_version', $versionId,
                    self::MEDIA_USAGE[$row->media_role] ?? 'attachment');
            }

            DB::table(AssessmentQuizService::TABLE)->where('customer_id', $customerId)->where('id', $quizId)->update([
                'current_version_id' => $versionId,
                'status' => 'published',
                'updated_by' => $user->id,
                'updated_at' => $now,
            ]);

            return $versionId;
        });
    }

    /** published → archived: no new registrations (registration closes); what learners already have stays. */
    public function archive(int $customerId, User $user, int $quizId): void
    {
        $this->transition($customerId, $user, $quizId, 'published', ['status' => 'archived', 'registration_open' => false]);
    }

    public function restore(int $customerId, User $user, int $quizId): void
    {
        $this->transition($customerId, $user, $quizId, 'archived', ['status' => 'published']);
    }

    /** Opens or closes self-registration; only a published quiz can be open. */
    public function setRegistration(int $customerId, User $user, int $quizId, bool $open): void
    {
        $this->transition($customerId, $user, $quizId, 'published', ['registration_open' => $open]);
    }

    /** @return Collection<int, object> versions, newest first, with the publisher's name */
    public function versions(int $customerId, int $quizId): Collection
    {
        return DB::table(AssessmentQuizService::VERSIONS_TABLE.' as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.published_by')
            ->where('v.customer_id', $customerId)->where('v.quiz_id', $quizId)
            ->orderByDesc('v.version_no')
            ->get(['v.id', 'v.version_no', 'v.question_count', 'v.total_points', 'v.published_at', 'u.name as publisher_name']);
    }

    /** @param  array<string, mixed>  $values */
    private function transition(int $customerId, User $user, int $quizId, string $from, array $values): void
    {
        DB::transaction(function () use ($customerId, $user, $quizId, $from, $values): void {
            $quiz = $this->quizzes->find($customerId, $user, $quizId, true);
            if ($quiz->status !== $from) {
                throw ValidationException::withMessages(['status' => __('lf.LF_assessment_quiz_error_status_'.$from)]);
            }

            DB::table(AssessmentQuizService::TABLE)->where('customer_id', $customerId)->where('id', $quizId)
                ->update($values + ['updated_by' => $user->id, 'updated_at' => now()]);
        });
    }

    /**
     * The draft must have questions; every question and passage must be ready; a passage's questions must sit together
     * in one section; every choice question must have valid answers.
     */
    private function assertPublishable(int $customerId, Collection $items, string $locale): void
    {
        $errors = [];

        if ($items->isEmpty()) {
            $errors[] = __('lf.LF_assessment_quiz_publish_error_empty');
        }

        foreach ($items as $position => $item) {
            $label = __('lf.LF_assessment_quiz_question_number', ['number' => $position + 1]);
            if ($item->question_status !== 'published') {
                $errors[] = __('lf.LF_assessment_quiz_publish_error_question_not_ready', ['question' => $label]);
            }
            if ($item->passage_id !== null && $item->passage_status !== 'published') {
                $errors[] = __('lf.LF_assessment_quiz_publish_error_passage_not_ready', ['passage' => $item->passage_title]);
            }
        }

        // A passage group: consecutive positions and one section.
        $seen = [];
        $previous = null;
        foreach ($items as $item) {
            $key = $item->passage_id === null ? null : $item->passage_id.':'.$item->quiz_section_id;
            if ($item->passage_id !== null && $previous !== $key && isset($seen[$item->passage_id])) {
                $errors[] = __('lf.LF_assessment_quiz_publish_error_passage_split', ['passage' => $item->passage_title]);
            }
            if ($item->passage_id !== null) {
                $seen[$item->passage_id] = true;
            }
            $previous = $key;
        }

        $options = DB::table('core_assessment_question_options')->where('customer_id', $customerId)
            ->whereIn('question_id', $items->pluck('question_id'))->where('locale', $locale)->get()->groupBy('question_id');
        foreach ($items as $position => $item) {
            $rows = $options->get($item->question_id, collect())
                ->map(fn (object $option) => ['text' => (string) $option->option_text, 'correct' => (bool) $option->is_correct])->values()->all();
            if (AssessmentQuestionService::answerProblem($item->question_type, $rows) !== null) {
                $errors[] = __('lf.LF_assessment_quiz_publish_error_answers', [
                    'question' => __('lf.LF_assessment_quiz_question_number', ['number' => $position + 1]),
                ]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['publish' => array_values(array_unique($errors))]);
        }
    }

    /** @return list<array{media_file_id: int, media_role: string}> */
    private function mediaRefs(Collection $rows): array
    {
        return $rows->map(fn (object $row) => ['media_file_id' => (int) $row->media_file_id, 'media_role' => $row->media_role])->values()->all();
    }
}
