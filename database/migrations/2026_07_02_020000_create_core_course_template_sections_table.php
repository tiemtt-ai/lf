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
        Schema::create('core_course_template_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('parent_section_id')->nullable();
            $table->string('code', 100)->nullable();
            $table->string('title');
            $table->string('short_title', 100)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('thumbnail_file_id')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('is_required')->default(true);
            $table->string('unlock_rule', 50)->default('immediate');
            $table->unsignedInteger('estimated_duration_minutes')->nullable();
            $table->unsignedInteger('total_lessons')->default(0);
            $table->string('status', 50)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_ccts_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('template_id', 'fk_ccts_template')
                ->references('id')
                ->on('core_course_templates')
                ->restrictOnDelete();
            $table->foreign('parent_section_id', 'fk_ccts_parent')
                ->references('id')
                ->on('core_course_template_sections')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_ccts_customer');
            $table->index(
                ['customer_id', 'template_id'],
                'idx_ccts_template'
            );
            $table->index(
                ['customer_id', 'parent_section_id'],
                'idx_ccts_parent'
            );
            $table->index(
                ['customer_id', 'template_id', 'parent_section_id', 'sort_order'],
                'idx_ccts_sort'
            );
            $table->index(
                ['customer_id', 'template_id', 'status'],
                'idx_ccts_status'
            );
            $table->unique(
                ['customer_id', 'template_id', 'code'],
                'uk_ccts_code'
            );
            $table->unique(
                ['customer_id', 'template_id', 'parent_section_id', 'sort_order'],
                'uk_ccts_sort'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_course_template_sections');
    }
};
