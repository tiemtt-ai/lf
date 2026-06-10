<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Support\AuditLog;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $customerId = TenantContext::customerId();

        $blocked = DB::transaction(function () use ($request, $user, $customerId): bool {
            abort_if(! $customerId, 404);

            $tenant = DB::table('saas_customers')
                ->where('id', $customerId)
                ->lockForUpdate()
                ->first();

            abort_if(! $tenant, 404);

            $lockedUser = DB::table('users')
                ->where('id', $user->id)
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first();

            abort_if(! $lockedUser, 404);

            if ($this->isLastActiveCustomerAdmin($lockedUser, $customerId)) {
                return true;
            }

            $before = $this->auditFields($lockedUser);

            AuditLog::record(
                $request,
                $customerId,
                'user.deleted',
                (int) $lockedUser->id,
                before: $before
            );

            AuditLog::record(
                $request,
                $customerId,
                'profile.deleted',
                (int) $lockedUser->id,
                before: $before
            );

            DB::table('users')
                ->where('id', $lockedUser->id)
                ->where('customer_id', $customerId)
                ->delete();

            return false;
        });

        if ($blocked) {
            return back()->withErrors([
                'password' => 'This tenant must have at least one active customer admin.',
            ], 'userDeletion');
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    private function isLastActiveCustomerAdmin($user, ?int $customerId): bool
    {
        if (
            ! $customerId ||
            $user->role !== 'customer_admin' ||
            $user->status !== 'active'
        ) {
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
