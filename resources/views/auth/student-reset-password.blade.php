@extends('layouts.tenant')

@section('title', __('lf.LF_auth_reset_title').' | '.__('lf.LF_common_brand_name'))
@section('tenant_page_class', 'student-page--login')
@section('footer_description', __('lf.LF_common_footer_common_copyright'))

@section('content')
    <div class="student-login-shell">
        <section class="student-card student-profile-card" aria-labelledby="student-reset-title" x-data="{ visible: false }">
            <header class="student-page-heading">
                <h1 id="student-reset-title">{{ __('lf.LF_auth_reset_title') }}</h1>
                <p id="student-reset-help">{{ __('lf.LF_profile_help_new_password') }}</p>
            </header>
            <form method="POST" action="{{ route('password.store', ['experience' => 'student']) }}">
                @csrf
                <input type="hidden" name="token" value="{{ $request->route('token') }}">
                <div class="student-form-grid">
                    <div class="student-form-group student-form-span">
                        <label class="student-form-label" for="email">{{ __('lf.LF_common_label_email') }}</label>
                        <input id="email" class="student-form-control" type="email" name="email"
                               value="{{ old('email', $request->query('email')) }}"
                               placeholder="{{ __('lf.LF_auth_forgot_email_placeholder') }}" required autofocus autocomplete="username"
                               aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                               @if ($errors->has('email')) aria-describedby="student-reset-email-error" @endif>
                        <x-input-error id="student-reset-email-error" :messages="$errors->get('email')" class="student-alert is-danger" />
                    </div>
                    @foreach (['password' => 'LF_common_label_password', 'password_confirmation' => 'LF_common_label_confirm_password'] as $field => $label)
                        <div class="student-form-group student-form-span">
                            <label class="student-form-label" for="{{ $field }}">{{ __('lf.'.$label) }}</label>
                            <input id="{{ $field }}" class="student-form-control" type="password" name="{{ $field }}"
                                   x-bind:type="visible ? 'text' : 'password'" required autocomplete="new-password"
                                   placeholder="{{ __('lf.'.($field === 'password' ? 'LF_profile_placeholder_new_password' : 'LF_profile_placeholder_confirm_new_password')) }}"
                                   aria-invalid="{{ $errors->has($field) ? 'true' : 'false' }}"
                                   aria-describedby="student-reset-help{{ $errors->has($field) ? ' student-reset-'.$field.'-error' : '' }}">
                            <x-input-error :id="'student-reset-'.$field.'-error'" :messages="$errors->get($field)" class="student-alert is-danger" />
                        </div>
                    @endforeach
                </div>
                <div class="student-login-options">
                    <label class="student-login-remember" for="show-passwords">
                        <input id="show-passwords" type="checkbox" x-model="visible">
                        <span>{{ __('lf.LF_auth_login_show_password') }}</span>
                    </label>
                </div>
                <button class="student-button student-login-submit" type="submit">{{ __('lf.LF_auth_reset_title') }}</button>
            </form>
            <div class="student-login-options">
                <a class="student-login-recovery" href="{{ route('login', ['experience' => 'student']) }}">{{ __('lf.LF_auth_forgot_back_login') }}</a>
            </div>
        </section>
    </div>
@endsection
