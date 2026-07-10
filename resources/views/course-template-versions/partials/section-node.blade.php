@php
    $currentSection = $section;
    $sectionLessons = $lessonsBySection->get($currentSection->id, collect());
    $childSections = $sectionsByParent->get((string) $currentSection->id, collect());
@endphp

<section class="course-version-group course-version-section"
         data-section-depth="{{ $depth }}">
    <div class="course-version-item-heading">
        <div>
            <p class="course-version-eyebrow">
                {{ __('lf.LF_course_template_version_detail_section_order', [
                    'order' => $currentSection->display_order,
                ]) }}
                ·
                {{ __('lf.LF_course_template_section_common_level', [
                    'level' => $depth + 1,
                ]) }}
            </p>
            <h3>{{ $currentSection->title_snapshot }}</h3>
            <p class="course-version-eyebrow">
                {{ __('lf.LF_course_template_section_common_allows_lessons') }}:
                {{ $currentSection->allows_lessons
                    ? __('lf.LF_course_template_section_common_yes')
                    : __('lf.LF_course_template_section_common_no') }}
            </p>
        </div>
    </div>

    @if ($currentSection->description_snapshot)
        <p>{{ $currentSection->description_snapshot }}</p>
    @endif

    <div class="course-version-lessons">
        @forelse ($sectionLessons as $lesson)
            @include('course-template-versions.partials.lesson')
        @empty
            <p class="course-version-empty">
                {{ __('lf.LF_course_template_version_detail_no_lessons') }}
            </p>
        @endforelse
    </div>

    @if ($childSections->isNotEmpty())
        <div class="course-version-section-children">
            @foreach ($childSections as $childSection)
                @include('course-template-versions.partials.section-node', [
                    'section' => $childSection,
                    'depth' => $depth + 1,
                ])
            @endforeach
        </div>
    @endif
</section>
