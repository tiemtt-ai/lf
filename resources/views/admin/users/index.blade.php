@extends('layouts.backend')

@section('title', __('lf.LF_admin_title_admin_users'))
@section('page_title', __('lf.LF_navigation_menu_admin_users'))

@section('content')
    @php
        $hasActiveFilters = $keyword !== '' || $status;
        $roleLabels = [
            'customer_admin' => __('lf.LF_common_role_admin_customer_admin'),
            'teacher' => __('lf.LF_common_role_teacher_teacher'),
            'student' => __('lf.LF_common_role_student_student'),
        ];
    @endphp

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

    <nav class="admin-tabs admin-user-tabs" aria-label="{{ __('lf.LF_admin_user_role_navigation') }}">
        <a href="{{ route('admin.users.index', ['role' => 'customer_admin']) }}"
           class="admin-tab {{ $role === 'customer_admin' ? 'is-active' : '' }}"
           @if ($role === 'customer_admin') aria-current="page" @endif>
            {{ __('lf.LF_common_role_admin_customer_admin') }}
            <span class="admin-user-tab-count">{{ $roleCounts['customer_admin'] ?? 0 }}</span>
        </a>
        <a href="{{ route('admin.users.index', ['role' => 'teacher']) }}"
           class="admin-tab {{ $role === 'teacher' ? 'is-active' : '' }}"
           @if ($role === 'teacher') aria-current="page" @endif>
            {{ __('lf.LF_common_role_teacher_teacher') }}
            <span class="admin-user-tab-count">{{ $roleCounts['teacher'] ?? 0 }}</span>
        </a>
        <a href="{{ route('admin.users.index', ['role' => 'student']) }}"
           class="admin-tab {{ $role === 'student' ? 'is-active' : '' }}"
           @if ($role === 'student') aria-current="page" @endif>
            {{ __('lf.LF_common_role_student_student') }}
            <span class="admin-user-tab-count">{{ $roleCounts['student'] ?? 0 }}</span>
        </a>
    </nav>

    <div class="admin-user-toolbar">
        <div>
            <strong class="admin-user-toolbar-title">{{ $roleLabels[$role] }}</strong>
            <span class="admin-user-toolbar-count">
                {{ trans_choice('lf.LF_admin_user_result_count', $users->total(), ['count' => $users->total()]) }}
            </span>
        </div>
        <a href="{{ route('admin.users.create', ['role' => $role]) }}" class="btn btn-primary">
            {{ __('lf.LF_admin_button_add_role', ['role' => mb_strtolower($roleLabels[$role])]) }}
        </a>
    </div>

    <div class="admin-card admin-form-card admin-user-filter-card">
        <form class="admin-user-filter-grid" method="GET" action="{{ route('admin.users.index') }}">
            <input type="hidden" name="role" value="{{ $role }}">
            <div class="lf-form-group">
                <label class="lf-form-label" for="keyword">{{ __('lf.LF_admin_user_search_label') }}</label>
                <input id="keyword" type="search" name="keyword" class="lf-form-control"
                       value="{{ $keyword }}" placeholder="{{ __('lf.LF_admin_user_search_placeholder') }}">
            </div>
            <div class="lf-form-group">
                <label class="lf-form-label" for="status">{{ __('lf.LF_common_label_common_status') }}</label>
                <select id="status" name="status" class="lf-form-control">
                    <option value="">{{ __('lf.LF_admin_user_all_statuses') }}</option>
                    <option value="active" @selected($status === 'active')>{{ __('lf.LF_common_status_common_active') }}</option>
                    <option value="inactive" @selected($status === 'inactive')>{{ __('lf.LF_common_status_common_inactive') }}</option>
                </select>
            </div>
            <div class="admin-form-actions admin-user-filter-actions">
                <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_search') }}</button>
                @if ($hasActiveFilters)
                    <a class="admin-text-action" href="{{ route('admin.users.index', ['role' => $role]) }}">
                        {{ __('lf.LF_admin_user_clear_filters') }}
                    </a>
                @endif
            </div>
        </form>
    </div>

    <div class="admin-table-wrap admin-user-table-wrap">
        <table class="table admin-user-table">
            <thead>
            <tr>
                <th class="admin-table-sequence">{{ __('lf.table_no') }}</th>
                <th>{{ __('lf.LF_common_label_name') }}</th>
                <th>{{ __('lf.LF_common_label_email') }}</th>
                <th>{{ __('lf.LF_common_label_phone') }}</th>
                <th>{{ __('lf.LF_common_label_common_status') }}</th>
                <th>{{ __('lf.table_actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($users as $user)
                <tr>
                    <td class="admin-table-sequence">{{ $users->firstItem() + $loop->index }}</td>
                    <td data-label="{{ __('lf.LF_common_label_name') }}">
                        <div class="admin-user-identity">
                            <span class="admin-user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                            <strong>{{ $user->name }}</strong>
                        </div>
                    </td>
                    <td data-label="{{ __('lf.LF_common_label_email') }}">{{ $user->email }}</td>
                    <td data-label="{{ __('lf.LF_common_label_phone') }}">{{ $user->phone ?? '-' }}</td>
                    <td data-label="{{ __('lf.LF_common_label_common_status') }}">
                        <span class="badge {{ $user->status === 'active' ? 'badge-success' : 'badge-danger' }}">
                            {{ $user->status === 'active' ? __('lf.LF_common_status_common_active') : __('lf.LF_common_status_common_inactive') }}
                        </span>
                    </td>
                    <td data-label="{{ __('lf.table_actions') }}">
                        <div class="admin-table-actions">
                            <a class="admin-table-action-link admin-text-action" href="{{ route('admin.users.edit', $user->id) }}">
                                {{ __('lf.action_edit') }}
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr class="admin-user-empty-row">
                    <td class="admin-user-empty-cell" colspan="6">
                        <div class="admin-user-empty-state" role="status">
                            <span class="admin-user-empty-icon" aria-hidden="true">👥</span>
                            <strong>{{ $hasActiveFilters ? __('lf.LF_admin_user_filter_empty') : __('lf.LF_admin_user_empty', ['role' => mb_strtolower($roleLabels[$role])]) }}</strong>
                            <span>{{ $hasActiveFilters ? __('lf.LF_admin_user_filter_empty_help') : __('lf.LF_admin_user_empty_help', ['role' => mb_strtolower($roleLabels[$role])]) }}</span>
                            @if ($hasActiveFilters)
                                <a class="admin-text-action" href="{{ route('admin.users.index', ['role' => $role]) }}">{{ __('lf.LF_admin_user_clear_filters') }}</a>
                            @else
                                <a class="btn btn-primary" href="{{ route('admin.users.create', ['role' => $role]) }}">
                                    {{ __('lf.LF_admin_button_add_role', ['role' => mb_strtolower($roleLabels[$role])]) }}
                                </a>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($users->hasPages())
        <div class="admin-pagination">
            {{ $users->links() }}
        </div>
    @endif
@endsection
