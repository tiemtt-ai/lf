@extends('layouts.app')

@section('body_class', 'lf-admin-page')

@section('app_shell')
    @php
        $adminUser = auth()->user();
        $adminMenu = [
            ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'active' => 'admin.dashboard', 'icon' => 'document', 'visible' => true],
            ['label' => 'Users', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'icon' => 'document', 'visible' => true],
            ['label' => 'Courses', 'route' => null, 'active' => null, 'icon' => 'wide', 'visible' => true],
            ['label' => 'Assessments', 'route' => null, 'active' => null, 'icon' => 'wide', 'visible' => true],
            ['label' => 'Live Classes', 'route' => null, 'active' => null, 'icon' => 'wide', 'visible' => false],
            ['label' => 'Media', 'route' => null, 'active' => null, 'icon' => 'wide', 'visible' => false],
            ['label' => 'Reports', 'route' => null, 'active' => null, 'icon' => 'wide', 'visible' => false],
            ['label' => 'AI', 'route' => null, 'active' => null, 'icon' => 'wide', 'visible' => false],
            ['label' => 'Settings', 'route' => 'admin.profile.edit', 'active' => 'admin.profile.*', 'icon' => 'wide', 'visible' => true],
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
                        {{ $adminUser->name ?? 'admin' }}
                        <span class="admin-chevron" aria-hidden="true"></span>
                    </button>

                    <div class="admin-account-dropdown" x-show="open" x-cloak>
                        <p>{{ $adminUser->email ?? 'admin@example.com' }}</p>
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
            <a href="{{ route('admin.dashboard') }}">
                <img class="admin-brand-logo" src="{{ asset('assets/admin/brand-logo.png') }}" alt="LearnForge">
            </a>

            <div class="admin-primary-menu" aria-hidden="true">
                <span>Khoá học <i class="admin-chevron"></i></span>
                <span>Đề thi</span>
                <span>Giáo trình</span>
                <span>Giảng viên</span>
                <span>Cộng đồng</span>
                <span>Level Test</span>
                <span>Visang Video <i class="admin-chevron"></i></span>
            </div>

            <div class="admin-nav-actions">
                <img src="{{ asset('assets/admin/search.png') }}" alt="Search">
            </div>
        </div>
    </nav>

    <main class="layout-content">
        <div class="admin-container admin-content-wrap">
            <aside class="layout-sidebar">
                <nav class="admin-sidebar-menu" aria-label="Customer admin navigation">
                    @foreach ($adminMenu as $item)
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
                    <h4 style="margin-top: 30px">☎ 0243-6886-333 / 0912-801-848</h4>
                    <h4>✉ visang@masterkorean.vn</h4>
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
                <div>
                    <p>Copyright © VISANG Education Group Vietnam Company</p>
                    <p>MST: 0109066143 do Sở KH & ĐT thành phố Hà Nội cấp ngày 14/01/2020 Người đại diện: Mr. Lee Young Geun</p>
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
