<?php

namespace App\Services;

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
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->orderBy('parent_section_id')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
        $lessons = DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->orderByRaw('template_section_id IS NOT NULL')
            ->orderBy('template_section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $activities = DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->orderBy('template_lesson_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return new CourseTemplatePublishGraph($template, $sections, $lessons, $activities);
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
