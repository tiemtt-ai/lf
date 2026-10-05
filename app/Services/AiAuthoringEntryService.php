<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data for the "AI proposals" section of a Course Template's "Outcomes & competencies" tab
 * (single-entry amendment): which Activities can be chosen, and the routes and Framework
 * selection for the chosen one.
 *
 * Visibility follows the authority every proposal command rechecks (an active admin, or an
 * active primary/assistant/reviewer), never the wider right to open the page. Only routes and
 * the Template's own Framework selection are produced; no proposal content is read here.
 */
final class AiAuthoringEntryService
{
    public function __construct(private readonly CourseAuthoringContextService $context) {}

    /**
     * The tab data, or null when the actor has no AI authority over the Template.
     *
     * @return array{activities:list<array{id:int,title:string,type:string,lesson_title:string}>,selected_activity_id:?int,entry:?array<string,mixed>}|null
     */
    public function forTemplate(
        int $actorId,
        int $customerId,
        object $template,
        string $routePrefix,
        ?int $requestedActivityId,
    ): ?array {
        $role = $this->context->authoringRole($actorId, (int) $template->id);
        if ($role === null) {
            return null;
        }

        $activities = $this->mediaActivities($customerId, (int) $template->id);
        $selected = $requestedActivityId !== null
            ? collect($activities)->first(fn (array $activity): bool => $activity['id'] === $requestedActivityId)
            : null;

        return [
            'activities' => $activities,
            'selected_activity_id' => $selected['id'] ?? null,
            'entry' => $selected === null
                ? null
                : $this->entry($role, $customerId, $template, $selected['id'], $selected['type'], $routePrefix),
        ];
    }

    /** @return list<array{id:int,title:string,type:string,lesson_title:string}> */
    private function mediaActivities(int $customerId, int $templateId): array
    {
        return DB::table('core_course_template_activities as activities')
            ->join('core_course_template_lessons as lessons', 'lessons.id', '=', 'activities.template_lesson_id')
            ->where('activities.customer_id', $customerId)
            ->where('activities.template_id', $templateId)
            ->whereIn('activities.activity_type', CourseAuthoringContextService::MEDIA_ACTIVITY_TYPES)
            ->orderBy('lessons.sort_order')
            ->orderBy('lessons.id')
            ->orderBy('activities.sort_order')
            ->orderBy('activities.id')
            ->get(['activities.id', 'activities.title', 'activities.activity_type', 'lessons.title as lesson_title'])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'type' => (string) $row->activity_type,
                'lesson_title' => (string) $row->lesson_title,
            ])
            ->all();
    }

    /** @return array<string,mixed> */
    private function entry(string $role, int $customerId, object $template, int $activityId, string $activityType, string $prefix): array
    {
        // Learning Foundation has no SQLite schema, so the selection columns are
        // absent there; absent means "no selection", as on the Template page.
        $frameworkId = ($template->selected_learning_framework_id ?? null) !== null
            ? (int) $template->selected_learning_framework_id : null;
        $frameworkVersionId = ($template->selected_learning_framework_version_id ?? null) !== null
            ? (int) $template->selected_learning_framework_version_id : null;
        $parameters = [(int) $template->id, $activityId];

        return [
            'media_supported' => $this->context->hasMediaSource($activityType),
            'is_admin' => $role === 'admin',
            // Generation must name exactly the Template's own selection; the
            // server rejects anything else, so it is handed over, not chosen.
            'framework' => [
                'selected' => $frameworkId !== null && $frameworkVersionId !== null,
                'framework_id' => $frameworkId,
                'framework_version_id' => $frameworkVersionId,
            ],
            // Only an admin can select a Framework; a teacher has no such section.
            'framework_url' => $role === 'admin'
                ? route($prefix.'.edit', (int) $template->id).'?tab=learning'
                : null,
            // Draft versions where an admin can have a proposed Node created, and published
            // versions a draft can be copied from: never for a teacher.
            'draft_versions' => $role === 'admin' ? $this->versions($customerId, $frameworkId, 'draft_snapshot') : [],
            'published_versions' => $role === 'admin' ? $this->versions($customerId, $frameworkId, 'published') : [],
            'urls' => [
                'proposals' => route($prefix.'.activities.ai-authoring.proposals.index', $parameters),
                'generation_requests' => route($prefix.'.activities.ai-authoring.generation-requests.store', $parameters),
                'bulk_decisions' => route($prefix.'.activities.ai-authoring.proposals.bulk-decide', $parameters),
            ],
        ];
    }

    /** @return list<array{id:int,label:string}> */
    private function versions(int $customerId, ?int $frameworkId, string $status): array
    {
        if ($frameworkId === null || ! Schema::hasTable('core_learning_framework_versions')) {
            return [];
        }

        return DB::table('core_learning_framework_versions')
            ->where('customer_id', $customerId)
            ->where('framework_id', $frameworkId)
            ->where('status', $status)
            ->orderByDesc('version_number')
            ->limit(50)
            ->get(['id', 'version_code', 'title_snapshot'])
            ->map(fn ($version) => [
                'id' => (int) $version->id,
                'label' => $version->version_code.' — '.$version->title_snapshot,
            ])
            ->all();
    }
}
