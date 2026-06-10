<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RoleProfileController extends Controller
{
    public function editTeacher(): View
    {
        return $this->edit('teacher', 'teacher.profile.edit');
    }

    public function updateTeacher(Request $request): RedirectResponse
    {
        return $this->update($request, 'teacher', 'teacher.profile.edit');
    }

    public function updateTeacherPassword(Request $request): RedirectResponse
    {
        return $this->updatePassword($request, 'teacher', 'teacher.profile.edit');
    }

    public function editStudent(): View
    {
        return $this->edit('student', 'student.profile.edit');
    }

    public function updateStudent(Request $request): RedirectResponse
    {
        return $this->update($request, 'student', 'student.profile.edit');
    }

    public function updateStudentPassword(Request $request): RedirectResponse
    {
        return $this->updatePassword($request, 'student', 'student.profile.edit');
    }

    private function edit(string $role, string $view): View
    {
        $user = DB::table('users')
            ->where('id', auth()->id())
            ->where('customer_id', TenantContext::customerId())
            ->where('role', $role)
            ->first();

        abort_if(! $user, 404);

        return view($view, compact('user'));
    }

    private function update(Request $request, string $role, string $route): RedirectResponse
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

        DB::transaction(function () use ($validated, $userId, $customerId, $role): void {
            $user = DB::table('users')
                ->where('id', $userId)
                ->where('customer_id', $customerId)
                ->where('role', $role)
                ->lockForUpdate()
                ->first();

            abort_if(! $user, 404);

            $attributes = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'email_verified_at' => $validated['email'] !== $user->email
                    ? null
                    : $user->email_verified_at,
                'updated_at' => now(),
            ];

            DB::table('users')
                ->where('id', $userId)
                ->where('customer_id', $customerId)
                ->where('role', $role)
                ->update($attributes);
        });

        return redirect()
            ->route($route)
            ->with('profile_success', 'Profile updated successfully.');
    }

    private function updatePassword(Request $request, string $role, string $route): RedirectResponse
    {
        $userId = auth()->id();
        $customerId = TenantContext::customerId();

        abort_if(! $userId || ! $customerId, 404);

        $user = DB::table('users')
            ->where('id', $userId)
            ->where('customer_id', $customerId)
            ->where('role', $role)
            ->first();

        abort_if(! $user, 404);

        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => [
                'required',
                Password::defaults(),
                'confirmed',
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if (Hash::check($value, $user->password)) {
                        $fail('The new password must be different from the current password.');
                    }
                },
            ],
        ]);

        DB::transaction(function () use ($validated, $userId, $customerId, $role): void {
            $user = DB::table('users')
                ->where('id', $userId)
                ->where('customer_id', $customerId)
                ->where('role', $role)
                ->lockForUpdate()
                ->first();

            abort_if(! $user, 404);

            DB::table('users')
                ->where('id', $userId)
                ->where('customer_id', $customerId)
                ->where('role', $role)
                ->update([
                    'password' => Hash::make($validated['password']),
                    'updated_at' => now(),
                ]);
        });

        return redirect()
            ->route($route)
            ->with('password_success', 'Password changed successfully.');
    }
}
