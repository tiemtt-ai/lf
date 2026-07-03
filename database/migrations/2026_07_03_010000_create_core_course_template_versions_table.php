<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_course_template_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('template_id');
            $table->unsignedInteger('version_number');
            $table->string('version_code', 100);
            $table->boolean('is_current')->default(false);
            $table->unsignedBigInteger('source_category_id')->nullable();
            $table->string('category_name_snapshot')->nullable();
            $table->string('title_snapshot');
            $table->string('slug_snapshot');
            $table->string('short_description_snapshot', 500)->nullable();
            $table->longText('description_snapshot')->nullable();
            $table->string('publisher_name_snapshot')->nullable();
            $table->string('thumbnail_type_snapshot', 50);
            $table->string('thumbnail_image_snapshot', 500)->nullable();
            $table->string('thumbnail_video_source_snapshot', 50)->nullable();
            $table->string('thumbnail_video_url_snapshot', 1000)->nullable();
            $table->unsignedBigInteger('thumbnail_video_media_id_snapshot')
                ->nullable();
            $table->string('difficulty_level_snapshot', 50)->nullable();
            $table->string('language_snapshot', 20)->nullable();
            $table->unsignedInteger('estimated_duration_minutes_snapshot')
                ->default(0);
            $table->unsignedInteger('max_lessons_snapshot')->nullable();
            $table->unsignedInteger('lesson_count_snapshot')->default(0);
            $table->string('meta_title_snapshot')->nullable();
            $table->string('meta_description_snapshot', 500)->nullable();
            $table->string('meta_keywords_snapshot', 500)->nullable();
            $table->unsignedInteger('source_working_revision');
            $table->string('status', 50)->default('draft_snapshot');
            $table->dateTime('published_at')->nullable();
            $table->unsignedBigInteger('published_by');
            $table->dateTime('source_template_updated_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('customer_id', 'fk_cctv_customer')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('template_id', 'fk_cctv_template')
                ->references('id')
                ->on('core_course_templates')
                ->restrictOnDelete();
            $table->foreign('published_by', 'fk_cctv_publisher')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index('customer_id', 'idx_cctv_customer');
            $table->index(
                ['customer_id', 'template_id'],
                'idx_cctv_template'
            );
            $table->index(
                ['customer_id', 'template_id', 'is_current'],
                'idx_cctv_current'
            );
            $table->index(
                ['customer_id', 'status'],
                'idx_cctv_status'
            );
            $table->index(
                ['customer_id', 'template_id', 'published_at'],
                'idx_cctv_published'
            );
            $table->unique(
                ['customer_id', 'template_id', 'version_number'],
                'uk_cctv_number'
            );
            $table->unique(
                ['customer_id', 'version_code'],
                'uk_cctv_code'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_template_versions');
    }
};
