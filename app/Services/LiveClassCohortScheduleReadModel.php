<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model for the Schedules tab of a Cohort.
 *
 * Like the Session read model, this used to be a private method on a
 * Course-domain controller reading three LiveClass tables. It takes plain
 * scalars rather than the HTTP request so the caller stays responsible for
 * interpreting query parameters.
 *
 * Stateless: no result is cached on the instance. See
 * `LiveClassCohortSessionReadModel` for why.
 */
class LiveClassCohortScheduleReadModel
{
    public function __construct(
        private readonly LiveClassSchedulePreviewService $previewService
    ) {}

    /**
     * Paginated Schedules, each decorated with its Slots, Exclusions, projected
     * occurrence count and date-derived status.
     *
     * Schedule status is never persisted — it is derived from the current date
     * in the Schedule's own timezone, per `core_liveclass_schedules.md`.
     */
    public function paginate(int $customerId, int $cohortId, int $perPage = 10): LengthAwarePaginator
    {
        $schedules = DB::table('core_liveclass_schedules')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohortId)
            ->orderBy('starts_on')->orderBy('id')
            ->paginate($perPage, ['*'], 'schedule_page')
            ->withQueryString();

        $scheduleIds = $schedules->getCollection()->pluck('id');
        if ($scheduleIds->isEmpty()) {
            return $schedules;
        }

        $slots = $this->slotsFor($customerId, $scheduleIds)->groupBy('schedule_id');
        $exclusions = $this->exclusionsFor($customerId, $scheduleIds)->groupBy('schedule_id');

        $schedules->getCollection()->each(function (object $schedule) use ($slots, $exclusions): void {
            $schedule->slots = $slots->get($schedule->id, collect())->values();
            $schedule->exclusions = $exclusions->get($schedule->id, collect())->values();
            $schedule->preview_count = $this->previewService->calculate(
                $schedule->starts_on, $schedule->ends_on, $schedule->timezone,
                $schedule->slots, $schedule->exclusions
            )->count();
            $schedule->derived_status = $this->previewService->derivedStatus(
                $schedule->starts_on, $schedule->ends_on, $schedule->timezone
            );
        });

        return $schedules;
    }

    /**
     * State for the inline Schedule view/edit panel.
     *
     * `$mode` is `view`, `edit` or null; only `view` needs the full projected
     * occurrence list, so `edit` does not pay for the calculation.
     *
     * @return array<string, mixed>
     */
    public function formState(int $customerId, int $cohortId, ?string $mode, int $scheduleId): array
    {
        $empty = [
            'scheduleFormSchedule' => null,
            'scheduleFormSlots' => collect(),
            'scheduleFormExclusions' => collect(),
            'scheduleFormPreview' => collect(),
            'scheduleFormDerivedStatus' => null,
        ];

        if (! in_array($mode, ['view', 'edit'], true)) {
            return $empty;
        }

        $schedule = DB::table('core_liveclass_schedules')
            ->where('customer_id', $customerId)
            ->where('cohort_id', $cohortId)
            ->where('id', $scheduleId)
            ->firstOrFail();
        $slots = $this->slotsFor($customerId, collect([$schedule->id]));
        $exclusions = $this->exclusionsFor($customerId, collect([$schedule->id]));

        return array_merge($empty, [
            'scheduleFormSchedule' => $schedule,
            'scheduleFormSlots' => $slots,
            'scheduleFormExclusions' => $exclusions,
            'scheduleFormPreview' => $mode === 'view'
                ? $this->previewService->calculate(
                    $schedule->starts_on, $schedule->ends_on, $schedule->timezone, $slots, $exclusions
                )
                : collect(),
            'scheduleFormDerivedStatus' => $mode === 'view'
                ? $this->previewService->derivedStatus(
                    $schedule->starts_on, $schedule->ends_on, $schedule->timezone
                )
                : null,
        ]);
    }

    /**
     * @param  Collection<int, mixed>  $scheduleIds
     * @return Collection<int, object>
     */
    private function slotsFor(int $customerId, Collection $scheduleIds): Collection
    {
        return DB::table('core_liveclass_schedule_slots')
            ->where('customer_id', $customerId)->whereIn('schedule_id', $scheduleIds)
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, mixed>  $scheduleIds
     * @return Collection<int, object>
     */
    private function exclusionsFor(int $customerId, Collection $scheduleIds): Collection
    {
        return DB::table('core_liveclass_schedule_exclusions')
            ->where('customer_id', $customerId)->whereIn('schedule_id', $scheduleIds)
            ->orderBy('excluded_on')->orderBy('id')->get();
    }
}
