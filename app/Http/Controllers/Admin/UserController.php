<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\TenantContext;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

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

        $customerId = TenantContext::customerId();

        DB::transaction(function () use ($request, $customerId): void {
            $userId = DB::table('users')->insertGetId([
                'customer_id' => $customerId,
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

            $user = DB::table('users')->where('id', $userId)->first();

            AuditLog::record(
                $request,
                $customerId,
                'user.created',
                $userId,
                after: $this->auditFields($user)
            );
        });

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
        $request->validate([
            'name' => ['required'],
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female,other'],
            'role' => ['required', 'in:customer_admin,teacher,student'],
        ]);

        $customerId = TenantContext::customerId();

        $result = DB::transaction(function () use ($request, $id, $customerId): array {
            $this->lockTenant($customerId);

            $user = DB::table('users')
                ->where('customer_id', $customerId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            abort_if(! $user, 404);

            if (
                $request->role !== 'customer_admin'
                && $this->isLastActiveCustomerAdmin($user, $customerId)
            ) {
                return ['blocked' => true, 'email_changed' => false];
            }

            $before = $this->auditFields($user);
            $emailChanged = $request->email !== $user->email;

            DB::table('users')
                ->where('id', $id)
                ->where('customer_id', $customerId)
                ->update([
                    'name' => $request->name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'date_of_birth' => $request->date_of_birth,
                    'gender' => $request->gender,
                    'role' => $request->role,
                    'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
                    'updated_at' => now(),
                ]);

            $updatedUser = DB::table('users')
                ->where('id', $id)
                ->where('customer_id', $customerId)
                ->first();
            $after = $this->auditFields($updatedUser);

            AuditLog::record(
                $request,
                $customerId,
                'user.updated',
                (int) $id,
                $before,
                $after
            );

            if ($user->role !== $updatedUser->role) {
                AuditLog::record(
                    $request,
                    $customerId,
                    'user.role_changed',
                    (int) $id,
                    ['role' => $user->role],
                    ['role' => $updatedUser->role]
                );
            }

            return ['blocked' => false, 'email_changed' => $emailChanged];
        });

        if ($result['blocked']) {
            return back()
                ->withErrors(['role' => 'This tenant must have at least one active customer admin.'])
                ->withInput();
        }

        if ($result['email_changed']) {
            $updatedUser = User::query()
                ->where('customer_id', $customerId)
                ->findOrFail($id);

            if ($updatedUser instanceof MustVerifyEmail) {
                $updatedUser->sendEmailVerificationNotification();
            }
        }

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User updated successfully.');
    }

    public function toggleStatus(Request $request, $id)
    {
        $customerId = TenantContext::customerId();

        $blocked = DB::transaction(function () use ($request, $id, $customerId): bool {
            $this->lockTenant($customerId);

            $user = DB::table('users')
                ->where('customer_id', $customerId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            abort_if(! $user, 404);

            $newStatus = $user->status === 'active'
                ? 'inactive'
                : 'active';

            if (
                $newStatus === 'inactive'
                && $this->isLastActiveCustomerAdmin($user, $customerId)
            ) {
                return true;
            }

            DB::table('users')
                ->where('id', $id)
                ->where('customer_id', $customerId)
                ->update([
                    'status' => $newStatus,
                    'updated_at' => now(),
                ]);

            AuditLog::record(
                $request,
                $customerId,
                'user.status_toggled',
                (int) $id,
                ['status' => $user->status],
                ['status' => $newStatus]
            );

            return false;
        });

        if ($blocked) {
            return back()
                ->withErrors(['status' => 'This tenant must have at least one active customer admin.']);
        }

        return back()
            ->with('success', 'Status updated.');
    }

    private function lockTenant(?int $customerId): void
    {
        abort_if(! $customerId, 404);

        $tenant = DB::table('saas_customers')
            ->where('id', $customerId)
            ->lockForUpdate()
            ->first();

        abort_if(! $tenant, 404);
    }

    private function isLastActiveCustomerAdmin(object $user, int $customerId): bool
    {
        if ($user->role !== 'customer_admin' || $user->status !== 'active') {
            return false;
        }

        return DB::table('users')
            ->where('customer_id', $customerId)
            ->where('role', 'customer_admin')
            ->where('status', 'active')
            ->count() <= 1;
    }

    private function auditFields(object $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'date_of_birth' => $user->date_of_birth,
            'gender' => $user->gender,
            'role' => $user->role,
            'status' => $user->status,
            'email_verified_at' => $user->email_verified_at,
        ];
    }
}
