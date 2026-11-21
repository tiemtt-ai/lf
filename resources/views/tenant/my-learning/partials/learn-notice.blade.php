{{-- A one-off notice after a refused action, e.g. "Điểm danh" once the Enrollment is no longer active. --}}
@if (session('lf_learn_notice'))
    <div class="classroom-review-only lf-learn-notice" role="alert" data-learn-notice>{{ session('lf_learn_notice') }}</div>
@endif
