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
        Schema::create('core_course_template_teachers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('teacher_id');
            $table->string('role', 50);
            $table->integer('sort_order')->default(0);
            $table->string('status', 50);
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_cctt_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('template_id', 'fk_cctt_template')
                ->references('id')
                ->on('core_course_templates')
                ->restrictOnDelete();
            $table->foreign('teacher_id', 'fk_cctt_teacher')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_cctt_customer');
            $table->index(
                ['customer_id', 'template_id'],
                'idx_cctt_template'
            );
            $table->index(
                ['customer_id', 'teacher_id'],
                'idx_cctt_teacher'
            );
            $table->index(
                ['customer_id', 'status'],
                'idx_cctt_status'
            );
            $table->unique(
                ['template_id', 'teacher_id'],
                'uk_cctt_template_teacher'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_course_template_teachers');
    }
};
