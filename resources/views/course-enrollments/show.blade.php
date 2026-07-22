@extends('layouts.backend')

@section('title', __('lf.LF_course_enrollment_common_detail'))
@section('page_title', __('lf.LF_course_enrollment_common_detail'))

@section('content')
    @php
        $formatDateTime = static fn ($value): string => $value
            ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i')
            : '';
    @endphp
    @if (session('success'))
        <div class="admin-alert admin-alert-success">
            {{ session('success') }}
        </div>
    @endif
    @if ($errors->has('lifecycle'))<div class="admin-alert admin-alert-danger" role="alert">{{ $errors->first('lifecycle') }}</div>@endif

    <div class="cohort-detail-toolbar course-enrollment-detail-toolbar">
        <a class="cohort-detail-back" href="{{ route($routePrefix.'.index') }}">
            <span aria-hidden="true">←</span>
            {{ __('lf.LF_course_enrollment_common_back_to_enrollments') }}
        </a>
        <div class="cohort-detail-action-group">
            @if (in_array($enrollment->status, ['pending', 'active', 'suspended'], true))
                <a href="{{ route($routePrefix.'.edit', $enrollment->id) }}" class="btn btn-secondary">{{ __('lf.LF_common_button_edit') }}</a>
            @endif
            @if ($enrollment->status === 'pending')
                @include('course-enrollments.partials.lifecycle-action', ['action' => route($routePrefix.'.activate', $enrollment->id), 'triggerClass' => 'btn btn-primary', 'triggerLabel' => __('lf.LF_course_enrollment_lifecycle_activate'), 'title' => __('lf.LF_course_enrollment_lifecycle_activate_title'), 'body' => __('lf.LF_course_enrollment_lifecycle_activate_body'), 'confirmClass' => 'btn btn-primary', 'confirmLabel' => __('lf.LF_course_enrollment_lifecycle_activate')])
            @elseif ($enrollment->status === 'active')
                @include('course-enrollments.partials.lifecycle-action', ['action' => route($routePrefix.'.suspend', $enrollment->id), 'triggerClass' => 'btn btn-primary', 'triggerLabel' => __('lf.LF_course_enrollment_lifecycle_suspend'), 'title' => __('lf.LF_course_enrollment_lifecycle_suspend_title'), 'body' => __('lf.LF_course_enrollment_lifecycle_suspend_body'), 'confirmClass' => 'btn btn-primary', 'confirmLabel' => __('lf.LF_course_enrollment_lifecycle_suspend')])
            @elseif ($enrollment->status === 'suspended')
                @include('course-enrollments.partials.lifecycle-action', ['action' => route($routePrefix.'.reactivate', $enrollment->id), 'triggerClass' => 'btn btn-primary', 'triggerLabel' => __('lf.LF_course_enrollment_lifecycle_reactivate'), 'title' => __('lf.LF_course_enrollment_lifecycle_reactivate_title'), 'body' => __('lf.LF_course_enrollment_lifecycle_reactivate_body'), 'confirmClass' => 'btn btn-primary', 'confirmLabel' => __('lf.LF_course_enrollment_lifecycle_reactivate')])
            @endif
            @if (in_array($enrollment->status, ['pending', 'active', 'suspended'], true))
                @include('course-enrollments.partials.lifecycle-action', ['action' => route($routePrefix.'.cancel', $enrollment->id), 'triggerClass' => 'admin-danger-text-action', 'triggerLabel' => __('lf.LF_course_enrollment_lifecycle_cancel'), 'title' => __('lf.LF_course_enrollment_lifecycle_cancel_title'), 'body' => __('lf.LF_course_enrollment_lifecycle_cancel_body'), 'confirmClass' => 'btn btn-danger', 'confirmLabel' => __('lf.LF_course_enrollment_lifecycle_cancel')])
            @endif
        </div>
    </div>

    <div class="admin-card admin-form-card admin-form-surface course-enrollment-detail">
        <div class="admin-form-standard">
            <div class="admin-form-flow">
                <section class="admin-form-standard-section course-enrollment-identity-section" aria-labelledby="enrollment-show-access">
                    <header class="admin-form-section-header">
                        <h2 id="enrollment-show-access" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_group_access') }}</h2>
                    </header>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field course-enrollment-detail-item">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_student') }}</span>
                            <div class="admin-form-calculated-summary">
                                <strong class="admin-form-calculated-summary-value">{{ $enrollment->student_name }}</strong>
                                <span class="admin-form-calculated-summary-meta">{{ $enrollment->student_email }}</span>
                            </div>
                        </div>
                        <div class="lf-form-group admin-form-field course-enrollment-detail-item">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_product') }}</span>
                            <div class="admin-form-calculated-summary">
                                <strong class="admin-form-calculated-summary-value">{{ $enrollment->product_title }}</strong>
                                <span class="admin-form-calculated-summary-meta">{{ $enrollment->product_code }}</span>
                            </div>
                        </div>
                        <div class="lf-form-group admin-form-field course-enrollment-detail-item">
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

                <section class="admin-form-standard-section course-enrollment-system-section" aria-labelledby="enrollment-show-information">
                    <header class="admin-form-section-header">
                        <h2 id="enrollment-show-information" class="admin-form-section-title">{{ __('lf.LF_course_enrollment_information') }}</h2>
                    </header>
                    <div class="course-enrollment-detail-information-panel">
                        <div class="admin-form-field-grid course-enrollment-detail-metadata-grid">
                            <div class="lf-form-group admin-form-field course-enrollment-detail-item">
                                <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_status') }}</span>
                                <div class="cohort-edit-readonly-row">
                                    <span @class([
                                        'badge',
                                        'badge-success' => $enrollment->status === 'active',
                                        'badge-danger' => in_array($enrollment->status, ['expired', 'cancelled'], true),
                                    ])>{{ __('lf.LF_course_enrollment_common_'.$enrollment->status) }}</span>
                                </div>
                            </div>
                            <div class="lf-form-group admin-form-field course-enrollment-detail-item">
                                <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_source') }}</span>
                                <div class="cohort-edit-readonly-stack"><strong class="cohort-edit-readonly-value">{{ __('lf.LF_course_enrollment_common_source_'.$enrollment->source) }}</strong></div>
                            </div>
                            <div class="lf-form-group admin-form-field course-enrollment-detail-item">
                                <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</span>
                                <div class="cohort-edit-readonly-stack"><strong class="cohort-edit-readonly-value">{{ $formatDateTime($enrollment->enrolled_at) }}</strong></div>
                            </div>
                        </div>
                        <div class="course-enrollment-detail-window-grid">
                            <div class="course-enrollment-detail-item">
                                <span class="lf-form-label">{{ __('lf.LF_course_enrollment_access_window') }}</span>
                                <strong class="cohort-edit-readonly-value course-enrollment-detail-window-value">
                                    {{ $enrollment->access_starts_at ? $formatDateTime($enrollment->access_starts_at) : __('lf.LF_bulk_enrollment_access_immediate') }}
                                    <span aria-hidden="true">→</span>
                                    {{ $enrollment->access_ends_at ? $formatDateTime($enrollment->access_ends_at) : __('lf.LF_bulk_enrollment_access_unlimited') }}
                                </strong>
                            </div>
                            <div class="course-enrollment-detail-item">
                                <span class="lf-form-label">{{ __('lf.LF_course_enrollment_review_window') }}</span>
                                <strong class="cohort-edit-readonly-value course-enrollment-detail-window-value">
                                    @if (!$enrollment->review_starts_at && !$enrollment->review_ends_at)
                                        {{ __('lf.LF_bulk_enrollment_not_configured') }}
                                    @else
                                        {{ $enrollment->review_starts_at ? $formatDateTime($enrollment->review_starts_at) : __('lf.LF_bulk_enrollment_not_configured') }}
                                        <span aria-hidden="true">→</span>
                                        {{ $enrollment->review_ends_at ? $formatDateTime($enrollment->review_ends_at) : __('lf.LF_bulk_enrollment_not_configured') }}
                                    @endif
                                </strong>
                            </div>
                        </div>
                        @if (filled($enrollment->notes))
                        <div class="course-enrollment-detail-notes course-enrollment-detail-item">
                            <span class="lf-form-label">{{ __('lf.LF_course_enrollment_common_notes') }}</span>
                            <div class="cohort-show-notes">{{ $enrollment->notes }}</div>
                        </div>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>
@endsection
