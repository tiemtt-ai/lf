<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0022 Amendment 1.1: two course types remain, Self-paced and Class-based (code `blended`).
 * Working Templates move from `scheduled` to `blended`; every scheduled Activity already fits blended.
 * Published Versions are immutable and keep `scheduled` in `course_type_snapshot`; it reads as Class-based.
 * The meaning does not change, so `working_revision` is not bumped. No schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('core_course_templates')
            ->where('course_type', 'scheduled')
            ->update(['course_type' => 'blended']);
    }

    /** Irreversible: which Templates were `scheduled` is not kept, and both values mean the same type. */
    public function down(): void {}
};
