<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0035: the link of an "Link nền tảng ngoài" Activity (`activity_type = external_link`) on the working Activity
 * and its published snapshot. Nullable, no backfill: no existing row is of that type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_template_activities', function (Blueprint $table): void {
            $table->string('external_link_url', 1000)->nullable()->after('external_video_url');
        });
        Schema::table('core_course_template_version_activities', function (Blueprint $table): void {
            $table->string('external_link_url_snapshot', 1000)->nullable()->after('external_video_url_snapshot');
        });
    }

    public function down(): void
    {
        // Dropping the columns would leave external_link Activities without their link.
        foreach (['core_course_template_activities', 'core_course_template_version_activities'] as $table) {
            if (DB::table($table)->where('activity_type', 'external_link')->exists()) {
                throw new RuntimeException("{$table} still has external_link Activities; remove or change them before rolling back.");
            }
        }
        Schema::table('core_course_template_version_activities', function (Blueprint $table): void {
            $table->dropColumn('external_link_url_snapshot');
        });
        Schema::table('core_course_template_activities', function (Blueprint $table): void {
            $table->dropColumn('external_link_url');
        });
    }
};
