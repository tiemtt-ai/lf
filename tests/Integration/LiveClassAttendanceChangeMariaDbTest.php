<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * core_liveclass_attendance_changes.md and core_liveclass_sessions.md § Attendance Tool Amendment on MariaDB (SQLite
 * ignores CHECK): the edit history only takes the documented values, and a Session only a documented attendance tool.
 */
class LiveClassAttendanceChangeMariaDbTest extends TestCase
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

    public function test_the_edit_history_takes_only_the_documented_values(): void
    {
        $this->assertStringContainsString('MariaDB', DB::selectOne('select version() as v')->v);
        // Only the value constraints are under test; the Attendance, Session and user rows are left out.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $change = fn (array $values): int => DB::table('core_liveclass_attendance_changes')->insertGetId($values + [
            'customer_id' => 1, 'attendance_id' => 1, 'session_id' => 1, 'enrollment_id' => 1,
            'old_status' => 'absent', 'new_status' => 'excused', 'change_source' => 'manual', 'changed_by' => 1,
            'created_at' => now(),
        ]);

        try {
            foreach ([
                'chk_clac_old_status' => ['old_status' => 'unknown'],
                // A result is never set back to "not recorded yet".
                'chk_clac_new_status' => ['new_status' => 'registered'],
                'chk_clac_old_mode' => ['old_attendance_mode' => 'hybrid'],
                'chk_clac_new_mode' => ['new_attendance_mode' => ''],
                'chk_clac_source' => ['change_source' => 'provider', 'changed_by' => null],
            ] as $constraint => $values) {
                $this->assertRejected(fn () => $change($values), $constraint);
            }
            // A manual change names who made it; a system change names nobody.
            $this->assertRejected(fn () => $change(['changed_by' => null]), 'chk_clac_actor');
            $this->assertRejected(fn () => $change(['change_source' => 'system']), 'chk_clac_actor');

            $this->assertGreaterThan(0, $change([]));
            $this->assertGreaterThan(0, $change(['old_status' => null, 'new_status' => 'present', 'new_attendance_mode' => 'offline']));
            $this->assertGreaterThan(0, $change(['old_status' => 'registered', 'new_status' => 'late', 'old_attendance_mode' => 'online',
                'new_attendance_mode' => 'offline', 'change_source' => 'system', 'changed_by' => null, 'notes' => str_repeat('a', 1000)]));
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function test_a_session_takes_only_a_documented_attendance_tool(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $number = 0;
        $session = function (?string $tool) use (&$number): int {
            return DB::table('core_liveclass_sessions')->insertGetId([
                'customer_id' => 1, 'cohort_id' => 1, 'template_version_id' => 1, 'session_type' => 'operational',
                'title' => 'Session', 'session_no' => ++$number, 'delivery_mode' => 'offline',
                'attendance_tool' => $tool, 'scheduled_start_at' => '2026-11-18 19:00:00', 'scheduled_end_at' => '2026-11-18 20:30:00',
                'timezone' => 'Asia/Ho_Chi_Minh', 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        try {
            foreach (['qr', '', 'manual'] as $tool) {
                $this->assertRejected(fn () => $session($tool), 'chk_lcs_attendance_tool');
            }
            foreach ([null, 'board', 'button'] as $tool) {
                $this->assertGreaterThan(0, $session($tool));
            }
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
