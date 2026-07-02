<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('core_course_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('thumbnail_image', 500)->nullable();
            $table->string('banner_image', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('meta_keywords', 500)->nullable();
            $table->string('status', 50);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_cc_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('parent_id', 'fk_cc_parent')
                ->references('id')
                ->on('core_course_categories')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_cc_customer');
            $table->index(
                ['customer_id', 'parent_id'],
                'idx_cc_customer_parent'
            );
            $table->index(
                ['customer_id', 'status'],
                'idx_cc_customer_status'
            );
            $table->index(
                ['customer_id', 'sort_order'],
                'idx_cc_customer_sort'
            );
            $table->unique(
                ['customer_id', 'slug'],
                'uk_cc_customer_slug'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_course_categories');
    }
};
