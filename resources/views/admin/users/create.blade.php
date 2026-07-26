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

    <div class="admin-card admin-form-card admin-form-surface">
        <form id="admin-user-create-form"
              class="admin-form-standard"
              method="POST"
              action="{{ route('admin.users.store') }}">
            @csrf

            <div class="admin-form-flow">
                <section class="admin-form-standard-section" aria-labelledby="admin-user-create-personal">
                    <h2 id="admin-user-create-personal" class="admin-form-section-title">
                        {{ __('lf.LF_admin_group_user_personal') }}
                    </h2>

                    <div class="admin-form-field-grid admin-form-field-grid--three">
                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="name" :value="__('lf.LF_common_label_name')" :required="true" />
                            <input id="name" type="text" name="name" class="lf-form-control"
                                   value="{{ old('name') }}" placeholder="{{ __('lf.LF_admin_placeholder_user_name') }}" required
                                   @if($errors->has('name')) aria-invalid="true" aria-describedby="name_error" @endif>
                            @error('name')<p id="name_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="email" :value="__('lf.LF_common_label_email')" :required="true" />
                            <input id="email" type="email" name="email" class="lf-form-control"
                                   value="{{ old('email') }}" placeholder="{{ __('lf.LF_admin_placeholder_user_email') }}" required
                                   @if($errors->has('email')) aria-invalid="true" aria-describedby="email_error" @endif>
                            @error('email')<p id="email_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <label class="lf-form-label" for="phone">{{ __('lf.LF_common_label_phone') }}</label>
                            <input id="phone" type="text" name="phone" class="lf-form-control"
                                   value="{{ old('phone') }}" placeholder="{{ __('lf.LF_admin_placeholder_user_phone') }}"
                                   @if($errors->has('phone')) aria-invalid="true" aria-describedby="phone_error" @endif>
                            @error('phone')<p id="phone_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <label class="lf-form-label" for="date_of_birth">{{ __('lf.LF_common_label_date_of_birth') }}</label>
                            <input id="date_of_birth" type="date" name="date_of_birth" class="lf-form-control"
                                   value="{{ old('date_of_birth') }}"
                                   @if($errors->has('date_of_birth')) aria-invalid="true" aria-describedby="date_of_birth_error" @endif>
                            @error('date_of_birth')<p id="date_of_birth_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <label class="lf-form-label" for="gender">{{ __('lf.LF_common_label_gender') }}</label>
                            <select id="gender" name="gender" class="lf-form-control"
                                    @if($errors->has('gender')) aria-invalid="true" aria-describedby="gender_error" @endif>
                                <option value="">{{ __('lf.LF_common_gender_common_select') }}</option>
                                <option value="male" @selected(old('gender') === 'male')>{{ __('lf.LF_common_gender_common_male') }}</option>
                                <option value="female" @selected(old('gender') === 'female')>{{ __('lf.LF_common_gender_common_female') }}</option>
                                <option value="other" @selected(old('gender') === 'other')>{{ __('lf.LF_common_gender_common_other') }}</option>
                            </select>
                            @error('gender')<p id="gender_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="admin-user-create-access">
                    <header class="admin-form-section-header">
                        <h2 id="admin-user-create-access" class="admin-form-section-title">{{ __('lf.LF_admin_group_user_access') }}</h2>
                        <p class="admin-form-section-help">{{ __('lf.LF_admin_help_user_access') }}</p>
                    </header>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="role" :value="__('lf.LF_common_label_role')" :required="true" />
                            <select id="role" name="role" class="lf-form-control" required
                                    @if($errors->has('role')) aria-invalid="true" aria-describedby="role_error" @endif>
                                <option value="customer_admin" @selected(old('role', $role) === 'customer_admin')>{{ __('lf.LF_common_role_admin_customer_admin') }}</option>
                                <option value="teacher" @selected(old('role', $role) === 'teacher')>{{ __('lf.LF_common_role_teacher_teacher') }}</option>
                                <option value="student" @selected(old('role', $role) === 'student')>{{ __('lf.LF_common_role_student_student') }}</option>
                            </select>
                            @error('role')<p id="role_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="admin-user-create-security">
                    <header class="admin-form-section-header">
                        <h2 id="admin-user-create-security" class="admin-form-section-title">{{ __('lf.LF_admin_group_user_security') }}</h2>
                        <p class="admin-form-section-help">{{ __('lf.LF_admin_help_user_create_security') }}</p>
                    </header>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="password" :value="__('lf.LF_common_label_password')" :required="true" />
                            <input id="password" type="password" name="password" class="lf-form-control" autocomplete="new-password" placeholder="{{ __('lf.LF_admin_placeholder_user_password') }}" required
                                   @if($errors->has('password')) aria-invalid="true" aria-describedby="password_error" @endif>
                            @error('password')<p id="password_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="password_confirmation" :value="__('lf.LF_common_label_confirm_password')" :required="true" />
                            <input id="password_confirmation" type="password" name="password_confirmation"
                                   class="lf-form-control" autocomplete="new-password" placeholder="{{ __('lf.LF_admin_placeholder_user_password_confirmation') }}" required>
                        </div>
                    </div>
                </section>
            </div>

            <footer class="admin-form-footer">
                <div class="admin-form-footer-danger"></div>
                <div class="admin-form-footer-primary">
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_admin_button_admin_create_user') }}</button>
                    <a href="{{ route('admin.users.index') }}" class="admin-form-cancel">{{ __('lf.LF_common_button_cancel') }}</a>
                </div>
            </footer>
        </form>
    </div>
@endsection
