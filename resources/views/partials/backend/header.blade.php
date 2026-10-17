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
        <div class="admin-top-pop">
            <button type="button" class="admin-top-button admin-notification" aria-controls="top-notifications"
                    aria-expanded="false" x-bind:aria-expanded="(open === 'notifications').toString()"
                    x-on:click="open = open === 'notifications' ? null : 'notifications'"
                    aria-label="{{ __('lf.LF_navigation_label_backend_notifications') }}">
                <x-backend-icon name="bell" class="admin-icon" />
            </button>
            <div id="top-notifications" class="admin-top-dropdown" x-show="open === 'notifications'" x-cloak>
                <strong>{{ __('lf.LF_navigation_label_backend_notifications') }}</strong>
                <p>{{ __('lf.LF_navigation_label_backend_notifications_unavailable') }}</p>
            </div>
        </div>
        <div class="admin-top-pop">
            <button type="button" class="admin-top-button" aria-controls="top-language" aria-expanded="false"
                    x-bind:aria-expanded="(open === 'language').toString()"
                    x-on:click="open = open === 'language' ? null : 'language'"
                    aria-label="{{ __('lf.LF_common_language_switch') }}">
                <x-backend-icon name="globe" class="admin-icon" />
                <span class="admin-language-label">{{ __('lf.LF_common_language_switch') }}:</span>
                <span id="current-language">{{ __('lf.LF_common_language_'.app()->getLocale()) }}</span>
                <x-backend-icon name="chevron-down" class="admin-chevron" />
            </button>
            <div id="top-language" class="admin-top-dropdown admin-top-dropdown--small" x-show="open === 'language'" x-cloak>
                @foreach (['vi', 'en'] as $locale)
                    <form method="POST" action="{{ route('language.update', ['locale' => $locale]) }}">
                        @csrf
                        <button type="submit" class="admin-dropdown-row" @if (app()->getLocale() === $locale) aria-current="true" @endif>
                            {{ __('lf.LF_common_language_'.$locale) }} @if (app()->getLocale() === $locale)<span aria-hidden="true">✓</span>@endif
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
        <div class="admin-top-pop">
            <button type="button" class="admin-top-button" aria-controls="top-account" aria-expanded="false"
                    x-bind:aria-expanded="(open === 'account').toString()"
                    x-on:click="open = open === 'account' ? null : 'account'"
                    aria-label="{{ __('lf.LF_common_navigation_common_account_menu') }}">
                <span class="admin-avatar" aria-hidden="true">{{ $initials }}</span>
                <span class="admin-account-name">{{ $portalUser->name }}</span>
                <x-backend-icon name="chevron-down" class="admin-chevron" />
            </button>
            <div id="top-account" class="admin-top-dropdown" x-show="open === 'account'" x-cloak>
                <strong>{{ $portalUser->name }}</strong>
                <p>{{ $portalUser->email }}</p>
                @foreach ($accountMenuLinks as $link)
                    <a class="admin-dropdown-row" href="{{ route($link['route']) }}">
                        <x-backend-icon name="user" class="admin-icon" />{{ $link['label'] }}
                    </a>
                @endforeach
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="admin-dropdown-row">
                        <x-backend-icon name="log-out" class="admin-icon" />{{ __('lf.LF_navigation_menu_student_logout') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
