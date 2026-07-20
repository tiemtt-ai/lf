@extends('layouts.backend')

@section('title', __('lf.LF_course_cohort_student_common_title'))
@section('page_title', __('lf.LF_course_cohort_student_common_title'))

@section('content')
    @if ($contextCohort)
        <nav class="admin-form-actions" aria-label="{{ __('lf.LF_course_cohort_common_tabs') }}">
            <a class="btn btn-secondary" href="{{ route('admin.course-cohorts.show', $contextCohort->id) }}#overview">{{ __('lf.LF_course_cohort_tab_overview') }}</a>
            <span class="btn btn-primary" aria-current="page">{{ __('lf.LF_course_cohort_tab_students') }}</span>
        </nav>
    @endif
    @if (session('success'))
        <div class="admin-alert admin-alert-success">
            {{ session('success') }}
        </div>
    @endif

    <div class="admin-card admin-form-card">
        <form method="GET" action="{{ route($routePrefix.'.index') }}">
            @if ($contextCohort)<input type="hidden" name="cohort_id" value="{{ $contextCohort->id }}">@endif
            <div class="lf-form-group">
                <label class="lf-form-label" for="keyword">
                    {{ __('lf.LF_course_cohort_student_common_keyword') }}
                </label>
                <input id="keyword"
                       type="search"
                       name="keyword"
                       class="lf-form-control"
                       value="{{ $keyword }}"
                       placeholder="{{ __('lf.LF_course_cohort_student_common_keyword_placeholder') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="status">
                    {{ __('lf.LF_course_cohort_student_common_status') }}
                </label>
                <select id="status" name="status" class="lf-form-control">
                    <option value="">{{ __('lf.LF_course_cohort_student_common_all_statuses') }}</option>
                    @foreach (['active', 'removed', 'completed', 'cancelled'] as $membershipStatus)
                        <option value="{{ $membershipStatus }}" @selected($status === $membershipStatus)>
                            {{ __('lf.LF_course_cohort_student_common_'.$membershipStatus) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_search') }}
                </button>
                <a href="{{ route($routePrefix.'.index') }}">
                    {{ __('lf.LF_course_cohort_student_common_clear_filters') }}
                </a>
            </div>
        </form>
    </div>

    <div class="admin-form-actions">
        <a href="{{ route($routePrefix.'.create', $contextCohort ? ['cohort_id' => $contextCohort->id] : []) }}" class="btn btn-primary" data-disable-on-submit>
            {{ __('lf.LF_course_cohort_student_common_create') }}
        </a>
    </div>

    <div class="admin-table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                <th>{{ __('lf.LF_course_cohort_student_common_student') }}</th>
                <th>{{ __('lf.LF_course_cohort_student_common_cohort') }}</th>
                <th>{{ __('lf.LF_course_cohort_student_common_product') }}</th>
                <th>{{ __('lf.LF_course_cohort_student_common_status') }}</th>
                <th>{{ __('lf.LF_course_cohort_student_common_joined_at') }}</th>
                <th>{{ __('lf.table_actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($memberships as $membership)
                <tr>
                    <td class="admin-table-sequence">{{ $memberships->firstItem() + $loop->index }}</td>
                    <td>
                        {{ $membership->student_name }}
                        <br>
                        <small>{{ $membership->student_email }}</small>
                    </td>
                    <td>
                        {{ $membership->cohort_name }}
                        @if ($membership->cohort_code)
                            <br>
                            <small>{{ $membership->cohort_code }}</small>
                        @endif
                    </td>
                    <td>
                        {{ $membership->product_title }}
                        <br>
                        <small>{{ $membership->product_code }}</small>
                    </td>
                    <td>
                        <span @class([
                            'badge',
                            'badge-success' => $membership->status === 'active',
                            'badge-danger' => in_array($membership->status, ['removed', 'cancelled'], true),
                        ])>
                            {{ $membership->status === 'active'
                                ? __('lf.LF_common_status_common_active')
                                : __('lf.LF_common_status_common_inactive') }}
                        </span>
                    </td>
                    <td>{{ $membership->joined_at }}</td>
                    <td>
                        <div class="admin-table-actions">
                            <a class="admin-table-action-link admin-text-action" href="{{ route($routePrefix.'.show', $membership->id) }}">
                                {{ __('lf.action_view') }}
                            </a>
                            <a class="admin-table-action-link admin-text-action" href="{{ route($routePrefix.'.edit', $membership->id) }}">
                                {{ __('lf.action_edit') }}
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        {{ __('lf.LF_course_cohort_student_common_empty') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($memberships->hasPages())
        <div class="admin-pagination">
            {{ $memberships->links() }}
        </div>
    @endif
@endsection
