<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'LearnForge')</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>

<div class="lf-dashboard">

    <aside class="lf-sidebar">

        <div class="lf-sidebar-brand">
            LearnForge
        </div>

        <nav class="lf-sidebar-menu">

            @if(auth()->user()?->role === 'customer_admin')

                <a href="{{ route('admin.dashboard') }}">
                    Dashboard
                </a>

                <a href="{{ route('admin.users.index') }}">
                    Users
                </a>

            @endif

            @if(auth()->user()?->role === 'teacher')

                <a href="{{ route('teacher.dashboard') }}">
                    Dashboard
                </a>

            @endif

            @if(auth()->user()?->role === 'student')

                <a href="{{ route('student.dashboard') }}">
                    Dashboard
                </a>

            @endif

        </nav>

    </aside>

    <div class="lf-dashboard-content">

        <header class="lf-dashboard-header">

            <div>
                @yield('page_title', 'Dashboard')
            </div>

            <div>

                <span style="margin-right:15px;">
                    {{ auth()->user()->name }}
                </span>

                <form method="POST"
                      action="{{ route('logout') }}"
                      style="display:inline;">
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