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
        $host = $request->getHost();
        $baseDomain = config('app.base_domain', 'localhost');

        $customer = null;

        if ($host !== $baseDomain && $host !== 'www.'.$baseDomain) {
            $customer = DB::table('saas_customers')
                ->where('custom_domain', $host)
                ->where('status', 'active')
                ->first();

            if (! $customer && str_ends_with($host, '.'.$baseDomain)) {
                $subdomain = str_replace('.'.$baseDomain, '', $host);

                $customer = DB::table('saas_customers')
                    ->where('subdomain', $subdomain)
                    ->where('status', 'active')
                    ->first();
            }
        }

        TenantContext::set($customer);

        return $next($request);
    }
}
