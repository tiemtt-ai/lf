<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * One /login route serves two presentations (Guardrails: single login screen).
 * `?experience=student` is the tenant-website variant and accepts only students;
 * the default variant is the back office entry and accepts only customer_admin/teacher.
 */
final class LoginExperience
{
    public const STUDENT = 'student';

    public static function isStudent(Request $request): bool
    {
        return $request->query('experience') === self::STUDENT;
    }

    public static function acceptsRole(Request $request, ?string $role): bool
    {
        return self::isStudent($request) === ($role === 'student');
    }

    public static function urlForRole(?string $role): string
    {
        return $role === 'student' ? self::studentUrl() : route('login');
    }

    public static function studentUrl(): string
    {
        return route('login', ['experience' => self::STUDENT]);
    }
}
