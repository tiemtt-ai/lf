<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_template_activities', function (Blueprint $table): void {
            $table->boolean('available_anytime')->default(true)->after('estimated_duration_seconds');
            $table->boolean('available_before_session')->default(false)->after('available_anytime');
            $table->boolean('available_during_session')->default(false)->after('available_before_session');
            $table->boolean('available_after_session')->default(false)->after('available_during_session');
        });

        Schema::table('core_course_template_version_activities', function (Blueprint $table): void {
            $table->boolean('available_anytime')->default(true)->after('estimated_duration_seconds_snapshot');
            $table->boolean('available_before_session')->default(false)->after('available_anytime');
            $table->boolean('available_during_session')->default(false)->after('available_before_session');
            $table->boolean('available_after_session')->default(false)->after('available_during_session');
        });
    }

    public function down(): void
    {
        Schema::table('core_course_template_version_activities', function (Blueprint $table): void {
            $table->dropColumn([
                'available_anytime',
                'available_before_session',
                'available_during_session',
                'available_after_session',
            ]);
        });

        Schema::table('core_course_template_activities', function (Blueprint $table): void {
            $table->dropColumn([
                'available_anytime',
                'available_before_session',
                'available_during_session',
                'available_after_session',
            ]);
        });
    }
};
