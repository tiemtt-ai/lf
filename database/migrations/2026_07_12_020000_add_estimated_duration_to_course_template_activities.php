<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('core_course_template_activities', fn (Blueprint $table) => $table->unsignedInteger('estimated_duration_seconds')->nullable()->after('duration_seconds'));
        Schema::table('core_course_template_version_activities', fn (Blueprint $table) => $table->unsignedInteger('estimated_duration_seconds_snapshot')->nullable()->after('duration_seconds'));
    }
    public function down(): void {
        Schema::table('core_course_template_version_activities', fn (Blueprint $table) => $table->dropColumn('estimated_duration_seconds_snapshot'));
        Schema::table('core_course_template_activities', fn (Blueprint $table) => $table->dropColumn('estimated_duration_seconds'));
    }
};
