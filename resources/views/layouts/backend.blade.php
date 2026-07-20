@extends('layouts.app')

@section('vite')
    <script>
        (() => {
            const collapsedKey = 'lf.backend.sidebar.collapsed';
            let shouldCollapse = false;

            try {
                const storedPreference = window.localStorage.getItem(collapsedKey);

                if (storedPreference === 'true' || storedPreference === 'false') {
                    shouldCollapse = storedPreference === 'true';
                }
            } catch (error) {
                shouldCollapse = false;
            }

            document.documentElement.classList.add('is-backend-sidebar-initializing');
            document.documentElement.classList.toggle('is-backend-sidebar-collapsed', shouldCollapse);
        })();
    </script>
    @vite(['resources/css/backend.css', 'resources/js/app.js'])
@endsection

@section('body_class', 'lf-admin-page')

@section('app_shell')
    @php
        $portalUser = auth()->user();
        $isTeacher = $portalUser?->role === 'teacher';
        $dashboardRoute = $isTeacher ? 'teacher.dashboard' : 'admin.dashboard';
        $navigationLabel = $isTeacher
            ? __('lf.LF_navigation_label_teacher_navigation')
            : __('lf.LF_navigation_label_admin_navigation');
        $pageTitle = trim($__env->yieldContent('page_title', __('lf.LF_common_title_common_dashboard')));
        $currentRouteName = (string) request()->route()?->getName();
        $isWorkspacePage = \Illuminate\Support\Str::of($currentRouteName)->endsWith([
            '.create',
            '.edit',
            '.show',
            '.detail',
            '.info',
        ]);
        $primaryMenu = $isTeacher
            ? [
                __('lf.LF_navigation_menu_student_my_courses'),
                __('lf.LF_navigation_menu_public_assessments'),
                __('lf.LF_navigation_menu_teacher_live_classes'),
                __('lf.LF_navigation_menu_teacher_students'),
                __('lf.LF_navigation_menu_teacher_reports'),
                __('lf.LF_navigation_menu_teacher_ai_assistant'),
            ]
            : [
                __('lf.LF_navigation_menu_public_courses'),
                __('lf.LF_navigation_menu_admin_exams'),
                __('lf.LF_navigation_menu_admin_curriculum'),
                __('lf.LF_navigation_menu_public_teachers'),
                __('lf.LF_navigation_menu_admin_community'),
                __('lf.LF_navigation_menu_admin_level_test'),
                __('lf.LF_navigation_menu_admin_visang_video'),
            ];
        $accountMenuLinks = $isTeacher
            ? [
                ['label' => __('lf.LF_navigation_menu_admin_my_account'), 'route' => 'teacher.profile.edit'],
            ]
            : [
                ['label' => __('lf.LF_navigation_menu_admin_my_account'), 'route' => 'admin.my-account.edit'],
                ['label' => __('lf.LF_navigation_menu_admin_users'), 'route' => 'admin.users.index'],
                ['label' => __('lf.LF_navigation_menu_admin_organization'), 'route' => 'admin.organization.edit'],
            ];
        $portalMenu = $isTeacher
            ? [
                ['label' => __('lf.LF_navigation_menu_teacher_dashboard'), 'route' => 'teacher.dashboard', 'active' => 'teacher.dashboard', 'visible' => true, 'icon' => 'home'],
                ['label' => __('lf.LF_navigation_menu_common_product_categories'), 'route' => 'teacher.course-categories.index', 'active' => 'teacher.course-categories.*', 'visible' => true, 'icon' => 'folder'],
                ['label' => __('lf.LF_navigation_menu_common_course_templates'), 'route' => 'teacher.course-templates.index', 'active' => 'teacher.course-templates.*', 'visible' => true, 'icon' => 'book-open'],
            ]
            : [
                ['label' => __('lf.LF_navigation_menu_admin_dashboard'), 'route' => 'admin.dashboard', 'active' => 'admin.dashboard', 'visible' => true, 'icon' => 'home'],
                ['label' => __('lf.LF_navigation_menu_common_product_categories'), 'route' => 'admin.course-categories.index', 'active' => 'admin.course-categories.*', 'visible' => true, 'icon' => 'folder'],
                ['label' => __('lf.LF_navigation_menu_common_course_templates'), 'route' => 'admin.course-templates.index', 'active' => 'admin.course-templates.*', 'visible' => true, 'icon' => 'book-open'],
                ['label' => __('lf.LF_navigation_menu_admin_course_products'), 'route' => 'admin.course-products.index', 'active' => 'admin.course-products.*', 'visible' => true, 'icon' => 'package'],
                ['label' => __('lf.LF_navigation_menu_admin_course_enrollments'), 'route' => 'admin.course-enrollments.index', 'active' => 'admin.course-enrollments.*', 'visible' => true, 'icon' => 'clipboard-check'],
                ['label' => __('lf.LF_navigation_menu_admin_course_cohorts'), 'route' => 'admin.course-cohorts.index', 'active' => 'admin.course-cohorts.*', 'visible' => true, 'icon' => 'users'],
                ['label' => __('lf.LF_navigation_menu_admin_media'), 'route' => 'admin.media.index', 'active' => 'admin.media.*', 'visible' => true, 'icon' => 'image'],
            ];
        $dashboardLabel = $isTeacher
            ? __('lf.LF_navigation_menu_teacher_dashboard')
            : __('lf.LF_navigation_menu_admin_dashboard');
        $breadcrumbs = [[
            'label' => $dashboardLabel,
            'url' => route($dashboardRoute),
            'current' => request()->routeIs($dashboardRoute),
        ]];

        if (! request()->routeIs($dashboardRoute)) {
            $activeMenuItem = null;

            foreach ($portalMenu as $item) {
                if (! $item['visible']) {
                    continue;
                }

                $children = collect($item['children'] ?? [])
                    ->filter(fn (array $child): bool => $child['visible']);

                if ($children->isNotEmpty()) {
                    $activeChild = $children->first(
                        fn (array $child): bool => $child['active']
                            && request()->routeIs($child['active'])
                    );

                    if ($activeChild) {
                        $activeMenuItem = $activeChild;
                        break;
                    }

                    continue;
                }

                if (($item['active'] ?? null) && request()->routeIs($item['active'])) {
                    $activeMenuItem = $item;
                    break;
                }
            }

            if ($activeMenuItem) {
                $menuLabel = $activeMenuItem['label'];
                $menuUrl = route($activeMenuItem['route']);
                $showPageCrumb = $pageTitle !== $menuLabel && $isWorkspacePage;

                $breadcrumbs[] = [
                    'label' => $menuLabel,
                    'url' => $showPageCrumb ? $menuUrl : null,
                    'current' => ! $showPageCrumb,
                ];

                if ($showPageCrumb) {
                    $breadcrumbs[] = [
                        'label' => $pageTitle,
                        'url' => null,
                        'current' => true,
                    ];
                }
            } else {
                $breadcrumbs[] = [
                    'label' => $pageTitle,
                    'url' => null,
                    'current' => true,
                ];
            }
        }
    @endphp

    <div x-data="backendSidebar()"
         x-bind:class="{ 'is-sidebar-collapsed': sidebarCollapsed }"
         class="backend-shell">
    <header class="layout-header layout-header-top">
        <div class="admin-container">
            <div class="admin-partner-logos" aria-label="{{ __('lf.LF_common_navigation_common_partner_brands') }}">
                @foreach (range(1, 4) as $partner)
                    <img src="{{ asset("assets/admin/partner-{$partner}.png") }}" alt="">
                @endforeach
            </div>

            <div class="admin-account-actions">
                <div class="admin-account-menu" x-data="{ open: false }" x-on:click.outside="open = false">
                    <button class="admin-account-trigger" type="button" x-on:click="open = ! open" aria-label="{{ __('lf.LF_common_navigation_common_account_menu') }}">
                        <img src="{{ asset('assets/admin/account.svg') }}" alt="">
                        {{ $portalUser->name ?? 'user' }}
                        <span class="admin-chevron" aria-hidden="true"></span>
                    </button>

                    <div class="admin-account-dropdown" x-show="open" x-cloak>
                        <div class="admin-account-dropdown-user">
                            <strong>{{ $portalUser->name ?? 'user' }}</strong>
                            <span>{{ $portalUser->email ?? 'user@example.com' }}</span>
                        </div>

                        <div class="admin-account-dropdown-links">
                            @foreach ($accountMenuLinks as $link)
                                <a href="{{ route($link['route']) }}">{{ $link['label'] }}</a>
                            @endforeach
                        </div>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit">{{ __('lf.LF_navigation_menu_student_logout') }}</button>
                        </form>
                    </div>
                </div>

                @include('partials.language-switcher', ['attributes' => new \Illuminate\View\ComponentAttributeBag([
                    'class' => 'admin-language',
                ])])
            </div>
        </div>
    </header>

    <nav class="layout-header layout-header-nav" aria-label="{{ __('lf.LF_common_navigation_common_primary') }}">
        <div class="admin-container">
            <a href="{{ route($dashboardRoute) }}">
                <img class="admin-brand-logo" src="{{ asset('assets/admin/brand-logo.png') }}" alt="LearnForge">
            </a>

            {{--<div class="admin-primary-menu" aria-hidden="true">
                @foreach ($primaryMenu as $item)
                    <span>{{ $item }}</span>
                @endforeach
            </div>--}}
            <div class="admin-primary-menu" aria-hidden="true">
                <span>{{ __('lf.LF_navigation_menu_public_courses') }} <i class="admin-chevron"></i></span>
                <span>{{ __('lf.LF_navigation_menu_admin_exams') }}</span>
                <span>{{ __('lf.LF_navigation_menu_admin_curriculum') }}</span>
                <span>{{ __('lf.LF_navigation_menu_public_teachers') }}</span>
                <span>{{ __('lf.LF_navigation_menu_admin_community') }}</span>
                <span>{{ __('lf.LF_navigation_menu_admin_level_test') }}</span>
                <span>{{ __('lf.LF_navigation_menu_admin_visang_video') }} <i class="admin-chevron"></i></span>
            </div>

            <div class="admin-nav-actions">
                <img src="{{ asset('assets/admin/search.png') }}" alt="{{ __('lf.LF_common_button_search') }}">
            </div>
        </div>
    </nav>

    <div class="layout-breadcrumb-bar">
        <div class="admin-container">
            <x-backend-breadcrumbs :breadcrumbs="$breadcrumbs" />
        </div>
    </div>

    <main class="layout-content">
        <div class="admin-container admin-content-wrap">
            <aside id="backend-sidebar" class="layout-sidebar">
                <nav @class([
                    'admin-sidebar-menu',
                    'is-teacher' => $isTeacher,
                ]) aria-label="{{ $navigationLabel }}">
                    @foreach ($portalMenu as $item)
                        @continue(! $item['visible'])

                        @php
                            $children = collect($item['children'] ?? [])
                                ->filter(fn (array $child): bool => $child['visible']);
                            $isGroup = $children->isNotEmpty();
                            $isActive = ! $isGroup
                                && $item['active']
                                && request()->routeIs($item['active']);
                            $isGroupActive = $isGroup
                                && $children->contains(
                                    fn (array $child): bool => $child['active']
                                        && request()->routeIs($child['active'])
                                );
                            $groupKey = 'sidebar-group-'.$loop->index;
                            $classes = 'admin-sidebar-link'.($isActive ? ' is-active' : '');
                        @endphp

                        @if ($isGroup)
                            <div @class([
                                'admin-sidebar-group',
                                'is-active' => $isGroupActive,
                                'is-open' => $isGroupActive,
                            ])
                                 data-sidebar-group-key="{{ $groupKey }}"
                                 x-init="registerSidebarGroup('{{ $groupKey }}', @js($isGroupActive))"
                                 x-bind:class="{ 'is-open': isSidebarGroupOpen('{{ $groupKey }}') }">
                                <script>
                                    (() => {
                                        try {
                                            const groups = JSON.parse(window.localStorage.getItem('lf.backend.sidebar.groups') || '{}') || {};

                                            if (groups[@js($groupKey)] === true) {
                                                document.currentScript.parentElement.classList.add('is-open');
                                            }
                                        } catch (error) {
                                            // Ignore storage failures so the backend layout remains usable.
                                        }
                                    })();
                                </script>
                                <button class="admin-sidebar-group-label"
                                        type="button"
                                        data-sidebar-icon="{{ $item['icon'] ?? 'circle' }}"
                                        data-sidebar-label="{{ $item['label'] }}"
                                        title="{{ $item['label'] }}"
                                        x-bind:aria-expanded="isSidebarGroupOpen('{{ $groupKey }}').toString()"
                                        x-on:click="toggleSidebarGroup('{{ $groupKey }}')">
                                    <x-backend-icon :name="$item['icon'] ?? 'circle'" />
                                    <span class="admin-sidebar-link-label">{{ $item['label'] }}</span>
                                    <span class="admin-sidebar-group-arrow" aria-hidden="true"></span>
                                </button>

                                <div class="admin-sidebar-group-links"
                                     x-show="isSidebarGroupOpen('{{ $groupKey }}')">
                                    @foreach ($children as $child)
                                        @php
                                            $childIsActive = $child['active'] && request()->routeIs($child['active']);
                                            $childClasses = 'admin-sidebar-link admin-sidebar-link-child'.($childIsActive ? ' is-active' : '');
                                        @endphp

                                        <a class="{{ $childClasses }}" href="{{ route($child['route']) }}"
                                           aria-label="{{ $child['label'] }}"
                                           title="{{ $child['label'] }}">
                                            <span class="admin-sidebar-link-label">{{ $child['label'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                            @continue
                        @endif

                        @if ($item['route'])
                            <a class="{{ $classes }}" href="{{ route($item['route']) }}"
                               aria-label="{{ $item['label'] }}"
                               data-sidebar-icon="{{ $item['icon'] ?? 'circle' }}"
                               data-sidebar-label="{{ $item['label'] }}"
                               title="{{ $item['label'] }}">
                                <x-backend-icon :name="$item['icon'] ?? 'circle'" />
                                <span class="admin-sidebar-link-label">{{ $item['label'] }}</span>
                            </a>
                        @endif
                    @endforeach
                </nav>
            </aside>

            <section class="admin-page-content">
                <h1 class="admin-page-title">@yield('page_title', __('lf.LF_common_title_common_dashboard'))</h1>
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

    <div class="admin-floating" aria-hidden="true">
        <div class="admin-floating-button is-chatbot">
            <img src="{{ asset('assets/admin/chatbot.png') }}" alt="">
        </div>
        <div class="admin-floating-button is-ai">
            <img src="{{ asset('assets/admin/ai-assistant.png') }}" alt="">
        </div>
        <div class="admin-floating-button is-message">
            <img src="{{ asset('assets/admin/download.svg') }}" alt="{{ __('lf.LF_common_image_common_download') }}">
        </div>
    </div>
    </div>
@endsection
