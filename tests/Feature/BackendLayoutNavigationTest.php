<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackendLayoutNavigationTest extends TestCase
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

    public function test_admin_and_teacher_backend_layouts_render_sidebar_toggle_and_breadcrumbs(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');

        foreach ([
            [$admin, 'https://tenant-a.localhost/admin'],
            [$teacher, 'https://tenant-a.localhost/teacher'],
        ] as [$user, $url]) {
            $this->actingAs($user)
                ->get($url)
                ->assertOk()
                ->assertSee('class="backend-sidebar-toggle"', false)
                ->assertSee('class="backend-breadcrumbs"', false)
                ->assertSee('aria-label="'.__('lf.LF_navigation_label_backend_breadcrumbs').'"', false)
                ->assertSee('data-sidebar-auto-collapse="false"', false)
                ->assertSee('x-data="backendSidebar(false)"', false);
        }
    }

    public function test_sidebar_first_level_items_render_icons(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin')
            ->assertOk()
            ->assertSee('class="backend-sidebar-icon"', false)
            ->assertSee('data-sidebar-icon="home"', false)
            ->assertSee('data-sidebar-icon="users"', false)
            ->assertSee('data-sidebar-icon="book-open"', false)
            ->assertSee('data-sidebar-icon="image"', false);

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher')
            ->assertOk()
            ->assertSee('class="backend-sidebar-icon"', false)
            ->assertSee('data-sidebar-icon="home"', false)
            ->assertSee('data-sidebar-icon="user-cog"', false)
            ->assertSee('data-sidebar-icon="folder"', false)
            ->assertSee('data-sidebar-icon="book-open"', false);
    }

    public function test_collapsed_sidebar_css_uses_icon_only_mode_and_hides_submenus(): void
    {
        $css = file_get_contents(
            base_path('resources/css/admin/admin-layout.css')
        );

        $this->assertStringContainsString(
            '.backend-shell.is-sidebar-collapsed .admin-sidebar-link-label',
            $css
        );
        $this->assertStringContainsString(
            '.backend-shell.is-sidebar-collapsed .admin-sidebar-group-links',
            $css
        );
        $this->assertStringContainsString('display: none;', $css);
        $this->assertStringContainsString('content: attr(data-sidebar-label);', $css);
    }

    public function test_backend_create_and_edit_pages_include_auto_collapse_marker(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory(
            $customerId,
            'Korean',
            'korean'
        );

        foreach ([
            'https://tenant-a.localhost/admin/course-categories/create',
            "https://tenant-a.localhost/admin/course-categories/{$categoryId}/edit",
        ] as $url) {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk()
                ->assertSee('has-sidebar-auto-collapse', false)
                ->assertSee('data-sidebar-auto-collapse="true"', false)
                ->assertSee('x-data="backendSidebar(true)"', false)
                ->assertSee('href="https://tenant-a.localhost/admin/course-categories"', false);
        }
    }

    public function test_backend_index_pages_auto_expand_sidebar(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-categories')
            ->assertOk()
            ->assertSee('class="backend-sidebar-toggle"', false)
            ->assertSee('data-sidebar-auto-collapse="false"', false)
            ->assertSee('x-data="backendSidebar(false)"', false)
            ->assertDontSee('has-sidebar-auto-collapse', false);
    }

    public function test_sidebar_state_script_auto_expands_list_pages_and_collapses_form_pages_once(): void
    {
        $script = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString('window.backendSidebar = (autoCollapse)', $script);
        $this->assertStringContainsString('this.sidebarCollapsed = true;', $script);
        $this->assertStringContainsString('this.storePreference(true);', $script);
        $this->assertStringContainsString('this.sidebarCollapsed = false;', $script);
        $this->assertStringContainsString('this.storePreference(false);', $script);
    }

    public function test_public_and_student_layouts_do_not_render_backend_sidebar_toggle(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');

        $this->get('https://tenant-a.localhost/')
            ->assertOk()
            ->assertDontSee('backend-sidebar-toggle', false)
            ->assertDontSee('backend-breadcrumbs', false);

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/profile')
            ->assertOk()
            ->assertDontSee('backend-sidebar-toggle', false)
            ->assertDontSee('backend-breadcrumbs', false);
    }

    private function createTenant(string $slug = 'tenant-a'): int
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

    private function createUser(int $customerId, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function createCategory(
        int $customerId,
        string $name,
        string $slug
    ): int {
        return DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId,
            'parent_id' => null,
            'name' => $name,
            'slug' => $slug,
            'description' => null,
            'thumbnail_image' => null,
            'banner_image' => null,
            'sort_order' => 0,
            'is_featured' => false,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'status' => 'active',
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
