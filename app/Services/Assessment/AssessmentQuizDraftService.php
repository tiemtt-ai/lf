<?php

namespace App\Services\Assessment;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The editable draft of a quiz (ADR-0025 D6): its sections and the questions chosen into them, with points and order.
 * Composition is manual: single questions, or a passage that brings its published children in passage order.
 *
 * Every change locks the quiz row first and marks the draft changed (content_updated_at); the published versions never
 * change. Only questions and passages this user may see can be added (teachers: their own; admins: the tenant).
 */
final class AssessmentQuizDraftService
{
    public function __construct(
        private readonly AssessmentQuizService $quizzes,
        private readonly AssessmentQuestionService $questions,
        private readonly AssessmentPassageService $passages,
    ) {}

    /** @return Collection<int, object> sections in order */
    public function sections(int $customerId, int $quizId): Collection
    {
        return DB::table(AssessmentQuizService::SECTIONS_TABLE)
            ->where('customer_id', $customerId)->where('quiz_id', $quizId)->orderBy('sort_order')->get();
    }

    /** Chosen questions in section order then question order, with what the page shows of each. */
    public function items(int $customerId, int $quizId, string $locale): Collection
    {
        return DB::table(AssessmentQuizService::QUESTIONS_TABLE.' as qq')
            ->join(AssessmentQuizService::SECTIONS_TABLE.' as s', function ($join): void {
                $join->on('s.id', '=', 'qq.quiz_section_id')->on('s.customer_id', '=', 'qq.customer_id');
            })
            ->join(AssessmentQuestionService::TABLE.' as q', function ($join): void {
                $join->on('q.id', '=', 'qq.question_id')->on('q.customer_id', '=', 'qq.customer_id');
            })
            ->leftJoin('core_assessment_question_contents as c', function ($join) use ($locale): void {
                $join->on('c.question_id', '=', 'q.id')->on('c.customer_id', '=', 'q.customer_id')->where('c.locale', '=', $locale);
            })
            ->leftJoin(AssessmentPassageService::TABLE.' as p', function ($join): void {
                $join->on('p.id', '=', 'q.passage_id')->on('p.customer_id', '=', 'q.customer_id');
            })
            ->where('qq.customer_id', $customerId)
            ->where('qq.quiz_id', $quizId)
            ->orderBy('s.sort_order')
            ->orderBy('qq.sort_order')
            ->get([
                'qq.*', 'q.question_type', 'q.status as question_status', 'q.passage_id', 'c.prompt',
                'p.title as passage_title', 'p.status as passage_status',
            ]);
    }

    /** @param  array{title: string, description: string|null, skill_type: string|null}  $data */
    public function saveSection(int $customerId, User $user, int $quizId, ?int $sectionId, array $data): int
    {
        return DB::transaction(function () use ($customerId, $user, $quizId, $sectionId, $data): int {
            $this->lockQuiz($customerId, $user, $quizId);
            $values = $data + ['updated_at' => now()];

            if ($sectionId !== null) {
                $this->section($customerId, $quizId, $sectionId);
                DB::table(AssessmentQuizService::SECTIONS_TABLE)->where('customer_id', $customerId)->where('id', $sectionId)->update($values);
                $this->touch($customerId, $quizId);

                return $sectionId;
            }

            $next = (int) DB::table(AssessmentQuizService::SECTIONS_TABLE)
                ->where('customer_id', $customerId)->where('quiz_id', $quizId)->max('sort_order') + 1;
            $id = DB::table(AssessmentQuizService::SECTIONS_TABLE)->insertGetId($values + [
                'customer_id' => $customerId, 'quiz_id' => $quizId, 'sort_order' => $next, 'created_at' => now(),
            ]);
            $this->touch($customerId, $quizId);

            return $id;
        });
    }

    /** A section is removed only while empty, and a quiz always keeps one. */
    public function deleteSection(int $customerId, User $user, int $quizId, int $sectionId): void
    {
        DB::transaction(function () use ($customerId, $user, $quizId, $sectionId): void {
            $this->lockQuiz($customerId, $user, $quizId);
            $this->section($customerId, $quizId, $sectionId);
            $sections = $this->sections($customerId, $quizId);

            if ($sections->count() <= 1) {
                throw ValidationException::withMessages(['section' => __('lf.LF_assessment_quiz_section_error_last')]);
            }
            if (DB::table(AssessmentQuizService::QUESTIONS_TABLE)->where('customer_id', $customerId)->where('quiz_section_id', $sectionId)->exists()) {
                throw ValidationException::withMessages(['section' => __('lf.LF_assessment_quiz_section_error_not_empty')]);
            }

            DB::table(AssessmentQuizService::SECTIONS_TABLE)->where('customer_id', $customerId)->where('id', $sectionId)->delete();
            $this->renumber(AssessmentQuizService::SECTIONS_TABLE, $customerId, ['quiz_id' => $quizId],
                $sections->pluck('id')->map(fn ($id) => (int) $id)->reject(fn (int $id) => $id === $sectionId)->values()->all());
            $this->touch($customerId, $quizId);
        });
    }

    public function moveSection(int $customerId, User $user, int $quizId, int $sectionId, int $direction): void
    {
        DB::transaction(function () use ($customerId, $user, $quizId, $sectionId, $direction): void {
            $this->lockQuiz($customerId, $user, $quizId);
            $ids = $this->sections($customerId, $quizId)->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($this->swap($ids, $sectionId, $direction)) {
                $this->renumber(AssessmentQuizService::SECTIONS_TABLE, $customerId, ['quiz_id' => $quizId], $ids);
                $this->touch($customerId, $quizId);
            }
        });
    }

    /**
     * Adds single questions and whole passages (their published children, in passage order) at the end of a section.
     * Questions already in the quiz are skipped, so a passage can be added again to bring back a removed child.
     *
     * @param  list<int>  $questionIds
     * @param  list<int>  $passageIds
     * @return int how many questions were added
     */
    public function addQuestions(int $customerId, User $user, int $quizId, int $sectionId, array $questionIds, array $passageIds): int
    {
        return DB::transaction(function () use ($customerId, $user, $quizId, $sectionId, $questionIds, $passageIds): int {
            $this->lockQuiz($customerId, $user, $quizId);
            $this->section($customerId, $quizId, $sectionId);

            $passages = $passageIds === [] ? collect() : $this->passages->scoped($customerId, $user)->whereIn('id', $passageIds)->pluck('id');
            abort_if($passages->count() !== count($passageIds), 404);
            $picked = $questionIds === [] ? collect() : $this->questions->scoped($customerId, $user, 'q')->whereIn('q.id', $questionIds)
                ->get(['q.id', 'q.status', 'q.default_points']);
            abort_if($picked->count() !== count($questionIds), 404);

            if ($picked->contains(fn (object $question) => $question->status !== 'published')) {
                throw ValidationException::withMessages(['question_ids' => __('lf.LF_assessment_quiz_add_error_not_ready')]);
            }

            // Passage children follow the passage order; a passage only brings what is ready.
            $children = $passages->isEmpty() ? collect() : DB::table(AssessmentQuestionService::TABLE)
                ->where('customer_id', $customerId)->whereIn('passage_id', $passages)->where('status', 'published')
                ->orderByRaw('CASE '.$passages->values()->map(fn ($id, $i) => 'WHEN passage_id = '.(int) $id.' THEN '.$i)->implode(' ').' END')
                ->orderBy('passage_sort_order')
                ->get(['id', 'default_points']);

            $existing = DB::table(AssessmentQuizService::QUESTIONS_TABLE)->where('customer_id', $customerId)->where('quiz_id', $quizId)
                ->pluck('question_id')->map(fn ($id) => (int) $id)->flip();
            $toAdd = $picked->concat($children)->unique('id')->reject(fn (object $question) => $existing->has((int) $question->id))->values();

            if ($toAdd->isEmpty()) {
                throw ValidationException::withMessages(['question_ids' => __('lf.LF_assessment_quiz_add_error_nothing')]);
            }

            $next = (int) DB::table(AssessmentQuizService::QUESTIONS_TABLE)
                ->where('customer_id', $customerId)->where('quiz_section_id', $sectionId)->max('sort_order');
            $now = now();
            DB::table(AssessmentQuizService::QUESTIONS_TABLE)->insert($toAdd->map(fn (object $question, int $i) => [
                'customer_id' => $customerId,
                'quiz_id' => $quizId,
                'quiz_section_id' => $sectionId,
                'question_id' => (int) $question->id,
                'sort_order' => $next + $i + 1,
                'points' => $question->default_points,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
            $this->touch($customerId, $quizId);

            return $toAdd->count();
        });
    }

    /** Changes the points of a chosen question and, when another section is picked, moves it to that section's end. */
    public function updateItem(int $customerId, User $user, int $quizId, int $itemId, string $points, int $sectionId): void
    {
        DB::transaction(function () use ($customerId, $user, $quizId, $itemId, $points, $sectionId): void {
            $this->lockQuiz($customerId, $user, $quizId);
            $item = $this->item($customerId, $quizId, $itemId);
            $this->section($customerId, $quizId, $sectionId);
            $values = ['points' => $points, 'updated_at' => now()];

            if ((int) $item->quiz_section_id !== $sectionId) {
                $values['quiz_section_id'] = $sectionId;
                $values['sort_order'] = (int) DB::table(AssessmentQuizService::QUESTIONS_TABLE)
                    ->where('customer_id', $customerId)->where('quiz_section_id', $sectionId)->max('sort_order') + 1;
            }

            DB::table(AssessmentQuizService::QUESTIONS_TABLE)->where('customer_id', $customerId)->where('id', $itemId)->update($values);

            if (isset($values['quiz_section_id'])) {
                $this->renumberSection($customerId, (int) $item->quiz_section_id);
            }
            $this->touch($customerId, $quizId);
        });
    }

    public function moveItem(int $customerId, User $user, int $quizId, int $itemId, int $direction): void
    {
        DB::transaction(function () use ($customerId, $user, $quizId, $itemId, $direction): void {
            $this->lockQuiz($customerId, $user, $quizId);
            $item = $this->item($customerId, $quizId, $itemId);
            $ids = $this->sectionItemIds($customerId, (int) $item->quiz_section_id);

            if ($this->swap($ids, $itemId, $direction)) {
                $this->renumber(AssessmentQuizService::QUESTIONS_TABLE, $customerId, ['quiz_section_id' => (int) $item->quiz_section_id], $ids);
                $this->touch($customerId, $quizId);
            }
        });
    }

    /**
     * Takes questions out of the draft; the questions stay in the bank.
     *
     * @param  list<int>  $itemIds
     */
    public function removeItems(int $customerId, User $user, int $quizId, array $itemIds): void
    {
        DB::transaction(function () use ($customerId, $user, $quizId, $itemIds): void {
            $this->lockQuiz($customerId, $user, $quizId);
            $items = DB::table(AssessmentQuizService::QUESTIONS_TABLE)
                ->where('customer_id', $customerId)->where('quiz_id', $quizId)->whereIn('id', $itemIds)->get(['id', 'quiz_section_id']);
            abort_if($items->count() !== count($itemIds), 404);

            DB::table(AssessmentQuizService::QUESTIONS_TABLE)->where('customer_id', $customerId)->whereIn('id', $itemIds)->delete();
            foreach ($items->pluck('quiz_section_id')->unique() as $sectionId) {
                $this->renumberSection($customerId, (int) $sectionId);
            }
            $this->touch($customerId, $quizId);
        });
    }

    /** Published questions this user may add (not yet in the quiz), newest first. */
    public function availableQuestions(int $customerId, User $user, int $quizId, string $locale, int $limit = 500): Collection
    {
        return $this->questions->scoped($customerId, $user, 'q')
            ->leftJoin('core_assessment_question_contents as c', function ($join) use ($locale): void {
                $join->on('c.question_id', '=', 'q.id')->on('c.customer_id', '=', 'q.customer_id')->where('c.locale', '=', $locale);
            })
            ->leftJoin(AssessmentCategoryService::TABLE.' as cat', function ($join): void {
                $join->on('cat.id', '=', 'q.category_id')->on('cat.customer_id', '=', 'q.customer_id');
            })
            ->where('q.status', 'published')
            ->whereNull('q.passage_id')
            ->whereNotIn('q.id', $this->chosenQuestionIds($customerId, $quizId))
            ->orderByDesc('q.created_at')->orderByDesc('q.id')
            ->limit($limit)
            ->get(['q.id', 'q.question_type', 'q.default_points', 'c.prompt', 'cat.name as category_name']);
    }

    /** Published passages this user may add that still have a ready child outside the quiz. */
    public function availablePassages(int $customerId, User $user, int $quizId, int $limit = 300): Collection
    {
        $chosen = $this->chosenQuestionIds($customerId, $quizId);

        return $this->passages->scoped($customerId, $user, 'p')
            ->leftJoin(AssessmentCategoryService::TABLE.' as cat', function ($join): void {
                $join->on('cat.id', '=', 'p.category_id')->on('cat.customer_id', '=', 'p.customer_id');
            })
            ->where('p.status', 'published')
            ->select('p.id', 'p.title', 'p.skill_type', 'cat.name as category_name')
            ->selectSub(fn ($query) => $query->from(AssessmentQuestionService::TABLE.' as child')->selectRaw('count(*)')
                ->whereColumn('child.passage_id', 'p.id')->where('child.customer_id', $customerId)
                ->where('child.status', 'published')->whereNotIn('child.id', $chosen), 'ready_count')
            ->orderByDesc('p.created_at')->orderByDesc('p.id')
            ->limit($limit)
            ->get()
            ->filter(fn (object $passage) => (int) $passage->ready_count > 0)
            ->values();
    }

    public function item(int $customerId, int $quizId, int $itemId): object
    {
        $item = DB::table(AssessmentQuizService::QUESTIONS_TABLE)
            ->where('customer_id', $customerId)->where('quiz_id', $quizId)->where('id', $itemId)->first();
        abort_if(! $item, 404);

        return $item;
    }

    public function section(int $customerId, int $quizId, int $sectionId): object
    {
        $section = DB::table(AssessmentQuizService::SECTIONS_TABLE)
            ->where('customer_id', $customerId)->where('quiz_id', $quizId)->where('id', $sectionId)->first();
        abort_if(! $section, 404);

        return $section;
    }

    private function lockQuiz(int $customerId, User $user, int $quizId): object
    {
        return $this->quizzes->find($customerId, $user, $quizId, true);
    }

    private function touch(int $customerId, int $quizId): void
    {
        DB::table(AssessmentQuizService::TABLE)->where('customer_id', $customerId)->where('id', $quizId)
            ->update(['content_updated_at' => now(), 'updated_at' => now()]);
    }

    private function chosenQuestionIds(int $customerId, int $quizId): Builder
    {
        return DB::table(AssessmentQuizService::QUESTIONS_TABLE)->select('question_id')
            ->where('customer_id', $customerId)->where('quiz_id', $quizId);
    }

    /** @return list<int> */
    private function sectionItemIds(int $customerId, int $sectionId): array
    {
        return DB::table(AssessmentQuizService::QUESTIONS_TABLE)->where('customer_id', $customerId)->where('quiz_section_id', $sectionId)
            ->orderBy('sort_order')->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function renumberSection(int $customerId, int $sectionId): void
    {
        $this->renumber(AssessmentQuizService::QUESTIONS_TABLE, $customerId, ['quiz_section_id' => $sectionId],
            $this->sectionItemIds($customerId, $sectionId));
    }

    /** @param  list<int>  $ids  swapped in place with the neighbour; false at the edges */
    private function swap(array &$ids, int $id, int $direction): bool
    {
        $index = array_search($id, $ids, true);
        abort_if($index === false, 404);
        $target = $index + ($direction < 0 ? -1 : 1);

        if ($target < 0 || $target >= count($ids)) {
            return false;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        return true;
    }

    /**
     * Writes sort_order 1..n in the given order. Two passes so the unique sort_order never sees a duplicate in between.
     *
     * @param  array<string, int>  $scope
     * @param  list<int>  $orderedIds
     */
    private function renumber(string $table, int $customerId, array $scope, array $orderedIds): void
    {
        $query = DB::table($table)->where('customer_id', $customerId)->where($scope);
        $offset = (int) (clone $query)->max('sort_order') + count($orderedIds) + 1;
        foreach ($orderedIds as $position => $id) {
            (clone $query)->where('id', $id)->update(['sort_order' => $offset + $position]);
        }
        foreach ($orderedIds as $position => $id) {
            (clone $query)->where('id', $id)->update(['sort_order' => $position + 1, 'updated_at' => now()]);
        }
    }
}
