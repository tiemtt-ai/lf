@extends('layouts.app')

@section('body_class', 'lf-student-page')

@section('app_shell')
    @php
        $tenant = \App\Support\TenantContext::customer();
        $currentUser = auth()->user();
        $studentMode = $currentUser?->role === 'student';
        $menu = $studentMode
            ? [
                ['label' => 'Home', 'route' => 'public.home', 'active' => 'public.home'],
                ['label' => 'Courses', 'route' => 'tenant.courses.index', 'active' => 'tenant.courses.*'],
                ['label' => 'Assessments', 'route' => 'tenant.assessments', 'active' => 'tenant.assessments'],
                ['label' => 'Services', 'route' => 'public.services', 'active' => 'public.services'],
                ['label' => 'My Courses', 'route' => 'student.courses.index', 'active' => 'student.courses.*'],
                ['label' => 'Learning History', 'route' => 'student.learning-history', 'active' => 'student.learning-history'],
                ['label' => 'AI Tutor', 'route' => 'student.ai-tutor', 'active' => 'student.ai-tutor'],
                ['label' => 'Profile', 'route' => 'student.profile.edit', 'active' => 'student.profile.*'],
            ]
            : [
                ['label' => 'Home', 'route' => 'public.home', 'active' => 'public.home'],
                ['label' => 'Courses', 'route' => 'tenant.courses.index', 'active' => 'tenant.courses.*'],
                ['label' => 'Assessments', 'route' => 'tenant.assessments', 'active' => 'tenant.assessments'],
                ['label' => 'Services', 'route' => 'public.services', 'active' => 'public.services'],
                ['label' => 'Teachers', 'route' => 'tenant.teachers', 'active' => 'tenant.teachers'],
                ['label' => 'About', 'route' => 'public.about', 'active' => 'public.about'],
                ['label' => 'Contact', 'route' => 'tenant.contact', 'active' => 'tenant.contact'],
            ];
    @endphp

    <div class="student-page" x-data="{ mobileMenuOpen: false }">
        <header class="student-header">
            <div class="student-container">
                <div class="student-header-inner">
                    <a class="student-brand" href="{{ url('/') }}" aria-label="{{ $tenant?->name ?? 'LearnForge' }}">
                        <span class="student-brand-mark">LF</span>
                        <span class="student-brand-copy">
                            <span class="student-brand-name">{{ $tenant?->name ?? 'LearnForge' }}</span>
                            <span class="student-brand-tenant">
                                {{ $studentMode ? 'Personalized learning experience' : 'Courses and learning services' }}
                            </span>
                        </span>
                    </a>

                    <nav class="student-nav" aria-label="Tenant website navigation">
                        @foreach ($menu as $item)
                            <a class="student-nav-link {{ request()->routeIs($item['active']) ? 'is-active' : '' }}"
                               href="{{ route($item['route']) }}">
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </nav>

                    <div class="student-header-actions">
                        @if ($studentMode)
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="student-login-button" type="submit">Logout</button>
                            </form>
                        @else
                            <a class="student-login-button" href="{{ route('login') }}">Login</a>
                        @endif

                        <button class="student-mobile-trigger" type="button"
                                x-on:click="mobileMenuOpen = ! mobileMenuOpen"
                                x-bind:aria-expanded="mobileMenuOpen" aria-label="Open menu">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" aria-hidden="true">
                                <path d="M4 7h16M4 12h16M4 17h16"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <nav class="student-mobile-menu" x-show="mobileMenuOpen" x-cloak
                     aria-label="Tenant website mobile navigation">
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
                    {{ $tenant?->name ?? 'LearnForge' }}
                </div>
                <p>Courses, services and personalized learning in one tenant website.</p>
                <div class="student-footer-links">
                    <a href="{{ route('public.about') }}">About</a>
                    <a href="{{ route('tenant.contact') }}">Contact</a>
                    <a href="{{ route('public.services') }}">Services</a>
                </div>
            </div>
        </footer>
    </div>
@endsection
