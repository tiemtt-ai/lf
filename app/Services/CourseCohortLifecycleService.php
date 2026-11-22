<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        private readonly LiveClassSessionLifecycleService $sessions,
    ) {}

    public function activate(int $customerId, int $cohortId): void
    {
        $this->transition($customerId, $cohortId, 'active');
    }

    public function complete(int $customerId, int $cohortId): void
    {
        $this->transition($customerId, $cohortId, 'completed');
    }

    /**
     * An active Cohort ends by itself after 23:59 of its end date, in the application timezone (ADR-0022
     * Amendment 1.4, "Lớp tự kết thúc theo ngày"); completing it this way has every effect of the button. A legacy
     * Cohort without an end date is left to an administrator.
     *
     * @return int Cohorts completed
     */
    public function completeEnded(?CarbonInterface $now = null): int
    {
        $today = CarbonImmutable::instance($now ?? now())->setTimezone(config('app.timezone'))->toDateString();
        $completed = 0;
        $cohorts = DB::table('core_course_cohorts')->where('status', 'active')
            ->whereNotNull('end_date')->where('end_date', '<', $today)
            ->orderBy('id')->get(['id', 'customer_id']);
        foreach ($cohorts as $cohort) {
            try {
                $this->complete((int) $cohort->customer_id, (int) $cohort->id);
                $completed++;
            } catch (ValidationException|HttpException $exception) {
                // Completed by hand since the read: nothing left to do. Anything else is reported and retried next run.
                if (DB::table('core_course_cohorts')->where('id', $cohort->id)->value('status') === 'active') {
                    report($exception);
                }
            }
        }

        return $completed;
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
    /**
     * `$ownPrimaryTeacherId`: a teacher deleting their own drafts (ADR-0033 D7); their active primary assignment is
     * not a record of the class and goes with it. Every other assignment still blocks.
     */
    public function destroyEmptyDrafts(int $customerId, array $cohortIds, ?int $ownPrimaryTeacherId = null): void
    {
        DB::transaction(function () use ($customerId, $cohortIds, $ownPrimaryTeacherId): void {
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

            $ownAssignments = $ownPrimaryTeacherId === null ? null : DB::table('core_course_cohort_teachers')
                ->where('customer_id', $customerId)->whereIn('cohort_id', $cohortIds)
                ->where('teacher_id', $ownPrimaryTeacherId)->where('role', 'primary_teacher')->where('status', 'active');
            if ($this->cohortIdsWithRecords($customerId, $cohortIds, $ownAssignments?->clone()->pluck('id')->all() ?? []) !== []) {
                throw ValidationException::withMessages(['lifecycle' => __('lf.LF_course_cohort_delete_blocked_records')]);
            }

            $ownAssignments?->delete();
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
    public function cohortIdsWithRecords(int $customerId, array $cohortIds, array $ignoredAssignmentIds = []): array
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
                ->when($table === 'core_course_cohort_teachers' && $ignoredAssignmentIds !== [], fn ($query) => $query->whereNotIn('id', $ignoredAssignmentIds))
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
        $plan = $this->sessions->cohortCompletionPlan($customerId, (int) $cohort->id);
        $endsLater = $cohort->end_date && Carbon::parse($cohort->end_date)->isAfter(today());

        return array_values(array_filter([
            $endsLater ? __('lf.LF_course_cohort_complete_warning_end_date', ['date' => Carbon::parse($cohort->end_date)->format('d/m/Y')]) : null,
            $plan['cancel'] !== [] ? __('lf.LF_course_cohort_complete_warning_sessions', ['count' => count($plan['cancel'])]) : null,
            $plan['complete'] !== [] ? __('lf.LF_course_cohort_complete_warning_sessions_running', ['count' => count($plan['complete'])]) : null,
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

            // Sessions not held are cancelled and running ones end, while the Cohort is still active (ADR-0022
            // Amendment 1.4, "Dọn buổi khi hoàn thành lớp").
            if ($cohort->status === 'active' && $targetStatus === 'completed') {
                $this->sessions->settleForCohortCompletion($customerId, $cohortId);
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

        // An active class ends by itself after its end date (ADR-0022 Amendment 1.4): one already past it would end
        // within a minute of being activated.
        if ($cohort->end_date && substr((string) $cohort->end_date, 0, 10) < now(config('app.timezone'))->toDateString()) {
            $errors[] = __('lf.LF_course_cohort_activation_end_date_past');
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
            // Nobody teaches a self-study Session (ADR-0023 §10).
            ->where('sessions.session_type', '!=', LiveClassSelfStudySession::TYPE)
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
            // ADR-0034: the system removed them, so the history names nobody.
            foreach ((clone $leaving)->get() as $member) {
                CourseCohortMembershipHistory::record($member, 'left', null);
            }
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
