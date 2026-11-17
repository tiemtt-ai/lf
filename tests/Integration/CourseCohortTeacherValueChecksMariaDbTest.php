<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * core_course_cohort_teachers.md § Value Constraint Amendment on MariaDB (SQLite ignores CHECK): the table refuses a
 * role, a status or a period outside the documented ones, whoever writes the row.
 */
class CourseCohortTeacherValueChecksMariaDbTest extends TestCase
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

    public function test_role_status_and_period_are_enforced_by_the_schema(): void
    {
        $this->assertStringContainsString('MariaDB', DB::selectOne('select version() as v')->v);
        // Only the value constraints are under test; the Cohort and Teacher rows are left out.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $teacher = 0;
        $assign = function (array $values) use (&$teacher): int {
            return DB::table('core_course_cohort_teachers')->insertGetId($values + [
                'customer_id' => 1, 'cohort_id' => 1, 'teacher_id' => ++$teacher, 'role' => 'teacher', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        try {
            // `primary` is the Template vocabulary: the value that reached the dev database by hand.
            $this->assertRejected(fn () => $assign(['role' => 'primary']), 'chk_core_course_cohort_teachers_role');
            $this->assertRejected(fn () => $assign(['role' => '']), 'chk_core_course_cohort_teachers_role');
            $this->assertRejected(fn () => $assign(['status' => 'removed']), 'chk_core_course_cohort_teachers_status');
            $this->assertRejected(fn () => $assign(['assigned_from' => '2026-12-31', 'assigned_to' => '2026-10-27']),
                'chk_core_course_cohort_teachers_period');

            foreach (['primary_teacher', 'teacher', 'assistant'] as $role) {
                $this->assertGreaterThan(0, $assign(['role' => $role]));
            }
            $kept = $assign(['status' => 'inactive', 'assigned_from' => '2026-10-27', 'assigned_to' => '2026-10-27']);
            $this->assertGreaterThan(0, $assign(['assigned_from' => '2026-10-27']));
            $this->assertGreaterThan(0, $assign(['assigned_to' => '2026-12-31']));

            // An update is held to the same values as an insert.
            $this->assertRejected(fn () => DB::table('core_course_cohort_teachers')->where('id', $kept)->update(['role' => 'primary']),
                'chk_core_course_cohort_teachers_role');
            $this->assertRejected(fn () => DB::table('core_course_cohort_teachers')->where('id', $kept)->update(['assigned_to' => '2026-10-01']),
                'chk_core_course_cohort_teachers_period');
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
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
