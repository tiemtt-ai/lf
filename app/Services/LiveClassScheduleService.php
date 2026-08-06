<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

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
            $this->syncSlots($customerId, $scheduleId, $actorId, $data['slots'], $now);
            $this->insertExclusions($customerId, $scheduleId, $actorId, $data, $now);
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

        $this->insertExclusions($customerId, $scheduleId, $actorId, $data, $now);
    }

    private function insertExclusions(int $customerId, int $scheduleId, int $actorId, array $data, mixed $now): void
    {
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

    private function syncSlots(int $customerId, int $scheduleId, int $actorId, array $slots, mixed $now): void
    {
        $existing = DB::table('core_liveclass_schedule_slots')
            ->where('customer_id', $customerId)->where('schedule_id', $scheduleId)
            ->lockForUpdate()->get()->keyBy(fn ($slot): string => implode('|', [
                $slot->weekday, substr($slot->start_time, 0, 5), substr($slot->end_time, 0, 5),
            ]));
        $keptIds = [];

        foreach (array_values($slots) as $index => $slot) {
            $key = implode('|', [(int) $slot['weekday'], substr($slot['start_time'], 0, 5), substr($slot['end_time'], 0, 5)]);
            if ($current = $existing->get($key)) {
                $keptIds[] = (int) $current->id;
                DB::table('core_liveclass_schedule_slots')->where('id', $current->id)->update([
                    'sort_order' => $index + 1, 'updated_at' => $now,
                ]);

                continue;
            }

            $keptIds[] = DB::table('core_liveclass_schedule_slots')->insertGetId([
                'customer_id' => $customerId, 'schedule_id' => $scheduleId,
                'weekday' => (int) $slot['weekday'], 'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'], 'sort_order' => $index + 1,
                'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $remove = DB::table('core_liveclass_schedule_slots')->where('customer_id', $customerId)
            ->where('schedule_id', $scheduleId)->whereNotIn('id', $keptIds);
        if (Schema::hasTable('core_liveclass_session_schedule_origins')) {
            $referenced = DB::table('core_liveclass_session_schedule_origins')
                ->where('customer_id', $customerId)->whereIn('schedule_slot_id', (clone $remove)->pluck('id'))
                ->exists();
            if ($referenced) {
                throw ValidationException::withMessages([
                    'slots' => __('lf.LF_course_cohort_schedule_slot_referenced'),
                ]);
            }
        }
        $remove->delete();
    }
}
