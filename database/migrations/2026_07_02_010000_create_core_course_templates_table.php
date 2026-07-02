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
        Schema::create('core_course_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('title');
            $table->string('slug');
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('publisher_name')->nullable();
            $table->string('thumbnail_type', 50);
            $table->string('thumbnail_image', 500)->nullable();
            $table->string('thumbnail_video_source', 50)->nullable();
            $table->string('thumbnail_video_url', 1000)->nullable();
            $table->unsignedBigInteger('thumbnail_video_media_id')->nullable();
            $table->string('difficulty_level', 50)->nullable();
            $table->string('language', 20)->nullable();
            $table->unsignedInteger('estimated_duration_minutes')->default(0);
            $table->unsignedInteger('max_lessons')->nullable();
            $table->unsignedInteger('lesson_count')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('meta_keywords', 500)->nullable();
            $table->unsignedInteger('working_revision')->default(1);
            $table->string('status', 50);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('last_version_published_at')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')
                ->references('id')
                ->on('saas_customers')
                ->restrictOnDelete();
            $table->foreign('category_id')
                ->references('id')
                ->on('core_course_categories')
                ->restrictOnDelete();

            $table->index('customer_id');
            $table->index(['customer_id', 'category_id']);
            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'created_by']);
            $table->index(['customer_id', 'slug']);
            $table->index(['customer_id', 'language']);
            $table->unique(['customer_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_course_templates');
    }
};
