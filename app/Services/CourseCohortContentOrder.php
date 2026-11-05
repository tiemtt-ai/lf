<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place that reads a Cohort's own order (ADR-0023 §6). A scope without
 * rows keeps the Version's order; a saved scope always has a row for every item.
 */
class CourseCohortContentOrder
{
    /**
     * @param  Collection<int, object>  $lessons  with `id` and `sort_order`
     * @return Collection<int, object>
     */
    public function lessons(int $customerId, int $cohortId, Collection $lessons): Collection
    {
        return $this->sorted($lessons, 'core_course_cohort_lesson_orders', 'version_lesson_id', $customerId, $cohortId);
    }

    /**
     * @param  Collection<int, object>  $activities  with `id` and `sort_order`
     * @return Collection<int, object>
     */
    public function activities(int $customerId, int $cohortId, Collection $activities): Collection
    {
        return $this->sorted($activities, 'core_course_cohort_activity_orders', 'version_activity_id', $customerId, $cohortId);
    }

    private function sorted(Collection $items, string $table, string $column, int $customerId, int $cohortId): Collection
    {
        $positions = DB::table($table)
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohortId)
            ->pluck('sort_order', $column);

        return $items
            ->sortBy([
                fn (object $a, object $b): int => (int) ($positions[$a->id] ?? $a->sort_order) <=> (int) ($positions[$b->id] ?? $b->sort_order),
                fn (object $a, object $b): int => (int) $a->id <=> (int) $b->id,
            ])
            ->values();
    }
}
