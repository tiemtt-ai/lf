<h1>LF Student Area</h1>

<p>
    Welcome Student
</p>

<form method="POST" action="{{ route('logout') }}">
    @csrf
    <button type="submit">
        Logout
    </button>
</form>