<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = rtrim(strtolower($request->getHost()), '.');
        $baseDomain = rtrim(strtolower(trim(
            (string) config('app.base_domain', 'localhost')
        )), '.');

        TenantContext::set(null);

        abort_if($baseDomain === '', 404);

        if ($host === $baseDomain || $host === 'www.'.$baseDomain) {
            return $next($request);
        }

        $customer = DB::table('saas_customers')
            ->where('custom_domain', $host)
            ->where('status', 'active')
            ->first();

        if (! $customer && str_ends_with($host, '.'.$baseDomain)) {
            $subdomain = substr($host, 0, -strlen('.'.$baseDomain));

            $customer = DB::table('saas_customers')
                ->where('subdomain', $subdomain)
                ->where('status', 'active')
                ->first();
        }

        if (! $customer) {
            // The 404 view for an unresolved tenant deliberately renders the
            // root public site (see force_root_navigation in
            // resources/views/errors/404.blade.php) so hand-written nav
            // links never leak the invalid subdomain. That Blade guard only
            // covers <a>/<form> tags it builds itself — it does not reach
            // @vite()'s asset tags or the asset() helper, which both fall
            // back to the current request's host by default. Forcing the
            // root here makes every URL/asset generator agree with the same
            // root the Blade guard already uses, for this response only.
            URL::forceRootUrl(rtrim((string) config('app.url'), '/'));

            abort(404);
        }

        TenantContext::set($customer);

        return $next($request);
    }
}
