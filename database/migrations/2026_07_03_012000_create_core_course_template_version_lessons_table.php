<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'core_course_template_version_lessons',
            function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('template_version_id');
                $table->unsignedBigInteger('version_section_id')->nullable();
                $table->unsignedBigInteger('source_template_lesson_id');
                $table->string('title_snapshot');
                $table->string('slug_snapshot')->nullable();
                $table->string('short_description_snapshot', 500)->nullable();
                $table->text('description_snapshot')->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_preview')->default(false);
                $table->text('learning_objective_snapshot')->nullable();
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->unsignedInteger('activity_count')->default(0);
                $table->string('unlock_rule_snapshot', 50)->default('none');
                $table->unsignedBigInteger('unlock_after_version_lesson_id')
                    ->nullable();
                $table->dateTime('unlock_at_snapshot')->nullable();
                $table->string('status_snapshot', 50);
                $table->unsignedBigInteger('created_by_snapshot')->nullable();
                $table->timestamps();

                $table->foreign('customer_id', 'fk_cctvl_customer')
                    ->references('id')
                    ->on('saas_customers')
                    ->restrictOnDelete();
                $table->foreign(
                    'template_version_id',
                    'fk_cctvl_version'
                )
                    ->references('id')
                    ->on('core_course_template_versions')
                    ->restrictOnDelete();
                $table->foreign(
                    'version_section_id',
                    'fk_cctvl_section'
                )
                    ->references('id')
                    ->on('core_course_template_version_sections')
                    ->restrictOnDelete();
                $table->foreign(
                    'unlock_after_version_lesson_id',
                    'fk_cctvl_unlock'
                )
                    ->references('id')
                    ->on('core_course_template_version_lessons')
                    ->restrictOnDelete();

                $table->index('customer_id', 'idx_cctvl_customer');
                $table->index(
                    ['customer_id', 'template_version_id'],
                    'idx_cctvl_version'
                );
                $table->index(
                    [
                        'customer_id',
                        'template_version_id',
                        'version_section_id',
                    ],
                    'idx_cctvl_section'
                );
                $table->index(
                    ['customer_id', 'source_template_lesson_id'],
                    'idx_cctvl_source'
                );
                $table->index(
                    [
                        'customer_id',
                        'template_version_id',
                        'unlock_after_version_lesson_id',
                    ],
                    'idx_cctvl_unlock'
                );
                $table->index(
                    [
                        'customer_id',
                        'template_version_id',
                        'version_section_id',
                        'sort_order',
                    ],
                    'idx_cctvl_sort'
                );
                $table->unique(
                    [
                        'customer_id',
                        'template_version_id',
                        'source_template_lesson_id',
                    ],
                    'uk_cctvl_source'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_template_version_lessons');
    }
};
