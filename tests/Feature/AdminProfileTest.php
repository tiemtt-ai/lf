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
        $otherAdmin = User::forceCreate([
            'customer_id' => $admin->customer_id,
            'name' => 'Other Admin',
            'email' => 'other-admin@example.test',
            'password' => Hash::make('password123'),
            'role' => 'customer_admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/my-account')
            ->assertOk()
            ->assertSeeText('admin@example.test')
            ->assertDontSee('name="email"', false)
            ->assertSeeText(__('lf.LF_common_button_common_change_password'))
            ->assertSeeText(__('lf.LF_navigation_menu_admin_organization'))
            ->assertSeeText(__('lf.LF_navigation_menu_admin_users'))
            ->assertSeeText(__('lf.LF_navigation_menu_admin_my_account'))
            ->assertSee('href="https://tenant-a.localhost/admin/organization"', false)
            ->assertSee('href="https://tenant-a.localhost/admin/users"', false)
            ->assertSee('href="https://tenant-a.localhost/admin/my-account"', false)
            ->assertSee('id="top-account"', false)
            ->assertDontSeeText(__('lf.LF_navigation_group_admin_account_organization'))
            ->assertDontSee('admin-sidebar-link-child is-active', false);

        $response->assertSee('class="ui-account-card ui-account-card--main"', false)
            ->assertSee('data-ui-dialog-form-scope', false)
            // Personal information is edited in place on the page and comes back to it after saving.
            ->assertSee('action="https://tenant-a.localhost/admin/my-account"', false)
            ->assertSee('name="return" value="page"', false)
            ->assertSee('data-url="https://tenant-a.localhost/admin/my-account/password"', false)
            ->assertSeeText(__('lf.LF_profile_group_admin_personal'))
            ->assertSeeText(__('lf.LF_profile_group_admin_security'))
            ->assertDontSee('name="email"', false)
            ->assertDontSee('name="current_password"', false);

        // The change-password popup is a UI Kit form fragment loaded on demand: current, new, confirmation, in that order.
        $fragment = $this->actingAs($admin)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get('https://tenant-a.localhost/admin/my-account/password')->assertOk()
            ->assertSee('class="ui-modal__form"', false)->assertDontSee('<html', false)->getContent();
        $this->flushHeaders();
        $this->assertSame(3, substr_count($fragment, 'class="ui-field__required"'));
        $this->assertLessThan(strpos($fragment, 'name="password"'), strpos($fragment, 'name="current_password"'));
        $this->assertLessThan(strpos($fragment, 'name="password_confirmation"'), strpos($fragment, 'name="password"'));
        $this->actingAs($admin)->get('https://tenant-a.localhost/admin/my-account/password')->assertNotFound();

        $this->actingAs($admin)
            ->patch('https://tenant-a.localhost/admin/my-account/password', [
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertRedirect('https://tenant-a.localhost/login')
            ->assertSessionHas('status', __('lf.LF_auth_message_password_changed_login_again'));

        $this->assertGuest();
        $this->assertTrue(Hash::check('new-password-456', $admin->fresh()->password));
        $this->assertTrue(Hash::check('password123', $otherAdmin->fresh()->password));

        $this->post('https://tenant-a.localhost/login', [
            'email' => 'admin@example.test',
            'password' => 'password123',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('https://tenant-a.localhost/login', [
            'email' => 'admin@example.test',
            'password' => 'new-password-456',
        ])->assertRedirect('https://tenant-a.localhost/admin');
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_admin_password_change_rejects_wrong_current_password_mismatch_and_reuse(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/my-account')
            ->patch('https://tenant-a.localhost/admin/my-account/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertSessionHasErrors('current_password', null, 'updatePassword');

        // The popup posts JSON and gets the field errors back with 422.
        $this->actingAs($admin)->patchJson('https://tenant-a.localhost/admin/my-account/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertStatus(422)->assertJsonValidationErrors(['current_password']);

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

    public function test_admin_organization_page_is_a_summary_with_a_popup_edit_form(): void
    {
        $admin = $this->createAdmin();
        DB::table('saas_customers')->where('id', $admin->customer_id)->update(['email' => 'org@example.test', 'phone' => '0900111222']);

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/organization')
            ->assertOk()
            ->assertSee('data-ui-dialog-form-scope', false)
            ->assertSee('data-url="https://tenant-a.localhost/admin/organization?return=page"', false)
            ->assertSee('aria-labelledby="organization-contact-title"', false)
            ->assertSee('aria-labelledby="organization-system-title"', false)
            ->assertSeeText('org@example.test')
            ->assertSeeText('0900111222')
            ->assertSeeText(__('lf.LF_common_status_common_active'))
            ->assertDontSee('id="organization-update-form"', false)
            ->assertDontSee('name="phone"', false);

        $fragment = $this->actingAs($admin)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get('https://tenant-a.localhost/admin/organization?return=page')->assertOk();
        $fragment->assertSee('class="ui-modal__form"', false)
            ->assertSee('placeholder="'.__('lf.LF_admin_placeholder_organization_name').'"', false)
            ->assertSee('name="return" value="page"', false);
        $content = $fragment->getContent();
        $this->assertLessThan(strpos($content, 'name="email"'), strpos($content, 'name="name"'));
        $this->assertLessThan(strpos($content, 'name="phone"'), strpos($content, 'name="email"'));
    }

    public function test_admin_organization_popup_returns_field_errors_and_the_page_it_came_from(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->patchJson('https://tenant-a.localhost/admin/organization', [
            'name' => '',
            'email' => 'not-an-email',
            'phone' => str_repeat('1', 31),
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'phone']);

        $this->actingAs($admin)->patchJson('https://tenant-a.localhost/admin/organization', ['name' => 'Academy', 'return' => 'page'])
            ->assertOk()->assertJson(['redirect' => 'https://tenant-a.localhost/admin/organization']);
        $this->actingAs($admin)->patchJson('https://tenant-a.localhost/admin/organization', ['name' => 'Academy 2'])
            ->assertOk()->assertJson(['redirect' => 'https://tenant-a.localhost/admin']);
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
