@extends('layouts.backend')

@section('title', __('lf.LF_teacher_title_teacher_profile'))
@section('page_title', __('lf.LF_navigation_menu_student_profile'))

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
        <h2 class="teacher-profile-card-title">{{ __('lf.LF_profile_title_common_information') }}</h2>
        <p class="teacher-profile-card-copy">{{ __('lf.LF_profile_message_teacher_information') }}</p>

        <form method="POST" action="{{ route('teacher.profile.update') }}">
            @csrf
            @method('PATCH')

            <div class="lf-form-group">
                <label class="lf-form-label" for="teacher-name">{{ __('lf.LF_common_label_name') }}</label>
                <input id="teacher-name" type="text" name="name" class="lf-form-control"
                       value="{{ old('name', $user->name) }}" required>
            </div>

            <div class="lf-form-group">
                <label class="lf-form-label" for="teacher-email">{{ __('lf.LF_common_label_email') }}</label>
                <input id="teacher-email" type="email" name="email" class="lf-form-control"
                       value="{{ old('email', $user->email) }}" required>
            </div>

            <div class="teacher-profile-fields">
                <div class="lf-form-group">
                    <label class="lf-form-label" for="teacher-phone">{{ __('lf.LF_common_label_phone') }}</label>
                    <input id="teacher-phone" type="text" name="phone" class="lf-form-control"
                           value="{{ old('phone', $user->phone) }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="teacher-date-of-birth">{{ __('lf.LF_common_label_date_of_birth') }}</label>
                    <input id="teacher-date-of-birth" type="date" name="date_of_birth" class="lf-form-control"
                           value="{{ old('date_of_birth', $user->date_of_birth) }}">
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="teacher-gender">{{ __('lf.LF_common_label_gender') }}</label>
                    <select id="teacher-gender" name="gender" class="lf-form-control">
                        <option value="">{{ __('lf.LF_common_gender_common_select') }}</option>
                        <option value="male" @selected(old('gender', $user->gender) === 'male')>{{ __('lf.LF_common_gender_common_male') }}</option>
                        <option value="female" @selected(old('gender', $user->gender) === 'female')>{{ __('lf.LF_common_gender_common_female') }}</option>
                        <option value="other" @selected(old('gender', $user->gender) === 'other')>{{ __('lf.LF_common_gender_common_other') }}</option>
                    </select>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label" for="teacher-role">{{ __('lf.LF_common_label_role') }}</label>
                    <input id="teacher-role" type="text" class="lf-form-control" value="{{ __('lf.LF_common_role_teacher_teacher') }}" disabled>
                </div>
            </div>

            <div class="admin-form-actions">
                <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_save_changes') }}</button>
                <button type="button" class="btn btn-secondary" x-data
                        x-on:click="$dispatch('open-modal', 'change-password')">
                    {{ __('lf.LF_common_button_common_change_password') }}
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
