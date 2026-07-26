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
            'slug' => 'forged-slug',
            'organization_type' => 'training_center',
            'name' => 'Acme Admin',
            'email' => 'admin@acme.test',
            'phone' => '0900000000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect('https://acme-academy.localhost/login');
        $this->assertGuest();

        $customer = DB::table('saas_customers')->where('slug', 'acme-academy')->first();
        $user = User::where('email', 'admin@acme.test')->firstOrFail();

        $this->assertSame('active', $customer->status);
        $this->assertSame('0900000000', $customer->phone);
        $this->assertSame('training_center', $customer->organization_type);
        $this->assertNull($customer->metadata);
        $this->assertSame($customer->id, $user->customer_id);
        $this->assertSame('0900000000', $user->phone);
        $this->assertNull($user->email_verified_at);
    }

    public function test_registration_form_generates_readonly_slug_and_has_localized_placeholders(): void
    {
        $response = $this->get('https://localhost/register-customer')
            ->assertOk()
            ->assertSee('x-data="tenantRegistrationForm(', false)
            ->assertSee('@input="slug = slugify($event.target.value)"', false)
            ->assertSee('id="slug"', false)
            ->assertSee('x-model="slug"', false)
            ->assertSee('readonly', false)
            ->assertSee('class="public-password-control"', false)
            ->assertSee('class="public-password-toggle"', false)
            ->assertSee(__('lf.LF_auth_register_slug_help'));

        foreach ([
            'Nhập tên tổ chức',
            'Được tạo tự động từ tên tổ chức',
            'Chọn loại tổ chức',
            'Nhập họ và tên quản trị viên',
            'Nhập email quản trị viên',
            'Nhập số điện thoại',
            'Nhập mật khẩu',
            'Nhập lại mật khẩu',
        ] as $placeholder) {
            $response->assertSee($placeholder);
        }

        $this->assertSame(8, substr_count(
            $response->getContent(),
            'class="lf-required-indicator"'
        ));
        $this->assertSame(8, substr_count(
            $response->getContent(),
            'aria-required="true"'
        ));
        $response
            ->assertSeeText(__('lf.LF_auth_register_group_organization'))
            ->assertSeeText(__('lf.LF_auth_register_group_administrator'))
            ->assertSeeText(__('lf.LF_auth_register_group_security'))
            ->assertSee('class="public-address-preview"', false)
            ->assertSeeText(__('lf.LF_auth_register_address_preview'))
            ->assertSee('class="public-register-actions"', false)
            ->assertSeeText(__('lf.LF_auth_register_login_guidance'))
            ->assertDontSee('href="https://localhost/login"', false);

        $publicCss = file_get_contents(resource_path('css/public/public.css'));
        $appJs = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString(
            '.public-page .public-form-control.is-lf-placeholder:not(:focus),',
            $publicCss
        );
        $this->assertStringContainsString(
            '.public-page .public-form-control.is-lf-placeholder:focus {',
            $publicCss
        );
        $this->assertStringContainsString(
            '.public-page select.public-form-control',
            $appJs
        );
        $this->assertStringContainsString(
            '.public-page input.public-form-control[type="datetime-local"]',
            $appJs
        );
    }

    public function test_backend_derives_and_normalizes_slug_from_organization_name(): void
    {
        $cases = [
            ['Visang 1', 'visang-1', 'visang1@example.test'],
            ['Trung tâm Tiếng Hàn', 'trung-tam-tieng-han', 'korean@example.test'],
            ['  Học viện && Công nghệ---LF  ', 'hoc-vien-cong-nghe-lf', 'academy@example.test'],
        ];

        foreach ($cases as [$customerName, $expectedSlug, $email]) {
            $this->post('https://localhost/register-customer', [
                'customer_name' => $customerName,
                'slug' => 'forged-value',
                'organization_type' => 'training_center',
                'name' => 'Tenant Admin',
                'email' => $email,
                'phone' => '0900000000',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])->assertRedirect("https://{$expectedSlug}.localhost/login");

            $this->assertDatabaseHas('saas_customers', [
                'name' => trim($customerName),
                'slug' => $expectedSlug,
                'subdomain' => $expectedSlug,
            ]);
        }
    }

    public function test_registration_rejects_derived_duplicate_slug(): void
    {
        DB::table('saas_customers')->insert([
            'name' => 'Existing',
            'slug' => 'trung-tam-tieng-han',
            'subdomain' => 'trung-tam-tieng-han',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('https://localhost/register-customer', [
            'customer_name' => 'Trung tâm Tiếng Hàn',
            'slug' => 'available-forged-value',
            'organization_type' => 'training_center',
            'name' => 'Tenant Admin',
            'email' => 'duplicate@example.test',
            'phone' => '0900000000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('slug');

        $this->assertDatabaseCount('saas_customers', 1);
    }

    public function test_registration_keeps_non_sensitive_input_after_validation_error(): void
    {
        $this->post('https://localhost/register-customer', [
            'customer_name' => 'Trung tâm Tiếng Hàn',
            'slug' => 'forged-value',
            'organization_type' => '',
            'name' => 'Nguyễn Văn A',
            'email' => 'admin@example.test',
            'phone' => '0900000000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertSessionHasErrors('organization_type')
            ->assertSessionHasInput('customer_name', 'Trung tâm Tiếng Hàn')
            ->assertSessionHasInput('slug', 'trung-tam-tieng-han')
            ->assertSessionHasInput('name', 'Nguyễn Văn A')
            ->assertSessionHasInput('email', 'admin@example.test')
            ->assertSessionHasInput('phone', '0900000000');
    }

    public function test_customer_registration_rejects_unknown_organization_type(): void
    {
        $this->post('https://localhost/register-customer', [
            'customer_name' => 'Acme Academy',
            'slug' => 'acme',
            'organization_type' => 'other',
            'name' => 'Acme Admin',
            'email' => 'admin@acme.test',
            'phone' => '0900000000',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('organization_type');

        $this->assertDatabaseMissing('saas_customers', ['slug' => 'acme']);
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
