<header class="lf-topbar">
    <div class="lf-topbar-inner">

        <a class="lf-logo" href="{{ route('public.home') }}">
            Master Korean | API Test
        </a>

        <nav class="lf-menu">
            <a href="{{ config('app.url') }}">Home</a>

            <a href="{{ config('app.url') . '/features' }}">Features</a>

            <a href="{{ config('app.url') . '/pricing' }}">Pricing</a>

            <a href="{{ config('app.url') . '/services' }}">Services</a>

            <a href="{{ config('app.url') . '/about' }}">About</a>

            <a href="{{ config('app.url') . '/register-customer' }}">
                Register Tenant
            </a>
        </nav>

    </div>
</header>