<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LiveClassSessionOriginService
{
    /**
     * Canonical Schedule-to-Session relationship label.
     *
     * ADR-0002 § Schedule-To-Session Origin Amendment defines these four values
     * and forbids deriving them from the current mutable Schedule. Classification
     * therefore reads only the immutable Origin snapshot, the Session's own
     * planned interval, and one server-configured rollout instant.
     *
     * The row must carry the Session columns plus the joined Origin columns
     * (`schedule_origin_id`, `source_start_at`, `source_end_at`).
     */
    public function classify(object $session): string
    {
        if (! ($session->schedule_origin_id ?? null)) {
            // No Origin means manual or legacy, and the two are told apart only
            // by the immutable rollout cutover — never by timestamp coincidence.
            return CarbonImmutable::parse($session->created_at)
                ->lt(CarbonImmutable::parse(config('liveclass.schedule_origin_rollout_at')))
                    ? 'source_unknown'
                    : 'off_schedule';
        }

        $start = LiveClassSessionTime::utc($session->scheduled_start_at, $session->timezone);
        $end = LiveClassSessionTime::utc($session->scheduled_end_at, $session->timezone);

        return $start->equalTo(CarbonImmutable::parse($session->source_start_at, 'UTC'))
            && $end->equalTo(CarbonImmutable::parse($session->source_end_at, 'UTC'))
                ? 'on_schedule'
                : 'rescheduled';
    }

    public function resolve(int $customerId, int $cohortId, int $scheduleId, int $slotId, string $localDate, bool $lock = false): array
    {
        $scheduleQuery = DB::table('core_liveclass_schedules')
            ->where('customer_id', $customerId)->where('cohort_id', $cohortId)->where('id', $scheduleId);
        $slotQuery = DB::table('core_liveclass_schedule_slots')
            ->where('customer_id', $customerId)->where('schedule_id', $scheduleId)->where('id', $slotId);
        if ($lock) {
            $scheduleQuery->lockForUpdate();
            $slotQuery->lockForUpdate();
        }
        $schedule = $scheduleQuery->first();
        $slot = $slotQuery->first();
        if (! $schedule || ! $slot) {
            throw $this->invalid();
        }

        if (DB::table('core_liveclass_session_schedule_origins')
            ->where('customer_id', $customerId)->where('schedule_id', $scheduleId)
            ->where('schedule_slot_id', $slotId)->where('source_local_date', $localDate)
            ->exists()) {
            throw $this->invalid();
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $localDate, $schedule->timezone);
        $excluded = DB::table('core_liveclass_schedule_exclusions')
            ->where('customer_id', $customerId)->where('schedule_id', $scheduleId)
            ->where('excluded_on', $localDate)->exists();
        if ($date->format('Y-m-d') !== $localDate || $date->isoWeekday() !== (int) $slot->weekday
            || $localDate < $schedule->starts_on || $localDate > $schedule->ends_on || $excluded) {
            throw $this->invalid();
        }

        $startTime = substr($slot->start_time, 0, 5);
        $endTime = substr($slot->end_time, 0, 5);
        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', "$localDate $startTime", $schedule->timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d H:i', "$localDate $endTime", $schedule->timezone);

        return [
            'schedule_id' => (int) $schedule->id,
            'schedule_slot_id' => (int) $slot->id,
            'source_local_date' => $localDate,
            'source_local_start_time' => $startTime.':00',
            'source_local_end_time' => $endTime.':00',
            'source_timezone' => $schedule->timezone,
            'source_start_at' => $start->utc()->format('Y-m-d H:i:s'),
            'source_end_at' => $end->utc()->format('Y-m-d H:i:s'),
            'scheduled_start_at' => $start->format('Y-m-d H:i:s'),
            'scheduled_end_at' => $end->format('Y-m-d H:i:s'),
            'timezone' => $schedule->timezone,
        ];
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages([
            'source_local_date' => __('lf.LF_course_cohort_session_occurrence_invalid'),
        ]);
    }
}
