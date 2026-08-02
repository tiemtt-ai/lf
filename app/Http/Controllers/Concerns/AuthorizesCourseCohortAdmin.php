<?php

namespace App\Http\Controllers\Concerns;

use App\Support\TenantContext;
use Illuminate\Http\Request;

trait AuthorizesCourseCohortAdmin
{
    protected function customerId(): int
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        return $customerId;
    }

    protected function authorizeAdmin(Request $request): int
    {
        abort_unless($request->user()?->role === 'customer_admin', 403);

        return $this->customerId();
    }

    protected function routePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => $this->cohortRoutePrefix,
            default => abort(403),
        };
    }
}
