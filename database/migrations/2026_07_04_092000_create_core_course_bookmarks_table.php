<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('version_section_id')->nullable();
            $table->unsignedBigInteger('version_lesson_id')->nullable();
            $table->unsignedBigInteger('version_activity_id')->nullable();
            $table->string('bookmark_type', 50)->default('activity');
            $table->string('title')->nullable();
            $table->unsignedBigInteger('position_seconds')->nullable();
            $table->unsignedInteger('page_number')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 50)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_core_course_bookmarks_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('product_id', 'fk_core_course_bookmarks_product')
                ->references('id')
                ->on('core_course_products')
                ->restrictOnDelete();
            $table->foreign('version_id', 'fk_core_course_bookmarks_version')
                ->references('id')
                ->on('core_course_template_versions')
                ->restrictOnDelete();
            $table->foreign('enrollment_id', 'fk_core_course_bookmarks_enrollment')
                ->references('id')
                ->on('core_course_enrollments')
                ->restrictOnDelete();
            $table->foreign('user_id', 'fk_core_course_bookmarks_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('version_lesson_id', 'fk_core_course_bookmarks_version_lesson')
                ->references('id')
                ->on('core_course_template_version_lessons')
                ->restrictOnDelete();
            $table->foreign('version_activity_id', 'fk_core_course_bookmarks_version_activity')
                ->references('id')
                ->on('core_course_template_version_activities')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_course_bookmarks_customer');
            $table->index(['customer_id', 'user_id'], 'idx_course_bookmarks_user');
            $table->index(['customer_id', 'product_id'], 'idx_course_bookmarks_product');
            $table->index(['customer_id', 'version_id'], 'idx_course_bookmarks_version');
            $table->index(['customer_id', 'enrollment_id'], 'idx_course_bookmarks_enrollment');
            $table->index(['customer_id', 'version_lesson_id'], 'idx_course_bookmarks_lesson');
            $table->index(['customer_id', 'version_activity_id'], 'idx_course_bookmarks_activity');
            $table->index(['customer_id', 'bookmark_type'], 'idx_course_bookmarks_type');
            $table->index(['customer_id', 'status'], 'idx_course_bookmarks_status');
            $table->index(['customer_id', 'created_at'], 'idx_course_bookmarks_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_bookmarks');
    }
};
