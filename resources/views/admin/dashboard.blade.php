<h1>LF Admin Area</h1>

<ul>
    <li>
        <a href="{{ route('admin.users.index') }}">
            User Management
        </a>
    </li>
</ul>

<form method="POST" action="{{ route('logout') }}">
    @csrf
    <button type="submit">Logout</button>
</form>