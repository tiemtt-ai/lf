@extends('layouts.backend')

@section('title', 'Edit User')
@section('page_title', 'Edit User')

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success admin-form-card">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->default->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->default->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-card admin-form-card">
        <form method="POST" action="{{ route('admin.users.update', $user->id) }}">
            @csrf
            @method('PUT')

            <div class="lf-form-group">
                <label class="lf-form-label" for="name">Name</label>
                <input id="name" type="text" name="name" class="lf-form-control"
                       value="{{ old('name', $user->name) }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="email">Email</label>
                <input id="email" type="email" name="email" class="lf-form-control"
                       value="{{ old('email', $user->email) }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="phone">Phone</label>
                <input id="phone" type="text" name="phone" class="lf-form-control"
                       value="{{ old('phone', $user->phone) }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="date_of_birth">Date of Birth</label>
                <input id="date_of_birth" type="date" name="date_of_birth" class="lf-form-control"
                       value="{{ old('date_of_birth', $user->date_of_birth) }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="gender">Gender</label>
                <select id="gender" name="gender" class="lf-form-control">
                    <option value="">Select Gender</option>
                    <option value="male" @selected(old('gender', $user->gender) === 'male')>Male</option>
                    <option value="female" @selected(old('gender', $user->gender) === 'female')>Female</option>
                    <option value="other" @selected(old('gender', $user->gender) === 'other')>Other</option>
                </select>
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="role">Role</label>
                <select id="role" name="role" class="lf-form-control">
                    <option value="customer_admin" @selected($user->role === 'customer_admin')>Customer Admin</option>
                    <option value="teacher" @selected($user->role === 'teacher')>Teacher</option>
                    <option value="student" @selected($user->role === 'student')>Student</option>
                </select>
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('admin.users.index') }}">Cancel</a>
                <button type="button" class="btn btn-secondary" x-data
                        x-on:click="$dispatch('open-modal', 'change-user-password')">
                    {{ (int) $user->id === (int) auth()->id() ? 'Change My Password' : 'Change User Password' }}
                </button>
            </div>
        </form>
    </div>

    <x-modal name="change-user-password" :show="$errors->resetPassword->any()" focusable>
        <div class="lf-modal-card">
            <h2>
                {{ (int) $user->id === (int) auth()->id() ? 'Change My Password' : 'Change User Password' }}
            </h2>

            @if ($errors->resetPassword->any())
                <div class="lf-alert-danger">
                    <ul>
                        @foreach ($errors->resetPassword->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.users.update', $user->id) }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="password_reset" value="1">

                @if ((int) $user->id === (int) auth()->id())
                    <div class="lf-form-group">
                        <label class="lf-form-label">Current Password</label>
                        <input type="password" name="current_password" class="lf-form-control"
                               autocomplete="current-password" required>
                    </div>
                @endif

                <div class="lf-form-group">
                    <label class="lf-form-label">New Password</label>
                    <input type="password" name="password" class="lf-form-control"
                           autocomplete="new-password" required>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label">Confirm New Password</label>
                    <input type="password" name="password_confirmation" class="lf-form-control"
                           autocomplete="new-password" required>
                </div>

                <div class="lf-modal-actions">
                    <button type="button" class="btn btn-secondary"
                            x-on:click="$dispatch('close-modal', 'change-user-password')">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">Save Password</button>
                </div>
            </form>
        </div>
    </x-modal>
@endsection
