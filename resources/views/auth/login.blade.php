<x-auth-layout>
    <section class="auth-login-card" aria-labelledby="login-tab">
        <div class="auth-login-tabs">
            <div id="login-tab" class="auth-login-tab is-active">{{ __('lf.LF_auth_login_title') }}</div>
            <a class="auth-login-tab" href="#">{{ __('lf.LF_auth_login_register') }}</a>
        </div>

        <x-auth-session-status class="auth-session-status" :status="session('status')" />

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="auth-field">
                <label class="auth-label" for="email">{{ __('lf.LF_auth_login_username') }}</label>
                <input id="email"
                       class="auth-input"
                       type="email"
                       name="email"
                       value="{{ old('email') }}"
                       placeholder="{{ __('lf.LF_auth_login_username') }}"
                       required
                       autofocus
                       autocomplete="username">
                <x-input-error :messages="$errors->get('email')" class="auth-error" />
            </div>

            <div class="auth-field" x-data="{ showPassword: false }">
                <label class="auth-label" for="password">{{ __('lf.LF_auth_login_password') }}</label>
                <div class="auth-input-wrap">
                    <input id="password"
                           class="auth-input auth-password-input"
                           x-bind:type="showPassword ? 'text' : 'password'"
                           name="password"
                           placeholder="{{ __('lf.LF_auth_login_password') }}"
                           required
                           autocomplete="current-password">
                    <button class="auth-password-toggle"
                            type="button"
                            x-on:click="showPassword = ! showPassword"
                            x-bind:aria-label="showPassword ? @js(__('lf.LF_auth_login_hide_password')) : @js(__('lf.LF_auth_login_show_password'))">
                        <svg x-show="! showPassword" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M3 3l18 18M10.6 10.7a2 2 0 002.7 2.7M9.9 4.2A10.8 10.8 0 0112 4c5 0 8.7 4.2 9.7 6.4a3.8 3.8 0 010 3.2 13.8 13.8 0 01-2 3M6.2 6.3A14 14 0 002.3 10.4a3.8 3.8 0 000 3.2C3.3 15.8 7 20 12 20c1.4 0 2.7-.3 3.8-.8"
                                  stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <svg x-show="showPassword" x-cloak viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M2.3 10.4C3.3 8.2 7 4 12 4s8.7 4.2 9.7 6.4a3.8 3.8 0 010 3.2C20.7 15.8 17 20 12 20s-8.7-4.2-9.7-6.4a3.8 3.8 0 010-3.2z"
                                  stroke="currentColor" stroke-width="2"/>
                            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </button>
                </div>
                <x-input-error :messages="$errors->get('password')" class="auth-error" />
            </div>

            <label class="auth-remember" for="remember_me">
                <input id="remember_me" type="checkbox" name="remember">
                <span>{{ __('lf.LF_auth_login_remember') }}</span>
            </label>

            <button class="auth-submit" type="submit">{{ __('lf.LF_auth_login_title') }}</button>

            <div class="auth-forgot-row">
                @if (Route::has('password.request'))
                    <a class="auth-forgot-link" href="{{ route('password.request') }}">{{ __('lf.LF_auth_login_forgot_password') }}</a>
                @endif
            </div>
        </form>

        <div class="auth-social-list">
            <a class="auth-social-button is-facebook" href="#">
                <img src="{{ asset('assets/auth/facebook.png') }}" alt="">
                {{ __('lf.LF_auth_login_facebook') }}
            </a>
            <a class="auth-social-button" href="#">
                <img src="{{ asset('assets/auth/google.svg') }}" alt="">
                {{ __('lf.LF_auth_login_google') }}
            </a>
        </div>
    </section>
</x-auth-layout>
