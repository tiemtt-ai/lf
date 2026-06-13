<header class="public-header">
    <div class="public-container public-header-inner">
        <a class="public-brand" href="{{ route('public.home') }}">
            <span class="public-brand-mark">LF</span>
            <span>LearnForge</span>
        </a>

        <nav class="public-nav" aria-label="Public navigation">
            <a class="public-nav-link {{ request()->routeIs('public.home') ? 'is-active' : '' }}"
               href="{{ route('public.home') }}">Home</a>
            <a class="public-nav-link {{ request()->routeIs('public.features') ? 'is-active' : '' }}"
               href="{{ route('public.features') }}">Features</a>
            <a class="public-nav-link {{ request()->routeIs('public.pricing') ? 'is-active' : '' }}"
               href="{{ route('public.pricing') }}">Pricing</a>
            <a class="public-nav-link {{ request()->routeIs('public.services') ? 'is-active' : '' }}"
               href="{{ route('public.services') }}">Services</a>
            <a class="public-nav-link {{ request()->routeIs('public.about') ? 'is-active' : '' }}"
               href="{{ route('public.about') }}">About</a>
            <a class="public-nav-cta" href="{{ route('customer.register') }}">Register Tenant</a>
        </nav>
    </div>
</header>
