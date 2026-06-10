@extends('layouts.tenant')

@section('title', 'Edit User')
@section('page_title', 'Edit User')

@section('content')

    <div class="lf-container">

        <h1>Edit User</h1>

        @if (session('success'))
            <div style="background:#dcfce7;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:20px;max-width:720px;">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->default->any())
            <div style="
            background:#fee2e2;
            color:#991b1b;
            padding:12px 16px;
            border-radius:8px;
            margin-bottom:20px;
        ">
                <ul style="margin:0;padding-left:20px;">
                    @foreach ($errors->default->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="lf-profile-card">

            <form method="POST"
                  action="{{ route('admin.users.update', $user->id) }}">

                @csrf
                @method('PUT')

                <div class="lf-form-group">

                    <label class="lf-form-label">
                        Name
                    </label>

                    <input type="text"
                           name="name"
                           class="lf-form-control"
                           value="{{ old('name', $user->name) }}">

                </div>

                <div class="lf-form-group">

                    <label class="lf-form-label">
                        Email
                    </label>

                    <input type="email"
                           name="email"
                           class="lf-form-control"
                           value="{{ old('email', $user->email) }}">

                </div>

                <div class="lf-form-group">

                    <label class="lf-form-label">
                        Phone
                    </label>

                    <input type="text"
                           name="phone"
                           class="lf-form-control"
                           value="{{ old('phone', $user->phone) }}">

                </div>

                <div class="lf-form-group">

                    <label class="lf-form-label">
                        Date of Birth
                    </label>

                    <input type="date"
                           name="date_of_birth"
                           class="lf-form-control"
                           value="{{ old('date_of_birth', $user->date_of_birth) }}">

                </div>

                <div class="lf-form-group">

                    <label class="lf-form-label">
                        Gender
                    </label>

                    <select name="gender"
                            class="lf-form-control">

                        <option value="">
                            Select Gender
                        </option>

                        <option value="male"
                                @selected(old('gender', $user->gender) == 'male')>
                            Male
                        </option>

                        <option value="female"
                                @selected(old('gender', $user->gender) == 'female')>
                            Female
                        </option>

                        <option value="other"
                                @selected(old('gender', $user->gender) == 'other')>
                            Other
                        </option>

                    </select>

                </div>

                <div class="lf-form-group">

                    <label class="lf-form-label">
                        Role
                    </label>

                    <select name="role"
                            class="lf-form-control">

                        <option value="customer_admin"
                                @selected($user->role == 'customer_admin')>
                            Customer Admin
                        </option>

                        <option value="teacher"
                                @selected($user->role == 'teacher')>
                            Teacher
                        </option>

                        <option value="student"
                                @selected($user->role == 'student')>
                            Student
                        </option>

                    </select>

                </div>

                <div style="margin-top:24px;">

                    <button type="submit"
                            class="lf-btn-primary">
                        Save Changes
                    </button>

                    <a href="{{ route('admin.users.index') }}"
                       style="margin-left:12px;">
                        Cancel
                    </a>

                    <button type="button"
                            class="lf-btn-secondary"
                            style="margin-left:12px;"
                            x-data
                            x-on:click="$dispatch('open-modal', 'change-user-password')">
                        {{ (int) $user->id === (int) auth()->id()
                            ? 'Change My Password'
                            : 'Change User Password' }}
                    </button>

                </div>

            </form>

        </div>

        <x-modal name="change-user-password" :show="$errors->resetPassword->any()" focusable>
            <div class="lf-modal-card">
                <h2>
                    {{ (int) $user->id === (int) auth()->id()
                        ? 'Change My Password'
                        : 'Change User Password' }}
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
                        <button type="button" class="lf-btn-secondary"
                                x-on:click="$dispatch('close-modal', 'change-user-password')">
                            Cancel
                        </button>
                        <button type="submit" class="lf-btn-primary">Save Password</button>
                    </div>
                </form>
            </div>
        </x-modal>

    </div>

@endsection
