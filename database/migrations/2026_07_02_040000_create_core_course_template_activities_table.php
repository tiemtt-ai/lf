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
        Schema::create('core_course_template_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('template_lesson_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('activity_type', 50);
            $table->string('activity_ref_type', 100)->nullable();
            $table->unsignedBigInteger('activity_ref_id')->nullable();
            $table->string('external_url', 1000)->nullable();
            $table->longText('embed_code')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->boolean('is_required')->default(true);
            $table->string('completion_rule', 50)->default('view');
            $table->unsignedInteger('completion_threshold')->nullable();
            $table->boolean('is_preview')->default(false);
            $table->string('unlock_rule', 50)->default('none');
            $table->unsignedBigInteger('unlock_after_activity_id')->nullable();
            $table->timestamp('unlock_at')->nullable();
            $table->string('status', 50);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_ccta_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('template_id', 'fk_ccta_template')
                ->references('id')
                ->on('core_course_templates')
                ->restrictOnDelete();
            $table->foreign('template_lesson_id', 'fk_ccta_lesson')
                ->references('id')
                ->on('core_course_template_lessons')
                ->restrictOnDelete();
            $table->foreign(
                'unlock_after_activity_id',
                'fk_ccta_unlock_activity'
            )
                ->references('id')
                ->on('core_course_template_activities')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_ccta_customer');
            $table->index(
                ['customer_id', 'template_id'],
                'idx_ccta_template'
            );
            $table->index(
                ['customer_id', 'template_lesson_id'],
                'idx_ccta_lesson'
            );
            $table->index(
                ['customer_id', 'activity_type'],
                'idx_ccta_type'
            );
            $table->index(
                ['customer_id', 'activity_ref_type', 'activity_ref_id'],
                'idx_ccta_reference'
            );
            $table->index(
                ['customer_id', 'template_lesson_id', 'sort_order'],
                'idx_ccta_sort'
            );
            $table->index(
                ['customer_id', 'status'],
                'idx_ccta_status'
            );
            $table->index(
                ['customer_id', 'created_by'],
                'idx_ccta_creator'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_course_template_activities');
    }
};
