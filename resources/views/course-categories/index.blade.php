@extends('layouts.backend')

@section('title', __('lf.LF_course_category_common_title'))
@section('page_title', __('lf.LF_course_category_common_title'))

@section('content')
    @php
        $hasActiveFilters = $keyword !== '' || $status;
    @endphp

    @if (session('success'))
        <div class="admin-alert admin-alert-success" role="status">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="admin-alert admin-alert-danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="course-category-index-toolbar">
        <span class="course-category-index-count">
            {{ trans_choice('lf.LF_course_category_index_count', $categories->total(), ['count' => $categories->total()]) }}
        </span>
        <a href="{{ route($routePrefix.'.create') }}" class="btn btn-primary">
            {{ __('lf.LF_course_category_common_create') }}
        </a>
    </div>

    <div class="admin-card admin-form-card course-category-filter-card">
        <form class="course-category-filter-grid" method="GET" action="{{ route($routePrefix.'.index') }}">
            <div class="lf-form-group">
                <label class="lf-form-label" for="keyword">
                    {{ __('lf.LF_course_category_common_keyword') }}
                </label>
                <input id="keyword" type="search" name="keyword" class="lf-form-control"
                       value="{{ $keyword }}"
                       placeholder="{{ __('lf.LF_course_category_common_keyword_placeholder') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="status">
                    {{ __('lf.LF_course_category_common_status') }}
                </label>
                <select id="status" name="status" class="lf-form-control">
                    <option value="">{{ __('lf.LF_course_category_common_all_statuses') }}</option>
                    <option value="active" @selected($status === 'active')>
                        {{ __('lf.LF_course_category_common_active') }}
                    </option>
                    <option value="inactive" @selected($status === 'inactive')>
                        {{ __('lf.LF_course_category_common_inactive') }}
                    </option>
                </select>
            </div>

            <div class="admin-form-actions course-category-filter-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_search') }}
                </button>
                @if ($hasActiveFilters)
                    <a class="admin-text-action" href="{{ route($routePrefix.'.index') }}">
                        {{ __('lf.LF_course_category_common_clear_filters') }}
                    </a>
                @endif
            </div>
        </form>
    </div>

    <div class="admin-table-wrap course-category-index-table-wrap">
        <table class="table course-category-index-table">
            <thead>
            <tr>
                <th class="course-category-index-name">{{ __('lf.LF_course_category_common_name') }}</th>
                <th class="course-category-index-parent">{{ __('lf.LF_course_category_common_parent') }}</th>
                <th class="course-category-index-order">{{ __('lf.LF_course_category_index_sort_order') }}</th>
                <th class="course-category-index-status">{{ __('lf.LF_course_category_common_status') }}</th>
                <th class="course-category-index-actions">{{ __('lf.table_actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($categories as $category)
                <tr>
                    <td class="course-category-index-name" data-label="{{ __('lf.LF_course_category_common_name') }}">
                        <strong class="course-category-index-primary">{{ $category->name }}</strong>
                        <span class="course-category-index-meta">{{ $category->slug }}</span>
                    </td>
                    <td @class(['course-category-index-parent', 'is-root' => ! $category->parent_name])
                        data-label="{{ __('lf.LF_course_category_common_parent') }}">
                        {{ $category->parent_name ?? __('lf.LF_course_category_common_root') }}
                    </td>
                    <td class="course-category-index-order" data-label="{{ __('lf.LF_course_category_index_sort_order') }}">
                        <strong>{{ $category->sort_order }}</strong>
                        @if ($category->is_featured)
                            <span class="course-category-featured-badge">{{ __('lf.LF_course_category_common_featured') }}</span>
                        @endif
                    </td>
                    <td class="course-category-index-status" data-label="{{ __('lf.LF_course_category_common_status') }}">
                        <span class="badge course-category-status-badge {{ $category->status === 'active' ? 'badge-success' : 'badge-danger' }}">
                            {{ $category->status === 'active'
                                ? __('lf.LF_common_status_common_active')
                                : __('lf.LF_common_status_common_inactive') }}
                        </span>
                    </td>
                    <td class="course-category-index-actions" data-label="{{ __('lf.table_actions') }}">
                        <div class="admin-table-actions course-category-index-action-list">
                            <a class="admin-table-action-link admin-text-action" href="{{ route($routePrefix.'.edit', $category->id) }}">
                                {{ __('lf.action_edit') }}
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr class="course-category-empty-row">
                    <td class="course-category-empty-cell" colspan="5">
                        <div class="course-category-empty-state" role="status">
                            <strong>{{ $hasActiveFilters ? __('lf.LF_course_category_filter_empty') : __('lf.LF_course_category_common_empty') }}</strong>
                            <span>{{ $hasActiveFilters ? __('lf.LF_course_category_filter_empty_help') : __('lf.LF_course_category_empty_help') }}</span>
                            @if ($hasActiveFilters)
                                <a class="admin-text-action" href="{{ route($routePrefix.'.index') }}">
                                    {{ __('lf.LF_course_category_common_clear_filters') }}
                                </a>
                            @else
                                <a class="btn btn-primary" href="{{ route($routePrefix.'.create') }}">
                                    {{ __('lf.LF_course_category_common_create') }}
                                </a>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($categories->hasPages())
        <div class="admin-pagination">
            {{ $categories->links() }}
        </div>
    @endif
@endsection
