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

    <div class="course-cohort-index-toolbar">
        <a href="{{ route($routePrefix.'.create') }}" class="btn btn-primary">
            {{ __('lf.LF_course_cohort_common_create') }}
        </a>
    </div>

    <div class="admin-table-wrap course-cohort-index-table-wrap">
        <table class="table course-cohort-index-table">
            <thead>
            <tr>
                <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                <th class="course-cohort-index-code">{{ __('lf.table_code') }}</th>
                <th>{{ __('lf.LF_course_cohort_common_name') }}</th>
                <th>{{ __('lf.LF_course_cohort_common_product') }}</th>
                <th>{{ __('lf.LF_course_cohort_common_version') }}</th>
                <th class="course-cohort-index-status">{{ __('lf.LF_course_cohort_common_status') }}</th>
                <th class="course-cohort-index-actions">{{ __('lf.table_actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($cohorts as $cohort)
                <tr>
                    <td class="admin-table-sequence">{{ $cohorts->firstItem() + $loop->index }}</td>
                    <td class="course-cohort-index-code"><span class="course-cohort-index-code-value">{{ $cohort->code ?? '-' }}</span></td>
                    <td><strong class="course-cohort-index-primary">{{ $cohort->name }}</strong></td>
                    <td>
                        @if ($cohort->product_id)
                            <strong class="course-cohort-index-primary">{{ $cohort->product_title }}</strong>
                            <span class="course-cohort-index-meta">{{ $cohort->product_code }}</span>
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        @if ($cohort->version_id)
                            <strong class="course-cohort-index-primary">{{ $cohort->version_title }}</strong>
                            <span class="course-cohort-index-meta">
                                {{ __('lf.LF_course_product_item_common_version_number', ['number' => $cohort->version_number]) }}
                                · {{ $cohort->version_code }}
                            </span>
                        @else
                            -
                        @endif
                    </td>
                    <td class="course-cohort-index-status">
                        <span @class([
                            'badge',
                            'course-cohort-status-badge',
                            'course-cohort-status-badge--draft' => $cohort->status === 'draft',
                            'badge-success' => $cohort->status === 'active',
                            'course-cohort-status-badge--completed' => $cohort->status === 'completed',
                            'badge-danger' => $cohort->status === 'archived',
                        ])>
                            {{ __('lf.LF_course_cohort_common_'.$cohort->status) }}
                        </span>
                    </td>
                    <td class="course-cohort-index-actions">
                        <div class="admin-table-actions course-cohort-index-action-list">
                            <a class="admin-table-action-link admin-text-action" href="{{ route($routePrefix.'.show', $cohort->id) }}">
                                {{ __('lf.action_view') }}
                            </a>
                            @if (in_array($cohort->status, ['draft', 'active'], true))
                                <a class="admin-table-action-link admin-text-action" href="{{ route($routePrefix.'.edit', $cohort->id) }}">
                                    {{ __('lf.action_edit') }}
                                </a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
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
