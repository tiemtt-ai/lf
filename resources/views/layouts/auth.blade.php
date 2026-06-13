<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng nhập - Master Korean</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page">
<header class="auth-header auth-header-top">
    <div class="auth-container">
        <div class="auth-partner-logos" aria-label="Partner brands">
            @foreach (range(1, 4) as $partner)
                <img src="{{ asset("assets/admin/partner-{$partner}.png") }}" alt="">
            @endforeach
        </div>

        <div class="auth-top-actions">
            <a class="auth-top-action" href="{{ auth()->check() ? route('dashboard') : route('login') }}">
                <img src="{{ asset('assets/admin/account.svg') }}" alt="">
                Tài khoản
            </a>
            <div class="auth-top-action">
                <img src="{{ asset('assets/admin/language.png') }}" alt="">
                VI
                <span class="auth-chevron" aria-hidden="true"></span>
            </div>
        </div>
    </div>
</header>

<nav class="auth-header auth-header-nav" aria-label="Public navigation">
    <div class="auth-container">
        <img class="auth-brand-logo" src="{{ asset('assets/admin/brand-logo.png') }}" alt="Master Korean">

        <div class="auth-primary-menu" aria-hidden="true">
            <span>Khoá học <i class="auth-chevron"></i></span>
            <span>Đề thi</span>
            <span>Giáo trình</span>
            <span>Giảng viên</span>
            <span>Cộng đồng</span>
            <span>Level Test</span>
            <span>Visang Video <i class="auth-chevron"></i></span>
        </div>

        <img class="auth-search" src="{{ asset('assets/admin/search.png') }}" alt="Search">
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
                <h4>Công ty TNHH Giáo dục Visang Việt Nam</h4>
                <p>Địa chỉ: Tầng 2, FLC Landmark Tower, đường Lê Đức Thọ, phường Từ Liêm, thành phố Hà Nội, Việt Nam</p>
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
                <h3>Về công ty</h3>
                <a href="#">Giới thiệu</a>
                <a href="#">Tài liệu pháp lý</a>
                <a href="#">Khóa học</a>
                <a href="#">Giảng viên</a>
            </div>

            <div class="admin-footer-column">
                <h3>Hỗ trợ</h3>
                <a href="#">Hỏi đáp</a>
                <a href="#">Quy chế hoạt động</a>
                <a href="#">Chính sách bảo mật</a>
            </div>
        </div>

        <div class="admin-footer-bottom">
            <div class="admin-footer-legal">
                <p>Copyright © VISANG Education Group Vietnam Company</p>
                <p>MST: 0109066143 do Sở KH & ĐT thành phố Hà Nội cấp ngày 14/01/2020 Người đại diện: Mr. Lee Young Geun</p>
            </div>

            <div class="admin-footer-certificates">
                <img class="admin-footer-bct" src="{{ asset('assets/admin/footer/bct.png') }}"
                     alt="Đã thông báo Bộ Công Thương">
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
