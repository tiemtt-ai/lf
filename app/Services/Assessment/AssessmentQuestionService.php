<?php

namespace App\Services\Assessment;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Source questions of the Kiểm tra module (core_assessment_questions + contents + options, ADR-0025 D3–D5).
 *
 * A draft may be incomplete; moving to `published` must satisfy the rules of its type (question_options doc).
 * Teachers see and edit only what they created; admins see and edit everything in the tenant. A question that
 * a quiz draft or a published quiz version uses is archived, never deleted.
 */
final class AssessmentQuestionService
{
    public const TABLE = 'core_assessment_questions';

    public const TYPES = ['single_choice', 'multiple_choice', 'true_false', 'short_answer', 'essay', 'speaking'];

    public const SKILLS = ['reading', 'listening', 'writing', 'speaking', 'other'];

    public const LEVELS = ['easy', 'medium', 'hard'];

    public const STATUSES = ['draft', 'published', 'archived'];

    public const CHOICE_TYPES = ['single_choice', 'multiple_choice', 'true_false'];

    public const GRADER_TYPES = ['essay', 'speaking'];

    public function __construct(
        private readonly AssessmentPromptMediaService $media,
        private readonly AssessmentPassageService $passages,
        private readonly AssessmentRubricService $rubrics,
    ) {}

    /**
     * @param  array{question_type: string, category_id: int|null, skill_type: string|null, difficulty_level: string|null,
     *     default_points: string, status: string, prompt: string, explanation: string|null, reference_answer: string|null,
     *     options: list<array{text: string, correct: bool}>, accepted_answers: list<string>, true_false_answer: string|null,
     *     rubric_id?: int|null, allow_file_answer?: bool}  $data
     */
    public function save(int $customerId, User $user, ?int $questionId, array $data): int
    {
        return DB::transaction(function () use ($customerId, $user, $questionId, $data): int {
            $existing = $questionId === null ? null : $this->findForUpdate($customerId, $user, $questionId);
            $this->assertCategory($customerId, $data['category_id'], $existing?->category_id);
            // Only essay / speaking are graded with a rubric (ADR-0026 D3); other types drop it.
            $rubricId = in_array($data['question_type'], self::GRADER_TYPES, true) ? ($data['rubric_id'] ?? null) : null;
            $this->rubrics->assertChoosable($customerId, $user, $rubricId, $existing?->rubric_id);
            $options = $this->optionsFor($data);

            if ($data['status'] === 'published') {
                $this->assertPublishable($data['question_type'], $options);
            }

            $now = now();
            $values = [
                'category_id' => $data['category_id'],
                'question_type' => $data['question_type'],
                'skill_type' => $data['skill_type'],
                'difficulty_level' => $data['difficulty_level'],
                'default_points' => $data['default_points'],
                'rubric_id' => $rubricId,
                // Cho nộp tệp is an essay setting (ADR-0027 D5); any other type clears it.
                'allow_file_answer' => $data['question_type'] === 'essay' && ($data['allow_file_answer'] ?? false),
                'status' => $data['status'],
                'updated_by' => $user->id,
                'updated_at' => $now,
            ];

            if ($existing === null) {
                // A child question joins its passage at the end; the passage row lock serialises the position.
                $passageId = $data['passage_id'] ?? null;
                if ($passageId !== null) {
                    $passage = $this->passages->find($customerId, $user, $passageId, true);
                    if ($passage->status === 'archived') {
                        throw ValidationException::withMessages(['passage_id' => __('lf.LF_assessment_passage_error_archived')]);
                    }
                }

                $questionId = DB::table(self::TABLE)->insertGetId($values + [
                    'customer_id' => $customerId,
                    'passage_id' => $passageId,
                    'passage_sort_order' => $passageId === null ? null : $this->passages->nextSortOrder($customerId, $passageId),
                    'created_by' => $user->id,
                    'created_at' => $now,
                ]);
            } else {
                DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $questionId)->update($values);
            }

            $locale = $this->locale();
            $contentKey = ['customer_id' => $customerId, 'question_id' => $questionId, 'locale' => $locale];
            $content = [
                'prompt' => $data['prompt'],
                'explanation' => $data['explanation'],
                'reference_answer' => in_array($data['question_type'], self::GRADER_TYPES, true) ? $data['reference_answer'] : null,
                'updated_at' => $now,
            ];
            if (DB::table('core_assessment_question_contents')->where($contentKey)->exists()) {
                DB::table('core_assessment_question_contents')->where($contentKey)->update($content);
            } else {
                DB::table('core_assessment_question_contents')->insert($contentKey + $content + ['created_at' => $now]);
            }

            // Options carry no outside references (attempts read the version snapshot), so they are rewritten whole.
            DB::table('core_assessment_question_options')->where('customer_id', $customerId)->where('question_id', $questionId)->delete();
            foreach ($options as $index => $option) {
                DB::table('core_assessment_question_options')->insert([
                    'customer_id' => $customerId,
                    'question_id' => $questionId,
                    'locale' => $locale,
                    'option_text' => $option['text'],
                    'is_correct' => $option['correct'],
                    'sort_order' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $questionId;
        });
    }

    /**
     * All or nothing. Returns null when deleted, otherwise the first blocking question and why.
     *
     * @param  list<int>  $ids
     * @return array{id: int, reason: string, count: int}|null
     */
    public function deleteMany(int $customerId, User $user, array $ids): ?array
    {
        return DB::transaction(function () use ($customerId, $user, $ids): ?array {
            $questions = $this->scoped($customerId, $user)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            abort_if($questions->count() !== count($ids), 404);

            foreach ($questions as $question) {
                foreach ($this->usageCounts($customerId, (int) $question->id) as $reason => $count) {
                    if ($count > 0) {
                        return ['id' => (int) $question->id, 'reason' => $reason, 'count' => $count];
                    }
                }
            }

            // Contents, options and media rows cascade with the question; the Media usages are released first.
            foreach ($ids as $id) {
                $this->media->detachAll('question', $customerId, $id);
            }
            DB::table(self::TABLE)->where('customer_id', $customerId)->whereIn('id', $ids)->delete();

            return null;
        });
    }

    /** @return array{versions: int, quizzes: int} */
    public function usageCounts(int $customerId, int $questionId): array
    {
        return [
            'versions' => DB::table('core_assessment_quiz_version_questions')
                ->where('customer_id', $customerId)->where('question_id', $questionId)->count(),
            'quizzes' => DB::table('core_assessment_quiz_questions')
                ->where('customer_id', $customerId)->where('question_id', $questionId)->count(),
        ];
    }

    /** Questions this user may see: all of the tenant for an admin, only their own for a teacher. */
    public function scoped(int $customerId, User $user, string $alias = ''): Builder
    {
        $prefix = $alias === '' ? '' : $alias.'.';

        // A teacher reaches their own questions and every child question of their own passages.
        return DB::table(self::TABLE.($alias === '' ? '' : ' as '.$alias))
            ->where($prefix.'customer_id', $customerId)
            ->when($user->role !== 'customer_admin', fn ($query) => $query->where(fn ($query) => $query
                ->where($prefix.'created_by', $user->id)
                ->orWhereIn($prefix.'passage_id', DB::table(AssessmentPassageService::TABLE)->select('id')
                    ->where('customer_id', $customerId)->where('created_by', $user->id))));
    }

    /** @return array{question: object, content: object|null, options: Collection<int, object>} */
    public function load(int $customerId, User $user, int $questionId): array
    {
        $question = $this->scoped($customerId, $user)->where('id', $questionId)->first();
        abort_if(! $question, 404);

        return [
            'question' => $question,
            'content' => DB::table('core_assessment_question_contents')
                ->where('customer_id', $customerId)->where('question_id', $questionId)
                ->orderByRaw('locale = ? desc', [$this->locale()])->first(),
            'options' => DB::table('core_assessment_question_options')
                ->where('customer_id', $customerId)->where('question_id', $questionId)
                ->orderBy('sort_order')->get(),
        ];
    }

    public function locale(): string
    {
        return (string) config('app.locale', 'vi');
    }

    private function findForUpdate(int $customerId, User $user, int $questionId): object
    {
        $question = $this->scoped($customerId, $user)->where('id', $questionId)->lockForUpdate()->first();
        abort_if(! $question, 404);

        return $question;
    }

    /** An archived category is refused for new choices but stays valid for a question that already has it. */
    private function assertCategory(int $customerId, ?int $categoryId, mixed $currentCategoryId): void
    {
        if ($categoryId === null || $categoryId === (int) $currentCategoryId) {
            return;
        }

        $ok = DB::table(AssessmentCategoryService::TABLE)
            ->where('customer_id', $customerId)->where('id', $categoryId)->where('status', 'active')->exists();

        if (! $ok) {
            throw ValidationException::withMessages(['category_id' => __('lf.LF_assessment_question_error_category')]);
        }
    }

    /**
     * Options to store for the type: choices for choice types, fixed Đúng/Sai for true_false, accepted answers
     * (all correct) for short_answer, nothing for essay / speaking.
     *
     * @return list<array{text: string, correct: bool}>
     */
    private function optionsFor(array $data): array
    {
        return match ($data['question_type']) {
            'single_choice', 'multiple_choice' => array_values($data['options']),
            'true_false' => [
                ['text' => __('lf.LF_assessment_question_true', [], $this->locale()), 'correct' => $data['true_false_answer'] === 'true'],
                ['text' => __('lf.LF_assessment_question_false', [], $this->locale()), 'correct' => $data['true_false_answer'] === 'false'],
            ],
            'short_answer' => array_map(fn (string $text) => ['text' => $text, 'correct' => true], array_values($data['accepted_answers'])),
            default => [],
        };
    }

    /** @param  list<array{text: string, correct: bool}>  $options */
    private function assertPublishable(string $type, array $options): void
    {
        $error = self::answerProblem($type, $options);

        if ($error !== null) {
            throw ValidationException::withMessages(['options' => __('lf.LF_assessment_question_error_publish_'.$error)]);
        }
    }

    /**
     * What keeps a question of this type from being used, or null: the answer rules a published question and a
     * published quiz version both rely on.
     *
     * @param  list<array{text: string, correct: bool}>  $options
     */
    public static function answerProblem(string $type, array $options): ?string
    {
        $correct = count(array_filter($options, fn (array $option) => $option['correct']));

        return match ($type) {
            'single_choice' => count($options) < 2 || $correct !== 1 ? 'single' : null,
            'multiple_choice' => count($options) < 2 || $correct < 1 ? 'multiple' : null,
            'true_false' => $correct !== 1 ? 'true_false' : null,
            'short_answer' => count($options) < 1 ? 'short_answer' : null,
            default => null,
        };
    }
}
