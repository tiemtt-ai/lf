<?php

namespace App\Services\Assessment;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tiêu chí chấm (ADR-0026): reusable rubrics for essay / speaking questions, their criteria, the snapshot frozen into
 * a published version question and the score of a criterion grading.
 *
 * Admins see and edit every rubric of the tenant. A teacher edits their own and may also pick the rubrics an admin
 * created (the centre's standard); other teachers' rubrics stay invisible.
 */
final class AssessmentRubricService
{
    public const TABLE = 'core_assessment_rubrics';

    public const ITEMS_TABLE = 'core_assessment_rubric_items';

    public const STATUSES = ['active', 'archived'];

    public const MAX_ITEMS = 20;

    /**
     * @param  array{title: string, description: string|null, skill_type: string|null, status: string,
     *     items: list<array{title: string, description: string|null, max_points: string}>}  $data
     */
    public function save(int $customerId, User $user, ?int $rubricId, array $data): int
    {
        if ($data['items'] === []) {
            throw ValidationException::withMessages(['items' => __('lf.LF_assessment_rubric_error_items')]);
        }

        return DB::transaction(function () use ($customerId, $user, $rubricId, $data): int {
            if ($rubricId !== null) {
                abort_if(! $this->editable($customerId, $user)->where('id', $rubricId)->lockForUpdate()->exists(), 404);
            }

            $now = now();
            $values = [
                'title' => $data['title'],
                'description' => $data['description'],
                'skill_type' => $data['skill_type'],
                'total_points' => number_format(array_sum(array_map(fn (array $item) => (float) $item['max_points'], $data['items'])), 2, '.', ''),
                'status' => $data['status'],
                'updated_by' => $user->id,
                'updated_at' => $now,
            ];

            if ($rubricId === null) {
                $rubricId = DB::table(self::TABLE)->insertGetId($values + [
                    'customer_id' => $customerId,
                    'created_by' => $user->id,
                    'created_at' => $now,
                ]);
            } else {
                DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $rubricId)->update($values);
            }

            // Nothing references a criterion row (snapshots and results use the position), so they are rewritten whole.
            DB::table(self::ITEMS_TABLE)->where('customer_id', $customerId)->where('rubric_id', $rubricId)->delete();
            foreach ($data['items'] as $index => $item) {
                DB::table(self::ITEMS_TABLE)->insert([
                    'customer_id' => $customerId,
                    'rubric_id' => $rubricId,
                    'title' => $item['title'],
                    'description' => $item['description'],
                    'max_points' => $item['max_points'],
                    'sort_order' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $rubricId;
        });
    }

    public function toggleStatus(int $customerId, User $user, int $rubricId): string
    {
        return DB::transaction(function () use ($customerId, $user, $rubricId): string {
            $rubric = $this->editable($customerId, $user)->where('id', $rubricId)->lockForUpdate()->first();
            abort_if(! $rubric, 404);
            $status = $rubric->status === 'active' ? 'archived' : 'active';
            DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $rubricId)
                ->update(['status' => $status, 'updated_by' => $user->id, 'updated_at' => now()]);

            return $status;
        });
    }

    /**
     * All or nothing. Returns null when deleted, otherwise the first rubric still chosen on a question.
     *
     * @param  list<int>  $ids
     * @return array{title: string, count: int}|null
     */
    public function deleteMany(int $customerId, User $user, array $ids): ?array
    {
        return DB::transaction(function () use ($customerId, $user, $ids): ?array {
            $rubrics = $this->editable($customerId, $user)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'title']);
            abort_if($rubrics->count() !== count($ids), 404);

            foreach ($rubrics as $rubric) {
                $count = $this->questionCount($customerId, (int) $rubric->id);
                if ($count > 0) {
                    return ['title' => (string) $rubric->title, 'count' => $count];
                }
            }

            DB::table(self::TABLE)->where('customer_id', $customerId)->whereIn('id', $ids)->delete();

            return null;
        });
    }

    public function questionCount(int $customerId, int $rubricId): int
    {
        return DB::table(AssessmentQuestionService::TABLE)->where('customer_id', $customerId)->where('rubric_id', $rubricId)->count();
    }

    /** Rubrics this user may open and edit: all of the tenant for an admin, their own for a teacher. */
    public function editable(int $customerId, User $user, string $alias = ''): Builder
    {
        $prefix = $alias === '' ? '' : $alias.'.';

        return DB::table(self::TABLE.($alias === '' ? '' : ' as '.$alias))
            ->where($prefix.'customer_id', $customerId)
            ->when($user->role !== 'customer_admin', fn ($query) => $query->where($prefix.'created_by', $user->id));
    }

    /** Rubrics this user may choose on a question: editable ones plus, for a teacher, those an admin created. */
    public function usable(int $customerId, User $user, string $alias = 'r'): Builder
    {
        return DB::table(self::TABLE.' as '.$alias)
            ->where($alias.'.customer_id', $customerId)
            ->when($user->role !== 'customer_admin', fn ($query) => $query->where(fn ($query) => $query
                ->where($alias.'.created_by', $user->id)
                ->orWhereIn($alias.'.created_by', DB::table('users')->select('id')
                    ->where('customer_id', $customerId)->where('role', 'customer_admin'))));
    }

    /**
     * Choices for the question form: active usable rubrics, plus the question's current one even when archived or
     * no longer usable, so saving an untouched question keeps it.
     */
    public function options(int $customerId, User $user, mixed $keepId = null): Collection
    {
        return $this->usable($customerId, $user)
            ->where(fn ($query) => $query->where('r.status', 'active')->when($keepId, fn ($query, $id) => $query->orWhere('r.id', $id)))
            ->orderBy('r.title')
            ->get(['r.id', 'r.title', 'r.total_points', 'r.status']);
    }

    /** A new choice must be an active rubric the user may use; the question's current rubric always stays valid. */
    public function assertChoosable(int $customerId, User $user, ?int $rubricId, mixed $currentRubricId): void
    {
        if ($rubricId === null || $rubricId === (int) $currentRubricId) {
            return;
        }

        if (! $this->usable($customerId, $user)->where('r.id', $rubricId)->where('r.status', 'active')->exists()) {
            throw ValidationException::withMessages(['rubric_id' => __('lf.LF_assessment_rubric_error_choice')]);
        }
    }

    /** @return Collection<int, object> */
    public function items(int $customerId, int $rubricId): Collection
    {
        return DB::table(self::ITEMS_TABLE)->where('customer_id', $customerId)->where('rubric_id', $rubricId)
            ->orderBy('sort_order')->get();
    }

    /**
     * The rubric as frozen into a published version question (ADR-0026 D4). `key` is the position, so a result
     * never depends on the editable criterion rows.
     *
     * @return array{rubric_id: int, title: string, total_points: float, items: list<array{key: int, title: string, description: string|null, max_points: float}>}
     */
    public function snapshot(int $customerId, int $rubricId): array
    {
        $rubric = DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $rubricId)->first();
        abort_if(! $rubric, 404);

        return [
            'rubric_id' => (int) $rubric->id,
            'title' => (string) $rubric->title,
            'total_points' => (float) $rubric->total_points,
            'items' => $this->items($customerId, $rubricId)->values()->map(fn ($item, int $index) => [
                'key' => $index + 1,
                'title' => (string) $item->title,
                'description' => $item->description,
                'max_points' => (float) $item->max_points,
            ])->all(),
        ];
    }

    /**
     * Checks one score per criterion against the snapshot and turns their sum into the question score
     * (ADR-0026 D5): sum ÷ rubric total × the question's points, rounded to 2 decimals.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<int|string, mixed>  $scores  criterion key => score
     * @return array{score: float, result: list<array{key: int, score: float}>}
     */
    public static function score(array $snapshot, array $scores, float $maxScore): array
    {
        $result = [];
        $sum = 0.0;

        foreach ($snapshot['items'] ?? [] as $item) {
            $key = (int) $item['key'];
            $value = $scores[$key] ?? null;
            if (! is_numeric($value) || (float) $value < 0 || (float) $value > (float) $item['max_points']
                || round((float) $value, 2) !== (float) $value) {
                throw ValidationException::withMessages([
                    'criteria.'.$key => __('lf.LF_assessment_rubric_error_score', ['name' => $item['title'], 'max' => self::points((float) $item['max_points'])]),
                ]);
            }

            $result[] = ['key' => $key, 'score' => (float) $value];
            $sum += (float) $value;
        }

        $total = (float) ($snapshot['total_points'] ?? 0);
        $score = $total > 0 ? round($sum / $total * $maxScore, 2) : 0.0;

        return ['score' => min($score, $maxScore), 'result' => $result];
    }

    public static function points(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
