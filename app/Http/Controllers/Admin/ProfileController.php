<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('admin.profile.edit', [
            'user' => $this->admin(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $userId = auth()->id();
        $customerId = TenantContext::customerId();

        abort_if(! $userId || ! $customerId, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female,other'],
        ]);

        DB::transaction(function () use ($validated, $userId, $customerId): void {
            $user = DB::table('users')
                ->where('id', $userId)
                ->where('customer_id', $customerId)
                ->where('role', 'customer_admin')
                ->lockForUpdate()
                ->first();

            abort_if(! $user, 404);

            DB::table('users')
                ->where('id', $userId)
                ->where('customer_id', $customerId)
                ->where('role', 'customer_admin')
                ->update([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? null,
                    'date_of_birth' => $validated['date_of_birth'] ?? null,
                    'gender' => $validated['gender'] ?? null,
                    'email_verified_at' => $validated['email'] !== $user->email
                        ? null
                        : $user->email_verified_at,
                    'updated_at' => now(),
                ]);
        });

        return redirect()
            ->route('admin.profile.edit')
            ->with('profile_success', 'Profile updated successfully.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $this->admin();

        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => [
                'required',
                Password::defaults(),
                'confirmed',
                function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                    if (Hash::check($value, $user->password)) {
                        $fail('The new password must be different from the current password.');
                    }
                },
            ],
        ]);

        DB::transaction(function () use ($validated): void {
            $user = DB::table('users')
                ->where('id', auth()->id())
                ->where('customer_id', TenantContext::customerId())
                ->where('role', 'customer_admin')
                ->lockForUpdate()
                ->first();

            abort_if(! $user, 404);

            DB::table('users')
                ->where('id', auth()->id())
                ->where('customer_id', TenantContext::customerId())
                ->where('role', 'customer_admin')
                ->update([
                    'password' => Hash::make($validated['password']),
                    'updated_at' => now(),
                ]);
        });

        return redirect()
            ->route('admin.profile.edit')
            ->with('password_success', 'Password changed successfully.');
    }

    private function admin(): object
    {
        $user = DB::table('users')
            ->where('id', auth()->id())
            ->where('customer_id', TenantContext::customerId())
            ->where('role', 'customer_admin')
            ->first();

        abort_if(! $user, 404);

        return $user;
    }
}
