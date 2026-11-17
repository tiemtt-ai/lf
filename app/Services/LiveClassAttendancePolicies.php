<?php

namespace App\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A tenant's attendance policies (ADR-0002 § Attendance Policy And Recording Amendment, part A): the thresholds
 * that open check-in and turn an entry into present, late or absent. A Cohort uses the policy it points to, or the
 * tenant's default when it points to none.
 */
final class LiveClassAttendancePolicies
{
    public const TABLE = 'core_liveclass_attendance_policies';

    public const DEFAULT_NAME = 'Mặc định của trung tâm';

    /** The thresholds of a policy; the documented defaults are the column defaults. */
    public const SETTINGS = [
        'opens_before_minutes', 'late_after_minutes', 'absent_after_minutes', 'auto_absent_after_minutes',
        'count_from_actual_start', 'offline_recording', 'online_recording', 'makeup_enabled', 'makeup_within_days',
        'teacher_edit_days',
    ];

    public const RECORDING_MODES = ['system', 'manual'];

    /** The tenant's default policy, created the first time it is needed (a tenant made after the migration has none yet). */
    public function defaultFor(int $customerId): object
    {
        $find = fn (): ?object => DB::table(self::TABLE)->where('customer_id', $customerId)->where('is_default', 1)->first();
        if ($policy = $find()) {
            return $policy;
        }
        try {
            // Its own savepoint: a concurrent request may insert the same row first, and that must not poison the
            // caller's transaction.
            DB::transaction(fn () => DB::table(self::TABLE)->insert([
                'customer_id' => $customerId, 'name' => $this->freeDefaultName($customerId), 'is_default' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Another request created it.
        }

        return $find() ?? throw new \RuntimeException('The default attendance policy could not be resolved.');
    }

    /** The policy the Cohort uses: its own, else the tenant default. */
    public function forCohort(int $customerId, int $cohortId): object
    {
        $policy = DB::table(self::TABLE.' as policies')
            ->join('core_course_cohorts as cohorts', function ($join) use ($customerId): void {
                $join->on('cohorts.attendance_policy_id', '=', 'policies.id')->where('cohorts.customer_id', '=', $customerId);
            })
            ->where('policies.customer_id', $customerId)->where('cohorts.id', $cohortId)
            ->first('policies.*');

        return $policy ?? $this->defaultFor($customerId);
    }

    /** One policy of this tenant, or null. */
    public function find(int $customerId, int $policyId): ?object
    {
        return DB::table(self::TABLE)->where('customer_id', $customerId)->where('id', $policyId)->first();
    }

    /** The default name, or a numbered one when an ordinary policy already took it. */
    private function freeDefaultName(int $customerId): string
    {
        $taken = DB::table(self::TABLE)->where('customer_id', $customerId)->pluck('name')->all();
        $name = self::DEFAULT_NAME;
        for ($n = 2; in_array($name, $taken, true); $n++) {
            $name = self::DEFAULT_NAME.' ('.$n.')';
        }

        return $name;
    }
}
