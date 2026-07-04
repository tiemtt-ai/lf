<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('template_code', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('template_version')->default(1);
            $table->string('language', 10)->default('en');
            $table->unsignedBigInteger('background_file_id')->nullable();
            $table->unsignedBigInteger('logo_file_id')->nullable();
            $table->unsignedBigInteger('signature_file_id')->nullable();
            $table->unsignedBigInteger('seal_file_id')->nullable();
            $table->string('title');
            $table->string('subtitle', 500)->nullable();
            $table->text('content_template');
            $table->string('certificate_number_prefix', 50)->nullable();
            $table->unsignedInteger('default_validity_days')->nullable();
            $table->string('render_engine', 50)->default('html_pdf');
            $table->json('layout_data')->nullable();
            $table->boolean('qr_code_enabled')->default(true);
            $table->boolean('verification_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('status', 50)->default('active');
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_cert_templates_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('created_by', 'fk_cert_templates_created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('updated_by', 'fk_cert_templates_updated_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index('customer_id', 'idx_cert_templates_customer');
            $table->index(['customer_id', 'status'], 'idx_cert_templates_status');
            $table->index(['customer_id', 'is_default'], 'idx_cert_templates_default');
            $table->index(['customer_id', 'language'], 'idx_cert_templates_language');
            $table->index(['customer_id', 'sort_order'], 'idx_cert_templates_sort');
            $table->unique(['customer_id', 'template_code'], 'uk_cert_templates_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_certificate_templates');
    }
};
