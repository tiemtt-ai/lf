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
            ->assertSeeText('Home')
            ->assertSeeText('Courses')
            ->assertSeeText('Assessments')
            ->assertSeeText('Services')
            ->assertSeeText('Teachers')
            ->assertSeeText('About')
            ->assertSeeText('Contact')
            ->assertSeeText('Login')
            ->assertSeeText('Featured Courses')
            ->assertSeeText('Featured Services')
            ->assertSeeText('News')
            ->assertSeeText('Contact / CTA')
            ->assertDontSeeText('Learning History')
            ->assertSeeText('Login to Register / Login to Purchase');
    }

    public function test_verified_student_sees_public_and_personalized_content_on_the_same_homepage(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/')
            ->assertOk()
            ->assertSeeText('Featured Courses')
            ->assertSeeText('Featured Services')
            ->assertSeeText('Teachers')
            ->assertSeeText('News')
            ->assertSeeText('My Courses')
            ->assertSeeText('Learning History')
            ->assertSeeText('AI Tutor')
            ->assertSeeText('Profile')
            ->assertSeeText('Logout')
            ->assertSeeText('Continue Learning')
            ->assertSeeText('Upcoming Activities')
            ->assertSeeText('Pending Assessments')
            ->assertSeeText('AI Recommendations')
            ->assertSee('href="https://tenant-a.localhost/profile"', false)
            ->assertSee('action="https://tenant-a.localhost/logout"', false);
    }

    public function test_student_personalized_routes_are_role_protected(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');

        $paths = ['/my-courses', '/learning-history', '/ai-tutor', '/profile'];

        $this->get('https://tenant-a.localhost/my-courses')
            ->assertRedirect('https://tenant-a.localhost/login');

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
    }

    public function test_course_actions_distinguish_guest_available_enrolled_and_favorite_states(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');

        $this->get('https://tenant-a.localhost/courses')
            ->assertOk()
            ->assertSeeText('Login to Register / Login to Purchase');

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/courses')
            ->assertOk()
            ->assertSeeText('Continue Learning')
            ->assertSeeText('View Progress')
            ->assertSeeText('Take Assessments')
            ->assertSeeText('Register')
            ->assertSeeText('Purchase')
            ->assertSeeText('Add To Favorites')
            ->assertSeeText('Remove Favorite');
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
