<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $invalidCurriculumCount = DB::table('core_liveclass_sessions as sessions')
            ->leftJoin('core_course_template_version_lessons as lessons', 'lessons.id', '=', 'sessions.version_lesson_id')
            ->leftJoin('core_course_template_version_activities as activities', 'activities.id', '=', 'sessions.version_activity_id')
            ->whereNotNull('sessions.version_activity_id')
            ->where(function ($query): void {
                $query->whereNull('lessons.id')
                    ->orWhereNull('activities.id')
                    ->orWhereColumn('lessons.customer_id', '!=', 'sessions.customer_id')
                    ->orWhereColumn('lessons.template_version_id', '!=', 'sessions.template_version_id')
                    ->orWhereColumn('activities.customer_id', '!=', 'sessions.customer_id')
                    ->orWhereColumn('activities.template_version_id', '!=', 'sessions.template_version_id')
                    ->orWhereColumn('activities.version_lesson_id', '!=', 'sessions.version_lesson_id')
                    ->orWhere('activities.activity_type', '!=', 'live_class');
            })
            ->count();
        $invalidOperationalEvidenceCount = DB::table('core_liveclass_sessions as sessions')
            ->join('core_liveclass_attendances as attendance', 'attendance.session_id', '=', 'sessions.id')
            ->whereNull('sessions.version_activity_id')
            ->whereNotNull('attendance.version_activity_id')
            ->count();

        if ($invalidCurriculumCount > 0 || $invalidOperationalEvidenceCount > 0) {
            throw new RuntimeException('Live Class Session binding audit failed; manual remediation is required.');
        }

        Schema::table('core_liveclass_sessions', function (Blueprint $table): void {
            $table->string('session_type', 50)->nullable()->after('template_version_id');
            $table->foreignId('version_lesson_id')->nullable()->change();
        });

        DB::table('core_liveclass_sessions')
            ->whereNotNull('version_activity_id')
            ->update(['session_type' => 'curriculum']);

        DB::table('core_liveclass_sessions')
            ->whereNull('version_activity_id')
            ->update([
                'session_type' => 'operational',
                'version_lesson_id' => null,
            ]);

        Schema::table('core_liveclass_sessions', function (Blueprint $table): void {
            $table->string('session_type', 50)->nullable(false)->change();
            $table->index(
                ['customer_id', 'cohort_id', 'session_type', 'scheduled_start_at'],
                'idx_lcs_type_schedule'
            );
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE core_liveclass_sessions ADD CONSTRAINT chk_lcs_type_binding CHECK ((session_type = 'curriculum' AND version_lesson_id IS NOT NULL AND version_activity_id IS NOT NULL) OR (session_type = 'operational' AND version_lesson_id IS NULL AND version_activity_id IS NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE core_liveclass_sessions DROP CHECK chk_lcs_type_binding');
        }

        $operational = DB::table('core_liveclass_sessions')
            ->where('session_type', 'operational')
            ->get(['id', 'customer_id', 'template_version_id']);

        foreach ($operational as $session) {
            $lessonId = DB::table('core_course_template_version_lessons')
                ->where('customer_id', $session->customer_id)
                ->where('template_version_id', $session->template_version_id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');

            if (! $lessonId) {
                throw new RuntimeException('Cannot restore legacy Session Lesson binding.');
            }

            DB::table('core_liveclass_sessions')->where('id', $session->id)
                ->update(['version_lesson_id' => $lessonId]);
        }

        Schema::table('core_liveclass_sessions', function (Blueprint $table): void {
            $table->dropIndex('idx_lcs_type_schedule');
            $table->foreignId('version_lesson_id')->nullable(false)->change();
            $table->dropColumn('session_type');
        });
    }
};
