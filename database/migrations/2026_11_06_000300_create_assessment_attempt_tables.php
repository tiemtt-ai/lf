<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0025 phase 1, taking quizzes: registrations (the access source and attempt counter), attempts locked to
 * a published version, answers, speaking recordings, and the manual grading history.
 */
return new class extends Migration
{
    private const TABLES = [
        'core_assessment_gradings',
        'core_assessment_answer_files',
        'core_assessment_answers',
        'core_assessment_attempts',
        'core_assessment_quiz_registrations',
    ];

    public function up(): void
    {
        Schema::create('core_assessment_quiz_registrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('quiz_id');
            $table->unsignedBigInteger('student_id');
            $table->string('source', 30);
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('attempts_used')->default(0);
            $table->timestamp('registered_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            // 1 while active, NULL otherwise: MariaDB has no partial unique index, and NULLs never collide.
            $table->tinyInteger('active_flag')->nullable()->storedAs("CASE WHEN status = 'active' THEN 1 END");

            $table->unique(['id', 'customer_id'], 'uk_asm_reg_tenant');
            $table->unique(['customer_id', 'quiz_id', 'student_id', 'active_flag'], 'uk_asm_reg_active');
            $table->index(['customer_id', 'quiz_id', 'status'], 'idx_asm_reg_quiz');
            $table->index(['customer_id', 'student_id', 'status'], 'idx_asm_reg_student');
            $table->index(['customer_id', 'granted_by'], 'idx_asm_reg_granted_by');
            $table->foreign('customer_id', 'fk_asm_reg_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['quiz_id', 'customer_id'], 'fk_asm_reg_quiz')
                ->references(['id', 'customer_id'])->on('core_assessment_quizzes')->restrictOnDelete();
            $table->foreign('student_id', 'fk_asm_reg_student')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('granted_by', 'fk_asm_reg_granted_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'fk_asm_reg_cancelled_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('core_assessment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('quiz_id');
            $table->unsignedBigInteger('quiz_version_id');
            $table->unsignedBigInteger('quiz_registration_id')->nullable();
            // Course access source, reserved for when quizzes are bound to courses; no keys until then.
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('template_version_id')->nullable();
            $table->unsignedBigInteger('version_activity_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('attempt_no');
            $table->string('status', 20)->default('in_progress');
            $table->string('grading_status', 20)->default('not_required');
            $table->string('submit_reason', 20)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->unsignedInteger('time_spent_seconds')->nullable();
            $table->decimal('score', 10, 2)->nullable();
            $table->decimal('max_score', 10, 2);
            $table->decimal('score_percentage', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->tinyInteger('in_progress_flag')->nullable()->storedAs("CASE WHEN status = 'in_progress' THEN 1 END");

            $table->unique(['id', 'customer_id'], 'uk_asm_att_tenant');
            $table->unique(['customer_id', 'quiz_registration_id', 'attempt_no'], 'uk_asm_att_number');
            $table->unique(['customer_id', 'quiz_registration_id', 'in_progress_flag'], 'uk_asm_att_in_progress');
            $table->index(['customer_id', 'quiz_id', 'status'], 'idx_asm_att_quiz');
            $table->index(['customer_id', 'quiz_version_id'], 'idx_asm_att_version');
            $table->index(['customer_id', 'user_id', 'status'], 'idx_asm_att_user');
            $table->index(['customer_id', 'grading_status'], 'idx_asm_att_grading');
            $table->index(['customer_id', 'status', 'deadline_at'], 'idx_asm_att_deadline');
            $table->foreign('customer_id', 'fk_asm_att_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['quiz_id', 'customer_id'], 'fk_asm_att_quiz')
                ->references(['id', 'customer_id'])->on('core_assessment_quizzes')->restrictOnDelete();
            $table->foreign(['quiz_version_id', 'customer_id'], 'fk_asm_att_version')
                ->references(['id', 'customer_id'])->on('core_assessment_quiz_versions')->restrictOnDelete();
            $table->foreign(['quiz_registration_id', 'customer_id'], 'fk_asm_att_registration')
                ->references(['id', 'customer_id'])->on('core_assessment_quiz_registrations')->restrictOnDelete();
            $table->foreign('user_id', 'fk_asm_att_user')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('core_assessment_answers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('attempt_id');
            $table->unsignedBigInteger('quiz_version_question_id');
            $table->unsignedBigInteger('question_id');
            $table->string('answer_type', 20);
            $table->json('selected_option_keys')->nullable();
            $table->json('selected_options_snapshot')->nullable();
            $table->longText('answer_text')->nullable();
            $table->decimal('score', 10, 2)->nullable();
            $table->decimal('max_score', 10, 2);
            $table->boolean('is_correct')->nullable();
            $table->string('grading_status', 20)->default('not_required');
            $table->text('feedback')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'customer_id'], 'uk_asm_ans_tenant');
            $table->unique(['customer_id', 'attempt_id', 'quiz_version_question_id'], 'uk_asm_ans_question');
            $table->index(['customer_id', 'quiz_version_question_id'], 'idx_asm_ans_version_question');
            $table->index(['customer_id', 'question_id'], 'idx_asm_ans_source');
            $table->index(['customer_id', 'grading_status'], 'idx_asm_ans_grading');
            $table->foreign('customer_id', 'fk_asm_ans_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['attempt_id', 'customer_id'], 'fk_asm_ans_attempt')
                ->references(['id', 'customer_id'])->on('core_assessment_attempts')->restrictOnDelete();
            $table->foreign(['quiz_version_question_id', 'customer_id'], 'fk_asm_ans_version_question')
                ->references(['id', 'customer_id'])->on('core_assessment_quiz_version_questions')->restrictOnDelete();
            $table->foreign(['question_id', 'customer_id'], 'fk_asm_ans_question')
                ->references(['id', 'customer_id'])->on('core_assessment_questions')->restrictOnDelete();
        });

        Schema::create('core_assessment_answer_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('answer_id');
            $table->unsignedBigInteger('media_file_id');
            $table->string('file_role', 50);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'answer_id', 'file_role'], 'uk_asm_af_role');
            $table->index(['customer_id', 'media_file_id'], 'idx_asm_af_media');
            $table->foreign('customer_id', 'fk_asm_af_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['answer_id', 'customer_id'], 'fk_asm_af_answer')
                ->references(['id', 'customer_id'])->on('core_assessment_answers')->restrictOnDelete();
            $table->foreign(['media_file_id', 'customer_id'], 'fk_asm_af_media')
                ->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
        });

        Schema::create('core_assessment_gradings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('attempt_id');
            $table->unsignedBigInteger('answer_id');
            $table->unsignedBigInteger('grader_user_id')->nullable();
            $table->string('grading_type', 20);
            $table->decimal('score', 10, 2);
            $table->text('feedback')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamp('graded_at');
            $table->timestamps();
            $table->tinyInteger('current_flag')->nullable()->storedAs('CASE WHEN is_current = 1 THEN 1 END');

            $table->unique(['customer_id', 'answer_id', 'current_flag'], 'uk_asm_grd_current');
            $table->index(['customer_id', 'attempt_id'], 'idx_asm_grd_attempt');
            $table->index(['customer_id', 'grader_user_id'], 'idx_asm_grd_grader');
            $table->foreign('customer_id', 'fk_asm_grd_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['attempt_id', 'customer_id'], 'fk_asm_grd_attempt')
                ->references(['id', 'customer_id'])->on('core_assessment_attempts')->restrictOnDelete();
            $table->foreign(['answer_id', 'customer_id'], 'fk_asm_grd_answer')
                ->references(['id', 'customer_id'])->on('core_assessment_answers')->restrictOnDelete();
            $table->foreign('grader_user_id', 'fk_asm_grd_grader')->references('id')->on('users')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE core_assessment_quiz_registrations
            ADD CONSTRAINT chk_asm_reg_source CHECK (source IN ('self_registered','granted')),
            ADD CONSTRAINT chk_asm_reg_granted CHECK ((source = 'granted') = (granted_by IS NOT NULL)),
            ADD CONSTRAINT chk_asm_reg_status CHECK (status IN ('active','exhausted','cancelled')),
            ADD CONSTRAINT chk_asm_reg_cancelled CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL))");
        DB::statement("ALTER TABLE core_assessment_attempts
            ADD CONSTRAINT chk_asm_att_source CHECK ((quiz_registration_id IS NOT NULL) + (enrollment_id IS NOT NULL) = 1),
            ADD CONSTRAINT chk_asm_att_status CHECK (status IN ('in_progress','submitted','graded')),
            ADD CONSTRAINT chk_asm_att_grading CHECK (grading_status IN ('not_required','pending','completed')),
            ADD CONSTRAINT chk_asm_att_reason CHECK (submit_reason IS NULL OR submit_reason IN ('student','timeout')),
            ADD CONSTRAINT chk_asm_att_submitted CHECK ((status = 'in_progress') = (submitted_at IS NULL)),
            ADD CONSTRAINT chk_asm_att_number CHECK (attempt_no >= 1),
            ADD CONSTRAINT chk_asm_att_percentage CHECK (score_percentage IS NULL OR (score_percentage >= 0 AND score_percentage <= 100))");
        DB::statement("ALTER TABLE core_assessment_answers
            ADD CONSTRAINT chk_asm_ans_type CHECK (answer_type IN ('option','text','audio')),
            ADD CONSTRAINT chk_asm_ans_grading CHECK (grading_status IN ('not_required','pending','graded')),
            ADD CONSTRAINT chk_asm_ans_max CHECK (max_score >= 0),
            ADD CONSTRAINT chk_asm_ans_score CHECK (score IS NULL OR (score >= 0 AND score <= max_score))");
        DB::statement("ALTER TABLE core_assessment_answer_files
            ADD CONSTRAINT chk_asm_af_role CHECK (file_role IN ('speaking_recording'))");
        DB::statement("ALTER TABLE core_assessment_gradings
            ADD CONSTRAINT chk_asm_grd_type CHECK (grading_type IN ('teacher','system_auto')),
            ADD CONSTRAINT chk_asm_grd_grader CHECK ((grading_type = 'teacher') = (grader_user_id IS NOT NULL)),
            ADD CONSTRAINT chk_asm_grd_score CHECK (score >= 0)");
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $rows = Schema::hasTable($table) ? DB::table($table)->count() : 0;
            if ($rows > 0) {
                throw new RuntimeException(
                    "Rollback refused: {$table} has {$rows} row(s) of student work or grading evidence. "
                    .'Archive them deliberately before rolling back.'
                );
            }
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
