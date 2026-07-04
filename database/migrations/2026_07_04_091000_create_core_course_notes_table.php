<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('version_section_id')->nullable();
            $table->unsignedBigInteger('version_lesson_id')->nullable();
            $table->unsignedBigInteger('version_activity_id')->nullable();
            $table->string('note_type', 50)->default('text');
            $table->string('title')->nullable();
            $table->text('content');
            $table->unsignedBigInteger('position_seconds')->nullable();
            $table->unsignedInteger('page_number')->nullable();
            $table->string('visibility', 50)->default('private');
            $table->boolean('pinned')->default(false);
            $table->string('status', 50)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_core_course_notes_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_core_course_notes_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('version_id', 'fk_core_course_notes_version')
                ->references('id')
                ->on('core_course_template_versions')
                ->restrictOnDelete();
            $table->foreign('enrollment_id', 'fk_core_course_notes_enrollment')
                ->references('id')
                ->on('core_course_enrollments')
                ->restrictOnDelete();
            $table->foreign('user_id', 'fk_core_course_notes_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('version_lesson_id', 'fk_core_course_notes_version_lesson')
                ->references('id')
                ->on('core_course_template_version_lessons')
                ->restrictOnDelete();
            $table->foreign('version_activity_id', 'fk_core_course_notes_version_activity')
                ->references('id')
                ->on('core_course_template_version_activities')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_course_notes_customer');
            $table->index(['customer_id', 'user_id'], 'idx_course_notes_user');
            $table->index(['customer_id', 'product_id'], 'idx_course_notes_product');
            $table->index(['customer_id', 'version_id'], 'idx_course_notes_version');
            $table->index(['customer_id', 'enrollment_id'], 'idx_course_notes_enrollment');
            $table->index(['customer_id', 'version_lesson_id'], 'idx_course_notes_lesson');
            $table->index(['customer_id', 'version_activity_id'], 'idx_course_notes_activity');
            $table->index(['customer_id', 'status'], 'idx_course_notes_status');
            $table->index(['customer_id', 'created_at'], 'idx_course_notes_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_notes');
    }
};
