@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_common_title'))
@section('page_title', __('lf.LF_course_cohort_common_title'))

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="admin-card admin-form-card">
        <form method="GET" action="{{ route($routePrefix.'.index') }}">
            <div class="lf-form-group">
                <label class="lf-form-label" for="keyword">
                    {{ __('lf.LF_course_cohort_common_keyword') }}
                </label>
                <input id="keyword"
                       type="search"
                       name="keyword"
                       class="lf-form-control"
                       value="{{ $keyword }}"
                       placeholder="{{ __('lf.LF_course_cohort_common_keyword_placeholder') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="status">
                    {{ __('lf.LF_course_cohort_common_status') }}
                </label>
                <select id="status" name="status" class="lf-form-control">
                    <option value="">{{ __('lf.LF_course_cohort_common_all_statuses') }}</option>
                    @foreach (['draft', 'active', 'completed', 'archived'] as $cohortStatus)
                        <option value="{{ $cohortStatus }}" @selected($status === $cohortStatus)>
                            {{ __('lf.LF_course_cohort_common_'.$cohortStatus) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_search') }}
                </button>
                <a href="{{ route($routePrefix.'.index') }}">
                    {{ __('lf.LF_course_cohort_common_clear_filters') }}
                </a>
            </div>
        </form>
    </div>

    <div class="admin-form-actions">
        <a href="{{ route($routePrefix.'.create') }}" class="btn btn-primary">
            {{ __('lf.LF_course_cohort_common_create') }}
        </a>
    </div>

    <div class="admin-table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                <th>{{ __('lf.table_code') }}</th>
                <th>{{ __('lf.LF_course_cohort_common_name') }}</th>
                <th>{{ __('lf.LF_course_cohort_common_product') }}</th>
                <th>{{ __('lf.LF_course_cohort_common_version') }}</th>
                <th>{{ __('lf.LF_course_cohort_common_teacher') }}</th>
                <th>{{ __('lf.LF_course_cohort_common_status') }}</th>
                <th>{{ __('lf.table_actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($cohorts as $cohort)
                <tr>
                    <td class="admin-table-sequence">{{ $cohorts->firstItem() + $loop->index }}</td>
                    <td>{{ $cohort->code ?? '-' }}</td>
                    <td>{{ $cohort->name }}</td>
                    <td>
                        @if ($cohort->product_id)
                            {{ $cohort->product_title }}
                            <br>
                            <small>{{ $cohort->product_code }}</small>
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        @if ($cohort->version_id)
                            {{ $cohort->version_title }}
                            <br>
                            <small>
                                {{ __('lf.LF_course_product_item_common_version_number', ['number' => $cohort->version_number]) }}
                                · {{ $cohort->version_code }}
                            </small>
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        @if ($cohort->teacher_id)
                            {{ $cohort->teacher_name }}
                            <br>
                            <small>{{ $cohort->teacher_email }}</small>
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        <span @class([
                            'badge',
                            'badge-success' => $cohort->status === 'active',
                            'badge-danger' => $cohort->status === 'archived',
                        ])>
                            {{ $cohort->status === 'active'
                                ? __('lf.LF_common_status_common_active')
                                : __('lf.LF_common_status_common_inactive') }}
                        </span>
                    </td>
                    <td>
                        <div class="admin-table-actions">
                            <a class="admin-table-action-link admin-text-action" href="{{ route($routePrefix.'.show', $cohort->id) }}">
                                {{ __('lf.action_view') }}
                            </a>
                            <a class="admin-table-action-link admin-text-action" href="{{ route($routePrefix.'.edit', $cohort->id) }}">
                                {{ __('lf.action_edit') }}
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        {{ __('lf.LF_course_cohort_common_empty') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($cohorts->hasPages())
        <div class="admin-pagination">
            {{ $cohorts->links() }}
        </div>
    @endif
@endsection
