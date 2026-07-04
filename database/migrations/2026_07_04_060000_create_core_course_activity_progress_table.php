<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_activity_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('course_progress_id');
            $table->unsignedBigInteger('lesson_progress_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('version_section_id')->nullable();
            $table->unsignedBigInteger('version_lesson_id');
            $table->unsignedBigInteger('version_activity_id');
            $table->string('activity_type', 50);
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('is_required')->default(true);
            $table->decimal('progress_percentage', 5, 2)->default(0.00);
            $table->string('completion_rule', 50)->default('manual');
            $table->decimal('completion_threshold', 5, 2)->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedBigInteger('total_learning_seconds')->default(0);
            $table->unsignedBigInteger('last_position_seconds')->nullable();
            $table->timestamp('first_accessed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->string('status', 50)->default('not_started');
            $table->timestamp('recalculated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_core_course_activity_progress_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('enrollment_id', 'fk_core_course_activity_progress_enrollment')
                ->references('id')
                ->on('core_course_enrollments')
                ->restrictOnDelete();
            $table->foreign('course_progress_id', 'fk_core_course_activity_progress_course_progress')
                ->references('id')
                ->on('core_course_progress')
                ->restrictOnDelete();
            $table->foreign('lesson_progress_id', 'fk_core_course_activity_progress_lesson_progress')
                ->references('id')
                ->on('core_course_lesson_progress')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_core_course_activity_progress_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('version_id', 'fk_core_course_activity_progress_version')
                ->references('id')
                ->on('core_course_template_versions')
                ->restrictOnDelete();
            $table->foreign('student_id', 'fk_core_course_activity_progress_student')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('version_lesson_id', 'fk_core_course_activity_progress_version_lesson')
                ->references('id')
                ->on('core_course_template_version_lessons')
                ->restrictOnDelete();
            $table->foreign('version_activity_id', 'fk_core_course_activity_progress_version_activity')
                ->references('id')
                ->on('core_course_template_version_activities')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_course_activity_progress_customer');
            $table->index(['customer_id', 'enrollment_id'], 'idx_course_activity_progress_enrollment');
            $table->index(['customer_id', 'course_progress_id'], 'idx_course_activity_progress_course_progress');
            $table->index(['customer_id', 'lesson_progress_id'], 'idx_course_activity_progress_lesson_progress');
            $table->index(['customer_id', 'product_id'], 'idx_course_activity_progress_product');
            $table->index(['customer_id', 'version_id'], 'idx_course_activity_progress_version');
            $table->index(['customer_id', 'student_id'], 'idx_course_activity_progress_student');
            $table->index(['customer_id', 'version_lesson_id'], 'idx_course_activity_progress_lesson');
            $table->index(['customer_id', 'version_activity_id'], 'idx_course_activity_progress_activity');
            $table->index(['customer_id', 'activity_type'], 'idx_course_activity_progress_type');
            $table->index(['customer_id', 'status'], 'idx_course_activity_progress_status');
            $table->index(['customer_id', 'last_accessed_at'], 'idx_course_activity_progress_last_accessed');
            $table->index(['customer_id', 'completed_at'], 'idx_course_activity_progress_completed_at');
            $table->unique(
                ['customer_id', 'enrollment_id', 'version_activity_id'],
                'uniq_course_activity_progress_enrollment_activity'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_activity_progress');
    }
};
