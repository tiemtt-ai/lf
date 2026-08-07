<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseCohortLifecycleService
{
    private const TRANSITIONS = [
        'draft' => ['active', 'archived'],
        'active' => ['completed'],
        'completed' => ['archived'],
        'archived' => [],
    ];

    public function __construct(
        private readonly CourseCohortVersionResolver $versionResolver
    ) {}

    public function activate(int $customerId, int $cohortId): void
    {
        $this->transition($customerId, $cohortId, 'active');
    }

    public function complete(int $customerId, int $cohortId): void
    {
        $this->transition($customerId, $cohortId, 'completed');
    }

    public function archive(int $customerId, int $cohortId): void
    {
        $this->transition($customerId, $cohortId, 'archived');
    }

    public function activationIssues(int $customerId, int $cohortId): array
    {
        $cohort = DB::table('core_course_cohorts')->where('customer_id', $customerId)
            ->where('id', $cohortId)->firstOrFail();

        return $this->collectActivationIssues($customerId, $cohort, false);
    }

    private function transition(int $customerId, int $cohortId, string $targetStatus): void
    {
        DB::transaction(function () use ($customerId, $cohortId, $targetStatus): void {
            $cohort = DB::table('core_course_cohorts')
                ->where('customer_id', $customerId)
                ->where('id', $cohortId)
                ->lockForUpdate()
                ->first();

            abort_if(! $cohort, 404);

            if (! in_array($targetStatus, self::TRANSITIONS[$cohort->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'lifecycle' => $cohort->status === 'active' && $targetStatus === 'archived'
                        ? __('lf.LF_course_cohort_lifecycle_active_archive_invalid')
                        : __('lf.LF_course_cohort_lifecycle_invalid_transition'),
                ]);
            }

            if ($targetStatus === 'active') {
                $this->assertActivationEligible($customerId, $cohort);
            }

            if ($cohort->status === 'draft' && $targetStatus === 'archived') {
                $this->assertDraftArchiveEligible($customerId, $cohortId);
            }

            DB::table('core_course_cohorts')
                ->where('customer_id', $customerId)
                ->where('id', $cohortId)
                ->update([
                    'status' => $targetStatus,
                    'updated_at' => now(),
                ]);
        }, 3);
    }

    private function assertActivationEligible(int $customerId, object $cohort): void
    {
        $errors = $this->collectActivationIssues($customerId, $cohort, true);
        if ($errors !== []) {
            $this->failActivation($errors);
        }
    }

    private function collectActivationIssues(int $customerId, object $cohort, bool $lock): array
    {
        $errors = [];

        if (! $cohort->product_id || ! $cohort->version_id) {
            $errors[] = __('lf.LF_course_cohort_activation_binding_invalid');
        }

        if ($cohort->capacity !== null && (int) $cohort->capacity < 1) {
            $errors[] = __('lf.LF_course_cohort_activation_capacity_invalid');
        }

        if ($cohort->start_date && $cohort->end_date && $cohort->end_date < $cohort->start_date) {
            $errors[] = __('lf.LF_course_cohort_activation_dates_invalid');
        }

        if ($cohort->product_id && $cohort->version_id) {
            $resolved = $this->versionResolver->resolve($customerId, (int) $cohort->product_id, true);
            if (! $resolved || (int) $resolved->version_id !== (int) $cohort->version_id) {
                $errors[] = __('lf.LF_course_cohort_activation_product_version_invalid');
            }
        }

        $membershipQuery = DB::table('core_course_cohort_students as memberships')
            ->leftJoin('core_course_enrollments as enrollments', function ($join) use ($customerId): void {
                $join->on('enrollments.id', '=', 'memberships.enrollment_id')
                    ->where('enrollments.customer_id', '=', $customerId);
            })
            ->where('memberships.customer_id', $customerId)
            ->where('memberships.cohort_id', $cohort->id)
            ->where('memberships.status', 'active');
        if ($lock) {
            $membershipQuery->lockForUpdate();
        }
        $memberships = $membershipQuery->get([
            'memberships.enrollment_id', 'memberships.product_id',
            'enrollments.status as enrollment_status',
            'enrollments.product_id as enrollment_product_id',
            'enrollments.version_id as enrollment_version_id',
        ]);

        if ($cohort->capacity !== null && $memberships->count() > (int) $cohort->capacity) {
            $errors[] = __('lf.LF_course_cohort_activation_capacity_exceeded');
        }

        foreach ($memberships as $membership) {
            if ($membership->enrollment_status !== 'active') {
                $errors[] = __('lf.LF_course_cohort_activation_enrollment_inactive', [
                    'id' => $membership->enrollment_id,
                ]);
            }
            if ((int) $membership->product_id !== (int) $cohort->product_id
                || (int) $membership->enrollment_product_id !== (int) $cohort->product_id
                || (int) $membership->enrollment_version_id !== (int) $cohort->version_id) {
                $errors[] = __('lf.LF_course_cohort_activation_membership_binding_invalid', [
                    'id' => $membership->enrollment_id,
                ]);
            }
        }

        $unassignedSessionCount = DB::table('core_liveclass_sessions as sessions')
            ->where('sessions.customer_id', $customerId)
            ->where('sessions.cohort_id', $cohort->id)
            ->whereNotIn('sessions.status', ['cancelled', 'no_show'])
            ->whereNull('sessions.primary_teacher_id')
            ->whereNotExists(function ($query) use ($customerId): void {
                $query->selectRaw('1')
                    ->from('core_liveclass_session_teachers as session_teachers')
                    ->whereColumn('session_teachers.session_id', 'sessions.id')
                    ->where('session_teachers.customer_id', $customerId);
            })
            ->count();
        if ($unassignedSessionCount > 0) {
            $errors[] = __('lf.LF_course_cohort_activation_sessions_unassigned', [
                'count' => $unassignedSessionCount,
            ]);
        }

        return array_values(array_unique($errors));
    }

    private function assertDraftArchiveEligible(int $customerId, int $cohortId): void
    {
        $hasMembershipUsage = DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)
            ->where(function ($query) use ($cohortId): void {
                $query->where('cohort_id', $cohortId)
                    ->orWhere('transfer_from_cohort_id', $cohortId);
            })
            ->exists();

        if ($hasMembershipUsage) {
            throw ValidationException::withMessages([
                'lifecycle' => __('lf.LF_course_cohort_lifecycle_draft_archive_usage'),
            ]);
        }
    }

    private function failActivation(array $errors): never
    {
        throw ValidationException::withMessages([
            'lifecycle' => $errors,
        ]);
    }
}
