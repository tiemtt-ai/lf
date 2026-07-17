<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CourseTemplatePublishReadinessService
{
    public function __construct(
        private readonly CourseTemplatePublishGraphValidator $validator,
    ) {}

    public function load(int $customerId, int $templateId, bool $lockTemplate = false): CourseTemplatePublishGraph
    {
        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->when($lockTemplate, fn ($query) => $query->lockForUpdate())
            ->first();

        abort_if(! $template, 404);

        $sections = DB::table('core_course_template_sections')
            ->where('template_id', $templateId)
            ->get();
        $sections = $this->expandSections($sections)
            ->sortBy(fn (object $section) => sprintf(
                '%020d:%020d:%020d',
                (int) ($section->parent_section_id ?? 0),
                (int) $section->display_order,
                (int) $section->id,
            ))
            ->values();

        $sectionIds = $sections->pluck('id');
        $lessons = DB::table('core_course_template_lessons')
            ->where(function ($query) use ($templateId, $sectionIds): void {
                $query->where('template_id', $templateId);
                if ($sectionIds->isNotEmpty()) {
                    $query->orWhereIn('template_section_id', $sectionIds);
                }
            })
            ->get();
        $lessons = $this->expandPrerequisites(
            'core_course_template_lessons',
            'unlock_after_lesson_id',
            $lessons,
        )->sortBy(fn (object $lesson) => sprintf(
            '%01d:%020d:%020d:%020d',
            $lesson->template_section_id === null ? 0 : 1,
            (int) ($lesson->template_section_id ?? 0),
            (int) $lesson->sort_order,
            (int) $lesson->id,
        ))->values();

        $lessonIds = $lessons->pluck('id');
        $activities = DB::table('core_course_template_activities')
            ->where(function ($query) use ($templateId, $lessonIds): void {
                $query->where('template_id', $templateId);
                if ($lessonIds->isNotEmpty()) {
                    $query->orWhereIn('template_lesson_id', $lessonIds);
                }
            })
            ->get();
        $activities = $this->expandPrerequisites(
            'core_course_template_activities',
            'unlock_after_activity_id',
            $activities,
        )->sortBy(fn (object $activity) => sprintf(
            '%020d:%020d:%020d',
            (int) $activity->template_lesson_id,
            (int) $activity->sort_order,
            (int) $activity->id,
        ))->values();

        return new CourseTemplatePublishGraph($template, $sections, $lessons, $activities);
    }

    private function expandSections(Collection $sections): Collection
    {
        if ($sections->isEmpty()) {
            return $sections;
        }

        do {
            $ids = $sections->pluck('id');
            $parentIds = $sections->pluck('parent_section_id')->filter()->unique();
            $related = DB::table('core_course_template_sections')
                ->where(function ($query) use ($ids, $parentIds): void {
                    if ($parentIds->isNotEmpty()) {
                        $query->whereIn('id', $parentIds);
                    }
                    if ($ids->isNotEmpty()) {
                        $method = $parentIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                        $query->{$method}('parent_section_id', $ids);
                    }
                })
                ->get();
            $expanded = $sections->merge($related)->unique('id')->values();
            $changed = $expanded->count() !== $sections->count();
            $sections = $expanded;
        } while ($changed);

        return $sections;
    }

    private function expandPrerequisites(string $table, string $foreignKey, Collection $records): Collection
    {
        do {
            $prerequisiteIds = $records->pluck($foreignKey)->filter()->unique();
            $related = $prerequisiteIds->isEmpty()
                ? collect()
                : DB::table($table)->whereIn('id', $prerequisiteIds)->get();
            $expanded = $records->merge($related)->unique('id')->values();
            $changed = $expanded->count() !== $records->count();
            $records = $expanded;
        } while ($changed);

        return $records;
    }

    public function evaluate(int $customerId, CourseTemplatePublishGraph $graph): CourseTemplatePublishReadiness
    {
        return $this->validator->evaluate(
            $customerId,
            $graph->template,
            $graph->sections,
            $graph->lessons,
            $graph->activities,
        );
    }
}
