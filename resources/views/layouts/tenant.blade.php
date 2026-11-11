@extends('layouts.app')

@section('vite')
    @vite(['resources/css/tenant-site.css', 'resources/js/app.js'])
    @stack('tenant_theme')
@endsection

@section('body_class', 'lf-student-page')

{{-- Tenant website shell: header, menus and footer of the standard B2B site (docs/ui-demo/learner-my-learning-design).
     Menu items point to LF pages; items LF does not have yet are shown locked ("Sắp ra mắt"). --}}
@section('app_shell')
    @php
        $tenant = \App\Support\TenantContext::customer();
        $currentUser = auth()->user();
        $studentMode = $currentUser?->role === 'student';
        $img = fn (string $file) => asset('assets/student/'.$file);
        // "Khóa học" (tự học) and "Lớp học" are the two filtered views of the catalogue `/courses` (LF-Navigation § Main Menu).
        $courseMenu = request()->routeIs('tenant.courses.*')
            ? (trim($__env->yieldContent('course_menu')) ?: (request()->query('type') === 'class' ? 'classes' : 'courses'))
            : null;
        $links = [
            ['label' => __('lf.LF_navigation_menu_public_home'), 'url' => route('public.home'), 'on' => request()->routeIs('public.home')],
            ['label' => __('lf.LF_navigation_menu_public_courses'), 'url' => route('tenant.courses.index', ['type' => 'self_paced']), 'on' => $courseMenu === 'courses'],
            ['label' => __('lf.LF_navigation_menu_public_classes'), 'url' => route('tenant.courses.index', ['type' => 'class']), 'on' => $courseMenu === 'classes'],
            ['label' => __('lf.LF_navigation_menu_public_assessments'), 'url' => route('tenant.assessments'), 'on' => request()->routeIs($studentMode && ! $__env->hasSection('student_course_context') ? ['tenant.assessments', 'student.assessments.*'] : 'tenant.assessments')],
            ['label' => __('lf.LF_navigation_menu_public_services'), 'url' => route('public.services'), 'on' => request()->routeIs('public.services')],
        ];
        $more = [
            ['label' => __('lf.LF_navigation_menu_public_about'), 'route' => 'public.about', 'active' => 'public.about'],
            ['label' => __('lf.LF_navigation_menu_public_teachers'), 'route' => 'tenant.teachers', 'active' => 'tenant.teachers'],
            ['label' => __('lf.LF_navigation_menu_public_contact'), 'route' => 'tenant.contact', 'active' => 'tenant.contact'],
        ];
        $moreActive = collect($more)->contains(fn ($item) => request()->routeIs($item['active']));
        $learningTab = request()->routeIs('student.courses.index') ? (request()->query('tab') ?: 'courses') : null;
        $icons = [
            'courses' => '<path d="M4 5.5c2.7-1.2 5.3-1.2 8 0v14c-2.7-1.2-5.3-1.2-8 0zM20 5.5c-2.7-1.2-5.3-1.2-8 0v14c2.7-1.2 5.3-1.2 8 0z"/>',
            'classes' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 21h8M12 16v5m-2-14 5 3-5 3z"/>',
            'exams' => '<path d="M9 4H6a2 2 0 0 0-2 2v14h16V6a2 2 0 0 0-2-2h-3"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="m8 12 2 2 6-6M8 17h8"/>',
            'results' => '<path d="M4 3v17h17M8 16v-4m5 4V8m5 8V5"/>',
            'history' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'ai' => '<path d="M12 3v3M5.6 5.6l2.1 2.1M3 12h3m12 0h3m-3.7-6.4-2.1 2.1"/><rect x="7" y="10" width="10" height="9" rx="3"/><path d="M10 14h.01M14 14h.01"/>',
            'profile' => '<circle cx="12" cy="8" r="3.2"/><path d="M5 20v-1.4a7 7 0 0 1 14 0V20"/>',
            'logout' => '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M14 7l5 5-5 5M9 12h10"/>',
        ];
        $icon = fn (string $name) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$icons[$name].'</svg>';
        $arrow = '<svg class="sb2b-resources__arrow" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m4.5 7.25 5.5 5.5 5.5-5.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        $locales = ['vi' => 'twemoji_flag-vietnam.svg', 'en' => 'twemoji_flag-united-kingdom.svg'];
        $locale = array_key_exists(app()->getLocale(), $locales) ? app()->getLocale() : 'vi';
    @endphp

    <div class="student-page standard-b2b-site @yield('tenant_page_class')"
         style="--sb2b-heart: url('{{ $img('heart_white.png') }}'); --sb2b-heart-on: url('{{ $img('heart.png') }}');"
         data-student-site data-coming-soon-message="{{ __('lf.LF_student_site_coming_soon') }}">
        <header class="sb2b-header">
            <div class="sb2b-header__inner">
                <a class="sb2b-brand" href="{{ route('public.home') }}" aria-label="{{ __('lf.LF_navigation_menu_public_home') }}">
                    <img src="{{ $img('mk-logo2.png') }}" alt="Master Korean">
                </a>

                <nav class="sb2b-links" aria-label="{{ __('lf.LF_navigation_label_tenant_navigation') }}">
                    @foreach ($links as $item)
                        <a @class(['is-active' => $item['on']]) href="{{ $item['url'] }}"
                           @if ($item['on']) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @endforeach
                    <details class="sb2b-resources">
                        <summary @class(['is-active' => $moreActive])>{{ __('lf.LF_student_site_more') }}{!! $arrow !!}</summary>
                        <div class="sb2b-popover">
                            @foreach ($more as $item)
                                <a @class(['is-active' => request()->routeIs($item['active'])]) href="{{ route($item['route']) }}">{{ $item['label'] }}</a>
                            @endforeach
                        </div>
                    </details>
                </nav>

                <div class="sb2b-actions">
                    @if ($studentMode)
                        <details class="sb2b-account">
                            <summary aria-label="{{ __('lf.LF_student_site_account') }}">
                                <span class="sb2b-account__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.2"/><path d="M4.7 20v-1.4a7.3 7.3 0 0 1 14.6 0V20"/></svg></span>
                                <span class="sb2b-account__name">{{ $currentUser->name }}</span>
                                <span class="sb2b-chevron" aria-hidden="true"></span>
                            </summary>
                            <div class="sb2b-popover">
                                @foreach (['courses', 'classes', 'exams'] as $hubTab)
                                    <a @class(['is-active' => $learningTab === $hubTab]) href="{{ route('student.courses.index', ['tab' => $hubTab]) }}">
                                        {!! $icon($hubTab) !!}<span>{{ __('lf.LF_my_learning_tab_'.$hubTab) }}</span>
                                    </a>
                                @endforeach
                                <a @class(['is-active' => $learningTab === 'results']) href="{{ route('student.courses.index', ['tab' => 'results']) }}">
                                    {!! $icon('results') !!}<span>{{ __('lf.LF_student_site_my_results') }}</span>
                                </a>
                                <a @class(['is-active' => request()->routeIs('student.learning-history')]) href="{{ route('student.learning-history') }}">
                                    {!! $icon('history') !!}<span>{{ __('lf.LF_navigation_menu_student_learning_history') }}</span>
                                </a>
                                <a @class(['is-active' => request()->routeIs('student.ai-tutor')]) href="{{ route('student.ai-tutor') }}">
                                    {!! $icon('ai') !!}<span>{{ __('lf.LF_navigation_menu_student_ai_tutor') }}</span>
                                </a>
                                <a @class(['is-active' => request()->routeIs('student.profile.*')]) href="{{ route('student.profile.edit') }}">
                                    {!! $icon('profile') !!}<span>{{ __('lf.LF_navigation_menu_student_profile') }}</span>
                                </a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button class="sb2b-account__logout" type="submit">
                                        {!! $icon('logout') !!}<span>{{ __('lf.LF_navigation_menu_student_logout') }}</span>
                                    </button>
                                </form>
                            </div>
                        </details>
                    @endif

                    <details class="sb2b-language">
                        <summary aria-label="{{ __('lf.LF_common_language_switch') }}: {{ __('lf.LF_common_language_'.$locale) }}" title="{{ __('lf.LF_common_language_'.$locale) }}">
                            <img src="{{ $img($locales[$locale]) }}" alt="" aria-hidden="true">
                            <span class="language-switcher-code">{{ strtoupper($locale) }}</span>
                            <span class="sb2b-chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="sb2b-popover" aria-label="{{ __('lf.LF_common_language_switch') }}">
                            @foreach ($locales as $code => $flag)
                                @if ($code === $locale)
                                    <span class="sb2b-language__option is-current" aria-current="true">
                                        <img src="{{ $img($flag) }}" alt="" aria-hidden="true"><span>{{ __('lf.LF_common_language_'.$code) }}</span>
                                    </span>
                                @else
                                    <form method="POST" action="{{ route('language.update', ['locale' => $code]) }}">
                                        @csrf
                                        <button type="submit" class="sb2b-language__option" lang="{{ $code }}">
                                            <img src="{{ $img($flag) }}" alt="" aria-hidden="true"><span>{{ __('lf.LF_common_language_'.$code) }}</span>
                                        </button>
                                    </form>
                                @endif
                            @endforeach
                        </div>
                    </details>

                    {{-- The sign-in pages already are the login; no second button in the header. --}}
                    @unless ($studentMode || request()->routeIs('login', 'password.request', 'password.reset'))
                        <a class="sb2b-login" href="{{ route('login', ['experience' => 'student']) }}">{{ __('lf.LF_navigation_menu_guest_login') }}</a>
                    @endunless

                    <details class="sb2b-mobile-menu">
                        <summary aria-label="{{ __('lf.LF_common_navigation_common_open_menu') }}"><span></span><span></span><span></span></summary>
                        <nav aria-label="{{ __('lf.LF_navigation_label_tenant_mobile_navigation') }}">
                            @foreach ($links as $item)
                                <a @class(['is-active' => $item['on']]) href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                            @endforeach
                            <details class="sb2b-mobile-resources" @if ($moreActive) open @endif>
                                <summary>{{ __('lf.LF_student_site_more') }}{!! $arrow !!}</summary>
                                @foreach ($more as $item)
                                    <a @class(['is-active' => request()->routeIs($item['active'])]) href="{{ route($item['route']) }}">{{ $item['label'] }}</a>
                                @endforeach
                            </details>
                        </nav>
                    </details>
                </div>
            </div>
        </header>

        <main class="student-main">
            @yield('page_hero')
            <div class="student-container">
                @yield('content')
            </div>
        </main>

        <footer class="sb2b-footer">
            <div class="sb2b-footer__inner">
                <div class="sb2b-footer__main">
                    <div class="sb2b-footer__brand">
                        <div class="sb2b-footer__center">
                            <p class="sb2b-footer__company">{{ __('lf.LF_student_site_footer_company') }}</p>
                            <p class="sb2b-footer__address">{{ __('lf.LF_student_site_footer_address') }}</p>
                        </div>
                        <div class="sb2b-footer__identity">
                            <img src="{{ $img('footerlogo.png') }}" alt="VISANG Vietnam" width="417" height="72">
                            <div class="sb2b-footer__social">
                                <a href="#" data-coming-soon aria-label="Facebook"><svg viewBox="6 2 14 21" fill="currentColor" aria-hidden="true"><path d="M14 21v-8h2.7l.4-3H14V8.1c0-.9.3-1.5 1.6-1.5h1.7V3.9c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3V10H8v3h2.6v8H14Z"/></svg></a>
                                <a href="#" data-coming-soon aria-label="YouTube"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="4" fill="currentColor"/><path d="m10 8 6 4-6 4V8Z" fill="#1f3278"/></svg></a>
                            </div>
                        </div>
                    </div>
                    <div class="sb2b-footer__contact">
                        <h2>{{ __('lf.LF_student_site_footer_contact') }}</h2>
                        <p>{{ __('lf.LF_student_site_footer_representative') }}</p>
                        <p><a href="tel:02436886333">{{ __('lf.LF_student_site_footer_phone') }}</a></p>
                        <p>{{ __('lf.LF_student_site_footer_email') }} <a href="mailto:lienhe@hkca.example">lienhe@hkca.example</a></p>
                        <p>{{ __('lf.LF_student_site_footer_tax') }}</p>
                    </div>
                    <div class="sb2b-footer__support">
                        <h2>{{ __('lf.LF_student_site_footer_support') }}</h2>
                        <nav class="sb2b-footer__links" aria-label="{{ __('lf.LF_student_site_footer_support') }}">
                            <a href="#" data-coming-soon>{{ __('lf.LF_student_site_footer_faq') }}</a>
                            <a href="#" data-coming-soon>{{ __('lf.LF_student_site_footer_rules') }}</a>
                            <a href="#" data-coming-soon>{{ __('lf.LF_student_site_footer_privacy') }}</a>
                        </nav>
                    </div>
                </div>
                <p class="sb2b-footer__copyright">{{ __('lf.LF_student_site_footer_copyright') }}</p>
            </div>
        </footer>

        <div class="mk-area-button-ai-mess">
            <button type="button" class="sb2b-ai-shortcut" data-coming-soon aria-label="{{ __('lf.LF_student_site_ai_speak') }}" data-tooltip="{{ __('lf.LF_student_site_ai_speak') }}">
                <img alt="" width="72" src="{{ $img('aispeak.svg') }}">
            </button>
            <button type="button" class="sb2b-to-top" data-to-top aria-label="{{ __('lf.LF_student_site_to_top') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
            </button>
        </div>
        <p class="sb2b-toast" role="status" aria-live="polite" data-coming-soon-status></p>
    </div>
@endsection
