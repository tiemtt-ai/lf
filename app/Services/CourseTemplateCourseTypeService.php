<?php

namespace App\Services;

use App\Support\CourseType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The course type rules of ADR-0022 for a working Template: when the type is locked and which
 * working Activities a type would reject. Callers hold the Template lock when they write.
 */
class CourseTemplateCourseTypeService
{
    /** A Product Item bound to a published Version of the Template locks the type (any Product status). */
    public function isLocked(int $customerId, int $templateId): bool
    {
        return DB::table('core_course_product_items as items')
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'items.version_id')->where('versions.customer_id', $customerId);
            })
            ->where('items.customer_id', $customerId)
            ->where('versions.template_id', $templateId)
            ->exists();
    }

    /** @return Collection<int, object{lesson_title: string, activity_title: string}> */
    public function invalidActivities(int $customerId, int $templateId, string $type): Collection
    {
        return DB::table('core_course_template_activities as activities')
            ->join('core_course_template_lessons as lessons', function ($join) use ($customerId): void {
                $join->on('lessons.id', '=', 'activities.template_lesson_id')->where('lessons.customer_id', $customerId);
            })
            ->where('activities.customer_id', $customerId)
            ->where('activities.template_id', $templateId)
            ->orderBy('lessons.sort_order')->orderBy('lessons.id')
            ->orderBy('activities.sort_order')->orderBy('activities.id')
            ->get([
                'lessons.title as lesson_title', 'activities.title as activity_title', 'activities.activity_type',
                'activities.available_anytime', 'activities.available_before_session',
                'activities.available_during_session', 'activities.available_after_session',
            ])
            ->reject(fn (object $activity): bool => CourseType::availabilityFits($type, $activity))
            ->values();
    }

    /** Rejects a type change that is locked or that working Activities do not fit. */
    public function assertCanChange(int $customerId, int $templateId, string $type, string $field = 'course_type'): void
    {
        if ($this->isLocked($customerId, $templateId)) {
            throw ValidationException::withMessages([$field => __('lf.LF_course_type_change_locked')]);
        }

        $invalid = $this->invalidActivities($customerId, $templateId, $type);
        if ($invalid->isNotEmpty()) {
            $list = $invalid->take(10)->map(fn (object $row): string => __('lf.LF_course_type_change_activity_item', [
                'lesson' => $row->lesson_title,
                'activity' => $row->activity_title,
            ]))->implode('; ');
            throw ValidationException::withMessages([$field => __('lf.LF_course_type_change_invalid_activities', [
                'type' => __(CourseType::labelKey($type)),
                'count' => $invalid->count(),
                'list' => $list,
            ])]);
        }
    }
}
