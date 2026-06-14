<header class="public-header">
    <div class="public-container public-header-inner">
        <a class="public-brand" href="{{ route('public.home') }}">
            <span class="public-brand-mark">LF</span>
            <span>{{ __('lf.LF_common_brand_name') }}</span>
        </a>

        <nav class="public-nav" aria-label="{{ __('lf.LF_common_navigation_public_label') }}">
            <a class="public-nav-link {{ request()->routeIs('public.home') ? 'is-active' : '' }}"
               href="{{ route('public.home') }}">{{ __('lf.LF_navigation_menu_public_home') }}</a>
            <a class="public-nav-link {{ request()->routeIs('public.features') ? 'is-active' : '' }}"
               href="{{ route('public.features') }}">{{ __('lf.LF_navigation_menu_public_features') }}</a>
            <a class="public-nav-link {{ request()->routeIs('public.pricing') ? 'is-active' : '' }}"
               href="{{ route('public.pricing') }}">{{ __('lf.LF_navigation_menu_public_pricing') }}</a>
            <a class="public-nav-link {{ request()->routeIs('public.services') ? 'is-active' : '' }}"
               href="{{ route('public.services') }}">{{ __('lf.LF_navigation_menu_public_services') }}</a>
            <a class="public-nav-link {{ request()->routeIs('public.about') ? 'is-active' : '' }}"
               href="{{ route('public.about') }}">{{ __('lf.LF_navigation_menu_public_about') }}</a>
            @include('partials.language-switcher', ['attributes' => new \Illuminate\View\ComponentAttributeBag([
                'class' => 'public-language-switcher',
            ])])
            <a class="public-nav-cta" href="{{ route('customer.register') }}">{{ __('lf.LF_home_public_register_tenant') }}</a>
        </nav>
    </div>
</header>
