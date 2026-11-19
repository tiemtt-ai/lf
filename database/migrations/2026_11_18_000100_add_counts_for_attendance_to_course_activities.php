<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0022 Amendment 1.3, Đợt 3: "Tính vào điểm danh" on the working Activity and its published snapshot.
 * Existing rows take 0, so published Versions keep recording attendance by the entry alone.
 */
return new class extends Migration
{
    private const TABLES = [
        'core_course_template_activities' => 'chk_ccta_counts_for_attendance',
        'core_course_template_version_activities' => 'chk_cctva_counts_for_attendance',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $constraint) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->boolean('counts_for_attendance')->default(false)->after('is_required');
            });
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::unprepared("ALTER TABLE {$table}
                    ADD CONSTRAINT {$constraint} CHECK (counts_for_attendance = 0
                        OR (activity_type <> 'live_class' AND available_anytime = 0 AND available_before_session = 0))");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $constraint) {
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::unprepared("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
            }
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('counts_for_attendance');
            });
        }
    }
};
