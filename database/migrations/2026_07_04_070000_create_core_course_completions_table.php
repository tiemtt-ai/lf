<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_completions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('course_progress_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('student_id');
            $table->string('completion_rule', 50);
            $table->decimal('required_progress_percentage', 5, 2)->nullable();
            $table->decimal('final_progress_percentage', 5, 2)->default(100.00);
            $table->unsignedInteger('completed_lessons')->default(0);
            $table->unsignedInteger('total_lessons')->default(0);
            $table->unsignedInteger('completed_activities')->default(0);
            $table->unsignedInteger('total_activities')->default(0);
            $table->unsignedInteger('required_activities_completed')->default(0);
            $table->unsignedInteger('required_activities_total')->default(0);
            $table->unsignedInteger('assessment_completed')->default(0);
            $table->unsignedInteger('assessment_total')->default(0);
            $table->decimal('final_score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->timestamp('completed_at');
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->string('completion_source', 50)->default('system');
            $table->string('status', 50)->default('completed');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('revoked_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_core_course_completions_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('enrollment_id', 'fk_core_course_completions_enrollment')
                ->references('id')
                ->on('core_course_enrollments')
                ->restrictOnDelete();
            $table->foreign('course_progress_id', 'fk_core_course_completions_progress')
                ->references('id')
                ->on('core_course_progress')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_core_course_completions_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('version_id', 'fk_core_course_completions_version')
                ->references('id')
                ->on('core_course_template_versions')
                ->restrictOnDelete();
            $table->foreign('student_id', 'fk_core_course_completions_student')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('completed_by', 'fk_core_course_completions_completed_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('revoked_by', 'fk_core_course_completions_revoked_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index('customer_id', 'idx_course_completions_customer');
            $table->index(['customer_id', 'enrollment_id'], 'idx_course_completions_enrollment');
            $table->index(['customer_id', 'course_progress_id'], 'idx_course_completions_progress');
            $table->index(['customer_id', 'product_id'], 'idx_course_completions_product');
            $table->index(['customer_id', 'version_id'], 'idx_course_completions_version');
            $table->index(['customer_id', 'student_id'], 'idx_course_completions_student');
            $table->index(['customer_id', 'completed_at'], 'idx_course_completions_completed_at');
            $table->index(['customer_id', 'status'], 'idx_course_completions_status');
            $table->unique(
                ['customer_id', 'enrollment_id'],
                'uniq_course_completions_enrollment'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_completions');
    }
};
