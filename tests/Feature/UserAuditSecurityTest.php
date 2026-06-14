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
            ->assertRedirect('https://tenant-a.localhost/admin/users/'.$target->id.'/edit')
            ->assertSessionHas('success', 'User updated successfully.');

        $target->refresh();
        $this->assertSame('updated@example.test', $target->email);
        $this->assertSame('student', $target->role);
        $this->assertNull($target->email_verified_at);
        Notification::assertSentTo($target, VerifyEmail::class);

        $this->assertAudit($customerId, $admin->id, $target->id, 'user.updated');
        $this->assertAudit($customerId, $admin->id, $target->id, 'user.role_changed');
    }

    public function test_admin_can_reset_passwords_for_other_user_roles_without_current_password(): void
    {
        $customerId = $this->createTenant('tenant-a');
        $admin = $this->createUser($customerId, 'admin@example.test', 'customer_admin');

        foreach (['customer_admin', 'teacher', 'student'] as $role) {
            $target = $this->createUser($customerId, $role.'@example.test', $role);
            $newPassword = 'new-'.$role.'-password';

            $this->actingAs($admin)
                ->put('https://tenant-a.localhost/admin/users/'.$target->id, [
                    'password_reset' => '1',
                    'password' => $newPassword,
                    'password_confirmation' => $newPassword,
                ])
                ->assertRedirect('https://tenant-a.localhost/admin/users/'.$target->id.'/edit')
                ->assertSessionHas('success', 'Password updated successfully.');

            $this->assertTrue(Hash::check($newPassword, $target->fresh()->password));
        }
    }

    public function test_admin_must_confirm_current_password_when_editing_own_account(): void
    {
        $customerId = $this->createTenant('tenant-a');
        $admin = $this->createUser($customerId, 'admin@example.test', 'customer_admin');

        $response = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/users/'.$admin->id.'/edit')
            ->assertOk()
            ->assertSeeText(__('lf.LF_profile_button_admin_change_my_password'))
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false);

        $content = $response->getContent();

        $this->assertLessThan(
            strpos($content, 'name="password"'),
            strpos($content, 'name="current_password"')
        );

        $wrongPasswordResponse = $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/users/'.$admin->id.'/edit')
            ->put('https://tenant-a.localhost/admin/users/'.$admin->id, [
                'password_reset' => '1',
                'current_password' => 'wrong-password',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertSessionHasErrors('current_password', null, 'resetPassword');

        $this->followRedirects($wrongPasswordResponse)
            ->assertOk()
            ->assertSee('style="display: block;"', false);

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/users/'.$admin->id.'/edit')
            ->put('https://tenant-a.localhost/admin/users/'.$admin->id, [
                'password_reset' => '1',
                'current_password' => 'password123',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertSessionHasErrors('password', null, 'resetPassword');

        $this->actingAs($admin)
            ->put('https://tenant-a.localhost/admin/users/'.$admin->id, [
                'password_reset' => '1',
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/users/'.$admin->id.'/edit')
            ->assertSessionHas('success', 'Password updated successfully.');

        $this->assertTrue(Hash::check('new-password-456', $admin->fresh()->password));
    }

    public function test_admin_user_password_reset_requires_confirmation(): void
    {
        $customerId = $this->createTenant('tenant-a');
        $admin = $this->createUser($customerId, 'admin@example.test', 'customer_admin');
        $target = $this->createUser($customerId, 'teacher@example.test', 'teacher');
        $originalPassword = $target->password;

        $response = $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/users/'.$target->id.'/edit')
            ->put('https://tenant-a.localhost/admin/users/'.$target->id, [
                'password_reset' => '1',
                'password' => 'new-password-456',
                'password_confirmation' => 'different-password',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/users/'.$target->id.'/edit')
            ->assertSessionHasErrors('password', null, 'resetPassword');

        $this->assertSame($originalPassword, $target->fresh()->password);

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('style="display: block;"', false);
    }

    public function test_admin_user_password_modal_is_rendered_and_cross_tenant_target_is_rejected(): void
    {
        $customerId = $this->createTenant('tenant-a');
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'admin@example.test', 'customer_admin');
        $target = $this->createUser($customerId, 'teacher@example.test', 'teacher');
        $otherTarget = $this->createUser($otherCustomerId, 'other@example.test', 'student');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/users/'.$target->id.'/edit')
            ->assertOk()
            ->assertSeeText(__('lf.LF_profile_button_admin_change_user_password'))
            ->assertSee('change-user-password')
            ->assertSee('name="password_reset"', false)
            ->assertDontSee('name="current_password"', false)
            ->assertSee('name="password_confirmation"', false);

        $this->actingAs($admin)
            ->put('https://tenant-a.localhost/admin/users/'.$otherTarget->id, [
                'password_reset' => '1',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertNotFound();

        $this->assertTrue(Hash::check('password123', $otherTarget->fresh()->password));
    }

    public function test_create_and_status_actions_are_audited_and_shared_profile_deletion_is_unavailable(): void
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
            ->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $student->id]);
        $this->assertDatabaseMissing('saas_audit_logs', [
            'actor_id' => $student->id,
            'target_user_id' => $student->id,
            'action' => 'user.deleted',
        ]);
        $this->assertDatabaseMissing('saas_audit_logs', [
            'actor_id' => $student->id,
            'target_user_id' => $student->id,
            'action' => 'profile.deleted',
        ]);
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
