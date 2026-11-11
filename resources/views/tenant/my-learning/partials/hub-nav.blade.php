{{-- The hub tiles of "Khóa học của tôi": the three lists and "Kết quả học tập" (ADR-0031). --}}
@php $hubTabs = [...\App\Http\Controllers\LearnerMyLearningController::TABS, \App\Http\Controllers\LearnerMyLearningController::RESULTS_TAB]; @endphp
<nav class="student-products__categories" aria-label="{{ __('lf.LF_my_learning_categories') }}">
    @foreach ($hubTabs as $hubTab)
        <a href="{{ route('student.courses.index', ['tab' => $hubTab]) }}" @class(['is-active' => $tab === $hubTab])
           @if ($tab === $hubTab) aria-current="page" @endif data-learn-tab="{{ $hubTab }}">
            {!! \App\Support\LearnerHubIcon::svg($hubTab) !!} {{ $hubTab === 'results' ? __('lf.LF_student_site_my_results') : __('lf.LF_my_learning_tab_'.$hubTab) }}
        </a>
    @endforeach
</nav>
