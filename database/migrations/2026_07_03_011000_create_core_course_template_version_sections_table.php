<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'core_course_template_version_sections',
            function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('template_version_id');
                $table->unsignedBigInteger('source_template_section_id');
                $table->unsignedBigInteger('parent_version_section_id')
                    ->nullable();
                $table->string('code_snapshot', 100)->nullable();
                $table->string('title_snapshot');
                $table->string('short_title_snapshot', 100)->nullable();
                $table->text('description_snapshot')->nullable();
                $table->unsignedBigInteger('thumbnail_file_id_snapshot')
                    ->nullable();
                $table->unsignedInteger('sort_order')->default(1);
                $table->boolean('is_required')->default(true);
                $table->string('unlock_rule_snapshot', 50)
                    ->default('immediate');
                $table->unsignedInteger('estimated_duration_minutes')
                    ->nullable();
                $table->unsignedInteger('total_lessons')->default(0);
                $table->string('status_snapshot', 50);
                $table->json('metadata_snapshot')->nullable();
                $table->timestamps();

                $table->foreign('customer_id', 'fk_cctvs_customer')
                    ->references('id')
                    ->on('saas_customers')
                    ->restrictOnDelete();
                $table->foreign(
                    'template_version_id',
                    'fk_cctvs_version'
                )
                    ->references('id')
                    ->on('core_course_template_versions')
                    ->restrictOnDelete();
                $table->foreign(
                    'parent_version_section_id',
                    'fk_cctvs_parent'
                )
                    ->references('id')
                    ->on('core_course_template_version_sections')
                    ->restrictOnDelete();

                $table->index('customer_id', 'idx_cctvs_customer');
                $table->index(
                    ['customer_id', 'template_version_id'],
                    'idx_cctvs_version'
                );
                $table->index(
                    ['customer_id', 'source_template_section_id'],
                    'idx_cctvs_source'
                );
                $table->index(
                    [
                        'customer_id',
                        'template_version_id',
                        'parent_version_section_id',
                    ],
                    'idx_cctvs_parent'
                );
                $table->index(
                    [
                        'customer_id',
                        'template_version_id',
                        'parent_version_section_id',
                        'sort_order',
                    ],
                    'idx_cctvs_sort'
                );
                $table->unique(
                    [
                        'customer_id',
                        'template_version_id',
                        'source_template_section_id',
                    ],
                    'uk_cctvs_source'
                );
                $table->unique(
                    [
                        'customer_id',
                        'template_version_id',
                        'parent_version_section_id',
                        'sort_order',
                    ],
                    'uk_cctvs_sort'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('core_course_template_version_sections');
    }
};
