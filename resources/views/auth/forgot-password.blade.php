<x-auth-layout>
    <section class="auth-login-card auth-forgot-card" aria-labelledby="forgot-password-title">
        <div class="auth-page-heading">
            <h1 id="forgot-password-title">{{ __('lf.LF_auth_forgot_title') }}</h1>
        </div>

        <p class="auth-help-text">
            {{ __('lf.LF_auth_forgot_description') }}
        </p>

        <x-auth-session-status class="auth-session-status" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <div class="auth-field">
                <label class="auth-label" for="email">{{ __('lf.LF_common_label_email') }}</label>
                <input id="email"
                       class="auth-input"
                       type="email"
                       name="email"
                       value="{{ old('email') }}"
                       placeholder="{{ __('lf.LF_auth_forgot_email_placeholder') }}"
                       required
                       autofocus
                       autocomplete="email">
                <x-input-error :messages="$errors->get('email')" class="auth-error" />
            </div>

            <button class="auth-submit" type="submit">{{ __('lf.LF_auth_forgot_send_link') }}</button>
        </form>

        <div class="auth-back-row">
            <a class="auth-forgot-link" href="{{ route('login') }}">{{ __('lf.LF_auth_forgot_back_login') }}</a>
        </div>
    </section>
</x-auth-layout>
