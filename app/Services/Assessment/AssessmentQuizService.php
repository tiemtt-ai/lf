<?php

namespace App\Services\Assessment;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Đề / Bài tập of the Kiểm tra module (core_assessment_quizzes, ADR-0025 D6): the editable draft of a quiz. Learners
 * never take the draft; publishing (a later step) freezes it into core_assessment_quiz_versions.
 *
 * A new quiz gets one default section, because every quiz has at least one (the page hides sections while there is
 * only one). Teachers see and edit only their own quizzes; admins see the whole tenant.
 */
final class AssessmentQuizService
{
    public const TABLE = 'core_assessment_quizzes';

    public const SECTIONS_TABLE = 'core_assessment_quiz_sections';

    public const QUESTIONS_TABLE = 'core_assessment_quiz_questions';

    public const VERSIONS_TABLE = 'core_assessment_quiz_versions';

    public const TYPES = ['exercise', 'exam'];

    public const RESULT_MODES = ['immediately', 'after_grading', 'score_only', 'hidden'];

    public const STATUSES = ['draft', 'published', 'archived'];

    /**
     * Saves the quiz settings. Status, current version and registration are not set here: they follow publishing.
     *
     * @param  array{title: string, description: string|null, category_id: int|null, assessment_type: string,
     *     duration_minutes: int|null, max_attempts: int, pass_score_percentage: string|null, show_result_mode: string}  $data
     */
    public function save(int $customerId, User $user, ?int $quizId, array $data): int
    {
        return DB::transaction(function () use ($customerId, $user, $quizId, $data): int {
            $existing = $quizId === null ? null : $this->find($customerId, $user, $quizId, true);
            $this->assertCategory($customerId, $data['category_id'], $existing?->category_id);
            $now = now();
            $values = [
                'category_id' => $data['category_id'],
                'title' => $data['title'],
                'description' => $data['description'],
                'assessment_type' => $data['assessment_type'],
                'duration_minutes' => $data['duration_minutes'],
                'max_attempts' => $data['max_attempts'],
                'pass_score_percentage' => $data['pass_score_percentage'],
                'show_result_mode' => $data['show_result_mode'],
                'updated_by' => $user->id,
                'updated_at' => $now,
                // Settings are part of the draft: a published quiz now has changes to publish again.
                'content_updated_at' => $now,
            ];

            if ($existing !== null) {
                DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $quizId)->update($values);

                return $quizId;
            }

            $id = DB::table(self::TABLE)->insertGetId($values + [
                'customer_id' => $customerId,
                'created_by' => $user->id,
                'status' => 'draft',
                'created_at' => $now,
            ]);
            DB::table(self::SECTIONS_TABLE)->insert([
                'customer_id' => $customerId,
                'quiz_id' => $id,
                'title' => __('lf.LF_assessment_quiz_default_section'),
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $id;
        });
    }

    /** Quizzes this user may see: all of the tenant for an admin, only their own for a teacher. */
    public function scoped(int $customerId, User $user, string $alias = ''): Builder
    {
        $prefix = $alias === '' ? '' : $alias.'.';

        return DB::table(self::TABLE.($alias === '' ? '' : ' as '.$alias))
            ->where($prefix.'customer_id', $customerId)
            ->when($user->role !== 'customer_admin', fn ($query) => $query->where($prefix.'created_by', $user->id));
    }

    public function find(int $customerId, User $user, int $quizId, bool $lock = false): object
    {
        $quiz = $this->scoped($customerId, $user)->where('id', $quizId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_if(! $quiz, 404);

        return $quiz;
    }

    /**
     * All or nothing. Only a quiz never published can be deleted (ADR-0025 D9); its sections and chosen questions
     * go with it (the foreign keys cascade), the questions themselves stay in the bank.
     *
     * @param  list<int>  $ids
     * @return array{name: string, reason: string, count: int}|null the first blocker, or null when deleted
     */
    public function deleteMany(int $customerId, User $user, array $ids): ?array
    {
        return DB::transaction(function () use ($customerId, $user, $ids): ?array {
            $quizzes = $this->scoped($customerId, $user)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'title']);
            abort_if($quizzes->count() !== count($ids), 404);

            foreach ($quizzes as $quiz) {
                $versions = $this->versionCount($customerId, (int) $quiz->id);
                if ($versions > 0) {
                    return ['name' => $quiz->title, 'reason' => 'versions', 'count' => $versions];
                }
            }

            DB::table(self::QUESTIONS_TABLE)->where('customer_id', $customerId)->whereIn('quiz_id', $ids)->delete();
            DB::table(self::SECTIONS_TABLE)->where('customer_id', $customerId)->whereIn('quiz_id', $ids)->delete();
            DB::table(self::TABLE)->where('customer_id', $customerId)->whereIn('id', $ids)->delete();

            return null;
        });
    }

    public function versionCount(int $customerId, int $quizId): int
    {
        return DB::table(self::VERSIONS_TABLE)->where('customer_id', $customerId)->where('quiz_id', $quizId)->count();
    }

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
}
