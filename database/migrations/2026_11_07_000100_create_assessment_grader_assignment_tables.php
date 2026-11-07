<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0027: grader scopes (teachers who grade a category or a quiz), the assignment of an attempt to the teacher who
 * holds it, the "Cho nộp tệp" setting of an essay question, and essay attachments next to speaking recordings.
 * Existing rows need no backfill: no scopes, no assignments, every question allow_file_answer = false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_assessment_grader_scopes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('quiz_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->unique(['customer_id', 'category_id', 'user_id'], 'uk_asm_gs_category');
            $table->unique(['customer_id', 'quiz_id', 'user_id'], 'uk_asm_gs_quiz');
            $table->index(['customer_id', 'user_id'], 'idx_asm_gs_user');
            $table->foreign('customer_id', 'fk_asm_gs_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['category_id', 'customer_id'], 'fk_asm_gs_category')
                ->references(['id', 'customer_id'])->on('core_assessment_categories')->cascadeOnDelete();
            $table->foreign(['quiz_id', 'customer_id'], 'fk_asm_gs_quiz')
                ->references(['id', 'customer_id'])->on('core_assessment_quizzes')->cascadeOnDelete();
            $table->foreign('user_id', 'fk_asm_gs_user')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by', 'fk_asm_gs_created_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('core_assessment_grading_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('attempt_id');
            $table->unsignedBigInteger('assigned_to_user_id');
            $table->unsignedBigInteger('assigned_by_user_id');
            $table->string('assignment_type', 20);
            $table->string('status', 20)->default('active');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('ended_by_user_id')->nullable();
            $table->timestamps();
            $table->tinyInteger('active_flag')->nullable()->storedAs("CASE WHEN status = 'active' THEN 1 END");

            $table->unique(['customer_id', 'attempt_id', 'active_flag'], 'uk_asm_ga_active');
            $table->index(['customer_id', 'attempt_id', 'status'], 'idx_asm_ga_attempt');
            $table->index(['customer_id', 'assigned_to_user_id', 'status'], 'idx_asm_ga_assignee');
            $table->foreign('customer_id', 'fk_asm_ga_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['attempt_id', 'customer_id'], 'fk_asm_ga_attempt')
                ->references(['id', 'customer_id'])->on('core_assessment_attempts')->restrictOnDelete();
            $table->foreign('assigned_to_user_id', 'fk_asm_ga_assignee')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('assigned_by_user_id', 'fk_asm_ga_assigner')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('ended_by_user_id', 'fk_asm_ga_ender')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('core_assessment_questions', function (Blueprint $table): void {
            $table->boolean('allow_file_answer')->default(false)->after('rubric_id');
        });

        // The new unique (also led by customer_id) is added before the old one goes, so the customer foreign key always
        // has an index to use.
        Schema::table('core_assessment_answer_files', function (Blueprint $table): void {
            $table->unique(['customer_id', 'answer_id', 'media_file_id'], 'uk_asm_af_media');
        });
        Schema::table('core_assessment_answer_files', function (Blueprint $table): void {
            $table->dropUnique('uk_asm_af_role');
        });

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE core_assessment_grader_scopes
            ADD CONSTRAINT chk_asm_gs_scope CHECK ((category_id IS NULL) <> (quiz_id IS NULL))');
        DB::statement("ALTER TABLE core_assessment_grading_assignments
            ADD CONSTRAINT chk_asm_ga_type CHECK (assignment_type IN ('assigned','claimed')),
            ADD CONSTRAINT chk_asm_ga_status CHECK (status IN ('active','released','completed')),
            ADD CONSTRAINT chk_asm_ga_ended CHECK ((status = 'active') = (ended_at IS NULL))");
        DB::statement("ALTER TABLE core_assessment_questions
            ADD CONSTRAINT chk_asm_q_file_answer CHECK (allow_file_answer = 0 OR question_type = 'essay')");
        DB::statement('ALTER TABLE core_assessment_answer_files DROP CONSTRAINT chk_asm_af_role');
        DB::statement("ALTER TABLE core_assessment_answer_files
            ADD CONSTRAINT chk_asm_af_role CHECK (file_role IN ('speaking_recording','essay_attachment'))");
    }

    public function down(): void
    {
        $used = [
            'core_assessment_grader_scopes' => DB::table('core_assessment_grader_scopes')->count(),
            'core_assessment_grading_assignments' => DB::table('core_assessment_grading_assignments')->count(),
            'core_assessment_questions.allow_file_answer' => DB::table('core_assessment_questions')->where('allow_file_answer', true)->count(),
            'core_assessment_answer_files (essay_attachment)' => DB::table('core_assessment_answer_files')->where('file_role', 'essay_attachment')->count(),
        ];
        foreach ($used as $place => $rows) {
            if ($rows > 0) {
                throw new RuntimeException("Rollback refused: {$place} has {$rows} row(s) of ADR-0027 data. Remove them deliberately before rolling back.");
            }
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE core_assessment_answer_files DROP CONSTRAINT chk_asm_af_role');
            DB::statement("ALTER TABLE core_assessment_answer_files
                ADD CONSTRAINT chk_asm_af_role CHECK (file_role IN ('speaking_recording'))");
            DB::statement('ALTER TABLE core_assessment_questions DROP CONSTRAINT chk_asm_q_file_answer');
        }

        Schema::table('core_assessment_answer_files', function (Blueprint $table): void {
            $table->unique(['customer_id', 'answer_id', 'file_role'], 'uk_asm_af_role');
        });
        Schema::table('core_assessment_answer_files', function (Blueprint $table): void {
            $table->dropUnique('uk_asm_af_media');
        });
        Schema::table('core_assessment_questions', fn (Blueprint $table) => $table->dropColumn('allow_file_answer'));
        Schema::dropIfExists('core_assessment_grading_assignments');
        Schema::dropIfExists('core_assessment_grader_scopes');
    }
};
