<header class="lf-topbar">
    <div class="lf-topbar-inner">

        <a class="lf-logo" href="{{ route('public.home') }}">
            LearnForge
        </a>

        <nav class="lf-menu">
            <a href="{{ route('public.home') }}">Home</a>
            <a href="{{ route('public.features') }}">Features</a>
            <a href="{{ route('public.pricing') }}">Pricing</a>
            <a href="{{ route('public.services') }}">Services</a>
            <a href="{{ route('public.about') }}">About</a>

            <a class="lf-menu-button" href="{{ route('customer.register') }}">
                Register Customer
            </a>
        </nav>

    </div>
</header>