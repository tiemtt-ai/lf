@extends('layouts.app')

@section('body_class', 'lf-student-page')

@section('app_shell')
    @php
        $studentUser = auth()->user();
        $tenant = \App\Support\TenantContext::customer();
        $studentInitials = $studentUser
            ? collect(preg_split('/\s+/', trim($studentUser->name)))
                ->filter()
                ->take(2)
                ->map(fn ($part) => mb_substr($part, 0, 1))
                ->implode('')
            : 'LF';
        $studentMenu = [
            ['label' => 'Trang chủ học tập', 'route' => 'student.dashboard', 'active' => 'student.dashboard'],
            ['label' => 'Khoá học', 'route' => null, 'active' => null],
            ['label' => 'Lộ trình', 'route' => null, 'active' => null],
            ['label' => 'Bài kiểm tra', 'route' => null, 'active' => null],
            ['label' => 'Lịch học', 'route' => null, 'active' => null],
            ['label' => 'AI Tutor', 'route' => null, 'active' => null],
        ];
    @endphp

    <div class="student-page" x-data="{ mobileMenuOpen: false }">
        <header class="student-header">
            <div class="student-container">
                <div class="student-header-inner">
                    <a class="student-brand" href="{{ route('student.dashboard') }}" aria-label="LearnForge Student">
                        <span class="student-brand-mark">LF</span>
                        <span class="student-brand-copy">
                            <span class="student-brand-name">LearnForge</span>
                            <span class="student-brand-tenant">{{ $tenant?->name ?? 'Student Portal' }}</span>
                        </span>
                    </a>

                    <nav class="student-nav" aria-label="Điều hướng học viên">
                        @foreach ($studentMenu as $item)
                            @php
                                $isActive = $item['active'] && request()->routeIs($item['active']);
                                $classes = 'student-nav-link'.($isActive ? ' is-active' : '');
                            @endphp

                            <a class="{{ $classes }}"
                               href="{{ $item['route'] ? route($item['route']) : '#' }}"
                               @if ($item['route'] === null) aria-disabled="true" @endif>
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </nav>

                    <div class="student-header-actions">
                        <div class="student-dropdown" x-data="{ open: false }" x-on:click.outside="open = false">
                            <button class="student-icon-button" type="button" x-on:click="open = ! open"
                                    aria-label="Chọn ngôn ngữ" x-bind:aria-expanded="open">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                     aria-hidden="true">
                                    <circle cx="12" cy="12" r="9"></circle>
                                    <path d="M3 12h18M12 3c2.2 2.5 3.3 5.5 3.3 9S14.2 18.5 12 21c-2.2-2.5-3.3-5.5-3.3-9S9.8 5.5 12 3Z"></path>
                                </svg>
                            </button>

                            <div class="student-dropdown-panel" x-show="open" x-cloak>
                                <p class="student-dropdown-label">Ngôn ngữ</p>
                                <button class="student-dropdown-link is-current" type="button">Tiếng Việt</button>
                                <button class="student-dropdown-link" type="button">English</button>
                            </div>
                        </div>

                        @auth
                            <div class="student-dropdown" x-data="{ open: false }" x-on:click.outside="open = false">
                                <button class="student-account-trigger" type="button" x-on:click="open = ! open"
                                        x-bind:aria-expanded="open">
                                    <span class="student-avatar">{{ $studentInitials }}</span>
                                    <span class="student-account-name">{{ $studentUser->name }}</span>
                                    <span class="student-chevron" aria-hidden="true"></span>
                                </button>

                                <div class="student-dropdown-panel" x-show="open" x-cloak>
                                    <p class="student-dropdown-label">{{ $studentUser->email }}</p>
                                    <a class="student-dropdown-link" href="{{ route('student.dashboard') }}">
                                        Trang học tập
                                    </a>
                                    <a class="student-dropdown-link" href="{{ route('student.profile.edit') }}">
                                        Hồ sơ cá nhân
                                    </a>
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button class="student-dropdown-link" type="submit">Đăng xuất</button>
                                    </form>
                                </div>
                            </div>
                        @endauth

                        @guest
                            <a class="student-login-button" href="{{ route('login') }}">Đăng nhập</a>
                        @endguest

                        <button class="student-mobile-trigger" type="button"
                                x-on:click="mobileMenuOpen = ! mobileMenuOpen"
                                x-bind:aria-expanded="mobileMenuOpen" aria-label="Mở menu học viên">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" aria-hidden="true">
                                <path d="M4 7h16M4 12h16M4 17h16"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="student-mobile-menu" x-show="mobileMenuOpen" x-cloak>
                    <nav class="student-mobile-links" aria-label="Điều hướng học viên trên thiết bị di động">
                        @foreach ($studentMenu as $item)
                            @php
                                $isActive = $item['active'] && request()->routeIs($item['active']);
                                $classes = 'student-nav-link'.($isActive ? ' is-active' : '');
                            @endphp
                            <a class="{{ $classes }}"
                               href="{{ $item['route'] ? route($item['route']) : '#' }}"
                               @if ($item['route'] === null) aria-disabled="true" @endif>
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                        @auth
                            <a class="student-nav-link {{ request()->routeIs('student.profile.*') ? 'is-active' : '' }}"
                               href="{{ route('student.profile.edit') }}">
                                Hồ sơ cá nhân
                            </a>
                        @endauth
                    </nav>
                </div>
            </div>
        </header>

        <main class="student-main">
            <div class="student-container">
                @yield('content')
            </div>
        </main>

        <footer class="student-footer">
            <div class="student-container student-footer-inner">
                <div class="student-footer-brand">
                    <span class="student-brand-mark">LF</span>
                    LearnForge Student
                </div>
                <p>Không gian học tập thông minh, được thiết kế cho tiến bộ mỗi ngày.</p>
                <div class="student-footer-links">
                    <a href="#">Trợ giúp</a>
                    <a href="#">Quyền riêng tư</a>
                    <a href="#">Điều khoản</a>
                </div>
            </div>
        </footer>
    </div>
@endsection
