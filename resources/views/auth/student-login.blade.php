@extends('layouts.tenant')

@section('title', __('lf.LF_auth_login_title').' | '.__('lf.LF_common_brand_student_name'))

@section('tenant_page_class', 'student-page--login')

@section('content')
<div class="student-login-shell">
    <section class="student-card student-profile-card" aria-labelledby="student-login-title">
        <div class="student-page-heading">
            <span class="student-login-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.2"/><path d="M4.7 20v-1.4a7.3 7.3 0 0 1 14.6 0V20"/></svg>
            </span>
            <h1 id="student-login-title">{{ __('lf.LF_auth_login_title') }}</h1>
            <p>{{ __('lf.LF_auth_login_student_subtitle') }}</p>
        </div>

        <x-auth-session-status class="student-alert is-success" :status="session('status')" />

        @if ($roleNotice = session('login_role_notice'))
            <div class="student-login-notice" role="alert" aria-labelledby="student-login-role-notice-title">
                <p id="student-login-role-notice-title" class="student-login-notice-title">{{ __('lf.LF_auth_login_backoffice_account_wrong_page_title') }}</p>
                <p class="student-login-notice-text">{{ __('lf.LF_auth_login_backoffice_account_wrong_page') }}</p>
                <a class="student-button student-login-notice-action" href="{{ $roleNotice['url'] }}" autofocus>
                    {{ __('lf.LF_auth_login_go_backoffice_login') }}
                    <span aria-hidden="true">&rarr;</span>
                </a>
            </div>
        @endif

        <form method="POST" action="{{ route('login', ['experience' => 'student']) }}">
            @csrf
            <div class="student-form-grid">
            <div class="student-form-group student-form-span">
                <label class="student-form-label" for="email">{{ __('lf.LF_common_label_email') }}</label>
                <input class="student-form-control" id="email" type="email" name="email" value="{{ old('email') }}"
                       placeholder="{{ __('lf.LF_auth_forgot_email_placeholder') }}"
                       required @unless (session('login_role_notice')) autofocus @endunless autocomplete="username"
                       aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                       @if ($errors->has('email')) aria-describedby="student-login-email-error" @endif>
                <x-input-error id="student-login-email-error" :messages="$errors->get('email')" class="student-alert is-danger" />
            </div>
            <div class="student-form-group student-form-span">
                <label class="student-form-label" for="password">{{ __('lf.LF_auth_login_password') }}</label>
                <input class="student-form-control" id="password" type="password" name="password"
                       placeholder="{{ __('lf.LF_auth_login_password_placeholder') }}"
                       required autocomplete="current-password"
                       aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                       @if ($errors->has('password')) aria-describedby="student-login-password-error" @endif>
                <x-input-error id="student-login-password-error" :messages="$errors->get('password')" class="student-alert is-danger" />
            </div>
            </div>
            <div class="student-login-options">
                <label class="student-login-remember" for="remember_me">
                    <input id="remember_me" type="checkbox" name="remember">
                    <span>{{ __('lf.LF_auth_login_remember') }}</span>
                </label>
                <a class="student-login-recovery" href="{{ route('password.request', ['experience' => 'student']) }}">{{ __('lf.LF_auth_login_forgot_password') }}</a>
            </div>
            <button class="student-button student-login-submit" type="submit">{{ __('lf.LF_auth_login_title') }}</button>
        </form>
        <p class="student-login-foot">
            {{ __('lf.LF_auth_login_no_account') }}
            <a href="{{ route('tenant.contact') }}">{{ __('lf.LF_auth_login_contact_center') }}</a>
        </p>
    </section>
</div>
@endsection
