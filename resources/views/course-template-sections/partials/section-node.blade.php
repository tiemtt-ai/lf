@php
    $currentSection = $section;
    $sectionLessons = $lessonsBySection->get($currentSection->id, collect());
    $childSections = $sectionsByParent->get((string) $currentSection->id, collect());
@endphp

<article class="course-template-outline-section"
         data-section-id="{{ $currentSection->id }}"
         data-section-depth="{{ $depth }}"
         x-data="courseTemplateSectionCollapse(
             @js($template->customer_id),
             @js($template->id),
             @js($currentSection->id)
         )">
    <header class="course-template-outline-section-header">
        <h3 class="course-template-outline-section-title">
            {{ $currentSection->title }}
        </h3>
        <div class="admin-table-actions">
            <button type="button"
                    class="admin-link-button admin-text-action course-template-section-toggle"
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
                <span class="admin-chevron"
                      x-bind:class="{ 'is-collapsed': ! expanded }"
                      aria-hidden="true"></span>
            </button>
            <a class="admin-text-action" href="{{ route($sectionRoutePrefix.'.create', $template->id) }}?parent_section_id={{ $currentSection->id }}">
                {{ __('lf.LF_course_template_section_common_add_child_action') }}
            </a>
            <a class="admin-text-action" href="{{ route(
                $sectionRoutePrefix.'.edit',
                [$template->id, $currentSection->id]
            ) }}">
                {{ __('lf.LF_common_button_edit') }}
            </a>
            <button type="button"
                    class="admin-link-button admin-text-action"
                    x-data
                    x-on:click="$dispatch(
                        'open-modal',
                        'delete-course-template-section-{{ $currentSection->id }}'
                    )">
                {{ __('lf.LF_common_button_delete') }}
            </button>
        </div>
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

    <x-modal name="delete-course-template-section-{{ $currentSection->id }}"
             focusable>
        <div class="lf-modal-card">
            <h2>
                {{ __('lf.LF_course_template_section_common_delete_confirm') }}
            </h2>
            <div class="lf-modal-actions">
                <button type="button"
                        class="btn"
                        x-on:click="$dispatch(
                            'close-modal',
                            'delete-course-template-section-{{ $currentSection->id }}'
                        )">
                    {{ __('lf.LF_course_template_section_common_delete_no') }}
                </button>
                <form method="POST"
                      action="{{ route(
                          $sectionRoutePrefix.'.destroy',
                          [$template->id, $currentSection->id]
                      ) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-primary">
                        {{ __('lf.LF_course_template_section_common_delete_yes') }}
                    </button>
                </form>
            </div>
        </div>
    </x-modal>
</article>
