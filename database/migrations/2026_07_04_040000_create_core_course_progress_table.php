<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('student_id');
            $table->decimal('progress_percentage', 5, 2)->default(0.00);
            $table->unsignedInteger('completed_lessons')->default(0);
            $table->unsignedInteger('total_lessons')->default(0);
            $table->unsignedInteger('completed_activities')->default(0);
            $table->unsignedInteger('total_activities')->default(0);
            $table->unsignedInteger('required_activities_completed')->default(0);
            $table->unsignedInteger('required_activities_total')->default(0);
            $table->unsignedInteger('assessment_completed')->default(0);
            $table->unsignedInteger('assessment_total')->default(0);
            $table->unsignedBigInteger('total_learning_seconds')->default(0);
            $table->unsignedBigInteger('last_version_activity_id')->nullable();
            $table->unsignedBigInteger('last_version_lesson_id')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 50)->default('not_started');
            $table->timestamp('recalculated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_core_course_progress_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('enrollment_id', 'fk_core_course_progress_enrollment')
                ->references('id')
                ->on('core_course_enrollments')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_core_course_progress_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('version_id', 'fk_core_course_progress_version')
                ->references('id')
                ->on('core_course_template_versions')
                ->restrictOnDelete();
            $table->foreign('student_id', 'fk_core_course_progress_student')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_course_progress_customer');
            $table->index(['customer_id', 'enrollment_id'], 'idx_course_progress_enrollment');
            $table->index(['customer_id', 'product_id'], 'idx_course_progress_product');
            $table->index(['customer_id', 'version_id'], 'idx_course_progress_template_version');
            $table->index(['customer_id', 'student_id'], 'idx_course_progress_student');
            $table->index(['customer_id', 'student_id', 'status'], 'idx_course_progress_student_status');
            $table->index(['customer_id', 'product_id', 'status'], 'idx_course_progress_product_status');
            $table->index(['customer_id', 'last_accessed_at'], 'idx_course_progress_last_accessed');
            $table->index(['customer_id', 'completed_at'], 'idx_course_progress_completed_at');
            $table->unique(['customer_id', 'enrollment_id'], 'uniq_course_progress_enrollment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_progress');
    }
};
