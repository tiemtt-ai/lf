<?php

namespace App\Services\Assessment;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who grades what (ADR-0027). Teachers named on a category (inherited by its sub-categories) or on one quiz grade every
 * attempt there; one teacher at a time may hold an attempt, given by an admin or the quiz creator ("Giao chấm") or taken
 * by the teacher ("Nhận chấm"). Reading and grading rights themselves are applied by AssessmentGradingService.
 *
 * Users are checked against the tenant here: the foreign keys to `users` carry no customer_id.
 */
final class AssessmentGraderService
{
    public const SCOPES_TABLE = 'core_assessment_grader_scopes';

    public const ASSIGNMENTS_TABLE = 'core_assessment_grading_assignments';

    /** Active teachers of the tenant: the only people who can be named as graders or hold an attempt. */
    public function teachers(int $customerId): Builder
    {
        return DB::table('users')->where('customer_id', $customerId)->where('role', 'teacher')->where('status', 'active');
    }

    /**
     * What a teacher grades through scopes: the categories they are named on with all their sub-categories, and the
     * quizzes they are named on.
     *
     * @return array{categories: list<int>, quizzes: list<int>}
     */
    public function coverage(int $customerId, int $userId): array
    {
        $rows = DB::table(self::SCOPES_TABLE)->where('customer_id', $customerId)->where('user_id', $userId)->get(['category_id', 'quiz_id']);
        $categories = $rows->pluck('category_id')->filter()->map(fn ($id) => (int) $id)->all();

        return [
            'categories' => $categories === [] ? [] : $this->withDescendants($customerId, $categories),
            'quizzes' => $rows->pluck('quiz_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    // ---- Giáo viên chấm theo danh mục / theo đề ----

    /** @return Collection<int, Collection<int, object>> category id => teachers named on it directly ({id, name}) */
    public function categoryGraders(int $customerId): Collection
    {
        return DB::table(self::SCOPES_TABLE.' as gs')->join('users as u', 'u.id', '=', 'gs.user_id')
            ->where('gs.customer_id', $customerId)->whereNotNull('gs.category_id')->orderBy('u.name')
            ->get(['gs.category_id', 'u.id', 'u.name'])->groupBy('category_id');
    }

    /** @return Collection<int, object> teachers named on the quiz itself ({id, name, email}) */
    public function quizGraders(int $customerId, int $quizId): Collection
    {
        return DB::table(self::SCOPES_TABLE.' as gs')->join('users as u', 'u.id', '=', 'gs.user_id')
            ->where('gs.customer_id', $customerId)->where('gs.quiz_id', $quizId)->orderBy('u.name')
            ->get(['u.id', 'u.name', 'u.email']);
    }

    /**
     * Teachers who already grade the quiz through its category or a parent category, with the category that names them.
     *
     * @return Collection<int, object> {id, name, category_name}
     */
    public function inheritedQuizGraders(int $customerId, ?int $categoryId): Collection
    {
        if (! $categoryId) {
            return collect();
        }
        $chain = $this->ancestorsOrSelf($customerId, $categoryId);

        return DB::table(self::SCOPES_TABLE.' as gs')->join('users as u', 'u.id', '=', 'gs.user_id')
            ->join(AssessmentCategoryService::TABLE.' as c', 'c.id', '=', 'gs.category_id')
            ->where('gs.customer_id', $customerId)->whereIn('gs.category_id', $chain)->orderBy('u.name')
            ->get(['u.id', 'u.name', 'c.name as category_name'])->unique('id')->values();
    }

    /** Replaces the teachers named on a category. Admin only (the route sits in the admin-only category group). */
    public function setCategoryGraders(int $customerId, User $user, int $categoryId, array $userIds): void
    {
        abort_unless($user->role === 'customer_admin', 403);
        DB::transaction(function () use ($customerId, $user, $categoryId, $userIds): void {
            $category = DB::table(AssessmentCategoryService::TABLE)->where('customer_id', $customerId)->where('id', $categoryId)->lockForUpdate()->first();
            abort_if(! $category, 404);
            $this->replaceScope($customerId, $user, 'category_id', $categoryId, $userIds);
        });
    }

    /** Replaces the teachers named on a quiz ("Giao chấm đề"): the admin, or the teacher who created the quiz. */
    public function setQuizGraders(int $customerId, User $user, int $quizId, array $userIds): void
    {
        DB::transaction(function () use ($customerId, $user, $quizId, $userIds): void {
            $quiz = DB::table(AssessmentQuizService::TABLE)->where('customer_id', $customerId)->where('id', $quizId)->lockForUpdate()->first();
            abort_if(! $quiz || ! $this->managesQuiz($user, $quiz), 404);
            $this->replaceScope($customerId, $user, 'quiz_id', $quizId, $userIds);
        });
    }

    // ---- Giao chấm / Nhận chấm / Trả lại ----

    /** Admins manage every quiz; a teacher the quizzes they created. */
    public function managesQuiz(User $user, object $quiz): bool
    {
        return $user->role === 'customer_admin' || (int) $quiz->created_by === (int) $user->id;
    }

    /**
     * The teacher holding each attempt now, and the last one who held it to completion when nobody holds it.
     *
     * @param  list<int>  $attemptIds
     * @return Collection<int, object> attempt id => {attempt_id, user_id, name, status, assignment_type, assigned_by_name, created_at}
     */
    public function holders(int $customerId, array $attemptIds): Collection
    {
        if ($attemptIds === []) {
            return collect();
        }

        return DB::table(self::ASSIGNMENTS_TABLE.' as ga')->join('users as u', 'u.id', '=', 'ga.assigned_to_user_id')
            ->leftJoin('users as b', 'b.id', '=', 'ga.assigned_by_user_id')
            ->where('ga.customer_id', $customerId)->whereIn('ga.attempt_id', $attemptIds)->whereIn('ga.status', ['active', 'completed'])
            ->orderBy('ga.id')
            ->get(['ga.attempt_id', 'ga.assigned_to_user_id as user_id', 'u.name', 'ga.status', 'ga.assignment_type', 'b.name as assigned_by_name', 'ga.created_at'])
            ->keyBy('attempt_id');
    }

    /** The teacher who holds the attempt now, or null. */
    public function activeHolder(int $customerId, int $attemptId): ?object
    {
        return DB::table(self::ASSIGNMENTS_TABLE.' as ga')->join('users as u', 'u.id', '=', 'ga.assigned_to_user_id')
            ->where('ga.customer_id', $customerId)->where('ga.attempt_id', $attemptId)->where('ga.status', 'active')
            ->first(['ga.id', 'ga.assigned_to_user_id as user_id', 'u.name']);
    }

    /**
     * Gives attempts to one teacher. Attempts this user may not hand out (another teacher's quiz, still in progress, other
     * tenant) are skipped and counted, never an error, so a mixed selection still does what it can.
     *
     * @param  list<int>  $attemptIds
     * @return array{assigned: int, skipped: int}
     */
    public function assign(int $customerId, User $user, array $attemptIds, int $teacherId): array
    {
        $teacher = $this->teachers($customerId)->where('id', $teacherId)->first(['id']);
        if (! $teacher) {
            throw ValidationException::withMessages(['teacher_id' => __('lf.LF_assessment_grader_error_teacher')]);
        }

        return DB::transaction(function () use ($customerId, $user, $attemptIds, $teacherId): array {
            $attempts = DB::table(AssessmentAttemptService::TABLE.' as a')
                ->join(AssessmentQuizService::TABLE.' as z', function ($join): void {
                    $join->on('z.id', '=', 'a.quiz_id')->on('z.customer_id', '=', 'a.customer_id');
                })
                ->where('a.customer_id', $customerId)->whereIn('a.id', $attemptIds)->orderBy('a.id')->lockForUpdate()
                ->get(['a.id', 'a.status', 'z.created_by']);
            $assigned = 0;

            foreach ($attempts as $attempt) {
                if ($attempt->status === 'in_progress' || ! $this->managesQuiz($user, $attempt)) {
                    continue;
                }
                $holder = $this->activeHolder($customerId, (int) $attempt->id);
                if ($holder && (int) $holder->user_id === $teacherId) {
                    $assigned++;

                    continue;
                }
                $this->endActive($customerId, (int) $attempt->id, 'released', (int) $user->id);
                $this->open($customerId, (int) $attempt->id, $teacherId, (int) $user->id, 'assigned');
                $assigned++;
            }

            return ['assigned' => $assigned, 'skipped' => count(array_unique($attemptIds)) - $assigned];
        });
    }

    /** "Nhận chấm": a teacher who may see the attempt takes it while nobody holds it. The caller has checked the view right. */
    public function claim(int $customerId, User $user, int $attemptId): void
    {
        abort_unless($user->role === 'teacher', 403);
        DB::transaction(function () use ($customerId, $user, $attemptId): void {
            $attempt = $this->lockAttempt($customerId, $attemptId);
            if ($attempt->grading_status !== 'pending' || $attempt->status === 'in_progress') {
                throw ValidationException::withMessages(['assignment' => __('lf.LF_assessment_grader_error_not_pending')]);
            }
            $holder = $this->activeHolder($customerId, $attemptId);
            if ($holder) {
                if ((int) $holder->user_id === (int) $user->id) {
                    return;
                }
                throw ValidationException::withMessages(['assignment' => __('lf.LF_assessment_grader_error_held', ['name' => $holder->name])]);
            }
            $this->open($customerId, $attemptId, (int) $user->id, (int) $user->id, 'claimed');
        });
    }

    /** "Trả lại": the holder, or whoever may hand the attempt out, frees it. */
    public function release(int $customerId, User $user, int $attemptId): void
    {
        DB::transaction(function () use ($customerId, $user, $attemptId): void {
            $attempt = $this->lockAttempt($customerId, $attemptId);
            $holder = $this->activeHolder($customerId, $attemptId);
            if (! $holder) {
                return;
            }
            abort_unless((int) $holder->user_id === (int) $user->id || $this->managesQuiz($user, $attempt), 403);
            $this->endActive($customerId, $attemptId, 'released', (int) $user->id);
        });
    }

    /** The attempt is fully graded: whoever held it finished (call inside the grading transaction). */
    public function complete(int $customerId, int $attemptId): void
    {
        $this->endActive($customerId, $attemptId, 'completed', null);
    }

    /**
     * Teachers offered in the Giao chấm dialog, best first: those who already grade the chosen attempts (category or quiz),
     * then the least busy. `covers` counts the chosen attempts each one grades; `load` the attempts they hold that still
     * wait for grading.
     *
     * @param  list<int>  $attemptIds
     * @return Collection<int, object> {id, name, email, covers, load}
     */
    public function suggestions(int $customerId, array $attemptIds): Collection
    {
        $teachers = $this->teachers($customerId)->orderBy('name')->limit(1000)->get(['id', 'name', 'email']);
        $quizzes = DB::table(AssessmentAttemptService::TABLE.' as a')->join(AssessmentQuizService::TABLE.' as z', 'z.id', '=', 'a.quiz_id')
            ->where('a.customer_id', $customerId)->whereIn('a.id', $attemptIds)->get(['a.quiz_id', 'z.category_id']);
        $scopes = DB::table(self::SCOPES_TABLE)->where('customer_id', $customerId)->whereIn('user_id', $teachers->pluck('id'))
            ->get(['user_id', 'category_id', 'quiz_id'])->groupBy('user_id');
        $load = DB::table(self::ASSIGNMENTS_TABLE.' as ga')->join(AssessmentAttemptService::TABLE.' as a', 'a.id', '=', 'ga.attempt_id')
            ->where('ga.customer_id', $customerId)->where('ga.status', 'active')->where('a.grading_status', 'pending')
            ->groupBy('ga.assigned_to_user_id')->selectRaw('ga.assigned_to_user_id as user_id, count(*) as total')->pluck('total', 'user_id');

        $tree = $this->tree($customerId);

        return $teachers->map(function (object $teacher) use ($customerId, $quizzes, $scopes, $load, $tree): object {
            $own = $scopes->get($teacher->id, collect());
            $categories = $this->withDescendants($customerId, $own->pluck('category_id')->filter()->map(fn ($id) => (int) $id)->all(), $tree);
            $quizIds = $own->pluck('quiz_id')->filter()->map(fn ($id) => (int) $id)->all();
            $teacher->covers = $quizzes->filter(fn (object $row) => in_array((int) $row->quiz_id, $quizIds, true)
                || ($row->category_id !== null && in_array((int) $row->category_id, $categories, true)))->count();
            $teacher->load = (int) ($load[$teacher->id] ?? 0);

            return $teacher;
        })->sortBy([['covers', 'desc'], ['load', 'asc'], ['name', 'asc']])->values();
    }

    // ---- internals ----

    private function open(int $customerId, int $attemptId, int $teacherId, int $byUserId, string $type): void
    {
        DB::table(self::ASSIGNMENTS_TABLE)->insert([
            'customer_id' => $customerId,
            'attempt_id' => $attemptId,
            'assigned_to_user_id' => $teacherId,
            'assigned_by_user_id' => $byUserId,
            'assignment_type' => $type,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function endActive(int $customerId, int $attemptId, string $status, ?int $byUserId): void
    {
        DB::table(self::ASSIGNMENTS_TABLE)->where('customer_id', $customerId)->where('attempt_id', $attemptId)->where('status', 'active')
            ->update(['status' => $status, 'ended_at' => now(), 'ended_by_user_id' => $byUserId, 'updated_at' => now()]);
    }

    /** The attempt (with its quiz creator), locked so two people cannot take it at once. */
    private function lockAttempt(int $customerId, int $attemptId): object
    {
        $attempt = DB::table(AssessmentAttemptService::TABLE.' as a')
            ->join(AssessmentQuizService::TABLE.' as z', function ($join): void {
                $join->on('z.id', '=', 'a.quiz_id')->on('z.customer_id', '=', 'a.customer_id');
            })
            ->where('a.customer_id', $customerId)->where('a.id', $attemptId)->lockForUpdate()
            ->first(['a.id', 'a.status', 'a.grading_status', 'z.created_by']);
        abort_if(! $attempt, 404);

        return $attempt;
    }

    /** @param  list<int>  $userIds */
    private function replaceScope(int $customerId, User $user, string $column, int $id, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $valid = $userIds === [] ? collect() : $this->teachers($customerId)->whereIn('id', $userIds)->pluck('id')->map(fn ($v) => (int) $v);
        if ($valid->count() !== count($userIds)) {
            throw ValidationException::withMessages(['user_ids' => __('lf.LF_assessment_grader_error_teacher')]);
        }

        $current = DB::table(self::SCOPES_TABLE)->where('customer_id', $customerId)->where($column, $id)->pluck('user_id')->map(fn ($v) => (int) $v);
        DB::table(self::SCOPES_TABLE)->where('customer_id', $customerId)->where($column, $id)->whereNotIn('user_id', $userIds)->delete();
        $now = now();
        $new = collect($userIds)->diff($current)->map(fn (int $userId) => [
            'customer_id' => $customerId, $column => $id, 'user_id' => $userId, 'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($new->isNotEmpty()) {
            DB::table(self::SCOPES_TABLE)->insert($new->values()->all());
        }
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, list<int>>|null  $tree  parent id => child ids, when the caller already has it
     * @return list<int>
     */
    private function withDescendants(int $customerId, array $ids, ?array $tree = null): array
    {
        if ($ids === []) {
            return [];
        }
        $children = $tree ?? $this->tree($customerId);
        $found = [];
        $queue = $ids;
        while ($queue !== []) {
            $id = array_pop($queue);
            if (isset($found[$id])) {
                continue;
            }
            $found[$id] = true;
            array_push($queue, ...($children[$id] ?? []));
        }

        return array_keys($found);
    }

    /** @return list<int> the category and its parents, nearest first */
    private function ancestorsOrSelf(int $customerId, int $categoryId): array
    {
        $parents = DB::table(AssessmentCategoryService::TABLE)->where('customer_id', $customerId)->pluck('parent_id', 'id');
        $chain = [];
        for ($id = $categoryId; $id && ! in_array($id, $chain, true); $id = (int) ($parents[$id] ?? 0)) {
            $chain[] = $id;
        }

        return $chain;
    }

    /** @return array<int, list<int>> parent id => child ids */
    private function tree(int $customerId): array
    {
        return DB::table(AssessmentCategoryService::TABLE)->where('customer_id', $customerId)
            ->whereNotNull('parent_id')->get(['id', 'parent_id'])
            ->groupBy('parent_id')->map(fn ($rows) => $rows->pluck('id')->map(fn ($id) => (int) $id)->all())->all();
    }
}
