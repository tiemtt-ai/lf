<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
            'mail.default' => 'array',
        ]);
    }

    public function test_login_and_dashboard_redirect_each_role_to_its_experience(): void
    {
        $customerId = $this->createTenant();

        foreach ([
            'customer_admin' => '/admin',
            'teacher' => '/teacher',
            'student' => '/',
        ] as $role => $destination) {
            $user = $this->createUser($customerId, $role, verified: true);
            $destinationUrl = $destination === '/'
                ? 'https://tenant-a.localhost'
                : 'https://tenant-a.localhost'.$destination;

            $this->post('https://tenant-a.localhost/login', [
                'email' => $user->email,
                'password' => 'password123',
            ])->assertRedirect($destinationUrl);

            $this->get('https://tenant-a.localhost/dashboard')
                ->assertRedirect($destinationUrl);

            $this->post('https://tenant-a.localhost/logout')
                ->assertRedirect('https://tenant-a.localhost/login');
        }
    }

    public function test_unknown_role_is_rejected_after_authentication(): void
    {
        $customerId = $this->createTenant();
        $user = $this->createUser($customerId, 'future_role', verified: true);

        $this->post('https://tenant-a.localhost/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertForbidden();

        $this->assertGuest();
    }

    public function test_admin_dashboard_shows_current_tenant_and_admin_information(): void
    {
        $customerId = $this->createTenant([
            'name' => 'Acme Academy',
            'slug' => 'acme',
            'subdomain' => 'acme',
            'organization_type' => 'school',
            'email' => 'hello@acme.test',
            'phone' => '0280000000',
            'status' => 'active',
        ]);
        $admin = $this->createUser($customerId, 'customer_admin', verified: true, overrides: [
            'name' => 'Acme Admin',
            'email' => 'admin@acme.test',
            'phone' => '0900000000',
        ]);

        $this->actingAs($admin)
            ->get('https://acme.localhost/admin')
            ->assertOk()
            ->assertSeeText('Thông tin tenant')
            ->assertSeeText('Acme Academy')
            ->assertSeeText('acme')
            ->assertSeeText('hello@acme.test')
            ->assertSeeText('0280000000')
            ->assertSeeText(__('lf.LF_common_status_common_active'))
            ->assertSeeText('Người dùng hiện tại')
            ->assertSeeText('Acme Admin')
            ->assertSeeText('admin@acme.test')
            ->assertSeeText('0900000000')
            ->assertSeeText(__('lf.LF_common_role_admin_customer_admin'))
            ->assertDontSeeText('Customer ID');
    }

    public function test_password_reset_views_and_token_flow_work_for_current_tenant(): void
    {
        $customerId = $this->createTenant();
        $user = $this->createUser($customerId, 'teacher', verified: true);
        $token = Password::createToken($user);

        $this->get('https://tenant-a.localhost/forgot-password')->assertOk();

        $this->get('https://tenant-a.localhost/reset-password/'.$token.'?email='.urlencode($user->email))
            ->assertOk()
            ->assertSee('class="auth-login-card auth-forgot-card"', false)
            ->assertSee('class="auth-input auth-password-input"', false)
            ->assertSee('class="auth-password-toggle"', false)
            ->assertSee('class="auth-field-help"', false)
            ->assertSeeText(__('lf.LF_auth_reset_title'));

        $authCss = file_get_contents(resource_path('css/auth/login.css'));
        $appJs = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString(
            '.auth-page .auth-input.is-lf-placeholder:not(:focus) {',
            $authCss
        );
        $this->assertStringContainsString(
            '.auth-page .auth-input.is-lf-placeholder:focus {',
            $authCss
        );
        $this->assertStringContainsString('.auth-page select.auth-input', $appJs);
        $this->assertStringContainsString(
            '.auth-page input.auth-input[type="date"]',
            $appJs
        );

        $this->post('https://tenant-a.localhost/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertRedirect('https://tenant-a.localhost/login');

        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));
    }

    public function test_email_verification_view_and_signed_link_work_for_current_tenant(): void
    {
        Notification::fake();

        $customerId = $this->createTenant();
        $user = $this->createUser($customerId, 'student', verified: false);

        $this->actingAs($user)
            ->get('https://tenant-a.localhost/verify-email')
            ->assertOk();

        $this->actingAs($user)
            ->post('https://tenant-a.localhost/email/verification-notification')
            ->assertRedirect();

        $verificationUrl = null;

        Notification::assertSentTo(
            $user,
            VerifyEmail::class,
            function (VerifyEmail $notification) use ($user, &$verificationUrl): bool {
                $verificationUrl = $notification->toMail($user)->actionUrl;

                return true;
            }
        );

        $this->actingAs($user)
            ->get($verificationUrl)
            ->assertRedirect('https://tenant-a.localhost/dashboard?verified=1');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    private function createTenant(array $overrides = []): int
    {
        return DB::table('saas_customers')->insertGetId(array_merge([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'subdomain' => 'tenant-a',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createUser(int $customerId, string $role, bool $verified, array $overrides = []): User
    {
        return User::forceCreate(array_merge([
            'customer_id' => $customerId,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => $verified ? now() : null,
        ], $overrides));
    }
}
