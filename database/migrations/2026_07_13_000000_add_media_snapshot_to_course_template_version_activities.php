<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_template_version_activities', function (Blueprint $table): void {
            $table->unsignedBigInteger('media_file_id')->nullable()->after('activity_type');
            $table->foreign('media_file_id', 'fk_cctva_media_file')->references('id')->on('media_files')->restrictOnDelete();
            $table->index(['customer_id', 'media_file_id'], 'idx_cctva_media');
        });
    }

    public function down(): void
    {
        Schema::table('core_course_template_version_activities', function (Blueprint $table): void {
            $table->dropForeign('fk_cctva_media_file');
            $table->dropIndex('idx_cctva_media');
            $table->dropColumn('media_file_id');
        });
    }
};
