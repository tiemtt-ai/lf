@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_student_common_detail'))
@section('page_title', __('lf.LF_course_cohort_student_common_detail'))

@section('content')
    @php
        $formatDateTime = static fn ($value): string => $value
            ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y H:i')
            : '—';
        $returnUrl = route('admin.course-cohorts.show', ['id' => $membership->cohort_id, 'tab' => 'students']);
    @endphp

    @if (session('success'))
        <div class="admin-alert admin-alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="cohort-detail-toolbar course-cohort-student-detail-toolbar">
        <a class="cohort-detail-back" href="{{ $returnUrl }}">
            <span aria-hidden="true">←</span>
            {{ __('lf.LF_course_cohort_student_back_to_class_students') }}
        </a>
    </div>

    <div class="admin-card admin-form-card admin-form-surface course-cohort-student-detail">
        <div class="admin-form-standard">
            <div class="admin-form-flow">
                <section class="admin-form-standard-section course-cohort-student-identity-section"
                         aria-labelledby="cohort-student-show-assignment">
                    <header class="admin-form-section-header">
                        <h2 id="cohort-student-show-assignment" class="admin-form-section-title">
                            {{ __('lf.LF_course_cohort_student_group_assignment') }}
                        </h2>
                        <p class="admin-form-section-help">
                            {{ __('lf.LF_course_cohort_student_common_assignment_help') }}
                        </p>
                    </header>

                    <div class="admin-form-field-grid course-cohort-student-detail-grid">
                        <div class="lf-form-group admin-form-field course-cohort-student-detail-item">
                            <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_student') }}</span>
                            <div class="admin-form-calculated-summary">
                                <strong class="admin-form-calculated-summary-value">{{ $membership->student_name }}</strong>
                                <span class="admin-form-calculated-summary-meta">{{ $membership->student_email }}</span>
                            </div>
                        </div>

                        <div class="lf-form-group admin-form-field course-cohort-student-detail-item">
                            <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_cohort') }}</span>
                            <div class="admin-form-calculated-summary">
                                <strong class="admin-form-calculated-summary-value">{{ $membership->cohort_name }}</strong>
                                <span class="admin-form-calculated-summary-meta">{{ $membership->cohort_code ?: '—' }}</span>
                            </div>
                        </div>

                        <div class="lf-form-group admin-form-field course-cohort-student-detail-item">
                            <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_product') }}</span>
                            <div class="admin-form-calculated-summary">
                                <strong class="admin-form-calculated-summary-value">{{ $membership->product_title }}</strong>
                                <span class="admin-form-calculated-summary-meta">{{ $membership->product_code }}</span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section course-cohort-student-system-section"
                         aria-labelledby="cohort-student-show-lifecycle">
                    <header class="admin-form-section-header">
                        <h2 id="cohort-student-show-lifecycle" class="admin-form-section-title">
                            {{ __('lf.LF_course_cohort_student_group_lifecycle') }}
                        </h2>
                    </header>

                    <div class="course-cohort-student-detail-panel">
                        <div class="admin-form-field-grid course-cohort-student-detail-grid">
                            <div class="lf-form-group admin-form-field course-cohort-student-detail-item">
                                <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_status') }}</span>
                                <div class="cohort-edit-readonly-row">
                                    <span @class([
                                        'badge',
                                        'badge-success' => $membership->status === 'active',
                                        'badge-danger' => in_array($membership->status, ['removed', 'cancelled'], true),
                                    ])>
                                        {{ __('lf.LF_course_cohort_student_common_'.$membership->status) }}
                                    </span>
                                </div>
                            </div>

                            <div class="lf-form-group admin-form-field course-cohort-student-detail-item">
                                <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_enrollment') }}</span>
                                <div class="cohort-edit-readonly-stack">
                                    <strong class="cohort-edit-readonly-value">
                                        ENR-{{ str_pad((string) $membership->enrollment_id, 6, '0', STR_PAD_LEFT) }}
                                    </strong>
                                </div>
                            </div>

                            <div class="lf-form-group admin-form-field course-cohort-student-detail-item">
                                <span class="lf-form-label">{{ __('lf.LF_common_label_common_id') }}</span>
                                <div class="cohort-edit-readonly-stack">
                                    <strong class="cohort-edit-readonly-value">#{{ $membership->id }}</strong>
                                </div>
                            </div>

                            <div class="lf-form-group admin-form-field course-cohort-student-detail-item">
                                <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_joined_at') }}</span>
                                <div class="cohort-edit-readonly-stack">
                                    <strong class="cohort-edit-readonly-value">{{ $formatDateTime($membership->joined_at) }}</strong>
                                </div>
                            </div>

                            <div class="lf-form-group admin-form-field course-cohort-student-detail-item">
                                <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_left_at') }}</span>
                                <div class="cohort-edit-readonly-stack">
                                    <strong class="cohort-edit-readonly-value">{{ $formatDateTime($membership->left_at) }}</strong>
                                </div>
                            </div>

                            <div class="lf-form-group admin-form-field course-cohort-student-detail-item">
                                <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_transfer_from') }}</span>
                                <div class="cohort-edit-readonly-stack">
                                    <strong class="cohort-edit-readonly-value">{{ $membership->transfer_from_cohort_name ?: '—' }}</strong>
                                </div>
                            </div>
                        </div>

                        <div class="course-cohort-student-detail-notes">
                            <div class="course-cohort-student-detail-item">
                                <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_transfer_reason') }}</span>
                                <div class="cohort-show-notes">{{ $membership->transfer_reason ?: '—' }}</div>
                            </div>
                            <div class="course-cohort-student-detail-item">
                                <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_note') }}</span>
                                <div class="cohort-show-notes">{{ $membership->note ?: '—' }}</div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <footer class="admin-form-footer">
                <div class="admin-form-footer-danger">
                    @if ($membership->status === 'active')
                        <form method="POST"
                              action="{{ route($routePrefix.'.archive', $membership->id) }}"
                              x-data="{ submitting: false }"
                              x-on:submit="submitting = true"
                              onsubmit="return confirm('{{ __('lf.LF_course_cohort_student_common_archive_confirm') }}')">
                            @csrf
                            <button type="submit" class="admin-danger-text-action" x-bind:disabled="submitting">
                                {{ __('lf.LF_course_cohort_student_common_archive') }}
                            </button>
                        </form>
                    @endif
                </div>
                <div class="admin-form-footer-primary">
                    <a class="admin-form-cancel" href="{{ $returnUrl }}">
                        {{ __('lf.LF_common_button_cancel') }}
                    </a>
                    @if ($membership->status === 'active')
                        <a href="{{ route($routePrefix.'.edit', $membership->id) }}" class="btn btn-primary">
                            {{ __('lf.LF_common_button_edit') }}
                        </a>
                    @endif
                </div>
            </footer>
        </div>
    </div>
@endsection
