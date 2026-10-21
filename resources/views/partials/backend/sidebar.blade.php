<aside id="backend-sidebar" class="admin-sidebar" x-ref="sidebar" aria-label="{{ $navigationLabel }}">
    <div class="admin-sidebar__brand">
        <a href="{{ route($dashboardRoute) }}" aria-label="{{ $dashboardLabel }}">
            <img class="admin-brand-image" src="{{ asset('assets/admin/brand-logo.png') }}" alt="Master Korean" width="180" height="67">
            <img class="admin-brand-symbol" src="{{ asset('assets/admin/brand-symbol.png') }}" alt="Master Korean" width="40" height="36">
        </a>
        <button type="button" class="admin-icon-button admin-close" x-ref="drawerClose"
                x-bind:hidden="! drawerOpen" hidden x-on:click="closeDrawer()"
                aria-label="{{ __('lf.LF_navigation_button_backend_menu_close') }}">
            <x-backend-icon name="x" class="admin-icon" />
        </button>
    </div>
    <nav class="admin-nav" aria-label="{{ $navigationLabel }}">
        @foreach ($portalMenu as $item)
            @continue(! $item['visible'])
            @php
                $children = collect($item['children'] ?? [])->filter(fn (array $child): bool => $child['visible']);
                $isActive = ($item['active'] ?? null) && request()->routeIs($item['active']);
                $groupKey = 'sidebar-group-'.$loop->index;
            @endphp
            @if ($children->isNotEmpty())
                @php
                    $isGroupActive = $children->contains(fn (array $child): bool => $child['active'] && request()->routeIs($child['active']));
                @endphp
                <div class="admin-nav__group" x-init="registerSidebarGroup('{{ $groupKey }}', @js($isGroupActive))">
                    <button type="button" class="admin-nav__item" aria-label="{{ $item['label'] }}"
                            aria-controls="{{ $groupKey }}" aria-expanded="{{ $isGroupActive ? 'true' : 'false' }}"
                            x-bind:aria-expanded="isSidebarGroupOpen('{{ $groupKey }}').toString()"
                            @if ($isGroupActive) data-active-parent="true" @endif
                            x-on:click="toggleSidebarGroup('{{ $groupKey }}')">
                        <x-backend-icon :name="$item['icon'] ?? 'circle'" class="admin-icon" />
                        <span class="admin-nav__label">{{ $item['label'] }}</span>
                        <x-backend-icon name="chevron-down" class="admin-nav__chevron" />
                    </button>
                    <div id="{{ $groupKey }}" class="admin-nav__children" x-show="isSidebarGroupOpen('{{ $groupKey }}')">
                        @foreach ($children as $child)
                            <a class="admin-nav__child" href="{{ route($child['route']) }}"
                               @if ($child['active'] && request()->routeIs($child['active'])) aria-current="page" @endif>{{ $child['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            @elseif ($item['route'])
                <a class="admin-nav__item" href="{{ route($item['route']) }}"
                   aria-label="{{ $item['label'] }}" data-sidebar-icon="{{ $item['icon'] ?? 'circle' }}"
                   @if ($isActive) aria-current="page" @endif
                   x-on:mouseenter="showSidebarTooltip($event, @js($item['label']))"
                   x-on:mouseleave="hideSidebarTooltip()" x-on:focus="showSidebarTooltip($event, @js($item['label']))"
                   x-on:blur="hideSidebarTooltip()">
                    <x-backend-icon :name="$item['icon'] ?? 'circle'" class="admin-icon" />
                    <span class="admin-nav__label">{{ $item['label'] }}</span>
                </a>
            @endif
        @endforeach
    </nav>
</aside>
