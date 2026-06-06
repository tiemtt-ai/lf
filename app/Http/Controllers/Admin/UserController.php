<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $users = DB::table('users')
            ->where('customer_id', TenantContext::customerId())
            ->orderBy('id', 'desc')
            ->get();

        return view('admin.users.index', compact('users'));
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
            'password' => ['required', 'confirmed', 'min:8'],
            'role' => ['required', 'in:teacher,student'],
        ]);

        DB::table('users')->insert([
            'customer_id' => TenantContext::customerId(),
            'name' => $request->name,
            'email' => $request->email,
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

        abort_if(!$user, 404);

        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, $id)
    {
        $user = DB::table('users')
            ->where('customer_id', TenantContext::customerId())
            ->where('id', $id)
            ->first();

        abort_if(!$user, 404);

        $request->validate([
            'name' => ['required'],
            'email' => ['required', 'email'],
            'role' => ['required', 'in:customer_admin,teacher,student'],
        ]);

        DB::table('users')
            ->where('id', $id)
            ->update([
                'name' => $request->name,
                'email' => $request->email,
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

        abort_if(!$user, 404);

        $newStatus = $user->status === 'active'
            ? 'inactive'
            : 'active';

        DB::table('users')
            ->where('id', $id)
            ->update([
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

        return back()
            ->with('success', 'Status updated.');
    }
}