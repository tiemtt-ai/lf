@extends('layouts.tenant')

@section('title', __('lf.LF_auth_forgot_title').' | '.__('lf.LF_common_brand_name'))
@section('tenant_page_class', 'student-page--login')
@section('footer_description', __('lf.LF_common_footer_common_copyright'))

@section('content')
    <div class="student-login-shell">
        <section class="student-card student-profile-card" aria-labelledby="student-forgot-title">
            <header class="student-page-heading">
                <h1 id="student-forgot-title">{{ __('lf.LF_auth_forgot_title') }}</h1>
                <p>{{ __('lf.LF_auth_forgot_description') }}</p>
            </header>

            <x-auth-session-status class="student-alert is-success" :status="session('status')" />

            <form method="POST" action="{{ route('password.email', ['experience' => 'student']) }}">
                @csrf
                <div class="student-form-group">
                    <label class="student-form-label" for="email">{{ __('lf.LF_common_label_email') }}</label>
                    <input id="email" class="student-form-control" type="email" name="email"
                           value="{{ old('email') }}" placeholder="{{ __('lf.LF_auth_forgot_email_placeholder') }}"
                           required autofocus autocomplete="email"
                           aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                           @if ($errors->has('email')) aria-describedby="student-forgot-email-error" @endif>
                    <x-input-error id="student-forgot-email-error" :messages="$errors->get('email')" class="student-alert is-danger" />
                </div>
                <div class="student-form-actions">
                    <button class="student-button student-login-submit" type="submit">{{ __('lf.LF_auth_forgot_send_link') }}</button>
                </div>
            </form>

            <div class="student-login-options">
                <a class="student-login-recovery" href="{{ route('login', ['experience' => 'student']) }}">{{ __('lf.LF_auth_forgot_back_login') }}</a>
            </div>
        </section>
    </div>
@endsection
