@props([
    'breadcrumbs' => [],
    'toggleLabel' => __('lf.LF_navigation_button_backend_sidebar_toggle'),
])

<div class="backend-breadcrumb-inner">
    <button class="backend-sidebar-toggle"
            type="button"
            x-on:click="toggleSidebar"
            x-bind:aria-expanded="(! sidebarCollapsed).toString()"
            aria-controls="backend-sidebar"
            aria-label="{{ $toggleLabel }}">
        <span class="backend-sidebar-toggle-icon" aria-hidden="true"></span>
    </button>

    <nav class="backend-breadcrumbs"
         aria-label="{{ __('lf.LF_navigation_label_backend_breadcrumbs') }}">
        <ol class="backend-breadcrumb-list">
            @foreach ($breadcrumbs as $breadcrumb)
                <li class="backend-breadcrumb-item">
                    @if (($breadcrumb['url'] ?? null) && ! ($breadcrumb['current'] ?? false))
                        <a href="{{ $breadcrumb['url'] }}">
                            {{ $breadcrumb['label'] }}
                        </a>
                    @else
                        <span @if ($breadcrumb['current'] ?? false) aria-current="page" @endif>
                            {{ $breadcrumb['label'] }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
</div>
