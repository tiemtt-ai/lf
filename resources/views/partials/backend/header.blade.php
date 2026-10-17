@php
    $accountRoleLabel = $isTeacher
        ? __('lf.LF_common_role_teacher_teacher')
        : __('lf.LF_navigation_label_account_administrator');
@endphp

<header class="admin-header">
    <button id="admin-menu-toggle" type="button" class="admin-icon-button" x-ref="menuToggle"
            x-on:click="toggleSidebar()" aria-controls="backend-sidebar" aria-expanded="true"
            aria-label="{{ __('lf.LF_navigation_button_backend_menu_collapse') }}"
            x-bind:aria-expanded="(isMobile ? drawerOpen : ! sidebarCollapsed).toString()"
            x-bind:aria-label="isMobile ? @js(__('lf.LF_navigation_button_backend_menu_open')) : (sidebarCollapsed ? @js(__('lf.LF_navigation_button_backend_menu_expand')) : @js(__('lf.LF_navigation_button_backend_menu_collapse')))">
        <x-backend-icon name="panel-left" class="admin-icon" />
    </button>
    <x-backend-breadcrumbs :breadcrumbs="$breadcrumbs" />
    <div class="admin-top-actions" x-data="{ open: null, closeMenu() { const button = this.$el.querySelector('[aria-expanded=true]'); this.open = null; button?.focus(); } }"
         x-on:click.outside="open = null" x-on:keydown.escape.stop="closeMenu()">
        <span class="admin-top-button admin-notification admin-notification-placeholder" role="img"
              aria-label="{{ __('lf.LF_navigation_label_backend_notifications') }}">
            <x-backend-icon name="bell" class="admin-icon" />
        </span>
        <div class="admin-top-pop">
            <button type="button" class="admin-top-button admin-language-button" aria-controls="top-language" aria-expanded="false"
                    x-bind:aria-expanded="(open === 'language').toString()"
                    x-on:click="open = open === 'language' ? null : 'language'"
                    aria-label="{{ __('lf.LF_common_language_switch') }}">
                <img class="admin-language-flag" src="{{ asset('assets/admin/flag-'.app()->getLocale().'.svg') }}" alt="" width="24" height="16">
                <span id="current-language">{{ strtoupper(app()->getLocale()) }}</span>
                <span class="admin-language-caret" aria-hidden="true"></span>
            </button>
            <div id="top-language" class="admin-top-dropdown admin-top-dropdown--small admin-language-dropdown" x-show="open === 'language'" x-cloak>
                @foreach (['vi', 'en'] as $locale)
                    @continue(app()->getLocale() === $locale)
                    <form method="POST" action="{{ route('language.update', ['locale' => $locale]) }}">
                        @csrf
                        <button type="submit" class="admin-dropdown-row admin-language-option" lang="{{ $locale }}" aria-label="{{ __('lf.LF_common_language_'.$locale) }}">
                            <img class="admin-language-flag" src="{{ asset('assets/admin/flag-'.$locale.'.svg') }}" alt="" width="24" height="16">
                            <span>{{ strtoupper($locale) }}</span>
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
        <div class="admin-top-pop">
            <button type="button" class="admin-top-button admin-account-button" aria-controls="top-account" aria-expanded="false"
                    x-bind:aria-expanded="(open === 'account').toString()"
                    x-on:click="open = open === 'account' ? null : 'account'"
                    aria-label="{{ __('lf.LF_common_navigation_common_account_menu') }}">
                <span class="admin-avatar" aria-hidden="true"><x-backend-icon name="user" class="admin-icon" /></span>
                <span class="admin-account-name">{{ $portalUser->name }}</span>
                <x-backend-icon name="chevron-down" class="admin-chevron" />
            </button>
            <div id="top-account" class="admin-top-dropdown admin-account-dropdown" x-show="open === 'account'" x-cloak>
                <div class="admin-account-summary">
                    <div class="admin-account-identity">
                        <strong>{{ $portalUser->name }}</strong>
                        <span class="admin-account-role">{{ $accountRoleLabel }}</span>
                    </div>
                    <p>{{ $portalUser->email }}</p>
                </div>
                @foreach ($accountMenuLinks as $link)
                    <a class="admin-dropdown-row" href="{{ route($link['route']) }}">
                        <x-backend-icon :name="$link['icon']" class="admin-icon" />{{ $link['label'] }}
                    </a>
                @endforeach
                <form class="admin-account-logout" method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="admin-dropdown-row">
                        <x-backend-icon name="log-out" class="admin-icon" />{{ __('lf.LF_navigation_menu_student_logout') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
