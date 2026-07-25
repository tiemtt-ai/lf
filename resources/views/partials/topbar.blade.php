@php
    $forceRootNavigation = $forceRootNavigation ?? false;
    $rootUrl = rtrim((string) config('app.url'), '/');
    $publicUrl = static fn (string $routeName, string $path = ''): string => $forceRootNavigation
        ? $rootUrl.($path === '' ? '/' : $path)
        : route($routeName);
@endphp

<header class="public-header">
    <div class="public-container public-header-inner">
        <a class="public-brand" href="{{ $publicUrl('public.home') }}">
            <span class="public-brand-mark">LF</span>
            <span>{{ __('lf.LF_common_brand_name') }}</span>
        </a>

        <nav class="public-nav" aria-label="{{ __('lf.LF_common_navigation_public_label') }}">
            <a class="public-nav-link {{ ! $forceRootNavigation && request()->routeIs('public.home') ? 'is-active' : '' }}"
               href="{{ $publicUrl('public.home') }}">{{ __('lf.LF_navigation_menu_public_home') }}</a>
            <a class="public-nav-link {{ ! $forceRootNavigation && request()->routeIs('public.features') ? 'is-active' : '' }}"
               href="{{ $publicUrl('public.features', '/features') }}">{{ __('lf.LF_navigation_menu_public_features') }}</a>
            <a class="public-nav-link {{ ! $forceRootNavigation && request()->routeIs('public.pricing') ? 'is-active' : '' }}"
               href="{{ $publicUrl('public.pricing', '/pricing') }}">{{ __('lf.LF_navigation_menu_public_pricing') }}</a>
            <a class="public-nav-link {{ ! $forceRootNavigation && request()->routeIs('public.services') ? 'is-active' : '' }}"
               href="{{ $publicUrl('public.services', '/services') }}">{{ __('lf.LF_navigation_menu_public_services') }}</a>
            <a class="public-nav-link {{ ! $forceRootNavigation && request()->routeIs('public.about') ? 'is-active' : '' }}"
               href="{{ $publicUrl('public.about', '/about') }}">{{ __('lf.LF_navigation_menu_public_about') }}</a>
            @include('partials.language-switcher', ['attributes' => new \Illuminate\View\ComponentAttributeBag([
                'class' => 'public-language-switcher',
            ]), 'action' => $forceRootNavigation ? $rootUrl.'/language' : null])
            <a class="public-nav-cta" href="{{ $publicUrl('customer.register', '/register-customer') }}">{{ __('lf.LF_home_public_register_tenant') }}</a>
        </nav>
    </div>
</header>
