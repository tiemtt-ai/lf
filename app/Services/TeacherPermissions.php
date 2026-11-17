<?php

namespace App\Services;

use App\Support\AuditLog;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a teacher may do (ADR-0033 D3–D6): the single check point `may()`, and the tenant's permission groups.
 *
 * A permission says which kind of work a teacher may do; where (Template, Cohort, Session) still comes from the
 * assignments. Admins pass every check; students never reach these areas.
 */
final class TeacherPermissions
{
    public const GROUPS_TABLE = 'saas_teacher_permission_groups';

    public const GRANTS_TABLE = 'saas_teacher_permission_grants';

    /** ADR-0033 D3. Adding, removing or changing the meaning of a key is an amendment of the ADR. */
    public const CATALOG = [
        'content.template.create',
        'content.category.manage',
        'assessment.authoring',
        'assessment.grading',
        'class.manage',
        'class.delete',
        'class.operate',
    ];

    /** The keys enforced so far (ADR-0033 Implementation Order, phases 1–3); the screen offers only these. */
    public const AVAILABLE = [
        'content.template.create', 'content.category.manage', 'assessment.authoring', 'assessment.grading',
        'class.manage', 'class.delete', 'class.operate',
    ];

    /** The catalog by area of work, in the order of the menu: the group form shows one box per area. */
    public const AREAS = [
        'content' => ['content.template.create', 'content.category.manage'],
        'assessment' => ['assessment.authoring', 'assessment.grading'],
        'class' => ['class.manage', 'class.delete', 'class.operate'],
    ];

    /** ADR-0033 D3, column "Mặc định": exactly what a teacher could do before the ADR. */
    public const DEFAULT_KEYS = ['content.template.create', 'assessment.authoring', 'assessment.grading', 'class.operate'];

    private const DEFAULT_NAME = 'Mặc định';

    /** Whether the user may do the work of one of the keys. */
    public function may(?object $user, string ...$keys): bool
    {
        if ($user === null || ($user->role ?? null) === 'customer_admin') {
            return $user !== null;
        }
        if (($user->role ?? null) !== 'teacher') {
            return false;
        }
        $granted = $this->keysOf($user);

        return array_intersect($keys, $granted) !== [];
    }

    /**
     * Whether the user manages a Cohort with a key (ADR-0033 D7): an admin always; a teacher holding the key and being
     * its active primary teacher. The scope follows the primary teacher, not whoever created the class.
     */
    public function managesCohort(?object $user, int $cohortId, string $key = 'class.manage'): bool
    {
        if (! $this->may($user, $key)) {
            return false;
        }

        return ($user->role ?? null) === 'customer_admin'
            || in_array($cohortId, $this->primaryCohortIds($user), true);
    }

    /**
     * The class pages shared by the admin and teacher areas (ADR-0033 D7): an admin from either; a teacher only from
     * the teacher area (`teacher.course-cohorts.*`), and only as the Cohort's primary teacher holding the key.
     */
    public function managesCohortFromRoute(?object $user, int $cohortId, ?string $routeName, string $key = 'class.manage'): bool
    {
        return ($user->role ?? null) === 'customer_admin'
            || (self::isTeacherCohortRoute($routeName) && $this->managesCohort($user, $cohortId, $key));
    }

    /** Whether a route belongs to the teacher area of the shared class pages. */
    public static function isTeacherCohortRoute(?string $routeName): bool
    {
        return str_starts_with((string) $routeName, 'teacher.course-cohorts.');
    }

    /** @return list<int> the Cohorts the teacher is the active primary teacher of, read once per request */
    public function primaryCohortIds(object $user): array
    {
        $request = request();
        $cacheKey = 'lf.teacher_primary_cohorts.'.$user->id;
        if ($request->attributes->has($cacheKey)) {
            return $request->attributes->get($cacheKey);
        }
        $ids = DB::table('core_course_cohort_teachers')->where('customer_id', (int) $user->customer_id)
            ->where('teacher_id', $user->id)->where('role', 'primary_teacher')->where('status', 'active')
            ->pluck('cohort_id')->map(fn ($id): int => (int) $id)->all();
        $request->attributes->set($cacheKey, $ids);

        return $ids;
    }

    /**
     * The keys a teacher holds, read once per request: a change made by an admin applies from the teacher's next
     * request (ADR-0033 D4).
     *
     * @return list<string>
     */
    public function keysOf(object $user): array
    {
        $request = request();
        $cacheKey = 'lf.teacher_permissions.'.$user->id;
        if ($request->attributes->has($cacheKey)) {
            return $request->attributes->get($cacheKey);
        }
        $customerId = (int) $user->customer_id;
        $groupId = DB::table('users')->where('customer_id', $customerId)->where('id', $user->id)->value('teacher_permission_group_id');
        $keys = $this->keysOfGroup($customerId, $groupId === null ? (int) $this->defaultGroup($customerId)->id : (int) $groupId);
        $request->attributes->set($cacheKey, $keys);

        return $keys;
    }

    /** @return list<string> catalogue keys of one group; a key no longer in the catalogue is ignored */
    public function keysOfGroup(int $customerId, int $groupId): array
    {
        return DB::table(self::GRANTS_TABLE)->where('customer_id', $customerId)->where('permission_group_id', $groupId)
            ->pluck('permission_key')->intersect(self::CATALOG)->values()->all();
    }

    /**
     * Narrows a query on `users` to the teachers holding a key, e.g. the graders who may be named (ADR-0033 D6).
     * `$userColumn` is the users id column of the query.
     */
    public function constrainHolders(Builder $query, int $customerId, string $key, string $userColumn = 'users.id'): Builder
    {
        $holding = DB::table(self::GRANTS_TABLE)->where('customer_id', $customerId)->where('permission_key', $key)
            ->select('permission_group_id');
        $defaultHolds = in_array($key, $this->keysOfGroup($customerId, (int) $this->defaultGroup($customerId)->id), true);

        return $query->whereIn($userColumn, DB::table('users')->select('id')->where('customer_id', $customerId)
            ->where(function (Builder $users) use ($holding, $defaultHolds): void {
                $users->whereIn('teacher_permission_group_id', $holding);
                if ($defaultHolds) {
                    $users->orWhereNull('teacher_permission_group_id');
                }
            }));
    }

    /** The tenant's default group; a tenant created after the migration gets it on first read. */
    public function defaultGroup(int $customerId): object
    {
        $group = DB::table(self::GROUPS_TABLE)->where('customer_id', $customerId)->where('is_default', 1)->first();
        if ($group) {
            return $group;
        }
        try {
            DB::transaction(function () use ($customerId): void {
                $id = DB::table(self::GROUPS_TABLE)->insertGetId([
                    'customer_id' => $customerId, 'name' => self::DEFAULT_NAME, 'is_default' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->writeGrants($customerId, $id, self::DEFAULT_KEYS);
            });
        } catch (QueryException) {
            // Another request created it first (uk_stpg_customer_default).
        }

        return DB::table(self::GROUPS_TABLE)->where('customer_id', $customerId)->where('is_default', 1)->firstOrFail();
    }

    /** @return Collection<int, object> the groups, default first, with their keys and how many teachers use each */
    public function groups(int $customerId): Collection
    {
        $default = $this->defaultGroup($customerId);
        $grants = DB::table(self::GRANTS_TABLE)->where('customer_id', $customerId)->get(['permission_group_id', 'permission_key'])
            ->groupBy('permission_group_id');
        $counts = DB::table('users')->where('customer_id', $customerId)->where('role', 'teacher')
            ->selectRaw('teacher_permission_group_id as group_id, COUNT(*) as aggregate')
            ->groupBy('teacher_permission_group_id')->pluck('aggregate', 'group_id');

        return DB::table(self::GROUPS_TABLE)->where('customer_id', $customerId)
            ->orderByRaw('is_default IS NULL')->orderBy('name')->get()
            ->map(function (object $group) use ($grants, $counts, $default): object {
                $group->keys = collect($grants->get($group->id, []))->pluck('permission_key')->intersect(self::CATALOG)->values()->all();
                // A teacher at NULL belongs to the default group.
                $group->teacher_count = (int) ($counts[$group->id] ?? 0) + ((int) $group->id === (int) $default->id ? (int) ($counts[''] ?? 0) : 0);

                return $group;
            });
    }

    public function find(int $customerId, int $groupId): ?object
    {
        return DB::table(self::GROUPS_TABLE)->where('customer_id', $customerId)->where('id', $groupId)->first();
    }

    /** @param  list<string>  $keys */
    public function create(Request $request, int $customerId, string $name, array $keys): int
    {
        $this->defaultGroup($customerId);

        return DB::transaction(function () use ($request, $customerId, $name, $keys): int {
            $id = DB::table(self::GROUPS_TABLE)->insertGetId([
                'customer_id' => $customerId, 'name' => $name, 'is_default' => null,
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->writeGrants($customerId, $id, $keys);
            AuditLog::record($request, $customerId, 'teacher_permission_group.created', null, null, ['id' => $id, 'name' => $name, 'keys' => $this->keysOfGroup($customerId, $id)]);

            return $id;
        });
    }

    /**
     * Saving replaces the group's keys inside one transaction (saas_teacher_permission_grants § Business Rules). Keys
     * not offered on the screen yet are kept as they are.
     *
     * @param  list<string>  $keys
     */
    public function update(Request $request, int $customerId, object $group, string $name, array $keys): void
    {
        DB::transaction(function () use ($request, $customerId, $group, $name, $keys): void {
            DB::table(self::GROUPS_TABLE)->where('customer_id', $customerId)->where('id', $group->id)->lockForUpdate()->first();
            $before = ['id' => (int) $group->id, 'name' => $group->name, 'keys' => $this->keysOfGroup($customerId, (int) $group->id)];
            $kept = array_values(array_diff($before['keys'], self::AVAILABLE));
            DB::table(self::GROUPS_TABLE)->where('customer_id', $customerId)->where('id', $group->id)
                ->update(['name' => $name, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            DB::table(self::GRANTS_TABLE)->where('customer_id', $customerId)->where('permission_group_id', $group->id)->delete();
            $this->writeGrants($customerId, (int) $group->id, array_merge($kept, $keys));
            AuditLog::record($request, $customerId, 'teacher_permission_group.updated', null, $before,
                ['id' => (int) $group->id, 'name' => $name, 'keys' => $this->keysOfGroup($customerId, (int) $group->id)]);
        });
    }

    /** @return bool false when the group is the default one or still has teachers, locked ones included */
    public function delete(Request $request, int $customerId, object $group): bool
    {
        return DB::transaction(function () use ($request, $customerId, $group): bool {
            $locked = DB::table(self::GROUPS_TABLE)->where('customer_id', $customerId)->where('id', $group->id)->lockForUpdate()->first();
            if (! $locked || $locked->is_default || DB::table('users')->where('customer_id', $customerId)->where('teacher_permission_group_id', $group->id)->exists()) {
                return false;
            }
            $before = ['id' => (int) $group->id, 'name' => $group->name, 'keys' => $this->keysOfGroup($customerId, (int) $group->id)];
            DB::table(self::GROUPS_TABLE)->where('customer_id', $customerId)->where('id', $group->id)->delete();
            AuditLog::record($request, $customerId, 'teacher_permission_group.deleted', null, $before, null);

            return true;
        });
    }

    /** @return Collection<int, object> the tenant's teachers with their group (NULL = default), for the assign popup */
    public function teachers(int $customerId): Collection
    {
        return DB::table('users')->where('customer_id', $customerId)->where('role', 'teacher')
            ->orderBy('name')->orderBy('id')->get(['id', 'name', 'email', 'status', 'teacher_permission_group_id']);
    }

    /**
     * Puts the ticked teachers in the group and sends the group's unticked teachers back to the default group. Only the
     * teachers the form showed are touched, so a teacher created meanwhile keeps their group. Each move is audited on
     * the teacher (ADR-0033 D4).
     *
     * @param  list<int>  $shownIds
     * @param  list<int>  $teacherIds
     * @return int how many teachers changed group
     */
    public function assign(Request $request, int $customerId, object $group, array $shownIds, array $teacherIds): int
    {
        $value = $group->is_default ? null : (int) $group->id;

        return DB::transaction(function () use ($request, $customerId, $group, $shownIds, $teacherIds, $value): int {
            $teachers = DB::table('users')->where('customer_id', $customerId)->where('role', 'teacher')
                ->whereIn('id', $shownIds)->lockForUpdate()->get(['id', 'teacher_permission_group_id']);
            $chosen = array_flip($teacherIds);
            $changed = 0;
            foreach ($teachers as $teacher) {
                $current = $teacher->teacher_permission_group_id === null ? null : (int) $teacher->teacher_permission_group_id;
                $inGroup = $group->is_default ? $current === null : $current === (int) $group->id;
                if (isset($chosen[(int) $teacher->id]) && ! $inGroup) {
                    $next = $value;
                } elseif (! isset($chosen[(int) $teacher->id]) && $inGroup && ! $group->is_default) {
                    $next = null;
                } else {
                    continue;
                }
                DB::table('users')->where('customer_id', $customerId)->where('id', $teacher->id)
                    ->update(['teacher_permission_group_id' => $next, 'updated_at' => now()]);
                AuditLog::record($request, $customerId, 'user.permission_group_changed', (int) $teacher->id,
                    ['teacher_permission_group_id' => $current], ['teacher_permission_group_id' => $next]);
                $changed++;
            }

            return $changed;
        });
    }

    /** @param  list<string>  $keys */
    private function writeGrants(int $customerId, int $groupId, array $keys): void
    {
        $rows = collect($keys)->intersect(self::CATALOG)->unique()->values()
            ->map(fn (string $key) => ['customer_id' => $customerId, 'permission_group_id' => $groupId, 'permission_key' => $key, 'created_at' => now()])
            ->all();
        if ($rows !== []) {
            DB::table(self::GRANTS_TABLE)->insert($rows);
        }
    }
}
