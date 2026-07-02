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
        Schema::create('core_course_template_lessons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('template_section_id');
            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_preview')->default(false);
            $table->text('learning_objective')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('activity_count')->default(0);
            $table->string('unlock_rule', 50)->default('none');
            $table->unsignedBigInteger('unlock_after_lesson_id')->nullable();
            $table->timestamp('unlock_at')->nullable();
            $table->string('status', 50);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_cctl_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('template_id', 'fk_cctl_template')
                ->references('id')
                ->on('core_course_templates')
                ->restrictOnDelete();
            $table->foreign('template_section_id', 'fk_cctl_section')
                ->references('id')
                ->on('core_course_template_sections')
                ->restrictOnDelete();
            $table->foreign(
                'unlock_after_lesson_id',
                'fk_cctl_unlock_lesson'
            )
                ->references('id')
                ->on('core_course_template_lessons')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_cctl_customer');
            $table->index(
                ['customer_id', 'template_id'],
                'idx_cctl_template'
            );
            $table->index(
                ['customer_id', 'template_section_id'],
                'idx_cctl_section'
            );
            $table->index(
                ['customer_id', 'template_id', 'status'],
                'idx_cctl_status'
            );
            $table->index([
                'customer_id',
                'template_section_id',
                'sort_order',
            ], 'idx_cctl_sort');
            $table->index(
                ['customer_id', 'slug'],
                'idx_cctl_slug'
            );
            $table->index(
                ['customer_id', 'created_by'],
                'idx_cctl_creator'
            );
            $table->unique(
                ['customer_id', 'template_id', 'slug'],
                'uk_cctl_slug'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_course_template_lessons');
    }
};
