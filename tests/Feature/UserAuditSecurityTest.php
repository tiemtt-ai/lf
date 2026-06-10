<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserAuditSecurityTest extends TestCase
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

    public function test_password_reset_throttle_is_tenant_email_and_ip_aware(): void
    {
        $this->createTenant('tenant-a');
        $this->createTenant('tenant-b');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->post('https://tenant-a.localhost/forgot-password', [
                    'email' => 'missing@example.test',
                ])
                ->assertRedirect();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('https://tenant-a.localhost/forgot-password', [
                'email' => 'missing@example.test',
            ])
            ->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('https://tenant-b.localhost/forgot-password', [
                'email' => 'missing@example.test',
            ])
            ->assertRedirect();
    }

    public function test_admin_update_requires_unique_email_and_reverifies_changed_email(): void
    {
        Notification::fake();

        $customerId = $this->createTenant('tenant-a');
        $admin = $this->createUser($customerId, 'admin@example.test', 'customer_admin');
        $target = $this->createUser($customerId, 'teacher@example.test', 'teacher');
        $other = $this->createUser($customerId, 'other@example.test', 'student');

        $this->actingAs($admin)
            ->put('https://tenant-a.localhost/admin/users/'.$target->id, [
                'name' => 'Updated Teacher',
                'email' => $other->email,
                'role' => 'student',
            ])
            ->assertSessionHasErrors('email');

        $this->actingAs($admin)
            ->put('https://tenant-a.localhost/admin/users/'.$target->id, [
                'name' => 'Updated Teacher',
                'email' => 'updated@example.test',
                'role' => 'student',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/users');

        $target->refresh();
        $this->assertSame('updated@example.test', $target->email);
        $this->assertSame('student', $target->role);
        $this->assertNull($target->email_verified_at);
        Notification::assertSentTo($target, VerifyEmail::class);

        $this->assertAudit($customerId, $admin->id, $target->id, 'user.updated');
        $this->assertAudit($customerId, $admin->id, $target->id, 'user.role_changed');
    }

    public function test_create_status_and_profile_delete_actions_are_audited_without_secrets(): void
    {
        $customerId = $this->createTenant('tenant-a');
        $admin = $this->createUser($customerId, 'admin@example.test', 'customer_admin');

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/users', [
                'name' => 'New Student',
                'email' => 'student@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => 'student',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/users');

        $student = User::where('email', 'student@example.test')->firstOrFail();

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/users/'.$student->id.'/toggle-status')
            ->assertRedirect();

        $this->assertAudit($customerId, $admin->id, $student->id, 'user.created');
        $this->assertAudit($customerId, $admin->id, $student->id, 'user.status_toggled');

        $auditPayload = DB::table('saas_audit_logs')
            ->where('target_user_id', $student->id)
            ->pluck('after')
            ->implode(' ');
        $this->assertStringNotContainsString('password123', $auditPayload);
        $this->assertStringNotContainsString('remember_token', $auditPayload);

        $student->forceFill(['status' => 'active'])->save();

        $this->actingAs($student)
            ->delete('https://tenant-a.localhost/profile', ['password' => 'password123'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertAudit($customerId, $student->id, $student->id, 'user.deleted');
        $this->assertAudit($customerId, $student->id, $student->id, 'profile.deleted');
    }

    public function test_tenant_test_route_is_removed(): void
    {
        $this->createTenant('tenant-a');

        $this->get('https://tenant-a.localhost/tenant-test')->assertNotFound();
    }

    private function assertAudit(
        int $customerId,
        int $actorId,
        int $targetUserId,
        string $action
    ): void {
        $this->assertDatabaseHas('saas_audit_logs', [
            'customer_id' => $customerId,
            'actor_id' => $actorId,
            'target_user_id' => $targetUserId,
            'action' => $action,
        ]);
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
