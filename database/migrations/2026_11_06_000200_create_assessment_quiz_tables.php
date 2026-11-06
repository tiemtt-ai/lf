<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0025 phase 1, quizzes: the editable draft (quiz, sections, chosen questions) and the immutable
 * published versions with their frozen questions. Attempts only ever read a version.
 */
return new class extends Migration
{
    private const TABLES = [
        'core_assessment_quiz_version_questions',
        'core_assessment_quiz_versions',
        'core_assessment_quiz_questions',
        'core_assessment_quiz_sections',
        'core_assessment_quizzes',
    ];

    public function up(): void
    {
        Schema::create('core_assessment_quizzes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('assessment_type', 20)->default('exercise');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('max_attempts')->default(1);
            $table->decimal('pass_score_percentage', 5, 2)->nullable();
            $table->string('show_result_mode', 20)->default('after_grading');
            $table->boolean('registration_open')->default(false);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamp('content_updated_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['id', 'customer_id'], 'uk_asm_qz_tenant');
            $table->index(['customer_id', 'category_id'], 'idx_asm_qz_category');
            $table->index(['customer_id', 'created_by'], 'idx_asm_qz_creator');
            $table->index(['customer_id', 'status'], 'idx_asm_qz_status');
            $table->index(['customer_id', 'registration_open', 'status'], 'idx_asm_qz_open');
            $table->foreign('customer_id', 'fk_asm_qz_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['category_id', 'customer_id'], 'fk_asm_qz_category')
                ->references(['id', 'customer_id'])->on('core_assessment_categories')->restrictOnDelete();
            $table->foreign('created_by', 'fk_asm_qz_created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by', 'fk_asm_qz_updated_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('core_assessment_quiz_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('quiz_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('skill_type', 50)->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['id', 'customer_id'], 'uk_asm_qzs_tenant');
            $table->unique(['customer_id', 'quiz_id', 'sort_order'], 'uk_asm_qzs_order');
            $table->foreign('customer_id', 'fk_asm_qzs_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            // Draft structure belongs to the draft quiz; a quiz is only hard-deleted while it has no version.
            $table->foreign(['quiz_id', 'customer_id'], 'fk_asm_qzs_quiz')
                ->references(['id', 'customer_id'])->on('core_assessment_quizzes')->cascadeOnDelete();
        });

        Schema::create('core_assessment_quiz_questions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('quiz_id');
            $table->unsignedBigInteger('quiz_section_id');
            $table->unsignedBigInteger('question_id');
            $table->unsignedInteger('sort_order')->default(1);
            $table->decimal('points', 8, 2);
            $table->timestamps();

            $table->unique(['customer_id', 'quiz_section_id', 'sort_order'], 'uk_asm_qzq_order');
            $table->unique(['customer_id', 'quiz_id', 'question_id'], 'uk_asm_qzq_question');
            $table->index(['customer_id', 'question_id'], 'idx_asm_qzq_source');
            $table->foreign('customer_id', 'fk_asm_qzq_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['quiz_id', 'customer_id'], 'fk_asm_qzq_quiz')
                ->references(['id', 'customer_id'])->on('core_assessment_quizzes')->cascadeOnDelete();
            $table->foreign(['quiz_section_id', 'customer_id'], 'fk_asm_qzq_section')
                ->references(['id', 'customer_id'])->on('core_assessment_quiz_sections')->cascadeOnDelete();
            $table->foreign(['question_id', 'customer_id'], 'fk_asm_qzq_question')
                ->references(['id', 'customer_id'])->on('core_assessment_questions')->restrictOnDelete();
        });

        Schema::create('core_assessment_quiz_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('quiz_id');
            $table->unsignedInteger('version_no');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('assessment_type', 20);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('max_attempts');
            $table->decimal('pass_score_percentage', 5, 2)->nullable();
            $table->string('show_result_mode', 20);
            $table->decimal('total_points', 10, 2);
            $table->unsignedInteger('question_count');
            $table->json('sections_snapshot');
            $table->json('passages_snapshot')->nullable();
            $table->unsignedBigInteger('published_by');
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['id', 'customer_id'], 'uk_asm_qzv_tenant');
            $table->unique(['customer_id', 'quiz_id', 'version_no'], 'uk_asm_qzv_number');
            $table->foreign('customer_id', 'fk_asm_qzv_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['quiz_id', 'customer_id'], 'fk_asm_qzv_quiz')
                ->references(['id', 'customer_id'])->on('core_assessment_quizzes')->restrictOnDelete();
            $table->foreign('published_by', 'fk_asm_qzv_published_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('core_assessment_quiz_version_questions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('quiz_version_id');
            $table->unsignedBigInteger('question_id');
            $table->unsignedBigInteger('passage_id')->nullable();
            $table->unsignedInteger('section_key');
            $table->unsignedInteger('sort_order');
            $table->string('question_type', 30);
            $table->string('skill_type', 50)->nullable();
            $table->decimal('points', 8, 2);
            $table->boolean('requires_manual_grading')->default(false);
            $table->json('question_snapshot');
            $table->json('options_snapshot')->nullable();
            $table->json('correct_answer_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['id', 'customer_id'], 'uk_asm_qzvq_tenant');
            $table->unique(['customer_id', 'quiz_version_id', 'sort_order'], 'uk_asm_qzvq_order');
            $table->unique(['customer_id', 'quiz_version_id', 'question_id'], 'uk_asm_qzvq_question');
            $table->index(['customer_id', 'question_id'], 'idx_asm_qzvq_source');
            $table->index(['customer_id', 'passage_id'], 'idx_asm_qzvq_passage');
            $table->foreign('customer_id', 'fk_asm_qzvq_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['quiz_version_id', 'customer_id'], 'fk_asm_qzvq_version')
                ->references(['id', 'customer_id'])->on('core_assessment_quiz_versions')->restrictOnDelete();
            $table->foreign(['question_id', 'customer_id'], 'fk_asm_qzvq_question')
                ->references(['id', 'customer_id'])->on('core_assessment_questions')->restrictOnDelete();
            $table->foreign(['passage_id', 'customer_id'], 'fk_asm_qzvq_passage')
                ->references(['id', 'customer_id'])->on('core_assessment_passages')->restrictOnDelete();
        });

        // Quiz and version reference each other; this key can only exist once both tables do.
        Schema::table('core_assessment_quizzes', function (Blueprint $table): void {
            $table->foreign(['current_version_id', 'customer_id'], 'fk_asm_qz_current_version')
                ->references(['id', 'customer_id'])->on('core_assessment_quiz_versions')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $types = "'exercise','exam'";
        $modes = "'immediately','after_grading','score_only','hidden'";

        DB::statement("ALTER TABLE core_assessment_quizzes
            ADD CONSTRAINT chk_asm_qz_type CHECK (assessment_type IN ({$types})),
            ADD CONSTRAINT chk_asm_qz_duration CHECK (duration_minutes IS NULL OR duration_minutes >= 1),
            ADD CONSTRAINT chk_asm_qz_attempts CHECK (max_attempts >= 1),
            ADD CONSTRAINT chk_asm_qz_pass CHECK (pass_score_percentage IS NULL OR (pass_score_percentage >= 0 AND pass_score_percentage <= 100)),
            ADD CONSTRAINT chk_asm_qz_result CHECK (show_result_mode IN ({$modes})),
            ADD CONSTRAINT chk_asm_qz_status CHECK (status IN ('draft','published','archived')),
            ADD CONSTRAINT chk_asm_qz_version CHECK ((status = 'draft') = (current_version_id IS NULL)),
            ADD CONSTRAINT chk_asm_qz_open CHECK (registration_open = 0 OR (current_version_id IS NOT NULL AND status <> 'archived'))");
        DB::statement("ALTER TABLE core_assessment_quiz_sections
            ADD CONSTRAINT chk_asm_qzs_skill CHECK (skill_type IS NULL OR skill_type IN ('reading','listening','writing','speaking','other'))");
        DB::statement('ALTER TABLE core_assessment_quiz_questions
            ADD CONSTRAINT chk_asm_qzq_points CHECK (points >= 0)');
        DB::statement("ALTER TABLE core_assessment_quiz_versions
            ADD CONSTRAINT chk_asm_qzv_number CHECK (version_no >= 1),
            ADD CONSTRAINT chk_asm_qzv_type CHECK (assessment_type IN ({$types})),
            ADD CONSTRAINT chk_asm_qzv_duration CHECK (duration_minutes IS NULL OR duration_minutes >= 1),
            ADD CONSTRAINT chk_asm_qzv_attempts CHECK (max_attempts >= 1),
            ADD CONSTRAINT chk_asm_qzv_pass CHECK (pass_score_percentage IS NULL OR (pass_score_percentage >= 0 AND pass_score_percentage <= 100)),
            ADD CONSTRAINT chk_asm_qzv_result CHECK (show_result_mode IN ({$modes})),
            ADD CONSTRAINT chk_asm_qzv_points CHECK (total_points >= 0)");
        DB::statement("ALTER TABLE core_assessment_quiz_version_questions
            ADD CONSTRAINT chk_asm_qzvq_type CHECK (question_type IN ('single_choice','multiple_choice','true_false','short_answer','essay','speaking')),
            ADD CONSTRAINT chk_asm_qzvq_points CHECK (points >= 0),
            ADD CONSTRAINT chk_asm_qzvq_manual CHECK (requires_manual_grading = (question_type IN ('essay','speaking')))");
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $rows = Schema::hasTable($table) ? DB::table($table)->count() : 0;
            if ($rows > 0) {
                throw new RuntimeException(
                    "Rollback refused: {$table} has {$rows} row(s); published versions are the history attempts are graded against. "
                    .'Remove them deliberately before rolling back.'
                );
            }
        }

        if (Schema::hasTable('core_assessment_quizzes')) {
            Schema::table('core_assessment_quizzes', function (Blueprint $table): void {
                $table->dropForeign('fk_asm_qz_current_version');
            });
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
