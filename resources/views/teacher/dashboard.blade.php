<h1>LF Teacher Area</h1>

<p>
    Welcome Teacher
</p>

<form method="POST" action="{{ route('logout') }}">
    @csrf
    <button type="submit">
        Logout
    </button>
</form>