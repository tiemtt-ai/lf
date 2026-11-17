<?php

namespace App\Http\Controllers\Concerns;

use App\Services\TeacherPermissions;
use App\Support\TenantContext;
use Illuminate\Http\Request;

trait AuthorizesCourseCohortAdmin
{
    protected function customerId(): int
    {
        $customerId = TenantContext::customerId();

        abort_if(! $customerId, 404);

        return $customerId;
    }

    protected function authorizeAdmin(Request $request): int
    {
        abort_unless($request->user()?->role === 'customer_admin', 403);

        return $this->customerId();
    }

    /**
     * The class pages shared with teachers (ADR-0033 D7): an admin, or a teacher holding `$key` who is the Cohort's
     * active primary teacher. A teacher only reaches them through the teacher area.
     */
    protected function authorizeCohortManager(Request $request, int $cohortId, string $key = 'class.manage'): int
    {
        $customerId = $this->customerId();
        abort_unless(app(TeacherPermissions::class)->managesCohortFromRoute($request->user(), $cohortId, $request->route()?->getName(), $key), 403);

        return $customerId;
    }

    /** Creating a class: an admin, or a teacher holding `class.manage` (ADR-0033 D7). */
    protected function authorizeCohortCreator(Request $request): int
    {
        $customerId = $this->customerId();
        if ($request->user()?->role === 'customer_admin') {
            return $customerId;
        }
        abort_unless($this->inTeacherCohortArea() && app(TeacherPermissions::class)->may($request->user(), 'class.manage'), 403);

        return $customerId;
    }

    /** Whether the request is a teacher's (`teacher.course-cohorts.*`), so the admin-only parts stay out. */
    protected function inTeacherCohortArea(): bool
    {
        return TeacherPermissions::isTeacherCohortRoute(request()->route()?->getName());
    }

    /** Route name prefix of the class pages in the area of the request. */
    protected function cohortArea(): string
    {
        return $this->inTeacherCohortArea() ? 'teacher.course-cohorts' : 'admin.course-cohorts';
    }

    protected function routePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            'customer_admin' => $this->cohortRoutePrefix,
            default => abort(403),
        };
    }
}
