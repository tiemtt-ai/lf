{{-- Previous / next of a catalogue list (courses, assessments). --}}
@if ($pages->hasPages())
    <nav class="lf-exam-pages" aria-label="{{ __('lf.LF_assessment_learner_pages') }}">
        @if ($pages->previousPageUrl())
            <a class="student-button is-outline is-small" href="{{ $pages->previousPageUrl() }}" rel="prev">{{ __('lf.LF_assessment_learner_previous') }}</a>
        @endif
        <span>{{ __('lf.LF_assessment_learner_page_of', ['page' => $pages->currentPage(), 'pages' => $pages->lastPage()]) }}</span>
        @if ($pages->nextPageUrl())
            <a class="student-button is-outline is-small" href="{{ $pages->nextPageUrl() }}" rel="next">{{ __('lf.LF_assessment_learner_next') }}</a>
        @endif
    </nav>
@endif
