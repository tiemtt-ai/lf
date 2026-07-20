@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_student_common_create'))
@section('page_title', __('lf.LF_course_cohort_student_common_create'))

@section('content')
    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-card admin-form-card">
        <form method="POST" action="{{ route($routePrefix.'.store') }}" x-data="{ submitting: false }" x-on:submit="submitting = true">
            @csrf

            <section class="admin-form-section">
                <h2 class="admin-form-section-title">
                    {{ __('lf.LF_course_cohort_student_group_assignment') }}
                </h2>

                <p>{{ __('lf.LF_course_cohort_student_common_assignment_help') }}</p>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="cohort_id">
                        {{ __('lf.LF_course_cohort_student_common_cohort') }}
                    </label>
                    <select id="cohort_id" name="cohort_id" class="lf-form-control" required>
                        <option value="">{{ __('lf.LF_course_cohort_student_common_select_cohort') }}</option>
                        @foreach ($cohorts as $cohort)
                            <option value="{{ $cohort->id }}" @selected((int) old('cohort_id') === $cohort->id)>
                                {{ $cohort->name }} @if ($cohort->code) · {{ $cohort->code }} @endif
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="enrollment_id">
                        {{ __('lf.LF_course_cohort_student_common_enrollment') }}
                    </label>
                    <select id="enrollment_id" name="enrollment_id" class="lf-form-control" required>
                        <option value="">{{ __('lf.LF_course_cohort_student_common_select_enrollment') }}</option>
                        @foreach ($enrollments as $enrollment)
                            <option value="{{ $enrollment->id }}" @selected((int) old('enrollment_id') === $enrollment->id)>
                                {{ $enrollment->student_name }} · {{ $enrollment->product_title }} · {{ $enrollment->product_code }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </section>

            @include('course-cohort-students.partials.lifecycle', [
                'membership' => null,
                'statuses' => $statuses,
            ])

            <footer class="admin-form-actions admin-form-actions--footer">
                <a href="{{ route($routePrefix.'.index') }}" class="btn btn-secondary">
                    {{ __('lf.LF_common_button_cancel') }}
                </a>
                <button type="submit" class="btn btn-primary" x-bind:disabled="submitting" aria-live="polite">
                    {{ __('lf.LF_course_cohort_student_common_create') }}
                </button>
            </footer>
        </form>
    </div>
@endsection
