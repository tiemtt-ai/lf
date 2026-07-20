@extends('layouts.backend')

@section('title', __('lf.LF_course_template_common_title'))
@section('page_title', __('lf.LF_course_template_common_title'))

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
                    {{ __('lf.LF_course_template_common_keyword') }}
                </label>
                <input id="keyword" type="search" name="keyword" class="lf-form-control"
                       value="{{ $keyword }}"
                       placeholder="{{ __('lf.LF_course_template_common_keyword_placeholder') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="status">
                    {{ __('lf.LF_course_template_common_status') }}
                </label>
                <select id="status" name="status" class="lf-form-control">
                    <option value="">{{ __('lf.LF_course_template_common_all_statuses') }}</option>
                    @foreach (\App\Support\CourseTemplateStatus::VALUES as $templateStatus)
                        <option value="{{ $templateStatus }}" @selected($status === $templateStatus)>
                            {{ __('lf.LF_course_template_common_'.$templateStatus) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">
                    {{ __('lf.LF_common_button_search') }}
                </button>
                <a href="{{ route($routePrefix.'.index') }}">
                    {{ __('lf.LF_course_template_common_clear_filters') }}
                </a>
            </div>
        </form>
    </div>

    <div class="course-template-index-toolbar">
        <a href="{{ route($routePrefix.'.create') }}" class="btn btn-primary">
            {{ __('lf.LF_course_template_common_create') }}
        </a>
    </div>

    <div class="admin-table-wrap course-template-index-table-wrap">
        <table class="table course-template-index-table">
            <thead>
            <tr>
                <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                <th>{{ __('lf.LF_course_template_common_name') }}</th>
                <th>{{ __('lf.LF_course_template_common_category') }}</th>
                <th class="course-template-index-status">{{ __('lf.LF_course_template_common_status') }}</th>
                <th class="course-template-index-actions">{{ __('lf.table_actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($templates as $template)
                <tr>
                    <td class="admin-table-sequence">{{ $templates->firstItem() + $loop->index }}</td>
                    <td><strong class="course-template-index-primary">{{ $template->title }}</strong></td>
                    <td>{{ $template->category_name ?? '—' }}</td>
                    <td class="course-template-index-status">
                        <span @class([
                            'badge',
                            'course-template-status-badge',
                            'admin-status-badge--neutral' => ! in_array($template->status, ['active', 'archived'], true),
                            'badge-success' => $template->status === 'active',
                            'badge-danger' => $template->status === 'archived',
                        ])>
                            {{ __('lf.LF_course_template_common_'.$template->status) }}
                        </span>
                    </td>
                    <td class="course-template-index-actions">
                        <div class="admin-table-actions course-template-index-action-list">
                            <a class="admin-table-action-link admin-text-action" href="{{ route($routePrefix.'.edit', $template->id) }}">
                                {{ __('lf.action_edit') }}
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        {{ __('lf.LF_course_template_common_empty') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($templates->hasPages())
        <div class="admin-pagination">
            {{ $templates->links() }}
        </div>
    @endif
@endsection
