<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantRegistrationSecurityTest extends TestCase
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

    public function test_customer_registration_is_root_only_and_creates_unverified_admin(): void
    {
        $this->get('https://tenant.localhost/register-customer')->assertNotFound();

        $response = $this->post('https://localhost/register-customer', [
            'customer_name' => 'Acme Academy',
            'slug' => 'acme',
            'name' => 'Acme Admin',
            'email' => 'admin@acme.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect('https://acme.localhost/login');
        $this->assertGuest();

        $customer = DB::table('saas_customers')->where('slug', 'acme')->first();
        $user = User::where('email', 'admin@acme.test')->firstOrFail();

        $this->assertSame('active', $customer->status);
        $this->assertSame($customer->id, $user->customer_id);
        $this->assertNull($user->email_verified_at);
    }

    public function test_last_active_admin_cannot_be_demoted_or_disabled_and_shared_profile_deletion_is_unavailable(): void
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'Acme Academy',
            'slug' => 'acme',
            'subdomain' => 'acme',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::forceCreate([
            'customer_id' => $customerId,
            'name' => 'Acme Admin',
            'email' => 'admin@acme.test',
            'password' => Hash::make('password123'),
            'role' => 'customer_admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->put('https://acme.localhost/admin/users/'.$admin->id, [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => 'teacher',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame('customer_admin', $admin->fresh()->role);

        $this->actingAs($admin)
            ->post('https://acme.localhost/admin/users/'.$admin->id.'/toggle-status')
            ->assertSessionHasErrors('status');

        $this->assertSame('active', $admin->fresh()->status);

        $this->actingAs($admin)
            ->delete('https://acme.localhost/profile', ['password' => 'password123'])
            ->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
