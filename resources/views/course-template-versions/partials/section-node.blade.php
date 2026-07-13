@php
    $currentSection = $section;
    $sectionLessons = $lessonsBySection->get($currentSection->id, collect());
    $childSections = $sectionsByParent->get((string) $currentSection->id, collect());
@endphp

<article class="course-template-outline-section"
         data-version-section-id="{{ $currentSection->id }}"
         data-section-depth="{{ $depth }}"
         x-data="{ expanded: true, toggle() { this.expanded = ! this.expanded } }">
    <header class="course-template-outline-section-header">
        <h3 class="course-template-outline-section-title">
            {{ $currentSection->title_snapshot }}
        </h3>
        <div class="admin-table-actions">
            <button type="button"
                    class="admin-link-button admin-text-action course-template-section-toggle"
                    aria-controls="course-version-section-{{ $currentSection->id }}-branch"
                    aria-expanded="true"
                    aria-label="{{ __('lf.LF_course_template_section_common_collapse', [
                        'title' => $currentSection->title_snapshot,
                    ]) }}"
                    x-bind:aria-expanded="expanded.toString()"
                    x-bind:aria-label="expanded
                        ? @js(__('lf.LF_course_template_section_common_collapse', [
                            'title' => $currentSection->title_snapshot,
                        ]))
                        : @js(__('lf.LF_course_template_section_common_expand', [
                            'title' => $currentSection->title_snapshot,
                        ]))"
                    x-on:click="toggle()">
                <span class="admin-chevron"
                      x-bind:class="{ 'is-collapsed': ! expanded }"
                      aria-hidden="true"></span>
            </button>
        </div>
    </header>

    <div id="course-version-section-{{ $currentSection->id }}-branch"
         class="course-template-section-branch"
         x-show="expanded">
        @if ($currentSection->allows_lessons)
            <section id="course-version-section-{{ $currentSection->id }}-lessons"
                     class="course-template-lesson-panel"
                     aria-labelledby="course-version-section-{{ $currentSection->id }}-lessons-title">
                <div class="course-template-lesson-toolbar">
                    <h4 id="course-version-section-{{ $currentSection->id }}-lessons-title">
                        {{ __('lf.LF_course_template_lesson_common_list_title') }}
                        ({{ $sectionLessons->count() }})
                    </h4>
                </div>
                <div class="course-template-lesson-list">
                    @forelse ($sectionLessons as $lesson)
                        @include('course-template-versions.partials.lesson')
                    @empty
                        <p class="course-template-lesson-empty">
                            {{ __('lf.LF_course_template_version_detail_no_lessons') }}
                        </p>
                    @endforelse
                </div>
            </section>
        @endif

        @if ($childSections->isNotEmpty())
            <div class="course-template-outline-children">
                @foreach ($childSections as $childSection)
                    @include('course-template-versions.partials.section-node', [
                        'section' => $childSection,
                        'depth' => $depth + 1,
                    ])
                @endforeach
            </div>
        @endif
    </div>
</article>
