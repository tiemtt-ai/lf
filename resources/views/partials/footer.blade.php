@php
    $forceRootNavigation = $forceRootNavigation ?? false;
    $rootUrl = rtrim((string) config('app.url'), '/');
    $footerUrl = static fn (string $routeName, string $path): string => $forceRootNavigation
        ? $rootUrl.$path
        : route($routeName);
@endphp

<footer class="public-footer">
    <div class="public-container public-footer-inner">
        <div>
            <p class="public-footer-brand">{{ __('lf.LF_common_brand_name') }}</p>
            <p>{{ __('lf.LF_common_footer_common_platform') }}</p>
            <p>© {{ date('Y') }} {{ __('lf.LF_common_brand_name') }}. {{ __('lf.LF_common_footer_common_rights') }}</p>
        </div>

        <nav class="public-footer-links" aria-label="{{ __('lf.LF_common_navigation_common_footer') }}">
            <a href="{{ $footerUrl('public.features', '/features') }}">{{ __('lf.LF_navigation_menu_public_features') }}</a>
            <a href="{{ $footerUrl('public.pricing', '/pricing') }}">{{ __('lf.LF_navigation_menu_public_pricing') }}</a>
            <a href="{{ $footerUrl('public.about', '/about') }}">{{ __('lf.LF_navigation_menu_public_about') }}</a>
        </nav>
    </div>
</footer>
