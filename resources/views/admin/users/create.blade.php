@extends('layouts.tenant')

@section('title', 'Create User')
@section('page_title', 'Create User')

@section('content')

    <div class="lf-container">

        <h1>Create User</h1>

        @if ($errors->any())
            <div style="
            background:#fee2e2;
            color:#991b1b;
            padding:12px 16px;
            border-radius:8px;
            margin-bottom:20px;
        ">
                <ul style="margin:0;padding-left:20px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="lf-profile-card">

            <form method="POST"
                  action="{{ route('admin.users.store') }}">

                @csrf

                <div class="lf-form-group">

                    <label class="lf-form-label">
                        Name
                    </label>

                    <input type="text"
                           name="name"
                           class="lf-form-control"
                           value="{{ old('name') }}">

                </div>

                <div class="lf-form-group">

                    <label class="lf-form-label">
                        Email
                    </label>

                    <input type="email"
                           name="email"
                           class="lf-form-control"
                           value="{{ old('email') }}">

                </div>

                <div class="lf-form-group">

                    <label class="lf-form-label">
                        Phone
                    </label>

                    <input type="text"
                           name="phone"
                           class="lf-form-control"
                           value="{{ old('phone') }}">

                </div>

                <div class="lf-form-group">

                    <label class="lf-form-label">
                        Date of Birth
                    </label>

                    <input type="date"
                           name="date_of_birth"
                           class="lf-form-control"
                           value="{{ old('date_of_birth') }}">

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
                                {{ old('gender') == 'male' ? 'selected' : '' }}>
                            Male
                        </option>

                        <option value="female"
                                {{ old('gender') == 'female' ? 'selected' : '' }}>
                            Female
                        </option>

                        <option value="other"
                                {{ old('gender') == 'other' ? 'selected' : '' }}>
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

                        <option value="teacher"
                                {{ old('role') == 'teacher' ? 'selected' : '' }}>
                            Teacher
                        </option>

                        <option value="student"
                                {{ old('role') == 'student' ? 'selected' : '' }}>
                            Student
                        </option>

                    </select>

                </div>

                <div class="lf-form-group">

                    <label class="lf-form-label">
                        Password
                    </label>

                    <input type="password"
                           name="password"
                           class="lf-form-control">

                </div>

                <div class="lf-form-group">

                    <label class="lf-form-label">
                        Confirm Password
                    </label>

                    <input type="password"
                           name="password_confirmation"
                           class="lf-form-control">

                </div>

                <div style="margin-top:24px;">

                    <button type="submit"
                            class="lf-btn-primary">
                        Create User
                    </button>

                    <a href="{{ route('admin.users.index') }}"
                       style="margin-left:12px;">
                        Cancel
                    </a>

                </div>

            </form>

        </div>

    </div>

@endsection
