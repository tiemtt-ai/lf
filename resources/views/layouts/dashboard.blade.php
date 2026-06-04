<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>

<div class="lf-dashboard">

    <aside class="lf-sidebar">
        <div class="lf-sidebar-brand">
            LF
        </div>

        <nav class="lf-sidebar-menu">
            <a href="{{ route('dashboard') }}">Dashboard</a>
            <a href="{{ route('profile.edit') }}">Profile</a>
        </nav>
    </aside>

    <div class="lf-dashboard-content">

        <header class="lf-dashboard-header">
            <div>
                Dashboard
            </div>

            <div style="display:flex;gap:16px;align-items:center;">
                <span>{{ Auth::user()->name }}</span>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">
                        Logout
                    </button>
                </form>
            </div>
        </header>

        <main class="lf-dashboard-main">
            @yield('content')
        </main>

    </div>

</div>

</body>
</html>
