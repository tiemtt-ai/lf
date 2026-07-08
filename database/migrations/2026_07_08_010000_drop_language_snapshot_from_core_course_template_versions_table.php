<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('core_course_template_versions', 'language_snapshot')) {
            return;
        }

        Schema::table('core_course_template_versions', function (Blueprint $table): void {
            $table->dropColumn('language_snapshot');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('core_course_template_versions', 'language_snapshot')) {
            return;
        }

        Schema::table('core_course_template_versions', function (Blueprint $table): void {
            $table->string('language_snapshot', 20)
                ->nullable()
                ->after('difficulty_level_snapshot');
        });
    }
};
