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
        if (! $cohort->product_id || ! $cohort->version_id) {
            $this->failActivation();
        }

        if ($cohort->capacity !== null && (int) $cohort->capacity < 1) {
            $this->failActivation();
        }

        if ($cohort->start_date && $cohort->end_date && $cohort->end_date < $cohort->start_date) {
            $this->failActivation();
        }

        $resolved = $this->versionResolver->resolve($customerId, (int) $cohort->product_id, true);
        if (! $resolved || (int) $resolved->version_id !== (int) $cohort->version_id) {
            $this->failActivation();
        }
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

    private function failActivation(): never
    {
        throw ValidationException::withMessages([
            'lifecycle' => __('lf.LF_course_cohort_lifecycle_activation_ineligible'),
        ]);
    }
}
