<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0002 § Attendance Policy And Recording Amendment, part B (Owner 2026-11-17): the append-only edit history of
 * attendance, and the attendance tool of an in-room Session (the QR code on the board, or the learner's "Điểm danh"
 * button). A Session left at NULL uses the board, so no Session row is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_liveclass_attendance_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->string('old_attendance_mode', 20)->nullable();
            $table->string('new_attendance_mode', 20)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('change_source', 20);
            $table->unsignedBigInteger('changed_by')->nullable();
            // No updated_at: a row is never changed.
            $table->timestamp('created_at');

            $table->index(['customer_id', 'attendance_id', 'created_at'], 'idx_clac_attendance');
            $table->index(['customer_id', 'session_id', 'created_at'], 'idx_clac_session');
            $table->foreign('customer_id', 'fk_clac_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('attendance_id', 'fk_clac_attendance')->references('id')->on('core_liveclass_attendances')->restrictOnDelete();
            $table->foreign('session_id', 'fk_clac_session')->references('id')->on('core_liveclass_sessions')->restrictOnDelete();
            $table->foreign('enrollment_id', 'fk_clac_enrollment')->references('id')->on('core_course_enrollments')->restrictOnDelete();
            $table->foreign('changed_by', 'fk_clac_changed_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('core_liveclass_sessions', function (Blueprint $table): void {
            $table->string('attendance_tool', 20)->nullable()->after('delivery_mode');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("ALTER TABLE core_liveclass_attendance_changes
                ADD CONSTRAINT chk_clac_old_status CHECK (old_status IS NULL
                    OR old_status IN ('registered', 'present', 'late', 'absent', 'excused')),
                ADD CONSTRAINT chk_clac_new_status CHECK (new_status IN ('present', 'late', 'absent', 'excused')),
                ADD CONSTRAINT chk_clac_old_mode CHECK (old_attendance_mode IS NULL OR old_attendance_mode IN ('online', 'offline')),
                ADD CONSTRAINT chk_clac_new_mode CHECK (new_attendance_mode IS NULL OR new_attendance_mode IN ('online', 'offline')),
                ADD CONSTRAINT chk_clac_source CHECK (change_source IN ('manual', 'system')),
                ADD CONSTRAINT chk_clac_actor CHECK ((change_source = 'manual' AND changed_by IS NOT NULL)
                    OR (change_source = 'system' AND changed_by IS NULL))");
            DB::unprepared("ALTER TABLE core_liveclass_sessions
                ADD CONSTRAINT chk_lcs_attendance_tool CHECK (attendance_tool IS NULL OR attendance_tool IN ('board', 'button'))");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('ALTER TABLE core_liveclass_sessions DROP CONSTRAINT chk_lcs_attendance_tool');
        }
        Schema::table('core_liveclass_sessions', function (Blueprint $table): void {
            $table->dropColumn('attendance_tool');
        });
        Schema::dropIfExists('core_liveclass_attendance_changes');
    }
};
