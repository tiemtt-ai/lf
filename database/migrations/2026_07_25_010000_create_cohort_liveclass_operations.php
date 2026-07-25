<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_cohort_teachers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('cohort_id');
            $table->foreignId('teacher_id');
            $table->string('role', 50);
            $table->date('assigned_from')->nullable();
            $table->date('assigned_to')->nullable();
            $table->string('status', 50)->default('active');
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->foreign('customer_id', 'fk_ccot_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('cohort_id', 'fk_ccot_cohort')->references('id')->on('core_course_cohorts')->restrictOnDelete();
            $table->foreign('teacher_id', 'fk_ccot_teacher')->references('id')->on('users')->restrictOnDelete();
            $table->index(['customer_id', 'cohort_id', 'status'], 'idx_ccot_cohort');
            $table->index(['customer_id', 'teacher_id'], 'idx_ccot_teacher');
            $table->unique(['customer_id', 'cohort_id', 'teacher_id'], 'uk_ccot_assignment');
        });

        Schema::create('core_liveclass_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->string('title');
            $table->string('delivery_mode', 50);
            $table->string('provider', 50)->nullable();
            $table->string('provider_room_id')->nullable();
            $table->text('meeting_url')->nullable();
            $table->text('join_url')->nullable();
            $table->text('host_url')->nullable();
            $table->string('facility_name')->nullable();
            $table->string('room_name')->nullable();
            $table->text('address')->nullable();
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status', 50)->default('active');
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->foreign('customer_id', 'fk_lcr_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->index(['customer_id', 'delivery_mode', 'status'], 'idx_lcr_delivery');
        });

        Schema::create('core_liveclass_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('cohort_id');
            $table->foreignId('template_version_id');
            $table->foreignId('version_lesson_id');
            $table->foreignId('version_activity_id')->nullable();
            $table->foreignId('room_id')->nullable();
            $table->foreignId('primary_teacher_id')->nullable();
            $table->foreignId('superseded_by_session_id')->nullable();
            $table->string('title');
            $table->unsignedInteger('session_no');
            $table->string('delivery_mode', 50);
            $table->dateTime('scheduled_start_at');
            $table->dateTime('scheduled_end_at');
            $table->dateTime('actual_start_at')->nullable();
            $table->dateTime('actual_end_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
            $table->string('status', 50)->default('draft');
            $table->string('online_provider', 50)->nullable();
            $table->text('meeting_url_snapshot')->nullable();
            $table->string('meeting_id_snapshot')->nullable();
            $table->string('facility_name_snapshot')->nullable();
            $table->string('room_name_snapshot')->nullable();
            $table->text('address_snapshot')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->foreign('customer_id', 'fk_lcs_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('cohort_id', 'fk_lcs_cohort')->references('id')->on('core_course_cohorts')->restrictOnDelete();
            $table->foreign('template_version_id', 'fk_lcs_version')->references('id')->on('core_course_template_versions')->restrictOnDelete();
            $table->foreign('version_lesson_id', 'fk_lcs_lesson')->references('id')->on('core_course_template_version_lessons')->restrictOnDelete();
            $table->foreign('version_activity_id', 'fk_lcs_activity')->references('id')->on('core_course_template_version_activities')->restrictOnDelete();
            $table->foreign('room_id', 'fk_lcs_room')->references('id')->on('core_liveclass_rooms')->restrictOnDelete();
            $table->foreign('primary_teacher_id', 'fk_lcs_primary_teacher')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('superseded_by_session_id', 'fk_lcs_superseded')->references('id')->on('core_liveclass_sessions')->restrictOnDelete();
            $table->unique(['customer_id', 'cohort_id', 'session_no'], 'uk_lcs_number');
            $table->index(['customer_id', 'cohort_id', 'scheduled_start_at'], 'idx_lcs_schedule');
            $table->index(['customer_id', 'version_lesson_id'], 'idx_lcs_lesson');
            $table->index(['customer_id', 'version_activity_id'], 'idx_lcs_activity');
            $table->index(['customer_id', 'status'], 'idx_lcs_status');
        });

        Schema::create('core_liveclass_session_teachers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('session_id');
            $table->foreignId('teacher_id');
            $table->string('role', 50);
            $table->dateTime('assigned_from')->nullable();
            $table->dateTime('assigned_to')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->foreign('customer_id', 'fk_lcst_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('session_id', 'fk_lcst_session')->references('id')->on('core_liveclass_sessions')->restrictOnDelete();
            $table->foreign('teacher_id', 'fk_lcst_teacher')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['customer_id', 'session_id', 'teacher_id'], 'uk_lcst_assignment');
            $table->index(['customer_id', 'teacher_id'], 'idx_lcst_teacher');
        });

        Schema::create('core_liveclass_session_schedule_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('session_id');
            $table->dateTime('previous_start_at');
            $table->dateTime('previous_end_at');
            $table->dateTime('new_start_at');
            $table->dateTime('new_end_at');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('customer_id', 'fk_lcsc_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('session_id', 'fk_lcsc_session')->references('id')->on('core_liveclass_sessions')->restrictOnDelete();
            $table->index(['customer_id', 'session_id', 'created_at'], 'idx_lcsc_session');
        });

        Schema::create('core_liveclass_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('session_id');
            $table->foreignId('enrollment_id');
            $table->foreignId('user_id');
            $table->foreignId('version_activity_id')->nullable();
            $table->string('status', 50)->default('registered');
            $table->string('attendance_mode', 50)->nullable();
            $table->dateTime('joined_at')->nullable();
            $table->dateTime('left_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->decimal('attendance_percentage', 5, 2)->default(0);
            $table->string('attendance_source', 50)->default('manual');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable();
            $table->timestamps();
            $table->foreign('customer_id', 'fk_lca_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('session_id', 'fk_lca_session')->references('id')->on('core_liveclass_sessions')->restrictOnDelete();
            $table->foreign('enrollment_id', 'fk_lca_enrollment')->references('id')->on('core_course_enrollments')->restrictOnDelete();
            $table->foreign('user_id', 'fk_lca_user')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('version_activity_id', 'fk_lca_activity')->references('id')->on('core_course_template_version_activities')->restrictOnDelete();
            $table->unique(['customer_id', 'session_id', 'enrollment_id'], 'uk_lca_enrollment');
            $table->index(['customer_id', 'session_id', 'status'], 'idx_lca_session');
        });

        Schema::create('core_liveclass_recordings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('session_id');
            $table->foreignId('media_file_id')->nullable();
            $table->string('title');
            $table->string('provider', 50)->nullable();
            $table->string('provider_recording_id')->nullable();
            $table->text('recording_url')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->dateTime('replay_available_from')->nullable();
            $table->dateTime('replay_available_until')->nullable();
            $table->string('visibility', 50)->default('cohort');
            $table->string('status', 50)->default('pending');
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->foreign('customer_id', 'fk_lcrec_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('session_id', 'fk_lcrec_session')->references('id')->on('core_liveclass_sessions')->restrictOnDelete();
            $table->foreign('media_file_id', 'fk_lcrec_media')->references('id')->on('media_files')->restrictOnDelete();
            $table->index(['customer_id', 'session_id', 'status'], 'idx_lcrec_session');
        });

        Schema::create('core_liveclass_replays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id');
            $table->foreignId('recording_id');
            $table->foreignId('session_id');
            $table->foreignId('enrollment_id');
            $table->foreignId('user_id');
            $table->foreignId('version_activity_id')->nullable();
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->decimal('watched_percentage', 5, 2)->default(0);
            $table->dateTime('last_watched_at')->nullable();
            $table->timestamps();
            $table->foreign('customer_id', 'fk_lcrp_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('recording_id', 'fk_lcrp_recording')->references('id')->on('core_liveclass_recordings')->restrictOnDelete();
            $table->foreign('session_id', 'fk_lcrp_session')->references('id')->on('core_liveclass_sessions')->restrictOnDelete();
            $table->foreign('enrollment_id', 'fk_lcrp_enrollment')->references('id')->on('core_course_enrollments')->restrictOnDelete();
            $table->foreign('user_id', 'fk_lcrp_user')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('version_activity_id', 'fk_lcrp_activity')->references('id')->on('core_course_template_version_activities')->restrictOnDelete();
            $table->unique(['customer_id', 'recording_id', 'enrollment_id'], 'uk_lcrp_enrollment');
            $table->index(['customer_id', 'session_id'], 'idx_lcrp_session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_liveclass_replays');
        Schema::dropIfExists('core_liveclass_recordings');
        Schema::dropIfExists('core_liveclass_attendances');
        Schema::dropIfExists('core_liveclass_session_schedule_changes');
        Schema::dropIfExists('core_liveclass_session_teachers');
        Schema::dropIfExists('core_liveclass_sessions');
        Schema::dropIfExists('core_liveclass_rooms');
        Schema::dropIfExists('core_course_cohort_teachers');
    }
};
