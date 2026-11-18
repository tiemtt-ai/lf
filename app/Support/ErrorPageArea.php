<?php

namespace App\Support;

use Illuminate\Http\Request;
use Throwable;

/**
 * Which shell an error page (404, 403) is drawn in (Owner 2026-11-18):
 * - root: no tenant was resolved, so the LearnForge public site;
 * - backend: an active admin or teacher of this tenant inside /admin or /teacher;
 * - tenant: everyone else on a tenant host, in the tenant's own website.
 */
final class ErrorPageArea
{
    public const ROOT = 'root';

    public const TENANT = 'tenant';

    public const BACKEND = 'backend';

    private const STAFF_ROLES = ['customer_admin', 'teacher'];

    public static function current(?Request $request = null): string
    {
        $request ??= request();
        $tenant = TenantContext::customer();

        if (! $tenant) {
            return self::ROOT;
        }

        $user = self::user($request);
        $isStaff = $user
            && (int) $user->customer_id === (int) $tenant->id
            && $user->status === 'active'
            && in_array($user->role, self::STAFF_ROLES, true);

        return $isStaff && $request->is('admin', 'admin/*', 'teacher', 'teacher/*')
            ? self::BACKEND
            : self::TENANT;
    }

    public static function dashboardRoute(?object $user): string
    {
        return $user?->role === 'teacher' ? 'teacher.dashboard' : 'admin.dashboard';
    }

    private static function user(Request $request): ?object
    {
        // An error raised before the session started has no signed-in user to read.
        try {
            return $request->user();
        } catch (Throwable) {
            return null;
        }
    }
}
