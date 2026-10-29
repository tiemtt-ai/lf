@php
    $currentSection = $section;
    $sectionLessons = $lessonsBySection->get($currentSection->id, collect());
    $childSections = $sectionsByParent->get((string) $currentSection->id, collect());
@endphp

<article id="course-template-section-{{ $currentSection->id }}"
         class="course-template-outline-section"
         data-section-id="{{ $currentSection->id }}"
         data-section-depth="{{ $depth }}"
         x-data="courseTemplateSectionCollapse(
             @js($template->customer_id),
             @js($template->id),
             @js($currentSection->id)
         )">
    <header class="course-template-outline-section-header"
            x-on:click="if (! $event.target.closest('button, a, form, input')) toggle()">
        <span class="course-template-outline-section-number" aria-hidden="true">{{ $sectionOutlineNumbers[$currentSection->id] ?? '' }}</span>
        <div class="course-template-outline-section-copy">
            <h3 class="course-template-outline-section-title">
                {{ $currentSection->title }}
            </h3>
            <span class="course-template-outline-section-meta">
                @php
                    $branchLessonTotal = $sectionLessonTotals[$currentSection->id] ?? 0;
                @endphp
                {{ collect([
                    $childSections->isNotEmpty()
                        ? trans_choice('lf.LF_course_template_section_common_children_count', $childSections->count(), ['count' => $childSections->count()])
                        : null,
                    $currentSection->allows_lessons || $branchLessonTotal > 0
                        ? trans_choice('lf.LF_course_template_section_common_lessons_count', $branchLessonTotal, ['count' => $branchLessonTotal])
                        : null,
                ])->filter()->implode(' · ') }}
            </span>
        </div>
        <div class="admin-table-actions">

            <button type="button" class="admin-link-button admin-text-action"
                    data-ui-dialog-form-open aria-haspopup="dialog"
                    data-url="{{ route($sectionRoutePrefix.'.create', $template->id) }}?parent_section_id={{ $currentSection->id }}">
                <x-backend-icon name="plus" class="ui-icon" />
                {{ __('lf.LF_course_template_section_common_add_child_action') }}
            </button>
            <button type="button" class="admin-link-button admin-text-action ui-row-action"
                    data-ui-dialog-form-open aria-haspopup="dialog"
                    data-url="{{ route($sectionRoutePrefix.'.edit', [$template->id, $currentSection->id]) }}"
                    aria-label="{{ __('lf.LF_common_button_edit') }}: {{ $currentSection->title }}"
                    data-tooltip="{{ __('lf.LF_common_button_edit') }}">
                <x-backend-icon name="pencil" class="ui-icon" />
                <span class="ui-sr-only">{{ __('lf.LF_common_button_edit') }}</span>
            </button>
            @php
                $sectionBlocker = $childSections->isNotEmpty()
                    ? __('lf.LF_course_template_section_delete_blocked_children')
                    : ($sectionLessons->isNotEmpty() ? __('lf.LF_course_template_section_delete_blocked_lessons') : null);
            @endphp
            <button type="button" class="admin-link-button admin-text-action ui-row-action ui-row-action--danger"
                    data-ui-row-delete
                    data-url="{{ route($sectionRoutePrefix.'.destroy', [$template->id, $currentSection->id]) }}"
                    data-confirm-title="{{ __('lf.LF_course_template_section_delete_title') }}"
                    data-confirm-message="{{ __('lf.LF_course_template_section_delete_confirm', ['name' => $currentSection->title]) }}"
                    data-confirm-label="{{ __('lf.LF_common_button_confirm') }}"
                    @if ($sectionBlocker) disabled aria-disabled="true" @endif
                    aria-label="{{ __('lf.LF_common_button_delete') }}: {{ $currentSection->title }}"
                    data-tooltip="{{ $sectionBlocker ?? __('lf.LF_common_button_delete') }}">
                <x-backend-icon name="trash-2" class="ui-icon" />
                <span class="ui-sr-only">{{ __('lf.LF_common_button_delete') }}</span>
            </button>
        </div>
        <button type="button"
                class="admin-link-button admin-text-action ui-row-action course-template-section-toggle"
                aria-controls="course-template-section-{{ $currentSection->id }}-branch"
                aria-expanded="true"
                aria-label="{{ __('lf.LF_course_template_section_common_collapse', [
                    'title' => $currentSection->title,
                ]) }}"
                x-bind:aria-expanded="expanded.toString()"
                x-bind:aria-label="expanded
                    ? @js(__('lf.LF_course_template_section_common_collapse', [
                        'title' => $currentSection->title,
                    ]))
                    : @js(__('lf.LF_course_template_section_common_expand', [
                        'title' => $currentSection->title,
                    ]))"
                x-on:click="toggle()">
            <x-backend-icon name="chevron-down" class="ui-icon" />
        </button>
    </header>

    <div id="course-template-section-{{ $currentSection->id }}-branch"
         class="course-template-section-branch"
         x-show="expanded">
        @if ($currentSection->allows_lessons)
            @include('course-template-lessons.partials.list', [
                'lessons' => $sectionLessons,
                'section' => $currentSection,
                'lessonRoutePrefix' => $lessonRoutePrefix,
                'activityRoutePrefix' => $activityRoutePrefix,
                'panelId' => 'course-template-section-'.$currentSection->id.'-lessons',
                'panelTitle' => __('lf.LF_course_template_lesson_common_list_title'),
                'emptyMessage' => __('lf.LF_course_template_lesson_common_empty'),
            ])
        @endif

        @if (! $currentSection->allows_lessons && $childSections->isEmpty())
            <div class="course-template-section-empty">
                <span class="course-template-content-empty-icon" aria-hidden="true">
                    <x-backend-icon name="book-open" />
                </span>
                <div>
                    <strong>{{ __('lf.LF_course_template_section_common_branch_empty_title') }}</strong>
                    <p>{{ __('lf.LF_course_template_section_common_branch_empty_help') }}</p>
                </div>
            </div>
        @endif

        @if ($childSections->isNotEmpty())
            <div class="course-template-outline-children">
                @foreach ($childSections as $childSection)
                    @include('course-template-sections.partials.section-node', [
                        'section' => $childSection,
                        'depth' => $depth + 1,
                    ])
                @endforeach
            </div>
        @endif
    </div>

</article>
