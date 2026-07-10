@php
    $currentSection = $section;
    $sectionLessons = $lessonsBySection->get($currentSection->id, collect());
    $childSections = $sectionsByParent->get((string) $currentSection->id, collect());
@endphp

<article class="course-template-outline-section"
         data-section-id="{{ $currentSection->id }}"
         data-section-depth="{{ $depth }}">
    <header class="course-template-outline-section-header">
        <h3 class="course-template-outline-section-title">
            {{ $currentSection->title }}
        </h3>
        <div class="admin-table-actions">
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
