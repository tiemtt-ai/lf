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
                ->assertSee('x-data="backendSidebar(false)"', false)
                ->assertSee("const collapsedKey = 'lf.backend.sidebar.collapsed';", false)
                ->assertSee("const manualKey = 'lf.backend.sidebar.manual';", false)
                ->assertSee("const pageModeKey = 'lf.backend.sidebar.pageMode';", false)
                ->assertSee("const currentMode = 'standard';", false)
                ->assertSee("document.documentElement.classList.add('is-backend-sidebar-initializing');", false);
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
            ->assertSee('x-on:click="handleSidebarNavigation($event)"', false)
            ->assertSee('x-on:click="toggleSidebarGroup(', false)
            ->assertSee('class="admin-sidebar-group-arrow"', false);

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher')
            ->assertOk()
            ->assertSee('class="backend-sidebar-icon"', false)
            ->assertSee('data-sidebar-icon="home"', false)
            ->assertSee('data-sidebar-icon="user-cog"', false)
            ->assertSee('data-sidebar-icon="folder"', false)
            ->assertSee('data-sidebar-icon="book-open"', false)
            ->assertSee('admin-sidebar-link-child', false)
            ->assertSee('x-on:click="handleSidebarNavigation($event)"', false);
    }

    public function test_sidebar_group_alpine_attributes_are_on_wrapper_not_visible_text(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $html = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin')
            ->assertOk()
            ->assertSee(__('lf.LF_navigation_group_admin_account_organization'))
            ->assertSee('class="admin-sidebar-group"', false)
            ->assertSee('data-sidebar-group-key="sidebar-group-1"', false)
            ->assertSee('x-init="registerSidebarGroup(\'sidebar-group-1\', false)"', false)
            ->assertSee('x-bind:class="{ \'is-open\': isSidebarGroupOpen(\'sidebar-group-1\') }"', false)
            ->assertSee("groups['sidebar-group-1'] === true", false)
            ->getContent();

        $this->assertStringNotContainsString(">\n                                 x-init=", $html);
        $this->assertStringNotContainsString(">\n                                 x-bind:class=", $html);
        $this->assertStringNotContainsString('&quot;registerSidebarGroup', $html);
        $this->assertStringNotContainsString('admin-sidebar-group is-open"', $html);
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

    public function test_active_child_expands_parent_sidebar_group(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/my-account')
            ->assertOk()
            ->assertSee('admin-sidebar-group is-active is-open', false)
            ->assertSee('x-init="registerSidebarGroup(\'sidebar-group-1\', true)"', false)
            ->assertSee('x-bind:aria-expanded="isSidebarGroupOpen(\'sidebar-group-1\').toString()"', false)
            ->assertSee('data-sidebar-group-key="sidebar-group-1"', false)
            ->assertSee('admin-sidebar-link admin-sidebar-link-child is-active', false);
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
                ->assertSee('x-on:click="handleBreadcrumbNavigation($event)"', false)
                ->assertSee('href="https://tenant-a.localhost/admin/course-categories"', false);
        }
    }

    public function test_backend_index_pages_preserve_sidebar_state_without_auto_expand(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-categories')
            ->assertOk()
            ->assertSee('class="backend-sidebar-toggle"', false)
            ->assertSee('data-sidebar-auto-collapse="false"', false)
            ->assertSee('x-data="backendSidebar(false)"', false)
            ->assertSee("const currentMode = 'standard';", false)
            ->assertDontSee('has-sidebar-auto-collapse', false);
    }

    public function test_sidebar_state_script_collapses_workspace_entry_and_preserves_manual_state(): void
    {
        $script = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString('window.backendSidebar = (autoCollapse)', $script);
        $this->assertStringContainsString("storageKey: 'lf.backend.sidebar.collapsed'", $script);
        $this->assertStringContainsString("manualStorageKey: 'lf.backend.sidebar.manual'", $script);
        $this->assertStringContainsString("pageModeStorageKey: 'lf.backend.sidebar.pageMode'", $script);
        $this->assertStringContainsString("groupStorageKey: 'lf.backend.sidebar.groups'", $script);
        $this->assertStringContainsString("storedManualPreference === 'true'", $script);
        $this->assertStringContainsString('isEnteringWorkspace', $script);
        $this->assertStringContainsString('return true;', $script);
        $this->assertStringContainsString("return storedPreference === 'true';", $script);
        $this->assertStringContainsString('return autoCollapse;', $script);
        $this->assertStringContainsString('this.hasManualPreference = true;', $script);
        $this->assertStringContainsString("window.localStorage.setItem(this.manualStorageKey, 'true')", $script);
        $this->assertStringNotContainsString('this.sidebarCollapsed = false;', $script);
        $this->assertStringNotContainsString('this.storePreference(false);', $script);
        $this->assertStringContainsString("'is-backend-sidebar-collapsed'", $script);
        $this->assertStringContainsString("'is-backend-sidebar-initializing'", $script);
    }

    public function test_collapsed_sidebar_navigation_click_saves_expanded_state_before_navigation(): void
    {
        $script = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString('handleSidebarNavigation(event)', $script);
        $this->assertStringContainsString('handleBreadcrumbNavigation(event)', $script);
        $this->assertStringContainsString('this.handleSidebarNavigation(event);', $script);
        $this->assertStringContainsString('this.expandSidebarFromNavigation();', $script);
        $this->assertStringContainsString('expandSidebarFromNavigation()', $script);
        $this->assertStringContainsString('this.setManualSidebarState(false);', $script);
        $this->assertStringContainsString("window.localStorage.setItem(this.manualStorageKey, 'true')", $script);
        $this->assertStringContainsString("window.localStorage.setItem(this.storageKey, value ? 'true' : 'false')", $script);
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
        $this->assertStringContainsString('this.setManualSidebarState(false);', $script);
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
