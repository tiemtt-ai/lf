<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The dashboard edit actions open UI Kit popups: GET (XHR) returns a form fragment, PATCH (JSON) saves and sends the user back with a toast. */
class DashboardEditPopupTest extends TestCase
{
    use RefreshDatabase;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://localhost', 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);
    }

    public function test_dashboards_open_the_edit_forms_as_popups(): void
    {
        [$admin, $teacher] = $this->people();

        $this->actingAs($admin)->get('https://tenant-a.localhost/admin')->assertOk()
            ->assertSee('data-ui-dialog-form-scope', false)
            ->assertSee('data-url="https://tenant-a.localhost/admin/organization"', false)
            ->assertSee('data-url="https://tenant-a.localhost/admin/my-account"', false)
            ->assertSee('data-ui-dialog-form', false);

        $this->actingAs($teacher)->get('https://tenant-a.localhost/teacher')->assertOk()
            ->assertSee('data-ui-dialog-form-scope', false)
            ->assertSee('data-url="https://tenant-a.localhost/teacher/profile"', false)
            ->assertDontSee('data-url="https://tenant-a.localhost/admin/organization"', false);
    }

    public function test_the_edit_urls_answer_a_popup_fragment_to_xhr_and_the_full_page_otherwise(): void
    {
        [$admin, $teacher] = $this->people();

        foreach ([[$admin, '/admin/organization', 'organization-form-name'], [$admin, '/admin/my-account', 'profile-form-name'], [$teacher, '/teacher/profile', 'profile-form-name']] as [$user, $path, $field]) {
            $this->actingAs($user)->get('https://tenant-a.localhost'.$path)->assertOk()->assertSee('<html', false);

            $fragment = $this->actingAs($user)->withHeaders(self::AJAX)->get('https://tenant-a.localhost'.$path)->assertOk();
            $fragment->assertSee('class="ui-modal__form"', false)->assertSee('id="'.$field.'"', false)
                ->assertSee('data-ui-dialog-form-submit', false)->assertDontSee('<html', false);
            $this->flushHeaders();
        }

        // Admins sign in with their email: it is shown but never sent; teachers may change it.
        $this->actingAs($admin)->withHeaders(self::AJAX)->get('https://tenant-a.localhost/admin/my-account')
            ->assertDontSee('name="email"', false);
        $this->actingAs($teacher)->withHeaders(self::AJAX)->get('https://tenant-a.localhost/teacher/profile')
            ->assertSee('name="email"', false);
    }

    public function test_saving_returns_json_with_a_redirect_to_the_dashboard_and_flashes_the_toast(): void
    {
        [$admin, $teacher] = $this->people();

        $this->actingAs($admin)->patchJson('https://tenant-a.localhost/admin/organization', ['name' => 'Academy', 'email' => 'a@example.test', 'phone' => '0900'])
            ->assertOk()->assertJson(['message' => __('lf.LF_admin_message_organization_updated'), 'redirect' => 'https://tenant-a.localhost/admin']);
        $this->assertDatabaseHas('saas_customers', ['id' => $admin->customer_id, 'name' => 'Academy', 'phone' => '0900']);
        $this->get('https://tenant-a.localhost/admin')->assertSeeText(__('lf.LF_admin_message_organization_updated'));

        $this->actingAs($admin)->patchJson('https://tenant-a.localhost/admin/my-account', ['name' => 'New Admin', 'phone' => '0911', 'gender' => 'female', 'date_of_birth' => '1990-05-04', 'email' => 'hacker@example.test'])
            ->assertOk()->assertJson(['redirect' => 'https://tenant-a.localhost/admin']);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'name' => 'New Admin', 'gender' => 'female', 'email' => 'admin@example.test']);

        $this->actingAs($teacher)->patchJson('https://tenant-a.localhost/teacher/profile', ['name' => 'New Teacher', 'email' => 'teacher2@example.test', 'phone' => '0922'])
            ->assertOk()->assertJson(['redirect' => 'https://tenant-a.localhost/teacher']);
        $this->assertDatabaseHas('users', ['id' => $teacher->id, 'name' => 'New Teacher', 'email' => 'teacher2@example.test']);
    }

    public function test_validation_failures_come_back_as_field_errors_without_changing_data(): void
    {
        [$admin, $teacher] = $this->people();

        $this->actingAs($admin)->patchJson('https://tenant-a.localhost/admin/organization', ['name' => '', 'email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'email']);
        $this->actingAs($teacher)->patchJson('https://tenant-a.localhost/teacher/profile', ['name' => 'X', 'email' => 'admin@example.test'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertDatabaseHas('saas_customers', ['id' => $admin->customer_id, 'name' => 'Tenant A']);
    }

    /** @return array{0: User, 1: User} */
    private function people(): array
    {
        $customerId = DB::table('saas_customers')->insertGetId(['name' => 'Tenant A', 'slug' => 'tenant-a', 'subdomain' => 'tenant-a', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $make = fn (string $role, string $email) => User::forceCreate(['customer_id' => $customerId, 'name' => ucfirst($role), 'email' => $email, 'password' => Hash::make('password123'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now()]);

        return [$make('customer_admin', 'admin@example.test'), $make('teacher', 'teacher@example.test')];
    }

    public function test_the_password_popup_loads_for_both_roles_and_signs_the_user_out_after_a_change(): void
    {
        [$admin, $teacher] = $this->people();

        foreach ([[$admin, '/admin/my-account/password'], [$teacher, '/teacher/profile/password']] as [$user, $path]) {
            $this->actingAs($user)->withHeaders(self::AJAX)->get('https://tenant-a.localhost'.$path)->assertOk()
                ->assertSee('class="ui-modal__form"', false)->assertSee('name="current_password"', false);
            $this->flushHeaders();
            $this->actingAs($user)->get('https://tenant-a.localhost'.$path)->assertNotFound();
        }

        $this->actingAs($teacher)->patchJson('https://tenant-a.localhost/teacher/profile/password', ['current_password' => 'nope', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456'])
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);

        $this->actingAs($teacher)->patchJson('https://tenant-a.localhost/teacher/profile/password', ['current_password' => 'password123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456'])
            ->assertOk()->assertJson(['redirect' => 'https://tenant-a.localhost/login', 'message' => __('lf.LF_auth_message_password_changed_login_again')]);
        $this->assertGuest();
        $this->assertTrue(Hash::check('new-password-456', $teacher->fresh()->password));
    }

    public function test_the_account_pages_return_to_themselves_after_a_popup_save(): void
    {
        [$admin, $teacher] = $this->people();

        $this->actingAs($admin)->patchJson('https://tenant-a.localhost/admin/my-account', ['name' => 'Admin', 'return' => 'page'])
            ->assertOk()->assertJson(['redirect' => 'https://tenant-a.localhost/admin/my-account']);
        $this->actingAs($teacher)->patchJson('https://tenant-a.localhost/teacher/profile', ['name' => 'Teacher', 'email' => 'teacher@example.test', 'return' => 'page'])
            ->assertOk()->assertJson(['redirect' => 'https://tenant-a.localhost/teacher/profile']);
    }
}
