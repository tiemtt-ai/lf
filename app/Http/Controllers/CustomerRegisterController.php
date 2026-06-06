<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CustomerRegisterController extends Controller
{
    public function show()
    {
        return view('auth.register-customer');
    }

    public function store(Request $request)
    {
        $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:saas_customers,slug'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $customer = DB::transaction(function () use ($request) {
            $customerId = DB::table('saas_customers')->insertGetId([
                'name' => $request->customer_name,
                'slug' => Str::lower($request->slug),
                'subdomain' => Str::lower($request->slug),
                'custom_domain' => null,
                'theme_key' => 'default',
                'layout_key' => 'default',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $userId = DB::table('users')->insertGetId([
                'customer_id' => $customerId,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'customer_admin',
                'status' => 'active',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'id' => $customerId,
                'slug' => Str::lower($request->slug),
                'user_id' => $userId,
            ];
        });

        Auth::loginUsingId($customer['user_id']);

        return redirect()->away(
            'http://' . $customer['slug'] . '.localhost:8000/admin'
        );
    }
}