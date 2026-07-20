@extends('layouts.backend')

@section('title', __('lf.LF_course_enrollment_common_detail'))
@section('page_title', __('lf.LF_course_enrollment_common_detail'))

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="cohort-detail-toolbar">
        <a class="cohort-detail-back" href="{{ route($routePrefix.'.index') }}">
            <span aria-hidden="true">←</span>
            {{ __('lf.LF_course_enrollment_common_back_to_enrollments') }}
        </a>
        <div class="cohort-detail-action-group">
            <a href="{{ route($routePrefix.'.edit', $enrollment->id) }}" class="btn btn-primary">
                {{ __('lf.LF_common_button_edit') }}
            </a>
        </div>
    </div>

    <div class="admin-card admin-form-card admin-form-surface">
        <div class="admin-form-standard">
            <div class="admin-form-flow">
                <section class="admin-form-standard-section" aria-labelledby="enrollment-show-access">
                    <header class="admin-form-section-header">
                        <h2 id="enrollment-show-access" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_group_access') }}</h2>
                    </header>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_student') }}</span>
                            <div class="admin-form-calculated-summary">
                                <strong class="admin-form-calculated-summary-value">{{ $enrollment->student_name }}</strong>
                                <span class="admin-form-calculated-summary-meta">{{ $enrollment->student_email }}</span>
                            </div>
                        </div>
                        <div class="lf-form-group admin-form-field">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_product') }}</span>
                            <div class="admin-form-calculated-summary">
                                <strong class="admin-form-calculated-summary-value">{{ $enrollment->product_title }}</strong>
                                <span class="admin-form-calculated-summary-meta">{{ $enrollment->product_code }}</span>
                            </div>
                        </div>
                        <div class="lf-form-group admin-form-field admin-form-field--full">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_version') }}</span>
                            <div class="admin-form-calculated-summary">
                                <strong class="admin-form-calculated-summary-value">{{ $enrollment->version_title }}</strong>
                                <span class="admin-form-calculated-summary-meta">
                                    {{ __('lf.LF_course_product_item_common_version_number', ['number' => $enrollment->version_number]) }}
                                    · {{ $enrollment->version_code }}
                                </span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="enrollment-show-information">
                    <header class="admin-form-section-header">
                        <h2 id="enrollment-show-information" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_information') }}</h2>
                    </header>
                    <div class="admin-form-field-grid admin-form-field-grid--three">
                        <div class="lf-form-group admin-form-field">
                            <span class="lf-form-label">{{ __('lf.LF_common_label_common_id') }}</span>
                            <div class="cohort-edit-readonly-stack"><strong class="cohort-edit-readonly-value">#{{ $enrollment->id }}</strong></div>
                        </div>
                        <div class="lf-form-group admin-form-field">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_source') }}</span>
                            <div class="cohort-edit-readonly-stack"><strong class="cohort-edit-readonly-value">{{ __('lf.LF_course_enrollment_common_source_'.$enrollment->source) }}</strong></div>
                        </div>
                        <div class="lf-form-group admin-form-field">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_status') }}</span>
                            <div class="cohort-edit-readonly-row">
                                <span @class([
                                    'badge',
                                    'badge-success' => $enrollment->status === 'active',
                                    'badge-danger' => in_array($enrollment->status, ['expired', 'cancelled'], true),
                                ])>{{ __('lf.LF_course_enrollment_common_'.$enrollment->status) }}</span>
                            </div>
                        </div>
                        <div class="lf-form-group admin-form-field admin-form-field--full">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</span>
                            <div class="cohort-edit-readonly-stack"><strong class="cohort-edit-readonly-value">{{ $enrollment->enrolled_at }}</strong></div>
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="enrollment-show-access-window">
                    <header class="admin-form-section-header">
                        <h2 id="enrollment-show-access-window" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_access_window') }}</h2>
                    </header>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field"><span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_access_starts_at') }}</span><div class="cohort-edit-readonly-stack"><strong class="cohort-edit-readonly-value">{{ $enrollment->access_starts_at ?: '—' }}</strong></div></div>
                        <div class="lf-form-group admin-form-field"><span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_access_ends_at') }}</span><div class="cohort-edit-readonly-stack"><strong class="cohort-edit-readonly-value">{{ $enrollment->access_ends_at ?: '—' }}</strong></div></div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="enrollment-show-review-window">
                    <header class="admin-form-section-header">
                        <h2 id="enrollment-show-review-window" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_review_window') }}</h2>
                    </header>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field"><span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_review_starts_at') }}</span><div class="cohort-edit-readonly-stack"><strong class="cohort-edit-readonly-value">{{ $enrollment->review_starts_at ?: '—' }}</strong></div></div>
                        <div class="lf-form-group admin-form-field"><span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_review_ends_at') }}</span><div class="cohort-edit-readonly-stack"><strong class="cohort-edit-readonly-value">{{ $enrollment->review_ends_at ?: '—' }}</strong></div></div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="enrollment-show-additional">
                    <header class="admin-form-section-header">
                        <h2 id="enrollment-show-additional" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_additional_information') }}</h2>
                    </header>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field admin-form-field--full">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_notes') }}</span>
                            <div class="cohort-show-notes">{{ $enrollment->notes ?: '—' }}</div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
@endsection
