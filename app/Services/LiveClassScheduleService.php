<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LiveClassScheduleService
{
    public function create(int $customerId, int $cohortId, int $actorId, array $data): int
    {
        return DB::transaction(function () use ($customerId, $cohortId, $actorId, $data): int {
            DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $cohortId)->lockForUpdate()->firstOrFail();
            $now = now();
            $scheduleId = DB::table('core_liveclass_schedules')->insertGetId([
                'customer_id' => $customerId,
                'cohort_id' => $cohortId,
                'name' => trim($data['name']),
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'timezone' => $data['timezone'],
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->replaceChildren($customerId, $scheduleId, $actorId, $data, $now);

            return $scheduleId;
        }, 3);
    }

    public function update(
        int $customerId,
        int $cohortId,
        int $scheduleId,
        int $actorId,
        array $data
    ): void {
        DB::transaction(function () use ($customerId, $cohortId, $scheduleId, $actorId, $data): void {
            DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $cohortId)->lockForUpdate()->firstOrFail();
            DB::table('core_liveclass_schedules')->where('customer_id', $customerId)
                ->where('cohort_id', $cohortId)->where('id', $scheduleId)
                ->lockForUpdate()->firstOrFail();
            $now = now();
            DB::table('core_liveclass_schedules')->where('customer_id', $customerId)
                ->where('cohort_id', $cohortId)->where('id', $scheduleId)->update([
                    'name' => trim($data['name']),
                    'starts_on' => $data['starts_on'],
                    'ends_on' => $data['ends_on'],
                    'timezone' => $data['timezone'],
                    'updated_at' => $now,
                ]);
            DB::table('core_liveclass_schedule_exclusions')->where('customer_id', $customerId)
                ->where('schedule_id', $scheduleId)->delete();
            DB::table('core_liveclass_schedule_slots')->where('customer_id', $customerId)
                ->where('schedule_id', $scheduleId)->delete();
            $this->replaceChildren($customerId, $scheduleId, $actorId, $data, $now);
        }, 3);
    }

    private function replaceChildren(
        int $customerId,
        int $scheduleId,
        int $actorId,
        array $data,
        mixed $now
    ): void {
        DB::table('core_liveclass_schedule_slots')->insert(
            collect($data['slots'])->values()->map(fn (array $slot, int $index): array => [
                'customer_id' => $customerId,
                'schedule_id' => $scheduleId,
                'weekday' => (int) $slot['weekday'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'sort_order' => $index + 1,
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        $exclusions = collect($data['exclusions'] ?? [])->filter(
            fn (array $exclusion): bool => filled($exclusion['excluded_on'] ?? null)
        )->values();
        if ($exclusions->isNotEmpty()) {
            DB::table('core_liveclass_schedule_exclusions')->insert(
                $exclusions->map(fn (array $exclusion): array => [
                    'customer_id' => $customerId,
                    'schedule_id' => $scheduleId,
                    'excluded_on' => $exclusion['excluded_on'],
                    'reason' => filled($exclusion['reason'] ?? null) ? trim($exclusion['reason']) : null,
                    'created_by' => $actorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        }
    }
}
