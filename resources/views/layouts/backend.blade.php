@extends('layouts.app')

@section('vite')
    <script>
        (() => {
            const collapsedKey = 'lf.backend.sidebar.collapsed';
            let shouldCollapse = window.matchMedia('(min-width: 681px) and (max-width: 1024px)').matches;

            try {
                const storedPreference = window.localStorage.getItem(collapsedKey);

                if (storedPreference === 'true' || storedPreference === 'false') {
                    shouldCollapse = storedPreference === 'true';
                }
            } catch (error) {
                // keep the width-based default
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
        // A section value arrives already escaped; decode it once because the breadcrumb component escapes again.
        $pageTitle = html_entity_decode(trim($__env->yieldContent('page_title', __('lf.LF_common_title_common_dashboard'))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $currentRouteName = (string) request()->route()?->getName();
        $isWorkspacePage = \Illuminate\Support\Str::of($currentRouteName)->endsWith([
            '.create',
            '.edit',
            '.show',
            '.detail',
            '.info',
        ]);
        $accountMenuLinks = $isTeacher
            ? [
                ['label' => __('lf.LF_navigation_menu_admin_my_account'), 'route' => 'teacher.profile.edit', 'icon' => 'user'],
            ]
            : [
                ['label' => __('lf.LF_navigation_menu_admin_my_account'), 'route' => 'admin.my-account.edit', 'icon' => 'user'],
                ['label' => __('lf.LF_navigation_menu_admin_users'), 'route' => 'admin.users.index', 'icon' => 'users'],
                ['label' => __('lf.LF_navigation_menu_admin_organization'), 'route' => 'admin.organization.edit', 'icon' => 'building-2'],
            ];
        $portalMenu = $isTeacher
            ? [
                ['label' => __('lf.LF_navigation_menu_teacher_dashboard'), 'route' => 'teacher.dashboard', 'active' => 'teacher.dashboard', 'visible' => true, 'icon' => 'home'],
                ['label' => __('lf.LF_navigation_group_course_content'), 'route' => null, 'active' => null, 'visible' => true, 'icon' => 'book-open', 'children' => [
                    ['label' => __('lf.LF_navigation_menu_common_product_categories'), 'route' => 'teacher.course-categories.index', 'active' => 'teacher.course-categories.*', 'visible' => true],
                    ['label' => __('lf.LF_navigation_menu_common_course_templates'), 'route' => 'teacher.course-templates.index', 'active' => 'teacher.course-templates.*', 'visible' => true],
                    ['label' => __('lf.LF_navigation_menu_admin_media'), 'route' => 'teacher.media.index', 'active' => 'teacher.media.*', 'visible' => true],
                ]],
                ['label' => __('lf.LF_navigation_menu_teacher_cohorts'), 'route' => 'teacher.cohorts.index', 'active' => 'teacher.cohorts.*', 'visible' => true, 'icon' => 'users'],
            ]
            : [
                ['label' => __('lf.LF_navigation_menu_admin_dashboard'), 'route' => 'admin.dashboard', 'active' => 'admin.dashboard', 'visible' => true, 'icon' => 'home'],
                ['label' => __('lf.LF_navigation_group_course_content'), 'route' => null, 'active' => null, 'visible' => true, 'icon' => 'book-open', 'children' => [
                    ['label' => __('lf.LF_navigation_menu_common_product_categories'), 'route' => 'admin.course-categories.index', 'active' => 'admin.course-categories.*', 'visible' => true],
                    ['label' => __('lf.LF_navigation_menu_common_course_templates'), 'route' => 'admin.course-templates.index', 'active' => 'admin.course-templates.*', 'visible' => true],
                    ['label' => __('lf.LF_navigation_menu_admin_course_products'), 'route' => 'admin.course-products.index', 'active' => 'admin.course-products.*', 'visible' => true],
                    ['label' => __('lf.LF_navigation_menu_admin_media'), 'route' => 'admin.media.index', 'active' => 'admin.media.*', 'visible' => true],
                ]],
                ['label' => __('lf.LF_navigation_menu_admin_course_enrollments'), 'route' => 'admin.course-enrollments.index', 'active' => 'admin.course-enrollments.*', 'visible' => true, 'icon' => 'clipboard-check'],
                ['label' => __('lf.LF_navigation_menu_admin_course_cohorts'), 'route' => 'admin.course-cohorts.index', 'active' => 'admin.course-cohorts.*', 'visible' => true, 'icon' => 'users'],
                ['label' => __('lf.LF_learning_frameworks'), 'route' => 'admin.learning-frameworks.index', 'active' => 'admin.learning-frameworks.*', 'visible' => true, 'icon' => 'book-marked'],
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
        $initials = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($portalUser?->name ?? '', 0, 2));
    @endphp

    <div id="admin-app" x-data="backendSidebar()" class="backend-shell"
         x-bind:class="{ 'is-sidebar-collapsed': sidebarCollapsed && ! isMobile }">
        <div id="admin-shell" class="admin-shell" x-ref="shell"
             x-bind:class="{ 'is-collapsed': sidebarCollapsed && ! isMobile }">
            @include('partials.backend.sidebar')
            <div class="admin-main">
                @include('partials.backend.header')
                <main id="admin-content" class="admin-content">
                    @hasSection('page_back')
                        <div class="page-back">@yield('page_back')</div>
                    @endif
                    {{-- A page may hide its visible title (`page_title_hidden`): the breadcrumb already names it; the h1 stays for screen readers. --}}
                    <div @class(['page-heading', 'page-heading--with-actions' => $__env->hasSection('page_actions'), 'page-heading--hidden' => $__env->hasSection('page_title_hidden')])>
                        <h1 id="admin-page-title">@yield('page_title', __('lf.LF_common_title_common_dashboard'))</h1>
                        @hasSection('page_actions')
                            <div class="page-heading__actions lf-admin-ui lf-admin-ui--list">@yield('page_actions')</div>
                        @endif
                    </div>
                    @yield('content')
                </main>
                <footer class="admin-page-footer">{{ __('lf.LF_common_footer_common_copyright') }}</footer>
            </div>
        </div>
        <dialog id="admin-drawer" class="admin-drawer" x-ref="drawer" aria-label="{{ $navigationLabel }}"
                x-on:close="restoreSidebar()" x-on:click="closeDrawerOnBackdrop($event)"></dialog>
        <div class="backend-sidebar-tooltip" x-cloak x-show="sidebarTooltip.visible"
             x-bind:style="`top: ${sidebarTooltip.top}px; left: ${sidebarTooltip.left}px`"
             role="tooltip"><span x-text="sidebarTooltip.label"></span></div>
        <x-confirm-dialog />
    </div>
@endsection
