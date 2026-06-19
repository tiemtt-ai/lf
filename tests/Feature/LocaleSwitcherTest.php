<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LocaleSwitcherTest extends TestCase
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

    public function test_default_locale_is_vietnamese(): void
    {
        $this->createTenant();

        $this->assertSame('vi', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));

        $this->get('https://tenant-a.localhost/')
            ->assertOk()
            ->assertSee('<html lang="vi">', false)
            ->assertSeeText(__('lf.LF_navigation_menu_public_home'))
            ->assertSee('<span class="language-switcher-code">VI</span>', false)
            ->assertSee('action="https://tenant-a.localhost/language/en"', false);
    }

    public function test_main_public_homepage_defaults_to_vietnamese_without_session_locale(): void
    {
        $this->assertNull(session('locale'));

        $this->get('https://localhost/')
            ->assertOk()
            ->assertSessionMissing('locale')
            ->assertSee('<html lang="vi">', false)
            ->assertSeeText(__('lf.LF_home_public_title'))
            ->assertSee('action="https://localhost/language/en"', false);
    }

    public function test_locale_can_switch_to_english_and_back_to_vietnamese(): void
    {
        $this->createTenant();

        $this->from('https://tenant-a.localhost/')
            ->post('https://tenant-a.localhost/language/en')
            ->assertRedirect('https://tenant-a.localhost/')
            ->assertSessionHas('locale', 'en');

        $this->get('https://tenant-a.localhost/')
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSeeText('Home')
            ->assertSee('<span class="language-switcher-code">EN</span>', false)
            ->assertSee('action="https://tenant-a.localhost/language/vi"', false);

        $this->from('https://tenant-a.localhost/')
            ->post('https://tenant-a.localhost/language/vi')
            ->assertRedirect('https://tenant-a.localhost/')
            ->assertSessionHas('locale', 'vi');

        $this->get('https://tenant-a.localhost/')
            ->assertOk()
            ->assertSee('<html lang="vi">', false)
            ->assertSeeText('Trang chủ')
            ->assertSee('<span class="language-switcher-code">VI</span>', false)
            ->assertSee('action="https://tenant-a.localhost/language/en"', false);
    }

    public function test_invalid_locale_is_not_accepted(): void
    {
        $this->createTenant();

        $this->post('https://tenant-a.localhost/language/fr')
            ->assertNotFound()
            ->assertSessionMissing('locale');
    }

    public function test_main_public_site_can_switch_to_english_and_back_to_vietnamese(): void
    {
        $this->get('https://localhost/features')
            ->assertOk()
            ->assertSeeText('Tính năng')
            ->assertSee('action="https://localhost/language/en"', false);

        $this->from('https://localhost/features')
            ->post('https://localhost/language/en')
            ->assertRedirect('https://localhost/features')
            ->assertSessionHas('locale', 'en');

        $this->get('https://localhost/features')
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSeeText('Features')
            ->assertSee('action="https://localhost/language/vi"', false);

        $this->from('https://localhost/features')
            ->post('https://localhost/language/vi')
            ->assertRedirect('https://localhost/features')
            ->assertSessionHas('locale', 'vi');

        $this->get('https://localhost/features')
            ->assertOk()
            ->assertSee('<html lang="vi">', false)
            ->assertSeeText('Tính năng')
            ->assertSee('action="https://localhost/language/en"', false);
    }

    public function test_switcher_is_available_in_admin_and_teacher_topbars(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin')
            ->assertOk()
            ->assertSee('action="https://tenant-a.localhost/language/en"', false);

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher')
            ->assertOk()
            ->assertSee('action="https://tenant-a.localhost/language/en"', false);

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/')
            ->assertOk()
            ->assertSee('action="https://tenant-a.localhost/language/en"', false);
    }

    public function test_switching_locale_preserves_auth_and_does_not_bypass_protected_routes(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');

        $this->actingAs($student)
            ->from('https://tenant-a.localhost/my-courses')
            ->post('https://tenant-a.localhost/language/en')
            ->assertRedirect('https://tenant-a.localhost/my-courses')
            ->assertSessionHas('locale', 'en');

        $this->assertAuthenticatedAs($student);

        $this->get('https://tenant-a.localhost/my-courses')
            ->assertOk()
            ->assertSee('<html lang="en">', false);

        $this->post('https://tenant-a.localhost/logout');

        $this->from('https://tenant-a.localhost/')
            ->post('https://tenant-a.localhost/language/vi')
            ->assertRedirect('https://tenant-a.localhost/');

        $this->get('https://tenant-a.localhost/my-courses')
            ->assertRedirect('https://tenant-a.localhost/login');
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
            'email' => $role.'-'.uniqid().'@locale.test',
            'password' => Hash::make('test-only-password'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
