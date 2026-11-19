<?php

namespace App\Services;

use App\Support\CourseType;
use App\Support\LessonType;
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

    /**
     * Clears the working Lesson fields the type does not use (ADR-0022 Amendment 1.2), so nothing the
     * form no longer shows is published. Run under the Template lock when the type changes or a Version
     * is copied back to the draft.
     */
    public function resetUnusedLessonFields(int $customerId, int $templateId, ?string $type): void
    {
        $lessons = fn () => DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId);

        if (! CourseType::usesLessonPreviewAndUnlock($type)) {
            DB::table('core_course_template_lesson_prerequisites')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->delete();
            $lessons()
                ->where(fn ($query) => $query->where('is_preview', true)->orWhere('unlock_rule', '!=', 'none'))
                ->update([
                    'is_preview' => false,
                    'unlock_rule' => 'none',
                    'prerequisite_match' => null,
                    'unlock_after_lesson_id' => null,
                    'unlock_at' => null,
                    'updated_at' => now(),
                ]);
        }
        if (! CourseType::usesLessonType($type)) {
            $lessons()->where('lesson_type', '!=', 'regular')->update(['lesson_type' => 'regular', 'updated_at' => now()]);
        }
        if (CourseType::isClassBased($type)) {
            // ADR-0022 Amendment 1.3: a class has no Activity preview, unlock rule or estimated duration.
            DB::table('core_course_template_activities')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where(fn ($query) => $query->where('is_preview', true)->orWhere('unlock_rule', '!=', 'none')
                    ->orWhereNotNull('unlock_after_activity_id')->orWhereNotNull('estimated_duration_seconds'))
                ->update([
                    'is_preview' => false,
                    'unlock_rule' => 'none',
                    'unlock_after_activity_id' => null,
                    'estimated_duration_seconds' => null,
                    'updated_at' => now(),
                ]);
            $lessons()->where('duration_seconds', '!=', 0)->update(['duration_seconds' => 0, 'updated_at' => now()]);
        } else {
            // "Tính vào điểm danh" belongs to a class (Amendment 1.3, Đợt 3).
            DB::table('core_course_template_activities')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('counts_for_attendance', true)
                ->update(['counts_for_attendance' => false, 'updated_at' => now()]);
        }
    }

    /**
     * A self-paced Template becoming class-based (ADR-0022 Amendment 1.3): its lessons, whose Activities are
     * all anytime, become self-study sessions, valid as they are; the author changes each type when needed.
     * Run under the Template lock, after {@see resetUnusedLessonFields()}.
     */
    public function startLessonsAsSelfStudy(int $customerId, int $templateId): void
    {
        DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->update(['lesson_type' => LessonType::SELF_STUDY, 'updated_at' => now()]);
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
