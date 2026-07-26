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

    <div class="admin-card admin-form-card admin-form-surface teacher-profile-card">
        <form class="admin-form-standard" method="POST" action="{{ route('teacher.profile.update') }}">
            @csrf
            @method('PATCH')

            <div class="admin-form-flow">
                <section class="admin-form-standard-section" aria-labelledby="teacher-profile-information">
                    <header class="admin-form-section-header">
                        <h2 id="teacher-profile-information" class="admin-form-section-title">{{ __('lf.LF_profile_title_common_information') }}</h2>
                        <p class="admin-form-section-help">{{ __('lf.LF_profile_message_teacher_information') }}</p>
                    </header>

                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="teacher-name" :value="__('lf.LF_common_label_name')" :required="true" />
                            <input id="teacher-name" type="text" name="name" class="lf-form-control"
                                   value="{{ old('name', $user->name) }}" required
                                   @error('name') aria-invalid="true" aria-describedby="teacher-name-error" @enderror>
                            @error('name')<p id="teacher-name-error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="teacher-email" :value="__('lf.LF_common_label_email')" :required="true" />
                            <input id="teacher-email" type="email" name="email" class="lf-form-control"
                                   value="{{ old('email', $user->email) }}" required
                                   @error('email') aria-invalid="true" aria-describedby="teacher-email-error" @enderror>
                            @error('email')<p id="teacher-email-error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="teacher-phone" :value="__('lf.LF_common_label_phone')" />
                            <input id="teacher-phone" type="text" name="phone" class="lf-form-control"
                                   value="{{ old('phone', $user->phone) }}"
                                   @error('phone') aria-invalid="true" aria-describedby="teacher-phone-error" @enderror>
                            @error('phone')<p id="teacher-phone-error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="teacher-date-of-birth" :value="__('lf.LF_common_label_date_of_birth')" />
                            <input id="teacher-date-of-birth" type="date" name="date_of_birth" class="lf-form-control"
                                   value="{{ old('date_of_birth', $user->date_of_birth) }}"
                                   @error('date_of_birth') aria-invalid="true" aria-describedby="teacher-date-of-birth-error" @enderror>
                            @error('date_of_birth')<p id="teacher-date-of-birth-error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="teacher-gender" :value="__('lf.LF_common_label_gender')" />
                            <select id="teacher-gender" name="gender" class="lf-form-control"
                                    @error('gender') aria-invalid="true" aria-describedby="teacher-gender-error" @enderror>
                                <option value="">{{ __('lf.LF_common_gender_common_select') }}</option>
                                <option value="male" @selected(old('gender', $user->gender) === 'male')>{{ __('lf.LF_common_gender_common_male') }}</option>
                                <option value="female" @selected(old('gender', $user->gender) === 'female')>{{ __('lf.LF_common_gender_common_female') }}</option>
                                <option value="other" @selected(old('gender', $user->gender) === 'other')>{{ __('lf.LF_common_gender_common_other') }}</option>
                            </select>
                            @error('gender')<p id="teacher-gender-error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="teacher-profile-access">
                    <h2 id="teacher-profile-access" class="admin-form-section-title">{{ __('lf.LF_admin_group_user_access') }}</h2>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="teacher-role" :value="__('lf.LF_common_label_role')" />
                            <input id="teacher-role" type="text" class="lf-form-control admin-form-readonly"
                                   value="{{ __('lf.LF_common_role_teacher_teacher') }}" disabled>
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="teacher-profile-security">
                    <header class="admin-form-section-header">
                        <h2 id="teacher-profile-security" class="admin-form-section-title">{{ __('lf.LF_admin_group_user_security') }}</h2>
                        <p class="admin-form-section-help">{{ __('lf.LF_profile_help_admin_security') }}</p>
                    </header>
                    <button type="button" class="btn btn-secondary" x-data
                            x-on:click="$dispatch('open-modal', 'change-password')">
                        {{ __('lf.LF_common_button_common_change_password') }}
                    </button>
                </section>
            </div>

            <footer class="admin-form-footer">
                <div class="admin-form-footer-danger"></div>
                <div class="admin-form-footer-primary">
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_save_changes') }}</button>
                    <a class="admin-form-cancel" href="{{ route('teacher.dashboard') }}">{{ __('lf.LF_common_button_cancel') }}</a>
                </div>
            </footer>
        </form>
    </div>

    @if (session('password_success'))
        <div class="admin-alert admin-alert-success teacher-password-success">
            {{ session('password_success') }}
        </div>
    @endif

    @include('profile.partials.backend-password-modal', [
        'action' => route('teacher.profile.password.update'),
    ])
@endsection
