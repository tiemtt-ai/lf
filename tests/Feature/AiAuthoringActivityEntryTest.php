<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Single-entry amendment: AI proposals live in one place, the Course Template's
 * "Outcomes & competencies" tab, never on the Activity list or the Activity page.
 * The tab offers the Template's Media Activities (video, audio, document) in a
 * selector, for people with AI authority only; choosing one reloads the page with
 * the section built for exactly that Activity.
 */
class AiAuthoringActivityEntryTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://tenant-a.localhost';

    private int $customerId;

    private int $templateId;

    private int $lessonId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => self::HOST, 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);
        URL::forceRootUrl(self::HOST);
        URL::forceScheme('https');
        TenantContext::set(null);

        $this->customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'tenant-a', 'slug' => 'tenant-a', 'subdomain' => 'tenant-a',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $this->customerId, 'category_id' => null, 'title' => 'Khoá học mẫu',
            'estimated_minutes_per_lesson' => 0, 'lesson_count' => 0, 'working_revision' => 1,
            'status' => 'draft', 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->lessonId = $this->lesson(null, 'Bài 1');
    }

    protected function tearDown(): void
    {
        URL::forceRootUrl(null);
        TenantContext::set(null);
        parent::tearDown();
    }

    /** @return array<string,array{string,bool}> */
    public static function kinds(): array
    {
        return [
            'video' => ['video', true], 'audio' => ['audio', true], 'document' => ['document', true],
            'embedded video (a link)' => ['embedded_video', false], 'quiz' => ['quiz', false],
            'live class (a link)' => ['live_class', false],
        ];
    }

    #[DataProvider('kinds')]
    public function test_no_activity_row_offers_an_ai_proposals_link(string $type, bool $hasMedia): void
    {
        $this->activity($this->lessonId, $type);

        $html = $this->actingAs($this->user('customer_admin'))->get($this->editUrl('admin'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-ai-authoring-entry', $html);
        $this->assertStringNotContainsString('#ai-authoring"', $html);
    }

    public function test_a_teacher_with_an_ai_assignment_gets_no_row_link_either(): void
    {
        $this->activity($this->lessonId, 'video');
        $teacher = $this->user('teacher');
        $this->assign($teacher, 'primary');

        $response = $this->actingAs($teacher)->get($this->editUrl('teacher'));

        $response->assertOk();
        $this->assertStringNotContainsString('data-ai-authoring-entry', $response->getContent());
    }

    public function test_the_activity_page_no_longer_carries_any_ai_section(): void
    {
        foreach (['document', 'quiz'] as $type) {
            $activityId = $this->activity($this->lessonId, $type);

            $page = $this->actingAs($this->user('customer_admin'))->get($this->activityUrl($activityId));

            $page->assertOk();
            $page->assertDontSee('id="ai-authoring"', false);
            $page->assertDontSee('data-ai-authoring', false);
        }
    }

    public function test_the_tab_lists_only_activities_with_media_and_opens_nothing_until_one_is_chosen(): void
    {
        $document = $this->activity($this->lessonId, 'document');
        $this->activity($this->lessonId, 'quiz');
        $this->activity($this->lessonId, 'embedded_video');

        $page = $this->actingAs($this->user('customer_admin'))->get($this->tabUrl('admin'));

        $page->assertOk();
        $page->assertSee('id="ai-authoring-entry"', false);
        $this->assertSame(
            [$document],
            array_column($page->viewData('aiAuthoring')['activities'], 'id'),
        );
        $page->assertDontSee('id="ai-authoring"', false);
    }

    public function test_choosing_an_activity_builds_the_section_for_exactly_that_activity(): void
    {
        $first = $this->activity($this->lessonId, 'document');
        $second = $this->activity($this->lessonId, 'video');

        $page = $this->actingAs($this->user('customer_admin'))->get($this->tabUrl('admin', $second));

        $page->assertOk();
        $page->assertSee('id="ai-authoring"', false);
        $this->assertSame(1, preg_match('/data-ai-authoring="([^"]*)"/', $page->getContent(), $match));
        $config = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('/activities/'.$second.'/ai-authoring/', $config['urls']['proposals']);
        $this->assertStringNotContainsString('/activities/'.$first.'/', $config['urls']['proposals']);
    }

    public function test_a_choice_that_is_not_a_media_activity_of_this_template_opens_nothing(): void
    {
        $quiz = $this->activity($this->lessonId, 'quiz');
        $this->activity($this->lessonId, 'document');
        $otherTemplate = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $this->customerId, 'category_id' => null, 'title' => 'Khác',
            'estimated_minutes_per_lesson' => 0, 'lesson_count' => 0, 'working_revision' => 1,
            'status' => 'draft', 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherLesson = DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $otherTemplate, 'template_section_id' => null,
            'title' => 'Bài khác', 'sort_order' => 0, 'is_preview' => false, 'unlock_rule' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreign = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $otherTemplate, 'template_lesson_id' => $otherLesson,
            'title' => 'Tài liệu khác', 'activity_type' => 'document', 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = $this->user('customer_admin');

        foreach ([$quiz, $foreign, 999999] as $id) {
            $page = $this->actingAs($admin)->get($this->tabUrl('admin', $id));
            $page->assertOk();
            $page->assertDontSee('id="ai-authoring"', false);
        }
        $this->actingAs($admin)->get($this->tabUrl('admin').'&ai_activity=abc')->assertOk()->assertDontSee('id="ai-authoring"', false);
    }

    public function test_a_template_without_media_activities_says_so(): void
    {
        $this->activity($this->lessonId, 'quiz');

        $page = $this->actingAs($this->user('customer_admin'))->get($this->tabUrl('admin'));

        $page->assertSee('data-ai-authoring-no-activities', false);
        $page->assertDontSee('id="ai-authoring-activity"', false);
    }

    public function test_a_teacher_with_ai_authority_gets_the_tab_and_a_teacher_without_it_does_not(): void
    {
        $this->activity($this->lessonId, 'document');
        $ai = $this->user('teacher');
        $this->assign($ai, 'assistant');
        $plain = $this->user('teacher');
        $this->assign($plain, 'teacher');

        $this->actingAs($ai)->get($this->tabUrl('teacher'))->assertOk()
            ->assertSee('course-template-tab-learning', false)->assertSee('id="ai-authoring-entry"', false)
            // The admin-only part of the tab is not rendered for a teacher.
            ->assertDontSee('learning-framework-version', false);

        $this->actingAs($plain)->get($this->tabUrl('teacher'))->assertOk()
            ->assertDontSee('course-template-tab-learning', false)->assertDontSee('id="ai-authoring-entry"', false);
    }

    // ------------------------------------------------------------------ helpers

    private function editUrl(string $role): string
    {
        return self::HOST.'/'.$role.'/course-templates/'.$this->templateId.'/edit?tab=structure';
    }

    private function tabUrl(string $role, ?int $activityId = null): string
    {
        return self::HOST.'/'.$role.'/course-templates/'.$this->templateId.'/edit?tab=learning'
            .($activityId === null ? '' : '&ai_activity='.$activityId);
    }

    private function activityUrl(int $activityId): string
    {
        return self::HOST.'/admin/course-templates/'.$this->templateId.'/lessons/'.$this->lessonId.'/activities/'.$activityId;
    }

    private function lesson(?int $sectionId, string $title): int
    {
        return DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $this->templateId, 'template_section_id' => $sectionId,
            'title' => $title, 'sort_order' => 0, 'is_preview' => false, 'unlock_rule' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function activity(int $lessonId, string $type): int
    {
        return DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $this->templateId,
            'template_lesson_id' => $lessonId, 'title' => ucfirst($type).' 1', 'activity_type' => $type,
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
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
}
