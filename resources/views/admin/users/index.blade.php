@extends('layouts.backend')

@section('title', 'User Management')
@section('page_title', 'Users')

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
            Admin
        </a>
        <a href="{{ route('admin.users.index', ['role' => 'teacher']) }}"
           class="admin-tab {{ $role === 'teacher' ? 'is-active' : '' }}">
            Teacher
        </a>
        <a href="{{ route('admin.users.index', ['role' => 'student']) }}"
           class="admin-tab {{ $role === 'student' ? 'is-active' : '' }}">
            Student
        </a>
    </div>

    <div class="admin-user-toolbar">
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary">
            Create User
        </a>
    </div>

    <div class="admin-table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
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
                    <td>{{ $user->phone ?? '-' }}</td>
                    <td>
                        <span class="badge {{ $user->status === 'active' ? 'badge-success' : 'badge-danger' }}">
                            {{ $user->status === 'active' ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        <div class="admin-table-actions">
                            <a href="{{ route('admin.users.edit', $user->id) }}">Edit</a>
                            <form method="POST" action="{{ route('admin.users.toggle-status', $user->id) }}">
                                @csrf
                                <button class="admin-link-button" type="submit">
                                    {{ $user->status === 'active' ? 'Disable' : 'Enable' }}
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center">No users found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
