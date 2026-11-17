<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * core_course_cohort_teachers.md § Value Constraint Amendment (Owner 2026-11-17): the database refuses a role, a status
 * or a period outside the documented ones. A hand-written row with the Template vocabulary (`primary`) had been accepted
 * and broke the class teacher list. Existing violations stop the migration; it never repairs data.
 */
return new class extends Migration
{
    private const TABLE = 'core_course_cohort_teachers';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $violations = DB::table(self::TABLE)
            ->whereNotIn('role', ['primary_teacher', 'teacher', 'assistant'])
            ->orWhereNotIn('status', ['active', 'inactive'])
            ->orWhereColumn('assigned_from', '>', 'assigned_to')
            ->orderBy('id')->pluck('id')->all();
        if ($violations !== []) {
            throw new RuntimeException('core_course_cohort_teachers has rows outside the documented role, status or period: ids '
                .implode(', ', $violations).'. Correct them before adding the constraints.');
        }

        DB::unprepared('ALTER TABLE '.self::TABLE."
            ADD CONSTRAINT chk_core_course_cohort_teachers_role CHECK (role IN ('primary_teacher', 'teacher', 'assistant')),
            ADD CONSTRAINT chk_core_course_cohort_teachers_status CHECK (status IN ('active', 'inactive')),
            ADD CONSTRAINT chk_core_course_cohort_teachers_period
                CHECK (assigned_from IS NULL OR assigned_to IS NULL OR assigned_from <= assigned_to)");
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::unprepared('ALTER TABLE '.self::TABLE.'
            DROP CONSTRAINT chk_core_course_cohort_teachers_role,
            DROP CONSTRAINT chk_core_course_cohort_teachers_status,
            DROP CONSTRAINT chk_core_course_cohort_teachers_period');
    }
};
