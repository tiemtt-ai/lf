<h1>Create User</h1>

@if ($errors->any())
    <div style="color: red;">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

<form method="POST" action="{{ route('admin.users.store') }}">
    @csrf

    <div>
        <label>Name</label><br>
        <input type="text" name="name" value="{{ old('name') }}">
    </div>

    <div>
        <label>Email</label><br>
        <input type="email" name="email" value="{{ old('email') }}">
    </div>

    <div>
        <label>Role</label><br>
        <select name="role">
            <option value="teacher">Teacher</option>
            <option value="student">Student</option>
        </select>
    </div>

    <div>
        <label>Password</label><br>
        <input type="password" name="password">
    </div>

    <div>
        <label>Confirm Password</label><br>
        <input type="password" name="password_confirmation">
    </div>

    <br>

    <button type="submit">Create</button>
</form>