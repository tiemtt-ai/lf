<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Left menu "Kiểm tra" (ADR-0025 D2): one group in the admin and teacher back office.
 * Categories are admin-only; teachers pick them but do not manage them.
 */
class AssessmentNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_SECTIONS = ['categories', 'questions', 'passages', 'quizzes', 'registrations', 'results'];

    private const TEACHER_SECTIONS = ['questions', 'passages', 'quizzes', 'registrations', 'results'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
        ]);
    }

    public function test_admin_sidebar_has_assessment_group_with_six_items_in_order(): void
    {
        $admin = $this->createUser($this->createTenant(), 'customer_admin');

        $html = $this->actingAs($admin)->get('https://tenant-a.localhost/admin')->assertOk()->getContent();
        $sidebar = $this->sidebar($html);

        $this->assertStringContainsString('aria-label="'.e(__('lf.LF_navigation_group_assessment')).'"', $sidebar);
        $this->assertSame(
            self::ADMIN_SECTIONS,
            $this->childSections($sidebar, 'admin'),
        );
        // A standalone product: the last group of the sidebar, after learning frameworks (Owner 2026-11-06).
        $this->assertLessThan(strpos($sidebar, '/admin/assessments/'), strpos($sidebar, '/admin/course-cohorts'));
        $this->assertLessThan(strpos($sidebar, '/admin/assessments/'), strpos($sidebar, '/admin/learning-frameworks'));
    }

    public function test_teacher_sidebar_has_assessment_group_without_categories(): void
    {
        $teacher = $this->createUser($this->createTenant(), 'teacher');

        $html = $this->actingAs($teacher)->get('https://tenant-a.localhost/teacher')->assertOk()->getContent();

        $this->assertSame(self::TEACHER_SECTIONS, $this->childSections($this->sidebar($html), 'teacher'));
        $this->actingAs($teacher)->get('https://tenant-a.localhost/teacher/assessments/categories')->assertNotFound();
    }

    public function test_each_section_page_renders_and_marks_its_menu_item_active(): void
    {
        $customerId = $this->createTenant();
        $areas = [
            'admin' => [$this->createUser($customerId, 'customer_admin'), self::ADMIN_SECTIONS],
            'teacher' => [$this->createUser($customerId, 'teacher'), self::TEACHER_SECTIONS],
        ];
        $group = preg_quote(e(__('lf.LF_navigation_group_assessment')), '/');

        foreach ($areas as $area => [$user, $sections]) {
            foreach ($sections as $section) {
                $html = $this->actingAs($user)
                    ->get("https://tenant-a.localhost/{$area}/assessments/{$section}")
                    ->assertOk()
                    ->assertSee('data-assessment-section="'.$section.'"', false)
                    ->assertSeeText(__('lf.LF_assessment_'.$section.'_help'))
                    ->getContent();

                $this->assertMatchesRegularExpression('/<button[^>]+aria-label="'.$group.'"[^>]+data-active-parent="true"/', $html);
                $this->assertMatchesRegularExpression(
                    '/class="admin-nav__child" href="[^"]+\/'.$area.'\/assessments\/'.$section.'"[^>]*aria-current="page"/',
                    $html,
                );
            }
        }
    }

    public function test_create_buttons_say_what_they_add(): void
    {
        $admin = $this->createUser($this->createTenant(), 'customer_admin');
        $labels = [
            'categories' => 'lf.LF_assessment_category_create',
            'questions' => 'lf.LF_assessment_question_create',
            'passages' => 'lf.LF_assessment_passage_create',
            'quizzes' => 'lf.LF_assessment_quiz_create',
        ];

        foreach ($labels as $section => $key) {
            $html = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/assessments/{$section}")->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<button[^>]*data-ui-dialog-form-open[^>]*>\s*<svg.*?<\/svg>\s*'.preg_quote(e(__($key)), '/').'\s*<\/button>/s',
                $html,
                $section,
            );
        }
    }

    public function test_module_root_redirects_to_questions(): void
    {
        $customerId = $this->createTenant();

        $this->actingAs($this->createUser($customerId, 'customer_admin'))
            ->get('https://tenant-a.localhost/admin/assessments')
            ->assertRedirect('https://tenant-a.localhost/admin/assessments/questions');
        $this->actingAs($this->createUser($customerId, 'teacher'))
            ->get('https://tenant-a.localhost/teacher/assessments')
            ->assertRedirect('https://tenant-a.localhost/teacher/assessments/questions');
    }

    public function test_guests_and_wrong_roles_cannot_open_back_office_assessment_pages(): void
    {
        $customerId = $this->createTenant();

        $this->get('https://tenant-a.localhost/admin/assessments/questions')->assertRedirect();
        $this->get('https://tenant-a.localhost/teacher/assessments/questions')->assertRedirect();

        $student = $this->createUser($customerId, 'student');
        $this->actingAs($student)->get('https://tenant-a.localhost/admin/assessments/questions')->assertForbidden();
        $this->actingAs($student)->get('https://tenant-a.localhost/teacher/assessments/questions')->assertForbidden();

        $teacher = $this->createUser($customerId, 'teacher');
        $this->actingAs($teacher)->get('https://tenant-a.localhost/admin/assessments/questions')->assertForbidden();

        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin)->get('https://tenant-a.localhost/teacher/assessments/questions')->assertForbidden();
    }

    public function test_admin_of_another_tenant_cannot_open_the_pages(): void
    {
        $this->createTenant();
        $otherAdmin = $this->createUser($this->createTenant('tenant-b'), 'customer_admin');

        $response = $this->actingAs($otherAdmin)->get('https://tenant-a.localhost/admin/assessments/questions');

        $this->assertContains($response->getStatusCode(), [302, 403, 404]);
        $this->assertStringNotContainsString('data-assessment-section', (string) $response->getContent());
    }

    public function test_menu_labels_are_translated_in_english(): void
    {
        $admin = $this->createUser($this->createTenant(), 'customer_admin');

        $this->actingAs($admin)
            ->withSession(['locale' => 'en'])
            ->get('https://tenant-a.localhost/admin/assessments/results')
            ->assertOk()
            ->assertSeeText('Assessments')
            ->assertSeeText('Results & grading')
            ->assertDontSeeText('Kết quả & Chấm bài');
    }

    /** @return list<string> */
    private function childSections(string $sidebar, string $area): array
    {
        preg_match_all('/class="admin-nav__child" href="[^"]+\/'.$area.'\/assessments\/([a-z-]+)"/', $sidebar, $matches);

        return $matches[1];
    }

    private function sidebar(string $html): string
    {
        preg_match('/<aside id="backend-sidebar".*?<\/aside>/s', $html, $matches);

        return $matches[0] ?? '';
    }

    private function createTenant(string $slug = 'tenant-a'): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug,
            'slug' => $slug,
            'subdomain' => $slug,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(int $customerId, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
