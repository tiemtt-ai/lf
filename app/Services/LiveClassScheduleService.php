<?php

namespace App\Services;

use App\Support\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LiveClassScheduleService
{
    /** A Session with a row in any of these has been used, so it and its Schedule stay. */
    private const EVIDENCE_TABLES = [
        'core_liveclass_attendances', 'core_liveclass_attendance_changes', 'core_liveclass_recordings', 'core_liveclass_replays',
    ];

    public function __construct(
        private readonly LiveClassSchedulePreviewService $preview,
        private readonly LiveClassSessionPolicy $sessionPolicy
    ) {}

    /**
     * Whether the Schedule may be deleted now (ADR-0002 § Schedule Deletion Amendment): every Session it produced
     * must still be unused — scheduled, ahead of its start, never moved, never replaced, without any evidence.
     * Returns the Sessions that would go with it and, when one of them is used, why the Schedule stays.
     *
     * @return array{sessions: Collection<int, object>, blocker: ?string}
     */
    public function deletion(int $customerId, int $scheduleId, bool $lock = false): array
    {
        $sessions = DB::table('core_liveclass_sessions as sessions')
            ->join('core_liveclass_session_schedule_origins as origins', function ($join) use ($customerId): void {
                $join->on('origins.session_id', '=', 'sessions.id')->where('origins.customer_id', $customerId);
            })
            ->where('sessions.customer_id', $customerId)->where('origins.schedule_id', $scheduleId)
            ->orderBy('sessions.scheduled_start_at')->orderBy('sessions.id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['sessions.*']);
        $ids = $sessions->pluck('id')->map(fn ($id): int => (int) $id);
        if ($ids->isEmpty()) {
            return ['sessions' => $sessions, 'blocker' => null];
        }
        $used = collect(self::EVIDENCE_TABLES)->flatMap(fn (string $table) => DB::table($table)
            ->where('customer_id', $customerId)->whereIn('session_id', $ids)->distinct()->pluck('session_id'))
            ->map(fn ($id): int => (int) $id)->flip();
        $moved = DB::table('core_liveclass_session_schedule_changes')->where('customer_id', $customerId)
            ->whereIn('session_id', $ids)->distinct()->pluck('session_id')->map(fn ($id): int => (int) $id)->flip();
        $replacing = DB::table('core_liveclass_sessions')->where('customer_id', $customerId)
            ->whereIn('superseded_by_session_id', $ids)->pluck('superseded_by_session_id')->map(fn ($id): int => (int) $id)->flip();
        $now = now();

        foreach ($sessions as $session) {
            $id = (int) $session->id;
            $reason = match (true) {
                in_array($session->status, ['cancelled', 'no_show'], true) => 'cancelled',
                $used->has($id) => 'evidence',
                ! $this->sessionPolicy->canReschedule($session, false, $now) => 'started',
                $moved->has($id) => 'rescheduled',
                $session->superseded_by_session_id !== null || $replacing->has($id) => 'replaced',
                default => null,
            };
            if ($reason !== null) {
                return ['sessions' => $sessions, 'blocker' => __('lf.LF_course_cohort_schedule_delete_blocked', [
                    'number' => $session->session_no,
                    'date' => Carbon::parse($session->scheduled_start_at)->format('d/m/Y H:i'),
                    'reason' => __('lf.LF_course_cohort_schedule_delete_blocked_'.$reason),
                ])];
            }
        }

        return ['sessions' => $sessions, 'blocker' => null];
    }

    /**
     * Deletes the Schedule with its Slots, Exclusions and the unused Sessions it produced, together with their
     * Origins and teacher assignments, in one transaction, and audits what went. Refuses, changing nothing, while
     * the Cohort is closed or any of those Sessions has been used.
     *
     * @return int how many Sessions were deleted
     */
    public function delete(Request $request, int $customerId, int $cohortId, int $scheduleId): int
    {
        return DB::transaction(function () use ($request, $customerId, $cohortId, $scheduleId): int {
            $cohort = DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $cohortId)->lockForUpdate()->firstOrFail();
            if (! CourseCohortMutationPolicy::canMutate($cohort)) {
                throw ValidationException::withMessages(['schedule' => __('lf.LF_course_cohort_schedule_delete_closed')]);
            }
            $schedule = DB::table('core_liveclass_schedules')->where('customer_id', $customerId)
                ->where('cohort_id', $cohortId)->where('id', $scheduleId)->lockForUpdate()->firstOrFail();
            ['sessions' => $sessions, 'blocker' => $blocker] = $this->deletion($customerId, $scheduleId, true);
            if ($blocker !== null) {
                throw ValidationException::withMessages(['schedule' => $blocker]);
            }
            $sessionIds = $sessions->pluck('id')->all();
            $slots = DB::table('core_liveclass_schedule_slots')->where('customer_id', $customerId)
                ->where('schedule_id', $scheduleId)->orderBy('sort_order')->get(['weekday', 'start_time', 'end_time']);

            if ($sessionIds !== []) {
                DB::table('core_liveclass_session_teachers')->where('customer_id', $customerId)->whereIn('session_id', $sessionIds)->delete();
                DB::table('core_liveclass_session_schedule_origins')->where('customer_id', $customerId)->whereIn('session_id', $sessionIds)->delete();
                DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->whereIn('id', $sessionIds)->delete();
            }
            DB::table('core_liveclass_schedule_exclusions')->where('customer_id', $customerId)->where('schedule_id', $scheduleId)->delete();
            DB::table('core_liveclass_schedule_slots')->where('customer_id', $customerId)->where('schedule_id', $scheduleId)->delete();
            DB::table('core_liveclass_schedules')->where('customer_id', $customerId)->where('id', $scheduleId)->delete();

            AuditLog::record($request, $customerId, 'liveclass_schedule.deleted', null, [
                'schedule_id' => (int) $schedule->id, 'cohort_id' => $cohortId, 'name' => $schedule->name,
                'starts_on' => substr((string) $schedule->starts_on, 0, 10), 'ends_on' => substr((string) $schedule->ends_on, 0, 10),
                'timezone' => $schedule->timezone,
                'slots' => $slots->map(fn (object $slot): array => [
                    'weekday' => (int) $slot->weekday, 'start_time' => substr((string) $slot->start_time, 0, 5), 'end_time' => substr((string) $slot->end_time, 0, 5),
                ])->all(),
                'sessions' => $sessions->map(fn (object $session): array => [
                    'id' => (int) $session->id, 'session_no' => (int) $session->session_no,
                    'scheduled_start_at' => (string) $session->scheduled_start_at, 'scheduled_end_at' => (string) $session->scheduled_end_at,
                ])->all(),
            ], null);

            return count($sessionIds);
        }, 3);
    }

    public function create(int $customerId, int $cohortId, int $actorId, array $data): int
    {
        return DB::transaction(function () use ($customerId, $cohortId, $actorId, $data): int {
            DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $cohortId)->lockForUpdate()->firstOrFail();
            $this->assertNoOverlapWithOtherSchedules($customerId, $cohortId, null, $data);
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
            $this->assertNoOverlapWithOtherSchedules($customerId, $cohortId, $scheduleId, $data);
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

    /**
     * Two Schedules of one Cohort may not share a time (LF-Core-LiveClass § Lịch học trùng giờ, 2026-11-19): the
     * Sessions tab could not hold both, and Session generation would skip the clashing date without a word.
     * Occurrences are compared as instants after exclusions; touching boundaries do not overlap. The caller holds
     * the Cohort lock, so a concurrent save of another Schedule waits for this one.
     */
    private function assertNoOverlapWithOtherSchedules(int $customerId, int $cohortId, ?int $scheduleId, array $data): void
    {
        $others = DB::table('core_liveclass_schedules')->where('customer_id', $customerId)->where('cohort_id', $cohortId)
            ->when($scheduleId, fn ($query) => $query->where('id', '!=', $scheduleId))
            // Occurrences are same-day local intervals, so a day either side covers any timezone difference.
            ->where('starts_on', '<=', CarbonImmutable::parse($data['ends_on'])->addDay()->toDateString())
            ->where('ends_on', '>=', CarbonImmutable::parse($data['starts_on'])->subDay()->toDateString())
            ->orderBy('id')->get(['id', 'name', 'starts_on', 'ends_on', 'timezone']);
        if ($others->isEmpty()) {
            return;
        }
        $mine = $this->intervals($this->preview->calculate(
            $data['starts_on'], $data['ends_on'], $data['timezone'], $data['slots'], $data['exclusions'] ?? []
        ));
        if ($mine === []) {
            return;
        }
        $slots = DB::table('core_liveclass_schedule_slots')->where('customer_id', $customerId)
            ->whereIn('schedule_id', $others->pluck('id'))->get()->groupBy('schedule_id');
        $exclusions = DB::table('core_liveclass_schedule_exclusions')->where('customer_id', $customerId)
            ->whereIn('schedule_id', $others->pluck('id'))->get()->groupBy('schedule_id');

        foreach ($others as $other) {
            $theirs = $this->intervals($this->preview->calculate(
                substr((string) $other->starts_on, 0, 10), substr((string) $other->ends_on, 0, 10), $other->timezone,
                $slots->get($other->id, collect())->all(),
                $exclusions->get($other->id, collect())->map(fn ($row): array => ['excluded_on' => substr((string) $row->excluded_on, 0, 10)])->all()
            ));
            foreach ($mine as [$start, $end, $occurrence]) {
                foreach ($theirs as [$theirStart, $theirEnd]) {
                    if ($start->lt($theirEnd) && $end->gt($theirStart)) {
                        throw ValidationException::withMessages(['slots' => __('lf.LF_course_cohort_schedule_overlaps_other', [
                            'name' => $other->name,
                            'date' => CarbonImmutable::createFromFormat('!Y-m-d', $occurrence['date'])->format('d/m/Y'),
                            'start' => $occurrence['start_time'],
                            'end' => $occurrence['end_time'],
                        ])]);
                    }
                }
            }
        }
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable, 2: array<string, mixed>}> UTC start, UTC end, occurrence */
    private function intervals(iterable $occurrences): array
    {
        $intervals = [];
        foreach ($occurrences as $occurrence) {
            $intervals[] = [
                CarbonImmutable::parse($occurrence['starts_at'])->utc(),
                CarbonImmutable::parse($occurrence['ends_at'])->utc(),
                $occurrence,
            ];
        }

        return $intervals;
    }
}
