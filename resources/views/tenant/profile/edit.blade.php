@extends('layouts.tenant')

@section('title', __('lf.LF_profile_title_student_profile').' | '.__('lf.LF_common_brand_name'))

@section('content')
    <div class="student-profile-shell">
        <header class="student-page-heading">
            <h1>{{ __('lf.LF_profile_title_student_profile') }}</h1>
            <p>{{ __('lf.LF_profile_message_student_profile') }}</p>
        </header>

        @if (session('profile_success'))
            <div class="student-alert is-success">{{ session('profile_success') }}</div>
        @endif

        @if ($errors->default->any())
            <div class="student-alert is-danger">
                <ul>
                    @foreach ($errors->default->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('password_success'))
            <div class="student-alert is-success">{{ session('password_success') }}</div>
        @endif

        <div class="student-profile-layout">
            <section class="student-card student-profile-card">
                <div class="student-profile-card-heading">
                    <div>
                        <p class="student-profile-card-label">{{ __('lf.LF_profile_section_student_personal_information') }}</p>
                        <h2>{{ __('lf.LF_profile_title_student_information') }}</h2>
                    </div>
                    <span class="student-profile-status">{{ __('lf.LF_common_status_common_active') }}</span>
                </div>

                <form method="POST" action="{{ route('student.profile.update') }}">
                    @csrf
                    @method('PATCH')

                    <div class="student-form-grid">
                        <div class="student-form-group student-form-span">
                            <label class="student-form-label" for="student-name">{{ __('lf.LF_profile_label_student_full_name') }}</label>
                            <input id="student-name" type="text" name="name" class="student-form-control"
                                   value="{{ old('name', $user->name) }}" required>
                        </div>

                        <div class="student-form-group">
                            <label class="student-form-label" for="student-email">{{ __('lf.LF_common_label_email') }}</label>
                            <input id="student-email" type="email" name="email" class="student-form-control"
                                   value="{{ old('email', $user->email) }}" required>
                        </div>

                        <div class="student-form-group">
                            <label class="student-form-label" for="student-phone">{{ __('lf.LF_common_label_phone') }}</label>
                            <input id="student-phone" type="text" name="phone" class="student-form-control"
                                   value="{{ old('phone', $user->phone) }}">
                        </div>

                        <div class="student-form-group">
                            <label class="student-form-label" for="student-date-of-birth">{{ __('lf.LF_common_label_date_of_birth') }}</label>
                            <input id="student-date-of-birth" type="date" name="date_of_birth"
                                   class="student-form-control" value="{{ old('date_of_birth', $user->date_of_birth) }}">
                        </div>

                        <div class="student-form-group">
                            <label class="student-form-label" for="student-gender">{{ __('lf.LF_common_label_gender') }}</label>
                            <select id="student-gender" name="gender" class="student-form-control">
                                <option value="">{{ __('lf.LF_common_gender_common_select') }}</option>
                                <option value="male" @selected(old('gender', $user->gender) === 'male')>{{ __('lf.LF_common_gender_common_male') }}</option>
                                <option value="female" @selected(old('gender', $user->gender) === 'female')>{{ __('lf.LF_common_gender_common_female') }}</option>
                                <option value="other" @selected(old('gender', $user->gender) === 'other')>{{ __('lf.LF_common_gender_common_other') }}</option>
                            </select>
                        </div>

                        <div class="student-form-group student-form-span">
                            <label class="student-form-label" for="student-role">{{ __('lf.LF_common_label_role') }}</label>
                            <input id="student-role" type="text" class="student-form-control" value="{{ __('lf.LF_common_role_student_student') }}" disabled>
                        </div>
                    </div>

                    <div class="student-form-actions">
                        <button type="submit" class="student-button">{{ __('lf.LF_common_button_save_changes') }}</button>
                    </div>
                </form>
            </section>

            <aside class="student-profile-sidebar" aria-label="{{ __('lf.LF_profile_label_student_settings') }}">
                <section class="student-card student-profile-side-card">
                    <span class="student-profile-side-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Z"></path>
                            <path d="m9 12 2 2 4-4"></path>
                        </svg>
                    </span>
                    <div class="student-profile-side-copy">
                        <h2>{{ __('lf.LF_profile_section_student_security') }}</h2>
                        <p>{{ __('lf.LF_profile_message_student_security') }}</p>
                    </div>
                    <button type="button" class="student-button is-outline" x-data
                            x-on:click="$dispatch('open-modal', 'change-password')">
                        {{ __('lf.LF_common_button_common_change_password') }}
                    </button>
                </section>

                <section class="student-card student-profile-side-card">
                    <span class="student-profile-side-icon is-green">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"></path>
                        </svg>
                    </span>
                    <div class="student-profile-side-copy">
                        <h2>{{ __('lf.LF_profile_label_student_language') }}</h2>
                        <p>{{ __('lf.LF_profile_message_student_language') }}</p>
                    </div>
                    <div class="student-profile-setting-value">
                        <span>{{ __('lf.LF_common_label_common_language_code') }}</span>
                        {{ __('lf.LF_profile_value_student_vietnamese') }}
                    </div>
                </section>

                <section class="student-card student-profile-side-card">
                    <div class="student-profile-progress-heading">
                        <div>
                            <p class="student-profile-card-label">{{ __('lf.LF_profile_section_student_progress') }}</p>
                            <h2>{{ __('lf.LF_profile_title_student_your_profile') }}</h2>
                        </div>
                        <strong>80%</strong>
                    </div>
                    <div class="student-profile-progress-track" aria-label="{{ __('lf.LF_profile_label_student_progress_aria') }}">
                        <span></span>
                    </div>
                    <p class="student-profile-progress-copy">
                        {{ __('lf.LF_profile_message_student_progress') }}
                    </p>
                </section>
            </aside>
        </div>

        @include('profile.partials.password-modal', [
            'action' => route('student.profile.password.update'),
            'variant' => 'student',
        ])
    </div>
@endsection
