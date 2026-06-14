@extends('layouts.backend')

@section('title', __('lf.LF_admin_title_admin_create_user'))
@section('page_title', __('lf.LF_admin_title_admin_create_user'))

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
                <label class="lf-form-label" for="name">{{ __('lf.LF_common_label_name') }}</label>
                <input id="name" type="text" name="name" class="lf-form-control" value="{{ old('name') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="email">{{ __('lf.LF_common_label_email') }}</label>
                <input id="email" type="email" name="email" class="lf-form-control" value="{{ old('email') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="phone">{{ __('lf.LF_common_label_phone') }}</label>
                <input id="phone" type="text" name="phone" class="lf-form-control" value="{{ old('phone') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="date_of_birth">{{ __('lf.LF_common_label_date_of_birth') }}</label>
                <input id="date_of_birth" type="date" name="date_of_birth" class="lf-form-control"
                       value="{{ old('date_of_birth') }}">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="gender">{{ __('lf.LF_common_label_gender') }}</label>
                <select id="gender" name="gender" class="lf-form-control">
                    <option value="">{{ __('lf.LF_common_gender_common_select') }}</option>
                    <option value="male" @selected(old('gender') === 'male')>{{ __('lf.LF_common_gender_common_male') }}</option>
                    <option value="female" @selected(old('gender') === 'female')>{{ __('lf.LF_common_gender_common_female') }}</option>
                    <option value="other" @selected(old('gender') === 'other')>{{ __('lf.LF_common_gender_common_other') }}</option>
                </select>
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="role">{{ __('lf.LF_common_label_role') }}</label>
                <select id="role" name="role" class="lf-form-control">
                    <option value="teacher" @selected(old('role') === 'teacher')>{{ __('lf.LF_common_role_teacher_teacher') }}</option>
                    <option value="student" @selected(old('role') === 'student')>{{ __('lf.LF_common_role_student_student') }}</option>
                </select>
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="password">{{ __('lf.LF_common_label_password') }}</label>
                <input id="password" type="password" name="password" class="lf-form-control">
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="password_confirmation">{{ __('lf.LF_common_label_confirm_password') }}</label>
                <input id="password_confirmation" type="password" name="password_confirmation"
                       class="lf-form-control">
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">{{ __('lf.LF_admin_button_admin_create_user') }}</button>
                <a href="{{ route('admin.users.index') }}">{{ __('lf.LF_common_button_cancel') }}</a>
            </div>
        </form>
    </div>
@endsection
