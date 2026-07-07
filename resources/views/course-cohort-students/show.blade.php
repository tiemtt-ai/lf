@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_student_common_detail'))
@section('page_title', __('lf.LF_course_cohort_student_common_detail'))

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="admin-form-actions">
        <a href="{{ route($routePrefix.'.index') }}">
            {{ __('lf.LF_course_cohort_student_common_back_to_memberships') }}
        </a>
        <a href="{{ route($routePrefix.'.edit', $membership->id) }}" class="btn btn-primary">
            {{ __('lf.LF_common_button_edit') }}
        </a>
        @if ($membership->status === 'active')
            <form method="POST"
                  action="{{ route($routePrefix.'.archive', $membership->id) }}"
                  onsubmit="return confirm('{{ __('lf.LF_course_cohort_student_common_archive_confirm') }}')">
                @csrf
                <button type="submit" class="btn btn-danger">
                    {{ __('lf.LF_course_cohort_student_common_archive') }}
                </button>
            </form>
        @endif
    </div>

    <div class="admin-card admin-form-card">
        <section class="admin-form-section">
            <h2 class="admin-form-section-title">
                {{ __('lf.LF_course_cohort_student_group_assignment') }}
            </h2>

            <table class="table">
                <tbody>
                <tr>
                    <th>{{ __('lf.LF_common_label_common_id') }}</th>
                    <td>{{ $membership->id }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_student_common_student') }}</th>
                    <td>{{ $membership->student_name }} · {{ $membership->student_email }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_student_common_cohort') }}</th>
                    <td>
                        {{ $membership->cohort_name }}
                        @if ($membership->cohort_code)
                            · {{ $membership->cohort_code }}
                        @endif
                    </td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_student_common_enrollment') }}</th>
                    <td>#{{ $membership->enrollment_id }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_student_common_product') }}</th>
                    <td>{{ $membership->product_title }} · {{ $membership->product_code }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_student_common_status') }}</th>
                    <td>{{ __('lf.LF_course_cohort_student_common_'.$membership->status) }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_student_common_joined_at') }}</th>
                    <td>{{ $membership->joined_at }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_student_common_left_at') }}</th>
                    <td>{{ $membership->left_at ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_student_common_transfer_from') }}</th>
                    <td>{{ $membership->transfer_from_cohort_name ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_student_common_transfer_reason') }}</th>
                    <td>{{ $membership->transfer_reason ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_student_common_note') }}</th>
                    <td>{{ $membership->note ?: '-' }}</td>
                </tr>
                </tbody>
            </table>
        </section>
    </div>
@endsection
