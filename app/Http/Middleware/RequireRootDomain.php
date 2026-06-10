<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRootDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $baseDomain = strtolower((string) config('app.base_domain'));

        abort_unless(
            $baseDomain !== ''
            && ($host === $baseDomain || $host === 'www.'.$baseDomain),
            404
        );

        return $next($request);
    }
}
