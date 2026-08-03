<?php

use App\Http\Middleware\RequireRootDomain;
use App\Http\Middleware\RequireTenantUser;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SetLocale;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        $exceptions->render(function (QueryException $exception, Request $request) {
            $isEnrollmentBindingTrigger = ($exception->errorInfo[0] ?? null) === '45000'
                && str_contains(
                    $exception->getMessage(),
                    'LF_ENROLLMENT_BINDING_IMMUTABLE:trg_core_course_enrollments_binding_immutable_bu'
                );
            if (! $isEnrollmentBindingTrigger) {
                return null;
            }

            $message = __('lf.LF_course_enrollment_validation_binding_immutable');

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'errors' => ['binding' => [$message]]], 422)
                : back()->withInput()->withErrors(['binding' => $message]);
        });
    })->create();
