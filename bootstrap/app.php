<?php

use App\Http\Middleware\RequireRootDomain;
use App\Http\Middleware\RequireTenantUser;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'tenant', 'auth', 'verified', 'tenant.user']]
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('web', SetLocale::class);

        $middleware->alias([
            'root.domain' => RequireRootDomain::class,
            'tenant' => ResolveTenant::class,
            'tenant.user' => RequireTenantUser::class,
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
