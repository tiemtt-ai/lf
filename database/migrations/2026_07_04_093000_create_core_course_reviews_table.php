<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedTinyInteger('rating');
            $table->string('title')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_verified_purchase')->default(false);
            $table->string('status', 50)->default('active');
            $table->timestamps();

            $table->foreign('customer_id', 'fk_core_course_reviews_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_core_course_reviews_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('enrollment_id', 'fk_core_course_reviews_enrollment')
                ->references('id')
                ->on('core_course_enrollments')
                ->restrictOnDelete();
            $table->foreign('version_id', 'fk_core_course_reviews_version')
                ->references('id')
                ->on('core_course_template_versions')
                ->restrictOnDelete();
            $table->foreign('user_id', 'fk_core_course_reviews_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_course_reviews_customer');
            $table->index(['customer_id', 'product_id'], 'idx_course_reviews_product');
            $table->index(['customer_id', 'user_id'], 'idx_course_reviews_user');
            $table->index(['customer_id', 'enrollment_id'], 'idx_course_reviews_enrollment');
            $table->index(['customer_id', 'version_id'], 'idx_course_reviews_version');
            $table->index(['customer_id', 'status'], 'idx_course_reviews_status');
            $table->index(['customer_id', 'rating'], 'idx_course_reviews_rating');
            $table->unique(
                ['customer_id', 'enrollment_id'],
                'uniq_course_reviews_enrollment'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_reviews');
    }
};
