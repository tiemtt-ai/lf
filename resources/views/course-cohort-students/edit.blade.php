@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_student_common_edit'))
@section('page_title', __('lf.LF_course_cohort_student_common_edit'))

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

    <div class="admin-form-actions">
        <a href="{{ route($routePrefix.'.show', $membership->id) }}">
            {{ __('lf.LF_course_cohort_student_common_back_to_detail') }}
        </a>
    </div>

    <div class="admin-card admin-form-card">
        <section class="admin-form-section">
            <h2 class="admin-form-section-title">
                {{ __('lf.LF_course_cohort_student_group_enrollment_context') }}
            </h2>

            <p>{{ __('lf.LF_course_cohort_student_common_context_help') }}</p>

            <table class="table">
                <tbody>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_student_common_student') }}</th>
                    <td>{{ $membership->student_name }} · {{ $membership->student_email }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_student_common_product') }}</th>
                    <td>{{ $membership->product_title }} · {{ $membership->product_code }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_student_common_enrollment') }}</th>
                    <td>#{{ $membership->enrollment_id }}</td>
                </tr>
                </tbody>
            </table>
        </section>

        <form method="POST" action="{{ route($routePrefix.'.update', $membership->id) }}" x-data="{ submitting: false }" x-on:submit="submitting = true">
            @csrf
            @method('PUT')

            <section class="admin-form-section">
                <h2 class="admin-form-section-title">
                    {{ __('lf.LF_course_cohort_student_group_assignment') }}
                </h2>

                <div class="lf-form-group">
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
                </div>
            </section>

            @include('course-cohort-students.partials.lifecycle', [
                'membership' => $membership,
                'statuses' => $statuses,
            ])

            <footer class="admin-form-actions admin-form-actions--footer">
                <a href="{{ route($routePrefix.'.show', $membership->id) }}" class="btn btn-secondary">
                    {{ __('lf.LF_common_button_cancel') }}
                </a>
                <button type="submit" class="btn btn-primary" x-bind:disabled="submitting">
                    {{ __('lf.LF_common_button_save_changes') }}
                </button>
            </footer>
        </form>
    </div>
@endsection
