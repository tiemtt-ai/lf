{{-- "Tất cả đề" / "Đề thi của tôi" inside Assessments (the student menu stays as the Guardrails define it). --}}
@if (auth()->user()?->role === 'student')
    <nav class="lf-exam-tabs" aria-label="{{ __('lf.LF_navigation_menu_public_assessments') }}">
        <a href="{{ route('tenant.assessments') }}" @class(['lf-exam-tab', 'is-active' => request()->routeIs('tenant.assessments')])
           @if (request()->routeIs('tenant.assessments')) aria-current="page" @endif>{{ __('lf.LF_assessment_learner_tab_all') }}</a>
        <a href="{{ route('student.assessments.mine') }}" @class(['lf-exam-tab', 'is-active' => request()->routeIs('student.assessments.*')])
           @if (request()->routeIs('student.assessments.mine')) aria-current="page" @endif>{{ __('lf.LF_assessment_learner_tab_mine') }}</a>
    </nav>
@endif
