<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The seat a learner holds in a Cohort, found by the learner rather than the Enrollment.
 *
 * Enrollment transitions never touch Membership, so data written before the
 * same-student guard may hold two current seats for one learner: an old one whose
 * Enrollment ended and a new one. The seat of an active Enrollment wins, then the
 * newest, so the order the database returns rows in never decides access.
 */
class LearnerCohortSeat
{
    public static function current(int $customerId, int $cohortId, int $studentId): ?object
    {
        return DB::table('core_course_cohort_students as seats')
            ->leftJoin('core_course_enrollments as enrollments', function ($join) use ($customerId): void {
                $join->on('enrollments.id', '=', 'seats.enrollment_id')
                    ->where('enrollments.customer_id', '=', $customerId);
            })
            ->where('seats.customer_id', $customerId)->where('seats.cohort_id', $cohortId)
            ->where('seats.student_id', $studentId)->where('seats.status', 'active')->whereNull('seats.left_at')
            ->orderByRaw("CASE WHEN enrollments.status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('seats.id')
            ->first(['seats.id', 'seats.enrollment_id', 'seats.student_id']);
    }
}
