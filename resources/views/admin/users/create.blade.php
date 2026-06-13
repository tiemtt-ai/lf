@extends('layouts.backend')

@section('title', 'Create User')
@section('page_title', 'Create User')

@section('content')
    @if ($errors->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-card admin-form-card">
        <form method="POST" action="{{ route('admin.users.store') }}">
            @csrf

            <div class="lf-form-group">
                <label class="lf-form-label" for="name">Name</label>
                <input id="name" type="text" name="name" class="lf-form-control" value="{{ old('name') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="email">Email</label>
                <input id="email" type="email" name="email" class="lf-form-control" value="{{ old('email') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="phone">Phone</label>
                <input id="phone" type="text" name="phone" class="lf-form-control" value="{{ old('phone') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="date_of_birth">Date of Birth</label>
                <input id="date_of_birth" type="date" name="date_of_birth" class="lf-form-control"
                       value="{{ old('date_of_birth') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="gender">Gender</label>
                <select id="gender" name="gender" class="lf-form-control">
                    <option value="">Select Gender</option>
                    <option value="male" @selected(old('gender') === 'male')>Male</option>
                    <option value="female" @selected(old('gender') === 'female')>Female</option>
                    <option value="other" @selected(old('gender') === 'other')>Other</option>
                </select>
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="role">Role</label>
                <select id="role" name="role" class="lf-form-control">
                    <option value="teacher" @selected(old('role') === 'teacher')>Teacher</option>
                    <option value="student" @selected(old('role') === 'student')>Student</option>
                </select>
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="password">Password</label>
                <input id="password" type="password" name="password" class="lf-form-control">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="password_confirmation">Confirm Password</label>
                <input id="password_confirmation" type="password" name="password_confirmation"
                       class="lf-form-control">
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">Create User</button>
                <a href="{{ route('admin.users.index') }}">Cancel</a>
            </div>
        </form>
    </div>
@endsection
