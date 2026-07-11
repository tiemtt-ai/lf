<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_file_usages')) {
            DB::table('media_file_usages')
                ->where('owner_type', 'course_lesson')
                ->delete();
        }

        Schema::table('core_course_template_lessons', function (Blueprint $table) {
            $table->dropIndex('idx_cctl_status');
            $table->dropColumn(['learning_objective', 'status']);
        });

        Schema::table('core_course_template_version_lessons', function (Blueprint $table) {
            $table->dropColumn([
                'learning_objective_snapshot',
                'status_snapshot',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('core_course_template_lessons', function (Blueprint $table) {
            $table->text('learning_objective')->nullable()->after('is_preview');
            $table->string('status', 50)->default('draft')->after('unlock_at');
            $table->index(
                ['customer_id', 'template_id', 'status'],
                'idx_cctl_status'
            );
        });

        Schema::table('core_course_template_version_lessons', function (Blueprint $table) {
            $table->text('learning_objective_snapshot')
                ->nullable()->after('is_preview');
            $table->string('status_snapshot', 50)
                ->default('draft')->after('unlock_at_snapshot');
        });
    }
};
