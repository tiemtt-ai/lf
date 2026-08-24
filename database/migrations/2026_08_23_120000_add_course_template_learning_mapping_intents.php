<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Learning Foundation deliberately has no SQLite schema. Keep the
        // cross-domain contract on the same MariaDB/MySQL-only test boundary.
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->unsignedBigInteger('selected_learning_framework_id')->nullable()->after('working_revision');
            $table->unsignedBigInteger('selected_learning_framework_version_id')->nullable()->after('selected_learning_framework_id');
            $table->unique(['id', 'customer_id', 'selected_learning_framework_id', 'selected_learning_framework_version_id'], 'uk_cct_learning_selection');
            $table->foreign(['selected_learning_framework_version_id', 'customer_id', 'selected_learning_framework_id'], 'fk_cct_learning_selection')
                ->references(['id', 'customer_id', 'framework_id'])
                ->on('core_learning_framework_versions')
                ->restrictOnDelete();
        });

        Schema::create('core_course_template_learning_mapping_intents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('template_id');
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('framework_id');
            $table->unsignedBigInteger('framework_version_id');
            $table->unsignedBigInteger('learning_node_id');
            $table->string('mapping_role', 50);
            $table->decimal('weight', 9, 6)->nullable();
            $table->string('origin', 50)->default('manual');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();

            $table->unique(['customer_id', 'template_id', 'source_type', 'source_id', 'learning_node_id', 'mapping_role'], 'uk_cct_lmi_identity');
            $table->index(['customer_id', 'template_id'], 'idx_cct_lmi_template');
            $table->index(['customer_id', 'learning_node_id', 'mapping_role'], 'idx_cct_lmi_node_role');
            $table->foreign(['template_id', 'customer_id', 'framework_id', 'framework_version_id'], 'fk_cct_lmi_selection')
                ->references(['id', 'customer_id', 'selected_learning_framework_id', 'selected_learning_framework_version_id'])
                ->on('core_course_templates')->restrictOnDelete();
            $table->foreign(['learning_node_id', 'customer_id', 'framework_id', 'framework_version_id'], 'fk_cct_lmi_node')
                ->references(['id', 'customer_id', 'framework_id', 'framework_version_id'])
                ->on('core_learning_nodes')->restrictOnDelete();
            $table->foreign(['created_by', 'customer_id'], 'fk_cct_lmi_created_by')
                ->references(['id', 'customer_id'])->on('users')->restrictOnDelete();
            $table->foreign(['updated_by', 'customer_id'], 'fk_cct_lmi_updated_by')
                ->references(['id', 'customer_id'])->on('users')->restrictOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("ALTER TABLE core_course_template_learning_mapping_intents
                ADD CONSTRAINT chk_cct_lmi_source CHECK (source_type IN ('course_template_lesson', 'course_template_activity')),
                ADD CONSTRAINT chk_cct_lmi_role CHECK (mapping_role IN ('teaches', 'practices', 'assesses')),
                ADD CONSTRAINT chk_cct_lmi_weight CHECK (weight IS NULL OR (weight >= 0 AND weight <= 1)),
                ADD CONSTRAINT chk_cct_lmi_origin CHECK (origin = 'manual')");
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::dropIfExists('core_course_template_learning_mapping_intents');
        Schema::table('core_course_templates', function (Blueprint $table): void {
            $table->dropForeign('fk_cct_learning_selection');
            $table->dropUnique('uk_cct_learning_selection');
            $table->dropColumn(['selected_learning_framework_id', 'selected_learning_framework_version_id']);
        });
    }
};
