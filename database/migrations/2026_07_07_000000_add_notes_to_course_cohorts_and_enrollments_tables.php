<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_cohorts', function (Blueprint $table): void {
            if (! Schema::hasColumn('core_course_cohorts', 'notes')) {
                $table->text('notes')->nullable();
            }
        });

        Schema::table('core_course_enrollments', function (Blueprint $table): void {
            if (! Schema::hasColumn('core_course_enrollments', 'notes')) {
                $table->text('notes')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('core_course_enrollments', function (Blueprint $table): void {
            if (Schema::hasColumn('core_course_enrollments', 'notes')) {
                $table->dropColumn('notes');
            }
        });

        Schema::table('core_course_cohorts', function (Blueprint $table): void {
            if (Schema::hasColumn('core_course_cohorts', 'notes')) {
                $table->dropColumn('notes');
            }
        });
    }
};
