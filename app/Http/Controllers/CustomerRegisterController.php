<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomerRegisterController extends Controller
{
    public function show()
    {
        return view('auth.register-customer');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:saas_customers,slug'],
            'organization_type' => ['required', Rule::in([
                'training_center',
                'school',
                'corporate',
                'individual',
            ])],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $registration = DB::transaction(function () use ($request, $validated) {
            $slug = Str::lower($validated['slug']);

            $customerId = DB::table('saas_customers')->insertGetId([
                'name' => $validated['customer_name'],
                'slug' => $slug,
                'subdomain' => $slug,
                'custom_domain' => null,
                'phone' => $validated['phone'],
                'organization_type' => $validated['organization_type'],
                'theme_key' => 'default',
                'layout_key' => 'default',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $userId = DB::table('users')->insertGetId([
                'customer_id' => $customerId,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'password' => Hash::make($validated['password']),
                'role' => 'customer_admin',
                'status' => 'active',
                'email_verified_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $user = DB::table('users')->where('id', $userId)->first();

            AuditLog::record(
                $request,
                $customerId,
                'user.created',
                $userId,
                after: [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'date_of_birth' => $user->date_of_birth,
                    'gender' => $user->gender,
                    'role' => $user->role,
                    'status' => $user->status,
                    'email_verified_at' => $user->email_verified_at,
                ]
            );

            return [
                'slug' => $slug,
                'user_id' => $userId,
            ];
        });

        $user = User::findOrFail($registration['user_id']);

        event(new Registered($user));

        return redirect()->away($this->tenantUrl($registration['slug'], '/login'))
            ->with('status', 'Registration completed. Please verify your email before continuing.');
    }

    private function tenantUrl(string $slug, string $path = ''): string
    {
        $scheme = config('app.tenant_scheme', 'https');
        $baseDomain = config('app.base_domain');
        $port = parse_url(config('app.url'), PHP_URL_PORT);

        return sprintf(
            '%s://%s.%s%s%s',
            $scheme,
            $slug,
            $baseDomain,
            $port ? ':'.$port : '',
            '/'.ltrim($path, '/')
        );
    }
}
