<?php

namespace App\Services;

use App\Support\LessonType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The content of the Version a Cohort is locked to, in the Cohort's own order,
 * with the Cohort's Sessions on their Activities and the next Schedule dates on the Activities that have
 * none. Read only: such a date persists nothing and becomes a Session only when
 * an admin saves it, though the Sessions tab shows both alike.
 *
 * Every Session of the Cohort appears exactly once: on its Activity when that
 * Activity is in the tree, on its Lesson when it is the Session of a self-study
 * Lesson of the tree, otherwise in `looseSessions`.
 *
 * A date belongs to a position (ADR-0023 §3 and §10): each live Activity, and each
 * self-study Lesson as a whole. A position is told by its key, {@see positionKey()}.
 */
class CourseCohortContentTree
{
    /** A Session in one of these states still delivers its Activity. */
    private const DELIVERING_STATUSES = ['scheduled', 'live', 'completed'];

    public function __construct(
        private readonly CourseTemplateVersionDetailPresenter $presenter,
        private readonly CourseCohortContentOrder $contentOrder
    ) {}

    /**
     * @param  Collection<int, object>  $sessions  from LiveClassCohortSessionReadModel::forCohort()
     * @param  Collection<int, array<string, mixed>>  $occurrences  from LiveClassCohortSessionReadModel::plannedOccurrences()
     * @param  Collection<int, object>  $teachers  the Cohort's active teachers, offered by default on a date
     * @return array<string, mixed>
     */
    public function build(int $customerId, int $cohortId, ?int $versionId, Collection $sessions, ?Collection $occurrences = null, ?Collection $teachers = null): array
    {
        $tree = $this->load($customerId, $cohortId, $versionId);
        $orderedLessons = $this->orderedLessons($tree);
        $this->offerClassTeachers($sessions, $teachers ?? collect());
        $placedActivityIds = $this->orderedActivities($tree, $orderedLessons)->pluck('id')->map(fn ($id): int => (int) $id)->flip();
        $lessonIds = collect($orderedLessons)->pluck('id')->map(fn ($id): int => (int) $id)->flip();

        [$placedSessions, $looseSessions] = $sessions->partition(
            fn (object $session): bool => $session->version_activity_id !== null
                && $placedActivityIds->has((int) $session->version_activity_id)
        );
        // The Session of a self-study Lesson sits on the Lesson itself.
        [$selfStudySessions, $looseSessions] = $looseSessions->partition(
            fn (object $session): bool => self::isSelfStudy($session) && $lessonIds->has((int) $session->version_lesson_id)
        );
        $proposals = $this->computeProposals($tree, $orderedLessons, $sessions, $occurrences ?? collect());
        foreach ($proposals as $activityId => $proposal) {
            $proposals[$activityId]['session'] = $proposal['occurrence'] ? $this->dateAsSession($proposal, $teachers ?? collect()) : null;
        }

        return [
            'structure' => [
                'template' => (object) ['customer_id' => $customerId],
                'version' => (object) ['id' => (int) $versionId],
                'sections' => $tree['sections'],
                'directLessons' => $tree['directLessons'],
                'lessonsBySection' => $tree['lessonsBySection'],
                'activitiesByLesson' => $tree['activitiesByLesson'],
                'presentedActivities' => $versionId && $tree['activities']->isNotEmpty()
                    // The class pages check their own scope (admin or the class's primary teacher, ADR-0033 D7).
                    ? $this->presenter->present($versionId, $tree['lessons'], $tree['activities'], true)['presentedActivities']
                    : collect(),
            ],
            'sessionsByActivity' => $placedSessions->groupBy('version_activity_id'),
            'selfStudySessionsByLesson' => $selfStudySessions->groupBy('version_lesson_id'),
            'looseSessions' => $looseSessions->values(),
            'proposals' => $proposals,
            'hasContent' => $orderedLessons !== [],
            'lockReasonsByLesson' => $this->lockReasons($sessions, 'version_lesson_id'),
            'lockReasonsByActivity' => $this->lockReasons($sessions, 'version_activity_id'),
        ];
    }

    /**
     * The Schedule dates the programme currently shows on Activities without a Session, without rendering it.
     *
     * @param  Collection<int, object>  $sessions
     * @param  Collection<int, array<string, mixed>>  $occurrences
     * @return array<int|string, array<string, mixed>>
     */
    public function proposals(int $customerId, int $cohortId, ?int $versionId, Collection $sessions, Collection $occurrences): array
    {
        $tree = $this->load($customerId, $cohortId, $versionId);

        return $this->computeProposals($tree, $this->orderedLessons($tree), $sessions, $occurrences);
    }

    /**
     * Why each Lesson or Activity keeps its place, for the tooltip on its lock.
     *
     * @param  Collection<int, object>  $sessions
     * @return Collection<int, string>
     */
    private function lockReasons(Collection $sessions, string $column): Collection
    {
        return $sessions
            ->filter(fn (object $session): bool => $session->position_lock_reason && $session->{$column})
            ->mapWithKeys(fn (object $session): array => [(int) $session->{$column} => $session->position_lock_reason]);
    }

    /**
     * The positions dates belong to, in the Cohort's order: each live Activity, and each self-study Lesson
     * (ADR-0023 §10). A self-study position is shaped like an Activity so both travel through the same code:
     * `id` null, `self_study` true, the Lesson's title. Every position carries its `position_key`.
     */
    public function positions(int $customerId, int $cohortId, int $versionId): Collection
    {
        $tree = $this->load($customerId, $cohortId, $versionId);

        return $this->positionsOf($tree, $this->orderedLessons($tree));
    }

    /** The key of the position a Session or a position row belongs to: the live Activity's id, or `l{lesson}`. */
    public static function positionKey(object $row): int|string|null
    {
        if (isset($row->position_key)) {
            return $row->position_key;
        }
        if (self::isSelfStudy($row)) {
            return 'l'.(int) $row->version_lesson_id;
        }
        // A Session names its Activity; one outside the programme has no position.
        if (property_exists($row, 'session_type') || property_exists($row, 'version_activity_id')) {
            return $row->version_activity_id === null ? null : (int) $row->version_activity_id;
        }

        return isset($row->id) ? (int) $row->id : null;
    }

    /** A self-study Session, or the position of a self-study Lesson. */
    public static function isSelfStudy(object $row): bool
    {
        return ($row->session_type ?? null) === 'self_study' || ($row->self_study ?? false) === true;
    }

    /**
     * What a self-study Lesson's row acts on, as {@see slotsOf()} for a live Activity.
     *
     * @param  array<string, mixed>  $tree  from build()
     * @return array{session: ?object, proposal: ?array<string, mixed>, others: Collection<int, object>}
     */
    public static function slotsOfLesson(array $tree, object $lesson): array
    {
        $sessions = $tree['selfStudySessionsByLesson']->get($lesson->id, collect());
        $delivering = $sessions->first(fn (object $session): bool => self::delivers($session));
        $proposal = $delivering ? null : ($tree['proposals']['l'.(int) $lesson->id] ?? null);

        return [
            'session' => $delivering ?? ($proposal['session'] ?? null),
            'proposal' => $proposal,
            'others' => $sessions->reject(fn (object $session): bool => $session === $delivering)->values(),
        ];
    }

    /** The Lessons of one Section, or the direct Lessons, in the Cohort's order. */
    public function lessonsInGroup(int $customerId, int $cohortId, int $versionId, ?int $sectionId): Collection
    {
        $tree = $this->load($customerId, $cohortId, $versionId);

        return $sectionId === null
            ? $tree['directLessons']
            : $tree['lessonsBySection']->get($sectionId, collect())->values();
    }

    /** The Activities of one Lesson in the Cohort's order. */
    public function activitiesInLesson(int $customerId, int $cohortId, int $versionId, int $lessonId): Collection
    {
        return $this->load($customerId, $cohortId, $versionId)['activitiesByLesson']->get($lessonId, collect())->values();
    }

    /**
     * What a live Activity's row acts on: the Session delivering it, otherwise its date from the Schedule; any other Session is listed apart.
     *
     * @param  array<string, mixed>  $tree  from build()
     * @return array{session: ?object, proposal: ?array<string, mixed>, others: Collection<int, object>}
     */
    public static function slotsOf(array $tree, object $activity): array
    {
        $sessions = $tree['sessionsByActivity']->get($activity->id, collect());
        $delivering = $sessions->first(fn (object $session): bool => self::delivers($session));
        $proposal = $delivering ? null : ($tree['proposals'][$activity->id] ?? null);

        return [
            'session' => $delivering ?? ($proposal['session'] ?? null),
            'proposal' => $proposal,
            'others' => $sessions->reject(fn (object $session): bool => $session === $delivering)->values(),
        ];
    }

    public static function delivers(object $session): bool
    {
        return in_array($session->status, self::DELIVERING_STATUSES, true);
    }

    /** @return array<string, Collection> */
    private function load(int $customerId, int $cohortId, ?int $versionId): array
    {
        $sections = collect();
        $lessons = collect();
        $activities = collect();

        if ($versionId) {
            $scope = fn (string $table) => DB::table($table)
                ->where('customer_id', $customerId)
                ->where('template_version_id', $versionId);

            $sections = $scope('core_course_template_version_sections')
                ->orderBy('display_order')->orderBy('id')->get();
            $lessons = $this->contentOrder->lessons($customerId, $cohortId, $scope('core_course_template_version_lessons')->get());
            $activities = $this->contentOrder->activities($customerId, $cohortId, $scope('core_course_template_version_activities')->get());
        }

        return [
            // The class's default join link comes before the Template's (core_course_cohorts § Default Meeting Link).
            'meetingUrl' => DB::table('core_course_cohorts')->where('customer_id', $customerId)
                ->where('id', $cohortId)->value('default_meeting_url') ?: null,
            'sections' => $sections,
            'lessons' => $lessons,
            'activities' => $activities,
            'sectionsByParent' => $sections->groupBy(fn (object $section): string => (string) ($section->parent_version_section_id ?? 'root')),
            'lessonsBySection' => $lessons->whereNotNull('version_section_id')->groupBy('version_section_id'),
            'directLessons' => $lessons->whereNull('version_section_id')->values(),
            'activitiesByLesson' => $activities->groupBy('version_lesson_id'),
        ];
    }

    /**
     * Lessons in programme order: direct first, then each section's lessons before its children.
     *
     * @param  array<string, Collection>  $tree
     * @return array<int, object>
     */
    private function orderedLessons(array $tree): array
    {
        $sectionsByParent = $tree['sectionsByParent'];
        $lessonsBySection = $tree['lessonsBySection'];
        $orderedLessons = $tree['directLessons']->all();
        $walk = function (string $parentKey) use (&$walk, &$orderedLessons, $sectionsByParent, $lessonsBySection): void {
            foreach ($sectionsByParent->get($parentKey, collect()) as $section) {
                array_push($orderedLessons, ...$lessonsBySection->get($section->id, collect())->all());
                $walk((string) $section->id);
            }
        };
        $walk('root');

        return $orderedLessons;
    }

    /**
     * @param  array<string, Collection>  $tree
     * @param  array<int, object>  $orderedLessons
     */
    private function orderedActivities(array $tree, array $orderedLessons): Collection
    {
        return collect($orderedLessons)->flatMap(
            fn (object $lesson): Collection => $tree['activitiesByLesson']->get($lesson->id, collect())
        );
    }

    /**
     * @param  array<string, Collection>  $tree
     * @param  array<int, object>  $orderedLessons
     */
    private function positionsOf(array $tree, array $orderedLessons): Collection
    {
        return collect($orderedLessons)->flatMap(function (object $lesson) use ($tree): Collection {
            $live = $tree['activitiesByLesson']->get($lesson->id, collect())
                ->filter(fn (object $activity): bool => $activity->activity_type === 'live_class')
                ->each(fn (object $activity) => $activity->position_key = (int) $activity->id)
                ->values();
            if (LessonType::normalize($lesson->lesson_type ?? null) !== LessonType::SELF_STUDY) {
                return $live;
            }

            // A self-study Lesson holds no live Activity (ADR-0022 Amendment 1.3); the Lesson is the position.
            return $live->push((object) [
                'id' => null,
                'position_key' => 'l'.(int) $lesson->id,
                'self_study' => true,
                'version_lesson_id' => (int) $lesson->id,
                'activity_type' => 'self_study',
                'title_snapshot' => (string) $lesson->title_snapshot,
                'live_class_url_snapshot' => null,
                'unlock_after_version_activity_id' => null,
            ]);
        })->values();
    }

    /**
     * @param  array<string, Collection>  $tree
     * @param  array<int, object>  $orderedLessons
     * @return array<int|string, array<string, mixed>>
     */
    private function computeProposals(array $tree, array $orderedLessons, Collection $sessions, Collection $occurrences): array
    {
        $sessionsByPosition = $sessions->filter(fn (object $session): bool => self::positionKey($session) !== null)
            ->groupBy(fn (object $session): string => (string) self::positionKey($session));

        // The next free dates go, in order, to the live activities without a Session. A past date is never proposed,
        // nor one a moved Session now occupies: its Origin elsewhere leaves it looking free, but saving it would overlap.
        $occupying = $sessions->reject(fn (object $session): bool => in_array($session->status, ['cancelled', 'no_show'], true));
        $freeOccurrences = $occurrences
            ->reject(fn (array $occurrence): bool => $occurrence['consumed']
                || $this->hasStarted($occurrence)
                || $this->isOccupied($occurrence, $occupying))
            ->values();
        $lessonTitles = collect($orderedLessons)->pluck('title_snapshot', 'id');
        $lessonTypes = collect($orderedLessons)->pluck('lesson_type', 'id');
        $proposals = [];
        $this->positionsOf($tree, $orderedLessons)
            ->filter(fn (object $position): bool => ! $sessionsByPosition->get((string) $position->position_key, collect())
                ->contains(fn (object $session): bool => self::delivers($session)))
            ->values()
            ->each(function (object $activity, int $index) use (&$proposals, $freeOccurrences, $lessonTitles, $lessonTypes, $tree): void {
                $occurrence = $freeOccurrences->get($index);
                $selfStudy = self::isSelfStudy($activity);
                // A practice session has no meeting link (ADR-0022 Amendment 1.3, Đợt 4); nor has a self-study one.
                $practice = ! $selfStudy && LiveClassPracticeSession::isType($lessonTypes->get($activity->version_lesson_id));
                $proposals[$activity->position_key] = [
                    'lesson_id' => (string) $activity->version_lesson_id,
                    'activity_id' => $selfStudy ? '' : (string) $activity->id,
                    'lesson_title' => (string) $lessonTitles->get($activity->version_lesson_id),
                    'activity_title' => (string) $activity->title_snapshot,
                    'meeting_url' => $practice || $selfStudy ? null : ($tree['meetingUrl'] ?? $activity->live_class_url_snapshot),
                    'practice' => $practice,
                    'self_study' => $selfStudy,
                    'occurrence' => $occurrence,
                    'occurrence_key' => $occurrence
                        ? implode('|', [$occurrence['schedule_id'], $occurrence['schedule_slot_id'], $occurrence['date']])
                        : null,
                ];
            });

        return $proposals;
    }

    /**
     * A date in the shape of a Session, so the tab shows and opens both the same way.
     *
     * @param  array<string, mixed>  $proposal
     */
    private function dateAsSession(array $proposal, Collection $teachers): object
    {
        $occurrence = $proposal['occurrence'];
        $selfStudy = (bool) ($proposal['self_study'] ?? false);
        // A self-study session has no teachers of its own.
        $team = $selfStudy ? collect() : $this->teachersOn($teachers, $occurrence['date']);

        return (object) [
            'id' => null,
            'title' => $proposal['lesson_title'],
            'session_type' => $selfStudy ? 'self_study' : 'curriculum',
            'delivery_mode' => match (true) {
                $selfStudy => null,
                (bool) ($proposal['practice'] ?? false) => LiveClassPracticeSession::BOX['delivery_mode'],
                default => 'online',
            },
            'status' => 'scheduled',
            'scheduled_start_at' => $occurrence['date'].' '.substr((string) $occurrence['start_time'], 0, 5).':00',
            'scheduled_end_at' => $occurrence['date'].' '.substr((string) $occurrence['end_time'], 0, 5).':00',
            'timezone' => $occurrence['timezone'],
            'lesson_title' => $proposal['lesson_title'],
            'activity_title' => $proposal['activity_title'],
            'activity_meeting_url' => $proposal['meeting_url'],
            'teacher_team' => $team->map(fn (object $teacher): object => (object) ['name' => $teacher->name])->values(),
            'teacher_ids' => $team->pluck('id')->values(),
            'primary_teacher_name' => null,
            'schedule_relation' => 'on_schedule',
            'is_past_due' => false,
            'can_edit' => false,
            'can_reschedule' => false,
            'can_assign_teachers' => false,
        ];
    }

    /**
     * A Session still to come without teachers of its own shows the class's, and opens with them selected; saving keeps them.
     *
     * @param  Collection<int, object>  $sessions
     */
    private function offerClassTeachers(Collection $sessions, Collection $teachers): void
    {
        $sessions
            ->filter(fn (object $session): bool => ($session->can_edit ?? false) && $session->teacher_ids->isEmpty())
            ->each(function (object $session) use ($teachers): void {
                $team = $this->teachersOn($teachers, substr((string) $session->scheduled_start_at, 0, 10));
                $session->teacher_team = $team->map(fn (object $teacher): object => (object) ['teacher_id' => $teacher->id, 'name' => $teacher->name])->values();
                $session->teacher_ids = $team->pluck('id')->map(fn ($id): string => (string) $id)->values();
            });
    }

    private function teachersOn(Collection $teachers, string $date): Collection
    {
        return $teachers->filter(fn (object $teacher): bool => (! $teacher->assigned_from || $date >= substr((string) $teacher->assigned_from, 0, 10))
            && (! $teacher->assigned_to || $date <= substr((string) $teacher->assigned_to, 0, 10)))->values();
    }

    /**
     * @param  array<string, mixed>  $occurrence
     * @param  Collection<int, object>  $sessions
     */
    private function isOccupied(array $occurrence, Collection $sessions): bool
    {
        $start = LiveClassSessionTime::utc($occurrence['date'].' '.$occurrence['start_time'], $occurrence['timezone']);
        $end = LiveClassSessionTime::utc($occurrence['date'].' '.$occurrence['end_time'], $occurrence['timezone']);

        return $sessions->contains(fn (object $session): bool => LiveClassSessionTime::overlaps(
            LiveClassSessionTime::utc($session->scheduled_start_at, $session->timezone),
            LiveClassSessionTime::utc($session->scheduled_end_at, $session->timezone),
            $start,
            $end
        ));
    }

    /** @param  array<string, mixed>  $occurrence */
    private function hasStarted(array $occurrence): bool
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $occurrence['date'].' '.substr((string) $occurrence['start_time'], 0, 5),
            $occurrence['timezone']
        )->lte(now());
    }
}
