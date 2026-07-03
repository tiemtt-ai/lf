<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_product_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('template_version_id');
            $table->string('title_override')->nullable();
            $table->string('short_description_override', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->string('status', 50);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_ccpi_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_ccpi_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('template_version_id', 'fk_ccpi_version')
                ->references('id')
                ->on('core_course_template_versions')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_ccpi_customer');
            $table->index(
                ['customer_id', 'product_id'],
                'idx_ccpi_product'
            );
            $table->index(
                ['customer_id', 'template_version_id'],
                'idx_ccpi_version'
            );
            $table->index(
                ['customer_id', 'status'],
                'idx_ccpi_status'
            );
            $table->index(
                ['customer_id', 'product_id', 'sort_order'],
                'idx_ccpi_product_sort'
            );
            $table->unique(
                ['customer_id', 'product_id', 'template_version_id'],
                'uk_ccpi_product_version'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_product_items');
    }
};
