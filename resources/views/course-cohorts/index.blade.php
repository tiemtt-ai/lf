@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_common_title'))
@section('page_title', __('lf.LF_course_cohort_common_title'))

@section('content')
    @php
        $hasActiveFilters = $keyword !== '' || $status;
    @endphp

    @if (session('success'))
        <div class="admin-alert admin-alert-success" role="status">
            {{ session('success') }}
        </div>
    @endif

    <div class="course-cohort-index-toolbar">
        <span class="course-cohort-index-count">
            {{ trans_choice('lf.LF_course_cohort_index_count', $cohorts->total(), ['count' => $cohorts->total()]) }}
        </span>
        <a href="{{ route($routePrefix.'.create') }}" class="btn btn-primary course-cohort-create-action">
            <span aria-hidden="true">+</span>
            {{ __('lf.LF_course_cohort_common_create') }}
        </a>
    </div>

    <div class="admin-card admin-form-card course-cohort-filter-card">
        <form class="course-cohort-filter-grid" method="GET" action="{{ route($routePrefix.'.index') }}">
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

            <div class="admin-form-actions course-cohort-filter-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_search') }}
                </button>
                @if ($hasActiveFilters)
                    <a class="admin-text-action" href="{{ route($routePrefix.'.index') }}">
                        {{ __('lf.LF_course_cohort_common_clear_filters') }}
                    </a>
                @endif
            </div>
        </form>
    </div>

    <div class="admin-table-wrap course-cohort-index-table-wrap">
        <table class="table course-cohort-index-table">
            <thead>
            <tr>
                <th class="course-cohort-index-sequence">{{ __('lf.table_no') }}</th>
                <th>{{ __('lf.LF_course_cohort_common_name') }}</th>
                <th>
                    {{ __('lf.LF_course_cohort_common_product') }}
                    / {{ __('lf.LF_course_cohort_common_version') }}
                </th>
                <th class="course-cohort-index-status">{{ __('lf.LF_course_cohort_common_status') }}</th>
                <th class="course-cohort-index-actions">{{ __('lf.table_actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($cohorts as $cohort)
                <tr>
                    <td class="course-cohort-index-sequence" data-label="{{ __('lf.table_no') }}">
                        {{ $cohorts->firstItem() + $loop->index }}
                    </td>
                    <td data-label="{{ __('lf.LF_course_cohort_common_name') }}">
                        <strong class="course-cohort-index-primary">{{ $cohort->name }}</strong>
                        <span class="course-cohort-index-meta">{{ $cohort->code ?? '—' }}</span>
                    </td>
                    <td data-label="{{ __('lf.LF_course_cohort_common_product') }}">
                        @if ($cohort->product_id)
                            <strong class="course-cohort-index-primary">{{ $cohort->product_title }}</strong>
                            <span class="course-cohort-index-meta">{{ $cohort->product_code }}</span>
                            @if ($cohort->version_id)
                                <span class="course-cohort-index-version">
                                    {{ __('lf.LF_course_product_item_common_version_number', ['number' => $cohort->version_number]) }}
                                    · {{ $cohort->version_code }}
                                </span>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td class="course-cohort-index-status" data-label="{{ __('lf.LF_course_cohort_common_status') }}">
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
                    <td class="course-cohort-index-actions" data-label="{{ __('lf.table_actions') }}">
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
                <tr class="course-cohort-empty-row">
                    <td class="course-cohort-empty-cell" colspan="5">
                        <div class="course-cohort-empty-state" role="status">
                            <strong>{{ $hasActiveFilters ? __('lf.LF_course_cohort_filter_empty') : __('lf.LF_course_cohort_common_empty') }}</strong>
                            <span>{{ $hasActiveFilters ? __('lf.LF_course_cohort_filter_empty_help') : __('lf.LF_course_cohort_empty_help') }}</span>
                            @if ($hasActiveFilters)
                                <a class="admin-text-action" href="{{ route($routePrefix.'.index') }}">
                                    {{ __('lf.LF_course_cohort_common_clear_filters') }}
                                </a>
                            @else
                                <a class="btn btn-primary" href="{{ route($routePrefix.'.create') }}">
                                    {{ __('lf.LF_course_cohort_common_create') }}
                                </a>
                            @endif
                        </div>
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
