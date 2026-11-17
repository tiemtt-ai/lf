<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * core_liveclass_attendance_policies.md on MariaDB (SQLite ignores CHECK): the documented defaults, the value
 * limits, one default policy per tenant, and a Cohort that can only point to a policy of its own tenant.
 */
class LiveClassAttendancePolicyMariaDbTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'core_liveclass_attendance_policies';

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

    public function test_a_new_policy_takes_the_documented_defaults(): void
    {
        $this->assertStringContainsString('MariaDB', DB::selectOne('select version() as v')->v);
        $customerId = $this->tenant('defaults');
        $id = DB::table(self::TABLE)->insertGetId(['customer_id' => $customerId, 'name' => 'Lớp buổi tối']);

        $this->assertEquals([
            'is_default' => null, 'opens_before_minutes' => 15, 'late_after_minutes' => 10, 'absent_after_minutes' => 30,
            'auto_absent_after_minutes' => 30, 'count_from_actual_start' => 1, 'offline_recording' => 'system',
            'online_recording' => 'system', 'makeup_enabled' => 1, 'makeup_within_days' => null, 'teacher_edit_days' => 7,
        ], (array) DB::table(self::TABLE)->where('id', $id)->first([
            'is_default', 'opens_before_minutes', 'late_after_minutes', 'absent_after_minutes', 'auto_absent_after_minutes',
            'count_from_actual_start', 'offline_recording', 'online_recording', 'makeup_enabled', 'makeup_within_days',
            'teacher_edit_days',
        ]));
    }

    public function test_the_value_limits_are_enforced_by_the_schema(): void
    {
        $customerId = $this->tenant('limits');
        $n = 0;
        $policy = function (array $values) use ($customerId, &$n): int {
            return DB::table(self::TABLE)->insertGetId($values + ['customer_id' => $customerId, 'name' => 'Policy '.++$n]);
        };

        foreach ([
            'chk_clap_is_default' => ['is_default' => 0],
            'chk_clap_opens_before' => ['opens_before_minutes' => 241],
            'chk_clap_late_after' => ['late_after_minutes' => 241, 'absent_after_minutes' => null],
            'chk_clap_absent_after' => ['late_after_minutes' => 20, 'absent_after_minutes' => 19],
            'chk_clap_auto_absent' => ['auto_absent_after_minutes' => 1441],
            'chk_clap_offline_recording' => ['offline_recording' => 'qr'],
            'chk_clap_online_recording' => ['online_recording' => ''],
            'chk_clap_makeup_days' => ['makeup_within_days' => 0],
            'chk_clap_teacher_edit_days' => ['teacher_edit_days' => 366],
        ] as $constraint => $values) {
            $this->assertRejected(fn () => $policy($values), $constraint);
        }
        $this->assertRejected(fn () => $policy(['absent_after_minutes' => 481]), 'chk_clap_absent_after');

        // The edges are accepted: no absent threshold, equal thresholds, manual recording, the widest windows.
        $kept = $policy(['absent_after_minutes' => null, 'late_after_minutes' => 240]);
        $this->assertGreaterThan(0, $policy(['late_after_minutes' => 30, 'absent_after_minutes' => 30]));
        $this->assertGreaterThan(0, $policy([
            'opens_before_minutes' => 0, 'auto_absent_after_minutes' => 1440, 'offline_recording' => 'manual',
            'online_recording' => 'manual', 'makeup_within_days' => 365, 'teacher_edit_days' => 0,
        ]));
        // An update is held to the same limits.
        $this->assertRejected(fn () => DB::table(self::TABLE)->where('id', $kept)->update(['absent_after_minutes' => 10]),
            'chk_clap_absent_after');
    }

    public function test_a_tenant_has_one_default_policy_and_unique_names(): void
    {
        $customerId = $this->tenant('default');
        $otherCustomerId = $this->tenant('other');
        $insert = fn (int $tenant, string $name, ?int $default) => DB::table(self::TABLE)
            ->insertGetId(['customer_id' => $tenant, 'name' => $name, 'is_default' => $default]);

        $insert($customerId, 'Mặc định', 1);
        $this->assertRejected(fn () => $insert($customerId, 'Mặc định thứ hai', 1), 'uk_clap_customer_default');
        // Any number of ordinary policies, and another tenant's own default with the same name.
        $insert($customerId, 'Lớp sáng', null);
        $insert($customerId, 'Lớp tối', null);
        $insert($otherCustomerId, 'Mặc định', 1);
        $this->assertRejected(fn () => $insert($customerId, 'Lớp sáng', null), 'uk_clap_customer_name');
    }

    public function test_a_cohort_points_only_to_a_policy_of_its_own_tenant_and_keeps_it_from_being_deleted(): void
    {
        $customerId = $this->tenant('cohort');
        $otherCustomerId = $this->tenant('cohort-other');
        $own = DB::table(self::TABLE)->insertGetId(['customer_id' => $customerId, 'name' => 'Own']);
        $foreign = DB::table(self::TABLE)->insertGetId(['customer_id' => $otherCustomerId, 'name' => 'Foreign']);
        $now = now();
        $cohortId = DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $customerId, 'name' => 'Cohort', 'code' => 'COH-POLICY', 'status' => 'draft',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $assign = fn (?int $policy) => DB::table('core_course_cohorts')->where('id', $cohortId)->update(['attendance_policy_id' => $policy]);

        $this->assertNull(DB::table('core_course_cohorts')->where('id', $cohortId)->value('attendance_policy_id'));
        $this->assertRejected(fn () => $assign($foreign), 'fk_ccc_attendance_policy');
        $assign($own);
        $this->assertRejected(fn () => DB::table(self::TABLE)->where('id', $own)->delete(), 'fk_ccc_attendance_policy');
        // Back on the tenant default (NULL), the policy can go.
        $assign(null);
        $this->assertSame(1, DB::table(self::TABLE)->where('id', $own)->delete());
    }

    private function tenant(string $slug): int
    {
        $now = now();

        return DB::table('saas_customers')->insertGetId([
            'name' => 'Policy '.$slug, 'slug' => 'policy-'.$slug, 'subdomain' => 'policy-'.$slug, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
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
