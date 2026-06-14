<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('lf.LF_auth_login_title') }} - Master Korean</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page">
<header class="auth-header auth-header-top">
    <div class="auth-container">
        <div class="auth-partner-logos" aria-label="{{ __('lf.LF_common_navigation_common_partner_brands') }}">
            @foreach (range(1, 4) as $partner)
                <img src="{{ asset("assets/admin/partner-{$partner}.png") }}" alt="">
            @endforeach
        </div>

        <div class="auth-top-actions">
            <a class="auth-top-action" href="{{ auth()->check() ? route('dashboard') : route('login') }}">
                <img src="{{ asset('assets/admin/account.svg') }}" alt="">
                {{ __('lf.LF_auth_account_common_label') }}
            </a>
            @include('partials.language-switcher', ['attributes' => new \Illuminate\View\ComponentAttributeBag([
                'class' => 'auth-top-action',
            ])])
        </div>
    </div>
</header>

<nav class="auth-header auth-header-nav" aria-label="{{ __('lf.LF_common_navigation_public_label') }}">
    <div class="auth-container">
        <img class="auth-brand-logo" src="{{ asset('assets/admin/brand-logo.png') }}" alt="Master Korean">

        <div class="auth-primary-menu" aria-hidden="true">
            <span>{{ __('lf.LF_navigation_menu_public_courses') }} <i class="auth-chevron"></i></span>
            <span>{{ __('lf.LF_navigation_menu_admin_exams') }}</span>
            <span>{{ __('lf.LF_navigation_menu_admin_curriculum') }}</span>
            <span>{{ __('lf.LF_navigation_menu_public_teachers') }}</span>
            <span>{{ __('lf.LF_navigation_menu_admin_community') }}</span>
            <span>{{ __('lf.LF_navigation_menu_admin_level_test') }}</span>
            <span>{{ __('lf.LF_navigation_menu_admin_visang_video') }} <i class="auth-chevron"></i></span>
        </div>

        <img class="auth-search" src="{{ asset('assets/admin/search.png') }}" alt="{{ __('lf.LF_common_button_search') }}">
    </div>
</nav>

<main class="auth-main">
    {{ $slot }}
</main>

<footer class="layout-footer">
    <div class="admin-footer-wrap">
        <div class="admin-footer-top">
            <div class="admin-footer-column admin-footer-company">
                <div class="admin-footer-logo">
                    <img src="{{ asset('assets/admin/brand-logo.png') }}" alt="Master Korean">
                </div>
                <h4>{{ __('lf.LF_common_footer_common_company') }}</h4>
                <p>{{ __('lf.LF_common_footer_common_address') }}</p>
                <h4 class="admin-footer-contact" style="margin-top: 30px">
                    <img src="{{ asset('assets/admin/footer/phone.png') }}" alt="">
                    0243-6886-333 / 0912-801-848
                </h4>
                <h4 class="admin-footer-contact">
                    <img src="{{ asset('assets/admin/footer/mail.png') }}" alt="">
                    visang@masterkorean.vn
                </h4>
            </div>

            <div class="admin-footer-column">
                <h3>{{ __('lf.LF_common_footer_common_about_company') }}</h3>
                <a href="#">{{ __('lf.LF_common_footer_common_introduction') }}</a>
                <a href="#">{{ __('lf.LF_common_footer_common_legal_documents') }}</a>
                <a href="#">{{ __('lf.LF_navigation_menu_public_courses') }}</a>
                <a href="#">{{ __('lf.LF_navigation_menu_public_teachers') }}</a>
            </div>

            <div class="admin-footer-column">
                <h3>{{ __('lf.LF_common_footer_common_support') }}</h3>
                <a href="#">{{ __('lf.LF_common_footer_common_faq') }}</a>
                <a href="#">{{ __('lf.LF_common_footer_common_operating_rules') }}</a>
                <a href="#">{{ __('lf.LF_common_footer_common_privacy_policy') }}</a>
            </div>
        </div>

        <div class="admin-footer-bottom">
            <div class="admin-footer-legal">
                <p>{{ __('lf.LF_common_footer_common_copyright') }}</p>
                <p>{{ __('lf.LF_common_footer_common_legal_notice') }}</p>
            </div>

            <div class="admin-footer-certificates">
                <img class="admin-footer-bct" src="{{ asset('assets/admin/footer/bct.png') }}"
                     alt="{{ __('lf.LF_common_image_common_trade_notice') }}">
                <img class="admin-footer-aws" src="{{ asset('assets/admin/footer/aws.png') }}"
                     alt="AWS Qualified Software">
            </div>

            <div class="admin-footer-apps">
                <img src="{{ asset('assets/admin/footer/mk-live.png') }}" alt="MK Live">
                <img src="{{ asset('assets/admin/footer/mk-jobs.png') }}" alt="MK Jobs">
                <img src="{{ asset('assets/admin/footer/youtube.png') }}" alt="YouTube">
                <img class="admin-footer-store" src="{{ asset('assets/admin/footer/google-play.png') }}"
                     alt="Google Play">
                <img src="{{ asset('assets/admin/footer/exam.png') }}" alt="Exam">
                <img src="{{ asset('assets/admin/footer/mk-blog.png') }}" alt="MK Blog">
                <img src="{{ asset('assets/admin/footer/facebook.png') }}" alt="Facebook">
                <img class="admin-footer-store" src="{{ asset('assets/admin/footer/app-store.png') }}"
                     alt="App Store">
            </div>
        </div>
    </div>
</footer>

<div class="auth-floating" aria-hidden="true">
    <div class="auth-floating-button is-chatbot">
        <img src="{{ asset('assets/admin/chatbot.png') }}" alt="">
    </div>
    <div class="auth-floating-button is-ai">
        <img src="{{ asset('assets/admin/ai-assistant.png') }}" alt="">
    </div>
    <div class="auth-floating-button is-message">
        <img src="{{ asset('assets/admin/download.svg') }}" alt="">
    </div>
</div>
</body>
</html>
