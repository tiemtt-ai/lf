<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProfileTest extends TestCase
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

    public function test_admin_profile_uses_password_modal_and_can_change_own_password(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/my-account')
            ->assertOk()
            ->assertSeeText(__('lf.LF_profile_title_common_information'))
            ->assertSeeText('admin@example.test')
            ->assertDontSee('name="email"', false)
            ->assertSeeText(__('lf.LF_common_button_common_change_password'))
            ->assertSee('open-modal')
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false);

        $content = $response->getContent();

        $this->assertLessThan(
            strpos($content, 'name="password"'),
            strpos($content, 'name="current_password"')
        );
        $this->assertLessThan(
            strpos($content, 'name="password_confirmation"'),
            strpos($content, 'name="password"')
        );

        $this->actingAs($admin)
            ->patch('https://tenant-a.localhost/admin/my-account/password', [
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/my-account');

        $this->assertTrue(Hash::check('new-password-456', $admin->fresh()->password));
    }

    public function test_admin_password_change_rejects_wrong_current_password_mismatch_and_reuse(): void
    {
        $admin = $this->createAdmin();

        $wrongPasswordResponse = $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/my-account')
            ->patch('https://tenant-a.localhost/admin/my-account/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertSessionHasErrors('current_password', null, 'updatePassword');

        $this->followRedirects($wrongPasswordResponse)
            ->assertOk()
            ->assertSee('style="display: block;"', false);

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/my-account')
            ->patch('https://tenant-a.localhost/admin/my-account/password', [
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'different-password',
            ])
            ->assertSessionHasErrors('password', null, 'updatePassword');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/my-account')
            ->patch('https://tenant-a.localhost/admin/my-account/password', [
                'current_password' => 'password123',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertSessionHasErrors('password', null, 'updatePassword');

        $this->assertTrue(Hash::check('password123', $admin->fresh()->password));
    }

    public function test_admin_can_update_only_own_my_account_profile(): void
    {
        $admin = $this->createAdmin();
        $sameTenantUser = User::forceCreate([
            'customer_id' => $admin->customer_id,
            'name' => 'Same Tenant Teacher',
            'email' => 'teacher@example.test',
            'password' => Hash::make('password123'),
            'role' => 'teacher',
            'status' => 'active',
            'phone' => '0101010101',
            'email_verified_at' => now(),
        ]);
        $otherCustomerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'subdomain' => 'tenant-b',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $crossTenantUser = User::forceCreate([
            'customer_id' => $otherCustomerId,
            'name' => 'Cross Tenant Admin',
            'email' => 'cross-admin@example.test',
            'password' => Hash::make('password123'),
            'role' => 'customer_admin',
            'status' => 'active',
            'phone' => '0202020202',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patch('https://tenant-a.localhost/admin/my-account', [
                'id' => $sameTenantUser->id,
                'customer_id' => $otherCustomerId,
                'name' => 'Updated Admin',
                'email' => 'changed@example.test',
                'phone' => '0900000000',
                'date_of_birth' => '1990-01-02',
                'gender' => 'female',
                'role' => 'student',
                'status' => 'inactive',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/my-account');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'customer_id' => $admin->customer_id,
            'name' => 'Updated Admin',
            'email' => 'admin@example.test',
            'phone' => '0900000000',
            'date_of_birth' => '1990-01-02',
            'gender' => 'female',
            'role' => 'customer_admin',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $sameTenantUser->id,
            'customer_id' => $admin->customer_id,
            'name' => 'Same Tenant Teacher',
            'email' => 'teacher@example.test',
            'phone' => '0101010101',
            'role' => 'teacher',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $crossTenantUser->id,
            'customer_id' => $otherCustomerId,
            'name' => 'Cross Tenant Admin',
            'email' => 'cross-admin@example.test',
            'phone' => '0202020202',
            'role' => 'customer_admin',
            'status' => 'active',
        ]);
    }

    public function test_admin_organization_updates_only_tenant_information(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->patch('https://tenant-a.localhost/admin/organization', [
                'name' => 'Tenant A Academy',
                'email' => 'academy@example.test',
                'phone' => '0900000000',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/organization');

        $this->assertDatabaseHas('saas_customers', [
            'id' => $admin->customer_id,
            'name' => 'Tenant A Academy',
            'email' => 'academy@example.test',
            'phone' => '0900000000',
            'slug' => 'tenant-a',
            'subdomain' => 'tenant-a',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'customer_id' => $admin->customer_id,
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'role' => 'customer_admin',
        ]);
    }

    private function createAdmin(): User
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'subdomain' => 'tenant-a',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password123'),
            'role' => 'customer_admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
