<?php

namespace App\Services;

use Carbon\CarbonImmutable;
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
     * occurrence count, how many of those occurrences already have a Session and
     * date-derived status.
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
        $origins = DB::table('core_liveclass_session_schedule_origins')
            ->where('customer_id', $customerId)->whereIn('schedule_id', $scheduleIds)
            ->get(['schedule_id', 'schedule_slot_id', 'source_local_date'])
            ->groupBy('schedule_id');

        $schedules->getCollection()->each(function (object $schedule) use ($slots, $exclusions, $origins): void {
            $schedule->slots = $slots->get($schedule->id, collect())->values();
            $schedule->exclusions = $exclusions->get($schedule->id, collect())->values();
            $preview = $this->previewService->calculate(
                $schedule->starts_on, $schedule->ends_on, $schedule->timezone,
                $schedule->slots, $schedule->exclusions
            );
            $schedule->preview_count = $preview->count();
            // Only occurrences still in the plan count: an Origin outlives a later
            // Schedule edit, and "6/4" would read as a progress bar gone wrong.
            $planned = $preview->mapWithKeys(fn (array $item): array => [$item['schedule_slot_id'].'|'.$item['date'] => true]);
            $schedule->created_session_count = $origins->get($schedule->id, collect())
                ->filter(fn (object $origin): bool => $planned->has($origin->schedule_slot_id.'|'.substr((string) $origin->source_local_date, 0, 10)))
                ->count();
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
            'scheduleFormPlan' => null,
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

        $derivedStatus = $mode === 'view'
            ? $this->previewService->derivedStatus($schedule->starts_on, $schedule->ends_on, $schedule->timezone)
            : null;

        return array_merge($empty, [
            'scheduleFormSchedule' => $schedule,
            'scheduleFormSlots' => $slots,
            'scheduleFormExclusions' => $exclusions,
            'scheduleFormPlan' => $mode === 'view' ? $this->detailPlan($schedule, $slots, $exclusions, $derivedStatus) : null,
            'scheduleFormDerivedStatus' => $derivedStatus,
        ]);
    }

    /**
     * What the detail popup shows: every projected occurrence, the excluded ones
     * marked instead of dropped so a date off stays visible where it falls. Only
     * for reading: the preview contract without exclusions is unchanged, and
     * "past" and "next" are judged in the Schedule's own timezone.
     *
     * @return array<string, mixed>
     */
    private function detailPlan(object $schedule, Collection $slots, Collection $exclusions, string $status): array
    {
        $timezone = $schedule->timezone;
        $now = CarbonImmutable::now($timezone);
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $schedule->starts_on, $timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $schedule->ends_on, $timezone);
        $reasons = $exclusions->mapWithKeys(fn (object $item): array => [substr((string) $item->excluded_on, 0, 10) => $item->reason]);

        $number = 0;
        $nextFound = false;
        $items = $this->previewService->calculate($schedule->starts_on, $schedule->ends_on, $timezone, $slots)
            ->map(function (array $occurrence) use ($reasons, $now, $status, &$number, &$nextFound): array {
                $excluded = $reasons->has($occurrence['date']);
                $endsAt = CarbonImmutable::parse($occurrence['ends_at']);
                $past = ! $excluded && $endsAt->lte($now);
                $next = ! $excluded && ! $past && ! $nextFound && $status === 'current';
                $nextFound = $nextFound || $next;

                return array_merge($occurrence, [
                    'excluded' => $excluded,
                    'reason' => $excluded ? $reasons->get($occurrence['date']) : null,
                    'past' => $past,
                    'next' => $next,
                    'number' => $excluded ? null : ++$number,
                    'minutes' => (int) CarbonImmutable::parse($occurrence['starts_at'])->diffInMinutes($endsAt),
                ]);
            })->values();
        $sessions = $items->reject(fn (array $item): bool => $item['excluded']);

        $months = [];
        for ($first = $start->startOfMonth(); $first->lte($end); $first = $first->addMonthNoOverflow()) {
            $inMonth = $items->filter(fn (array $item): bool => str_starts_with($item['date'], $first->format('Y-m')));
            $cells = array_fill(0, $first->dayOfWeekIso - 1, null);
            for ($day = $first; $day->month === $first->month; $day = $day->addDay()) {
                $cells[] = [
                    'day' => $day->day,
                    'out' => $day->lt($start) || $day->gt($end),
                    'today' => $day->isSameDay($now),
                    'items' => $inMonth->where('date', $day->format('Y-m-d'))->values()->all(),
                ];
            }
            $months[] = [
                'key' => $first->format('Y-m'),
                'year' => $first->year,
                'month' => $first->month,
                'count' => $inMonth->reject(fn (array $item): bool => $item['excluded'])->count(),
                'cells' => array_merge($cells, array_fill(0, (7 - count($cells) % 7) % 7, null)),
            ];
        }

        $weeks = $items
            ->groupBy(function (array $item) use ($timezone): string {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $item['date'], $timezone);

                return $date->subDays($date->dayOfWeekIso - 1)->format('Y-m-d');
            })
            ->map(fn (Collection $week, string $monday): array => [
                'from' => $monday,
                'to' => CarbonImmutable::parse($monday)->addDays(6)->format('Y-m-d'),
                'count' => $week->reject(fn (array $item): bool => $item['excluded'])->count(),
                'items' => $week->all(),
            ])->values()->all();

        $next = $items->firstWhere('next', true);
        $focusMonth = $next ? substr($next['date'], 0, 7) : $now->format('Y-m');
        $initialMonth = array_search($focusMonth, array_column($months, 'key'), true);

        return [
            'days' => (int) $start->diffInDays($end) + 1,
            'session_count' => $sessions->count(),
            'past_count' => $sessions->where('past', true)->count(),
            'next' => $next,
            'total_minutes' => $sessions->sum('minutes'),
            'timezone_offset' => 'GMT'.$now->format('P'),
            'months' => $months,
            'initial_month' => $initialMonth === false ? 0 : $initialMonth,
            'weeks' => $weeks,
            'exclusions' => $exclusions
                ->map(function (object $item) use ($items, $timezone): array {
                    $date = substr((string) $item->excluded_on, 0, 10);

                    return [
                        'date' => $date,
                        'weekday' => CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)->dayOfWeekIso,
                        'reason' => $item->reason,
                        'hits' => $items->where('date', $date)->count(),
                    ];
                })
                ->sortBy('date')->values()->all(),
        ];
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
