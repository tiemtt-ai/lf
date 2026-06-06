@extends('layouts.tenant')

@section('title', 'User Management')
@section('page_title', 'User Management')

@section('content')

    <div class="lf-container">

        <h1>User Management</h1>

        @if (session('success'))
            <div style="
            background:#dcfce7;
            color:#166534;
            padding:12px 16px;
            border-radius:8px;
            margin-bottom:20px;
        ">
                {{ session('success') }}
            </div>
        @endif

        <div class="lf-tabs">

            <a href="{{ route('admin.users.index', ['role' => 'customer_admin']) }}"
               class="lf-tab {{ $role == 'customer_admin' ? 'active' : '' }}">
                Admin
            </a>

            <a href="{{ route('admin.users.index', ['role' => 'teacher']) }}"
               class="lf-tab {{ $role == 'teacher' ? 'active' : '' }}">
                Teacher
            </a>

            <a href="{{ route('admin.users.index', ['role' => 'student']) }}"
               class="lf-tab {{ $role == 'student' ? 'active' : '' }}">
                Student
            </a>

        </div>

        <div style="margin-bottom:20px;">
            <a href="{{ route('admin.users.create') }}"
               class="lf-btn-primary">
                Create User
            </a>
        </div>

        <div class="lf-table-wrapper">

            <table class="lf-table">

                <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th width="180">Action</th>
                </tr>
                </thead>

                <tbody>

                @forelse ($users as $user)

                    <tr>
                        <td>{{ $user->id }}</td>

                        <td>{{ $user->name }}</td>

                        <td>{{ $user->email }}</td>

                        <td>
                            @if($user->status == 'active')
                                <span class="lf-badge lf-badge-success">
                                Active
                            </span>
                            @else
                                <span class="lf-badge lf-badge-danger">
                                Inactive
                            </span>
                            @endif
                        </td>

                        <td>
                            <a href="{{ route('admin.users.edit', $user->id) }}">
                                Edit
                            </a>

                            <form method="POST"
                                  action="{{ route('admin.users.toggle-status', $user->id) }}"
                                  style="display:inline-block;margin-left:10px;">
                                @csrf

                                <button type="submit">
                                    {{ $user->status == 'active'
                                        ? 'Disable'
                                        : 'Enable' }}
                                </button>
                            </form>
                        </td>
                    </tr>

                @empty

                    <tr>
                        <td colspan="5" style="text-align:center;">
                            No users found.
                        </td>
                    </tr>

                @endforelse

                </tbody>

            </table>

        </div>

    </div>

@endsection