<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saving a Schedule turns its free dates into Sessions, in programme order, for
 * the live Activities that have none (ADR-0002 § Automatic Session Generation
 * Amendment). The pairing is the one the Sessions tab shows, so what the admin
 * sees and what is stored cannot differ.
 *
 * Existing Sessions and their Origins are never touched. Call it inside the
 * transaction saving the Schedule, so that either both are stored or neither.
 */
class LiveClassScheduleSessionGenerator
{
    public function __construct(
        private readonly CourseCohortContentTree $contentTree,
        private readonly LiveClassCohortSessionReadModel $sessionReadModel,
        private readonly LiveClassSessionOriginService $originService,
        private readonly CourseCohortVersionResolver $versionResolver
    ) {}

    /** @return list<int> the Sessions created */
    public function generate(int $customerId, int $cohortId, int $actorId): array
    {
        $cohort = DB::table('core_course_cohorts')->where('customer_id', $customerId)
            ->where('id', $cohortId)->lockForUpdate()->firstOrFail();
        if (! $cohort->version_id || ! $this->versionResolver->runsInClass($customerId, (int) $cohort->version_id)) {
            return [];
        }

        // Two Schedules of a Cohort may share a time. A date overlapping one just
        // created ends the pass: the next one no longer offers it. A pass also sees
        // at most the first free occurrences the read model lists (100), so a long
        // programme needs further passes; each one that creates nothing ends it.
        $created = [];
        do {
            [$ids] = $this->pass($customerId, $cohort, $actorId);
            $created = [...$created, ...$ids];
        } while ($ids !== []);

        // Every live Activity must get a date: refused here, the Schedule is not saved either.
        $missing = count($this->proposals($customerId, $cohort));
        if ($missing > 0) {
            throw ValidationException::withMessages([
                'slots' => __('lf.LF_course_cohort_schedule_not_enough_dates', ['missing' => $missing]),
            ]);
        }

        return $created;
    }

    /**
     * For the form, before saving: how many dates the programme still lacks with these occurrences.
     * It counts dates only, so it may say 0 where saving still refuses, for example on overlapping Schedules.
     *
     * @param  Collection<int, array<string, mixed>>  $preview  occurrences of the Schedule being edited or created
     */
    public function missingDates(int $customerId, int $cohortId, ?int $scheduleId, Collection $preview): int
    {
        $cohort = DB::table('core_course_cohorts')->where('customer_id', $customerId)->where('id', $cohortId)->first();
        if (! $cohort?->version_id || ! $this->versionResolver->runsInClass($customerId, (int) $cohort->version_id)) {
            return 0;
        }

        $future = fn (array $occurrence): bool => CarbonImmutable::parse($occurrence['starts_at'])->gt(now());
        $used = DB::table('core_liveclass_session_schedule_origins')
            ->where('customer_id', $customerId)->where('schedule_id', (int) $scheduleId)
            ->get(['source_local_date', 'source_local_start_time'])
            ->map(fn (object $origin): string => substr((string) $origin->source_local_date, 0, 10).'|'.substr((string) $origin->source_local_start_time, 0, 5))
            ->flip();
        $own = $preview->filter(fn (array $occurrence): bool => $future($occurrence)
            && ! $used->has($occurrence['date'].'|'.substr((string) $occurrence['start_time'], 0, 5)))->count();
        $others = $this->sessionReadModel->plannedOccurrences($customerId, $cohortId)
            ->filter(fn (array $occurrence): bool => (int) $occurrence['schedule_id'] !== (int) $scheduleId
                && ! $occurrence['consumed'] && $future($occurrence))->count();

        return max(0, count($this->proposals($customerId, $cohort)) - $own - $others);
    }

    /** @return array<int|string, array<string, mixed>> */
    private function proposals(int $customerId, object $cohort): array
    {
        return $this->contentTree->proposals(
            $customerId,
            (int) $cohort->id,
            (int) $cohort->version_id,
            $this->sessionReadModel->forCohort($customerId, (int) $cohort->id),
            $this->sessionReadModel->plannedOccurrences($customerId, (int) $cohort->id)
        );
    }

    /** @return array{0: list<int>, 1: bool} */
    private function pass(int $customerId, object $cohort, int $actorId): array
    {
        $proposals = $this->proposals($customerId, $cohort);
        $nextSessionNo = (int) DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)->where('cohort_id', $cohort->id)
            ->lockForUpdate()->max('session_no') + 1;
        $placed = [];
        $ids = [];

        foreach ($proposals as $proposal) {
            $occurrence = $proposal['occurrence'];
            if (! $occurrence) {
                continue;
            }
            try {
                $origin = $this->originService->resolve(
                    $customerId, (int) $cohort->id, (int) $occurrence['schedule_id'],
                    (int) $occurrence['schedule_slot_id'], $occurrence['date'], true
                );
            } catch (ValidationException) {
                continue;
            }

            $start = LiveClassSessionTime::utc($origin['scheduled_start_at'], $origin['timezone']);
            $end = LiveClassSessionTime::utc($origin['scheduled_end_at'], $origin['timezone']);
            $window = CourseCohortSessionWindow::for($cohort, $origin['timezone']);
            if (! $window || $start->lt($window['min']) || $end->gt($window['max'])) {
                continue;
            }
            foreach ($placed as [$placedStart, $placedEnd]) {
                if (LiveClassSessionTime::overlaps($placedStart, $placedEnd, $start, $end)) {
                    return [$ids, true];
                }
            }

            $ids[] = $this->store($customerId, $cohort, $proposal, $origin, $nextSessionNo++, $actorId);
            $placed[] = [$start, $end];
        }

        return [$ids, false];
    }

    /**
     * The default box of ADR-0023 §3: the Lesson's title, online and the Activity's
     * link, with the Cohort's teachers of that date as its team.
     *
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $origin
     */
    private function store(int $customerId, object $cohort, array $proposal, array $origin, int $sessionNo, int $actorId): int
    {
        $now = now();
        $sessionId = DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $customerId,
            'cohort_id' => $cohort->id,
            'template_version_id' => $cohort->version_id,
            'room_id' => null,
            'session_no' => $sessionNo,
            'session_type' => 'curriculum',
            'version_lesson_id' => (int) $proposal['lesson_id'],
            'version_activity_id' => (int) $proposal['activity_id'],
            'primary_teacher_id' => null,
            'title' => trim($proposal['lesson_title']),
            'delivery_mode' => 'online',
            'scheduled_start_at' => $origin['scheduled_start_at'],
            'scheduled_end_at' => $origin['scheduled_end_at'],
            'timezone' => $origin['timezone'],
            'online_provider' => LiveClassMeetingProvider::fromUrl($proposal['meeting_url']),
            'meeting_url_snapshot' => $proposal['meeting_url'],
            'status' => 'scheduled',
            'created_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('core_liveclass_session_schedule_origins')->insert([
            'customer_id' => $customerId,
            'session_id' => $sessionId,
            'schedule_id' => $origin['schedule_id'],
            'schedule_slot_id' => $origin['schedule_slot_id'],
            'source_local_date' => $origin['source_local_date'],
            'source_local_start_time' => $origin['source_local_start_time'],
            'source_local_end_time' => $origin['source_local_end_time'],
            'source_timezone' => $origin['source_timezone'],
            'source_start_at' => $origin['source_start_at'],
            'source_end_at' => $origin['source_end_at'],
            'created_by' => $actorId,
            'created_at' => $now,
        ]);

        $date = $origin['source_local_date'];
        $team = DB::table('core_course_cohort_teachers')
            ->where('customer_id', $customerId)->where('cohort_id', $cohort->id)->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('assigned_from')->orWhereDate('assigned_from', '<=', $date))
            ->where(fn ($query) => $query->whereNull('assigned_to')->orWhereDate('assigned_to', '>=', $date))
            ->get(['teacher_id', 'role']);
        if ($team->isNotEmpty()) {
            DB::table('core_liveclass_session_teachers')->insert($team->map(fn (object $teacher): array => [
                'customer_id' => $customerId,
                'session_id' => $sessionId,
                'teacher_id' => $teacher->teacher_id,
                'role' => $teacher->role === 'assistant' ? 'assistant' : 'teacher',
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }

        return $sessionId;
    }
}
