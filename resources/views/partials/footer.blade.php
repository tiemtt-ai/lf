<footer class="public-footer">
    <div class="public-container public-footer-inner">
        <div>
            <p class="public-footer-brand">{{ __('lf.LF_common_brand_name') }}</p>
            <p>{{ __('lf.LF_common_footer_common_platform') }}</p>
            <p>© {{ date('Y') }} {{ __('lf.LF_common_brand_name') }}. {{ __('lf.LF_common_footer_common_rights') }}</p>
        </div>

        <nav class="public-footer-links" aria-label="{{ __('lf.LF_common_navigation_common_footer') }}">
            <a href="{{ route('public.features') }}">{{ __('lf.LF_navigation_menu_public_features') }}</a>
            <a href="{{ route('public.pricing') }}">{{ __('lf.LF_navigation_menu_public_pricing') }}</a>
            <a href="{{ route('public.about') }}">{{ __('lf.LF_navigation_menu_public_about') }}</a>
        </nav>
    </div>
</footer>
