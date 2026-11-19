<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0022 Amendment 1.3, Đợt 4, Bước 2: a self-study lesson gets a Session of its own type on the class
 * schedule. It is bound to the Lesson, not to an Activity, and has no delivery mode, link, room or attendance tool.
 * Existing rows are untouched: no class has a self-study lesson yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $constrained = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        if ($constrained) {
            DB::unprepared('ALTER TABLE core_liveclass_sessions DROP CONSTRAINT chk_lcs_type_binding');
        }
        Schema::table('core_liveclass_sessions', function (Blueprint $table): void {
            $table->string('delivery_mode', 50)->nullable()->change();
        });
        if ($constrained) {
            DB::unprepared("ALTER TABLE core_liveclass_sessions
                ADD CONSTRAINT chk_lcs_type_binding CHECK (
                    (session_type = 'curriculum' AND version_lesson_id IS NOT NULL AND version_activity_id IS NOT NULL)
                    OR (session_type = 'operational' AND version_lesson_id IS NULL AND version_activity_id IS NULL)
                    OR (session_type = 'self_study' AND version_lesson_id IS NOT NULL AND version_activity_id IS NULL)),
                ADD CONSTRAINT chk_lcs_self_study_box CHECK (
                    (session_type = 'self_study' AND delivery_mode IS NULL AND online_provider IS NULL
                        AND meeting_url_snapshot IS NULL AND room_name_snapshot IS NULL
                        AND address_snapshot IS NULL AND attendance_tool IS NULL)
                    OR (session_type <> 'self_study' AND delivery_mode IS NOT NULL))");
        }
    }

    public function down(): void
    {
        $constrained = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
        if ($constrained) {
            DB::unprepared('ALTER TABLE core_liveclass_sessions DROP CONSTRAINT chk_lcs_self_study_box');
            DB::unprepared('ALTER TABLE core_liveclass_sessions DROP CONSTRAINT chk_lcs_type_binding');
        }
        DB::table('core_liveclass_sessions')->where('session_type', 'self_study')->delete();
        Schema::table('core_liveclass_sessions', function (Blueprint $table): void {
            $table->string('delivery_mode', 50)->nullable(false)->change();
        });
        if ($constrained) {
            DB::unprepared("ALTER TABLE core_liveclass_sessions
                ADD CONSTRAINT chk_lcs_type_binding CHECK (
                    (session_type = 'curriculum' AND version_lesson_id IS NOT NULL AND version_activity_id IS NOT NULL)
                    OR (session_type = 'operational' AND version_lesson_id IS NULL AND version_activity_id IS NULL))");
        }
    }
};
