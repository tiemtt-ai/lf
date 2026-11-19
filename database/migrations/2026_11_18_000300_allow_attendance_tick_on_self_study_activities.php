<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0022 Amendment 1.3, Đợt 4, Bước 3: an Activity of a self-study lesson (stored as `available_anytime = 1`)
 * may be ticked "Tính vào điểm danh". The CHECK of Đợt 3 allowed only the "Trong" and "Sau" parts of a led session.
 */
return new class extends Migration
{
    private const TABLES = [
        'core_course_template_activities' => 'chk_ccta_counts_for_attendance',
        'core_course_template_version_activities' => 'chk_cctva_counts_for_attendance',
    ];

    public function up(): void
    {
        $this->replace("counts_for_attendance = 0 OR (activity_type <> 'live_class' AND available_before_session = 0)");
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            DB::table($table)->where('counts_for_attendance', true)->where('available_anytime', true)
                ->update(['counts_for_attendance' => false]);
        }
        $this->replace("counts_for_attendance = 0 OR (activity_type <> 'live_class' AND available_anytime = 0 AND available_before_session = 0)");
    }

    private function replace(string $check): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach (self::TABLES as $table => $constraint) {
            DB::unprepared("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            DB::unprepared("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$check})");
        }
    }
};
