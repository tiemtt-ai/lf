<?php

namespace App\Services;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** Course-owned read port: published Version Activity titles for AI Knowledge labels. */
final class CourseVersionActivityTitleService
{
    /**
     * @param  array<int, int>  $versionActivityIds
     * @return array<int, string> keyed by Version Activity id
     */
    public function titles(array $versionActivityIds): array
    {
        $customerId = TenantContext::customerId();
        $ids = array_values(array_unique(array_map('intval', $versionActivityIds)));
        if ($customerId === null || $ids === []) {
            return [];
        }

        return DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)->whereIn('id', $ids)
            ->pluck('title_snapshot', 'id')
            ->map(fn ($title): string => (string) $title)->all();
    }
}
