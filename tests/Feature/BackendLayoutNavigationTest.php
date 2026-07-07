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
                ->assertSee('x-data="backendSidebar()"', false)
                ->assertSee("const collapsedKey = 'lf.backend.sidebar.collapsed';", false)
                ->assertSee("document.documentElement.classList.add('is-backend-sidebar-initializing');", false)
                ->assertDontSee('data-sidebar-auto-collapse', false)
                ->assertDontSee('has-sidebar-auto-collapse', false)
                ->assertDontSee('lf.backend.sidebar.pageMode', false);
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
            ->assertSee('data-sidebar-icon="image"', false)
            ->assertDontSee('x-on:click="handleSidebarNavigation($event)"', false);

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher')
            ->assertOk()
            ->assertSee('class="backend-sidebar-icon"', false)
            ->assertSee('data-sidebar-icon="home"', false)
            ->assertSee('data-sidebar-icon="folder"', false)
            ->assertSee('data-sidebar-icon="book-open"', false)
            ->assertDontSee('x-on:click="handleSidebarNavigation($event)"', false);
    }

    public function test_account_navigation_moves_from_sidebar_to_user_dropdown_by_role(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');

        $adminHtml = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin')
            ->assertOk()
            ->assertSee('class="admin-account-dropdown"', false)
            ->assertSee('class="admin-account-dropdown-user"', false)
            ->assertSee('class="admin-account-dropdown-links"', false)
            ->assertSee('href="https://tenant-a.localhost/admin/organization"', false)
            ->assertSee('href="https://tenant-a.localhost/admin/users"', false)
            ->assertSee('href="https://tenant-a.localhost/admin/my-account"', false)
            ->assertSeeText(__('lf.LF_navigation_menu_admin_organization'))
            ->assertSeeText(__('lf.LF_navigation_menu_admin_users'))
            ->assertSeeText(__('lf.LF_navigation_menu_admin_my_account'))
            ->assertSeeText(__('lf.LF_navigation_menu_student_logout'))
            ->getContent();

        $adminSidebar = $this->backendSidebarHtml($adminHtml);

        $this->assertStringNotContainsString(__('lf.LF_navigation_group_admin_account_organization'), $adminSidebar);
        $this->assertStringNotContainsString(__('lf.LF_navigation_menu_admin_organization'), $adminSidebar);
        $this->assertStringNotContainsString(__('lf.LF_navigation_menu_admin_users'), $adminSidebar);
        $this->assertStringNotContainsString(__('lf.LF_navigation_menu_admin_my_account'), $adminSidebar);

        $teacherHtml = $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher')
            ->assertOk()
            ->assertSee('class="admin-account-dropdown"', false)
            ->assertSee('href="https://tenant-a.localhost/teacher/profile"', false)
            ->assertSeeText(__('lf.LF_navigation_menu_admin_my_account'))
            ->assertDontSee('href="https://tenant-a.localhost/admin/organization"', false)
            ->assertDontSee('href="https://tenant-a.localhost/admin/users"', false)
            ->assertDontSee('href="https://tenant-a.localhost/admin/my-account"', false)
            ->getContent();

        $teacherSidebar = $this->backendSidebarHtml($teacherHtml);

        $this->assertStringNotContainsString(__('lf.LF_navigation_group_teacher_my_account'), $teacherSidebar);
        $this->assertStringNotContainsString(__('lf.LF_navigation_menu_admin_my_account'), $teacherSidebar);
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
        $this->assertStringContainsString('.admin-sidebar-group-arrow', $css);
        $this->assertStringContainsString('transform: rotate(-45deg);', $css);
        $this->assertStringContainsString('transform: rotate(45deg);', $css);
        $this->assertStringContainsString(
            '.backend-shell.is-sidebar-collapsed .admin-sidebar-group-arrow',
            $css
        );
        $this->assertStringContainsString(
            ':root.is-backend-sidebar-collapsed .backend-shell .admin-content-wrap',
            $css
        );
        $this->assertStringContainsString(
            ':root.is-backend-sidebar-initializing .backend-shell .admin-content-wrap',
            $css
        );
        $this->assertStringContainsString(
            ':root.is-backend-sidebar-initializing .backend-shell .admin-sidebar-group-arrow',
            $css
        );
        $this->assertStringContainsString(
            '.admin-sidebar-group:not(.is-open) .admin-sidebar-group-links',
            $css
        );
        $this->assertStringContainsString('transition: none;', $css);
        $this->assertStringContainsString('display: none;', $css);
        $this->assertStringContainsString('content: attr(data-sidebar-label);', $css);
    }

    public function test_account_pages_render_without_left_sidebar_account_group(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $html = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/my-account')
            ->assertOk()
            ->assertSee('href="https://tenant-a.localhost/admin/my-account"', false)
            ->getContent();

        $sidebar = $this->backendSidebarHtml($html);

        $this->assertStringNotContainsString('admin-sidebar-link-child is-active', $sidebar);
        $this->assertStringNotContainsString(__('lf.LF_navigation_group_admin_account_organization'), $sidebar);
    }

    public function test_backend_create_and_edit_pages_do_not_expose_auto_collapse_marker(): void
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
                ->assertSee('x-data="backendSidebar()"', false)
                ->assertSee('href="https://tenant-a.localhost/admin/course-categories"', false)
                ->assertDontSee('x-on:click="handleBreadcrumbNavigation($event)"', false)
                ->assertDontSee('has-sidebar-auto-collapse', false)
                ->assertDontSee('data-sidebar-auto-collapse', false)
                ->assertDontSee('backendSidebar(true)', false)
                ->assertDontSee('backendSidebar(false)', false);
        }
    }

    public function test_backend_index_pages_do_not_expose_auto_expand_marker(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-categories')
            ->assertOk()
            ->assertSee('class="backend-sidebar-toggle"', false)
            ->assertSee('x-data="backendSidebar()"', false)
            ->assertDontSee('data-sidebar-auto-collapse', false)
            ->assertDontSee('has-sidebar-auto-collapse', false)
            ->assertDontSee('lf.backend.sidebar.pageMode', false);
    }

    public function test_sidebar_state_script_uses_saved_state_and_defaults_expanded(): void
    {
        $script = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString('window.backendSidebar = ()', $script);
        $this->assertStringContainsString("storageKey: 'lf.backend.sidebar.collapsed'", $script);
        $this->assertStringContainsString("manualStorageKey: 'lf.backend.sidebar.manual'", $script);
        $this->assertStringContainsString("groupStorageKey: 'lf.backend.sidebar.groups'", $script);
        $this->assertStringContainsString('const hasStoredPreference', $script);
        $this->assertStringContainsString("storedManualPreference === 'true'", $script);
        $this->assertStringContainsString('this.sidebarCollapsed = this.resolveInitialSidebarState(hasStoredPreference, storedPreference);', $script);
        $this->assertStringContainsString("return storedPreference === 'true';", $script);
        $this->assertStringContainsString('return false;', $script);
        $this->assertStringContainsString('this.hasManualPreference = true;', $script);
        $this->assertStringContainsString("window.localStorage.setItem(this.manualStorageKey, 'true')", $script);
        $this->assertStringContainsString("'is-backend-sidebar-collapsed'", $script);
        $this->assertStringContainsString("'is-backend-sidebar-initializing'", $script);
        $this->assertStringNotContainsString('pageModeStorageKey', $script);
        $this->assertStringNotContainsString('isEnteringWorkspace', $script);
        $this->assertStringNotContainsString('return autoCollapse;', $script);
        $this->assertStringNotContainsString('this.storePreference(false);', $script);
    }

    public function test_backend_navigation_clicks_preserve_sidebar_state(): void
    {
        $script = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString('toggleSidebar()', $script);
        $this->assertStringContainsString('this.setManualSidebarState(! this.sidebarCollapsed);', $script);
        $this->assertStringContainsString("window.localStorage.setItem(this.manualStorageKey, 'true')", $script);
        $this->assertStringContainsString("window.localStorage.setItem(this.storageKey, value ? 'true' : 'false')", $script);
        $this->assertStringNotContainsString('handleSidebarNavigation', $script);
        $this->assertStringNotContainsString('handleBreadcrumbNavigation', $script);
        $this->assertStringNotContainsString('expandSidebarFromNavigation', $script);
        $this->assertStringNotContainsString('this.setManualSidebarState(false);', $script);
        $this->assertStringNotContainsString('event.preventDefault();', $script);
    }

    public function test_sidebar_group_script_toggles_and_persists_submenus(): void
    {
        $script = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString('registerSidebarGroup(groupKey, isActive)', $script);
        $this->assertStringContainsString('toggleSidebarGroup(groupKey)', $script);
        $this->assertStringContainsString('setSidebarGroupOpen(groupKey, isOpen)', $script);
        $this->assertStringContainsString('isSidebarGroupOpen(groupKey)', $script);
        $this->assertStringContainsString('this.storeSidebarGroups();', $script);
        $this->assertStringContainsString('JSON.stringify(this.sidebarGroups)', $script);
        $this->assertStringContainsString('this.setSidebarGroupOpen(groupKey, ! this.isSidebarGroupOpen(groupKey));', $script);
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

    private function backendSidebarHtml(string $html): string
    {
        preg_match('/<aside id="backend-sidebar".*?<\/aside>/s', $html, $matches);

        return $matches[0] ?? '';
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
