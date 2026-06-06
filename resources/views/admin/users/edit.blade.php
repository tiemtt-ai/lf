@extends('layouts.tenant')

@section('title', 'Edit User')
@section('page_title', 'Edit User')

@section('content')

    <div class="lf-container">

        <h1>Edit User</h1>

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

                </div>

            </form>

        </div>

    </div>

@endsection
