<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_enrollments', function (Blueprint $table): void {
            $table->unsignedInteger('access_duration_days')->nullable()->after('enrolled_at');
            $table->unsignedInteger('review_duration_days')->nullable()->after('access_duration_days');
        });
    }

    public function down(): void
    {
        Schema::table('core_course_enrollments', function (Blueprint $table): void {
            $table->dropColumn(['access_duration_days', 'review_duration_days']);
        });
    }
};
