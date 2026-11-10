<?php

namespace App\Services;

use Illuminate\Support\Carbon;
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

    /**
     * Tables that keep a Cohort's operational records, including ended memberships and removed teachers.
     * A teacher judgment always points at a membership, so a Cohort with one is already caught here.
     */
    private const RECORD_TABLES = [
        'core_course_cohort_students',
        'core_course_cohort_teachers',
        'core_liveclass_schedules',
        'core_liveclass_sessions',
    ];

    public function __construct(
        private readonly CourseCohortVersionResolver $versionResolver,
        private readonly LiveClassWhiteboardLiveState $boards,
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

    /**
     * Only a draft that never held any record can disappear; everything else is archived to keep its history.
     * All or nothing: one blocked Cohort keeps the whole selection.
     *
     * @param  array<int, int>  $cohortIds
     */
    public function destroyEmptyDrafts(int $customerId, array $cohortIds): void
    {
        DB::transaction(function () use ($customerId, $cohortIds): void {
            $cohorts = DB::table('core_course_cohorts')
                ->where('customer_id', $customerId)
                ->whereIn('id', $cohortIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'status']);

            abort_if($cohorts->count() !== count($cohortIds), 404);

            if ($cohorts->contains(fn (object $cohort): bool => $cohort->status !== 'draft')) {
                throw ValidationException::withMessages(['lifecycle' => __('lf.LF_course_cohort_delete_blocked_status')]);
            }

            if ($this->cohortIdsWithRecords($customerId, $cohortIds) !== []) {
                throw ValidationException::withMessages(['lifecycle' => __('lf.LF_course_cohort_delete_blocked_records')]);
            }

            DB::table('core_course_cohorts')
                ->where('customer_id', $customerId)
                ->whereIn('id', $cohortIds)
                ->delete();
        }, 3);
    }

    /**
     * @param  array<int, int>  $cohortIds
     * @return array<int, int>
     */
    public function cohortIdsWithRecords(int $customerId, array $cohortIds): array
    {
        // A seat transferred out still names this Cohort as its origin (FK restrict), so it is a record too.
        $transferredOut = DB::table('core_course_cohort_students')
            ->where('customer_id', $customerId)
            ->whereIn('transfer_from_cohort_id', $cohortIds)
            ->distinct()
            ->pluck('transfer_from_cohort_id');

        return collect(self::RECORD_TABLES)
            ->flatMap(fn (string $table) => DB::table($table)
                ->where('customer_id', $customerId)
                ->whereIn('cohort_id', $cohortIds)
                ->distinct()
                ->pluck('cohort_id'))
            ->concat($transferredOut)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * What completing the Cohort now would leave behind. Informs the confirmation only: completion stays allowed.
     *
     * @return array<int, string>
     */
    public function completionWarnings(int $customerId, object $cohort, int $activeMembershipCount): array
    {
        $openSessions = DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohort->id)
            ->whereIn('status', ['scheduled', 'live'])
            ->count();
        $endsLater = $cohort->end_date && Carbon::parse($cohort->end_date)->isAfter(today());

        return array_values(array_filter([
            $endsLater ? __('lf.LF_course_cohort_complete_warning_end_date', ['date' => Carbon::parse($cohort->end_date)->format('d/m/Y')]) : null,
            $openSessions > 0 ? __('lf.LF_course_cohort_complete_warning_sessions', ['count' => $openSessions]) : null,
            $activeMembershipCount > 0 ? __('lf.LF_course_cohort_complete_warning_students', ['count' => $activeMembershipCount]) : null,
        ]));
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
                $this->assertDraftArchiveEligible($customerId, $cohort);
            }

            DB::table('core_course_cohorts')
                ->where('customer_id', $customerId)
                ->where('id', $cohortId)
                ->update([
                    'status' => $targetStatus,
                    'updated_at' => now(),
                ]);

            // A Cohort leaving `active` closes the Whiteboards of its live Sessions.
            if ($cohort->status === 'active' && $targetStatus !== 'active') {
                DB::afterCommit(fn () => $this->boards->endCohort($customerId, $cohortId));
            }
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

        if ($cohort->version_id && ! $this->versionResolver->runsInClass($customerId, (int) $cohort->version_id)) {
            $errors[] = __('lf.LF_course_type_cohort_legacy_self_paced');
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

    private function assertDraftArchiveEligible(int $customerId, object $cohort): void
    {
        $cohortId = (int) $cohort->id;

        // Owner decision 2026-11-10: a draft whose Product moved to another Version can never
        // activate, so it may be archived with its members. They leave the class; their
        // Enrollments are untouched (core_course_cohorts.md § Stale Draft Archive Amendment).
        if ($this->bindsAStaleVersion($customerId, $cohort)) {
            $leaving = DB::table('core_course_cohort_students')
                ->where('customer_id', $customerId)->where('cohort_id', $cohortId)->where('status', 'active');
            $now = now();
            $leaving->update(['status' => 'removed', 'left_at' => $now, 'updated_at' => $now]);

            return;
        }

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

    private function bindsAStaleVersion(int $customerId, object $cohort): bool
    {
        if (! $cohort->product_id || ! $cohort->version_id) {
            return false;
        }
        $resolved = $this->versionResolver->resolve($customerId, (int) $cohort->product_id, true);

        return ! $resolved || (int) $resolved->version_id !== (int) $cohort->version_id;
    }

    private function failActivation(array $errors): never
    {
        throw ValidationException::withMessages([
            'lifecycle' => $errors,
        ]);
    }
}
