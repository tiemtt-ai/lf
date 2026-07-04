<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('student_id');
            $table->string('source', 50)->default('admin');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('enrolled_by')->nullable();
            $table->timestamp('enrolled_at');
            $table->timestamp('access_starts_at')->nullable();
            $table->timestamp('access_ends_at')->nullable();
            $table->timestamp('review_starts_at')->nullable();
            $table->timestamp('review_ends_at')->nullable();
            $table->string('status', 50)->default('active');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_cce_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_cce_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('version_id', 'fk_cce_version')
                ->references('id')
                ->on('core_course_template_versions')
                ->restrictOnDelete();
            $table->foreign('student_id', 'fk_cce_student')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('enrolled_by', 'fk_cce_enrolled_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index('customer_id', 'idx_cce_customer');
            $table->index(['customer_id', 'product_id'], 'idx_cce_product');
            $table->index(['customer_id', 'version_id'], 'idx_cce_version');
            $table->index(['customer_id', 'student_id'], 'idx_cce_student');
            $table->index(
                ['customer_id', 'student_id', 'status'],
                'idx_cce_student_status'
            );
            $table->index(
                ['customer_id', 'product_id', 'status'],
                'idx_cce_product_status'
            );
            $table->index(
                ['customer_id', 'access_ends_at'],
                'idx_cce_access_ends'
            );
            $table->index(
                ['customer_id', 'enrolled_at'],
                'idx_cce_enrolled_at'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_enrollments');
    }
};
