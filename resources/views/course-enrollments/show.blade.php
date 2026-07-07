@extends('layouts.backend')

@section('title', __('lf.LF_course_enrollment_common_detail'))
@section('page_title', __('lf.LF_course_enrollment_common_detail'))

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="admin-form-actions">
        <a href="{{ route($routePrefix.'.index') }}">
            {{ __('lf.LF_course_enrollment_common_back_to_enrollments') }}
        </a>
        <a href="{{ route($routePrefix.'.edit', $enrollment->id) }}" class="btn btn-primary">
            {{ __('lf.LF_common_button_edit') }}
        </a>
    </div>

    <div class="admin-card admin-form-card">
        <section class="admin-form-section">
            <h2 class="admin-form-section-title">
                {{ __('lf.LF_course_enrollment_group_access') }}
            </h2>

            <table class="table">
                <tbody>
                <tr>
                    <th>{{ __('lf.LF_common_label_common_id') }}</th>
                    <td>{{ $enrollment->id }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_enrollment_common_student') }}</th>
                    <td>{{ $enrollment->student_name }} · {{ $enrollment->student_email }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_enrollment_common_product') }}</th>
                    <td>{{ $enrollment->product_title }} · {{ $enrollment->product_code }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_enrollment_common_version') }}</th>
                    <td>
                        {{ $enrollment->version_title }}
                        · {{ __('lf.LF_course_product_item_common_version_number', ['number' => $enrollment->version_number]) }}
                        · {{ $enrollment->version_code }}
                    </td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_enrollment_common_source') }}</th>
                    <td>{{ __('lf.LF_course_enrollment_common_source_'.$enrollment->source) }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_enrollment_common_status') }}</th>
                    <td>{{ __('lf.LF_course_enrollment_common_'.$enrollment->status) }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</th>
                    <td>{{ $enrollment->enrolled_at }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_enrollment_common_access_starts_at') }}</th>
                    <td>{{ $enrollment->access_starts_at ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_enrollment_common_access_ends_at') }}</th>
                    <td>{{ $enrollment->access_ends_at ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_enrollment_common_notes') }}</th>
                    <td>{{ $enrollment->notes ?: '-' }}</td>
                </tr>
                </tbody>
            </table>
        </section>
    </div>
@endsection
