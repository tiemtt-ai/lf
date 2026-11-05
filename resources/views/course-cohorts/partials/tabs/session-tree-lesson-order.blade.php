{{-- Attributes of a lesson list in a class's programme: a group of more than one lesson can be reordered, saved for this class only. --}}
@if ($sessionTree['canReorder'] && $orderLessons->count() > 1)
    data-reorder-url="{{ route('admin.course-cohorts.content.lessons.reorder', array_filter(['cohort' => $cohort->id, 'version_section_id' => $orderSectionId])) }}"
    data-reorder-field="lesson_ids"
    data-reorder-reload
    data-order-number=".course-template-lesson-number"
    data-msg-saved="{{ __('lf.LF_course_template_lesson_reorder_saved') }}"
    data-msg-error="{{ __('lf.LF_course_template_lesson_reorder_error') }}"
    data-msg-outside="{{ __($orderSectionId ? 'lf.LF_course_template_lesson_reorder_outside' : 'lf.LF_course_template_lesson_reorder_outside_direct') }}"
    x-init="courseTemplateOrder($el)"
    x-sort:config="courseTemplateOrder($el).sortable"
    x-sort.ghost="courseTemplateOrder($el).dropped()"
@endif
