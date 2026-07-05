@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_common_detail'))
@section('page_title', __('lf.LF_course_cohort_common_detail'))

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="admin-form-actions">
        <a href="{{ route($routePrefix.'.index') }}">
            {{ __('lf.LF_course_cohort_common_back_to_cohorts') }}
        </a>
        <a href="{{ route($routePrefix.'.edit', $cohort->id) }}" class="btn btn-primary">
            {{ __('lf.LF_common_button_edit') }}
        </a>
        @if ($cohort->status !== 'archived')
            <form method="POST" action="{{ route($routePrefix.'.archive', $cohort->id) }}">
                @csrf
                <button type="submit"
                        class="btn btn-secondary"
                        onclick="return confirm('{{ __('lf.LF_course_cohort_common_archive_confirm') }}')">
                    {{ __('lf.LF_course_cohort_common_archive') }}
                </button>
            </form>
        @endif
    </div>

    <div class="admin-card admin-form-card">
        <section class="admin-form-section">
            <h2 class="admin-form-section-title">
                {{ __('lf.LF_course_cohort_group_basic') }}
            </h2>

            <table class="table">
                <tbody>
                <tr>
                    <th>{{ __('lf.LF_common_label_common_id') }}</th>
                    <td>{{ $cohort->id }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_common_name') }}</th>
                    <td>{{ $cohort->name }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_common_code') }}</th>
                    <td>{{ $cohort->code ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_common_status') }}</th>
                    <td>{{ __('lf.LF_course_cohort_common_'.$cohort->status) }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_common_capacity') }}</th>
                    <td>{{ $cohort->capacity ?? '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_common_start_date') }}</th>
                    <td>{{ $cohort->start_date ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_common_end_date') }}</th>
                    <td>{{ $cohort->end_date ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_common_description') }}</th>
                    <td>{{ $cohort->description ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_common_metadata') }}</th>
                    <td><pre>{{ $cohort->metadata ?: '-' }}</pre></td>
                </tr>
                </tbody>
            </table>
        </section>

        <section class="admin-form-section">
            <h2 class="admin-form-section-title">
                Cohort media
            </h2>

            @if (($cohortMedia ?? collect())->isNotEmpty())
                <table class="table">
                    <thead>
                    <tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($cohortMedia as $media)
                        <tr>
                            <td>{{ $media->usage_type }}</td>
                            <td>{{ $media->display_name }}</td>
                            <td>
                                <a href="{{ $media->signed_url }}" target="_blank" rel="noopener">
                                    Open
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <p>-</p>
            @endif
        </section>

        <section class="admin-form-section">
            <h2 class="admin-form-section-title">
                {{ __('lf.LF_course_cohort_group_context') }}
            </h2>

            <p>{{ __('lf.LF_course_cohort_common_operational_help') }}</p>

            <table class="table">
                <tbody>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_common_product') }}</th>
                    <td>
                        @if ($cohort->product_id)
                            {{ $cohort->product_title }} · {{ $cohort->product_code }}
                        @else
                            -
                        @endif
                    </td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_common_version') }}</th>
                    <td>
                        @if ($cohort->version_id)
                            {{ $cohort->version_title }}
                            · {{ __('lf.LF_course_product_item_common_version_number', ['number' => $cohort->version_number]) }}
                            · {{ $cohort->version_code }}
                        @else
                            -
                        @endif
                    </td>
                </tr>
                <tr>
                    <th>{{ __('lf.LF_course_cohort_common_teacher') }}</th>
                    <td>
                        @if ($cohort->teacher_id)
                            {{ $cohort->teacher_name }} · {{ $cohort->teacher_email }}
                        @else
                            -
                        @endif
                    </td>
                </tr>
                </tbody>
            </table>
        </section>
    </div>
@endsection
