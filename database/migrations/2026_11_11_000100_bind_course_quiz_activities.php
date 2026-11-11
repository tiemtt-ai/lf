<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0032 Course Quiz Activity: a published Quiz Activity freezes the quiz version it was published with, and a
 * course attempt is sourced from the Enrollment (core_course_template_version_activities.md,
 * core_course_template_activities.md, core_assessment_attempts.md, Amendment 2026-11-11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_template_version_activities', function (Blueprint $table): void {
            $table->unsignedBigInteger('assessment_quiz_version_id_snapshot')->nullable()->after('assessment_quiz_id_snapshot');
            $table->unique(['id', 'customer_id'], 'uk_cctva_tenant');
        });

        Schema::table('core_assessment_attempts', function (Blueprint $table): void {
            $table->unique(['customer_id', 'enrollment_id', 'version_activity_id', 'attempt_no'], 'uk_asm_att_enrollment_no');
            $table->unique(['customer_id', 'enrollment_id', 'version_activity_id', 'in_progress_flag'], 'uk_asm_att_enrollment_open');
        });

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('core_course_template_version_activities', function (Blueprint $table): void {
            $table->foreign(['assessment_quiz_id_snapshot', 'customer_id'], 'fk_cctva_quiz')
                ->references(['id', 'customer_id'])->on('core_assessment_quizzes')->restrictOnDelete();
            $table->foreign(['assessment_quiz_version_id_snapshot', 'customer_id'], 'fk_cctva_quiz_version')
                ->references(['id', 'customer_id'])->on('core_assessment_quiz_versions')->restrictOnDelete();
        });
        Schema::table('core_course_template_activities', function (Blueprint $table): void {
            $table->foreign(['assessment_quiz_id', 'customer_id'], 'fk_ccta_quiz')
                ->references(['id', 'customer_id'])->on('core_assessment_quizzes')->restrictOnDelete();
        });
        Schema::table('core_assessment_attempts', function (Blueprint $table): void {
            $table->foreign(['enrollment_id', 'customer_id'], 'fk_asm_att_enrollment')
                ->references(['id', 'customer_id'])->on('core_course_enrollments')->restrictOnDelete();
            $table->foreign(['version_activity_id', 'customer_id'], 'fk_asm_att_version_activity')
                ->references(['id', 'customer_id'])->on('core_course_template_version_activities')->restrictOnDelete();
            $table->foreign('product_id', 'fk_asm_att_product')->references('id')->on('core_course_products')->restrictOnDelete();
            $table->foreign('template_version_id', 'fk_asm_att_template_version')
                ->references('id')->on('core_course_template_versions')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE core_course_template_version_activities
            ADD CONSTRAINT chk_cctva_quiz_ref CHECK (
                (activity_type = 'quiz') = (assessment_quiz_version_id_snapshot IS NOT NULL)
                AND (assessment_quiz_id_snapshot IS NULL) = (assessment_quiz_version_id_snapshot IS NULL))");
        DB::statement('ALTER TABLE core_assessment_attempts
            ADD CONSTRAINT chk_asm_att_course_ctx CHECK (enrollment_id IS NULL
                OR (product_id IS NOT NULL AND template_version_id IS NOT NULL AND version_activity_id IS NOT NULL)),
            ADD CONSTRAINT chk_asm_att_registration_ctx CHECK (quiz_registration_id IS NULL
                OR (product_id IS NULL AND template_version_id IS NULL AND version_activity_id IS NULL))');
    }

    public function down(): void
    {
        $rows = DB::table('core_assessment_attempts')->whereNotNull('enrollment_id')->count()
            + DB::table('core_course_template_version_activities')->whereNotNull('assessment_quiz_version_id_snapshot')->count();
        if ($rows > 0) {
            throw new RuntimeException("Rollback refused: {$rows} course quiz attempt(s) or published Quiz Activities exist (ADR-0032). "
                .'Remove them deliberately before rolling back.');
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE core_assessment_attempts DROP CONSTRAINT chk_asm_att_registration_ctx, DROP CONSTRAINT chk_asm_att_course_ctx');
            DB::statement('ALTER TABLE core_course_template_version_activities DROP CONSTRAINT chk_cctva_quiz_ref');
            // MariaDB keeps the index it made for each foreign key; drop both.
            $drop = [
                'core_assessment_attempts' => ['fk_asm_att_template_version', 'fk_asm_att_product', 'fk_asm_att_version_activity', 'fk_asm_att_enrollment'],
                'core_course_template_activities' => ['fk_ccta_quiz'],
                'core_course_template_version_activities' => ['fk_cctva_quiz_version', 'fk_cctva_quiz'],
            ];
            foreach ($drop as $name => $keys) {
                Schema::table($name, function (Blueprint $table) use ($keys): void {
                    foreach ($keys as $key) {
                        $table->dropForeign($key);
                    }
                });
                Schema::table($name, function (Blueprint $table) use ($keys): void {
                    foreach ($keys as $key) {
                        $table->dropIndex($key);
                    }
                });
            }
        }

        Schema::table('core_assessment_attempts', function (Blueprint $table): void {
            $table->dropUnique('uk_asm_att_enrollment_open');
            $table->dropUnique('uk_asm_att_enrollment_no');
        });
        Schema::table('core_course_template_version_activities', function (Blueprint $table): void {
            $table->dropUnique('uk_cctva_tenant');
            $table->dropColumn('assessment_quiz_version_id_snapshot');
        });
    }
};
