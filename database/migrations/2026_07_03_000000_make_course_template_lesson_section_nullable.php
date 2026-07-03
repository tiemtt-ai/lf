<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('core_course_template_lessons', function (Blueprint $table) {
            $table->unsignedBigInteger('template_section_id')
                ->nullable()
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (
            DB::table('core_course_template_lessons')
                ->whereNull('template_section_id')
                ->exists()
        ) {
            throw new RuntimeException(
                'Cannot restore mandatory sections while direct lessons exist.'
            );
        }

        Schema::table('core_course_template_lessons', function (Blueprint $table) {
            $table->unsignedBigInteger('template_section_id')
                ->nullable(false)
                ->change();
        });
    }
};
