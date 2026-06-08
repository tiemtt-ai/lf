<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        abort_if(TenantContext::customerId() === null, 404);

        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $customerId = TenantContext::customerId();

        abort_if($customerId === null, 404);

        Password::sendResetLink([
            'email' => $request->email,
            'customer_id' => $customerId,
            'status' => 'active',
        ]);

        return back()->with('status', __(Password::RESET_LINK_SENT));
    }
}
