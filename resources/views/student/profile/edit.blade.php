@extends('layouts.tenant')

@section('title', 'Student Profile')
@section('page_title', 'Profile')

@section('content')

    <div class="lf-container">

        <h1>Student Profile</h1>

        @if (session('profile_success'))
            <div style="background:#dcfce7;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:20px;">
                {{ session('profile_success') }}
            </div>
        @endif

        @if ($errors->default->any())
            <div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:20px;">
                <ul style="margin:0;padding-left:20px;">
                    @foreach ($errors->default->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="lf-profile-card">

            <h2>Profile Information</h2>

            <form method="POST" action="{{ route('student.profile.update') }}">
                @csrf
                @method('PATCH')

                <div class="lf-form-group">
                    <label class="lf-form-label">Name</label>
                    <input type="text" name="name" class="lf-form-control"
                           value="{{ old('name', $user->name) }}" required>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label">Email</label>
                    <input type="email" name="email" class="lf-form-control"
                           value="{{ old('email', $user->email) }}" required>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label">Phone</label>
                    <input type="text" name="phone" class="lf-form-control"
                           value="{{ old('phone', $user->phone) }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" class="lf-form-control"
                           value="{{ old('date_of_birth', $user->date_of_birth) }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label">Gender</label>
                    <select name="gender" class="lf-form-control">
                        <option value="">Select Gender</option>
                        <option value="male" @selected(old('gender', $user->gender) === 'male')>Male</option>
                        <option value="female" @selected(old('gender', $user->gender) === 'female')>Female</option>
                        <option value="other" @selected(old('gender', $user->gender) === 'other')>Other</option>
                    </select>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label">Role</label>
                    <input type="text" class="lf-form-control" value="Student" disabled>
                </div>

                <div style="margin-top:24px;">
                    <button type="submit" class="lf-btn-primary">Save Changes</button>
                </div>
            </form>

        </div>

        <div style="margin-top:24px;">
            <button type="button" class="lf-btn-secondary" x-data
                    x-on:click="$dispatch('open-modal', 'change-password')">
                Change Password
            </button>
        </div>

        @if (session('password_success'))
            <div style="background:#dcfce7;color:#166534;padding:12px 16px;border-radius:8px;margin-top:20px;max-width:720px;">
                {{ session('password_success') }}
            </div>
        @endif

        @include('profile.partials.password-modal', [
            'action' => route('student.profile.password.update'),
        ])

    </div>

@endsection
