<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentPortalUiTest extends TestCase
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

    public function test_guest_uses_the_shared_tenant_login_for_the_student_portal(): void
    {
        $this->createTenant();

        $this->get('https://tenant-a.localhost/student')
            ->assertRedirect('https://tenant-a.localhost/login');

        $this->get('https://tenant-a.localhost/login')
            ->assertOk()
            ->assertSeeText('Đăng nhập');
    }

    public function test_verified_student_sees_the_student_dashboard_experience(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/student')
            ->assertOk()
            ->assertSeeText('Tiếp tục hành trình học tập')
            ->assertSeeText('Tiếp tục học')
            ->assertSeeText('Khoá học đang học')
            ->assertSeeText('Lịch học sắp tới')
            ->assertSeeText('LearnForge AI Tutor')
            ->assertSeeText('Gợi ý ôn tập hôm nay')
            ->assertSeeText('Thành tích tuần')
            ->assertSeeText('Tenant A')
            ->assertSee('student-mobile-trigger', false)
            ->assertSee('href="https://tenant-a.localhost/student/profile"', false)
            ->assertSee('action="https://tenant-a.localhost/logout"', false);
    }

    public function test_student_portal_stays_role_protected(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/student')
            ->assertForbidden();
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
            'email' => $role.'@student-portal.test',
            'password' => Hash::make('test-only-password'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
