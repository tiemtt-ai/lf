<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('course_progress_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('version_section_id')->nullable();
            $table->unsignedBigInteger('version_lesson_id');
            $table->unsignedInteger('sort_order')->default(1);
            $table->decimal('progress_percentage', 5, 2)->default(0.00);
            $table->unsignedInteger('completed_activities')->default(0);
            $table->unsignedInteger('total_activities')->default(0);
            $table->unsignedInteger('required_activities_completed')->default(0);
            $table->unsignedInteger('required_activities_total')->default(0);
            $table->unsignedBigInteger('total_learning_seconds')->default(0);
            $table->timestamp('first_accessed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 50)->default('not_started');
            $table->timestamp('recalculated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_core_course_lesson_progress_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('enrollment_id', 'fk_core_course_lesson_progress_enrollment')
                ->references('id')
                ->on('core_course_enrollments')
                ->restrictOnDelete();
            $table->foreign('course_progress_id', 'fk_core_course_lesson_progress_course_progress')
                ->references('id')
                ->on('core_course_progress')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_core_course_lesson_progress_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('version_id', 'fk_core_course_lesson_progress_version')
                ->references('id')
                ->on('core_course_template_versions')
                ->restrictOnDelete();
            $table->foreign('student_id', 'fk_core_course_lesson_progress_student')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('version_lesson_id', 'fk_core_course_lesson_progress_version_lesson')
                ->references('id')
                ->on('core_course_template_version_lessons')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_course_lesson_progress_customer');
            $table->index(['customer_id', 'enrollment_id'], 'idx_course_lesson_progress_enrollment');
            $table->index(['customer_id', 'course_progress_id'], 'idx_course_lesson_progress_course_progress');
            $table->index(['customer_id', 'product_id'], 'idx_course_lesson_progress_product');
            $table->index(['customer_id', 'version_id'], 'idx_course_lesson_progress_version');
            $table->index(['customer_id', 'student_id'], 'idx_course_lesson_progress_student');
            $table->index(['customer_id', 'version_lesson_id'], 'idx_course_lesson_progress_lesson');
            $table->index(['customer_id', 'version_section_id'], 'idx_course_lesson_progress_section');
            $table->index(['customer_id', 'status'], 'idx_course_lesson_progress_status');
            $table->index(['customer_id', 'last_accessed_at'], 'idx_course_lesson_progress_last_accessed');
            $table->index(['customer_id', 'completed_at'], 'idx_course_lesson_progress_completed_at');
            $table->unique(
                ['customer_id', 'enrollment_id', 'version_lesson_id'],
                'uniq_course_lesson_progress_enrollment_lesson'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_lesson_progress');
    }
};
