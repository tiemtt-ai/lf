<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('core_course_template_lessons', function (Blueprint $table): void {
            $table->string('prerequisite_match', 10)->nullable()
                ->after('unlock_rule');
        });

        Schema::table('core_course_template_version_lessons', function (Blueprint $table): void {
            $table->string('prerequisite_match_snapshot', 10)->nullable()
                ->after('unlock_rule_snapshot');
        });

        Schema::create('core_course_template_lesson_prerequisites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('lesson_id');
            $table->unsignedBigInteger('prerequisite_lesson_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['customer_id', 'lesson_id', 'prerequisite_lesson_id'],
                'uk_cctlp_edge'
            );
            $table->index(
                ['customer_id', 'template_id'],
                'idx_cctlp_template'
            );
            $table->index(
                ['customer_id', 'template_id', 'lesson_id', 'sort_order'],
                'idx_cctlp_lesson'
            );
            $table->index(
                ['customer_id', 'template_id', 'prerequisite_lesson_id'],
                'idx_cctlp_prerequisite'
            );
            $table->foreign('customer_id', 'fk_cctlp_customer')->references('id')
                ->on('saas_customers')->restrictOnDelete();
            $table->foreign('template_id', 'fk_cctlp_template')->references('id')
                ->on('core_course_templates')->restrictOnDelete();
            $table->foreign('lesson_id', 'fk_cctlp_lesson')->references('id')
                ->on('core_course_template_lessons')->restrictOnDelete();
            $table->foreign('prerequisite_lesson_id', 'fk_cctlp_prerequisite')
                ->references('id')
                ->on('core_course_template_lessons')->restrictOnDelete();
        });

        Schema::create('core_course_template_version_lesson_prerequisites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('template_version_id');
            $table->unsignedBigInteger('version_lesson_id');
            $table->unsignedBigInteger('prerequisite_version_lesson_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['customer_id', 'version_lesson_id', 'prerequisite_version_lesson_id'],
                'uk_cctvlp_edge'
            );
            $table->index(
                ['customer_id', 'template_version_id'],
                'idx_cctvlp_version'
            );
            $table->index(
                ['customer_id', 'template_version_id', 'version_lesson_id', 'sort_order'],
                'idx_cctvlp_lesson'
            );
            $table->index(
                ['customer_id', 'template_version_id', 'prerequisite_version_lesson_id'],
                'idx_cctvlp_prerequisite'
            );
            $table->foreign('customer_id', 'fk_cctvlp_customer')->references('id')
                ->on('saas_customers')->restrictOnDelete();
            $table->foreign('template_version_id', 'fk_cctvlp_version')
                ->references('id')
                ->on('core_course_template_versions')->restrictOnDelete();
            $table->foreign('version_lesson_id', 'fk_cctvlp_lesson')
                ->references('id')
                ->on('core_course_template_version_lessons')->restrictOnDelete();
            $table->foreign(
                'prerequisite_version_lesson_id',
                'fk_cctvlp_prerequisite'
            )->references('id')
                ->on('core_course_template_version_lessons')->restrictOnDelete();
        });

        $now = now();
        DB::table('core_course_template_lessons')
            ->where('unlock_rule', 'previous_lesson_completed')
            ->whereNotNull('unlock_after_lesson_id')
            ->orderBy('id')
            ->each(function (object $lesson) use ($now): void {
                DB::table('core_course_template_lesson_prerequisites')
                    ->insertOrIgnore([
                        'customer_id' => $lesson->customer_id,
                        'template_id' => $lesson->template_id,
                        'lesson_id' => $lesson->id,
                        'prerequisite_lesson_id' => $lesson->unlock_after_lesson_id,
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            });

        DB::table('core_course_template_version_lessons')
            ->where('unlock_rule_snapshot', 'previous_lesson_completed')
            ->whereNotNull('unlock_after_version_lesson_id')
            ->orderBy('id')
            ->each(function (object $lesson) use ($now): void {
                DB::table('core_course_template_version_lesson_prerequisites')
                    ->insertOrIgnore([
                        'customer_id' => $lesson->customer_id,
                        'template_version_id' => $lesson->template_version_id,
                        'version_lesson_id' => $lesson->id,
                        'prerequisite_version_lesson_id' => $lesson->unlock_after_version_lesson_id,
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            });

        DB::table('core_course_template_lessons')
            ->where('unlock_rule', 'previous_lesson_completed')
            ->update([
                'unlock_rule' => 'selected_lessons_completed',
                'prerequisite_match' => 'all',
            ]);

        DB::table('core_course_template_version_lessons')
            ->where('unlock_rule_snapshot', 'previous_lesson_completed')
            ->update([
                'unlock_rule_snapshot' => 'selected_lessons_completed',
                'prerequisite_match_snapshot' => 'all',
            ]);
    }

    public function down(): void
    {
        $workingDowngrades = $this->losslessDowngrades(
            'core_course_template_lessons',
            'core_course_template_lesson_prerequisites',
            'unlock_rule',
            'prerequisite_match',
            'lesson_id',
            'prerequisite_lesson_id'
        );
        $versionDowngrades = $this->losslessDowngrades(
            'core_course_template_version_lessons',
            'core_course_template_version_lesson_prerequisites',
            'unlock_rule_snapshot',
            'prerequisite_match_snapshot',
            'version_lesson_id',
            'prerequisite_version_lesson_id'
        );

        foreach ($workingDowngrades as $lessonId => $prerequisiteLessonId) {
            DB::table('core_course_template_lessons')
                ->where('id', $lessonId)
                ->update([
                    'unlock_rule' => 'previous_lesson_completed',
                    'unlock_after_lesson_id' => $prerequisiteLessonId,
                ]);
        }

        foreach ($versionDowngrades as $lessonId => $prerequisiteLessonId) {
            DB::table('core_course_template_version_lessons')
                ->where('id', $lessonId)
                ->update([
                    'unlock_rule_snapshot' => 'previous_lesson_completed',
                    'unlock_after_version_lesson_id' => $prerequisiteLessonId,
                ]);
        }

        Schema::dropIfExists('core_course_template_version_lesson_prerequisites');
        Schema::dropIfExists('core_course_template_lesson_prerequisites');

        Schema::table('core_course_template_version_lessons', function (Blueprint $table): void {
            $table->dropColumn('prerequisite_match_snapshot');
        });
        Schema::table('core_course_template_lessons', function (Blueprint $table): void {
            $table->dropColumn('prerequisite_match');
        });
    }

    /**
     * Return the only prerequisite mappings that the legacy schema can
     * represent. Refuse the rollback before any mutation when an edge or rule
     * would be lost.
     *
     * @return array<int, int>
     */
    private function losslessDowngrades(
        string $lessonTable,
        string $edgeTable,
        string $ruleColumn,
        string $matchColumn,
        string $edgeLessonColumn,
        string $edgePrerequisiteColumn
    ): array {
        $edges = DB::table($edgeTable)
            ->orderBy($edgeLessonColumn)
            ->orderBy('sort_order')
            ->get([$edgeLessonColumn, $edgePrerequisiteColumn])
            ->groupBy($edgeLessonColumn);

        $newRuleLessons = DB::table($lessonTable)
            ->whereIn($ruleColumn, [
                'all_previous_lessons_completed',
                'selected_lessons_completed',
            ])
            ->get(['id', $ruleColumn, $matchColumn])
            ->keyBy('id');

        foreach ($edges as $lessonId => $lessonEdges) {
            if (! $newRuleLessons->has((int) $lessonId)) {
                throw new RuntimeException(
                    "Rollback refused: {$edgeTable} contains prerequisite edges "
                    .'for a lesson without a compatible unlock rule.'
                );
            }
        }

        $downgrades = [];
        foreach ($newRuleLessons as $lesson) {
            $lessonEdges = $edges->get($lesson->id, collect());
            $isLossless = $lesson->{$ruleColumn} === 'selected_lessons_completed'
                && $lesson->{$matchColumn} === 'all'
                && $lessonEdges->count() === 1;

            if (! $isLossless) {
                throw new RuntimeException(
                    "Rollback refused: lesson {$lesson->id} in {$lessonTable} "
                    .'cannot be represented losslessly by the legacy single-prerequisite schema.'
                );
            }

            $downgrades[(int) $lesson->id] = (int) $lessonEdges
                ->first()->{$edgePrerequisiteColumn};
        }

        return $downgrades;
    }
};
