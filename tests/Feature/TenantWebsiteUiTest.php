<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantWebsiteUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
        ]);
    }

    public function test_guest_sees_the_tenant_storefront_and_public_course_actions(): void
    {
        $this->createTenant();

        $this->get('https://tenant-a.localhost/')
            ->assertOk()
            ->assertSeeText(__('lf.LF_navigation_menu_public_home'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_courses'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_assessments'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_services'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_teachers'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_about'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_contact'))
            ->assertSeeText(__('lf.LF_navigation_menu_guest_login'))
            ->assertSeeText(__('lf.LF_home_public_featured_courses'))
            ->assertSeeText(__('lf.LF_home_public_featured_services'))
            ->assertSeeText(__('lf.LF_home_public_news'))
            ->assertSeeText(__('lf.LF_home_public_contact_cta'))
            ->assertDontSeeText(__('lf.LF_navigation_menu_student_learning_history'))
            ->assertSeeText(__('lf.LF_course_card_guest_login_register_purchase'));

        foreach (['/courses', '/assessments', '/services', '/teachers', '/about', '/contact'] as $path) {
            $this->get('https://tenant-a.localhost'.$path)->assertOk();
        }
    }

    public function test_verified_student_sees_public_and_personalized_content_on_the_same_homepage(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/')
            ->assertOk()
            ->assertSeeText(__('lf.LF_home_public_featured_courses'))
            ->assertSeeText(__('lf.LF_home_public_featured_services'))
            ->assertSeeText(__('lf.LF_navigation_menu_public_teachers'))
            ->assertSeeText(__('lf.LF_home_public_news'))
            ->assertSeeText(__('lf.LF_navigation_menu_student_my_courses'))
            ->assertSeeText(__('lf.LF_navigation_menu_student_learning_history'))
            ->assertSeeText(__('lf.LF_navigation_menu_student_ai_tutor'))
            ->assertSeeText(__('lf.LF_navigation_menu_student_profile'))
            ->assertSeeText(__('lf.LF_navigation_menu_student_logout'))
            ->assertSeeText(__('lf.LF_home_student_continue_learning'))
            ->assertSeeText(__('lf.LF_home_student_upcoming_activities'))
            ->assertSeeText(__('lf.LF_home_student_pending_assessments'))
            ->assertSeeText(__('lf.LF_home_student_ai_recommendations'))
            ->assertSee('href="https://tenant-a.localhost/profile"', false)
            ->assertSee('action="https://tenant-a.localhost/logout"', false);
    }

    public function test_student_personalized_routes_are_role_protected(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');

        $paths = ['/my-courses', '/learning-history', '/ai-tutor', '/profile'];

        foreach ($paths as $path) {
            $this->get('https://tenant-a.localhost'.$path)
                ->assertRedirect('https://tenant-a.localhost/login');
        }

        foreach ($paths as $path) {
            $this->actingAs($teacher)
                ->get('https://tenant-a.localhost'.$path)
                ->assertForbidden();
        }

        foreach ($paths as $path) {
            $this->actingAs($student)
                ->get('https://tenant-a.localhost'.$path)
                ->assertOk();
        }

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/admin')
            ->assertForbidden();

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/teacher')
            ->assertForbidden();

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/admin')
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/teacher')
            ->assertForbidden();
    }

    public function test_course_actions_distinguish_guest_available_enrolled_and_favorite_states(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');

        $this->get('https://tenant-a.localhost/courses')
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_card_guest_login_register_purchase'));

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/courses')
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_card_student_continue_learning'))
            ->assertSeeText(__('lf.LF_course_card_student_view_progress'))
            ->assertSeeText(__('lf.LF_course_card_student_take_assessments'))
            ->assertSeeText(__('lf.LF_course_card_student_register'))
            ->assertSeeText(__('lf.LF_course_card_student_purchase'))
            ->assertSeeText(__('lf.LF_course_card_student_add_to_favorites'))
            ->assertSeeText(__('lf.LF_course_card_student_remove_favorite'));
    }

    private function createTenant(): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'subdomain' => 'tenant-a',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(int $customerId, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@tenant-website.test',
            'password' => Hash::make('test-only-password'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
