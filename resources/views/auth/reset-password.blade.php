<x-auth-layout>
    <section class="auth-login-card auth-forgot-card" aria-labelledby="reset-password-title">
        <div class="auth-page-heading">
            <h1 id="reset-password-title">{{ __('lf.LF_auth_reset_title') }}</h1>
        </div>

        <form method="POST" action="{{ route('password.store') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div class="auth-field">
                <label class="auth-label" for="email">{{ __('lf.LF_common_label_email') }}</label>
                <input id="email" class="auth-input" type="email" name="email"
                       value="{{ old('email', $request->email) }}"
                       placeholder="{{ __('lf.LF_auth_forgot_email_placeholder') }}"
                       required autofocus autocomplete="username">
                <x-input-error :messages="$errors->get('email')" class="auth-error" />
            </div>

            <div class="auth-field" x-data="{ visible: false }">
                <label class="auth-label" for="password">{{ __('lf.LF_common_label_password') }}</label>
                <div class="auth-input-wrap">
                    <input id="password" class="auth-input auth-password-input" type="password"
                           x-bind:type="visible ? 'text' : 'password'" name="password"
                           placeholder="{{ __('lf.LF_profile_placeholder_new_password') }}"
                           aria-describedby="reset-password-help" required autocomplete="new-password">
                    <button class="auth-password-toggle" type="button" x-on:click="visible = ! visible"
                            x-bind:aria-label="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))">
                        <svg x-show="! visible" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.75"></circle></svg>
                        <svg x-show="visible" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m3 3 18 18M10.6 6.15A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a15 15 0 0 1-2.1 2.75M6.2 6.2C3.8 7.85 2.5 12 2.5 12s3.5 6 9.5 6a9.8 9.8 0 0 0 3.15-.52M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg>
                    </button>
                </div>
                <p id="reset-password-help" class="auth-field-help">{{ __('lf.LF_profile_help_new_password') }}</p>
                <x-input-error :messages="$errors->get('password')" class="auth-error" />
            </div>

            <div class="auth-field" x-data="{ visible: false }">
                <label class="auth-label" for="password_confirmation">{{ __('lf.LF_common_label_confirm_password') }}</label>
                <div class="auth-input-wrap">
                    <input id="password_confirmation" class="auth-input auth-password-input" type="password"
                           x-bind:type="visible ? 'text' : 'password'" name="password_confirmation"
                           placeholder="{{ __('lf.LF_profile_placeholder_confirm_new_password') }}"
                           required autocomplete="new-password">
                    <button class="auth-password-toggle" type="button" x-on:click="visible = ! visible"
                            x-bind:aria-label="visible ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))">
                        <svg x-show="! visible" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.75"></circle></svg>
                        <svg x-show="visible" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m3 3 18 18M10.6 6.15A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a15 15 0 0 1-2.1 2.75M6.2 6.2C3.8 7.85 2.5 12 2.5 12s3.5 6 9.5 6a9.8 9.8 0 0 0 3.15-.52M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg>
                    </button>
                </div>
                <x-input-error :messages="$errors->get('password_confirmation')" class="auth-error" />
            </div>

            <button class="auth-submit" type="submit">{{ __('lf.LF_auth_reset_title') }}</button>
        </form>
    </section>
</x-auth-layout>
