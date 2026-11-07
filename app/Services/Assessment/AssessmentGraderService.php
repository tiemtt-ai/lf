<?php

namespace App\Services\Assessment;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who grades what (ADR-0027, Amendment 1.2). Teachers named on a quiz grade every attempt of it; they are named from the
 * quiz list, for one or many quizzes at once ("Giao chấm"). One teacher at a time may hold an attempt, taken by the teacher
 * ("Nhận chấm", or on saving a first score). Reading and grading rights themselves are applied by AssessmentGradingService.
 * Category scopes are no longer read or written; copyCategoryScopes turns the old ones into quiz scopes once.
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

    /** @return list<int> the quizzes a teacher is named on */
    public function coverage(int $customerId, int $userId): array
    {
        return DB::table(self::SCOPES_TABLE)->where('customer_id', $customerId)->where('user_id', $userId)->whereNotNull('quiz_id')
            ->pluck('quiz_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    // ---- Giáo viên chấm của đề ----

    /** @return Collection<int, object> teachers named on the quiz itself ({id, name, email}) */
    public function quizGraders(int $customerId, int $quizId): Collection
    {
        return DB::table(self::SCOPES_TABLE.' as gs')->join('users as u', 'u.id', '=', 'gs.user_id')
            ->where('gs.customer_id', $customerId)->where('gs.quiz_id', $quizId)->orderBy('u.name')
            ->get(['u.id', 'u.name', 'u.email']);
    }

    /** @param  list<int>  $quizIds  @return array<int, int> quiz id => number of teachers named on it */
    public function graderCounts(int $customerId, array $quizIds): array
    {
        if ($quizIds === []) {
            return [];
        }

        return DB::table(self::SCOPES_TABLE)->where('customer_id', $customerId)->whereIn('quiz_id', $quizIds)
            ->groupBy('quiz_id')->selectRaw('quiz_id, count(*) as total')->pluck('total', 'quiz_id')
            ->mapWithKeys(fn ($total, $quizId) => [(int) $quizId => (int) $total])->all();
    }

    /**
     * Quizzes with something to grade by hand: an essay / speaking question in the draft or in a published version. Only
     * these take graders; a quiz of auto-graded questions needs none.
     */
    public function whereNeedsGrading(Builder $query, int $customerId, string $quizColumn): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereExists(fn (Builder $draft) => $draft->selectRaw('1')->from(AssessmentQuizService::QUESTIONS_TABLE.' as gq')
                ->join(AssessmentQuestionService::TABLE.' as gqq', function ($join): void {
                    $join->on('gqq.id', '=', 'gq.question_id')->on('gqq.customer_id', '=', 'gq.customer_id');
                })
                ->whereColumn('gq.quiz_id', $quizColumn)->where('gq.customer_id', $customerId)
                ->whereIn('gqq.question_type', AssessmentQuestionService::GRADER_TYPES))
            ->orWhereExists(fn (Builder $version) => $version->selectRaw('1')->from(AssessmentQuizService::VERSIONS_TABLE.' as gv')
                ->join(AssessmentQuizPublishService::VERSION_QUESTIONS_TABLE.' as gvq', function ($join): void {
                    $join->on('gvq.quiz_version_id', '=', 'gv.id')->on('gvq.customer_id', '=', 'gv.customer_id');
                })
                ->whereColumn('gv.quiz_id', $quizColumn)->where('gv.customer_id', $customerId)
                ->where('gvq.requires_manual_grading', true)));
    }

    /**
     * @param  list<int>  $quizIds
     * @return list<int> those of them that need grading by hand
     */
    public function gradableQuizIds(int $customerId, array $quizIds): array
    {
        if ($quizIds === []) {
            return [];
        }

        return $this->whereNeedsGrading(DB::table(AssessmentQuizService::TABLE.' as gz')->where('gz.customer_id', $customerId)
            ->whereIn('gz.id', $quizIds), $customerId, 'gz.id')->orderBy('gz.id')->pluck('gz.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * "Giao chấm" from the quiz list: names the teachers on each chosen quiz the user manages. `add` keeps who is there
     * already; `replace` leaves exactly these (none = nobody). Quizzes the user may not manage, or with nothing to grade
     * by hand, are skipped and counted.
     *
     * @param  list<int>  $quizIds
     * @param  list<int>  $userIds
     * @return array{saved: int, skipped: int}
     */
    public function setQuizGraders(int $customerId, User $user, array $quizIds, array $userIds, string $mode): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $valid = $userIds === [] ? collect() : $this->teachers($customerId)->whereIn('id', $userIds)->pluck('id');
        if ($valid->count() !== count($userIds)) {
            throw ValidationException::withMessages(['user_ids' => __('lf.LF_assessment_grader_error_teacher')]);
        }
        if ($mode === 'add' && $userIds === []) {
            throw ValidationException::withMessages(['user_ids' => __('lf.LF_assessment_grader_choose_teacher')]);
        }
        $quizIds = array_values(array_unique(array_map('intval', $quizIds)));

        return DB::transaction(function () use ($customerId, $user, $quizIds, $userIds, $mode): array {
            // A quiz with nothing to grade by hand is skipped like one the user does not manage.
            $gradable = $this->gradableQuizIds($customerId, $quizIds);
            $quizzes = DB::table(AssessmentQuizService::TABLE)->where('customer_id', $customerId)->whereIn('id', $gradable)
                ->orderBy('id')->lockForUpdate()->get(['id', 'created_by']);
            $saved = 0;
            foreach ($quizzes as $quiz) {
                if (! $this->managesQuiz($user, $quiz)) {
                    continue;
                }
                $this->nameOnQuiz($customerId, (int) $user->id, (int) $quiz->id, $userIds, $mode === 'replace');
                $saved++;
            }

            return ['saved' => $saved, 'skipped' => count($quizIds) - $saved];
        });
    }

    /**
     * Amendment 1.2: each teacher named on a category (or a parent of it) is named on every quiz of it, so nobody loses a
     * right when category scopes stop counting. Idempotent; the category rows stay as history.
     *
     * @return array{categories: int, added: int}
     */
    public function copyCategoryScopes(?int $customerId = null, bool $dryRun = false): array
    {
        $rows = DB::table(self::SCOPES_TABLE)->whereNotNull('category_id')
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->orderBy('id')->get(['customer_id', 'category_id', 'user_id', 'created_by']);
        $added = 0;
        foreach ($rows->groupBy('customer_id') as $tenant => $scopes) {
            $tree = $this->tree((int) $tenant);
            foreach ($scopes as $scope) {
                $categories = $this->withDescendants((int) $tenant, [(int) $scope->category_id], $tree);
                $quizIds = DB::table(AssessmentQuizService::TABLE)->where('customer_id', $tenant)->whereIn('category_id', $categories)->pluck('id');
                $named = DB::table(self::SCOPES_TABLE)->where('customer_id', $tenant)->where('user_id', $scope->user_id)
                    ->whereIn('quiz_id', $quizIds)->pluck('quiz_id')->map(fn ($id) => (int) $id)->all();
                $missing = $quizIds->map(fn ($id) => (int) $id)->diff($named)->values();
                $added += $missing->count();
                if (! $dryRun && $missing->isNotEmpty()) {
                    $now = now();
                    DB::table(self::SCOPES_TABLE)->insert($missing->map(fn (int $quizId) => [
                        'customer_id' => (int) $tenant, 'quiz_id' => $quizId, 'user_id' => (int) $scope->user_id,
                        'created_by' => $scope->created_by, 'created_at' => $now, 'updated_at' => $now,
                    ])->all());
                }
            }
        }

        return ['categories' => $rows->count(), 'added' => $added];
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

    /** A teacher saved a score on a pending attempt nobody holds: it becomes theirs (call inside the grading transaction). */
    public function takeOnGrading(int $customerId, int $attemptId, int $teacherId): void
    {
        $this->open($customerId, $attemptId, $teacherId, $teacherId, 'claimed');
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
     * Teachers offered in the Giao chấm dialog: the least busy first. `covers` counts the chosen quizzes each one is
     * already named on; `load` the attempts they hold that still wait for grading.
     *
     * @param  list<int>  $quizIds
     * @return Collection<int, object> {id, name, email, covers, load}
     */
    public function suggestions(int $customerId, array $quizIds): Collection
    {
        $teachers = $this->teachers($customerId)->orderBy('name')->limit(1000)->get(['id', 'name', 'email']);
        $covers = DB::table(self::SCOPES_TABLE)->where('customer_id', $customerId)->whereIn('quiz_id', $quizIds)
            ->groupBy('user_id')->selectRaw('user_id, count(*) as total')->pluck('total', 'user_id');
        $load = DB::table(self::ASSIGNMENTS_TABLE.' as ga')->join(AssessmentAttemptService::TABLE.' as a', 'a.id', '=', 'ga.attempt_id')
            ->where('ga.customer_id', $customerId)->where('ga.status', 'active')->where('a.grading_status', 'pending')
            ->groupBy('ga.assigned_to_user_id')->selectRaw('ga.assigned_to_user_id as user_id, count(*) as total')->pluck('total', 'user_id');

        return $teachers->map(function (object $teacher) use ($covers, $load): object {
            $teacher->covers = (int) ($covers[$teacher->id] ?? 0);
            $teacher->load = (int) ($load[$teacher->id] ?? 0);

            return $teacher;
        })->sortBy([['load', 'asc'], ['name', 'asc']])->values();
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

    /** @param  list<int>  $userIds  checked teachers of the tenant */
    private function nameOnQuiz(int $customerId, int $byUserId, int $quizId, array $userIds, bool $replace): void
    {
        $current = DB::table(self::SCOPES_TABLE)->where('customer_id', $customerId)->where('quiz_id', $quizId)->pluck('user_id')->map(fn ($v) => (int) $v);
        if ($replace) {
            DB::table(self::SCOPES_TABLE)->where('customer_id', $customerId)->where('quiz_id', $quizId)->whereNotIn('user_id', $userIds)->delete();
        }
        $now = now();
        $new = collect($userIds)->diff($current)->map(fn (int $userId) => [
            'customer_id' => $customerId, 'quiz_id' => $quizId, 'user_id' => $userId, 'created_by' => $byUserId, 'created_at' => $now, 'updated_at' => $now,
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

    /** @return array<int, list<int>> parent id => child ids */
    private function tree(int $customerId): array
    {
        return DB::table(AssessmentCategoryService::TABLE)->where('customer_id', $customerId)
            ->whereNotNull('parent_id')->get(['id', 'parent_id'])
            ->groupBy('parent_id')->map(fn ($rows) => $rows->pluck('id')->map(fn ($id) => (int) $id)->all())->all();
    }
}
