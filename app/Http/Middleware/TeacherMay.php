<?php

namespace App\Http\Middleware;

use App\Services\TeacherPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `teacher.may:key[,key…]` (ADR-0033 D6): the request passes when the user holds one of the keys. Admins always pass,
 * so the routes shared by the admin and teacher areas carry it unchanged.
 */
class TeacherMay
{
    public function __construct(private readonly TeacherPermissions $permissions) {}

    public function handle(Request $request, Closure $next, string ...$keys): Response
    {
        abort_unless($this->permissions->may($request->user(), ...$keys), 403, __('lf.LF_teacher_permission_denied'));

        return $next($request);
    }
}
