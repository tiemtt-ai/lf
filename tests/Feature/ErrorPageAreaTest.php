<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 404 and 403 pages are drawn in the visitor's area (Owner 2026-11-18): the admin/teacher shell, the tenant's own
 * website, or the LearnForge root site. None of the tenant areas may link to LearnForge (customer sign-up).
 */
class ErrorPageAreaTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://tenant-a.localhost';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
        ]);
    }

    public function test_an_unknown_admin_url_shows_the_404_inside_the_admin_shell(): void
    {
        $admin = $this->createUser($this->createTenant('tenant-a'), 'admin@example.test', 'customer_admin');

        $this->actingAs($admin)->get(self::HOST.'/admin/no-such-page')
            ->assertNotFound()
            ->assertSee('id="admin-app"', false)
            ->assertSee('ui-error-card', false)
            ->assertSeeText(__('lf.LF_common_title_public_page_not_found'))
            ->assertSee('href="'.self::HOST.'/admin"', false)
            ->assertSeeText(__('lf.LF_common_button_common_back_to_dashboard'))
            ->assertDontSee('register-customer', false)
            ->assertDontSee('public-error-card', false);
    }

    public function test_an_unknown_teacher_url_shows_the_404_inside_the_teacher_shell(): void
    {
        $teacher = $this->createUser($this->createTenant('tenant-a'), 'teacher@example.test', 'teacher');

        $this->actingAs($teacher)->get(self::HOST.'/teacher/no-such-page')
            ->assertNotFound()
            ->assertSee('id="admin-app"', false)
            ->assertSee('ui-error-card', false)
            ->assertSee('href="'.self::HOST.'/teacher"', false)
            ->assertDontSee('href="'.self::HOST.'/admin"', false)
            ->assertDontSee('register-customer', false);
    }

    public function test_a_forbidden_backend_page_shows_the_403_inside_the_shell(): void
    {
        $admin = $this->createUser($this->createTenant('tenant-a'), 'admin@example.test', 'customer_admin');

        $this->actingAs($admin)->get(self::HOST.'/teacher')
            ->assertForbidden()
            ->assertSee('id="admin-app"', false)
            ->assertSeeText(__('lf.LF_common_title_public_page_forbidden'))
            ->assertSeeText(__('lf.LF_common_message_public_page_forbidden'))
            ->assertSee('href="'.self::HOST.'/admin"', false);
    }

    public function test_guests_students_and_staff_on_the_website_see_the_tenant_site(): void
    {
        $tenantId = $this->createTenant('tenant-a');
        $student = $this->createUser($tenantId, 'student@example.test', 'student');
        $admin = $this->createUser($tenantId, 'admin@example.test', 'customer_admin');

        $responses = [
            'guest in /admin' => $this->get(self::HOST.'/admin/no-such-page'),
            'student' => $this->actingAs($student)->get(self::HOST.'/no-such-page'),
            'admin on the website' => $this->actingAs($admin)->get(self::HOST.'/no-such-page'),
        ];

        foreach ($responses as $case => $response) {
            $response->assertNotFound();
            $this->assertStringContainsString('sb2b-header', $response->getContent(), $case);
            $this->assertStringContainsString('public-error-actions', $response->getContent(), $case);
            $this->assertStringContainsString('href="'.self::HOST.'"', $response->getContent(), $case);
            $this->assertStringNotContainsString('id="admin-app"', $response->getContent(), $case);
            $this->assertStringNotContainsString('register-customer', $response->getContent(), $case);
        }
    }

    public function test_a_user_of_another_tenant_never_gets_this_tenants_admin_shell(): void
    {
        $this->createTenant('tenant-a');
        $outsider = $this->createUser($this->createTenant('tenant-b'), 'admin-b@example.test', 'customer_admin');

        $this->actingAs($outsider)->get(self::HOST.'/admin/no-such-page')
            ->assertNotFound()
            ->assertDontSee('id="admin-app"', false)
            ->assertSee('sb2b-header', false);
    }

    public function test_the_root_domain_keeps_the_learnforge_404(): void
    {
        $this->get('https://localhost/no-such-page')
            ->assertNotFound()
            ->assertSee('public-error-card', false)
            ->assertSee('register-customer', false)
            ->assertDontSee('id="admin-app"', false);
    }

    public function test_the_fallback_keeps_405_for_a_known_path_and_404_for_any_method_elsewhere(): void
    {
        $this->createTenant('tenant-a');

        // The 405 is drawn in the tenant's site too, never as the framework's bare page.
        $this->get(self::HOST.'/logout')->assertStatus(405)
            ->assertSee('sb2b-header', false)->assertSeeText(__('lf.LF_common_title_public_page_method_not_allowed'));
        $this->post(self::HOST.'/no-such-page')->assertNotFound();
        $this->delete(self::HOST.'/admin/no-such-page')->assertNotFound();
        $this->call('OPTIONS', self::HOST.'/logout')->assertOk()->assertHeader('Allow', 'POST');
    }

    private function createTenant(string $slug): int
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

    private function createUser(int $customerId, string $email, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
