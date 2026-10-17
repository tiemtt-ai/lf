@extends('layouts.app')

@section('title', __('lf.LF_auth_login_title').' - Master Korean')
@section('body_class', 'lf-login-page')
@section('vite')
    @vite(['resources/css/auth.css', 'resources/js/app.js'])
@endsection

@section('app_shell')
    <header class="lf-login-header">
        <div class="lf-login-header-inner">
            <div class="lf-login-heading">
                <span>ADMIN</span>
                <p>{{ __('lf.LF_auth_login_system_management') }}</p>
            </div>
            <div class="lf-login-brand">
                <img class="lf-login-logo" src="{{ asset('assets/admin/brand-logo.svg') }}" alt="Master Korean" width="150" height="54">
            </div>
            <div class="lf-login-languages" x-data="{ open: false }" x-on:click.outside="open = false"
                 x-on:keydown.escape.stop="open = false; $refs.languageTrigger.focus()">
                <button class="lf-login-language-trigger" type="button" x-ref="languageTrigger"
                        aria-controls="login-language-options" aria-expanded="false" x-bind:aria-expanded="open.toString()"
                        x-on:click="open = ! open" aria-label="{{ __('lf.LF_common_language_switch') }}">
                    <img class="lf-login-language-flag" src="{{ asset('assets/admin/flag-'.app()->getLocale().'.svg') }}" alt="" width="24" height="16">
                    <span>{{ strtoupper(app()->getLocale()) }}</span>
                    <span class="lf-login-language-caret" aria-hidden="true"></span>
                </button>
                <div id="login-language-options" class="lf-login-language-options" x-show="open" x-cloak>
                    @foreach (['vi', 'en'] as $locale)
                        @continue(app()->getLocale() === $locale)
                        <form method="POST" action="{{ route('language.update', ['locale' => $locale]) }}">
                            @csrf
                            <button type="submit" class="lf-login-language-option" lang="{{ $locale }}" aria-label="{{ __('lf.LF_common_language_'.$locale) }}">
                                <img class="lf-login-language-flag" src="{{ asset('assets/admin/flag-'.$locale.'.svg') }}" alt="" width="24" height="16">
                                <span>{{ strtoupper($locale) }}</span>
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        </div>
    </header>
    <main class="lf-login-main">
        <section class="lf-login-panel" aria-labelledby="login-title">
            <h1 id="login-title">{{ __('lf.LF_auth_login_system_title') }}</h1>
            <p class="lf-login-description">{{ __('lf.LF_auth_login_system_description') }}</p>
            <x-auth-session-status class="lf-login-status" :status="session('status')" />
            <form method="POST" action="{{ route('login') }}">
                @csrf

                <div class="lf-login-field">
                    <label class="lf-login-label" for="email">{{ __('lf.LF_auth_login_username') }}</label>
                    <div class="lf-login-input-wrap">
                        <input id="email"
                               class="lf-login-input"
                               type="email"
                               name="email"
                               value="{{ old('email') }}"
                               placeholder="{{ __('lf.LF_auth_login_username_placeholder') }}"
                               required
                               autofocus
                               autocomplete="username"
                               aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                               @if ($errors->has('email')) aria-describedby="login-email-error" @endif>
                    </div>
                    <x-input-error id="login-email-error" :messages="$errors->get('email')" class="lf-login-error" />
                </div>

                <div class="lf-login-field" x-data="{ showPassword: false }">
                    <label class="lf-login-label" for="password">{{ __('lf.LF_auth_login_password') }}</label>
                    <div class="lf-login-input-wrap">
                        <input id="password"
                               class="lf-login-input lf-login-password"
                               type="password"
                               x-bind:type="showPassword ? 'text' : 'password'"
                               name="password"
                               placeholder="{{ __('lf.LF_auth_login_password_placeholder') }}"
                               required
                               autocomplete="current-password"
                               aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                               @if ($errors->has('password')) aria-describedby="login-password-error" @endif>
                        <button class="lf-login-password-toggle"
                                type="button"
                                x-on:click="showPassword = ! showPassword"
                                x-bind:aria-pressed="showPassword.toString()"
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
                    <x-input-error id="login-password-error" :messages="$errors->get('password')" class="lf-login-error" />
                </div>

                <label class="lf-login-remember" for="remember_me">
                    <input id="remember_me" type="checkbox" name="remember">
                    <span>{{ __('lf.LF_auth_login_remember') }}</span>
                </label>

                <button class="lf-login-submit" type="submit">{{ __('lf.LF_auth_login_title') }}</button>

            </form>
        </section>
    </main>
    <footer class="lf-login-footer">{{ __('lf.LF_common_footer_common_copyright') }}</footer>
@endsection
