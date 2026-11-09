<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\LiveClassAttendanceCheckInController;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\LoginExperience;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): View
    {
        if (TenantContext::customerId() === null) {
            abort(404);
        }

        return view(LoginExperience::isStudent($request) ? 'auth.student-login' : 'auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        if ($user->status !== 'active') {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                'email' => 'User is not active.',
            ]);
        }

        if ($user->customer_id != TenantContext::customerId()) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                'email' => 'This account does not belong to this tenant.',
            ]);
        }

        if (! in_array($user->role, ['customer_admin', 'teacher', 'student'], true)) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, 'You do not have permission to access this application.');
        }

        if (! LoginExperience::acceptsRole($request, $user->role)) {
            $studentExperience = LoginExperience::isStudent($request);

            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Credentials were valid, so this is guidance rather than a field error.
            return redirect()
                ->to($studentExperience ? LoginExperience::studentUrl() : route('login'))
                ->withInput($request->only('email'))
                ->with('login_role_notice', [
                    'account' => $user->role === 'student' ? 'student' : 'backoffice',
                    'url' => LoginExperience::urlForRole($user->role),
                ]);
        }

        return match ($user->role) {
            'customer_admin' => redirect()->route('admin.dashboard'),
            'teacher' => redirect()->route('teacher.dashboard'),
            // A phone that opened the attendance QR link before signing in goes back to it.
            'student' => redirect()->to($this->studentReturn($request) ?? '/'),
        };
    }

    /** Only the check-in page may be returned to, and only on the tenant that set it. */
    private function studentReturn(Request $request): ?string
    {
        $url = $request->session()->pull(LiveClassAttendanceCheckInController::RETURN_TO);
        $path = is_string($url) ? (string) parse_url($url, PHP_URL_PATH) : '';
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        return preg_match('#^/my-courses/live-sessions/\d+/check-in$#', $path) && $host === $request->getHost() ? $url : null;
    }

    public function destroy(Request $request): RedirectResponse
    {
        $role = $request->user()?->role;

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (TenantContext::customerId() !== null) {
            return redirect()->to(LoginExperience::urlForRole($role));
        }

        return redirect()->route('public.home');
    }
}
