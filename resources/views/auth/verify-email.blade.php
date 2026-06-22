<x-auth-layout>
    <section class="auth-card auth-notice-card" aria-labelledby="verify-email-title">
        <div class="auth-page-heading">
            <h1 id="verify-email-title">{{ __('lf.LF_auth_verify_resend') }}</h1>
        </div>

        <p class="auth-description">
            {{ __('lf.LF_auth_verify_message') }}
        </p>

        @if (session('status') == 'verification-link-sent')
            <div class="auth-session-status auth-notice-status">
                {{ __('lf.LF_auth_verify_sent') }}
            </div>
        @endif

        <div class="auth-actions">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf

                <button class="auth-button" type="submit">
                    {{ __('lf.LF_auth_verify_resend') }}
                </button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf

                <button type="submit" class="auth-link-button">
                    {{ __('lf.LF_navigation_menu_student_logout') }}
                </button>
            </form>
        </div>
    </section>
</x-auth-layout>
