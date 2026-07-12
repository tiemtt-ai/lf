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
                    <option value="draft" @selected($status === 'draft')>
                        {{ __('lf.LF_course_template_common_draft') }}
                    </option>
                    <option value="active" @selected($status === 'active')>
                        {{ __('lf.LF_course_template_common_active') }}
                    </option>
                    <option value="archived" @selected($status === 'archived')>
                        {{ __('lf.LF_course_template_common_archived') }}
                    </option>
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

    <div class="admin-form-actions">
        <a href="{{ route($routePrefix.'.create') }}" class="btn btn-primary">
            {{ __('lf.LF_course_template_common_create') }}
        </a>
    </div>

    <div class="admin-table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                <th>{{ __('lf.LF_course_template_common_name') }}</th>
                <th>{{ __('lf.LF_course_template_common_category') }}</th>
                <th>{{ __('lf.LF_course_template_common_status') }}</th>
                <th>{{ __('lf.table_actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($templates as $template)
                <tr>
                    <td class="admin-table-sequence">{{ $templates->firstItem() + $loop->index }}</td>
                    <td>{{ $template->title }}</td>
                    <td>{{ $template->category_name ?? '—' }}</td>
                    <td>
                        <span @class([
                            'badge',
                            'badge-success' => $template->status === 'active',
                            'badge-danger' => $template->status === 'archived',
                        ])>
                            {{ $template->status === 'active'
                                ? __('lf.LF_common_status_common_active')
                                : __('lf.LF_common_status_common_inactive') }}
                        </span>
                    </td>
                    <td>
                        <div class="admin-table-actions">
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
