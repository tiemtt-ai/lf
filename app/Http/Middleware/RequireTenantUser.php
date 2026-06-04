<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        $tenant = app(TenantContext::class)->customer();

        if (!$tenant) {
            abort(404, 'Tenant not found.');
        }

        if ((int) $user->customer_id !== (int) $tenant->id) {
            abort(403, 'User does not belong to this tenant.');
        }

        if ($user->status !== 'active') {
            abort(403, 'User is not active.');
        }

        return $next($request);
    }
}