@extends('layouts.backend')

@section('title', __('lf.LF_admin_title_admin_edit_user'))
@section('page_title', __('lf.LF_admin_title_admin_edit_user'))

@section('content')
    @if (session('success'))
        <div class="admin-alert admin-alert-success admin-form-card">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->default->any())
        <div class="admin-alert admin-alert-danger admin-form-card">
            <ul>
                @foreach ($errors->default->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-card admin-form-card admin-form-surface">
        <form id="admin-user-update-form"
              class="admin-form-standard"
              method="POST"
              action="{{ route('admin.users.update', $user->id) }}">
            @csrf
            @method('PUT')

            <div class="admin-form-flow">
                <section class="admin-form-standard-section" aria-labelledby="admin-user-edit-personal">
                    <h2 id="admin-user-edit-personal" class="admin-form-section-title">
                        {{ __('lf.LF_admin_group_user_personal') }}
                    </h2>

                    <div class="admin-form-field-grid admin-form-field-grid--three">
                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="name" :value="__('lf.LF_common_label_name')" :required="true" />
                            <input id="name" type="text" name="name" class="lf-form-control"
                                   value="{{ old('name', $user->name) }}" placeholder="{{ __('lf.LF_admin_placeholder_user_name') }}" required
                                   @if($errors->default->has('name')) aria-invalid="true" aria-describedby="name_error" @endif>
                            @error('name')<p id="name_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="email" :value="__('lf.LF_common_label_email')" :required="true" />
                            <input id="email" type="email" name="email" class="lf-form-control"
                                   value="{{ old('email', $user->email) }}" placeholder="{{ __('lf.LF_admin_placeholder_user_email') }}" required
                                   @if($errors->default->has('email')) aria-invalid="true" aria-describedby="email_error" @endif>
                            @error('email')<p id="email_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <label class="lf-form-label" for="phone">{{ __('lf.LF_common_label_phone') }}</label>
                            <input id="phone" type="text" name="phone" class="lf-form-control"
                                   value="{{ old('phone', $user->phone) }}" placeholder="{{ __('lf.LF_admin_placeholder_user_phone') }}"
                                   @if($errors->default->has('phone')) aria-invalid="true" aria-describedby="phone_error" @endif>
                            @error('phone')<p id="phone_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <label class="lf-form-label" for="date_of_birth">{{ __('lf.LF_common_label_date_of_birth') }}</label>
                            <input id="date_of_birth" type="date" name="date_of_birth" class="lf-form-control"
                                   value="{{ old('date_of_birth', $user->date_of_birth) }}"
                                   @if($errors->default->has('date_of_birth')) aria-invalid="true" aria-describedby="date_of_birth_error" @endif>
                            @error('date_of_birth')<p id="date_of_birth_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <label class="lf-form-label" for="gender">{{ __('lf.LF_common_label_gender') }}</label>
                            <select id="gender" name="gender" class="lf-form-control"
                                    @if($errors->default->has('gender')) aria-invalid="true" aria-describedby="gender_error" @endif>
                                <option value="">{{ __('lf.LF_common_gender_common_select') }}</option>
                                <option value="male" @selected(old('gender', $user->gender) === 'male')>{{ __('lf.LF_common_gender_common_male') }}</option>
                                <option value="female" @selected(old('gender', $user->gender) === 'female')>{{ __('lf.LF_common_gender_common_female') }}</option>
                                <option value="other" @selected(old('gender', $user->gender) === 'other')>{{ __('lf.LF_common_gender_common_other') }}</option>
                            </select>
                            @error('gender')<p id="gender_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="admin-user-edit-access">
                    <header class="admin-form-section-header">
                        <h2 id="admin-user-edit-access" class="admin-form-section-title">{{ __('lf.LF_admin_group_user_access') }}</h2>
                        <p class="admin-form-section-help">{{ __('lf.LF_admin_help_user_access') }}</p>
                    </header>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="role" :value="__('lf.LF_common_label_role')" :required="true" />
                            <select id="role" name="role" class="lf-form-control" required
                                    @if($errors->default->has('role')) aria-invalid="true" aria-describedby="role_error" @endif>
                                <option value="customer_admin" @selected(old('role', $user->role) === 'customer_admin')>{{ __('lf.LF_common_role_admin_customer_admin') }}</option>
                                <option value="teacher" @selected(old('role', $user->role) === 'teacher')>{{ __('lf.LF_common_role_teacher_teacher') }}</option>
                                <option value="student" @selected(old('role', $user->role) === 'student')>{{ __('lf.LF_common_role_student_student') }}</option>
                            </select>
                            @error('role')<p id="role_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="admin-user-edit-security">
                    <header class="admin-form-section-header">
                        <h2 id="admin-user-edit-security" class="admin-form-section-title">{{ __('lf.LF_admin_group_user_security') }}</h2>
                        <p class="admin-form-section-help">{{ __('lf.LF_admin_help_user_edit_security') }}</p>
                    </header>
                    <button type="button" class="btn btn-secondary" x-data
                            x-on:click="$dispatch('open-modal', 'change-user-password')">
                        {{ (int) $user->id === (int) auth()->id() ? __('lf.LF_profile_button_admin_change_my_password') : __('lf.LF_profile_button_admin_change_user_password') }}
                    </button>
                </section>
            </div>

            <footer class="admin-form-footer">
                <div class="admin-form-footer-danger"></div>
                <div class="admin-form-footer-primary">
                    <a href="{{ route('admin.users.index', ['role' => $user->role]) }}" class="btn btn-secondary">{{ __('lf.LF_common_button_cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_save_changes') }}</button>
                </div>
            </footer>
        </form>
    </div>

    <x-modal name="change-user-password" :show="$errors->resetPassword->any()" max-width="lg" focusable>
        <div class="lf-modal-card admin-password-modal">
            <header class="admin-password-modal-header">
                <div class="admin-password-modal-heading">
                    <span class="admin-password-modal-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="5" y="10" width="14" height="10" rx="2"></rect>
                            <path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"></path>
                        </svg>
                    </span>
                    <div>
                        <h2 id="change-user-password-title">
                            {{ (int) $user->id === (int) auth()->id() ? __('lf.LF_profile_button_admin_change_my_password') : __('lf.LF_profile_button_admin_change_user_password') }}
                        </h2>
                        <p>{{ __('lf.LF_admin_help_user_edit_security') }}</p>
                    </div>
                </div>
                <button type="button" class="admin-password-modal-close"
                        aria-label="{{ __('lf.LF_common_button_close') }}"
                        x-on:click="$dispatch('close-modal', 'change-user-password')">
                    <span aria-hidden="true">×</span>
                </button>
            </header>

            @if ($errors->resetPassword->any())
                <div class="lf-alert-danger admin-password-modal-errors" role="alert">
                    <ul>
                        @foreach ($errors->resetPassword->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.users.update', $user->id) }}"
                  aria-labelledby="change-user-password-title">
                @csrf
                @method('PUT')
                <input type="hidden" name="password_reset" value="1">

                @if ((int) $user->id === (int) auth()->id())
                    <div class="lf-form-group admin-password-field" x-data="{ visible: false }">
                        <label for="user_current_password" class="lf-form-label">
                            {{ __('lf.LF_common_label_current_password') }}
                            <span class="lf-required-indicator" aria-hidden="true">*</span>
                        </label>
                        <div class="admin-password-control">
                            <input id="user_current_password" type="password" x-bind:type="visible ? 'text' : 'password'"
                                   name="current_password" class="lf-form-control" autocomplete="current-password"
                                   placeholder="{{ __('lf.LF_profile_placeholder_current_password') }}"
                                   aria-required="true" autofocus required>
                            <button type="button" x-on:click="visible = ! visible"
                                    x-bind:aria-label="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))"
                                    x-bind:title="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))">
                                <svg x-show="! visible" class="admin-password-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.75"></circle></svg>
                                <svg x-show="visible" x-cloak class="admin-password-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m3 3 18 18M10.6 6.15A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a15 15 0 0 1-2.1 2.75M6.2 6.2C3.8 7.85 2.5 12 2.5 12s3.5 6 9.5 6a9.8 9.8 0 0 0 3.15-.52M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg>
                            </button>
                        </div>
                        @foreach ($errors->resetPassword->get('current_password') as $error)
                            <p class="lf-form-error">{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <div class="lf-form-group admin-password-field" x-data="{ visible: false }">
                    <label for="user_new_password" class="lf-form-label">
                        {{ __('lf.LF_common_label_new_password') }}
                        <span class="lf-required-indicator" aria-hidden="true">*</span>
                    </label>
                    <div class="admin-password-control">
                        <input id="user_new_password" type="password" x-bind:type="visible ? 'text' : 'password'"
                               name="password" class="lf-form-control" autocomplete="new-password"
                               placeholder="{{ __('lf.LF_profile_placeholder_new_password') }}"
                               aria-describedby="user_new_password_help" aria-required="true" required
                               @if ((int) $user->id !== (int) auth()->id()) autofocus @endif>
                        <button type="button" x-on:click="visible = ! visible"
                                x-bind:aria-label="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))"
                                x-bind:title="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))">
                            <svg x-show="! visible" class="admin-password-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.75"></circle></svg>
                            <svg x-show="visible" x-cloak class="admin-password-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m3 3 18 18M10.6 6.15A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a15 15 0 0 1-2.1 2.75M6.2 6.2C3.8 7.85 2.5 12 2.5 12s3.5 6 9.5 6a9.8 9.8 0 0 0 3.15-.52M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg>
                        </button>
                    </div>
                    <p id="user_new_password_help" class="lf-form-help">{{ __('lf.LF_profile_help_new_password') }}</p>
                    @foreach ($errors->resetPassword->get('password') as $error)
                        <p class="lf-form-error">{{ $error }}</p>
                    @endforeach
                </div>

                <div class="lf-form-group admin-password-field" x-data="{ visible: false }">
                    <label for="user_password_confirmation" class="lf-form-label">
                        {{ __('lf.LF_common_label_confirm_new_password') }}
                        <span class="lf-required-indicator" aria-hidden="true">*</span>
                    </label>
                    <div class="admin-password-control">
                        <input id="user_password_confirmation" type="password" x-bind:type="visible ? 'text' : 'password'"
                               name="password_confirmation" class="lf-form-control" autocomplete="new-password"
                               placeholder="{{ __('lf.LF_profile_placeholder_confirm_new_password') }}"
                               aria-required="true" required>
                        <button type="button" x-on:click="visible = ! visible"
                                x-bind:aria-label="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))"
                                x-bind:title="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))">
                            <svg x-show="! visible" class="admin-password-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.75"></circle></svg>
                            <svg x-show="visible" x-cloak class="admin-password-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m3 3 18 18M10.6 6.15A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a15 15 0 0 1-2.1 2.75M6.2 6.2C3.8 7.85 2.5 12 2.5 12s3.5 6 9.5 6a9.8 9.8 0 0 0 3.15-.52M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg>
                        </button>
                    </div>
                </div>

                <div class="lf-modal-actions admin-password-modal-actions">
                    <button type="button" class="btn btn-secondary"
                            x-on:click="$dispatch('close-modal', 'change-user-password')">
                        {{ __('lf.LF_common_button_cancel') }}
                    </button>
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_profile_button_common_save_password') }}</button>
                </div>
            </form>
        </div>
    </x-modal>
@endsection
