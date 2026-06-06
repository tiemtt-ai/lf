<h1>Users</h1>

@if (session('success'))
    <div style="color: green;">
        {{ session('success') }}
    </div>
@endif

<p>
    <a href="{{ route('admin.users.create') }}">Create user</a>
</p>

<table border="1" cellpadding="8">
    <thead>
    <tr>
        <th>ID</th>
        <th>Customer ID</th>
        <th>Name</th>
        <th>Email</th>
        <th>Role</th>
        <th>Status</th>
        <th>Action</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($users as $user)
        <tr>
            <td>{{ $user->id }}</td>
            <td>{{ $user->customer_id }}</td>
            <td>{{ $user->name }}</td>
            <td>{{ $user->email }}</td>
            <td>{{ $user->role }}</td>
            <td>{{ $user->status }}</td>
            <td>
                <a href="{{ route('admin.users.edit', $user->id) }}">
                    Edit
                </a>

                <form method="POST"
                      action="{{ route('admin.users.toggle-status', $user->id) }}"
                      style="display:inline;">
                    @csrf

                    <button type="submit">
                        {{ $user->status == 'active'
                            ? 'Disable'
                            : 'Enable' }}
                    </button>
                </form>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>