<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_product_relations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('related_product_id');
            $table->string('relation_type', 50);
            $table->string('title_override')->nullable();
            $table->string('description_override', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 50);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_ccpr_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_ccpr_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('related_product_id', 'fk_ccpr_related_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_ccpr_customer');
            $table->index(
                ['customer_id', 'product_id'],
                'idx_ccpr_product'
            );
            $table->index(
                ['customer_id', 'related_product_id'],
                'idx_ccpr_related'
            );
            $table->index(
                ['customer_id', 'relation_type'],
                'idx_ccpr_type'
            );
            $table->index(
                ['customer_id', 'status'],
                'idx_ccpr_status'
            );
            $table->index(
                ['customer_id', 'starts_at'],
                'idx_ccpr_starts'
            );
            $table->index(
                ['customer_id', 'ends_at'],
                'idx_ccpr_ends'
            );
            $table->index(
                ['customer_id', 'sort_order'],
                'idx_ccpr_sort'
            );
            $table->unique(
                ['customer_id', 'product_id', 'related_product_id', 'relation_type'],
                'uk_ccpr_product_related_type'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_product_relations');
    }
};
