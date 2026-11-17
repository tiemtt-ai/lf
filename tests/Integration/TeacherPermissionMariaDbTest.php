<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * saas_teacher_permission_groups.md, saas_teacher_permission_grants.md and saas_customers.md § Tenant Owner Amendment on
 * MariaDB (SQLite ignores CHECK): one default group per tenant, keys and teachers kept inside their tenant, the owner a
 * user of the tenant itself.
 */
class TeacherPermissionMariaDbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires a throwaway MariaDB instance.');
        }
        if (DB::connection()->getDatabaseName() === 'learnforge_db') {
            throw new RuntimeException('Refused: run on a throwaway database.');
        }
    }

    public function test_the_schema_keeps_groups_teachers_and_the_owner_inside_their_tenant(): void
    {
        $this->assertStringContainsString('MariaDB', DB::selectOne('select version() as v')->v);
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $teacherA = $this->user($a, 'teacher');
        $adminB = $this->user($b, 'customer_admin');
        $group = fn (int $customerId, string $name, ?int $default = null): int => DB::table('saas_teacher_permission_groups')
            ->insertGetId(['customer_id' => $customerId, 'name' => $name, 'is_default' => $default, 'created_at' => now(), 'updated_at' => now()]);

        $defaultA = $group($a, 'Mặc định', 1);
        $this->assertRejected(fn () => $group($a, 'Second default', 1), 'uk_stpg_customer_default');
        $this->assertRejected(fn () => $group($a, 'Zero', 0), 'chk_stpg_is_default');
        $this->assertRejected(fn () => $group($a, 'Mặc định'), 'uk_stpg_customer_name');
        $groupB = $group($b, 'Mặc định', 1);
        $extraA = $group($a, 'Trợ giảng');

        $grant = fn (int $customerId, int $groupId, string $key) => DB::table('saas_teacher_permission_grants')
            ->insert(['customer_id' => $customerId, 'permission_group_id' => $groupId, 'permission_key' => $key, 'created_at' => now()]);
        $grant($a, $extraA, 'class.operate');
        $this->assertRejected(fn () => $grant($a, $extraA, 'class.operate'), 'uk_stpgr_group_key');
        $this->assertRejected(fn () => $grant($a, $groupB, 'class.operate'), 'fk_stpgr_group');

        // A teacher only joins a group of their own tenant, and a group with a teacher cannot go.
        $this->assertRejected(fn () => DB::table('users')->where('id', $teacherA)->update(['teacher_permission_group_id' => $groupB]), 'fk_users_permission_group');
        DB::table('users')->where('id', $teacherA)->update(['teacher_permission_group_id' => $extraA]);
        $this->assertRejected(fn () => DB::table('saas_teacher_permission_groups')->where('id', $extraA)->delete(), 'fk_users_permission_group');
        DB::table('users')->where('id', $teacherA)->update(['teacher_permission_group_id' => null]);
        DB::table('saas_teacher_permission_groups')->where('id', $extraA)->delete();
        $this->assertSame(0, DB::table('saas_teacher_permission_grants')->where('permission_group_id', $extraA)->count());

        // The owner is a user of the tenant itself, and cannot be deleted while named.
        $this->assertRejected(fn () => DB::table('saas_customers')->where('id', $a)->update(['owner_user_id' => $adminB]), 'fk_sc_owner_user');
        DB::table('saas_customers')->where('id', $b)->update(['owner_user_id' => $adminB]);
        $this->assertRejected(fn () => DB::table('users')->where('id', $adminB)->delete(), 'fk_sc_owner_user');
        $this->assertGreaterThan(0, $defaultA);
    }

    private function tenant(string $slug): int
    {
        return DB::table('saas_customers')->insertGetId(['name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(int $customerId, string $role): int
    {
        return DB::table('users')->insertGetId([
            'customer_id' => $customerId, 'name' => $role, 'email' => $role.$customerId.'@example.test', 'password' => 'x',
            'role' => $role, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertRejected(callable $write, string $constraint): void
    {
        try {
            DB::transaction(fn () => $write());
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());

            return;
        }
        $this->fail("Expected {$constraint} to reject the write.");
    }
}
