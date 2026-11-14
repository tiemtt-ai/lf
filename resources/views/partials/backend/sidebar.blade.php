<aside id="backend-sidebar" class="admin-sidebar" x-ref="sidebar" aria-label="{{ $navigationLabel }}">
    <div class="admin-sidebar__brand">
        <a href="{{ route($dashboardRoute) }}" aria-label="{{ $dashboardLabel }}">
            <img class="admin-brand-image" src="{{ asset('assets/admin/brand-logo.png') }}" alt="Master Korean" width="180" height="67" decoding="sync" fetchpriority="high">
            <img class="admin-brand-symbol" src="{{ asset('assets/admin/brand-symbol.png') }}" alt="Master Korean" width="40" height="36" decoding="sync">
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
                    <div id="{{ $groupKey }}" class="admin-nav__children" x-show="isSidebarGroupOpen('{{ $groupKey }}')"
                         @unless ($isGroupActive) style="display: none;" @endunless>
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
    {{-- Runs before the first paint: give the shell the classes backendSidebar() will bind and open the groups
         the user left open (same storage), so the menu is drawn once in its final shape instead of jumping when Alpine starts. --}}
    <script>
        (() => {
            const isRail = document.documentElement.classList.contains('is-backend-sidebar-collapsed')
                && ! window.matchMedia('(max-width: 680px)').matches;

            document.getElementById('admin-app')?.classList.toggle('is-sidebar-collapsed', isRail);
            document.getElementById('admin-shell')?.classList.toggle('is-collapsed', isRail);

            let openGroups = {};

            try {
                openGroups = JSON.parse(window.localStorage.getItem('lf.backend.sidebar.groups') || '{}') || {};
            } catch (error) {
                openGroups = {};
            }

            document.querySelectorAll('#backend-sidebar .admin-nav__group > [aria-controls]').forEach((button) => {
                const panel = document.getElementById(button.getAttribute('aria-controls'));
                const isOpen = button.dataset.activeParent === 'true' || openGroups[panel.id] === true;

                panel.style.display = isOpen ? '' : 'none';
                button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        })();
    </script>
</aside>
