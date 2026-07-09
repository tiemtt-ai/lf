@extends('layouts.backend')

@section('title', __('lf.LF_course_enrollment_common_title'))
@section('page_title', __('lf.LF_course_enrollment_common_title'))

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success">
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

    <div class="admin-card admin-form-card">
        <form method="GET" action="{{ route($routePrefix.'.index') }}">
            <div class="lf-form-group">
                <label class="lf-form-label" for="keyword">
                    {{ __('lf.LF_course_enrollment_common_keyword') }}
                </label>
                <input id="keyword"
                       type="search"
                       name="keyword"
                       class="lf-form-control"
                       value="{{ $keyword }}"
                       placeholder="{{ __('lf.LF_course_enrollment_common_keyword_placeholder') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="status">
                    {{ __('lf.LF_course_enrollment_common_status') }}
                </label>
                <select id="status" name="status" class="lf-form-control">
                    <option value="">{{ __('lf.LF_course_enrollment_common_all_statuses') }}</option>
                    @foreach (['pending', 'active', 'suspended', 'completed', 'expired', 'cancelled'] as $enrollmentStatus)
                        <option value="{{ $enrollmentStatus }}" @selected($status === $enrollmentStatus)>
                            {{ __('lf.LF_course_enrollment_common_'.$enrollmentStatus) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="source">
                    {{ __('lf.LF_course_enrollment_common_source') }}
                </label>
                <select id="source" name="source" class="lf-form-control">
                    <option value="">{{ __('lf.LF_course_enrollment_common_all_sources') }}</option>
                    @foreach (['admin', 'teacher', 'self_registration', 'purchase', 'promotion', 'import', 'api'] as $enrollmentSource)
                        <option value="{{ $enrollmentSource }}" @selected($source === $enrollmentSource)>
                            {{ __('lf.LF_course_enrollment_common_source_'.$enrollmentSource) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_search') }}
                </button>
                <a href="{{ route($routePrefix.'.index') }}">
                    {{ __('lf.LF_course_enrollment_common_clear_filters') }}
                </a>
            </div>
        </form>
    </div>

    <div class="admin-form-actions">
        <a href="{{ route($routePrefix.'.create') }}" class="btn btn-primary">
            {{ __('lf.LF_course_enrollment_common_create') }}
        </a>
    </div>

    <div class="admin-table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                <th>{{ __('lf.LF_course_enrollment_common_student') }}</th>
                <th>{{ __('lf.LF_course_enrollment_common_product') }}</th>
                <th>{{ __('lf.LF_course_enrollment_common_version') }}</th>
                <th>{{ __('lf.LF_course_enrollment_common_source') }}</th>
                <th>{{ __('lf.LF_course_enrollment_common_status') }}</th>
                <th>{{ __('lf.LF_course_enrollment_common_enrolled_at') }}</th>
                <th>{{ __('lf.table_actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($enrollments as $enrollment)
                <tr>
                    <td class="admin-table-sequence">{{ $enrollments->firstItem() + $loop->index }}</td>
                    <td>
                        {{ $enrollment->student_name }}
                        <br>
                        <small>{{ $enrollment->student_email }}</small>
                    </td>
                    <td>
                        {{ $enrollment->product_title }}
                        <br>
                        <small>{{ $enrollment->product_code }}</small>
                    </td>
                    <td>
                        {{ $enrollment->version_title }}
                        <br>
                        <small>
                            {{ __('lf.LF_course_product_item_common_version_number', ['number' => $enrollment->version_number]) }}
                            · {{ $enrollment->version_code }}
                        </small>
                    </td>
                    <td>{{ __('lf.LF_course_enrollment_common_source_'.$enrollment->source) }}</td>
                    <td>
                        <span @class([
                            'badge',
                            'badge-success' => $enrollment->status === 'active',
                            'badge-danger' => in_array($enrollment->status, ['expired', 'cancelled'], true),
                        ])>
                            {{ $enrollment->status === 'active'
                                ? __('lf.LF_common_status_common_active')
                                : __('lf.LF_common_status_common_inactive') }}
                        </span>
                    </td>
                    <td>{{ $enrollment->enrolled_at }}</td>
                    <td>
                        <div class="admin-table-actions">
                            <a class="admin-table-action-link" href="{{ route($routePrefix.'.show', $enrollment->id) }}">
                                {{ __('lf.action_view') }}
                            </a>
                            <a class="admin-table-action-link" href="{{ route($routePrefix.'.edit', $enrollment->id) }}">
                                {{ __('lf.action_edit') }}
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        {{ __('lf.LF_course_enrollment_common_empty') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($enrollments->hasPages())
        <div class="admin-pagination">
            {{ $enrollments->links() }}
        </div>
    @endif
@endsection
