@extends('layouts.tenant')

@section('title', __('lf.LF_auth_login_title').' | '.__('lf.LF_common_brand_name'))

@section('tenant_page_class', 'student-page--login')
@section('footer_description', __('lf.LF_common_footer_common_copyright'))

@section('content')
<div class="student-login-shell">
    <section class="student-card student-profile-card" aria-labelledby="student-login-title">
        <div class="student-page-heading">
            <h1 id="student-login-title">{{ __('lf.LF_auth_login_title') }}</h1>
        </div>

        <x-auth-session-status class="student-alert is-success" :status="session('status')" />

        <form method="POST" action="{{ route('login', ['experience' => 'student']) }}">
            @csrf
            <div class="student-form-grid">
            <div class="student-form-group student-form-span">
                <label class="student-form-label" for="email">{{ __('lf.LF_common_label_email') }}</label>
                <input class="student-form-control" id="email" type="email" name="email" value="{{ old('email') }}"
                       placeholder="{{ __('lf.LF_auth_forgot_email_placeholder') }}"
                       required autofocus autocomplete="username"
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
    </section>
</div>
@endsection
