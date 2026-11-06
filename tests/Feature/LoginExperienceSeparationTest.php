<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The single /login route has two presentations: the back office variant accepts only
 * customer_admin/teacher, `?experience=student` accepts only students (Guardrails: one login screen).
 */
class LoginExperienceSeparationTest extends TestCase
{
    use RefreshDatabase;

    private const BACKOFFICE_LOGIN = 'https://tenant-a.localhost/login';

    private const STUDENT_LOGIN = 'https://tenant-a.localhost/login?experience=student';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
        ]);
    }

    public function test_backoffice_login_accepts_admin_and_teacher(): void
    {
        $customerId = $this->createTenant('tenant-a');

        foreach (['customer_admin' => '/admin', 'teacher' => '/teacher'] as $role => $destination) {
            $user = $this->createUser($customerId, $role);

            $this->from(self::BACKOFFICE_LOGIN)->post(self::BACKOFFICE_LOGIN, [
                'email' => $user->email,
                'password' => 'password123',
            ])->assertRedirect('https://tenant-a.localhost'.$destination);

            $this->assertAuthenticatedAs($user);
            $this->post('https://tenant-a.localhost/logout')->assertRedirect(self::BACKOFFICE_LOGIN);
            $this->assertGuest();
        }
    }

    public function test_student_login_accepts_student_and_lands_on_tenant_website(): void
    {
        $customerId = $this->createTenant('tenant-a');
        $student = $this->createUser($customerId, 'student');

        $this->get(self::STUDENT_LOGIN)->assertOk()->assertViewIs('auth.student-login');

        $this->from(self::STUDENT_LOGIN)->post(self::STUDENT_LOGIN, [
            'email' => $student->email,
            'password' => 'password123',
        ])->assertRedirect('https://tenant-a.localhost');

        $this->assertAuthenticatedAs($student);
        $this->post('https://tenant-a.localhost/logout')->assertRedirect(self::STUDENT_LOGIN);
    }

    public function test_student_is_rejected_on_backoffice_login_and_pointed_to_student_login(): void
    {
        $customerId = $this->createTenant('tenant-a');
        $student = $this->createUser($customerId, 'student');

        $this->from(self::BACKOFFICE_LOGIN)->post(self::BACKOFFICE_LOGIN, [
            'email' => $student->email,
            'password' => 'password123',
        ])
            ->assertRedirect(self::BACKOFFICE_LOGIN)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('login_role_notice', ['account' => 'student', 'url' => self::STUDENT_LOGIN]);

        $this->assertGuest();

        $this->get(self::BACKOFFICE_LOGIN)
            ->assertOk()
            ->assertViewIs('auth.login')
            ->assertSee('lf-login-notice', false)
            ->assertSee(__('lf.LF_auth_login_student_account_wrong_page_title'))
            ->assertSee(__('lf.LF_auth_login_go_student_login'))
            ->assertSee('href="'.self::STUDENT_LOGIN.'"', false)
            ->assertSee('value="'.$student->email.'"', false);
    }

    public function test_admin_and_teacher_are_rejected_on_student_login_and_pointed_to_backoffice_login(): void
    {
        $customerId = $this->createTenant('tenant-a');

        foreach (['customer_admin', 'teacher'] as $role) {
            $user = $this->createUser($customerId, $role);

            $this->from(self::STUDENT_LOGIN)->post(self::STUDENT_LOGIN, [
                'email' => $user->email,
                'password' => 'password123',
            ])
                ->assertRedirect(self::STUDENT_LOGIN)
                ->assertSessionHasNoErrors()
                ->assertSessionHas('login_role_notice', ['account' => 'backoffice', 'url' => self::BACKOFFICE_LOGIN]);

            $this->assertGuest();

            $this->get(self::STUDENT_LOGIN)
                ->assertOk()
                ->assertViewIs('auth.student-login')
                ->assertSee('student-login-notice', false)
                ->assertSee(__('lf.LF_auth_login_backoffice_account_wrong_page_title'))
                ->assertSee(__('lf.LF_auth_login_go_backoffice_login'))
                ->assertSee('href="'.self::BACKOFFICE_LOGIN.'"', false);
        }
    }

    public function test_wrong_password_does_not_reveal_role_mismatch(): void
    {
        $customerId = $this->createTenant('tenant-a');
        $student = $this->createUser($customerId, 'student');

        $this->from(self::BACKOFFICE_LOGIN)->post(self::BACKOFFICE_LOGIN, [
            'email' => $student->email,
            'password' => 'wrong-password',
        ])
            ->assertRedirect(self::BACKOFFICE_LOGIN)
            ->assertSessionHasErrors(['email' => trans('auth.failed')])
            ->assertSessionMissing('login_role_notice');

        $this->assertGuest();
    }

    public function test_both_login_variants_keep_tenant_isolation(): void
    {
        $tenantA = $this->createTenant('tenant-a');
        $tenantB = $this->createTenant('tenant-b');
        $studentB = $this->createUser($tenantB, 'student', 'student-b@example.test');
        $adminB = $this->createUser($tenantB, 'customer_admin', 'admin-b@example.test');
        $this->createUser($tenantA, 'student');

        foreach ([[self::STUDENT_LOGIN, $studentB], [self::BACKOFFICE_LOGIN, $adminB]] as [$url, $user]) {
            $this->from($url)->post($url, [
                'email' => $user->email,
                'password' => 'password123',
            ])
                ->assertRedirect($url)
                ->assertSessionHasErrors(['email' => trans('auth.failed')])
                ->assertSessionMissing('login_role_notice');

            $this->assertGuest();
        }

        $this->post('https://tenant-b.localhost/login?experience=student', [
            'email' => $studentB->email,
            'password' => 'password123',
        ])->assertRedirect('https://tenant-b.localhost');
        $this->assertAuthenticatedAs($studentB);
    }

    public function test_guest_is_sent_to_matching_login_variant(): void
    {
        $this->createTenant('tenant-a');

        $this->get('https://tenant-a.localhost/my-courses')->assertRedirect(self::STUDENT_LOGIN);
        $this->get('https://tenant-a.localhost/profile')->assertRedirect(self::STUDENT_LOGIN);
        $this->get('https://tenant-a.localhost/admin')->assertRedirect(self::BACKOFFICE_LOGIN);
        $this->get('https://tenant-a.localhost/teacher')->assertRedirect(self::BACKOFFICE_LOGIN);
    }

    private function createTenant(string $subdomain): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => ucfirst($subdomain),
            'slug' => $subdomain,
            'subdomain' => $subdomain,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(int $customerId, string $role, ?string $email = null): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $email ?? $role.'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
