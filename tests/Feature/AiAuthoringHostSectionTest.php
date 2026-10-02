<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P3-A slice 1 (AI Authoring Review UI design §2.1): the "AI proposals" entry on
 * the Activity page follows the authority every proposal command rechecks, not
 * the wider authority to open the page.
 *
 * Opening the page needs an admin, the Template's creator, or ANY active
 * assignment; AI needs an active admin or an active primary/assistant/reviewer.
 * The section carries routes and the Template's Framework selection only.
 */
class AiAuthoringHostSectionTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://tenant-a.localhost';

    private int $customerId;

    private int $templateId;

    private int $lessonId;

    private int $activityId;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => self::HOST, 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https',
        ]);
        URL::forceRootUrl(self::HOST);
        URL::forceScheme('https');
        TenantContext::set(null);

        $this->customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'tenant-a', 'slug' => 'tenant-a', 'subdomain' => 'tenant-a',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->templateId = $this->template();
        $this->lessonId = DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $this->templateId, 'template_section_id' => null,
            'title' => 'Bài 1', 'sort_order' => 0, 'is_preview' => false, 'unlock_rule' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $this->templateId,
            'template_lesson_id' => $this->lessonId, 'title' => 'Video 1', 'activity_type' => 'video',
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        URL::forceRootUrl(null);
        TenantContext::set(null);
        parent::tearDown();
    }

    public function test_an_admin_sees_the_section_with_a_link_to_select_a_framework(): void
    {
        $page = $this->actingAs($this->user('customer_admin'))->get($this->pageUrl('admin'));

        $page->assertOk();
        $page->assertSee('id="ai-authoring"', false);
        $page->assertSee(__('lf.LF_ai_authoring_title'));
        $page->assertSee(__('lf.LF_ai_authoring_framework_missing_admin'));
        $page->assertSee(self::HOST.'/admin/course-templates/'.$this->templateId.'/edit?tab=learning', false);
        $this->assertFalse($this->config($page)['framework']['selected']);
    }

    public function test_the_section_hands_over_the_templates_own_framework_selection(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The selection columns have a real foreign key on MariaDB; this case uses synthetic ids.');
        }
        // Learning Foundation has no SQLite schema, so add the two columns it would add.
        if (! Schema::hasColumn('core_course_templates', 'selected_learning_framework_id')) {
            Schema::table('core_course_templates', function ($table): void {
                $table->unsignedBigInteger('selected_learning_framework_id')->nullable();
                $table->unsignedBigInteger('selected_learning_framework_version_id')->nullable();
            });
        }
        DB::table('core_course_templates')->where('id', $this->templateId)->update([
            'selected_learning_framework_id' => 41, 'selected_learning_framework_version_id' => 77,
        ]);

        $page = $this->actingAs($this->user('customer_admin'))->get($this->pageUrl('admin'));

        // The messages attribute carries every text, so look at the visible markers.
        $page->assertSee('data-ai-authoring-framework="selected"', false);
        $page->assertDontSee('data-ai-authoring-framework="missing"', false);
        $this->assertSame(
            ['selected' => true, 'framework_id' => 41, 'framework_version_id' => 77],
            $this->config($page)['framework'],
        );
    }

    /** @return array<string,array{string}> */
    public static function aiRoles(): array
    {
        return ['primary' => ['primary'], 'assistant' => ['assistant'], 'reviewer' => ['reviewer']];
    }

    #[DataProvider('aiRoles')]
    public function test_a_teacher_with_an_ai_assignment_sees_the_section_without_the_admin_link(string $assignmentRole): void
    {
        $teacher = $this->user('teacher');
        $this->assign($teacher, $assignmentRole);

        $page = $this->actingAs($teacher)->get($this->pageUrl('teacher'));

        $page->assertOk();
        $page->assertSee('id="ai-authoring"', false);
        $page->assertSee(__('lf.LF_ai_authoring_framework_missing_teacher'));
        // A teacher has no page to select a Framework, so no link is offered, and
        // the view is not even handed one.
        $page->assertDontSee('tab=learning', false);
        $this->assertNull($page->viewData('aiAuthoring')['framework_url']);
        $config = $this->config($page);
        $this->assertFalse($config['isAdmin']);
        $this->assertStringStartsWith(
            self::HOST.'/teacher/course-templates/'.$this->templateId.'/activities/'.$this->activityId.'/ai-authoring/',
            $config['urls']['proposals'],
        );
    }

    /**
     * The point of D1: these people open the page but must not see AI.
     *
     * @return array<string,array{string}>
     */
    public static function pageOnlyAssignments(): array
    {
        return ['generic teacher assignment' => ['teacher'], 'observer' => ['observer']];
    }

    #[DataProvider('pageOnlyAssignments')]
    public function test_an_assignment_that_only_opens_the_page_gets_no_ai_section(string $assignmentRole): void
    {
        $teacher = $this->user('teacher');
        $this->assign($teacher, $assignmentRole);

        $page = $this->actingAs($teacher)->get($this->pageUrl('teacher'));

        $page->assertOk();
        $page->assertSee('Video 1');
        $page->assertDontSee('id="ai-authoring"', false);
        $page->assertDontSee(__('lf.LF_ai_authoring_title'));
    }

    public function test_the_templates_creator_without_an_assignment_opens_the_page_but_gets_no_ai_section(): void
    {
        $creator = $this->user('teacher');
        DB::table('core_course_templates')->where('id', $this->templateId)->update(['created_by' => $creator->id]);

        $page = $this->actingAs($creator)->get($this->pageUrl('teacher'));

        $page->assertOk();
        $page->assertDontSee('id="ai-authoring"', false);
    }

    /**
     * The creator can still open the page, so this isolates the AI authority
     * itself: an ended assignment carries no AI even for someone who is in.
     */
    public function test_an_ended_ai_assignment_gives_no_section_even_to_someone_who_can_open_the_page(): void
    {
        $creator = $this->user('teacher');
        DB::table('core_course_templates')->where('id', $this->templateId)->update(['created_by' => $creator->id]);
        $assignment = $this->assign($creator, 'primary');
        $this->actingAs($creator)->get($this->pageUrl('teacher'))->assertSee('id="ai-authoring"', false);

        DB::table('core_course_template_teachers')->where('id', $assignment)->update(['status' => 'inactive']);

        $page = $this->actingAs($creator)->get($this->pageUrl('teacher'));
        $page->assertOk();
        $page->assertDontSee('id="ai-authoring"', false);
    }

    public function test_a_revoked_ai_assignment_removes_the_section(): void
    {
        $teacher = $this->user('teacher');
        $assignment = $this->assign($teacher, 'primary');
        $this->actingAs($teacher)->get($this->pageUrl('teacher'))->assertSee('id="ai-authoring"', false);

        DB::table('core_course_template_teachers')->where('id', $assignment)->update(['status' => 'inactive']);

        // The page itself is no longer reachable for an inactive assignment.
        $this->actingAs($teacher)->get($this->pageUrl('teacher'))->assertNotFound();
    }

    public function test_the_section_appears_for_an_activity_inside_a_section_too(): void
    {
        $sectionId = DB::table('core_course_template_sections')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $this->templateId,
            'title' => 'Phần 1', 'display_order' => 1, 'allows_lessons' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_template_lessons')->where('id', $this->lessonId)->update(['template_section_id' => $sectionId]);

        $page = $this->actingAs($this->user('customer_admin'))->get(
            self::HOST.'/admin/course-templates/'.$this->templateId.'/sections/'.$sectionId.'/lessons/'.$this->lessonId.'/activities/'.$this->activityId,
        );

        $page->assertOk();
        $page->assertSee('id="ai-authoring"', false);
        // The AI routes carry the Template and Activity only, never lesson or section.
        $this->assertStringContainsString(
            '/course-templates/'.$this->templateId.'/activities/'.$this->activityId.'/ai-authoring/proposals',
            $this->config($page)['urls']['proposals'],
        );
        $this->assertStringNotContainsString('/lessons/', $this->config($page)['urls']['proposals']);
    }

    public function test_the_section_carries_routes_and_selection_only_never_identity_or_content(): void
    {
        $page = $this->actingAs($this->user('customer_admin'))->get($this->pageUrl('admin'));

        $config = $this->config($page);
        $this->assertEqualsCanonicalizing(['isAdmin', 'framework', 'draftVersions', 'publishedVersions', 'urls', 'mappingsUrl', 'csrfToken'], array_keys($config));
        $this->assertSame(csrf_token(), $config['csrfToken']);
        $this->assertEqualsCanonicalizing(['proposals', 'generation_requests', 'bulk_decisions'], array_keys($config['urls']));
        foreach ($config['urls'] as $url) {
            $this->assertStringStartsWith(self::HOST.'/admin/course-templates/', $url);
        }
        $this->assertStringNotContainsString('customer', json_encode($config));
    }

    public function test_the_templates_mapping_list_is_linked_for_an_admin_only(): void
    {
        $admin = $this->config($this->actingAs($this->user('customer_admin'))->get($this->pageUrl('admin')));
        $this->assertStringEndsWith('/admin/course-templates/'.$this->templateId.'/edit?tab=learning', $admin['mappingsUrl']);

        $teacher = $this->user('teacher');
        $this->assign($teacher, 'primary');
        $this->assertNull($this->config($this->actingAs($teacher)->get($this->pageUrl('teacher')))['mappingsUrl']);
    }

    public function test_the_script_receives_every_text_from_the_translation_files_and_holds_none_of_its_own(): void
    {
        $page = $this->actingAs($this->user('customer_admin'))->get($this->pageUrl('admin'));

        $this->assertSame(1, preg_match('/data-ai-authoring-messages="([^"]*)"/', $page->getContent(), $match));
        $messages = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
        $expected = collect(require base_path('resources/lang/vi/lf.php'))
            ->filter(fn ($text, $key) => str_starts_with($key, 'LF_ai_authoring_'))
            ->mapWithKeys(fn ($text, $key) => [substr($key, strlen('LF_ai_authoring_')) => $text])
            ->all();

        $this->assertEquals($expected, $messages);
        // Every key the script asks for must exist, for each kind, status and action it can meet.
        foreach (['pending_review', 'accepted', 'rejected', 'stale', 'deletion_pending', 'deleted', 'unknown'] as $status) {
            $this->assertArrayHasKey('status_'.$status, $messages);
        }
        foreach (['summary', 'concept', 'learning_objective', 'competency', 'node_mapping', 'unknown'] as $kind) {
            $this->assertArrayHasKey('kind_'.$kind, $messages);
        }
        foreach (['edit', 'accept', 'reject', 'approve_node', 'confirm_target', 'reconfirm_target', 'reject_target', 'rebase_target', 'inherit_draft', 'rebase_selection', 'reconfirm_context', 'cancel_application', 'apply_intent', 'retry_application', 'unknown'] as $action) {
            $this->assertArrayHasKey('review_'.$action, $messages);
        }
    }

    public function test_the_ai_translation_keys_exist_in_both_languages(): void
    {
        $vi = array_filter(array_keys(require base_path('resources/lang/vi/lf.php')), fn ($key) => str_starts_with($key, 'LF_ai_authoring_'));
        $en = array_filter(array_keys(require base_path('resources/lang/en/lf.php')), fn ($key) => str_starts_with($key, 'LF_ai_authoring_'));

        $this->assertNotEmpty($vi);
        $this->assertEqualsCanonicalizing($vi, $en, 'Every AI authoring key needs both a VI and an EN text.');
    }

    // ------------------------------------------------------------------ helpers

    private function pageUrl(string $role): string
    {
        return self::HOST.'/'.$role.'/course-templates/'.$this->templateId.'/lessons/'.$this->lessonId.'/activities/'.$this->activityId;
    }

    /** @return array<string,mixed> */
    private function config($response): array
    {
        $this->assertSame(1, preg_match('/data-ai-authoring="([^"]*)"/', $response->getContent(), $match));

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    }

    private function user(string $role): User
    {
        return User::forceCreate([
            'customer_id' => $this->customerId, 'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function assign(User $teacher, string $role): int
    {
        return DB::table('core_course_template_teachers')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $this->templateId, 'teacher_id' => $teacher->id,
            'role' => $role, 'sort_order' => 0, 'status' => 'active', 'assigned_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function template(): int
    {
        return DB::table('core_course_templates')->insertGetId([
            'customer_id' => $this->customerId, 'category_id' => null, 'title' => 'Khoá học mẫu',
            'estimated_minutes_per_lesson' => 0, 'lesson_count' => 0, 'working_revision' => 1,
            'status' => 'draft', 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
