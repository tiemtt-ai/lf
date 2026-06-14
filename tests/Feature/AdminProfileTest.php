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
            ->get('https://tenant-a.localhost/admin/profile')
            ->assertOk()
            ->assertSeeText(__('lf.LF_profile_title_common_information'))
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
            ->patch('https://tenant-a.localhost/admin/profile/password', [
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/profile');

        $this->assertTrue(Hash::check('new-password-456', $admin->fresh()->password));
    }

    public function test_admin_password_change_rejects_wrong_current_password_mismatch_and_reuse(): void
    {
        $admin = $this->createAdmin();

        $wrongPasswordResponse = $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/profile')
            ->patch('https://tenant-a.localhost/admin/profile/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertSessionHasErrors('current_password', null, 'updatePassword');

        $this->followRedirects($wrongPasswordResponse)
            ->assertOk()
            ->assertSee('style="display: block;"', false);

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/profile')
            ->patch('https://tenant-a.localhost/admin/profile/password', [
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'different-password',
            ])
            ->assertSessionHasErrors('password', null, 'updatePassword');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/profile')
            ->patch('https://tenant-a.localhost/admin/profile/password', [
                'current_password' => 'password123',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertSessionHasErrors('password', null, 'updatePassword');

        $this->assertTrue(Hash::check('password123', $admin->fresh()->password));
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
