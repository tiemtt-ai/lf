@extends('layouts.backend')

@section('title', __('lf.LF_profile_title_admin_my_account'))
@section('page_title', __('lf.LF_profile_title_admin_my_account'))

@section('content')
    @php
        $birthDate = old('date_of_birth', $user->date_of_birth);
        $birth = $birthDate ? \Illuminate\Support\Carbon::parse($birthDate) : null;
    @endphp

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

    <h2 class="sr-only">{{ __('lf.LF_profile_title_common_information') }}</h2>

    <div class="admin-card admin-form-card admin-form-surface">
        <form class="admin-form-standard" method="POST" action="{{ route('admin.my-account.update') }}">
            @csrf
            @method('PATCH')

            <div class="admin-form-flow">
                <section class="admin-form-standard-section" aria-labelledby="admin-account-information">
                    <header class="admin-form-section-header">
                        <h2 id="admin-account-information" class="admin-form-section-title">{{ __('lf.LF_profile_group_admin_account') }}</h2>
                    </header>
                    <div class="admin-form-field-grid admin-form-field-grid--three">
                        <div class="lf-form-group">
                            <x-form-label for="admin_username" :value="__('lf.LF_profile_label_admin_username')" />
                            <input id="admin_username" type="text" class="lf-form-control admin-form-readonly" value="{{ $user->name }}" readonly>
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="admin_email" :value="__('lf.LF_common_label_email')" />
                            <input id="admin_email" type="email" class="lf-form-control admin-form-readonly" value="{{ $user->email }}" readonly>
                        </div>
                        <div class="lf-form-group">
                            <x-form-label for="admin_registration_date" :value="__('lf.LF_profile_label_admin_registration_date')" />
                            <input id="admin_registration_date" type="text" class="lf-form-control admin-form-readonly" value="{{ $user->created_at ? \Illuminate\Support\Carbon::parse($user->created_at)->format('d/m/Y') : '01/01/1970' }}" readonly>
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="admin-personal-information">
                    <header class="admin-form-section-header">
                        <h2 id="admin-personal-information" class="admin-form-section-title">{{ __('lf.LF_profile_group_admin_personal') }}</h2>
                    </header>
                    <div class="admin-form-field-grid">
                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="admin_name" :value="__('lf.LF_profile_label_admin_name')" required />
                            <input id="admin_name" type="text" name="name" class="lf-form-control"
                                   value="{{ old('name', $user->name) }}" placeholder="{{ __('lf.LF_common_label_name') }}" required>
                        </div>

                        <div class="lf-form-group admin-form-field">
                            <x-form-label for="admin_phone" :value="__('lf.LF_profile_label_admin_phone')" />
                            <input id="admin_phone" type="text" name="phone" class="lf-form-control"
                                   value="{{ old('phone', $user->phone) }}" placeholder="0987 654 321">
                        </div>

                        <fieldset class="lf-form-group admin-form-field">
                            <legend class="lf-form-label">{{ __('lf.LF_profile_label_admin_gender') }}</legend>
                            <div class="admin-radio-group">
                                <input id="admin_male" type="radio" name="gender" value="male" @checked(old('gender', $user->gender) === 'male')>
                                <label for="admin_male">{{ __('lf.LF_common_gender_common_male') }}</label>
                                <input id="admin_female" type="radio" name="gender" value="female" @checked(old('gender', $user->gender) === 'female')>
                                <label for="admin_female">{{ __('lf.LF_common_gender_common_female') }}</label>
                                <input id="admin_other" type="radio" name="gender" value="other" @checked(old('gender', $user->gender) === 'other')>
                                <label for="admin_other">{{ __('lf.LF_common_gender_common_other') }}</label>
                            </div>
                        </fieldset>

                        <div class="lf-form-group admin-form-field"
                             x-data="{ day: '{{ $birth?->format('d') }}', month: '{{ $birth?->format('m') }}', year: '{{ $birth?->format('Y') }}' }">
                            <span class="lf-form-label">{{ __('lf.LF_profile_label_admin_birth_date') }}</span>
                            <input type="hidden" name="date_of_birth" x-bind:value="day && month && year ? `${year}-${month}-${day}` : ''">
                            <div class="admin-birth-fields">
                                <select class="lf-form-control" x-model="day" aria-label="{{ __('lf.LF_common_label_day') }}">
                                    <option value="">{{ __('lf.LF_common_label_day') }}</option>
                                    @foreach (range(1, 31) as $day)<option value="{{ str_pad((string) $day, 2, '0', STR_PAD_LEFT) }}">{{ $day }}</option>@endforeach
                                </select>
                                <select class="lf-form-control" x-model="month" aria-label="{{ __('lf.LF_common_label_month') }}">
                                    <option value="">{{ __('lf.LF_common_label_month') }}</option>
                                    @foreach (range(1, 12) as $month)<option value="{{ str_pad((string) $month, 2, '0', STR_PAD_LEFT) }}">{{ $month }}</option>@endforeach
                                </select>
                                <select class="lf-form-control" x-model="year" aria-label="{{ __('lf.LF_common_label_year') }}">
                                    <option value="">{{ __('lf.LF_common_label_year') }}</option>
                                    @foreach (range((int) now()->format('Y'), 1940) as $year)<option value="{{ $year }}">{{ $year }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="admin-form-standard-section" aria-labelledby="admin-account-security">
                    <header class="admin-form-section-header">
                        <h2 id="admin-account-security" class="admin-form-section-title">{{ __('lf.LF_profile_group_admin_security') }}</h2>
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
                </div>
            </footer>
        </form>
    </div>

    @if (session('password_success'))
        <div class="admin-alert admin-alert-success">
            {{ session('password_success') }}
        </div>
    @endif

    <x-modal name="change-password" :show="$errors->updatePassword->any()" max-width="lg" focusable>
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
                        <h2 id="admin-password-modal-title">{{ __('lf.LF_profile_title_common_change_password') }}</h2>
                        <p>{{ __('lf.LF_profile_help_admin_security') }}</p>
                    </div>
                </div>
                <button type="button" class="admin-password-modal-close"
                        aria-label="{{ __('lf.LF_common_button_close') }}"
                        x-on:click="$dispatch('close-modal', 'change-password')">
                    <span aria-hidden="true">×</span>
                </button>
            </header>

            @if ($errors->updatePassword->any())
                <div class="lf-alert-danger admin-password-modal-errors" role="alert">
                    <ul>
                        @foreach ($errors->updatePassword->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.my-account.password.update') }}"
                  aria-labelledby="admin-password-modal-title">
                @csrf
                @method('PATCH')

                <div class="lf-form-group admin-password-field" x-data="{ visible: false }">
                    <label for="admin_current_password" class="lf-form-label">
                        {{ __('lf.LF_common_label_current_password') }}
                        <span class="lf-required-indicator" aria-hidden="true">*</span>
                    </label>
                    <div class="admin-password-control">
                        <input id="admin_current_password" type="password" x-bind:type="visible ? 'text' : 'password'"
                               name="current_password" class="lf-form-control"
                               autocomplete="current-password"
                               placeholder="{{ __('lf.LF_profile_placeholder_current_password') }}"
                               aria-required="true" required autofocus>
                        <button type="button" x-on:click="visible = ! visible"
                                x-bind:aria-label="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))"
                                x-bind:title="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))">
                            <svg x-show="! visible" class="admin-password-eye" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path>
                                <circle cx="12" cy="12" r="2.75"></circle>
                            </svg>
                            <svg x-show="visible" x-cloak class="admin-password-eye" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="m3 3 18 18M10.6 6.15A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a15 15 0 0 1-2.1 2.75M6.2 6.2C3.8 7.85 2.5 12 2.5 12s3.5 6 9.5 6a9.8 9.8 0 0 0 3.15-.52M9.9 9.9a3 3 0 0 0 4.2 4.2"></path>
                            </svg>
                        </button>
                    </div>
                    @foreach ($errors->updatePassword->get('current_password') as $error)
                        <p class="lf-form-error">{{ $error }}</p>
                    @endforeach
                </div>

                <div class="lf-form-group admin-password-field" x-data="{ visible: false }">
                    <label for="admin_new_password" class="lf-form-label">
                        {{ __('lf.LF_common_label_new_password') }}
                        <span class="lf-required-indicator" aria-hidden="true">*</span>
                    </label>
                    <div class="admin-password-control">
                        <input id="admin_new_password" type="password" x-bind:type="visible ? 'text' : 'password'"
                               name="password" class="lf-form-control"
                               autocomplete="new-password"
                               placeholder="{{ __('lf.LF_profile_placeholder_new_password') }}"
                               aria-describedby="admin_new_password_help" aria-required="true" required>
                        <button type="button" x-on:click="visible = ! visible"
                                x-bind:aria-label="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))"
                                x-bind:title="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))">
                            <svg x-show="! visible" class="admin-password-eye" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path>
                                <circle cx="12" cy="12" r="2.75"></circle>
                            </svg>
                            <svg x-show="visible" x-cloak class="admin-password-eye" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="m3 3 18 18M10.6 6.15A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a15 15 0 0 1-2.1 2.75M6.2 6.2C3.8 7.85 2.5 12 2.5 12s3.5 6 9.5 6a9.8 9.8 0 0 0 3.15-.52M9.9 9.9a3 3 0 0 0 4.2 4.2"></path>
                            </svg>
                        </button>
                    </div>
                    <p id="admin_new_password_help" class="lf-form-help">{{ __('lf.LF_profile_help_new_password') }}</p>
                    @foreach ($errors->updatePassword->get('password') as $error)
                        <p class="lf-form-error">{{ $error }}</p>
                    @endforeach
                </div>

                <div class="lf-form-group admin-password-field" x-data="{ visible: false }">
                    <label for="admin_password_confirmation" class="lf-form-label">
                        {{ __('lf.LF_common_label_confirm_new_password') }}
                        <span class="lf-required-indicator" aria-hidden="true">*</span>
                    </label>
                    <div class="admin-password-control">
                        <input id="admin_password_confirmation" type="password" x-bind:type="visible ? 'text' : 'password'"
                               name="password_confirmation" class="lf-form-control"
                               autocomplete="new-password"
                               placeholder="{{ __('lf.LF_profile_placeholder_confirm_new_password') }}"
                               aria-required="true" required>
                        <button type="button" x-on:click="visible = ! visible"
                                x-bind:aria-label="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))"
                                x-bind:title="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))">
                            <svg x-show="! visible" class="admin-password-eye" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path>
                                <circle cx="12" cy="12" r="2.75"></circle>
                            </svg>
                            <svg x-show="visible" x-cloak class="admin-password-eye" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="m3 3 18 18M10.6 6.15A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6-3.5 6-9.5 6a9.8 9.8 0 0 1-3.15-.52M6.2 6.2C3.8 7.85 2.5 12 2.5 12s3.5 6 9.5 6a9.8 9.8 0 0 0 3.15-.52M9.9 9.9a3 3 0 0 0 4.2 4.2"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="lf-modal-actions admin-password-modal-actions">
                    <button type="button" class="btn btn-secondary"
                            x-on:click="$dispatch('close-modal', 'change-password')">
                        {{ __('lf.LF_common_button_cancel') }}
                    </button>
                    <button type="submit" class="btn btn-primary">{{ __('lf.LF_common_button_common_change_password') }}</button>
                </div>
            </form>
        </div>
    </x-modal>
@endsection
