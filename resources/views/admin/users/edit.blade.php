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
                <section class="admin-form-standard-section" aria-labelledby="admin-user-edit-title">
                    <h2 id="admin-user-edit-title" class="admin-form-section-title">
                        {{ __('lf.LF_admin_title_admin_edit_user') }}
                    </h2>

                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="name" :value="__('lf.LF_common_label_name')" :required="true" />
                            <input id="name" type="text" name="name" class="lf-form-control"
                                   value="{{ old('name', $user->name) }}" required
                                   @if($errors->default->has('name')) aria-invalid="true" aria-describedby="name_error" @endif>
                            @error('name')<p id="name_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="email" :value="__('lf.LF_common_label_email')" :required="true" />
                            <input id="email" type="email" name="email" class="lf-form-control"
                                   value="{{ old('email', $user->email) }}" required
                                   @if($errors->default->has('email')) aria-invalid="true" aria-describedby="email_error" @endif>
                            @error('email')<p id="email_error" class="lf-form-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <label class="lf-form-label" for="phone">{{ __('lf.LF_common_label_phone') }}</label>
                            <input id="phone" type="text" name="phone" class="lf-form-control"
                                   value="{{ old('phone', $user->phone) }}"
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
            </div>

            <footer class="admin-form-footer">
                <div class="admin-form-footer-danger"></div>
                <div class="admin-form-footer-primary">
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_save_changes') }}</button>
                    <a href="{{ route('admin.users.index') }}" class="admin-form-cancel">{{ __('lf.LF_common_button_cancel') }}</a>
                    <button type="button" class="btn btn-secondary" x-data
                            x-on:click="$dispatch('open-modal', 'change-user-password')">
                        {{ (int) $user->id === (int) auth()->id() ? __('lf.LF_profile_button_admin_change_my_password') : __('lf.LF_profile_button_admin_change_user_password') }}
                    </button>
                </div>
            </footer>
        </form>
    </div>

    <x-modal name="change-user-password" :show="$errors->resetPassword->any()" focusable>
        <div class="lf-modal-card">
            <h2>
                {{ (int) $user->id === (int) auth()->id() ? __('lf.LF_profile_button_admin_change_my_password') : __('lf.LF_profile_button_admin_change_user_password') }}
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
                        <label class="lf-form-label">{{ __('lf.LF_common_label_current_password') }}</label>
                        <input type="password" name="current_password" class="lf-form-control"
                               autocomplete="current-password" required>
                    </div>
                @endif

                <div class="lf-form-group">
                    <label class="lf-form-label">{{ __('lf.LF_common_label_new_password') }}</label>
                    <input type="password" name="password" class="lf-form-control"
                           autocomplete="new-password" required>
                </div>

                <div class="lf-form-group">
                    <label class="lf-form-label">{{ __('lf.LF_common_label_confirm_new_password') }}</label>
                    <input type="password" name="password_confirmation" class="lf-form-control"
                           autocomplete="new-password" required>
                </div>

                <div class="lf-modal-actions">
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
