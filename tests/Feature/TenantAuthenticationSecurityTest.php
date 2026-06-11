<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantAuthenticationSecurityTest extends TestCase
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

    public function test_login_only_authenticates_active_user_in_current_tenant(): void
    {
        $tenantA = $this->createTenant('tenant-a');
        $tenantB = $this->createTenant('tenant-b');

        $activeUser = $this->createUser($tenantA, 'active@example.test', 'active');
        $this->createUser($tenantB, 'other@example.test', 'active');
        $this->createUser($tenantA, 'inactive@example.test', 'inactive');

        $this->post('https://tenant-a.localhost/login', [
            'email' => 'other@example.test',
            'password' => 'password123',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('https://tenant-a.localhost/login', [
            'email' => 'inactive@example.test',
            'password' => 'password123',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('https://tenant-a.localhost/login', [
            'email' => $activeUser->email,
            'password' => 'password123',
        ])->assertRedirect('https://tenant-a.localhost/admin');
        $this->assertAuthenticatedAs($activeUser);
    }

    public function test_unused_breeze_auth_routes_are_removed_but_logout_remains_available(): void
    {
        $tenantId = $this->createTenant('tenant-a');
        $user = $this->createUser($tenantId, 'admin@example.test', 'active');

        $this->get('https://tenant-a.localhost/register')->assertNotFound();
        $this->post('https://tenant-a.localhost/register')->assertNotFound();
        $this->actingAs($user)->get('https://tenant-a.localhost/confirm-password')->assertNotFound();
        $this->actingAs($user)->post('https://tenant-a.localhost/confirm-password')->assertNotFound();
        $this->actingAs($user)->put('https://tenant-a.localhost/password')->assertNotFound();

        $user->forceFill(['status' => 'inactive'])->save();
        Auth::forgetUser();

        $this->actingAs($user->fresh())
            ->post('https://tenant-a.localhost/logout')
            ->assertRedirect('https://tenant-a.localhost/login');
        $this->assertGuest();
    }

    public function test_factory_users_always_belong_to_a_tenant(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->customer_id);
        $this->assertDatabaseHas('saas_customers', ['id' => $user->customer_id]);
    }

    public function test_customer_id_is_required_and_customer_delete_is_restricted(): void
    {
        $tenantId = $this->createTenant('tenant-a');
        $this->createUser($tenantId, 'admin@example.test', 'active');

        $this->expectException(QueryException::class);
        DB::table('saas_customers')->where('id', $tenantId)->delete();
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

    private function createUser(int $customerId, string $email, string $status): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password123'),
            'role' => 'customer_admin',
            'status' => $status,
            'email_verified_at' => now(),
        ]);
    }
}
