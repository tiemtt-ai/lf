<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('core_course_template_lessons', function (Blueprint $table): void {
            $table->string('lesson_type', 50)->default('regular')->after('is_preview');
        });
        Schema::table('core_course_template_version_lessons', function (Blueprint $table): void {
            $table->string('lesson_type', 50)->default('regular')->after('is_preview');
        });
    }

    public function down(): void
    {
        Schema::table('core_course_template_version_lessons', fn (Blueprint $table) => $table->dropColumn('lesson_type'));
        Schema::table('core_course_template_lessons', fn (Blueprint $table) => $table->dropColumn('lesson_type'));
    }
};
