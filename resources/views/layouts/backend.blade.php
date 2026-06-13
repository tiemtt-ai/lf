@extends('layouts.app')

@section('body_class', 'lf-admin-page')

@section('app_shell')
    @php
        $portalUser = auth()->user();
        $isTeacher = $portalUser?->role === 'teacher';
        $dashboardRoute = $isTeacher ? 'teacher.dashboard' : 'admin.dashboard';
        $navigationLabel = $isTeacher ? 'Teacher navigation' : 'Customer admin navigation';
        $primaryMenu = $isTeacher
            ? ['My Courses', 'Assessments', 'Live Classes', 'Students', 'Reports', 'AI Assistant']
            : ['Khoá học', 'Đề thi', 'Giáo trình', 'Giảng viên', 'Cộng đồng', 'Level Test', 'Visang Video'];
        $portalMenu = $isTeacher
            ? [
                ['label' => 'Dashboard', 'route' => 'teacher.dashboard', 'active' => 'teacher.dashboard', 'visible' => true],
                ['label' => 'My Courses', 'route' => null, 'active' => null, 'visible' => true],
                ['label' => 'Assessments', 'route' => null, 'active' => null, 'visible' => true],
                ['label' => 'Live Classes', 'route' => null, 'active' => null, 'visible' => true],
                ['label' => 'Students', 'route' => null, 'active' => null, 'visible' => true],
                ['label' => 'Reports', 'route' => null, 'active' => null, 'visible' => true],
                ['label' => 'AI Assistant', 'route' => null, 'active' => null, 'visible' => true],
                ['label' => 'Profile', 'route' => 'teacher.profile.edit', 'active' => 'teacher.profile.*', 'visible' => true],
            ]
            : [
                ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'active' => 'admin.dashboard', 'visible' => true],
                ['label' => 'Users', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'visible' => true],
                ['label' => 'Courses', 'route' => null, 'active' => null, 'visible' => true],
                ['label' => 'Assessments', 'route' => null, 'active' => null, 'visible' => true],
                ['label' => 'Live Classes', 'route' => null, 'active' => null, 'visible' => false],
                ['label' => 'Media', 'route' => null, 'active' => null, 'visible' => false],
                ['label' => 'Reports', 'route' => null, 'active' => null, 'visible' => false],
                ['label' => 'AI', 'route' => null, 'active' => null, 'visible' => false],
                ['label' => 'Settings', 'route' => 'admin.profile.edit', 'active' => 'admin.profile.*', 'visible' => true],
            ];
    @endphp

    <header class="layout-header layout-header-top">
        <div class="admin-container">
            <div class="admin-partner-logos" aria-label="Partner brands">
                @foreach (range(1, 4) as $partner)
                    <img src="{{ asset("assets/admin/partner-{$partner}.png") }}" alt="">
                @endforeach
            </div>

            <div class="admin-account-actions">
                <div class="admin-account-menu" x-data="{ open: false }" x-on:click.outside="open = false">
                    <button class="admin-account-trigger" type="button" x-on:click="open = ! open" aria-label="Account menu">
                        <img src="{{ asset('assets/admin/account.svg') }}" alt="">
                        {{ $portalUser->name ?? 'user' }}
                        <span class="admin-chevron" aria-hidden="true"></span>
                    </button>

                    <div class="admin-account-dropdown" x-show="open" x-cloak>
                        <p>{{ $portalUser->email ?? 'user@example.com' }}</p>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit">Logout</button>
                        </form>
                    </div>
                </div>

                <div class="admin-language">
                    <img src="{{ asset('assets/admin/language.png') }}" alt="">
                    VI
                    <span class="admin-chevron" aria-hidden="true"></span>
                </div>
            </div>
        </div>
    </header>

    <nav class="layout-header layout-header-nav" aria-label="Primary navigation">
        <div class="admin-container">
            <a href="{{ route($dashboardRoute) }}">
                <img class="admin-brand-logo" src="{{ asset('assets/admin/brand-logo.png') }}" alt="LearnForge">
            </a>

            <div class="admin-primary-menu" aria-hidden="true">
                @foreach ($primaryMenu as $item)
                    <span>{{ $item }}</span>
                @endforeach
            </div>

            <div class="admin-nav-actions">
                <img src="{{ asset('assets/admin/search.png') }}" alt="Search">
            </div>
        </div>
    </nav>

    <main class="layout-content">
        <div class="admin-container admin-content-wrap">
            <aside class="layout-sidebar">
                <nav @class([
                    'admin-sidebar-menu',
                    'is-teacher' => $isTeacher,
                ]) aria-label="{{ $navigationLabel }}">
                    @foreach ($portalMenu as $item)
                        @continue(! $item['visible'])

                        @php
                            $isActive = $item['active'] && request()->routeIs($item['active']);
                            $classes = 'admin-sidebar-link'
                                .($isActive ? ' is-active' : '')
                                .($item['route'] ? '' : ' is-disabled');
                        @endphp

                        @if ($item['route'])
                            <a class="{{ $classes }}" href="{{ route($item['route']) }}">
                                {{ $item['label'] }}
                            </a>
                        @else
                            <span class="{{ $classes }}" aria-disabled="true">
                                {{ $item['label'] }}
                            </span>
                        @endif
                    @endforeach
                </nav>
            </aside>

            <section class="admin-page-content">
                <h1 class="admin-page-title">@yield('page_title', 'Dashboard')</h1>
                <hr class="admin-divider">
                @yield('content')
            </section>
        </div>
    </main>

    <footer class="layout-footer">
        <div class="admin-footer-wrap">
            <div class="admin-footer-top">
                <div class="admin-footer-column admin-footer-company">
                    <div class="admin-footer-logo">
                        <img src="{{ asset('assets/admin/brand-logo.png') }}" alt="LearnForge">
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

    <div class="admin-floating" aria-hidden="true">
        <div class="admin-floating-button is-chatbot">
            <img src="{{ asset('assets/admin/chatbot.png') }}" alt="">
        </div>
        <div class="admin-floating-button is-ai">
            <img src="{{ asset('assets/admin/ai-assistant.png') }}" alt="">
        </div>
        <div class="admin-floating-button is-message">
            <img src="{{ asset('assets/admin/download.svg') }}" alt="Download">
        </div>
    </div>
@endsection
