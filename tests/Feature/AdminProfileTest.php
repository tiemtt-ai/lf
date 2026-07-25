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
            ->assertSeeText(__('lf.LF_profile_title_common_information'))
            ->assertSeeText('admin@example.test')
            ->assertDontSee('name="email"', false)
            ->assertSeeText(__('lf.LF_common_button_common_change_password'))
            ->assertSee('open-modal')
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('class="lf-modal-card admin-password-modal"', false)
            ->assertSee('class="admin-password-modal-header"', false)
            ->assertSee(__('lf.LF_profile_placeholder_current_password'))
            ->assertSee(__('lf.LF_profile_placeholder_new_password'))
            ->assertSee(__('lf.LF_profile_placeholder_confirm_new_password'))
            ->assertSee(__('lf.LF_profile_help_new_password'))
            ->assertSeeText(__('lf.LF_navigation_menu_admin_organization'))
            ->assertSeeText(__('lf.LF_navigation_menu_admin_users'))
            ->assertSeeText(__('lf.LF_navigation_menu_admin_my_account'))
            ->assertSee('href="https://tenant-a.localhost/admin/organization"', false)
            ->assertSee('href="https://tenant-a.localhost/admin/users"', false)
            ->assertSee('href="https://tenant-a.localhost/admin/my-account"', false)
            ->assertSee('class="admin-account-dropdown-links"', false)
            ->assertDontSeeText(__('lf.LF_navigation_group_admin_account_organization'))
            ->assertDontSee('admin-sidebar-link-child is-active', false);

        $response->assertSee('class="admin-form-standard"', false)
            ->assertSee('class="admin-form-flow"', false)
            ->assertSee('aria-labelledby="admin-account-information"', false)
            ->assertSee('aria-labelledby="admin-personal-information"', false)
            ->assertSee('aria-labelledby="admin-account-security"', false)
            ->assertSee('admin-form-field-grid--three', false)
            ->assertSee('admin-form-readonly', false)
            ->assertSee('class="admin-form-footer"', false)
            ->assertDontSee('class="admin-form-grid"', false);

        $content = $response->getContent();

        $modalContent = substr($content, strpos($content, 'admin-password-modal'));
        $this->assertSame(3, substr_count(
            $modalContent,
            'class="lf-required-indicator"'
        ));
        $this->assertSame(3, substr_count(
            $modalContent,
            'aria-required="true"'
        ));
        $this->assertSame(6, substr_count(
            $modalContent,
            'class="admin-password-eye"'
        ));

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

    public function test_admin_organization_uses_the_shared_adaptive_form_contract(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/organization')
            ->assertOk()
            ->assertSee('class="admin-card admin-form-card admin-form-surface"', false)
            ->assertSee('id="organization-update-form"', false)
            ->assertSee('class="admin-form-standard"', false)
            ->assertSee('class="admin-form-flow"', false)
            ->assertSee('admin-form-field-grid--three', false)
            ->assertSee('admin-profile-summary--three admin-readonly-summary admin-readonly-summary--standalone', false)
            ->assertSee('aria-labelledby="organization-contact-title"', false)
            ->assertSee('aria-labelledby="organization-system-title"', false)
            ->assertSee('placeholder="'.__('lf.LF_admin_placeholder_organization_name').'"', false)
            ->assertSee('placeholder="'.__('lf.LF_admin_placeholder_organization_email').'"', false)
            ->assertSee('placeholder="'.__('lf.LF_admin_placeholder_organization_phone').'"', false)
            ->assertSee('class="admin-form-footer"', false)
            ->assertSee('class="admin-form-footer-primary"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="phone"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('value="PATCH"', false)
            ->assertDontSee('backend-form-columns', false)
            ->assertDontSee('admin-form-actions', false);

        $content = $response->getContent();
        $this->assertLessThan(strpos($content, 'name="email"'), strpos($content, 'name="name"'));
        $this->assertLessThan(strpos($content, 'name="phone"'), strpos($content, 'name="email"'));

        $view = file_get_contents(resource_path('views/admin/organization/edit.blade.php'));
        $this->assertStringNotContainsString('style=', $view);
        $this->assertStringNotContainsString('<style', $view);

        $css = file_get_contents(resource_path('css/admin/admin-components.css'));
        $this->assertStringContainsString(
            ':root.is-backend-sidebar-collapsed .backend-shell .admin-form-field-grid',
            $css
        );
        $this->assertStringContainsString(
            'grid-template-columns: repeat(2, minmax(0, 1fr));',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 900px\).*?\.admin-form-field-grid.*?grid-template-columns: minmax\(0, 1fr\);/s',
            $css
        );
        $this->assertStringContainsString('@media (max-width: 575.98px)', $css);
    }

    public function test_admin_organization_keeps_field_errors_adjacent_and_accessible(): void
    {
        $admin = $this->createAdmin();

        $invalid = $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/organization')
            ->patch('https://tenant-a.localhost/admin/organization', [
                'name' => '',
                'email' => 'not-an-email',
                'phone' => str_repeat('1', 31),
            ])
            ->assertSessionHasErrors(['name', 'email', 'phone']);

        $this->followRedirects($invalid)
            ->assertOk()
            ->assertSee('id="organization_name_error" class="lf-form-error"', false)
            ->assertSee('aria-invalid="true" aria-describedby="organization_name_error"', false)
            ->assertSee('id="organization_email_error" class="lf-form-error"', false)
            ->assertSee('aria-invalid="true" aria-describedby="organization_email_error"', false)
            ->assertSee('id="organization_phone_error" class="lf-form-error"', false)
            ->assertSee('aria-invalid="true" aria-describedby="organization_phone_error"', false);
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
