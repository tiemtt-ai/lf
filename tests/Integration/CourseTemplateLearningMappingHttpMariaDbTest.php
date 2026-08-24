<?php

namespace Tests\Integration;

use App\Models\User;
use App\Services\LearningFrameworkAuthoringService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Mapping Intent tab driven over HTTP, with browser-shaped payloads.
 *
 * The sibling promotion suite verifies the service and database boundaries. It
 * cannot see the boundary this file covers: request validation, the controller,
 * the routes, and the tab markup those routes redirect back to. Three blocking
 * defects in the Learning authoring surface lived exactly there, and all three
 * survived a green service-level suite, so the payloads below are written the
 * way the form transmits them — every value a string, and an empty string for
 * any box left blank, which ConvertEmptyStringsToNull then turns into null.
 *
 * The optional weight box is the case worth naming: `<input type="number">`
 * left empty arrives as null, not as an absent field, and `nullable` is what
 * separates "no weight given" from a validation error.
 *
 * Fixture setup is deliberately duplicated from the promotion suite rather than
 * shared. That file closed an Owner-attested gate; a shared trait can be
 * extracted once both are settled.
 */
class CourseTemplateLearningMappingHttpMariaDbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Course Template Learning Mapping over HTTP requires MariaDB.');
        }

        $this->withoutVite();
        TenantContext::set(null);
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------- Selection

    public function test_selecting_a_published_version_from_the_tab_form(): void
    {
        $f = $this->fixture('select-ok');

        $this->admin($f)->put($f['host']."/admin/course-templates/{$f['template_id']}/learning-framework", [
            'framework_version_id' => (string) $f['version_id'],
            'framework_id' => (string) $f['framework_id'],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $template = DB::table('core_course_templates')->where('id', $f['template_id'])->first();
        $this->assertSame($f['framework_id'], (int) $template->selected_learning_framework_id);
        $this->assertSame($f['version_id'], (int) $template->selected_learning_framework_version_id);
    }

    /**
     * The Framework id travels in a hidden field that page script fills in, so
     * it is caller-controlled. It must be checked against the Version rather
     * than trusted.
     */
    public function test_a_framework_id_that_contradicts_the_version_is_refused(): void
    {
        $f = $this->fixture('select-forged');
        $other = $this->learningGraph($f['customer_id'], $f['admin_id'], 'select-forged-2');

        $this->admin($f)->put($f['host']."/admin/course-templates/{$f['template_id']}/learning-framework", [
            'framework_version_id' => (string) $f['version_id'],
            'framework_id' => (string) $other['framework_id'],
        ])->assertSessionHasErrors('framework_version_id');

        $this->assertNull(DB::table('core_course_templates')->where('id', $f['template_id'])->value('selected_learning_framework_id'));
    }

    public function test_a_draft_version_cannot_be_selected(): void
    {
        $f = $this->fixture('select-draft');
        TenantContext::set((object) ['id' => $f['customer_id']]);
        $draft = app(LearningFrameworkAuthoringService::class)->createDraftVersion($f['admin_id'], [
            'framework_id' => $f['framework_id'], 'version_code' => 'v2', 'title' => 'V2 draft',
        ]);

        $this->admin($f)->put($f['host']."/admin/course-templates/{$f['template_id']}/learning-framework", [
            'framework_version_id' => (string) $draft->id,
            'framework_id' => (string) $f['framework_id'],
        ])->assertSessionHasErrors('framework_version_id');

        $this->assertNull(DB::table('core_course_templates')->where('id', $f['template_id'])->value('selected_learning_framework_version_id'));
    }

    public function test_changing_the_version_while_mappings_exist_is_refused(): void
    {
        $f = $this->fixture('select-locked');
        $this->select($f);
        $this->storeLessonMapping($f)->assertSessionHasNoErrors();

        $other = $this->learningGraph($f['customer_id'], $f['admin_id'], 'select-locked-2');

        $this->admin($f)->put($f['host']."/admin/course-templates/{$f['template_id']}/learning-framework", [
            'framework_version_id' => (string) $other['version_id'],
            'framework_id' => (string) $other['framework_id'],
        ])->assertSessionHasErrors('framework_version_id');

        $this->assertSame($f['version_id'], (int) DB::table('core_course_templates')
            ->where('id', $f['template_id'])->value('selected_learning_framework_version_id'));
    }

    // --------------------------------------------------------------- Mapping

    public function test_adding_a_lesson_mapping_from_the_tab_form(): void
    {
        $f = $this->fixture('store-lesson');
        $this->select($f);

        $this->storeLessonMapping($f, ['weight' => '0.5'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $intent = DB::table('core_course_template_learning_mapping_intents')
            ->where('template_id', $f['template_id'])->firstOrFail();
        $this->assertSame('course_template_lesson', $intent->source_type);
        $this->assertSame($f['lesson_id'], (int) $intent->source_id);
        $this->assertSame($f['node_id'], (int) $intent->learning_node_id);
        $this->assertSame('teaches', $intent->mapping_role);
        $this->assertSame('0.500000', $intent->weight);
        $this->assertSame('manual', $intent->origin);
    }

    /**
     * The weight box is optional. Empty means "no weight", not "invalid".
     */
    public function test_a_blank_weight_box_is_accepted_as_no_weight(): void
    {
        $f = $this->fixture('store-blank-weight');
        $this->select($f);

        $this->storeLessonMapping($f, ['weight' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull(DB::table('core_course_template_learning_mapping_intents')
            ->where('template_id', $f['template_id'])->value('weight'));
    }

    public function test_adding_an_activity_mapping_from_the_tab_form(): void
    {
        $f = $this->fixture('store-activity');
        $this->select($f);

        // The page script rewrites the hidden source_type when the option changes.
        $this->storeMapping($f, [
            'source_type' => 'course_template_activity',
            'source_id' => (string) $f['activity_id'],
            'mapping_role' => 'practices',
        ])->assertSessionHasNoErrors();

        $intent = DB::table('core_course_template_learning_mapping_intents')
            ->where('template_id', $f['template_id'])->firstOrFail();
        $this->assertSame('course_template_activity', $intent->source_type);
        $this->assertSame($f['activity_id'], (int) $intent->source_id);
        $this->assertSame('practices', $intent->mapping_role);
    }

    public function test_removing_a_mapping_from_the_tab_form(): void
    {
        $f = $this->fixture('destroy');
        $this->select($f);
        $this->storeLessonMapping($f)->assertSessionHasNoErrors();
        $intentId = DB::table('core_course_template_learning_mapping_intents')
            ->where('template_id', $f['template_id'])->value('id');

        $this->admin($f)
            ->delete($f['host']."/admin/course-templates/{$f['template_id']}/learning-mappings/{$intentId}")
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')
            ->where('template_id', $f['template_id'])->count());
    }

    public function test_mapping_before_a_version_is_selected_is_refused(): void
    {
        $f = $this->fixture('store-no-selection');

        $this->storeLessonMapping($f)->assertSessionHasErrors('framework_version_id');

        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')->count());
    }

    public function test_a_node_outside_the_selected_version_is_refused(): void
    {
        $f = $this->fixture('store-foreign-node');
        $this->select($f);
        $other = $this->learningGraph($f['customer_id'], $f['admin_id'], 'store-foreign-node-2');

        $this->storeLessonMapping($f, ['learning_node_id' => (string) $other['node_id']])
            ->assertSessionHasErrors('learning_node_id');

        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')->count());
    }

    public function test_a_source_from_another_template_is_refused(): void
    {
        $f = $this->fixture('store-foreign-source');
        $this->select($f);
        // array_merge, not `+`: the union operator keeps the left-hand
        // template_id and would have created the Lesson in the same Template,
        // making this test pass for the wrong reason.
        $secondTemplateId = $this->createTemplate($f, 'Second template');
        $otherTemplateLesson = $this->createLesson(
            array_merge($f, ['template_id' => $secondTemplateId]), 'Foreign Lesson', 0
        );

        $this->storeLessonMapping($f, ['source_id' => (string) $otherTemplateLesson])
            ->assertSessionHasErrors('source_id');

        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')->count());
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function invalidMappingProvider(): array
    {
        return [
            'unknown role' => [['mapping_role' => 'inspires'], 'mapping_role'],
            'weight above one' => [['weight' => '1.5'], 'weight'],
            'negative weight' => [['weight' => '-0.1'], 'weight'],
            'unknown source type' => [['source_type' => 'course_version_lesson'], 'source_type'],
        ];
    }

    /**
     * @param  array<string, string>  $override
     */
    #[DataProvider('invalidMappingProvider')]
    public function test_invalid_mapping_input_is_refused(array $override, string $field): void
    {
        $f = $this->fixture('invalid-'.$field.'-'.md5(serialize($override)));
        $this->select($f);

        $this->storeLessonMapping($f, $override)->assertSessionHasErrors($field);

        $this->assertSame(0, DB::table('core_course_template_learning_mapping_intents')->count());
    }

    // --------------------------------------------------------- Authorization

    public function test_teacher_and_student_are_refused_on_every_mapping_route(): void
    {
        $f = $this->fixture('roles');
        $this->select($f);
        $this->storeLessonMapping($f)->assertSessionHasNoErrors();
        $intentId = DB::table('core_course_template_learning_mapping_intents')
            ->where('template_id', $f['template_id'])->value('id');

        foreach (['teacher_id', 'student_id'] as $role) {
            $this->actingAs(User::findOrFail($f[$role]));
            $this->put($f['host']."/admin/course-templates/{$f['template_id']}/learning-framework", [
                'framework_version_id' => (string) $f['version_id'], 'framework_id' => (string) $f['framework_id'],
            ])->assertForbidden();
            $this->post($f['host']."/admin/course-templates/{$f['template_id']}/learning-mappings", [])->assertForbidden();
            $this->delete($f['host']."/admin/course-templates/{$f['template_id']}/learning-mappings/{$intentId}")->assertForbidden();
        }

        $this->assertSame(1, DB::table('core_course_template_learning_mapping_intents')->count());
    }

    public function test_another_tenant_admin_cannot_touch_the_template(): void
    {
        $owner = $this->fixture('tenant-owner');
        $other = $this->fixture('tenant-other');
        $this->select($owner);
        $this->storeLessonMapping($owner)->assertSessionHasNoErrors();
        $intentId = DB::table('core_course_template_learning_mapping_intents')
            ->where('template_id', $owner['template_id'])->value('id');

        // The other tenant's admin, on the other tenant's host, naming the
        // owner's template: the tenant boundary is resolved from the host.
        $this->actingAs(User::findOrFail($other['admin_id']));
        $this->post($other['host']."/admin/course-templates/{$owner['template_id']}/learning-mappings", [
            'source_type' => 'course_template_lesson', 'source_id' => (string) $owner['lesson_id'],
            'learning_node_id' => (string) $owner['node_id'], 'mapping_role' => 'teaches', 'weight' => '',
        ])->assertNotFound();
        $this->delete($other['host']."/admin/course-templates/{$owner['template_id']}/learning-mappings/{$intentId}")
            ->assertNotFound();

        $this->assertSame(1, DB::table('core_course_template_learning_mapping_intents')
            ->where('customer_id', $owner['customer_id'])->count());
    }

    // ----------------------------------------------------------- Rendered tab

    public function test_the_edit_page_renders_the_tab_with_its_selection_and_mappings(): void
    {
        $f = $this->fixture('render');
        $this->select($f);
        $this->storeLessonMapping($f)->assertSessionHasNoErrors();

        $html = $this->admin($f)
            ->get($f['host']."/admin/course-templates/{$f['template_id']}/edit")
            ->assertOk()->getContent();

        $this->assertStringContainsString('Chuẩn đầu ra &amp; năng lực', $html);
        $this->assertStringContainsString('name="framework_version_id"', $html);
        $this->assertStringContainsString('Definition render', $html, 'The mapped Node label must render from the Learning read service.');
        $this->assertStringContainsString('name="learning_node_id"', $html, 'The Node picker appears once a Version is selected.');
    }

    public function test_the_tab_hides_the_node_picker_until_a_version_is_selected(): void
    {
        $f = $this->fixture('render-empty');

        $html = $this->admin($f)
            ->get($f['host']."/admin/course-templates/{$f['template_id']}/edit")
            ->assertOk()->getContent();

        $this->assertStringContainsString('name="framework_version_id"', $html);
        $this->assertStringNotContainsString('name="learning_node_id"', $html);
    }

    // -------------------------------------------------------------- Fixtures

    /** @param array<string, mixed> $f */
    private function admin(array $f): self
    {
        $this->actingAs(User::findOrFail($f['admin_id']));

        return $this;
    }

    /** @param array<string, mixed> $f */
    private function select(array $f): void
    {
        $this->admin($f)->put($f['host']."/admin/course-templates/{$f['template_id']}/learning-framework", [
            'framework_version_id' => (string) $f['version_id'],
            'framework_id' => (string) $f['framework_id'],
        ])->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, string>  $override
     */
    private function storeLessonMapping(array $f, array $override = [])
    {
        return $this->storeMapping($f, $override);
    }

    /**
     * The exact payload the tab form transmits: strings throughout, and an
     * empty string for the optional weight box.
     *
     * @param  array<string, mixed>  $f
     * @param  array<string, string>  $override
     */
    private function storeMapping(array $f, array $override = [])
    {
        return $this->admin($f)->post($f['host']."/admin/course-templates/{$f['template_id']}/learning-mappings", array_merge([
            'source_type' => 'course_template_lesson',
            'source_id' => (string) $f['lesson_id'],
            'learning_node_id' => (string) $f['node_id'],
            'mapping_role' => 'teaches',
            'weight' => '',
        ], $override));
    }

    /** @return array<string, mixed> */
    private function fixture(string $slug): array
    {
        $slug = substr(preg_replace('/[^a-z0-9-]/', '', strtolower($slug)), 0, 40);
        $now = now();
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $users = [];
        foreach (['admin' => 'customer_admin', 'teacher' => 'teacher', 'student' => 'student'] as $key => $role) {
            $users[$key.'_id'] = (int) User::forceCreate([
                'customer_id' => $customerId, 'name' => ucfirst($key),
                'email' => "{$key}-{$slug}@example.test", 'password' => Hash::make('password123'),
                'role' => $role, 'status' => 'active', 'email_verified_at' => $now,
            ])->id;
        }

        $f = ['customer_id' => $customerId, 'host' => "https://{$slug}.localhost", 'slug' => $slug] + $users;
        $f['template_id'] = $this->createTemplate($f, 'Template '.$slug);
        $f['lesson_id'] = $this->createLesson($f, 'Lesson '.$slug, 0);
        $f['activity_id'] = $this->createActivity($f, $f['lesson_id'], 'Activity '.$slug);

        return $f + $this->learningGraph($customerId, $f['admin_id'], $slug);
    }

    /** @param array<string, mixed> $f */
    private function createTemplate(array $f, string $title): int
    {
        $now = now();
        $categoryId = DB::table('core_course_categories')->where('customer_id', $f['customer_id'])->value('id')
            ?? DB::table('core_course_categories')->insertGetId([
                'customer_id' => $f['customer_id'], 'parent_id' => null, 'name' => 'General '.$f['slug'],
                'slug' => 'general-'.$f['slug'], 'description' => null, 'thumbnail_image' => null,
                'banner_image' => null, 'sort_order' => 1, 'is_featured' => false, 'meta_title' => null,
                'meta_description' => null, 'meta_keywords' => null, 'status' => 'active',
                'created_by' => $f['admin_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);

        return DB::table('core_course_templates')->insertGetId([
            'customer_id' => $f['customer_id'], 'category_id' => $categoryId, 'title' => $title,
            'short_description' => 'Published snapshot course', 'description' => 'Detailed snapshot description.',
            'publisher_name' => 'LearnForge', 'intro_video_source' => null, 'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => null, 'difficulty_level' => 'beginner',
            'estimated_minutes_per_lesson' => 90, 'estimated_lesson_count' => null, 'lesson_count' => 1,
            'meta_title' => 'Snapshot Course', 'meta_description' => 'Snapshot course metadata.',
            'meta_keywords' => 'snapshot,course', 'working_revision' => 1, 'status' => 'active',
            'created_by' => $f['admin_id'], 'last_version_published_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
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

    /** @return array<string, int> */
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
        TenantContext::set(null);

        return [
            'framework_id' => (int) $framework->id,
            'version_id' => (int) $version->id,
            'node_id' => (int) $node->id,
        ];
    }
}
