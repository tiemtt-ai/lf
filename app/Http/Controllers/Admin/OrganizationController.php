<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function edit(Request $request): View
    {
        // The dashboard opens this form as a UI Kit popup (XHR); the full page stays for direct visits.
        return view($request->ajax() ? 'admin.organization.dialog' : 'admin.organization.edit', [
            'tenant' => $this->tenant(),
        ]);
    }

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'lowercase', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        DB::transaction(function () use ($customerId, $validated): void {
            $tenant = DB::table('saas_customers')
                ->where('id', $customerId)
                ->lockForUpdate()
                ->first();

            abort_if(! $tenant, 404);

            DB::table('saas_customers')
                ->where('id', $customerId)
                ->update([
                    'name' => $validated['name'],
                    'email' => $validated['email'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                    'updated_at' => now(),
                ]);
        });

        if ($request->expectsJson()) {
            $message = __('lf.LF_admin_message_organization_updated');
            session()->flash('success', $message);

            return response()->json(['message' => $message, 'redirect' => route('admin.dashboard')]);
        }

        return redirect()
            ->route('admin.organization.edit')
            ->with('organization_success', __('lf.LF_admin_message_organization_updated'));
    }

    private function tenant(): object
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        $tenant = DB::table('saas_customers')
            ->where('id', $customerId)
            ->first();

        abort_if(! $tenant, 404);

        return $tenant;
    }
}
