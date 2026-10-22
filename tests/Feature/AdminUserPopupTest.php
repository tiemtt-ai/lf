<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The users list adds, edits and resets passwords in UI Kit popups for admins, teachers and students. */
class AdminUserPopupTest extends TestCase
{
    use RefreshDatabase;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://localhost', 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);
    }

    public function test_add_and_edit_forms_open_as_popups_for_every_role_and_stay_full_pages_otherwise(): void
    {
        [$admin, $users] = $this->setup3();

        foreach (['customer_admin', 'teacher', 'student'] as $role) {
            $this->actingAs($admin)->get('https://tenant-a.localhost/admin/users/create?role='.$role)->assertOk()->assertSee('<html', false);
            $add = $this->actingAs($admin)->withHeaders(self::AJAX)->get('https://tenant-a.localhost/admin/users/create?role='.$role)->assertOk();
            $add->assertSee('class="ui-modal__form"', false)->assertSee('name="password_confirmation"', false)->assertDontSee('<html', false)
                ->assertSee(__('lf.LF_admin_user_create_'.$role));
            $this->assertMatchesRegularExpression('/<option value="'.$role.'"\s+selected/', $add->getContent());
            $this->flushHeaders();

            $user = $users[$role];
            $this->actingAs($admin)->get('https://tenant-a.localhost/admin/users/'.$user->id.'/edit')->assertOk()->assertSee('<html', false);
            $edit = $this->actingAs($admin)->withHeaders(self::AJAX)->get('https://tenant-a.localhost/admin/users/'.$user->id.'/edit')->assertOk();
            $edit->assertSee('class="ui-modal__form"', false)->assertSee('value="'.$user->email.'"', false)->assertDontSee('name="password"', false)
                ->assertSee('name="_method" value="PUT"', false);
            $this->flushHeaders();
        }
    }

    public function test_adding_a_user_through_the_popup_returns_json_and_lands_on_that_role_tab(): void
    {
        [$admin] = $this->setup3();

        $this->actingAs($admin)->postJson('https://tenant-a.localhost/admin/users', [
            'name' => 'New Student', 'email' => 'new-student@example.test', 'phone' => '0900', 'gender' => 'female',
            'date_of_birth' => '2005-01-02', 'role' => 'student', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated()->assertJson(['message' => __('lf.LF_admin_user_message_created'), 'redirect' => 'https://tenant-a.localhost/admin/users?role=student']);

        $this->assertDatabaseHas('users', ['email' => 'new-student@example.test', 'role' => 'student', 'customer_id' => $admin->customer_id, 'status' => 'active']);
        $this->get('https://tenant-a.localhost/admin/users?role=student')->assertSeeText(__('lf.LF_admin_user_message_created'));

        $this->actingAs($admin)->postJson('https://tenant-a.localhost/admin/users', ['name' => '', 'email' => 'admin@example.test', 'role' => 'teacher', 'password' => 'a', 'password_confirmation' => 'b'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_editing_through_the_popup_saves_and_guards_the_last_admin(): void
    {
        [$admin, $users] = $this->setup3();
        $teacher = $users['teacher'];

        $this->actingAs($admin)->putJson('https://tenant-a.localhost/admin/users/'.$teacher->id, [
            'name' => 'Teacher Renamed', 'email' => $teacher->email, 'phone' => '0911', 'role' => 'student',
        ])->assertOk()->assertJson(['message' => __('lf.LF_admin_user_message_updated'), 'redirect' => 'https://tenant-a.localhost/admin/users?role=student']);
        $this->assertDatabaseHas('users', ['id' => $teacher->id, 'name' => 'Teacher Renamed', 'role' => 'student']);

        // Demoting the only active admin is refused as a field error on the role.
        $this->actingAs($admin)->putJson('https://tenant-a.localhost/admin/users/'.$admin->id, [
            'name' => 'Admin', 'email' => $admin->email, 'role' => 'teacher',
        ])->assertStatus(422)->assertJsonValidationErrors(['role']);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'customer_admin']);
    }

    public function test_password_popup_resets_another_users_password_and_signs_you_out_for_your_own(): void
    {
        [$admin, $users] = $this->setup3();
        $student = $users['student'];

        $other = $this->actingAs($admin)->withHeaders(self::AJAX)->get('https://tenant-a.localhost/admin/users/'.$student->id.'/password')->assertOk();
        $other->assertSee('class="ui-modal__form"', false)->assertSee('name="password_reset" value="1"', false)->assertDontSee('name="current_password"', false);
        $own = $this->actingAs($admin)->withHeaders(self::AJAX)->get('https://tenant-a.localhost/admin/users/'.$admin->id.'/password')->assertOk();
        $own->assertSee('name="current_password"', false);
        $this->flushHeaders();
        $this->actingAs($admin)->get('https://tenant-a.localhost/admin/users/'.$student->id.'/password')->assertNotFound();

        $this->actingAs($admin)->putJson('https://tenant-a.localhost/admin/users/'.$student->id, ['password_reset' => 1, 'password' => 'Brand-new-pass-77', 'password_confirmation' => 'Brand-new-pass-77'])
            ->assertOk()->assertJson(['message' => __('lf.LF_admin_user_message_password_reset'), 'redirect' => 'https://tenant-a.localhost/admin/users?role=student']);
        $this->assertTrue(Hash::check('Brand-new-pass-77', $student->fresh()->password));

        $this->actingAs($admin)->putJson('https://tenant-a.localhost/admin/users/'.$admin->id, ['password_reset' => 1, 'current_password' => 'wrong', 'password' => 'Another-new-pass-88', 'password_confirmation' => 'Another-new-pass-88'])
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->actingAs($admin)->putJson('https://tenant-a.localhost/admin/users/'.$admin->id, ['password_reset' => 1, 'current_password' => 'password123', 'password' => 'Another-new-pass-88', 'password_confirmation' => 'Another-new-pass-88'])
            ->assertOk()->assertJson(['redirect' => 'https://tenant-a.localhost/login']);
        $this->assertGuest();
    }

    public function test_another_tenants_users_cannot_be_opened_or_changed(): void
    {
        [$admin] = $this->setup3();
        $otherTenant = DB::table('saas_customers')->insertGetId(['name' => 'B', 'slug' => 'tenant-b', 'subdomain' => 'tenant-b', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $outsider = $this->make($otherTenant, 'teacher', 'outsider@example.test');

        $this->actingAs($admin)->withHeaders(self::AJAX)->get('https://tenant-a.localhost/admin/users/'.$outsider->id.'/edit')->assertNotFound();
        $this->actingAs($admin)->withHeaders(self::AJAX)->get('https://tenant-a.localhost/admin/users/'.$outsider->id.'/password')->assertNotFound();
        $this->actingAs($admin)->putJson('https://tenant-a.localhost/admin/users/'.$outsider->id, ['name' => 'X', 'email' => 'outsider@example.test', 'role' => 'teacher'])->assertNotFound();
    }

    /** @return array{0: User, 1: array<string, User>} */
    private function setup3(): array
    {
        $customerId = DB::table('saas_customers')->insertGetId(['name' => 'A', 'slug' => 'tenant-a', 'subdomain' => 'tenant-a', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $admin = $this->make($customerId, 'customer_admin', 'admin@example.test');

        return [$admin, ['customer_admin' => $admin, 'teacher' => $this->make($customerId, 'teacher', 'teacher@example.test'), 'student' => $this->make($customerId, 'student', 'student@example.test')]];
    }

    private function make(int $customerId, string $role, string $email): User
    {
        return User::forceCreate(['customer_id' => $customerId, 'name' => ucfirst($role), 'email' => $email, 'password' => Hash::make('password123'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now()]);
    }

    public function test_the_birth_date_is_the_kit_date_input_with_an_iso_value_and_a_future_limit(): void
    {
        [$admin, $users] = $this->setup3();
        DB::table('users')->where('id', $users['teacher']->id)->update(['date_of_birth' => '1991-03-04']);

        $html = $this->actingAs($admin)->withHeaders(self::AJAX)->get('https://tenant-a.localhost/admin/users/'.$users['teacher']->id.'/edit')->assertOk()->getContent();
        $this->assertStringContainsString('type="date" name="date_of_birth" value="1991-03-04" data-ui-kit-date', $html);
        $this->assertStringContainsString('max="'.now()->format('Y-m-d').'"', $html);
        $this->assertStringContainsString('data-today-label="'.__('lf.LF_common_date_today').'"', $html);
        $this->assertStringContainsString('data-weekdays="[&quot;T2&quot;', $html);
        $this->flushHeaders();

        $profile = $this->actingAs($admin)->withHeaders(self::AJAX)->get('https://tenant-a.localhost/admin/my-account')->assertOk()->getContent();
        $this->assertStringContainsString('data-ui-kit-date', $profile);
    }
}
