<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_favorites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('student_id');
            $table->string('source', 50)->default('manual');
            $table->string('note', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_core_course_favorites_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_core_course_favorites_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('student_id', 'fk_core_course_favorites_student')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_course_favorites_customer');
            $table->index(['customer_id', 'student_id'], 'idx_course_favorites_student');
            $table->index(['customer_id', 'product_id'], 'idx_course_favorites_product');
            $table->index(['customer_id', 'created_at'], 'idx_course_favorites_created');
            $table->unique(
                ['customer_id', 'student_id', 'product_id'],
                'uniq_course_favorites_student_product'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_favorites');
    }
};
