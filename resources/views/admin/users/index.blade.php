@extends('layouts.backend')

@section('title', __('lf.LF_admin_title_admin_users'))
@section('page_title', __('lf.LF_navigation_menu_admin_users'))

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

    <div class="admin-tabs">
        <a href="{{ route('admin.users.index', ['role' => 'customer_admin']) }}"
           class="admin-tab {{ $role === 'customer_admin' ? 'is-active' : '' }}">
            {{ __('lf.LF_common_role_admin_customer_admin') }}
        </a>
        <a href="{{ route('admin.users.index', ['role' => 'teacher']) }}"
           class="admin-tab {{ $role === 'teacher' ? 'is-active' : '' }}">
            {{ __('lf.LF_common_role_teacher_teacher') }}
        </a>
        <a href="{{ route('admin.users.index', ['role' => 'student']) }}"
           class="admin-tab {{ $role === 'student' ? 'is-active' : '' }}">
            {{ __('lf.LF_common_role_student_student') }}
        </a>
    </div>

    <div class="admin-user-toolbar">
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary">
            {{ __('lf.LF_admin_button_admin_create_user') }}
        </a>
    </div>

    <div class="admin-table-wrap">
        <table class="table">
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
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->phone ?? '-' }}</td>
                    <td>
                        <span class="badge {{ $user->status === 'active' ? 'badge-success' : 'badge-danger' }}">
                            {{ $user->status === 'active' ? __('lf.LF_common_status_common_active') : __('lf.LF_common_status_common_inactive') }}
                        </span>
                    </td>
                    <td>
                        <div class="admin-table-actions">
                            <a class="admin-table-action-link admin-text-action" href="{{ route('admin.users.edit', $user->id) }}">
                                {{ __('lf.action_edit') }}
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">{{ __('lf.LF_common_message_common_no_users') }}</td>
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
