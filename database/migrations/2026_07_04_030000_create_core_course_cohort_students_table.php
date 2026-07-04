<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_cohort_students', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('cohort_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->string('status', 50)->default('active');
            $table->unsignedBigInteger('transfer_from_cohort_id')->nullable();
            $table->string('transfer_reason', 500)->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_cccs_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('cohort_id', 'fk_cccs_cohort')
                ->references('id')
                ->on('core_course_cohorts')
                ->restrictOnDelete();
            $table->foreign('enrollment_id', 'fk_cccs_enrollment')
                ->references('id')
                ->on('core_course_enrollments')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_cccs_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('student_id', 'fk_cccs_student')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('assigned_by', 'fk_cccs_assigned_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('transfer_from_cohort_id', 'fk_cccs_transfer_from')
                ->references('id')
                ->on('core_course_cohorts')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_course_cohort_students_customer');
            $table->index(['customer_id', 'cohort_id'], 'idx_course_cohort_students_cohort');
            $table->index(['customer_id', 'enrollment_id'], 'idx_course_cohort_students_enrollment');
            $table->index(['customer_id', 'product_id'], 'idx_course_cohort_students_product');
            $table->index(['customer_id', 'student_id'], 'idx_course_cohort_students_student');
            $table->index(['customer_id', 'status'], 'idx_course_cohort_students_status');
            $table->index(['customer_id', 'joined_at'], 'idx_course_cohort_students_joined');
            $table->unique(['customer_id', 'enrollment_id'], 'uniq_course_cohort_students_enrollment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_cohort_students');
    }
};
