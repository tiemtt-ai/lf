<?php

namespace App\Services;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Course owner port contextExistenceBatch (contract § Hash and dependency
 * definitions): existence only, for trusted cleanup. It returns which of the
 * given working Activity IDs of the current tenant still exist, and nothing
 * about them — no titles, no authority.
 */
final class CourseAuthoringExistenceService
{
    public const MAX_BATCH = 500;

    /**
     * @param  array<int,int>  $activityIds
     * @return array<int,int>
     */
    public function existingActivityIds(array $activityIds): array
    {
        $customerId = TenantContext::customerId();
        $ids = array_slice(array_values(array_unique(array_map('intval', $activityIds))), 0, self::MAX_BATCH);
        if ($customerId === null || $ids === []) {
            return [];
        }

        return DB::table('core_course_template_activities')->where('customer_id', $customerId)
            ->whereIn('id', $ids)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
