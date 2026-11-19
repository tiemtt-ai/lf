<?php

namespace App\Services;

use App\Support\CourseType;
use Illuminate\Support\Facades\DB;

/**
 * A class that has ended is review only for its learners (ADR-0022 Amendment 1.4, MK 3.6): they still open the
 * content and see their results, but nothing they do adds progress and no new quiz attempt starts. The class ends
 * when it becomes `completed`; nothing ends it by date.
 */
final class CourseClassReview
{
    /**
     * Whether the Enrollment's seat is in a class that has ended. Only a class-based course is learned in a class:
     * a self-paced Enrollment is never review only, even when an older class still holds a seat for it.
     */
    public static function only(int $customerId, int $enrollmentId): bool
    {
        return DB::table('core_course_cohort_students as seats')
            ->join('core_course_cohorts as cohorts', function ($join) use ($customerId): void {
                $join->on('cohorts.id', '=', 'seats.cohort_id')->where('cohorts.customer_id', $customerId);
            })
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'cohorts.version_id')->where('versions.customer_id', $customerId);
            })
            ->whereIn('versions.course_type_snapshot', CourseType::COHORT_VALUES)
            ->where('seats.customer_id', $customerId)->where('seats.enrollment_id', $enrollmentId)
            ->where('seats.status', 'active')->whereNull('seats.left_at')
            ->orderByDesc('seats.id')
            ->value('cohorts.status') === 'completed';
    }
}
