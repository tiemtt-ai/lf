<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('core_course_templates')->increment('sort_order');

        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(1)->change();
        });
    }

    public function down(): void
    {
        DB::table('core_course_templates')
            ->where('sort_order', '>', 0)
            ->decrement('sort_order');

        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0)->change();
        });
    }
};
