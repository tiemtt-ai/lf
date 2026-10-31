{{-- One Section of the learning map, as in the Content tab (number, meta, fold) without edit actions; meta adds the unmapped count. --}}
@php
    $childSections = $sectionsByParent->get((string) $section->id, collect());
    $branch = $sectionTotals[$section->id] ?? ['lessons' => 0, 'unmapped' => 0];
    $rows = $sectionRows[$section->id] ?? collect();
@endphp
<article id="course-learning-section-{{ $section->id }}"
         class="course-template-outline-section"
         data-section-depth="{{ $depth }}"
         x-data="courseTemplateSectionCollapse(@js($template->customer_id), @js('learning-'.$template->id), @js($section->id))"
         x-bind:hidden="! sectionShown({{ $section->id }})">
    <header class="course-template-outline-section-header"
            x-on:click="if (! $event.target.closest('button, a')) toggle()">
        <span class="course-template-outline-section-number" aria-hidden="true">{{ $sectionOutlineNumbers[$section->id] ?? '' }}</span>
        <div class="course-template-outline-section-copy">
            <h3 class="course-template-outline-section-title">{{ $section->title }}</h3>
            <span class="course-template-outline-section-meta">
                {{ collect([
                    $childSections->isNotEmpty()
                        ? trans_choice('lf.LF_course_template_section_common_children_count', $childSections->count(), ['count' => $childSections->count()])
                        : null,
                    $section->allows_lessons || $branch['lessons'] > 0
                        ? trans_choice('lf.LF_course_template_section_common_lessons_count', $branch['lessons'], ['count' => $branch['lessons']])
                        : null,
                    $branch['unmapped'] > 0
                        ? __('lf.LF_course_template_learning_ui_unmapped_count', ['count' => $branch['unmapped']])
                        : null,
                ])->filter()->implode(' · ') }}
            </span>
        </div>
        <button type="button"
                class="admin-link-button admin-text-action ui-row-action course-template-section-toggle"
                aria-controls="course-learning-section-{{ $section->id }}-branch"
                aria-expanded="true"
                x-bind:aria-expanded="(expanded || filtering).toString()"
                aria-label="{{ __('lf.LF_course_template_section_common_collapse', ['title' => $section->title]) }}"
                x-bind:aria-label="expanded
                    ? @js(__('lf.LF_course_template_section_common_collapse', ['title' => $section->title]))
                    : @js(__('lf.LF_course_template_section_common_expand', ['title' => $section->title]))"
                x-on:click="toggle()">
            <x-backend-icon name="chevron-down" class="ui-icon" />
        </button>
    </header>

    <div id="course-learning-section-{{ $section->id }}-branch" class="course-template-section-branch" x-show="expanded || filtering">
        @if ($rows->isNotEmpty())
            <section class="course-template-lesson-panel" aria-label="{{ __('lf.LF_course_template_lesson_common_list_title') }}">
                <div class="course-template-lesson-toolbar">
                    <h4>{{ __('lf.LF_course_template_lesson_common_list_title') }} ({{ $rows->count() }})</h4>
                </div>
                <div class="course-template-lesson-list">
                    @foreach ($rows as $row)
                        @include('course-templates.partials.learning-lesson-card', ['position' => $loop->iteration])
                    @endforeach
                </div>
            </section>
        @endif

        @if ($childSections->isNotEmpty())
            <div class="course-template-outline-children">
                @foreach ($childSections as $childSection)
                    @include('course-templates.partials.learning-section-node', ['section' => $childSection, 'depth' => $depth + 1])
                @endforeach
            </div>
        @endif
    </div>
</article>
