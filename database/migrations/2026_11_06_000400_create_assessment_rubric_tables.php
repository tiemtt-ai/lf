<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0026: Tiêu chí chấm. Reusable rubrics and their criteria, the rubric chosen on an essay / speaking question, the
 * rubric frozen into each published version question and the per-criterion scores of a grading. Existing rows keep
 * NULL in the new columns and are graded exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_assessment_rubrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('skill_type', 50)->nullable();
            $table->decimal('total_points', 10, 2);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['id', 'customer_id'], 'uk_asm_rb_tenant');
            $table->index(['customer_id', 'status'], 'idx_asm_rb_status');
            $table->index(['customer_id', 'created_by'], 'idx_asm_rb_creator');
            $table->foreign('customer_id', 'fk_asm_rb_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign('created_by', 'fk_asm_rb_created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by', 'fk_asm_rb_updated_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('core_assessment_rubric_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('rubric_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->decimal('max_points', 8, 2);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['customer_id', 'rubric_id', 'sort_order'], 'uk_asm_rbi_order');
            $table->foreign('customer_id', 'fk_asm_rbi_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['rubric_id', 'customer_id'], 'fk_asm_rbi_rubric')
                ->references(['id', 'customer_id'])->on('core_assessment_rubrics')->cascadeOnDelete();
        });

        Schema::table('core_assessment_questions', function (Blueprint $table): void {
            $table->unsignedBigInteger('rubric_id')->nullable()->after('default_points');
            $table->index(['customer_id', 'rubric_id'], 'idx_asm_q_rubric');
            $table->foreign(['rubric_id', 'customer_id'], 'fk_asm_q_rubric')
                ->references(['id', 'customer_id'])->on('core_assessment_rubrics')->restrictOnDelete();
        });

        Schema::table('core_assessment_quiz_version_questions', function (Blueprint $table): void {
            $table->json('rubric_snapshot')->nullable()->after('options_snapshot');
        });

        Schema::table('core_assessment_gradings', function (Blueprint $table): void {
            $table->json('rubric_result')->nullable()->after('feedback');
        });

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE core_assessment_rubrics
            ADD CONSTRAINT chk_asm_rb_status CHECK (status IN ('active','archived')),
            ADD CONSTRAINT chk_asm_rb_skill CHECK (skill_type IS NULL OR skill_type IN ('reading','listening','writing','speaking','other')),
            ADD CONSTRAINT chk_asm_rb_total CHECK (total_points > 0)");
        DB::statement('ALTER TABLE core_assessment_rubric_items
            ADD CONSTRAINT chk_asm_rbi_points CHECK (max_points > 0),
            ADD CONSTRAINT chk_asm_rbi_order CHECK (sort_order >= 1)');
        DB::statement("ALTER TABLE core_assessment_questions
            ADD CONSTRAINT chk_asm_q_rubric CHECK (rubric_id IS NULL OR question_type IN ('essay','speaking'))");
        DB::statement('ALTER TABLE core_assessment_quiz_version_questions
            ADD CONSTRAINT chk_asm_vq_rubric CHECK (rubric_snapshot IS NULL OR requires_manual_grading = 1)');
    }

    public function down(): void
    {
        $used = [
            'core_assessment_rubrics' => Schema::hasTable('core_assessment_rubrics') ? DB::table('core_assessment_rubrics')->count() : 0,
            'core_assessment_quiz_version_questions.rubric_snapshot' => DB::table('core_assessment_quiz_version_questions')->whereNotNull('rubric_snapshot')->count(),
            'core_assessment_gradings.rubric_result' => DB::table('core_assessment_gradings')->whereNotNull('rubric_result')->count(),
        ];
        foreach ($used as $place => $rows) {
            if ($rows > 0) {
                throw new RuntimeException("Rollback refused: {$place} has {$rows} row(s) of rubric data. Remove them deliberately before rolling back.");
            }
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE core_assessment_quiz_version_questions DROP CONSTRAINT chk_asm_vq_rubric');
            DB::statement('ALTER TABLE core_assessment_questions DROP CONSTRAINT chk_asm_q_rubric');
        }

        Schema::table('core_assessment_gradings', fn (Blueprint $table) => $table->dropColumn('rubric_result'));
        Schema::table('core_assessment_quiz_version_questions', fn (Blueprint $table) => $table->dropColumn('rubric_snapshot'));
        Schema::table('core_assessment_questions', function (Blueprint $table): void {
            $table->dropForeign('fk_asm_q_rubric');
            $table->dropIndex('idx_asm_q_rubric');
            $table->dropColumn('rubric_id');
        });
        Schema::dropIfExists('core_assessment_rubric_items');
        Schema::dropIfExists('core_assessment_rubrics');
    }
};
