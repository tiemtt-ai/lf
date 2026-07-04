<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_learning_path_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('learning_path_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('prerequisite_product_id')->nullable();
            $table->string('item_type', 50)->default('course_product');
            $table->boolean('is_required')->default(true);
            $table->string('unlock_rule', 50)->default('always_available');
            $table->boolean('completion_required')->default(true);
            $table->string('title_override')->nullable();
            $table->text('description_override')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('status', 50)->default('active');
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_core_course_learning_path_items_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('learning_path_id', 'fk_core_course_learning_path_items_path')
                ->references('id')
                ->on('core_course_learning_paths')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_core_course_learning_path_items_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('prerequisite_product_id', 'fk_core_course_learning_path_items_prereq')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_learning_path_items_customer');
            $table->index(['customer_id', 'learning_path_id'], 'idx_learning_path_items_path');
            $table->index(['customer_id', 'product_id'], 'idx_learning_path_items_product');
            $table->index(
                ['customer_id', 'prerequisite_product_id'],
                'idx_learning_path_items_prerequisite'
            );
            $table->index(
                ['customer_id', 'learning_path_id', 'sort_order'],
                'idx_learning_path_items_sort'
            );
            $table->index(['customer_id', 'status'], 'idx_learning_path_items_status');
            $table->unique(
                ['customer_id', 'learning_path_id', 'product_id'],
                'uniq_learning_path_item_product'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_learning_path_items');
    }
};
