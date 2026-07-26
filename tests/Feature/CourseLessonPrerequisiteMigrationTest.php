<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class CourseLessonPrerequisiteMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_backfills_legacy_working_and_version_prerequisites(): void
    {
        $migration = $this->migration();
        $migration->down();

        $context = $this->createCourseContext();
        $workingPrerequisiteId = $this->createWorkingLesson($context, 'Working prerequisite', 1);
        $workingLessonId = $this->createWorkingLesson(
            $context,
            'Working dependent',
            2,
            'previous_lesson_completed',
            $workingPrerequisiteId
        );
        $versionPrerequisiteId = $this->createVersionLesson($context, 'Version prerequisite', 1);
        $versionLessonId = $this->createVersionLesson(
            $context,
            'Version dependent',
            2,
            'previous_lesson_completed',
            $versionPrerequisiteId
        );

        $migration->up();

        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $workingLessonId,
            'unlock_rule' => 'selected_lessons_completed',
            'prerequisite_match' => 'all',
        ]);
        $this->assertDatabaseHas('core_course_template_lesson_prerequisites', [
            'lesson_id' => $workingLessonId,
            'prerequisite_lesson_id' => $workingPrerequisiteId,
        ]);
        $this->assertDatabaseHas('core_course_template_version_lessons', [
            'id' => $versionLessonId,
            'unlock_rule_snapshot' => 'selected_lessons_completed',
            'prerequisite_match_snapshot' => 'all',
        ]);
        $this->assertDatabaseHas('core_course_template_version_lesson_prerequisites', [
            'version_lesson_id' => $versionLessonId,
            'prerequisite_version_lesson_id' => $versionPrerequisiteId,
        ]);
    }

    public function test_down_losslessly_restores_single_all_prerequisites(): void
    {
        $context = $this->createCourseContext();
        $workingPrerequisiteId = $this->createWorkingLesson($context, 'Working prerequisite', 1);
        $workingLessonId = $this->createWorkingLesson(
            $context,
            'Working dependent',
            2,
            'selected_lessons_completed'
        );
        $this->createWorkingEdge($context, $workingLessonId, $workingPrerequisiteId);
        $versionPrerequisiteId = $this->createVersionLesson($context, 'Version prerequisite', 1);
        $versionLessonId = $this->createVersionLesson(
            $context,
            'Version dependent',
            2,
            'selected_lessons_completed'
        );
        $this->createVersionEdge($context, $versionLessonId, $versionPrerequisiteId);

        $this->migration()->down();

        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $workingLessonId,
            'unlock_rule' => 'previous_lesson_completed',
            'unlock_after_lesson_id' => $workingPrerequisiteId,
        ]);
        $this->assertDatabaseHas('core_course_template_version_lessons', [
            'id' => $versionLessonId,
            'unlock_rule_snapshot' => 'previous_lesson_completed',
            'unlock_after_version_lesson_id' => $versionPrerequisiteId,
        ]);
        $this->assertFalse(Schema::hasTable('core_course_template_lesson_prerequisites'));
        $this->assertFalse(Schema::hasTable('core_course_template_version_lesson_prerequisites'));
        $this->assertFalse(Schema::hasColumn('core_course_template_lessons', 'prerequisite_match'));
        $this->assertFalse(
            Schema::hasColumn(
                'core_course_template_version_lessons',
                'prerequisite_match_snapshot'
            )
        );
    }

    public function test_down_refuses_non_lossless_rules_before_changing_schema_or_data(): void
    {
        $context = $this->createCourseContext();
        $firstId = $this->createWorkingLesson($context, 'First prerequisite', 1);
        $secondId = $this->createWorkingLesson($context, 'Second prerequisite', 2);
        $lessonId = $this->createWorkingLesson(
            $context,
            'Dependent',
            3,
            'selected_lessons_completed',
            null,
            'any'
        );
        $this->createWorkingEdge($context, $lessonId, $firstId, 0);
        $this->createWorkingEdge($context, $lessonId, $secondId, 1);

        try {
            $this->migration()->down();
            $this->fail('Expected the migration to refuse a lossy rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'cannot be represented losslessly',
                $exception->getMessage()
            );
        }

        $this->assertTrue(Schema::hasTable('core_course_template_lesson_prerequisites'));
        $this->assertTrue(Schema::hasColumn('core_course_template_lessons', 'prerequisite_match'));
        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $lessonId,
            'unlock_rule' => 'selected_lessons_completed',
            'prerequisite_match' => 'any',
        ]);
        $this->assertSame(
            2,
            DB::table('core_course_template_lesson_prerequisites')
                ->where('lesson_id', $lessonId)
                ->count()
        );
    }

    public function test_down_refuses_all_previous_rule_without_changing_schema(): void
    {
        $context = $this->createCourseContext();
        $lessonId = $this->createWorkingLesson(
            $context,
            'All previous dependent',
            2,
            'all_previous_lessons_completed'
        );

        $this->assertRollbackIsRefused();

        $this->assertTrue(Schema::hasTable('core_course_template_lesson_prerequisites'));
        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $lessonId,
            'unlock_rule' => 'all_previous_lessons_completed',
        ]);
    }

    public function test_down_refuses_multiple_all_prerequisites_without_losing_edges(): void
    {
        $context = $this->createCourseContext();
        $firstId = $this->createWorkingLesson($context, 'First prerequisite', 1);
        $secondId = $this->createWorkingLesson($context, 'Second prerequisite', 2);
        $lessonId = $this->createWorkingLesson(
            $context,
            'All dependent',
            3,
            'selected_lessons_completed'
        );
        $this->createWorkingEdge($context, $lessonId, $firstId, 0);
        $this->createWorkingEdge($context, $lessonId, $secondId, 1);

        $this->assertRollbackIsRefused();

        $this->assertSame(
            2,
            DB::table('core_course_template_lesson_prerequisites')
                ->where('lesson_id', $lessonId)
                ->count()
        );
    }

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_07_26_000000_add_multiple_course_lesson_prerequisites.php'
        );
    }

    private function assertRollbackIsRefused(): void
    {
        try {
            $this->migration()->down();
            $this->fail('Expected the migration to refuse a lossy rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'cannot be represented losslessly',
                $exception->getMessage()
            );
        }
    }

    /**
     * @return array{customer_id: int, user_id: int, template_id: int, version_id: int}
     */
    private function createCourseContext(): array
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Migration tenant',
            'slug' => 'migration-tenant-'.uniqid(),
            'subdomain' => 'migration-'.uniqid(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'customer_id' => $customerId,
            'role' => 'customer_admin',
            'status' => 'active',
        ]);
        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId,
            'category_id' => null,
            'title' => 'Migration course',
            'short_description' => null,
            'description' => null,
            'publisher_name' => null,
            'intro_video_source' => null,
            'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => null,
            'difficulty_level' => null,
            'estimated_minutes_per_lesson' => null,
            'estimated_lesson_count' => null,
            'lesson_count' => 2,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'working_revision' => 1,
            'status' => 'active',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $versionId = DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'version_number' => 1,
            'version_code' => 'migration-v1-'.uniqid(),
            'is_current' => true,
            'source_category_id' => null,
            'category_name_snapshot' => null,
            'title_snapshot' => 'Migration course',
            'short_description_snapshot' => null,
            'description_snapshot' => null,
            'publisher_name_snapshot' => null,
            'intro_video_source_snapshot' => null,
            'intro_image_media_file_id_snapshot' => null,
            'intro_video_media_file_id_snapshot' => null,
            'difficulty_level_snapshot' => null,
            'estimated_minutes_per_lesson_snapshot' => null,
            'estimated_lesson_count_snapshot' => null,
            'lesson_count_snapshot' => 2,
            'meta_title_snapshot' => null,
            'meta_description_snapshot' => null,
            'meta_keywords_snapshot' => null,
            'source_working_revision' => 1,
            'status' => 'published',
            'published_at' => now(),
            'published_by' => $user->id,
            'source_template_updated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'customer_id' => $customerId,
            'user_id' => $user->id,
            'template_id' => $templateId,
            'version_id' => $versionId,
        ];
    }

    private function createWorkingLesson(
        array $context,
        string $title,
        int $sortOrder,
        string $rule = 'none',
        ?int $legacyPrerequisiteId = null,
        ?string $match = 'all'
    ): int {
        $attributes = [
            'customer_id' => $context['customer_id'],
            'template_id' => $context['template_id'],
            'template_section_id' => null,
            'title' => $title,
            'short_description' => null,
            'description' => null,
            'sort_order' => $sortOrder,
            'is_preview' => false,
            'duration_seconds' => 0,
            'activity_count' => 0,
            'lesson_type' => 'regular',
            'unlock_rule' => $rule,
            'unlock_after_lesson_id' => $legacyPrerequisiteId,
            'unlock_at' => null,
            'created_by' => $context['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('core_course_template_lessons', 'prerequisite_match')) {
            $attributes['prerequisite_match'] = $rule === 'selected_lessons_completed'
                ? $match
                : null;
        }

        return DB::table('core_course_template_lessons')->insertGetId($attributes);
    }

    private function createVersionLesson(
        array $context,
        string $title,
        int $sortOrder,
        string $rule = 'none',
        ?int $legacyPrerequisiteId = null
    ): int {
        $attributes = [
            'customer_id' => $context['customer_id'],
            'template_version_id' => $context['version_id'],
            'version_section_id' => null,
            'source_template_lesson_id' => $sortOrder,
            'title_snapshot' => $title,
            'short_description_snapshot' => null,
            'description_snapshot' => null,
            'sort_order' => $sortOrder,
            'is_preview' => false,
            'lesson_type' => 'regular',
            'duration_seconds' => 0,
            'activity_count' => 0,
            'unlock_rule_snapshot' => $rule,
            'unlock_after_version_lesson_id' => $legacyPrerequisiteId,
            'unlock_at_snapshot' => null,
            'created_by_snapshot' => $context['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn(
            'core_course_template_version_lessons',
            'prerequisite_match_snapshot'
        )) {
            $attributes['prerequisite_match_snapshot'] =
                $rule === 'selected_lessons_completed' ? 'all' : null;
        }

        return DB::table('core_course_template_version_lessons')->insertGetId($attributes);
    }

    private function createWorkingEdge(
        array $context,
        int $lessonId,
        int $prerequisiteId,
        int $sortOrder = 0
    ): void {
        DB::table('core_course_template_lesson_prerequisites')->insert([
            'customer_id' => $context['customer_id'],
            'template_id' => $context['template_id'],
            'lesson_id' => $lessonId,
            'prerequisite_lesson_id' => $prerequisiteId,
            'sort_order' => $sortOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createVersionEdge(
        array $context,
        int $lessonId,
        int $prerequisiteId
    ): void {
        DB::table('core_course_template_version_lesson_prerequisites')->insert([
            'customer_id' => $context['customer_id'],
            'template_version_id' => $context['version_id'],
            'version_lesson_id' => $lessonId,
            'prerequisite_version_lesson_id' => $prerequisiteId,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
