<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_template_activities', function (Blueprint $table) {
            $table->dropIndex('idx_ccta_reference');
            $table->dropIndex('idx_ccta_status');
            $table->string('external_video_url', 1000)->nullable()->after('activity_type');
            $table->string('live_class_url', 1000)->nullable()->after('external_video_url');
            $table->unsignedBigInteger('assessment_quiz_id')->nullable()->after('live_class_url');
            $table->dropColumn(['activity_ref_type', 'activity_ref_id', 'external_url', 'embed_code', 'status']);
        });

        Schema::table('core_course_template_version_activities', function (Blueprint $table) {
            $table->dropIndex('idx_cctva_reference');
            $table->string('external_video_url_snapshot', 1000)->nullable()->after('activity_type');
            $table->string('live_class_url_snapshot', 1000)->nullable()->after('external_video_url_snapshot');
            $table->unsignedBigInteger('assessment_quiz_id_snapshot')->nullable()->after('live_class_url_snapshot');
            $table->dropColumn(['activity_ref_type_snapshot', 'activity_ref_id_snapshot', 'external_url_snapshot', 'embed_code_snapshot', 'status_snapshot']);
        });
    }

    public function down(): void
    {
        Schema::table('core_course_template_activities', function (Blueprint $table) {
            $table->string('activity_ref_type', 100)->nullable()->after('activity_type');
            $table->unsignedBigInteger('activity_ref_id')->nullable()->after('activity_ref_type');
            $table->string('external_url', 1000)->nullable()->after('activity_ref_id');
            $table->longText('embed_code')->nullable()->after('external_url');
            $table->string('status', 50)->default('draft')->after('unlock_at');
            $table->index(['customer_id', 'activity_ref_type', 'activity_ref_id'], 'idx_ccta_reference');
            $table->index(['customer_id', 'status'], 'idx_ccta_status');
            $table->dropColumn(['external_video_url', 'live_class_url', 'assessment_quiz_id']);
        });

        Schema::table('core_course_template_version_activities', function (Blueprint $table) {
            $table->string('activity_ref_type_snapshot', 100)->nullable()->after('activity_type');
            $table->unsignedBigInteger('activity_ref_id_snapshot')->nullable()->after('activity_ref_type_snapshot');
            $table->string('external_url_snapshot', 1000)->nullable()->after('activity_ref_id_snapshot');
            $table->longText('embed_code_snapshot')->nullable()->after('external_url_snapshot');
            $table->string('status_snapshot', 50)->default('draft')->after('unlock_at_snapshot');
            $table->index(['customer_id', 'activity_ref_type_snapshot', 'activity_ref_id_snapshot'], 'idx_cctva_reference');
            $table->dropColumn(['external_video_url_snapshot', 'live_class_url_snapshot', 'assessment_quiz_id_snapshot']);
        });
    }
};
