<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Support\TenantContext;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        /*
        |--------------------------------------------------------------------------
        | Domain gốc không được login
        |--------------------------------------------------------------------------
        */
        if (TenantContext::customerId() === null) {
            abort(404);
        }

        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Tenant Check
        |--------------------------------------------------------------------------
        */
        if (
            TenantContext::customerId() !== null &&
            $user->customer_id != TenantContext::customerId()
        ) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                'email' => 'This account does not belong to this tenant.',
            ]);
        }

        return match ($user->role) {
            'customer_admin' => redirect()->intended('/admin'),
            'teacher'        => redirect()->intended('/teacher'),
            'student'        => redirect()->intended('/student'),
            default          => redirect()->intended('/dashboard'),
        };
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
