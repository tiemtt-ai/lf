<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class LiveClassSchedulePreviewService
{
    public function calculate(
        string $startsOn,
        string $endsOn,
        string $timezone,
        iterable $slots,
        iterable $exclusions = []
    ): Collection {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $startsOn, $timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $endsOn, $timezone);
        $excludedDates = collect($exclusions)
            ->map(fn ($exclusion): string => $this->value($exclusion, 'excluded_on'))
            ->filter()->flip();
        $slotsByWeekday = collect($slots)
            ->sortBy(fn ($slot): string => sprintf(
                '%02d-%s-%s-%06d',
                (int) $this->value($slot, 'weekday'),
                $this->time($this->value($slot, 'start_time')),
                $this->time($this->value($slot, 'end_time')),
                (int) ($this->value($slot, 'sort_order') ?: 0)
            ))
            ->groupBy(fn ($slot): int => (int) $this->value($slot, 'weekday'));
        $occurrences = collect();

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $localDate = $date->format('Y-m-d');
            if ($excludedDates->has($localDate)) {
                continue;
            }

            foreach ($slotsByWeekday->get($date->isoWeekday(), collect()) as $slot) {
                $startTime = $this->time($this->value($slot, 'start_time'));
                $endTime = $this->time($this->value($slot, 'end_time'));
                $startsAt = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$localDate} {$startTime}", $timezone);
                $endsAt = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$localDate} {$endTime}", $timezone);

                $occurrences->push([
                    'schedule_slot_id' => $this->value($slot, 'id'),
                    'date' => $localDate,
                    'weekday' => $date->isoWeekday(),
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'starts_at' => $startsAt->format('Y-m-d\TH:i:sP'),
                    'ends_at' => $endsAt->format('Y-m-d\TH:i:sP'),
                    'timezone' => $timezone,
                ]);
            }
        }

        return $occurrences->values();
    }

    public function derivedStatus(string $startsOn, string $endsOn, string $timezone): string
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $startsOn, $timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $endsOn, $timezone);

        return match (true) {
            $today->lt($start) => 'upcoming',
            $today->gt($end) => 'ended',
            default => 'current',
        };
    }

    private function value(mixed $item, string $key): mixed
    {
        return is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
    }

    private function time(mixed $value): string
    {
        return substr((string) $value, 0, 5);
    }
}
