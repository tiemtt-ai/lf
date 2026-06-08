<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $role = $request->role ?? 'customer_admin';

        $validRoles = [
            'customer_admin',
            'teacher',
            'student',
        ];

        if (! in_array($role, $validRoles)) {
            $role = 'customer_admin';
        }

        $users = DB::table('users')
            ->where('customer_id', TenantContext::customerId())
            ->where('role', $role)
            ->orderBy('name')
            ->get();

        return view('admin.users.index', [
            'users' => $users,
            'role' => $role,
        ]);
    }

    public function create()
    {
        return view('admin.users.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female,other'],
            'password' => ['required', 'confirmed', 'min:8'],
            'role' => ['required', 'in:teacher,student'],
        ]);

        DB::table('users')->insert([
            'customer_id' => TenantContext::customerId(),
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'date_of_birth' => $request->date_of_birth,
            'gender' => $request->gender,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'status' => 'active',
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User created successfully.');
    }

    public function edit($id)
    {
        $user = DB::table('users')
            ->where('customer_id', TenantContext::customerId())
            ->where('id', $id)
            ->first();

        abort_if(! $user, 404);

        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, $id)
    {
        $user = DB::table('users')
            ->where('customer_id', TenantContext::customerId())
            ->where('id', $id)
            ->first();

        abort_if(! $user, 404);

        $request->validate([
            'name' => ['required'],
            'email' => ['required', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female,other'],
            'role' => ['required', 'in:customer_admin,teacher,student'],
        ]);

        if ($request->role !== 'customer_admin' && $this->isLastActiveCustomerAdmin($user)) {
            return back()
                ->withErrors(['role' => 'This tenant must have at least one active customer admin.'])
                ->withInput();
        }

        DB::table('users')
            ->where('id', $id)
            ->where('customer_id', TenantContext::customerId())
            ->update([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'date_of_birth' => $request->date_of_birth,
                'gender' => $request->gender,
                'role' => $request->role,
                'updated_at' => now(),
            ]);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User updated successfully.');
    }

    public function toggleStatus($id)
    {
        $user = DB::table('users')
            ->where('customer_id', TenantContext::customerId())
            ->where('id', $id)
            ->first();

        abort_if(! $user, 404);

        $newStatus = $user->status === 'active'
            ? 'inactive'
            : 'active';

        if ($newStatus === 'inactive' && $this->isLastActiveCustomerAdmin($user)) {
            return back()
                ->withErrors(['status' => 'This tenant must have at least one active customer admin.']);
        }

        DB::table('users')
            ->where('id', $id)
            ->where('customer_id', TenantContext::customerId())
            ->update([
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

        return back()
            ->with('success', 'Status updated.');
    }

    private function isLastActiveCustomerAdmin(object $user): bool
    {
        if ($user->role !== 'customer_admin' || $user->status !== 'active') {
            return false;
        }

        return DB::table('users')
            ->where('customer_id', TenantContext::customerId())
            ->where('role', 'customer_admin')
            ->where('status', 'active')
            ->count() <= 1;
    }
}
