<?php

namespace App\Services\Assessment;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Passages of the Kiểm tra module (core_assessment_passages, ADR-0025 D3–D4): a reading text or listening track that a
 * group of child questions shares. The children are ordinary questions with passage_id and passage_sort_order.
 *
 * Teachers see and edit only their own passages (and every child question of them); admins see the whole tenant.
 * A passage whose children a quiz uses is archived, never deleted.
 */
final class AssessmentPassageService
{
    public const TABLE = 'core_assessment_passages';

    public const STATUSES = ['draft', 'published', 'archived'];

    public function __construct(private readonly AssessmentPromptMediaService $media) {}

    /**
     * @param  array{title: string, content: string|null, category_id: int|null, skill_type: string|null,
     *     difficulty_level: string|null, status: string}  $data
     */
    public function save(int $customerId, User $user, ?int $passageId, array $data): int
    {
        return DB::transaction(function () use ($customerId, $user, $passageId, $data): int {
            $existing = $passageId === null ? null : $this->find($customerId, $user, $passageId, true);
            $this->assertCategory($customerId, $data['category_id'], $existing?->category_id);
            $now = now();
            $values = [
                'category_id' => $data['category_id'],
                'title' => $data['title'],
                'content' => $data['content'],
                'skill_type' => $data['skill_type'],
                'difficulty_level' => $data['difficulty_level'],
                'status' => $data['status'],
                'updated_by' => $user->id,
                'updated_at' => $now,
            ];

            if ($existing === null) {
                return DB::table(self::TABLE)->insertGetId($values + [
                    'customer_id' => $customerId,
                    'created_by' => $user->id,
                    'created_at' => $now,
                ]);
            }

            DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $passageId)->update($values);

            return $passageId;
        });
    }

    /** Passages this user may see: all of the tenant for an admin, only their own for a teacher. */
    public function scoped(int $customerId, User $user, string $alias = ''): Builder
    {
        $prefix = $alias === '' ? '' : $alias.'.';

        return DB::table(self::TABLE.($alias === '' ? '' : ' as '.$alias))
            ->where($prefix.'customer_id', $customerId)
            ->when($user->role !== 'customer_admin', fn ($query) => $query->where($prefix.'created_by', $user->id));
    }

    public function find(int $customerId, User $user, int $passageId, bool $lock = false): object
    {
        $passage = $this->scoped($customerId, $user)->where('id', $passageId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_if(! $passage, 404);

        return $passage;
    }

    /** Child questions in passage order, with their prompt and what blocks deleting them. */
    public function children(int $customerId, int $passageId, string $locale): Collection
    {
        $count = fn (string $table) => fn ($query) => $query->from($table)->selectRaw('count(*)')
            ->whereColumn($table.'.question_id', 'q.id')->where($table.'.customer_id', $customerId);

        return DB::table(AssessmentQuestionService::TABLE.' as q')
            ->leftJoin('core_assessment_question_contents as c', function ($join) use ($locale): void {
                $join->on('c.question_id', '=', 'q.id')->on('c.customer_id', '=', 'q.customer_id')->where('c.locale', '=', $locale);
            })
            ->where('q.customer_id', $customerId)
            ->where('q.passage_id', $passageId)
            ->select('q.*', 'c.prompt')
            ->selectSub($count('core_assessment_quiz_version_questions'), 'version_count')
            ->selectSub($count('core_assessment_quiz_questions'), 'quiz_count')
            ->orderBy('q.passage_sort_order')
            ->get();
    }

    /** Position for a new child: after the last one. Call inside a transaction that locked the passage. */
    public function nextSortOrder(int $customerId, int $passageId): int
    {
        return (int) DB::table(AssessmentQuestionService::TABLE)
            ->where('customer_id', $customerId)->where('passage_id', $passageId)->max('passage_sort_order') + 1;
    }

    /** Swaps a child with its neighbour, then renumbers 1..n so gaps left by deletions disappear. */
    public function moveChild(int $customerId, User $user, int $passageId, int $questionId, int $direction): void
    {
        DB::transaction(function () use ($customerId, $user, $passageId, $questionId, $direction): void {
            $this->find($customerId, $user, $passageId, true);
            $ids = DB::table(AssessmentQuestionService::TABLE)
                ->where('customer_id', $customerId)->where('passage_id', $passageId)
                ->orderBy('passage_sort_order')->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $index = array_search($questionId, $ids, true);
            abort_if($index === false, 404);
            $target = $index + ($direction < 0 ? -1 : 1);

            if ($target < 0 || $target >= count($ids)) {
                return;
            }

            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            $this->renumber($customerId, $passageId, $ids);
        });
    }

    /**
     * All or nothing. Deletes the passages with their child questions and releases every Media usage.
     *
     * @param  list<int>  $ids
     * @return array{name: string, reason: string, count: int}|null the first blocker, or null when deleted
     */
    public function deleteMany(int $customerId, User $user, array $ids): ?array
    {
        return DB::transaction(function () use ($customerId, $user, $ids): ?array {
            $passages = $this->scoped($customerId, $user)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'title']);
            abort_if($passages->count() !== count($ids), 404);

            foreach ($passages as $passage) {
                foreach ($this->usageCounts($customerId, (int) $passage->id) as $reason => $count) {
                    if ($count > 0) {
                        return ['name' => $passage->title, 'reason' => $reason, 'count' => $count];
                    }
                }
            }

            $childIds = DB::table(AssessmentQuestionService::TABLE)
                ->where('customer_id', $customerId)->whereIn('passage_id', $ids)->pluck('id')->map(fn ($id) => (int) $id);
            foreach ($childIds as $childId) {
                $this->media->detachAll('question', $customerId, $childId);
            }
            DB::table(AssessmentQuestionService::TABLE)->where('customer_id', $customerId)->whereIn('id', $childIds)->delete();

            foreach ($ids as $id) {
                $this->media->detachAll('passage', $customerId, $id);
            }
            DB::table(self::TABLE)->where('customer_id', $customerId)->whereIn('id', $ids)->delete();

            return null;
        });
    }

    /** @return array{versions: int, quizzes: int} how many quiz versions / quiz drafts use a child question */
    public function usageCounts(int $customerId, int $passageId): array
    {
        $children = DB::table(AssessmentQuestionService::TABLE)
            ->select('id')->where('customer_id', $customerId)->where('passage_id', $passageId);

        return [
            'versions' => DB::table('core_assessment_quiz_version_questions')
                ->where('customer_id', $customerId)->whereIn('question_id', $children)->distinct()->count('quiz_version_id'),
            'quizzes' => DB::table('core_assessment_quiz_questions')
                ->where('customer_id', $customerId)->whereIn('question_id', $children)->distinct()->count('quiz_id'),
        ];
    }

    /** @param  list<int>  $orderedIds */
    private function renumber(int $customerId, int $passageId, array $orderedIds): void
    {
        $table = DB::table(AssessmentQuestionService::TABLE)->where('customer_id', $customerId)->where('passage_id', $passageId);
        // Two passes so the unique (passage_id, passage_sort_order) never sees a duplicate in between.
        $offset = (int) (clone $table)->max('passage_sort_order') + count($orderedIds) + 1;
        foreach ($orderedIds as $position => $id) {
            (clone $table)->where('id', $id)->update(['passage_sort_order' => $offset + $position]);
        }
        foreach ($orderedIds as $position => $id) {
            (clone $table)->where('id', $id)->update(['passage_sort_order' => $position + 1, 'updated_at' => now()]);
        }
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
