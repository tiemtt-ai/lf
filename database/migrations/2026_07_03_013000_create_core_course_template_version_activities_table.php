<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'core_course_template_version_activities',
            function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('template_version_id');
                $table->unsignedBigInteger('version_lesson_id');
                $table->unsignedBigInteger('source_template_activity_id');
                $table->string('title_snapshot');
                $table->text('description_snapshot')->nullable();
                $table->integer('sort_order')->default(0);
                $table->string('activity_type', 50);
                $table->string('activity_ref_type_snapshot', 100)->nullable();
                $table->unsignedBigInteger('activity_ref_id_snapshot')
                    ->nullable();
                $table->string('external_url_snapshot', 1000)->nullable();
                $table->longText('embed_code_snapshot')->nullable();
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->boolean('is_required')->default(true);
                $table->string('completion_rule', 50)->default('view');
                $table->unsignedInteger('completion_threshold')->nullable();
                $table->boolean('is_preview')->default(false);
                $table->string('unlock_rule_snapshot', 50)->default('none');
                $table->unsignedBigInteger('unlock_after_version_activity_id')
                    ->nullable();
                $table->dateTime('unlock_at_snapshot')->nullable();
                $table->string('status_snapshot', 50);
                $table->unsignedBigInteger('created_by_snapshot')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('customer_id', 'fk_cctva_customer')
                    ->references('id')
                    ->on('saas_customers')
                    ->restrictOnDelete();
                $table->foreign(
                    'template_version_id',
                    'fk_cctva_version'
                )
                    ->references('id')
                    ->on('core_course_template_versions')
                    ->restrictOnDelete();
                $table->foreign(
                    'version_lesson_id',
                    'fk_cctva_lesson'
                )
                    ->references('id')
                    ->on('core_course_template_version_lessons')
                    ->restrictOnDelete();
                $table->foreign(
                    'unlock_after_version_activity_id',
                    'fk_cctva_unlock'
                )
                    ->references('id')
                    ->on('core_course_template_version_activities')
                    ->restrictOnDelete();

                $table->index('customer_id', 'idx_cctva_customer');
                $table->index(
                    ['customer_id', 'template_version_id'],
                    'idx_cctva_version'
                );
                $table->index(
                    [
                        'customer_id',
                        'template_version_id',
                        'version_lesson_id',
                    ],
                    'idx_cctva_lesson'
                );
                $table->index(
                    ['customer_id', 'source_template_activity_id'],
                    'idx_cctva_source'
                );
                $table->index(
                    ['customer_id', 'activity_type'],
                    'idx_cctva_type'
                );
                $table->index(
                    [
                        'customer_id',
                        'activity_ref_type_snapshot',
                        'activity_ref_id_snapshot',
                    ],
                    'idx_cctva_reference'
                );
                $table->index(
                    [
                        'customer_id',
                        'version_lesson_id',
                        'unlock_after_version_activity_id',
                    ],
                    'idx_cctva_unlock'
                );
                $table->index(
                    ['customer_id', 'version_lesson_id', 'sort_order'],
                    'idx_cctva_sort'
                );
                $table->unique(
                    [
                        'customer_id',
                        'template_version_id',
                        'source_template_activity_id',
                    ],
                    'uk_cctva_source'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_template_version_activities');
    }
};
