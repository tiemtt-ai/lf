<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('core_course_templates', 'language')) {
            return;
        }

        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->dropIndex('idx_cct_customer_language');
            $table->dropColumn('language');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('core_course_templates', 'language')) {
            return;
        }

        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->string('language', 20)
                ->nullable()
                ->after('difficulty_level');
            $table->index(
                ['customer_id', 'language'],
                'idx_cct_customer_language'
            );
        });
    }
};
