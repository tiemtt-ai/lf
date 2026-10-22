<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view($request->ajax() ? 'admin.my-account.dialog' : 'admin.my-account.edit', [
            'user' => $this->admin(),
        ]);
    }

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $userId = $user?->id;
        $customerId = TenantContext::customerId();

        abort_if(
            ! $userId
            || ! $customerId
            || (int) $user->customer_id !== (int) $customerId,
            404
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
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
                    'phone' => $validated['phone'] ?? null,
                    'date_of_birth' => $validated['date_of_birth'] ?? null,
                    'gender' => $validated['gender'] ?? null,
                    'updated_at' => now(),
                ]);
        });

        if ($request->expectsJson()) {
            $message = __('lf.LF_admin_message_my_account_updated');
            session()->flash('success', $message);

            return response()->json(['message' => $message, 'redirect' => $request->input('return') === 'page' ? route('admin.my-account.edit') : route('admin.dashboard')]);
        }

        return redirect()
            ->route('admin.my-account.edit')
            ->with('profile_success', __('lf.LF_admin_message_my_account_updated'));
    }

    public function editPassword(Request $request): View
    {
        abort_unless($request->ajax(), 404);

        return view('partials.account.password-dialog', ['action' => route('admin.my-account.password.update')]);
    }

    public function updatePassword(Request $request): RedirectResponse|JsonResponse
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

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            $message = __('lf.LF_auth_message_password_changed_login_again');
            session()->flash('status', $message);

            return response()->json(['message' => $message, 'redirect' => route('login')]);
        }

        return redirect()
            ->route('login')
            ->with('status', __('lf.LF_auth_message_password_changed_login_again'));
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
