<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        abort_if(! $customer, 404);

        TenantContext::set($customer);

        return $next($request);
    }
}
