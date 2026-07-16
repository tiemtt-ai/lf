<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CourseProductTemplateChangePolicy
{
    /** @var list<string> */
    private const USAGE_TABLES = [
        'core_course_enrollments',
        'core_course_cohorts',
        'core_course_cohort_students',
        'core_course_progress',
        'core_course_lesson_progress',
        'core_course_activity_progress',
        'core_course_completions',
        'core_course_notes',
        'core_course_bookmarks',
        'core_course_reviews',
        'core_certificate_issued_certificates',
    ];

    public function hasUsage(int $customerId, int $productId): bool
    {
        foreach (self::USAGE_TABLES as $table) {
            if (DB::table($table)->where('customer_id', $customerId)
                ->where('product_id', $productId)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function decision(object $product, int $customerId, ?string $targetStatus = null): array
    {
        if ($product->status === 'archived') {
            return ['allowed' => false, 'reason' => 'archived'];
        }

        if ($this->hasUsage($customerId, (int) $product->id)) {
            return ['allowed' => false, 'reason' => 'used'];
        }

        if (($targetStatus ?? $product->status) !== 'draft') {
            return ['allowed' => false, 'reason' => 'draft_required'];
        }

        return ['allowed' => true, 'reason' => null];
    }
}
