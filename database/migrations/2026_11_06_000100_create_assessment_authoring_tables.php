<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0025 phase 1, authoring sources: categories, passages, questions and their content, options and media.
 * Every in-domain reference is a composite (id, customer_id) key so the database itself refuses cross-tenant links.
 */
return new class extends Migration
{
    private const TABLES = [
        'core_assessment_question_media',
        'core_assessment_question_options',
        'core_assessment_question_contents',
        'core_assessment_questions',
        'core_assessment_passages',
        'core_assessment_categories',
    ];

    public function up(): void
    {
        Schema::create('core_assessment_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 255);
            $table->string('slug', 255);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->string('status', 20)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['id', 'customer_id'], 'uk_asm_cat_tenant');
            $table->unique(['customer_id', 'slug'], 'uk_asm_cat_slug');
            $table->index(['customer_id', 'parent_id'], 'idx_asm_cat_parent');
            $table->index(['customer_id', 'status'], 'idx_asm_cat_status');
            $table->foreign('customer_id', 'fk_asm_cat_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['parent_id', 'customer_id'], 'fk_asm_cat_parent')
                ->references(['id', 'customer_id'])->on('core_assessment_categories')->restrictOnDelete();
        });

        Schema::create('core_assessment_passages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('title', 255);
            $table->longText('content')->nullable();
            $table->string('skill_type', 50)->nullable();
            $table->string('difficulty_level', 20)->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['id', 'customer_id'], 'uk_asm_psg_tenant');
            $table->index(['customer_id', 'category_id'], 'idx_asm_psg_category');
            $table->index(['customer_id', 'created_by'], 'idx_asm_psg_creator');
            $table->index(['customer_id', 'status'], 'idx_asm_psg_status');
            $table->index(['customer_id', 'skill_type'], 'idx_asm_psg_skill');
            $table->foreign('customer_id', 'fk_asm_psg_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['category_id', 'customer_id'], 'fk_asm_psg_category')
                ->references(['id', 'customer_id'])->on('core_assessment_categories')->restrictOnDelete();
            $table->foreign('created_by', 'fk_asm_psg_created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by', 'fk_asm_psg_updated_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('core_assessment_questions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('passage_id')->nullable();
            $table->unsignedInteger('passage_sort_order')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('question_type', 30);
            $table->string('skill_type', 50)->nullable();
            $table->string('difficulty_level', 20)->nullable();
            $table->decimal('default_points', 8, 2)->default(1.00);
            $table->string('status', 20)->default('draft');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['id', 'customer_id'], 'uk_asm_q_tenant');
            $table->unique(['customer_id', 'passage_id', 'passage_sort_order'], 'uk_asm_q_passage_order');
            $table->index(['customer_id', 'category_id'], 'idx_asm_q_category');
            $table->index(['customer_id', 'created_by'], 'idx_asm_q_creator');
            $table->index(['customer_id', 'status'], 'idx_asm_q_status');
            $table->index(['customer_id', 'question_type'], 'idx_asm_q_type');
            $table->index(['customer_id', 'skill_type', 'difficulty_level'], 'idx_asm_q_skill_level');
            $table->foreign('customer_id', 'fk_asm_q_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['category_id', 'customer_id'], 'fk_asm_q_category')
                ->references(['id', 'customer_id'])->on('core_assessment_categories')->restrictOnDelete();
            $table->foreign(['passage_id', 'customer_id'], 'fk_asm_q_passage')
                ->references(['id', 'customer_id'])->on('core_assessment_passages')->restrictOnDelete();
            $table->foreign('created_by', 'fk_asm_q_created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by', 'fk_asm_q_updated_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('core_assessment_question_contents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('question_id');
            $table->string('locale', 10);
            $table->string('title', 255)->nullable();
            $table->longText('prompt');
            $table->longText('explanation')->nullable();
            $table->longText('reference_answer')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'question_id', 'locale'], 'uk_asm_qc_locale');
            $table->foreign('customer_id', 'fk_asm_qc_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            // Content belongs to its question; a question is only hard-deleted while it was never published.
            $table->foreign(['question_id', 'customer_id'], 'fk_asm_qc_question')
                ->references(['id', 'customer_id'])->on('core_assessment_questions')->cascadeOnDelete();
        });

        Schema::create('core_assessment_question_options', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('question_id');
            $table->string('locale', 10);
            $table->text('option_text');
            $table->string('option_label', 50)->nullable();
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order')->default(1);
            $table->text('feedback')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'question_id', 'locale', 'sort_order'], 'uk_asm_qo_order');
            $table->foreign('customer_id', 'fk_asm_qo_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['question_id', 'customer_id'], 'fk_asm_qo_question')
                ->references(['id', 'customer_id'])->on('core_assessment_questions')->cascadeOnDelete();
        });

        Schema::create('core_assessment_question_media', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('question_id')->nullable();
            $table->unsignedBigInteger('passage_id')->nullable();
            $table->unsignedBigInteger('media_file_id');
            $table->string('media_role', 50);
            $table->unsignedInteger('sort_order')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'question_id', 'media_file_id', 'media_role'], 'uk_asm_qm_question');
            $table->unique(['customer_id', 'passage_id', 'media_file_id', 'media_role'], 'uk_asm_qm_passage');
            $table->index(['customer_id', 'media_file_id'], 'idx_asm_qm_media');
            $table->foreign('customer_id', 'fk_asm_qm_customer')->references('id')->on('saas_customers')->restrictOnDelete();
            $table->foreign(['question_id', 'customer_id'], 'fk_asm_qm_question')
                ->references(['id', 'customer_id'])->on('core_assessment_questions')->cascadeOnDelete();
            $table->foreign(['passage_id', 'customer_id'], 'fk_asm_qm_passage')
                ->references(['id', 'customer_id'])->on('core_assessment_passages')->cascadeOnDelete();
            $table->foreign(['media_file_id', 'customer_id'], 'fk_asm_qm_media')
                ->references(['id', 'customer_id'])->on('media_files')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $skills = "'reading','listening','writing','speaking','other'";
        $levels = "'easy','medium','hard'";

        // A self-parent check is impossible here: MariaDB rejects CHECKs on the AUTO_INCREMENT id (error 1901).
        // The service rejects a category that is its own ancestor, which covers the self-parent case too.
        DB::statement("ALTER TABLE core_assessment_categories
            ADD CONSTRAINT chk_asm_cat_status CHECK (status IN ('active','archived'))");
        DB::statement("ALTER TABLE core_assessment_passages
            ADD CONSTRAINT chk_asm_psg_status CHECK (status IN ('draft','published','archived')),
            ADD CONSTRAINT chk_asm_psg_skill CHECK (skill_type IS NULL OR skill_type IN ({$skills})),
            ADD CONSTRAINT chk_asm_psg_level CHECK (difficulty_level IS NULL OR difficulty_level IN ({$levels}))");
        DB::statement("ALTER TABLE core_assessment_questions
            ADD CONSTRAINT chk_asm_q_type CHECK (question_type IN ('single_choice','multiple_choice','true_false','short_answer','essay','speaking')),
            ADD CONSTRAINT chk_asm_q_skill CHECK (skill_type IS NULL OR skill_type IN ({$skills})),
            ADD CONSTRAINT chk_asm_q_level CHECK (difficulty_level IS NULL OR difficulty_level IN ({$levels})),
            ADD CONSTRAINT chk_asm_q_status CHECK (status IN ('draft','published','archived')),
            ADD CONSTRAINT chk_asm_q_points CHECK (default_points >= 0),
            ADD CONSTRAINT chk_asm_q_passage CHECK ((passage_id IS NULL) = (passage_sort_order IS NULL))");
        DB::statement("ALTER TABLE core_assessment_question_media
            ADD CONSTRAINT chk_asm_qm_owner CHECK ((question_id IS NULL) <> (passage_id IS NULL)),
            ADD CONSTRAINT chk_asm_qm_role CHECK (media_role IN ('prompt_image','prompt_audio','prompt_video','attachment','explanation_media')),
            ADD CONSTRAINT chk_asm_qm_passage_role CHECK (passage_id IS NULL OR media_role IN ('prompt_image','prompt_audio','prompt_video'))");
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $rows = Schema::hasTable($table) ? DB::table($table)->count() : 0;
            if ($rows > 0) {
                throw new RuntimeException(
                    "Rollback refused: {$table} has {$rows} row(s) of authored assessment content. "
                    .'Export or remove them deliberately before rolling back.'
                );
            }
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
