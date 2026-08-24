<?php

namespace Tests\Integration;

use App\Models\User;
use App\Services\CourseTemplateLearningMappingIntentService;
use App\Services\CourseTemplatePublishingService;
use App\Services\LearningFrameworkAuthoringService;
use App\Services\LearningMappingPromotionService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 1 Mapping Intent promotion, exercised against MariaDB.
 *
 * SQLite carries no Learning Foundation schema and no Intent table, so the
 * default suite cannot reach a single line of this flow: promotion is skipped
 * entirely and a green run says nothing about it. Everything here therefore
 * runs on the real driver, where the composite foreign keys, the CHECK
 * constraints and the Learning immutability triggers all exist.
 *
 * What the promotion contract actually promises, and what each test pins:
 * canonical Mappings carry the published Version identities rather than the
 * working ones; any invalid Node, Framework Version or source fails the publish
 * closed and rolls the whole snapshot back; a later publish never touches the
 * Mappings of an earlier Course Version; and a retry cannot duplicate a
 * Mapping.
 */
class CourseTemplateLearningMappingPromotionMariaDbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Learning Mapping promotion requires MariaDB.');
        }

        TenantContext::set(null);
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------- Promotion

    public function test_lesson_and_activity_intents_promote_to_canonical_mappings(): void
    {
        $f = $this->fixture('promote');
        $this->mapLesson($f, 'teaches', '0.75');
        $this->mapActivity($f, 'practices', null);

        $version = $this->publish($f);
        $versionLessonId = $this->versionLessonId($f, $version->id);
        $versionActivityId = $this->versionActivityId($f, $version->id);

        $mappings = DB::table('core_learning_node_mappings')
            ->where('customer_id', $f['customer_id'])->orderBy('id')->get()->keyBy('source_type');

        $this->assertCount(2, $mappings);

        $lesson = $mappings['course_version_lesson'];
        $this->assertSame($versionLessonId, (int) $lesson->source_id, 'Mapping must carry the published Version Lesson, not the working one.');
        // Not merely "different from the working id" — on a fresh schema both
        // sequences can legitimately reach the same number. What matters is
        // that the identity resolves inside the published Version tables.
        $this->assertTrue(
            DB::table('core_course_template_version_lessons')
                ->where('customer_id', $f['customer_id'])
                ->where('template_version_id', $version->id)
                ->where('id', $lesson->source_id)->exists(),
            'Mapping source_id must resolve to a published Version Lesson row.'
        );
        $this->assertSame((string) $version->id, $lesson->source_discriminator);
        $this->assertSame('teaches', $lesson->mapping_role);
        $this->assertSame('0.750000', $lesson->weight);
        $this->assertSame($f['node_id'], (int) $lesson->learning_node_id);
        $this->assertNull($lesson->invalidated_at);

        $snapshot = json_decode($lesson->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Promote Lesson', $snapshot['label']);
        $this->assertSame('course_version_lesson', $snapshot['source_type']);
        $this->assertSame($version->id, $snapshot['course_version_id']);

        $activity = $mappings['course_version_activity'];
        $this->assertSame($versionActivityId, (int) $activity->source_id);
        $this->assertSame('practices', $activity->mapping_role);
        $this->assertNull($activity->weight);
    }

    public function test_publishing_without_intents_writes_no_mapping(): void
    {
        $f = $this->fixture('empty');
        $this->publish($f);

        $this->assertSame(0, DB::table('core_learning_node_mappings')->count());
    }

    /**
     * The Intent list renders Node code and name. Those come from the Learning
     * read service rather than a join, per Owner decision G2-N1, so the
     * decoration is worth pinning: a silent regression here shows up as blank
     * labels in the UI and nowhere else.
     */
    public function test_intent_state_decorates_nodes_through_the_learning_read_service(): void
    {
        $f = $this->fixture('state');
        $this->mapLesson($f);

        TenantContext::set((object) ['id' => $f['customer_id']]);
        $state = app(CourseTemplateLearningMappingIntentService::class)
            ->state($f['admin_id'], $f['customer_id'], $f['template_id']);

        $this->assertCount(1, $state['intents']);
        $intent = $state['intents']->first();
        $this->assertSame('D-state', $intent->code_snapshot);
        $this->assertSame('Definition state', $intent->name_snapshot);

        $this->assertTrue($state['versions']->contains('framework_version_id', $f['version_id']));
        $this->assertTrue($state['nodes']->contains('id', $f['node_id']));
    }

    // -------------------------------------------------------------- Rollback

    public function test_a_deprecated_framework_version_fails_the_publish_closed(): void
    {
        $f = $this->fixture('deprecated');
        $this->mapLesson($f);

        DB::table('core_learning_framework_versions')->where('id', $f['version_id'])
            ->update(['status' => 'deprecated', 'deprecated_at' => now(), 'deprecated_by' => $f['admin_id']]);

        $this->assertPublishRollsBack($f, 'A Learning Node or Framework Version is no longer publishable.');
    }

    public function test_a_deleted_mapped_source_fails_the_publish_closed(): void
    {
        $f = $this->fixture('orphan');
        $this->mapLesson($f);

        // A second Lesson keeps the Template publishable once the mapped one is
        // gone; without it the failure could come from readiness instead.
        $this->createLesson($f, 'Spare Lesson', 1);
        DB::table('core_course_template_activities')->where('template_lesson_id', $f['lesson_id'])->delete();
        DB::table('core_course_template_lessons')->where('id', $f['lesson_id'])->delete();

        $this->assertPublishRollsBack($f);
    }

    public function test_promotion_refuses_a_call_that_does_not_match_its_publish_context(): void
    {
        $f = $this->fixture('token');

        $this->expectException(ValidationException::class);
        app(LearningMappingPromotionService::class)->promote(
            $f['customer_id'], $f['template_id'], 1, [], [], $f['admin_id'], now(), 'not-the-publish-context'
        );
    }

    // ----------------------------------------------------------- Idempotency

    public function test_a_retried_promotion_does_not_duplicate_a_mapping(): void
    {
        $f = $this->fixture('retry');
        $this->mapLesson($f);
        $version = $this->publish($f);

        $before = DB::table('core_learning_node_mappings')->where('customer_id', $f['customer_id'])->get();
        $this->assertCount(1, $before);

        // Replay the exact promotion the publish transaction just performed.
        app(LearningMappingPromotionService::class)->promote(
            $f['customer_id'], $f['template_id'], $version->id,
            [$f['lesson_id'] => $this->versionLessonId($f, $version->id)],
            [$f['activity_id'] => $this->versionActivityId($f, $version->id)],
            $f['admin_id'], now(), $this->promotionToken($f, $version->id)
        );

        $after = DB::table('core_learning_node_mappings')->where('customer_id', $f['customer_id'])->get();
        $this->assertCount(1, $after);
        $this->assertEquals($before->first(), $after->first(), 'A retry must not rewrite the existing Mapping.');
    }

    // ------------------------------------------------------- Version binding

    public function test_a_later_publish_leaves_the_earlier_version_mappings_untouched(): void
    {
        $f = $this->fixture('rebind');
        $this->mapLesson($f);

        $first = $this->publish($f);
        $firstMapping = DB::table('core_learning_node_mappings')->where('customer_id', $f['customer_id'])->firstOrFail();

        $second = $this->publish($f);
        $this->assertNotSame($first->id, $second->id);

        $mappings = DB::table('core_learning_node_mappings')->where('customer_id', $f['customer_id'])->orderBy('id')->get();
        $this->assertCount(2, $mappings, 'Each published Course Version owns its own canonical Mapping.');

        $this->assertEquals($firstMapping, $mappings->firstWhere('id', $firstMapping->id),
            'Publishing again must not modify or invalidate the earlier Version mapping.');
        $this->assertSame(
            [(string) $first->id, (string) $second->id],
            $mappings->pluck('source_discriminator')->all()
        );
    }

    // ----------------------------------------------------------- Tenant scope

    public function test_promotion_never_reads_another_tenant_intents(): void
    {
        $owner = $this->fixture('tenant-owner');
        $other = $this->fixture('tenant-other');
        $this->mapLesson($owner);

        $this->publish($other);

        $this->assertSame(0, DB::table('core_learning_node_mappings')->where('customer_id', $other['customer_id'])->count());
        $this->assertSame(0, DB::table('core_learning_node_mappings')->count());

        $this->publish($owner);
        $this->assertSame(1, DB::table('core_learning_node_mappings')->where('customer_id', $owner['customer_id'])->count());
        $this->assertSame(0, DB::table('core_learning_node_mappings')->where('customer_id', $other['customer_id'])->count());
    }

    // ------------------------------------------------- Physical constraints

    /**
     * Phase 1 is manual-only and the boundary is physical, not a service check.
     * `ai_proposal` stays a reserved value until Proposal persistence and its
     * review workflow exist, so the database must refuse it even when the write
     * bypasses every application guard.
     */
    public function test_the_database_refuses_an_ai_proposal_origin(): void
    {
        $f = $this->fixture('origin-check');
        $this->mapLesson($f);

        $this->assertInsertRejectedBy('chk_cct_lmi_origin', $this->rawIntent($f, [
            'mapping_role' => 'assesses',
            'origin' => 'ai_proposal',
        ]));
    }

    /**
     * The composite foreign key is what actually holds every Intent to the one
     * Framework Version the Template selected. The service checks it too, but a
     * service check is not the contract the review approved.
     */
    public function test_the_database_refuses_an_intent_that_leaves_the_template_selection(): void
    {
        $f = $this->fixture('selection-fk');
        $this->mapLesson($f);

        // A second published Version in the same tenant: valid Learning graph,
        // wrong selection. Only fk_cct_lmi_selection can reject this row.
        $other = $this->learningGraph($f['customer_id'], $f['admin_id'], 'selection-fk-2');

        $this->assertInsertRejectedBy('fk_cct_lmi_selection', $this->rawIntent($f, [
            'framework_id' => $other['framework_id'],
            'framework_version_id' => $other['version_id'],
            'learning_node_id' => $other['node_id'],
        ]));
    }

    /**
     * Consequence of the same key, and a deliberate one: every Intent column in
     * it is NOT NULL, so a Template that has selected nothing has no parent row
     * to match. An Intent cannot exist before a Framework Version is chosen,
     * without any code enforcing the ordering.
     */
    public function test_the_database_refuses_an_intent_before_a_version_is_selected(): void
    {
        $f = $this->fixture('no-selection');

        $this->assertSame([null, null], [
            DB::table('core_course_templates')->where('id', $f['template_id'])->value('selected_learning_framework_id'),
            DB::table('core_course_templates')->where('id', $f['template_id'])->value('selected_learning_framework_version_id'),
        ]);

        $this->assertInsertRejectedBy('fk_cct_lmi_selection', $this->rawIntent($f));
    }

    // -------------------------------------------------------------- Fixtures

    /** @return array<string, mixed> */
    private function fixture(string $slug): array
    {
        $now = now();
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $admin = User::forceCreate([
            'customer_id' => $customerId, 'name' => 'Admin', 'email' => "admin-{$slug}@example.test",
            'password' => Hash::make('password123'), 'role' => 'customer_admin', 'status' => 'active',
            'email_verified_at' => $now,
        ]);
        $teacher = User::forceCreate([
            'customer_id' => $customerId, 'name' => 'Teacher', 'email' => "teacher-{$slug}@example.test",
            'password' => Hash::make('password123'), 'role' => 'teacher', 'status' => 'active',
            'email_verified_at' => $now,
        ]);

        $categoryId = DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId, 'parent_id' => null, 'name' => 'General '.$slug,
            'slug' => 'general-'.$slug, 'description' => null, 'thumbnail_image' => null,
            'banner_image' => null, 'sort_order' => 1, 'is_featured' => false, 'meta_title' => null,
            'meta_description' => null, 'meta_keywords' => null, 'status' => 'active',
            'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId, 'category_id' => $categoryId, 'title' => 'Template '.$slug,
            'short_description' => 'Published snapshot course', 'description' => 'Detailed snapshot description.',
            'publisher_name' => 'LearnForge', 'intro_video_source' => null, 'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => null, 'difficulty_level' => 'beginner',
            'estimated_minutes_per_lesson' => 90, 'estimated_lesson_count' => null, 'lesson_count' => 1,
            'meta_title' => 'Snapshot Course', 'meta_description' => 'Snapshot course metadata.',
            'meta_keywords' => 'snapshot,course', 'working_revision' => 1, 'status' => 'active',
            'created_by' => $admin->id, 'last_version_published_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('core_course_template_teachers')->insert([
            'customer_id' => $customerId, 'template_id' => $templateId, 'teacher_id' => $teacher->id,
            'role' => 'primary', 'sort_order' => 0, 'status' => 'active', 'assigned_by' => $admin->id,
            'assigned_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $f = [
            'customer_id' => $customerId, 'admin_id' => (int) $admin->id, 'template_id' => $templateId,
            'slug' => $slug,
        ];
        $f['lesson_id'] = $this->createLesson($f, ucfirst(explode('-', $slug)[0]).' Lesson', 0);
        $f['activity_id'] = $this->createActivity($f, $f['lesson_id'], ucfirst(explode('-', $slug)[0]).' Activity');

        return $f + $this->learningGraph($customerId, (int) $admin->id, $slug);
    }

    /**
     * A published Framework Version with one active Node, authored through the
     * Learning owner service so the graph is built the only way the domain
     * allows.
     *
     * @return array<string, int>
     */
    private function learningGraph(int $customerId, int $adminId, string $slug): array
    {
        TenantContext::set((object) ['id' => $customerId]);
        $authoring = app(LearningFrameworkAuthoringService::class);

        $framework = $authoring->createFramework($adminId, [
            'code' => 'fw-'.$slug, 'name' => 'Framework '.$slug,
            'mastery_scale_key' => 'direct', 'mastery_scale_version' => '1',
            'mastery_scale' => ['levels' => [
                ['key' => 'novice', 'threshold' => 0],
                ['key' => 'mastered', 'threshold' => 0.8],
            ]],
        ]);
        $definition = $authoring->createDefinition($adminId, [
            'framework_id' => $framework->id, 'code' => 'D-'.$slug,
            'node_type' => 'competency', 'canonical_name' => 'Definition '.$slug,
        ]);
        $version = $authoring->createDraftVersion($adminId, [
            'framework_id' => $framework->id, 'version_code' => 'v1', 'title' => 'V1',
        ]);
        $node = $authoring->createNode($adminId, [
            'framework_version_id' => $version->id, 'node_definition_id' => $definition->id,
        ]);
        $authoring->publishVersion($adminId, (int) $version->id);

        return [
            'framework_id' => (int) $framework->id,
            'version_id' => (int) $version->id,
            'node_id' => (int) $node->id,
        ];
    }

    /** @param array<string, mixed> $f */
    private function createLesson(array $f, string $title, int $sortOrder): int
    {
        return DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $f['customer_id'], 'template_id' => $f['template_id'],
            'template_section_id' => null, 'title' => $title, 'short_description' => 'Lesson summary.',
            'description' => 'Lesson description.', 'sort_order' => $sortOrder, 'is_preview' => true,
            'duration_seconds' => 0, 'activity_count' => 1, 'unlock_rule' => 'none',
            'unlock_after_lesson_id' => null, 'unlock_at' => null, 'created_by' => $f['admin_id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $f */
    private function createActivity(array $f, int $lessonId, string $title): int
    {
        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $f['customer_id'], 'template_id' => $f['template_id'],
            'template_lesson_id' => $lessonId, 'title' => $title, 'description' => 'Activity description.',
            'sort_order' => 0, 'activity_type' => 'document', 'external_video_url' => null,
            'live_class_url' => null, 'assessment_quiz_id' => null, 'duration_seconds' => 600,
            'is_required' => true, 'completion_rule' => 'view', 'completion_threshold' => null,
            'is_preview' => false, 'unlock_rule' => 'none', 'unlock_after_activity_id' => null,
            'unlock_at' => null, 'created_by' => $f['admin_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mediaId = DB::table('media_files')->insertGetId([
            'customer_id' => $f['customer_id'], 'category_id' => null, 'uploaded_by' => $f['admin_id'],
            'file_type' => 'document', 'mime_type' => 'application/pdf',
            'original_name' => "activity-{$activityId}.pdf", 'display_name' => $title,
            'extension' => 'pdf', 'storage_disk' => 'media_local', 'storage_bucket' => 'local-media',
            'storage_region' => null, 'storage_key' => "tests/activity-{$activityId}.pdf",
            'storage_class' => null, 'cdn_url' => null, 'public_url' => null, 'checksum' => null,
            'file_size_bytes' => 1, 'duration_seconds' => null, 'width' => null, 'height' => null,
            'page_count' => 1, 'language' => null, 'visibility' => 'private', 'status' => 'ready',
            'metadata' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_file_usages')->insert([
            'customer_id' => $f['customer_id'], 'media_file_id' => $mediaId,
            'owner_type' => 'course_activity', 'owner_id' => $activityId, 'usage_type' => 'document',
            'status' => 'active', 'metadata' => null, 'created_by' => $f['admin_id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $activityId;
    }

    /** @param array<string, mixed> $f */
    private function mapLesson(array $f, string $role = 'teaches', ?string $weight = null): void
    {
        $this->addIntent($f, 'course_template_lesson', $f['lesson_id'], $role, $weight);
    }

    /** @param array<string, mixed> $f */
    private function mapActivity(array $f, string $role = 'practices', ?string $weight = null): void
    {
        $this->addIntent($f, 'course_template_activity', $f['activity_id'], $role, $weight);
    }

    /** @param array<string, mixed> $f */
    private function addIntent(array $f, string $sourceType, int $sourceId, string $role, ?string $weight): void
    {
        TenantContext::set((object) ['id' => $f['customer_id']]);
        $intents = app(CourseTemplateLearningMappingIntentService::class);
        $intents->select($f['admin_id'], $f['customer_id'], $f['template_id'], $f['framework_id'], $f['version_id']);
        $intents->store($f['admin_id'], $f['customer_id'], $f['template_id'], [
            'source_type' => $sourceType, 'source_id' => $sourceId,
            'learning_node_id' => $f['node_id'], 'mapping_role' => $role, 'weight' => $weight,
        ]);
    }

    /** @param array<string, mixed> $f */
    private function publish(array $f): object
    {
        TenantContext::set((object) ['id' => $f['customer_id']]);

        return app(CourseTemplatePublishingService::class)
            ->publish($f['customer_id'], $f['template_id'], $f['admin_id']);
    }

    /** @param array<string, mixed> $f */
    private function assertPublishRollsBack(array $f, ?string $expectedMessage = null): void
    {
        $versionsBefore = DB::table('core_course_template_versions')->where('template_id', $f['template_id'])->count();

        try {
            $this->publish($f);
            $this->fail('Publish was expected to fail closed.');
        } catch (ValidationException $exception) {
            if ($expectedMessage !== null) {
                $this->assertSame($expectedMessage, $exception->validator->errors()->first('publish'));
            }
        }

        $this->assertSame($versionsBefore, DB::table('core_course_template_versions')->where('template_id', $f['template_id'])->count(),
            'A failed promotion must roll the Course Version snapshot back.');
        $this->assertSame(0, DB::table('core_learning_node_mappings')->where('customer_id', $f['customer_id'])->count());
    }

    /** @param array<string, mixed> $f */
    private function versionLessonId(array $f, int $versionId): int
    {
        return (int) DB::table('core_course_template_version_lessons')
            ->where('customer_id', $f['customer_id'])->where('template_version_id', $versionId)
            ->where('source_template_lesson_id', $f['lesson_id'])->value('id');
    }

    /** @param array<string, mixed> $f */
    private function versionActivityId(array $f, int $versionId): int
    {
        return (int) DB::table('core_course_template_version_activities')
            ->where('customer_id', $f['customer_id'])->where('template_version_id', $versionId)
            ->where('source_template_activity_id', $f['activity_id'])->value('id');
    }

    /** @param array<string, mixed> $f */
    private function promotionToken(array $f, int $versionId): string
    {
        return hash_hmac('sha256', implode(':', [$f['customer_id'], $f['template_id'], $versionId, $f['admin_id']]), (string) config('app.key'));
    }

    /**
     * A structurally valid Intent row, written straight to the table so the
     * assertion is about the database and not about the service.
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawIntent(array $f, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $f['customer_id'], 'template_id' => $f['template_id'],
            'source_type' => 'course_template_lesson', 'source_id' => $f['lesson_id'],
            'framework_id' => $f['framework_id'], 'framework_version_id' => $f['version_id'],
            'learning_node_id' => $f['node_id'], 'mapping_role' => 'teaches',
            'weight' => null, 'origin' => 'manual',
            'created_by' => $f['admin_id'], 'updated_by' => $f['admin_id'],
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides);
    }

    /** @param array<string, mixed> $row */
    private function assertInsertRejectedBy(string $constraint, array $row): void
    {
        try {
            DB::table('core_course_template_learning_mapping_intents')->insert($row);
            $this->fail("Expected {$constraint} to reject the row.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($constraint, $exception->getMessage(),
                "The insert was rejected, but not by {$constraint}.");
        }

        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')
            ->where('customer_id', $row['customer_id'])
            ->where('framework_version_id', $row['framework_version_id'])
            ->where('mapping_role', $row['mapping_role'])
            ->where('origin', $row['origin'])
            ->count());
    }
}
