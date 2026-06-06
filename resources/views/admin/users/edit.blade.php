<h1>Edit User</h1>

<form method="POST"
      action="{{ route('admin.users.update', $user->id) }}">
    @csrf
    @method('PUT')

    <div>
        <label>Name</label><br>
        <input type="text"
               name="name"
               value="{{ $user->name }}">
    </div>

    <div>
        <label>Email</label><br>
        <input type="email"
               name="email"
               value="{{ $user->email }}">
    </div>

    <div>
        <label>Role</label><br>
        <select name="role">
            <option value="customer_admin"
                    @selected($user->role=='customer_admin')>
                Customer Admin
            </option>

            <option value="teacher"
                    @selected($user->role=='teacher')>
                Teacher
            </option>

            <option value="student"
                    @selected($user->role=='student')>
                Student
            </option>
        </select>
    </div>

    <br>

    <button type="submit">
        Save
    </button>
</form>