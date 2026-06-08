<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
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

        if ($this->isLastActiveCustomerAdmin($user, $customerId)) {
            return back()->withErrors([
                'password' => 'This tenant must have at least one active customer admin.',
            ], 'userDeletion');
        }

        Auth::logout();

        DB::table('users')
            ->where('id', $user->id)
            ->where('customer_id', $customerId)
            ->delete();

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
}
