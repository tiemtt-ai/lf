<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_template_lessons', function (Blueprint $table) {
            $table->dropUnique('uk_cctl_slug');
            $table->dropIndex('idx_cctl_slug');
            $table->dropColumn('slug');
        });

        Schema::table('core_course_template_version_lessons', function (Blueprint $table) {
            $table->dropColumn('slug_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('core_course_template_lessons', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('title');
            $table->index(['customer_id', 'slug'], 'idx_cctl_slug');
            $table->unique(
                ['customer_id', 'template_id', 'slug'],
                'uk_cctl_slug'
            );
        });

        Schema::table('core_course_template_version_lessons', function (Blueprint $table) {
            $table->string('slug_snapshot')->nullable()->after('title_snapshot');
        });
    }
};
