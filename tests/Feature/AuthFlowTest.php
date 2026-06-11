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

    public function test_login_and_dashboard_redirect_each_role_to_its_portal(): void
    {
        $customerId = $this->createTenant();

        foreach ([
            'customer_admin' => '/admin',
            'teacher' => '/teacher',
            'student' => '/student',
        ] as $role => $portal) {
            $user = $this->createUser($customerId, $role, verified: true);

            $this->post('https://tenant-a.localhost/login', [
                'email' => $user->email,
                'password' => 'password123',
            ])->assertRedirect('https://tenant-a.localhost'.$portal);

            $this->get('https://tenant-a.localhost/dashboard')
                ->assertRedirect('https://tenant-a.localhost'.$portal);

            $this->post('https://tenant-a.localhost/logout')
                ->assertRedirect('https://tenant-a.localhost/login');
        }
    }

    public function test_password_reset_views_and_token_flow_work_for_current_tenant(): void
    {
        $customerId = $this->createTenant();
        $user = $this->createUser($customerId, 'teacher', verified: true);
        $token = Password::createToken($user);

        $this->get('https://tenant-a.localhost/forgot-password')->assertOk();

        $this->get('https://tenant-a.localhost/reset-password/'.$token.'?email='.urlencode($user->email))
            ->assertOk();

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

    private function createUser(int $customerId, string $role, bool $verified): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => $verified ? now() : null,
        ]);
    }
}
