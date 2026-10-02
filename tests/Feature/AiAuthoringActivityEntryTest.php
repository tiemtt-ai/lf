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
 * D11 (AI Authoring Review UI design): the way in to the "AI proposals" section.
 *
 * The section lives on the Activity page, but the View button on an Activity row
 * opens the file or the outside link for any Activity that has Media or a link, so
 * that page could not be reached from the list. Each row of an Activity that
 * carries Media (video, audio, document) now has its own "AI proposals" link, for
 * people with AI authority only; the other kinds have nothing for AI to read and
 * the page says so instead of offering a form that can only fail.
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
    public function test_the_row_link_is_offered_only_for_an_activity_that_carries_media(string $type, bool $offered): void
    {
        $activityId = $this->activity($this->lessonId, $type);

        $html = $this->actingAs($this->user('customer_admin'))->get($this->editUrl('admin'))->assertOk()->getContent();

        if ($offered) {
            $this->assertSame(1, substr_count($html, 'data-ai-authoring-entry'));
            $this->assertStringContainsString(
                self::HOST.'/admin/course-templates/'.$this->templateId.'/lessons/'.$this->lessonId.'/activities/'.$activityId.'#ai-authoring"',
                $html,
            );
            $this->assertStringContainsString(__('lf.LF_ai_authoring_open'), $html);
        } else {
            $this->assertStringNotContainsString('data-ai-authoring-entry', $html);
        }
    }

    public function test_the_link_goes_through_the_section_for_an_activity_inside_one(): void
    {
        $sectionId = DB::table('core_course_template_sections')->insertGetId([
            'customer_id' => $this->customerId, 'template_id' => $this->templateId,
            'title' => 'Phần 1', 'display_order' => 1, 'allows_lessons' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lessonId = $this->lesson($sectionId, 'Bài trong phần');
        $activityId = $this->activity($lessonId, 'document');

        $html = $this->actingAs($this->user('customer_admin'))->get($this->editUrl('admin'))->assertOk()->getContent();

        $this->assertStringContainsString(
            '/admin/course-templates/'.$this->templateId.'/sections/'.$sectionId.'/lessons/'.$lessonId.'/activities/'.$activityId.'#ai-authoring"',
            $html,
        );
    }

    /** @return array<string,array{string}> */
    public static function aiRoles(): array
    {
        return ['primary' => ['primary'], 'assistant' => ['assistant'], 'reviewer' => ['reviewer']];
    }

    #[DataProvider('aiRoles')]
    public function test_a_teacher_with_an_ai_assignment_gets_the_link(string $assignmentRole): void
    {
        $this->activity($this->lessonId, 'video');
        $teacher = $this->user('teacher');
        $this->assign($teacher, $assignmentRole);

        $html = $this->actingAs($teacher)->get($this->editUrl('teacher'))->assertOk()->getContent();

        $this->assertStringContainsString('data-ai-authoring-entry', $html);
        $this->assertStringContainsString(self::HOST.'/teacher/course-templates/'.$this->templateId.'/lessons/', $html);
    }

    public function test_someone_who_can_open_the_page_but_has_no_ai_authority_gets_no_link(): void
    {
        $this->activity($this->lessonId, 'video');
        $teacher = $this->user('teacher');
        $this->assign($teacher, 'teacher');

        $response = $this->actingAs($teacher)->get($this->editUrl('teacher'));

        $response->assertOk();
        $this->assertStringNotContainsString('data-ai-authoring-entry', $response->getContent());
    }

    public function test_an_ended_assignment_takes_the_link_away(): void
    {
        $this->activity($this->lessonId, 'video');
        $creator = $this->user('teacher');
        DB::table('core_course_templates')->where('id', $this->templateId)->update(['created_by' => $creator->id]);
        $assignment = $this->assign($creator, 'primary');
        $this->assertStringContainsString('data-ai-authoring-entry', $this->actingAs($creator)->get($this->editUrl('teacher'))->getContent());

        DB::table('core_course_template_teachers')->where('id', $assignment)->update(['status' => 'inactive']);

        $response = $this->actingAs($creator)->get($this->editUrl('teacher'));
        $response->assertOk();
        $this->assertStringNotContainsString('data-ai-authoring-entry', $response->getContent());
    }

    public function test_the_activity_page_for_a_kind_without_media_says_so_and_offers_no_form(): void
    {
        $activityId = $this->activity($this->lessonId, 'quiz');

        $page = $this->actingAs($this->user('customer_admin'))->get($this->activityUrl($activityId));

        $page->assertOk();
        $page->assertSee('data-ai-authoring-unsupported', false);
        $page->assertSee(__('lf.LF_ai_authoring_no_media'));
        // No script configuration, no list, no create form to fail.
        $page->assertDontSee('data-ai-authoring="', false);
        $page->assertDontSee('data-ai-authoring-list', false);
    }

    public function test_the_activity_page_for_a_kind_with_media_keeps_its_section(): void
    {
        $activityId = $this->activity($this->lessonId, 'document');

        $page = $this->actingAs($this->user('customer_admin'))->get($this->activityUrl($activityId));

        $page->assertOk();
        $page->assertSee('data-ai-authoring="', false);
        $page->assertDontSee('data-ai-authoring-unsupported', false);
    }

    // ------------------------------------------------------------------ helpers

    private function editUrl(string $role): string
    {
        return self::HOST.'/'.$role.'/course-templates/'.$this->templateId.'/edit?tab=structure';
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
