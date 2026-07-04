<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_certificate_template_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('certificate_template_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('version_id');
            $table->decimal('completion_required_percentage', 5, 2)->default(100.00);
            $table->decimal('minimum_score_percentage', 5, 2)->nullable();
            $table->string('issue_mode', 50)->default('automatic');
            $table->unsignedInteger('validity_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('status', 50)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_cert_tpl_products_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('certificate_template_id', 'fk_cert_tpl_products_template')
                ->references('id')
                ->on('core_certificate_templates')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_cert_tpl_products_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('version_id', 'fk_cert_tpl_products_version')
                ->references('id')
                ->on('core_course_template_versions')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_cert_tpl_products_customer');
            $table->index(
                ['customer_id', 'certificate_template_id'],
                'idx_cert_tpl_products_template'
            );
            $table->index(['customer_id', 'product_id'], 'idx_cert_tpl_products_product');
            $table->index(['customer_id', 'version_id'], 'idx_cert_tpl_products_version');
            $table->index(['customer_id', 'status'], 'idx_cert_tpl_products_status');
            $table->index(
                ['customer_id', 'product_id', 'is_active'],
                'idx_cert_tpl_products_active'
            );
            $table->unique(
                ['customer_id', 'certificate_template_id', 'product_id', 'version_id'],
                'uk_cert_tpl_products_mapping'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_certificate_template_products');
    }
};
