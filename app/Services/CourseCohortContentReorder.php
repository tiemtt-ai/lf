<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a Cohort's order of one group of Lessons or Activities (ADR-0023).
 *
 * A date belongs to its position, the list of live Activities in the Cohort's
 * order; everything else belongs to the content. So after a drag each position
 * keeps its Session row, with its time and Origin, and takes the box of the
 * Activity that now sits there: name, mode, link, room, address and teachers.
 * No time changes, so it needs no audit row.
 */
class CourseCohortContentReorder
{
    public function __construct(
        private readonly CourseCohortContentTree $contentTree,
        private readonly LiveClassCohortSessionReadModel $sessionReadModel,
        private readonly LiveClassSessionPolicy $sessionPolicy,
        private readonly LiveClassSessionOriginService $originService,
    ) {}

    /** @param  array<int, int>  $lessonIds  the complete group, in the new order */
    public function reorderLessons(int $customerId, object $cohort, ?int $sectionId, array $lessonIds, int $actorId): void
    {
        $this->inTransaction($customerId, $cohort, function (Collection $sessions) use ($customerId, $cohort, $sectionId, $lessonIds, $actorId): void {
            $versionId = (int) $cohort->version_id;
            $titles = DB::table('core_course_template_version_lessons')
                ->where('customer_id', $customerId)->where('template_version_id', $versionId)
                ->pluck('title_snapshot', 'id');
            $group = $this->contentTree->lessonsInGroup($customerId, (int) $cohort->id, $versionId, $sectionId)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if (! $this->sameMembers($group, $lessonIds)) {
                abort(409, __('lf.LF_course_template_lesson_reorder_stale'));
            }
            if ($group === $lessonIds) {
                return;
            }

            $locked = $sessions->filter(fn (object $session): bool => $session->locks_position)->pluck('version_lesson_id')->map(fn ($id): int => (int) $id)->unique();
            foreach ($locked as $lessonId) {
                if (in_array($lessonId, $group, true) && array_search($lessonId, $group, true) !== array_search($lessonId, $lessonIds, true)) {
                    $this->refuse('lesson_ids', __('lf.LF_course_cohort_order_lesson_locked', ['lesson' => $titles[$lessonId]]));
                }
            }
            $this->assertPrerequisitesStayBefore($customerId, $versionId, $lessonIds, $titles);

            $before = $this->contentTree->liveActivities($customerId, (int) $cohort->id, $versionId);
            $dates = $this->unsavedDates($customerId, $cohort, $sessions);
            $this->writeOrder('core_course_cohort_lesson_orders', 'version_lesson_id', $customerId, (int) $cohort->id, $lessonIds, $actorId);
            $this->followPositions($customerId, $cohort, $sessions, $before, $dates, $titles, $actorId);
        });
    }

    /** @param  array<int, int>  $activityIds  all the Activities of the Lesson, in the new order */
    public function reorderActivities(int $customerId, object $cohort, int $lessonId, array $activityIds, int $actorId): void
    {
        $this->inTransaction($customerId, $cohort, function (Collection $sessions) use ($customerId, $cohort, $lessonId, $activityIds, $actorId): void {
            $versionId = (int) $cohort->version_id;
            $titles = DB::table('core_course_template_version_lessons')
                ->where('customer_id', $customerId)->where('template_version_id', $versionId)
                ->pluck('title_snapshot', 'id');
            abort_unless($titles->has($lessonId), 404);
            $group = $this->contentTree->activitiesInLesson($customerId, (int) $cohort->id, $versionId, $lessonId);
            $groupIds = $group->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if (! $this->sameMembers($groupIds, $activityIds)) {
                abort(409, __('lf.LF_course_template_activity_reorder_stale'));
            }
            if ($groupIds === $activityIds) {
                return;
            }

            $locked = $sessions->filter(fn (object $session): bool => $session->locks_position)->pluck('version_activity_id')->map(fn ($id): int => (int) $id)->unique();
            foreach ($locked as $activityId) {
                if (in_array($activityId, $groupIds, true) && array_search($activityId, $groupIds, true) !== array_search($activityId, $activityIds, true)) {
                    $this->refuse('activity_ids', __('lf.LF_course_cohort_order_activity_locked', [
                        'activity' => $group->firstWhere('id', $activityId)->title_snapshot,
                    ]));
                }
            }

            $before = $this->contentTree->liveActivities($customerId, (int) $cohort->id, $versionId);
            $dates = $this->unsavedDates($customerId, $cohort, $sessions);
            $this->writeOrder('core_course_cohort_activity_orders', 'version_activity_id', $customerId, (int) $cohort->id, $activityIds, $actorId);
            $this->followPositions($customerId, $cohort, $sessions, $before, $dates, $titles, $actorId);
        });
    }

    /** Cohort first, then its Sessions, the order every Session write in the Cohort follows; §4 is judged inside the lock at one instant. */
    private function inTransaction(int $customerId, object $cohort, callable $reorder): void
    {
        DB::transaction(function () use ($customerId, $cohort, $reorder): void {
            DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $cohort->id)->lockForUpdate()->firstOrFail();

            $sessions = DB::table('core_liveclass_sessions')
                ->where('customer_id', $customerId)->where('cohort_id', $cohort->id)
                ->whereNotNull('version_activity_id')
                ->lockForUpdate()
                ->get();
            $evidence = $this->sessionReadModel->sessionIdsWithEvidence($customerId, $sessions->pluck('id'));
            $now = now();
            $sessions->each(function (object $session) use ($evidence, $now): void {
                $hasEvidence = $evidence->contains((int) $session->id);
                $session->locks_position = $this->sessionPolicy->locksPosition($session, $hasEvidence, $now);
                $session->can_edit = $this->sessionPolicy->canEdit($session, $hasEvidence, $now);
            });

            $reorder($sessions);
        });
    }

    /**
     * The Schedule dates the positions without a Session show before the drag.
     *
     * @param  Collection<int, object>  $sessions
     * @return array<int|string, array<string, mixed>>
     */
    private function unsavedDates(int $customerId, object $cohort, Collection $sessions): array
    {
        return $this->contentTree->proposals(
            $customerId,
            (int) $cohort->id,
            (int) $cohort->version_id,
            DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->where('cohort_id', $cohort->id)->get(),
            $this->sessionReadModel->plannedOccurrences($customerId, (int) $cohort->id)
        );
    }

    /**
     * @param  Collection<int, object>  $sessions
     * @param  Collection<int, object>  $before  the live Activities in the order before this drag
     * @param  array<int|string, array<string, mixed>>  $dates  from unsavedDates(), keyed by the Activity sitting there before the drag
     * @param  Collection<int|string, string>  $titles
     */
    private function followPositions(int $customerId, object $cohort, Collection $sessions, Collection $before, array $dates, Collection $titles, int $actorId): void
    {
        $after = $this->contentTree->liveActivities($customerId, (int) $cohort->id, (int) $cohort->version_id);
        $positionBefore = $before->pluck('id')->map(fn ($id): int => (int) $id)->flip();
        $positionAfter = $after->pluck('id')->map(fn ($id): int => (int) $id)->flip();

        // A live prerequisite may not end up behind the live Activity that needs it. An order the Version already had stands.
        foreach ($after as $activity) {
            $prerequisiteId = (int) $activity->unlock_after_version_activity_id;
            if ($prerequisiteId && $positionAfter->has($prerequisiteId)
                && $positionBefore[$prerequisiteId] < $positionBefore[(int) $activity->id]
                && $positionAfter[$prerequisiteId] > $positionAfter[(int) $activity->id]) {
                $this->refuse('order', __('lf.LF_course_cohort_order_activity_prerequisite', [
                    'activity' => $activity->title_snapshot,
                    'prerequisite' => $after->firstWhere('id', $prerequisiteId)->title_snapshot,
                ]));
            }
        }

        $sessionsByActivity = $sessions->groupBy(fn (object $session): int => (int) $session->version_activity_id);
        $savedAt = [];
        foreach ($before->values() as $index => $previous) {
            if ((int) $previous->id === (int) $after->get($index)->id) {
                continue;
            }
            $here = $sessionsByActivity->get((int) $previous->id, collect());
            if ($here->contains(fn (object $session): bool => $session->locks_position)) {
                $this->refuse('order', __('lf.LF_course_cohort_order_activity_locked', ['activity' => $previous->title_snapshot]));
            }
            // More than one Session would give this position several dates.
            $editable = $here->filter(fn (object $session): bool => $session->can_edit)->values();
            if ($editable->count() > 1) {
                $this->refuse('order', __('lf.LF_course_cohort_order_activity_many_sessions', ['activity' => $previous->title_snapshot]));
            }
            $savedAt[$index] = $editable->first();
        }

        // Read every box before any write: two positions may swap.
        $savedOf = collect($savedAt)->filter()->keyBy(fn (object $session): int => (int) $session->version_activity_id);
        $teams = DB::table('core_liveclass_session_teachers')
            ->where('customer_id', $customerId)->whereIn('session_id', $savedOf->pluck('id'))
            ->get(['session_id', 'teacher_id', 'role'])
            ->groupBy(fn (object $teacher): int => (int) $teacher->session_id);

        $writes = [];
        foreach ($savedAt as $index => $sessionHere) {
            $current = $after->get($index);
            $moving = $savedOf->get((int) $current->id);
            $previous = $before->get($index);
            $date = $dates[(int) $previous->id]['occurrence'] ?? null;
            if (! $sessionHere && ! $moving) {
                continue;
            }
            if (! $sessionHere && ! $date) {
                $this->refuse('order', __('lf.LF_course_cohort_order_no_date', ['activity' => $current->title_snapshot]));
            }
            $team = $moving ? $teams->get((int) $moving->id, collect()) : collect();
            $localDate = $sessionHere ? substr((string) $sessionHere->scheduled_start_at, 0, 10) : (string) $date['date'];
            $this->assertTeachersCanTeach($customerId, (int) $cohort->id, $team, $localDate);
            $writes[] = [$sessionHere, $date, $current, $this->boxOf($moving, $current, $titles, $cohort), $team];
        }

        foreach ($writes as [$sessionHere, $date, $current, $box, $team]) {
            $sessionId = $sessionHere
                ? $this->moveBoxOnto($customerId, $sessionHere, $box)
                : $this->saveAtDate($customerId, $cohort, $date, $box, $actorId);
            $this->replaceTeam($customerId, $sessionId, $team, $actorId);
        }
    }

    /**
     * What travels with the content. Content never scheduled brings what a new Session of it would start with.
     *
     * @param  Collection<int|string, string>  $titles
     * @return array<string, mixed>
     */
    private function boxOf(?object $session, object $activity, Collection $titles, object $cohort): array
    {
        $defaultUrl = ($cohort->default_meeting_url ?? null) ?: $activity->live_class_url_snapshot;

        return [
            'version_lesson_id' => $activity->version_lesson_id,
            'version_activity_id' => $activity->id,
            'title' => $session ? $session->title : $titles[$activity->version_lesson_id],
            'delivery_mode' => $session ? $session->delivery_mode : 'online',
            'online_provider' => $session ? $session->online_provider : LiveClassMeetingProvider::fromUrl($defaultUrl),
            'meeting_url_snapshot' => $session ? $session->meeting_url_snapshot : $defaultUrl,
            'facility_name_snapshot' => $session?->facility_name_snapshot,
            'room_name_snapshot' => $session?->room_name_snapshot,
            'address_snapshot' => $session?->address_snapshot,
            'room_id' => $session?->room_id,
        ];
    }

    /** @param  array<string, mixed>  $box */
    private function moveBoxOnto(int $customerId, object $session, array $box): int
    {
        DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->where('id', $session->id)
            ->update($box + ['updated_at' => now()]);

        return (int) $session->id;
    }

    /**
     * Saved content moved to a date not saved yet: saved there so nothing typed into it is lost.
     *
     * @param  array<string, mixed>  $date
     * @param  array<string, mixed>  $box
     */
    private function saveAtDate(int $customerId, object $cohort, array $date, array $box, int $actorId): int
    {
        $origin = $this->originService->resolve($customerId, (int) $cohort->id, (int) $date['schedule_id'], (int) $date['schedule_slot_id'], (string) $date['date'], true);
        $sessionNo = (int) DB::table('core_liveclass_sessions')->where('customer_id', $customerId)->where('cohort_id', $cohort->id)->max('session_no') + 1;
        $now = now();
        $sessionId = DB::table('core_liveclass_sessions')->insertGetId($box + [
            'customer_id' => $customerId,
            'cohort_id' => $cohort->id,
            'template_version_id' => $cohort->version_id,
            'session_no' => $sessionNo,
            'session_type' => 'curriculum',
            'primary_teacher_id' => null,
            'scheduled_start_at' => $origin['scheduled_start_at'],
            'scheduled_end_at' => $origin['scheduled_end_at'],
            'timezone' => $origin['timezone'],
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

        return $sessionId;
    }

    /** @param  Collection<int, object>  $team */
    private function assertTeachersCanTeach(int $customerId, int $cohortId, Collection $team, string $localDate): void
    {
        foreach ($team as $member) {
            $assignment = DB::table('core_course_cohort_teachers')
                ->where('customer_id', $customerId)->where('cohort_id', $cohortId)
                ->where('teacher_id', $member->teacher_id)->where('status', 'active')
                ->first(['assigned_from', 'assigned_to']);
            $from = $assignment?->assigned_from ? substr((string) $assignment->assigned_from, 0, 10) : null;
            $to = $assignment?->assigned_to ? substr((string) $assignment->assigned_to, 0, 10) : null;
            if (! $assignment || ($from && $localDate < $from) || ($to && $localDate > $to)) {
                $name = DB::table('users')->where('customer_id', $customerId)->where('id', $member->teacher_id)->value('name');
                $this->refuse('order', __('lf.LF_course_cohort_order_teacher_unavailable', [
                    'teacher' => $name,
                    'date' => Carbon::parse($localDate)->format('d/m/Y'),
                ]));
            }
        }
    }

    /** @param  Collection<int, object>  $team */
    private function replaceTeam(int $customerId, int $sessionId, Collection $team, int $actorId): void
    {
        DB::table('core_liveclass_session_teachers')->where('customer_id', $customerId)->where('session_id', $sessionId)->delete();
        $now = now();
        DB::table('core_liveclass_session_teachers')->insert($team->map(fn (object $member): array => [
            'customer_id' => $customerId,
            'session_id' => $sessionId,
            'teacher_id' => $member->teacher_id,
            'role' => $member->role,
            'created_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /**
     * ADR-0015 is unchanged: both the prerequisite rows and the older single column are read, whatever the match mode.
     *
     * @param  array<int, int>  $order
     * @param  Collection<int|string, string>  $titles
     */
    private function assertPrerequisitesStayBefore(int $customerId, int $versionId, array $order, Collection $titles): void
    {
        $positions = array_flip($order);
        $edges = DB::table('core_course_template_version_lesson_prerequisites')
            ->where('customer_id', $customerId)->where('template_version_id', $versionId)
            ->get(['version_lesson_id', 'prerequisite_version_lesson_id'])
            ->map(fn (object $edge): array => [(int) $edge->version_lesson_id, (int) $edge->prerequisite_version_lesson_id])
            ->merge(DB::table('core_course_template_version_lessons')
                ->where('customer_id', $customerId)->where('template_version_id', $versionId)
                ->whereNotNull('unlock_after_version_lesson_id')
                ->get(['id', 'unlock_after_version_lesson_id'])
                ->map(fn (object $lesson): array => [(int) $lesson->id, (int) $lesson->unlock_after_version_lesson_id]));

        foreach ($edges as [$lessonId, $prerequisiteId]) {
            if (isset($positions[$lessonId], $positions[$prerequisiteId]) && $positions[$prerequisiteId] > $positions[$lessonId]) {
                $this->refuse('lesson_ids', __('lf.LF_course_template_lesson_reorder_blocked_prerequisite', [
                    'lesson' => $titles[$lessonId],
                    'prerequisite' => $titles[$prerequisiteId],
                ]));
            }
        }
    }

    /** @param  array<int, int>  $ids */
    private function writeOrder(string $table, string $column, int $customerId, int $cohortId, array $ids, int $actorId): void
    {
        $now = now();
        DB::table($table)->upsert(
            array_map(fn (int $id, int $index): array => [
                'customer_id' => $customerId,
                'cohort_id' => $cohortId,
                $column => $id,
                'sort_order' => $index + 1,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $ids, array_keys($ids)),
            ['customer_id', 'cohort_id', $column],
            ['sort_order', 'updated_by', 'updated_at']
        );
    }

    /**
     * @param  array<int, int>  $first
     * @param  array<int, int>  $second
     */
    private function sameMembers(array $first, array $second): bool
    {
        sort($first);
        sort($second);

        return $first === $second;
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
