<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_cohorts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('version_id')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->string('name');
            $table->string('code', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 50)->default('draft');
            $table->unsignedInteger('capacity')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_ccco_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_ccco_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('version_id', 'fk_ccco_version')
                ->references('id')
                ->on('core_course_template_versions')
                ->restrictOnDelete();
            $table->foreign('teacher_id', 'fk_ccco_teacher')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_ccco_customer');
            $table->index(['customer_id', 'status'], 'idx_ccco_status');
            $table->index(['customer_id', 'teacher_id'], 'idx_ccco_teacher');
            $table->index(['customer_id', 'product_id'], 'idx_ccco_product');
            $table->index(['customer_id', 'version_id'], 'idx_ccco_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_cohorts');
    }
};
