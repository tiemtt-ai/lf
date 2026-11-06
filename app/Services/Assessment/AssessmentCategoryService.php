<?php

namespace App\Services\Assessment;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Category tree of the Kiểm tra module (core_assessment_categories, ADR-0025 D3).
 *
 * Rules the database cannot hold live here: no category may be its own ancestor (MariaDB refuses a CHECK on the
 * AUTO_INCREMENT id), values are stored lowercase because the CHECKs compare case-insensitively, and a category
 * that something still uses is archived, never deleted. Every tree write locks the tenant row first so two edits
 * cannot build a cycle between them.
 */
final class AssessmentCategoryService
{
    public const TABLE = 'core_assessment_categories';

    public const STATUSES = ['active', 'archived'];

    /** Tables whose rows point at a category through category_id; any row there blocks a hard delete. */
    private const USAGE_TABLES = [
        'questions' => 'core_assessment_questions',
        'passages' => 'core_assessment_passages',
        'quizzes' => 'core_assessment_quizzes',
    ];

    /**
     * @param  array{name: string, parent_id?: int|null, description?: string|null, status: string}  $data
     */
    public function create(int $customerId, array $data): int
    {
        return DB::transaction(function () use ($customerId, $data): int {
            $this->lockTenant($customerId);
            $parentId = $this->checkedParent($customerId, $data['parent_id'] ?? null, null);
            $now = now();

            return DB::table(self::TABLE)->insertGetId([
                'customer_id' => $customerId,
                'parent_id' => $parentId,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($customerId, $data['name'], null),
                'description' => $data['description'] ?? null,
                'sort_order' => $this->nextSortOrder($customerId, $parentId),
                'status' => Str::lower($data['status']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * @param  array{name: string, parent_id?: int|null, description?: string|null, status: string}  $data
     */
    public function update(int $customerId, int $id, array $data): void
    {
        DB::transaction(function () use ($customerId, $id, $data): void {
            $this->lockTenant($customerId);
            $category = $this->find($customerId, $id);
            $parentId = $this->checkedParent($customerId, $data['parent_id'] ?? null, $id);
            $parentChanged = (int) $category->parent_id !== (int) $parentId;

            DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $id)->update([
                'parent_id' => $parentId,
                'name' => $data['name'],
                // The slug follows the name only while it still is the name's own slug, so links stay stable.
                'slug' => $category->name === $data['name']
                    ? $category->slug
                    : $this->uniqueSlug($customerId, $data['name'], $id),
                'description' => $data['description'] ?? null,
                'sort_order' => $parentChanged ? $this->nextSortOrder($customerId, $parentId) : $category->sort_order,
                'status' => Str::lower($data['status']),
                'updated_at' => now(),
            ]);
        });
    }

    /** Active ↔ archived. Archiving keeps every link; archived categories just stop being offered for new content. */
    public function toggleStatus(int $customerId, int $id): string
    {
        return DB::transaction(function () use ($customerId, $id): string {
            $category = DB::table(self::TABLE)
                ->where('customer_id', $customerId)->where('id', $id)
                ->lockForUpdate()->first();
            abort_if(! $category, 404);

            $status = $category->status === 'active' ? 'archived' : 'active';
            DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $id)
                ->update(['status' => $status, 'updated_at' => now()]);

            return $status;
        });
    }

    /**
     * All or nothing. Returns null when deleted, otherwise the first blocking category and why.
     *
     * @param  list<int>  $ids
     * @return array{name: string, reason: string, count: int}|null
     */
    public function deleteMany(int $customerId, array $ids): ?array
    {
        return DB::transaction(function () use ($customerId, $ids): ?array {
            $this->lockTenant($customerId);
            $categories = DB::table(self::TABLE)
                ->where('customer_id', $customerId)->whereIn('id', $ids)
                ->orderBy('id')->lockForUpdate()->get(['id', 'name']);
            abort_if($categories->count() !== count($ids), 404);

            foreach ($categories as $category) {
                // A child that is deleted in the same request does not block its parent.
                $blockers = $this->usageCounts($customerId, (int) $category->id, $ids);
                foreach ($blockers as $reason => $count) {
                    if ($count > 0) {
                        return ['name' => $category->name, 'reason' => $reason, 'count' => $count];
                    }
                }
            }

            // Children first: parent_id is a RESTRICT foreign key.
            foreach ($this->deepestFirst($customerId, $ids) as $id) {
                DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $id)->delete();
            }

            return null;
        });
    }

    /**
     * @param  list<int>  $alsoDeleted
     * @return array<string, int>
     */
    public function usageCounts(int $customerId, int $id, array $alsoDeleted = []): array
    {
        $counts = ['children' => DB::table(self::TABLE)
            ->where('customer_id', $customerId)->where('parent_id', $id)
            ->whereNotIn('id', $alsoDeleted)->count()];

        foreach (self::USAGE_TABLES as $reason => $table) {
            $counts[$reason] = DB::table($table)->where('customer_id', $customerId)->where('category_id', $id)->count();
        }

        return $counts;
    }

    public function find(int $customerId, int $id): object
    {
        $category = DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $id)->first();
        abort_if(! $category, 404);

        return $category;
    }

    /**
     * Parent options: active categories, minus the edited one and its descendants (they would make a cycle).
     * The current parent stays listed even when archived, so saving the form unchanged keeps it.
     */
    public function parentOptions(int $customerId, ?object $editing = null): Collection
    {
        $excluded = $editing ? [(int) $editing->id, ...$this->descendantIds($customerId, (int) $editing->id)] : [];

        return DB::table(self::TABLE)
            ->where('customer_id', $customerId)
            ->where(fn ($query) => $query->where('status', 'active')
                ->when($editing?->parent_id, fn ($query, $parentId) => $query->orWhere('id', $parentId)))
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('id', $excluded))
            ->orderBy('name')
            ->get(['id', 'name', 'status']);
    }

    /** @return list<int> */
    public function descendantIds(int $customerId, int $id): array
    {
        $children = DB::table(self::TABLE)->where('customer_id', $customerId)
            ->whereNotNull('parent_id')->pluck('parent_id', 'id');
        $found = [];
        $frontier = [$id];

        while ($frontier !== []) {
            $next = [];
            foreach ($children as $childId => $parentId) {
                if (in_array((int) $parentId, $frontier, true) && ! in_array((int) $childId, $found, true) && (int) $childId !== $id) {
                    $found[] = (int) $childId;
                    $next[] = (int) $childId;
                }
            }
            $frontier = $next;
        }

        return $found;
    }

    private function checkedParent(int $customerId, ?int $parentId, ?int $editingId): ?int
    {
        if (! $parentId) {
            return null;
        }

        $exists = DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $parentId)->exists();
        $cycle = $editingId !== null
            && ($parentId === $editingId || in_array($parentId, $this->descendantIds($customerId, $editingId), true));

        if (! $exists || $cycle) {
            throw ValidationException::withMessages([
                'parent_id' => __('lf.LF_assessment_category_error_parent'),
            ]);
        }

        return $parentId;
    }

    private function uniqueSlug(int $customerId, string $name, ?int $ignoreId): string
    {
        $base = Str::limit(Str::slug($name) ?: 'danh-muc', 240, '');
        $slug = $base;
        $suffix = 2;

        while (DB::table(self::TABLE)->where('customer_id', $customerId)->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '<>', $ignoreId))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function nextSortOrder(int $customerId, ?int $parentId): int
    {
        $max = DB::table(self::TABLE)->where('customer_id', $customerId)
            ->when($parentId, fn ($query) => $query->where('parent_id', $parentId), fn ($query) => $query->whereNull('parent_id'))
            ->max('sort_order');

        return $max === null ? 1 : (int) $max + 1;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function deepestFirst(int $customerId, array $ids): array
    {
        $depth = fn (int $id): int => count(array_intersect($ids, $this->ancestorIds($customerId, $id)));
        usort($ids, fn (int $a, int $b): int => $depth($b) <=> $depth($a));

        return $ids;
    }

    /** @return list<int> */
    private function ancestorIds(int $customerId, int $id): array
    {
        $parents = DB::table(self::TABLE)->where('customer_id', $customerId)->pluck('parent_id', 'id');
        $found = [];
        $current = $parents[$id] ?? null;

        while ($current !== null && ! in_array((int) $current, $found, true)) {
            $found[] = (int) $current;
            $current = $parents[$current] ?? null;
        }

        return $found;
    }

    private function lockTenant(int $customerId): void
    {
        DB::table('saas_customers')->where('id', $customerId)->lockForUpdate()->first(['id']);
    }
}
