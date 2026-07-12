<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->unsignedBigInteger('intro_image_media_file_id')->nullable();
            $table->string('intro_video_source', 50)->nullable();
            $table->string('intro_video_embed_url', 2048)->nullable();
            $table->string('intro_video_provider', 50)->nullable();
            $table->unsignedBigInteger('intro_document_media_file_id')->nullable();
            $table->unsignedInteger('estimated_minutes_per_lesson')->nullable();
            $table->unsignedInteger('estimated_lesson_count')->nullable();
        });
        DB::table('core_course_templates')->update([
            'intro_image_media_file_id' => DB::raw("CASE WHEN cover_type = 'image' THEN cover_image_media_file_id ELSE NULL END"),
            'intro_video_source' => DB::raw("CASE WHEN cover_type = 'video' AND intro_video_media_file_id IS NOT NULL THEN 'upload' ELSE NULL END"),
            'estimated_minutes_per_lesson' => DB::raw('estimated_duration_minutes'),
            'estimated_lesson_count' => DB::raw('max_lessons'),
        ]);

        Schema::table('core_course_template_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('intro_image_media_file_id_snapshot')->nullable();
            $table->string('intro_video_source_snapshot', 50)->nullable();
            $table->string('intro_video_embed_url_snapshot', 2048)->nullable();
            $table->string('intro_video_provider_snapshot', 50)->nullable();
            $table->unsignedBigInteger('intro_document_media_file_id_snapshot')->nullable();
            $table->unsignedInteger('estimated_minutes_per_lesson_snapshot')->nullable();
            $table->unsignedInteger('estimated_lesson_count_snapshot')->nullable();
        });
        DB::table('core_course_template_versions')->update([
            'intro_image_media_file_id_snapshot' => DB::raw("CASE WHEN cover_type_snapshot = 'image' THEN cover_image_media_file_id_snapshot ELSE NULL END"),
            'intro_video_source_snapshot' => DB::raw("CASE WHEN cover_type_snapshot = 'video' AND intro_video_media_file_id_snapshot IS NOT NULL THEN 'upload' ELSE NULL END"),
            'estimated_minutes_per_lesson_snapshot' => DB::raw('estimated_duration_minutes_snapshot'),
            'estimated_lesson_count_snapshot' => DB::raw('max_lessons_snapshot'),
        ]);

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('core_course_templates', fn (Blueprint $table) => $table->dropForeign('fk_cct_cover_image_media'));
            Schema::table('core_course_template_versions', fn (Blueprint $table) => $table->dropForeign('fk_cctv_cover_image_media'));
        } else {
            Schema::table('core_course_templates', fn (Blueprint $table) => $table->dropForeign(['cover_image_media_file_id']));
            Schema::table('core_course_template_versions', fn (Blueprint $table) => $table->dropForeign(['cover_image_media_file_id_snapshot']));
        }
        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->dropIndex('idx_cct_cover_image_media');
            $table->dropIndex('idx_cct_customer_slug');
            $table->dropUnique('uk_cct_customer_slug');
            $table->dropColumn(['slug', 'cover_type', 'cover_image_media_file_id', 'estimated_duration_minutes', 'max_lessons']);
        });
        Schema::table('core_course_template_versions', function (Blueprint $table): void {
            $table->dropColumn(['slug_snapshot', 'cover_type_snapshot', 'cover_image_media_file_id_snapshot', 'estimated_duration_minutes_snapshot', 'max_lessons_snapshot']);
        });
    }

    public function down(): void
    {
        if (DB::table('core_course_templates')->whereNotNull('intro_video_embed_url')->orWhereNotNull('intro_document_media_file_id')->exists()
            || DB::table('core_course_template_versions')->whereNotNull('intro_video_embed_url_snapshot')->orWhereNotNull('intro_document_media_file_id_snapshot')->exists()) {
            throw new RuntimeException('Rollback refused: embed/document introduction data cannot be represented losslessly.');
        }
        throw new RuntimeException('Rollback refused unless a reviewed lossless legacy restoration procedure is supplied.');
    }
};
