@extends('layouts.app')

@section('vite')
    @vite(['resources/css/tenant-site.css', 'resources/js/app.js'])
    @stack('tenant_theme')
@endsection

@section('body_class', 'lf-student-page')

@section('app_shell')
    @php
        $tenant = \App\Support\TenantContext::customer();
        $currentUser = auth()->user();
        $studentMode = $currentUser?->role === 'student';
        $menu = $studentMode
            ? [
                ['label' => __('lf.LF_navigation_menu_public_home'), 'route' => 'public.home', 'active' => 'public.home'],
                ['label' => __('lf.LF_navigation_menu_public_courses'), 'route' => 'tenant.courses.index', 'active' => 'tenant.courses.*'],
                ['label' => __('lf.LF_navigation_menu_public_assessments'), 'route' => 'tenant.assessments', 'active' => 'tenant.assessments'],
                ['label' => __('lf.LF_navigation_menu_public_services'), 'route' => 'public.services', 'active' => 'public.services'],
                ['label' => __('lf.LF_navigation_menu_student_my_courses'), 'route' => 'student.courses.index', 'active' => 'student.courses.*'],
                ['label' => __('lf.LF_navigation_menu_student_learning_history'), 'route' => 'student.learning-history', 'active' => 'student.learning-history'],
                ['label' => __('lf.LF_navigation_menu_student_ai_tutor'), 'route' => 'student.ai-tutor', 'active' => 'student.ai-tutor'],
                ['label' => __('lf.LF_navigation_menu_student_profile'), 'route' => 'student.profile.edit', 'active' => 'student.profile.*'],
            ]
            : [
                ['label' => __('lf.LF_navigation_menu_public_home'), 'route' => 'public.home', 'active' => 'public.home'],
                ['label' => __('lf.LF_navigation_menu_public_courses'), 'route' => 'tenant.courses.index', 'active' => 'tenant.courses.*'],
                ['label' => __('lf.LF_navigation_menu_public_assessments'), 'route' => 'tenant.assessments', 'active' => 'tenant.assessments'],
                ['label' => __('lf.LF_navigation_menu_public_services'), 'route' => 'public.services', 'active' => 'public.services'],
                ['label' => __('lf.LF_navigation_menu_public_teachers'), 'route' => 'tenant.teachers', 'active' => 'tenant.teachers'],
                ['label' => __('lf.LF_navigation_menu_public_about'), 'route' => 'public.about', 'active' => 'public.about'],
                ['label' => __('lf.LF_navigation_menu_public_contact'), 'route' => 'tenant.contact', 'active' => 'tenant.contact'],
            ];
    @endphp

    <div class="student-page" x-data="{ mobileMenuOpen: false }">
        <header class="student-header">
            <div class="student-container">
                <div class="student-header-inner">
                    <a class="student-brand" href="{{ url('/') }}" aria-label="{{ $tenant?->name ?? 'LF' }}">
                        <span class="student-brand-mark">LF</span>
                        <span class="student-brand-copy">
                            <span class="student-brand-name">{{ $tenant?->name ?? 'LF' }}</span>
                            <span class="student-brand-tenant">
                                {{ $studentMode ? __('lf.LF_student_title_student_personalized_experience') : __('lf.LF_common_footer_common_platform') }}
                            </span>
                        </span>
                    </a>

                    <nav class="student-nav" aria-label="{{ __('lf.LF_navigation_label_tenant_navigation') }}">
                        @foreach ($menu as $item)
                            <a class="student-nav-link {{ request()->routeIs($item['active']) ? 'is-active' : '' }}"
                               href="{{ route($item['route']) }}">
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </nav>

                    <div class="student-header-actions">
                        @include('partials.language-switcher', ['attributes' => new \Illuminate\View\ComponentAttributeBag([
                            'class' => 'student-language-switcher',
                        ])])

                        @if ($studentMode)
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="student-login-button" type="submit">{{ __('lf.LF_navigation_menu_student_logout') }}</button>
                            </form>
                        @else
                            <a class="student-login-button" href="{{ route('login') }}">{{ __('lf.LF_navigation_menu_guest_login') }}</a>
                        @endif

                        <button class="student-mobile-trigger" type="button"
                                x-on:click="mobileMenuOpen = ! mobileMenuOpen"
                                x-bind:aria-expanded="mobileMenuOpen" aria-label="{{ __('lf.LF_common_navigation_common_open_menu') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" aria-hidden="true">
                                <path d="M4 7h16M4 12h16M4 17h16"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <nav class="student-mobile-menu" x-show="mobileMenuOpen" x-cloak
                     aria-label="{{ __('lf.LF_navigation_label_tenant_mobile_navigation') }}">
                    <div class="student-mobile-links">
                        @foreach ($menu as $item)
                            <a class="student-nav-link {{ request()->routeIs($item['active']) ? 'is-active' : '' }}"
                               href="{{ route($item['route']) }}">
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                </nav>
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
                    {{ $tenant?->name ?? 'LF' }}
                </div>
                <p>{{ __('lf.LF_home_public_roles_description') }}</p>
                <div class="student-footer-links">
                    <a href="{{ route('public.about') }}">{{ __('lf.LF_navigation_menu_public_about') }}</a>
                    <a href="{{ route('tenant.contact') }}">{{ __('lf.LF_navigation_menu_public_contact') }}</a>
                    <a href="{{ route('public.services') }}">{{ __('lf.LF_navigation_menu_public_services') }}</a>
                </div>
            </div>
        </footer>
    </div>
@endsection
