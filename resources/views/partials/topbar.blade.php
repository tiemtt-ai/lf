<header class="lf-header">
    <div class="lf-container" style="display:flex;justify-content:space-between;align-items:center;">
        <a href="{{ url('/') }}" class="lf-brand">LF</a>

        <nav style="display:flex;gap:16px;align-items:center;">
            <a href="{{ url('/') }}">Home</a>

            @auth
                <a href="{{ route('dashboard') }}">Dashboard</a>
                <a href="{{ route('profile.edit') }}">Profile</a>

                <span>{{ Auth::user()->name }}</span>

                <form method="POST" action="{{ route('logout') }}" style="display:inline;">
                    @csrf
                    <button type="submit" style="background:none;border:0;cursor:pointer;padding:0;font:inherit;">
                        Logout
                    </button>
                </form>
            @else
                <a href="{{ route('login') }}">Login</a>
                <a href="{{ route('register') }}">Register</a>
            @endauth
        </nav>
    </div>
</header>