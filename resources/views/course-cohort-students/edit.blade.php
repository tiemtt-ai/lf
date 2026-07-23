@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_student_common_edit'))
@section('page_title', __('lf.LF_course_cohort_student_common_edit'))

@section('content')
    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="cohort-detail-toolbar course-cohort-student-edit-toolbar">
        <a class="cohort-detail-back" href="{{ route($routePrefix.'.show', $membership->id) }}">
            <span aria-hidden="true">←</span>
            {{ __('lf.LF_course_cohort_student_common_back_to_detail') }}
        </a>
    </div>

    <div class="admin-card admin-form-card admin-form-surface course-cohort-student-edit">
        <form method="POST"
              action="{{ route($routePrefix.'.update', $membership->id) }}"
              class="admin-form-standard"
              x-data="{ submitting: false }"
              x-on:submit="submitting = true">
            @csrf
            @method('PUT')

            <div class="admin-form-flow">
                <section class="admin-form-standard-section course-cohort-student-identity-section"
                         aria-labelledby="cohort-student-edit-context">
                    <header class="admin-form-section-header">
                        <h2 id="cohort-student-edit-context" class="admin-form-section-title">
                            {{ __('lf.LF_course_cohort_student_group_enrollment_context') }}
                        </h2>
                        <p class="admin-form-section-help">
                            {{ __('lf.LF_course_cohort_student_common_context_help') }}
                        </p>
                    </header>

                    <div class="admin-form-field-grid course-cohort-student-edit-context-grid">
                        <div class="lf-form-group admin-form-field course-cohort-student-edit-item">
                            <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_student') }}</span>
                            <div class="admin-form-calculated-summary">
                                <strong class="admin-form-calculated-summary-value">{{ $membership->student_name }}</strong>
                                <span class="admin-form-calculated-summary-meta">{{ $membership->student_email }}</span>
                            </div>
                        </div>

                        <div class="lf-form-group admin-form-field course-cohort-student-edit-item">
                            <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_product') }}</span>
                            <div class="admin-form-calculated-summary">
                                <strong class="admin-form-calculated-summary-value">{{ $membership->product_title }}</strong>
                                <span class="admin-form-calculated-summary-meta">{{ $membership->product_code }}</span>
                            </div>
                        </div>

                        <div class="lf-form-group admin-form-field course-cohort-student-edit-item">
                            <span class="lf-form-label">{{ __('lf.LF_course_cohort_student_common_enrollment') }}</span>
                            <div class="cohort-edit-readonly-stack">
                                <strong class="cohort-edit-readonly-value">
                                    ENR-{{ str_pad((string) $membership->enrollment_id, 6, '0', STR_PAD_LEFT) }}
                                </strong>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="cohort-student-edit-assignment">
                    <header class="admin-form-section-header">
                        <h2 id="cohort-student-edit-assignment" class="admin-form-section-title">
                            {{ __('lf.LF_course_cohort_student_group_assignment') }}
                        </h2>
                        <p class="admin-form-section-help">
                            {{ __('lf.LF_course_cohort_student_transfer_help') }}
                        </p>
                    </header>

                    <div class="lf-form-group admin-form-field--full">
                        <label class="lf-form-label" for="cohort_id">
                            {{ __('lf.LF_course_cohort_student_common_cohort') }}
                        </label>
                        <select id="cohort_id" name="cohort_id" class="lf-form-control" required>
                            @foreach ($cohorts as $cohort)
                                <option value="{{ $cohort->id }}" @selected((int) old('cohort_id', $membership->cohort_id) === $cohort->id)>
                                    {{ $cohort->name }} @if ($cohort->code) · {{ $cohort->code }} @endif
                                </option>
                            @endforeach
                        </select>
                        @error('cohort_id')<p class="lf-form-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </section>

                @include('course-cohort-students.partials.lifecycle', [
                    'membership' => $membership,
                    'statuses' => $statuses,
                ])
            </div>

            <footer class="admin-form-footer" data-actions-align="end">
                <div class="admin-form-footer-primary">
                    <a href="{{ route($routePrefix.'.show', $membership->id) }}" class="admin-form-cancel">
                        {{ __('lf.LF_common_button_cancel') }}
                    </a>
                    <button type="submit" class="btn btn-primary"
                            x-bind:disabled="submitting" x-bind:aria-busy="submitting">
                        {{ __('lf.LF_common_button_save_changes') }}
                    </button>
                </div>
            </footer>
        </form>
    </div>
@endsection
