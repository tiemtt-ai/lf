<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->string('cover_type', 50)->default('image');
            $table->unsignedBigInteger('cover_image_media_file_id')->nullable();
            $table->unsignedBigInteger('intro_video_media_file_id')->nullable();
        });

        DB::table('core_course_templates')->update([
            'cover_type' => DB::raw(
                "CASE WHEN thumbnail_type = 'video' THEN 'video' ELSE 'image' END"
            ),
            'intro_video_media_file_id' => DB::raw('thumbnail_video_media_id'),
        ]);

        DB::table('core_course_templates')
            ->whereNotNull('intro_video_media_file_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('media_files')
                    ->whereColumn(
                        'media_files.customer_id',
                        'core_course_templates.customer_id'
                    )
                    ->whereColumn(
                        'media_files.id',
                        'core_course_templates.intro_video_media_file_id'
                    );
            })
            ->update(['intro_video_media_file_id' => null]);

        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->foreign(
                'cover_image_media_file_id',
                'fk_cct_cover_image_media'
            )
                ->references('id')
                ->on('media_files')
                ->restrictOnDelete();
            $table->foreign(
                'intro_video_media_file_id',
                'fk_cct_intro_video_media'
            )
                ->references('id')
                ->on('media_files')
                ->restrictOnDelete();
            $table->index(
                ['customer_id', 'cover_image_media_file_id'],
                'idx_cct_cover_image_media'
            );
            $table->index(
                ['customer_id', 'intro_video_media_file_id'],
                'idx_cct_intro_video_media'
            );
        });

        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->dropColumn([
                'thumbnail_type',
                'thumbnail_image',
                'thumbnail_video_source',
                'thumbnail_video_url',
                'thumbnail_video_media_id',
            ]);
        });

        Schema::table('core_course_template_versions', function (Blueprint $table): void {
            $table->string('cover_type_snapshot', 50)->default('image');
            $table->unsignedBigInteger('cover_image_media_file_id_snapshot')
                ->nullable();
            $table->unsignedBigInteger('intro_video_media_file_id_snapshot')
                ->nullable();
        });

        DB::table('core_course_template_versions')->update([
            'cover_type_snapshot' => DB::raw(
                "CASE WHEN thumbnail_type_snapshot = 'video' THEN 'video' ELSE 'image' END"
            ),
            'intro_video_media_file_id_snapshot' => DB::raw(
                'thumbnail_video_media_id_snapshot'
            ),
        ]);

        DB::table('core_course_template_versions')
            ->whereNotNull('intro_video_media_file_id_snapshot')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('media_files')
                    ->whereColumn(
                        'media_files.customer_id',
                        'core_course_template_versions.customer_id'
                    )
                    ->whereColumn(
                        'media_files.id',
                        'core_course_template_versions.intro_video_media_file_id_snapshot'
                    );
            })
            ->update(['intro_video_media_file_id_snapshot' => null]);

        Schema::table('core_course_template_versions', function (Blueprint $table): void {
            $table->foreign(
                'cover_image_media_file_id_snapshot',
                'fk_cctv_cover_image_media'
            )
                ->references('id')
                ->on('media_files')
                ->restrictOnDelete();
            $table->foreign(
                'intro_video_media_file_id_snapshot',
                'fk_cctv_intro_video_media'
            )
                ->references('id')
                ->on('media_files')
                ->restrictOnDelete();
        });

        Schema::table('core_course_template_versions', function (Blueprint $table): void {
            $table->dropColumn([
                'thumbnail_type_snapshot',
                'thumbnail_image_snapshot',
                'thumbnail_video_source_snapshot',
                'thumbnail_video_url_snapshot',
                'thumbnail_video_media_id_snapshot',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('core_course_template_versions', function (Blueprint $table): void {
            $table->string('thumbnail_type_snapshot', 50)->default('image');
            $table->string('thumbnail_image_snapshot', 500)->nullable();
            $table->string('thumbnail_video_source_snapshot', 50)->nullable();
            $table->string('thumbnail_video_url_snapshot', 1000)->nullable();
            $table->unsignedBigInteger('thumbnail_video_media_id_snapshot')
                ->nullable();
        });

        DB::table('core_course_template_versions')->update([
            'thumbnail_type_snapshot' => DB::raw('cover_type_snapshot'),
            'thumbnail_video_media_id_snapshot' => DB::raw(
                'intro_video_media_file_id_snapshot'
            ),
        ]);

        Schema::table('core_course_template_versions', function (Blueprint $table): void {
            $table->dropForeign('fk_cctv_cover_image_media');
            $table->dropForeign('fk_cctv_intro_video_media');
            $table->dropColumn([
                'cover_type_snapshot',
                'cover_image_media_file_id_snapshot',
                'intro_video_media_file_id_snapshot',
            ]);
        });

        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->string('thumbnail_type', 50)->default('image');
            $table->string('thumbnail_image', 500)->nullable();
            $table->string('thumbnail_video_source', 50)->nullable();
            $table->string('thumbnail_video_url', 1000)->nullable();
            $table->unsignedBigInteger('thumbnail_video_media_id')->nullable();
        });

        DB::table('core_course_templates')->update([
            'thumbnail_type' => DB::raw('cover_type'),
            'thumbnail_video_media_id' => DB::raw('intro_video_media_file_id'),
        ]);

        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->dropForeign('fk_cct_cover_image_media');
            $table->dropForeign('fk_cct_intro_video_media');
            $table->dropIndex('idx_cct_cover_image_media');
            $table->dropIndex('idx_cct_intro_video_media');
            $table->dropColumn([
                'cover_type',
                'cover_image_media_file_id',
                'intro_video_media_file_id',
            ]);
        });
    }
};
