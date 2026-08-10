<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model for the LiveClass data shown on a Cohort detail page.
 *
 * These queries used to live as private methods on `CourseCohortController` —
 * a Course-domain controller reading five LiveClass tables directly. Keeping
 * them here restores the domain boundary ADR-0002 draws, and makes the data
 * reachable from any future consumer (a Teacher view, an export) without
 * copying the query.
 *
 * Deliberately stateless. An earlier version cached results on the instance to
 * stop the Cohort detail page loading the same Session list twice, but the
 * instance outlives a single request under a persistent container (Octane, and
 * observably inside a feature test), so the cache served stale rows after a
 * write. The caller now loads once and passes the collection down instead.
 */
class LiveClassCohortSessionReadModel
{
    public function __construct(
        private readonly LiveClassSessionOriginService $originService,
        private readonly LiveClassSessionPolicy $sessionPolicy,
        private readonly LiveClassSchedulePreviewService $previewService
    ) {}

    /**
     * Every Session of a Cohort, decorated with its teaching team, its
     * Schedule-relationship label and whether it may still be edited.
     *
     * @return Collection<int, object>
     */
    public function forCohort(int $customerId, int $cohortId): Collection
    {
        $sessions = DB::table('core_liveclass_sessions as sessions')
            ->leftJoin('core_liveclass_session_schedule_origins as origins', function ($join) use ($customerId): void {
                $join->on('origins.session_id', '=', 'sessions.id')
                    ->where('origins.customer_id', '=', $customerId);
            })
            ->leftJoin('core_course_template_version_lessons as lessons', function ($join) use ($customerId): void {
                $join->on('lessons.id', '=', 'sessions.version_lesson_id')
                    ->where('lessons.customer_id', '=', $customerId);
            })
            ->leftJoin('core_course_template_version_activities as activities', function ($join) use ($customerId): void {
                $join->on('activities.id', '=', 'sessions.version_activity_id')
                    ->where('activities.customer_id', '=', $customerId);
            })
            ->leftJoin('users as teachers', function ($join) use ($customerId): void {
                $join->on('teachers.id', '=', 'sessions.primary_teacher_id')
                    ->where('teachers.customer_id', '=', $customerId);
            })
            ->where('sessions.customer_id', $customerId)
            ->where('sessions.cohort_id', $cohortId)
            ->orderBy('sessions.scheduled_start_at')->orderBy('sessions.id')
            ->select(
                'sessions.*',
                'lessons.title_snapshot as lesson_title',
                'activities.title_snapshot as activity_title',
                'activities.live_class_url_snapshot as activity_meeting_url',
                'teachers.name as primary_teacher_name',
                'origins.id as schedule_origin_id',
                'origins.schedule_id as source_schedule_id',
                'origins.source_start_at', 'origins.source_end_at', 'origins.source_timezone'
            )
            ->get();

        if ($sessions->isEmpty()) {
            return $sessions;
        }

        $sessionIds = $sessions->pluck('id');
        $teamBySession = DB::table('core_liveclass_session_teachers as assignments')
            ->join('users as teachers', function ($join) use ($customerId): void {
                $join->on('teachers.id', '=', 'assignments.teacher_id')
                    ->where('teachers.customer_id', '=', $customerId);
            })
            ->where('assignments.customer_id', $customerId)
            ->whereIn('assignments.session_id', $sessionIds)
            ->orderByRaw("CASE assignments.role WHEN 'primary_teacher' THEN 0 WHEN 'teacher' THEN 1 ELSE 2 END")
            ->orderBy('teachers.name')
            ->get(['assignments.session_id', 'assignments.teacher_id', 'assignments.role', 'teachers.name'])
            ->groupBy('session_id');
        $sessionsWithEvidence = $this->sessionIdsWithEvidence($customerId, $sessionIds);
        $now = now();

        $sessions->each(function (object $session) use ($teamBySession, $sessionsWithEvidence, $now): void {
            $team = $teamBySession->get($session->id, collect())->values();
            $session->teacher_team = $team;
            $teacherIds = $team->pluck('teacher_id')->map(fn ($id): string => (string) $id);
            if ($session->primary_teacher_id && ! $teacherIds->contains((string) $session->primary_teacher_id)) {
                $teacherIds->prepend((string) $session->primary_teacher_id);
            }
            $session->teacher_ids = $teacherIds->unique()->values();
            $session->schedule_relation = $this->originService->classify($session);
            $session->can_edit = $this->sessionPolicy->canEdit(
                $session,
                $sessionsWithEvidence->contains((int) $session->id),
                $now
            );
        });

        return $sessions;
    }

    /**
     * Projected Schedule occurrences from today onwards, flagged with whether
     * their occurrence identity has already been consumed by a Session.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function plannedOccurrences(int $customerId, int $cohortId, int $limit = 100): Collection
    {
        $schedules = DB::table('core_liveclass_schedules')
            ->where('customer_id', $customerId)->where('cohort_id', $cohortId)
            ->whereDate('ends_on', '>=', now()->toDateString())
            ->orderBy('starts_on')->get();

        if ($schedules->isEmpty()) {
            return collect();
        }

        // Loaded in two grouped queries instead of two per Schedule: the tab
        // previously ran a slot query and an exclusion query inside the loop.
        $scheduleIds = $schedules->pluck('id');
        $slots = DB::table('core_liveclass_schedule_slots')
            ->where('customer_id', $customerId)->whereIn('schedule_id', $scheduleIds)
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('schedule_id');
        $exclusions = DB::table('core_liveclass_schedule_exclusions')
            ->where('customer_id', $customerId)->whereIn('schedule_id', $scheduleIds)
            ->get()->groupBy('schedule_id');
        $consumed = DB::table('core_liveclass_session_schedule_origins')
            ->where('customer_id', $customerId)->whereIn('schedule_id', $scheduleIds)
            ->get(['schedule_id', 'schedule_slot_id', 'source_local_date'])
            ->mapWithKeys(fn (object $origin): array => [
                implode('|', [$origin->schedule_id, $origin->schedule_slot_id, $origin->source_local_date]) => true,
            ]);

        $occurrences = collect();

        foreach ($schedules as $schedule) {
            $preview = $this->previewService->calculate(
                max($schedule->starts_on, now($schedule->timezone)->toDateString()),
                $schedule->ends_on,
                $schedule->timezone,
                $slots->get($schedule->id, collect()),
                $exclusions->get($schedule->id, collect())
            );

            foreach ($preview as $occurrence) {
                $occurrences->push(array_merge($occurrence, [
                    'schedule_id' => (int) $schedule->id,
                    'schedule_name' => $schedule->name,
                    'timezone' => $schedule->timezone,
                    'consumed' => $consumed->has(
                        implode('|', [$schedule->id, $occurrence['schedule_slot_id'], $occurrence['date']])
                    ),
                ]));
            }
        }

        return $occurrences->take($limit)->values();
    }

    /**
     * @param  Collection<int, mixed>  $sessionIds
     * @return Collection<int, int>
     */
    private function sessionIdsWithEvidence(int $customerId, Collection $sessionIds): Collection
    {
        return DB::table('core_liveclass_attendances')
            ->where('customer_id', $customerId)->whereIn('session_id', $sessionIds)->pluck('session_id')
            ->merge(DB::table('core_liveclass_recordings')
                ->where('customer_id', $customerId)->whereIn('session_id', $sessionIds)->pluck('session_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique();
    }
}
