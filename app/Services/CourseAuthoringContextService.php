<?php

namespace App\Services;

use App\Exceptions\CourseAuthoringContextException;
use App\Support\Ai\CanonicalJson;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Course owner port for Step 7 (contract § Owner-service ports):
 * proposalContext / assertProposalContext.
 *
 * Course alone maps its physical rows to the `course-authoring-v1` DTO and
 * decides who may author against a working Activity. AI never reads Course
 * tables; it receives this DTO and its hash.
 *
 * Authority, checked on every call and never cached: an active customer_admin
 * of the tenant, or an active teacher with an active assignment to the
 * Activity's Template (primary/assistant/reviewer). Anything else, including a
 * foreign or missing Activity, is the same non-disclosing `not_found`.
 */
final class CourseAuthoringContextService
{
    public const SCHEMA = 'course-authoring-v1';

    private const ASSIGNMENT_ROLES = ['primary', 'assistant', 'reviewer'];

    /**
     * Activity types that carry uploaded Media. AI proposals are made from the
     * text of that Media, so only these have anything to read; the others hold a
     * link (embedded video, live class) or no content AI can see (quiz).
     */
    public const MEDIA_ACTIVITY_TYPES = ['video', 'audio', 'document'];

    /**
     * @return array{template_id:int,lesson_id:int,activity_id:int,actor_role:string,selected_framework_id:?int,selected_framework_version_id:?int,dto:array<string,mixed>,course_context_hash:string}
     */
    public function proposalContext(int $actorId, int $activityId, bool $lockTemplate = false): array
    {
        $customerId = TenantContext::customerId() ?? throw new CourseAuthoringContextException('not_found');

        $activity = DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)->where('id', $activityId)->first();
        if ($activity === null) {
            throw new CourseAuthoringContextException('not_found');
        }

        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)->where('id', $activity->template_id)
            ->when($lockTemplate, fn ($query) => $query->lockForUpdate())
            ->first();
        if ($template === null) {
            throw new CourseAuthoringContextException('not_found');
        }

        $role = $this->actorRole($customerId, (int) $template->id, $actorId);
        if ($role === null) {
            throw new CourseAuthoringContextException('not_found');
        }

        // Re-read under the Template lock so the DTO matches what the lock protects.
        if ($lockTemplate) {
            $activity = DB::table('core_course_template_activities')
                ->where('customer_id', $customerId)->where('id', $activityId)->first()
                ?? throw new CourseAuthoringContextException('not_found');
        }

        $lesson = DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)->where('id', $activity->template_lesson_id)->first()
            ?? throw new CourseAuthoringContextException('not_found');

        $dto = [
            'context_schema_version' => self::SCHEMA,
            'customer_id' => $customerId,
            'template_id' => (int) $template->id,
            'lesson_id' => (int) $lesson->id,
            'activity_id' => (int) $activity->id,
            'activity_type' => (string) $activity->activity_type,
            'title' => (string) $activity->title,
            'instructions' => $activity->description === null ? null : (string) $activity->description,
            // Course has no audience field: explicit NULL, never inferred.
            'audience' => null,
            'level' => $template->difficulty_level === null ? null : (string) $template->difficulty_level,
            'ordered_position' => $this->orderedPosition($customerId, $lesson, $activity),
        ];

        return [
            'template_id' => (int) $template->id,
            'lesson_id' => (int) $lesson->id,
            'activity_id' => (int) $activity->id,
            'actor_role' => $role,
            'selected_framework_id' => $template->selected_learning_framework_id === null ? null : (int) $template->selected_learning_framework_id,
            'selected_framework_version_id' => $template->selected_learning_framework_version_id === null ? null : (int) $template->selected_learning_framework_version_id,
            'dto' => $dto,
            'course_context_hash' => CanonicalJson::hash($dto),
        ];
    }

    /**
     * Whether the actor may use AI authoring on a Template of the current
     * tenant: `admin`, `teacher` or null. This is the same authority every
     * proposal command rechecks, exposed so a page can decide whether to show
     * the entry point. Being able to open the Template or Activity page is a
     * wider authority and must not be used instead. It discloses nothing but
     * the actor's own role.
     */
    public function authoringRole(int $actorId, int $templateId): ?string
    {
        $customerId = TenantContext::customerId();

        return $customerId === null ? null : $this->actorRole($customerId, $templateId, $actorId);
    }

    public function hasMediaSource(string $activityType): bool
    {
        return in_array($activityType, self::MEDIA_ACTIVITY_TYPES, true);
    }

    /** Current authority of the actor over a Template, or null. */
    private function actorRole(int $customerId, int $templateId, int $actorId): ?string
    {
        $actor = DB::table('users')->where('customer_id', $customerId)->where('id', $actorId)
            ->where('status', 'active')->first(['role']);
        if ($actor === null) {
            return null;
        }
        if ($actor->role === 'customer_admin') {
            return 'admin';
        }
        if ($actor->role !== 'teacher') {
            return null;
        }

        return DB::table('core_course_template_teachers')
            ->where('customer_id', $customerId)->where('template_id', $templateId)
            ->where('teacher_id', $actorId)->where('status', 'active')
            ->whereIn('role', self::ASSIGNMENT_ROLES)->exists() ? 'teacher' : null;
    }

    /**
     * Ordered ancestor identities and sort values. Section ancestry is walked
     * root-first; reordering or moving the Activity changes this value on
     * purpose — location is part of the pedagogical context.
     *
     * @return array<string,mixed>
     */
    private function orderedPosition(int $customerId, object $lesson, object $activity): array
    {
        $sections = [];
        $sectionId = $lesson->template_section_id;
        $guard = 0;
        while ($sectionId !== null && $guard++ < 32) {
            $section = DB::table('core_course_template_sections')
                ->where('customer_id', $customerId)->where('id', $sectionId)
                ->first(['id', 'parent_section_id', 'display_order']);
            if ($section === null) {
                break;
            }
            array_unshift($sections, ['id' => (int) $section->id, 'display_order' => (int) $section->display_order]);
            $sectionId = $section->parent_section_id;
        }

        return [
            'sections' => $sections,
            'lesson' => ['id' => (int) $lesson->id, 'sort_order' => (int) $lesson->sort_order],
            'activity' => ['id' => (int) $activity->id, 'sort_order' => (int) $activity->sort_order],
        ];
    }
}
