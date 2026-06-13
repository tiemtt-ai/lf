@extends('layouts.backend')

@section('title', 'Teacher Profile')
@section('page_title', 'Profile')

@section('content')
    @if (session('profile_success'))
        <div class="admin-alert admin-alert-success">
            {{ session('profile_success') }}
        </div>
    @endif

    @if ($errors->default->any())
        <div class="admin-alert admin-alert-danger">
            <ul>
                @foreach ($errors->default->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-card admin-form-card teacher-profile-card">
        <h2 class="teacher-profile-card-title">Profile Information</h2>
        <p class="teacher-profile-card-copy">Manage your teacher account information and security.</p>

        <form method="POST" action="{{ route('teacher.profile.update') }}">
            @csrf
            @method('PATCH')

            <div class="lf-form-group">
                <label class="lf-form-label" for="teacher-name">Name</label>
                <input id="teacher-name" type="text" name="name" class="lf-form-control"
                       value="{{ old('name', $user->name) }}" required>
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="teacher-email">Email</label>
                <input id="teacher-email" type="email" name="email" class="lf-form-control"
                       value="{{ old('email', $user->email) }}" required>
            </div>

            <div class="teacher-profile-fields">
                <div class="lf-form-group">
                    <label class="lf-form-label" for="teacher-phone">Phone</label>
                    <input id="teacher-phone" type="text" name="phone" class="lf-form-control"
                           value="{{ old('phone', $user->phone) }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="teacher-date-of-birth">Date of Birth</label>
                    <input id="teacher-date-of-birth" type="date" name="date_of_birth" class="lf-form-control"
                           value="{{ old('date_of_birth', $user->date_of_birth) }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="teacher-gender">Gender</label>
                    <select id="teacher-gender" name="gender" class="lf-form-control">
                        <option value="">Select Gender</option>
                        <option value="male" @selected(old('gender', $user->gender) === 'male')>Male</option>
                        <option value="female" @selected(old('gender', $user->gender) === 'female')>Female</option>
                        <option value="other" @selected(old('gender', $user->gender) === 'other')>Other</option>
                    </select>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="teacher-role">Role</label>
                    <input id="teacher-role" type="text" class="lf-form-control" value="Teacher" disabled>
                </div>
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-secondary" x-data
                        x-on:click="$dispatch('open-modal', 'change-password')">
                    Change Password
                </button>
            </div>
        </form>
    </div>

    @if (session('password_success'))
        <div class="admin-alert admin-alert-success teacher-password-success">
            {{ session('password_success') }}
        </div>
    @endif

    @include('profile.partials.password-modal', [
        'action' => route('teacher.profile.password.update'),
    ])
@endsection
