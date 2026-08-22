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

    public function test_admin_dashboard_exposes_localized_status_and_quick_actions(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin')
            ->assertOk()
            ->assertSee('class="admin-dashboard-intro"', false)
            ->assertSee('class="admin-dashboard-quick-grid"', false)
            ->assertSee('href="https://tenant-a.localhost/admin/organization"', false)
            ->assertSee('href="https://tenant-a.localhost/admin/users"', false)
            ->assertSee('href="https://tenant-a.localhost/admin/my-account"', false)
            ->assertSeeText(__('lf.LF_common_status_common_active'))
            ->assertSeeText(__('lf.LF_common_role_admin_customer_admin'));
    }

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
            ->assertSee('data-sidebar-icon="package"', false)
            ->assertDontSee('data-sidebar-icon="shopping-bag"', false)
            ->assertSee('data-sidebar-icon="image"', false)
            ->assertSee('href="https://tenant-a.localhost/admin/learning-frameworks"', false)
            ->assertSeeText(__('lf.LF_learning_frameworks'))
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

    public function test_course_products_uses_the_package_icon_when_active_and_inactive(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $inactiveHtml = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin')
            ->assertOk()
            ->getContent();
        $activeHtml = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-products')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/class="admin-sidebar-link" href="[^"]+\/admin\/course-products"[^>]+data-sidebar-icon="package"/',
            $inactiveHtml
        );
        $this->assertMatchesRegularExpression(
            '/class="admin-sidebar-link is-active" href="[^"]+\/admin\/course-products"[^>]+data-sidebar-icon="package"/',
            $activeHtml
        );
        $this->assertStringNotContainsString('data-sidebar-icon="shopping-bag"', $activeHtml);
    }

    public function test_enrollment_navigation_precedes_class_navigation(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $html = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin')
            ->assertOk()
            ->getContent();

        $enrollmentPosition = strpos($html, 'href="https://tenant-a.localhost/admin/course-enrollments"');
        $classPosition = strpos($html, 'href="https://tenant-a.localhost/admin/course-cohorts"');

        $this->assertNotFalse($enrollmentPosition);
        $this->assertNotFalse($classPosition);
        $this->assertLessThan($classPosition, $enrollmentPosition);
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
        $this->assertStringContainsString('.backend-sidebar-tooltip {', $css);
        $this->assertStringContainsString('position: fixed;', $css);

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('showSidebarTooltip(event, label)', $script);
        $this->assertStringContainsString('getBoundingClientRect()', $script);
    }

    public function test_backend_sidebar_uses_shared_sticky_layout_css(): void
    {
        $css = file_get_contents(
            base_path('resources/css/admin/admin-layout.css')
        );

        $this->assertStringContainsString('.lf-admin-page .layout-sidebar', $css);
        $this->assertStringContainsString('position: sticky;', $css);
        $this->assertStringContainsString('top: 156px;', $css);
        $this->assertStringContainsString('max-height: calc(100vh - 156px);', $css);
        $this->assertStringContainsString('overflow-y: auto;', $css);
        $this->assertStringContainsString('overflow-x: hidden;', $css);
        $this->assertStringContainsString('@media (max-width: 900px)', $css);
        $this->assertStringContainsString('position: static;', $css);
        $this->assertStringContainsString('max-height: none;', $css);
        $this->assertStringContainsString('overflow: visible;', $css);
    }

    public function test_backend_grouped_forms_adapt_to_collapsed_sidebar(): void
    {
        $componentCss = file_get_contents(
            base_path('resources/css/admin/admin-components.css')
        );
        $pageCss = file_get_contents(
            base_path('resources/css/admin/admin-pages.css')
        );

        $this->assertStringContainsString('.backend-form-layout', $componentCss);
        $this->assertStringContainsString('.admin-form-card > form:has(> .admin-form-section)', $componentCss);
        $this->assertStringContainsString(
            ':root.is-backend-sidebar-collapsed .backend-shell .admin-form-card > form:has(> .admin-form-section)',
            $componentCss
        );
        $this->assertStringContainsString(
            '.backend-shell.is-sidebar-collapsed .admin-form-card > form:has(> .admin-form-section)',
            $componentCss
        );
        $this->assertStringContainsString('column-count: 2;', $componentCss);
        $this->assertStringContainsString('column-count: 1;', $componentCss);
        $this->assertStringContainsString('break-inside: avoid;', $componentCss);
        $this->assertStringContainsString('column-span: all;', $componentCss);
        $this->assertStringContainsString('justify-content: flex-end;', $componentCss);
        $this->assertStringContainsString('margin-bottom: 24px;', $componentCss);
        $this->assertStringContainsString('.backend-form-shell', $componentCss);
        $this->assertStringContainsString('.backend-form-columns', $componentCss);
        $this->assertStringContainsString('.backend-form-column', $componentCss);
        $this->assertStringContainsString(
            '.admin-form-card > form:has(> .backend-form-shell > .backend-form-columns) > .admin-form-actions',
            $componentCss
        );
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr));', $componentCss);
        $this->assertStringContainsString('flex-direction: column;', $componentCss);
        $this->assertStringContainsString('gap: 24px;', $componentCss);
        $this->assertStringContainsString('@media (max-width: 900px)', $componentCss);
        $this->assertStringContainsString(
            '.lf-admin-page input.lf-form-control[readonly]',
            $componentCss
        );
        $this->assertStringContainsString('background-color: #f8fafc;', $componentCss);
        $this->assertStringContainsString('color: #374151;', $componentCss);
        $this->assertStringContainsString('cursor: default;', $componentCss);
        $this->assertStringContainsString('.admin-form-card {', $pageCss);
        $this->assertStringContainsString('max-width: 720px;', $pageCss);
        $this->assertStringContainsString('.admin-form-card:has(> form > .admin-form-section)', $pageCss);
        $this->assertStringContainsString('.admin-form-card:has(> form > .backend-form-columns)', $pageCss);
        $this->assertStringContainsString(
            '.admin-form-card:has(> form > .backend-form-shell > .backend-form-columns)',
            $pageCss
        );
        $this->assertStringContainsString('width: 100%;', $pageCss);
        $this->assertStringContainsString('max-width: none;', $pageCss);
    }

    public function test_backend_index_tables_use_standard_status_and_actions(): void
    {
        $indexFiles = [
            'resources/views/admin/users/index.blade.php',
            'resources/views/course-categories/index.blade.php',
            'resources/views/course-cohort-students/index.blade.php',
            'resources/views/course-cohorts/index.blade.php',
            'resources/views/course-enrollments/index.blade.php',
            'resources/views/course-products/index.blade.php',
            'resources/views/course-templates/index.blade.php',
            'resources/views/media-categories/index.blade.php',
            'resources/views/media-files/index.blade.php',
        ];

        foreach ($indexFiles as $file) {
            $blade = file_get_contents(base_path($file));

            $this->assertStringContainsString('lf.table_no', $blade, $file);
            $this->assertStringContainsString('lf.table_actions', $blade, $file);
            $this->assertStringContainsString('firstItem() + $loop->index', $blade, $file);
            $this->assertStringContainsString('->links()', $blade, $file);
            $this->assertStringNotContainsString('LF_common_label_common_id', $blade, $file);
            $this->assertStringNotContainsString('LF_common_label_common_action', $blade, $file);
            $this->assertStringNotContainsString('toggle-status', $blade, $file);
            $this->assertStringNotContainsString('LF_common_button_disable', $blade, $file);
            $this->assertStringNotContainsString('LF_common_button_enable', $blade, $file);
            $this->assertStringNotContainsString('LF_course_category_common_deactivate', $blade, $file);
            $this->assertStringNotContainsString('LF_course_category_common_activate', $blade, $file);
            $this->assertStringNotContainsString('LF_media_category_common_archive\')', $blade, $file);
            $this->assertStringNotContainsString('LF_media_category_common_archive_confirm', $blade, $file);
            $this->assertStringNotContainsString('LF_course_product_common_archive\')', $blade, $file);
            $this->assertStringNotContainsString('LF_common_button_delete', $blade, $file);
        }

        foreach (array_diff($indexFiles, [
            'resources/views/course-cohorts/index.blade.php',
            'resources/views/course-enrollments/index.blade.php',
            'resources/views/media-files/index.blade.php',
            'resources/views/course-products/index.blade.php',
            'resources/views/course-templates/index.blade.php',
        ]) as $file) {
            $blade = file_get_contents(base_path($file));

            $this->assertStringContainsString('LF_common_status_common_active', $blade, $file);
            $this->assertStringContainsString('LF_common_status_common_inactive', $blade, $file);
        }

        $enrollmentIndex = file_get_contents(
            base_path('resources/views/course-enrollments/index.blade.php')
        );
        $this->assertStringContainsString(
            "@foreach (['pending', 'active', 'suspended', 'completed', 'expired', 'cancelled'] as \$enrollmentStatus)",
            $enrollmentIndex
        );
        $this->assertStringContainsString(
            "__('lf.LF_course_enrollment_common_'.\$enrollmentStatus)",
            $enrollmentIndex
        );

        $cohortIndex = file_get_contents(
            base_path('resources/views/course-cohorts/index.blade.php')
        );
        $this->assertStringContainsString(
            "@foreach (['draft', 'active', 'completed', 'archived'] as \$cohortStatus)",
            $cohortIndex
        );
        $this->assertStringContainsString(
            "__('lf.LF_course_cohort_common_'.\$cohortStatus)",
            $cohortIndex
        );
        $this->assertStringContainsString(
            "__('lf.LF_course_cohort_common_'.\$cohort->status)",
            $cohortIndex
        );
        $this->assertStringContainsString('course-cohort-status-badge--draft', $cohortIndex);
        $this->assertStringContainsString('badge-success', $cohortIndex);
        $this->assertStringContainsString('course-cohort-status-badge--completed', $cohortIndex);
        $this->assertStringContainsString('badge-danger', $cohortIndex);

        $productIndex = file_get_contents(
            base_path('resources/views/course-products/index.blade.php')
        );
        $this->assertStringContainsString(
            "@foreach (['draft', 'active', 'inactive', 'archived'] as \$productStatus)",
            $productIndex
        );
        $this->assertStringContainsString(
            "__('lf.LF_course_product_common_'.\$productStatus)",
            $productIndex
        );
        $this->assertStringContainsString(
            "__('lf.LF_course_product_common_'.\$product->status)",
            $productIndex
        );

        $templateIndex = file_get_contents(
            base_path('resources/views/course-templates/index.blade.php')
        );
        $this->assertStringContainsString(
            '@foreach (\App\Support\CourseTemplateStatus::VALUES as $templateStatus)',
            $templateIndex
        );
        $this->assertStringContainsString(
            "__('lf.LF_course_template_common_'.\$templateStatus)",
            $templateIndex
        );
        $this->assertStringContainsString(
            "__('lf.LF_course_template_common_'.\$template->status)",
            $templateIndex
        );

        foreach ([
            'app/Http/Controllers/Admin/UserController.php',
            'app/Http/Controllers/CourseCategoryController.php',
            'app/Http/Controllers/CourseCohortController.php',
            'app/Http/Controllers/CourseCohortStudentController.php',
            'app/Http/Controllers/CourseEnrollmentController.php',
            'app/Http/Controllers/CourseProductController.php',
            'app/Http/Controllers/CourseTemplateController.php',
            'app/Http/Controllers/MediaCategoryController.php',
            'app/Http/Controllers/MediaFileController.php',
        ] as $file) {
            $controller = file_get_contents(base_path($file));

            $this->assertStringContainsString('->paginate(10)', $controller, $file);
            $this->assertStringContainsString('->withQueryString()', $controller, $file);
        }

        $componentCss = file_get_contents(
            base_path('resources/css/admin/admin-components.css')
        );

        $this->assertMatchesRegularExpression(
            '/\.admin-text-action\s*\{[^}]*color:\s*var\(--admin-primary\);[^}]*\}/s',
            $componentCss
        );
        $this->assertMatchesRegularExpression(
            '/\.admin-text-action:hover,\s*\.admin-text-action:focus,\s*\.admin-text-action:focus-visible\s*\{[^}]*text-decoration:\s*underline;/s',
            $componentCss
        );
        $this->assertMatchesRegularExpression(
            '/\.admin-text-action:focus-visible\s*\{[^}]*outline:\s*2px solid var\(--admin-primary\);[^}]*outline-offset:\s*2px;/s',
            $componentCss
        );
        $this->assertMatchesRegularExpression(
            '/\.lf-admin-page \.admin-primary-outline-action\s*\{[^}]*border-color:\s*var\(--admin-primary\);[^}]*color:\s*var\(--admin-primary\);[^}]*background:\s*transparent;/s',
            $componentCss
        );
        $this->assertMatchesRegularExpression(
            '/\.lf-admin-page \.admin-primary-outline-action:hover,\s*\.lf-admin-page \.admin-primary-outline-action:focus,\s*\.lf-admin-page \.admin-primary-outline-action:focus-visible\s*\{[^}]*color:\s*var\(--admin-primary-contrast\);[^}]*background:\s*var\(--admin-primary\);/s',
            $componentCss
        );
        $this->assertMatchesRegularExpression(
            '/\.lf-admin-page \.admin-primary-outline-action:focus-visible\s*\{[^}]*outline:\s*2px solid var\(--admin-primary\);[^}]*outline-offset:\s*2px;/s',
            $componentCss
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.admin-table-action-link\s*\{[^}]*color:/s',
            $componentCss
        );
        $this->assertStringNotContainsString(
            'backend-breadcrumb-item-active-link-color',
            file_get_contents(base_path('resources/css/admin/admin-layout.css'))
        );
    }

    public function test_backend_text_actions_use_the_shared_primary_color_class(): void
    {
        $viewFiles = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/views'))
        );
        $actionTags = 0;
        $actionGroups = 0;

        foreach ($viewFiles as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $blade = file_get_contents($file->getPathname());
            preg_match_all(
                '/<(?:a|button|span)\b[^>]*\badmin-(?:table-action-link|link-button|media-preview-link)\b[^>]*>/s',
                $blade,
                $matches
            );

            foreach ($matches[0] as $tag) {
                $actionTags++;
                $this->assertStringContainsString(
                    'admin-text-action',
                    $tag,
                    $file->getPathname()
                );
            }

            preg_match_all(
                '/<div\b[^>]*\badmin-table-actions\b[^>]*>(.*?)<\/div>/s',
                $blade,
                $groups
            );

            foreach ($groups[1] as $group) {
                $actionGroups++;
                $this->assertStringNotContainsString('&nbsp;', $group, $file->getPathname());
                $this->assertDoesNotMatchRegularExpression(
                    '/>\s*(?:\||\/|•|·)\s*</',
                    $group,
                    $file->getPathname()
                );
            }
        }

        $this->assertGreaterThan(20, $actionTags);
        $this->assertGreaterThan(3, $actionGroups);

        $actionMenu = file_get_contents(
            base_path('resources/views/components/admin-action-menu.blade.php')
        );
        $this->assertStringContainsString('admin-action-menu__trigger', $actionMenu);
        $this->assertStringContainsString('admin-action-menu__panel', $actionMenu);
        $this->assertStringContainsString('x-on:click.outside', $actionMenu);
        $this->assertStringContainsString('x-on:mouseenter="openMenu()"', $actionMenu);
        $this->assertStringContainsString('x-on:mouseleave="scheduleClose()"', $actionMenu);
        $this->assertStringContainsString("__('lf.table_more_actions')", $actionMenu);
        $this->assertStringContainsString('<circle cx="12" cy="5"', $actionMenu);
        $this->assertStringContainsString('<circle cx="12" cy="19"', $actionMenu);

        $actionIcon = file_get_contents(
            base_path('resources/views/components/admin-action-icon.blade.php')
        );
        $this->assertStringContainsString("@case('view')", $actionIcon);
        $this->assertStringContainsString("@case('edit')", $actionIcon);
        $this->assertStringContainsString("@case('delete')", $actionIcon);
        $this->assertStringContainsString("@case('remove')", $actionIcon);

        $componentCss = file_get_contents(base_path('resources/css/admin/admin-components.css'));
        $this->assertStringContainsString('.admin-table-has-actions > thead > tr > th:last-child', $componentCss);
        $this->assertStringContainsString('.admin-table-has-actions > tbody > tr:hover > td', $componentCss);
        $this->assertStringContainsString('.admin-action-menu__trigger:focus-visible', $componentCss);

        $pageCss = file_get_contents(base_path('resources/css/admin/admin-pages.css'));
        $this->assertMatchesRegularExpression(
            '/\.admin-table-actions\s*\{[^}]*display:\s*flex;[^}]*flex-wrap:\s*wrap;[^}]*gap:\s*12px;/s',
            $pageCss
        );
        $this->assertMatchesRegularExpression(
            '/\.authoring-media-actions \.admin-text-action\s*\{[^}]*flex:\s*0 0 auto;[^}]*white-space:\s*nowrap;/s',
            $pageCss
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.[\w-]+\s+\.admin-text-action\s*\{[^}]*(?:color|text-decoration)\s*:/s',
            $pageCss
        );

        $cssDocumentation = file_get_contents(base_path('docs/tech/LF-Tech-CSS.md'));

        $this->assertStringContainsString(
            'all backend text actions must use',
            strtolower($cssDocumentation)
        );
        $this->assertStringContainsString('color: var(--admin-primary);', $cssDocumentation);
        $this->assertStringContainsString('text-decoration: underline;', $cssDocumentation);
        $this->assertStringContainsString('outline: 2px solid var(--admin-primary);', $cssDocumentation);
        $this->assertStringContainsString('flex-wrap: wrap;', $cssDocumentation);
        $this->assertStringContainsString('gap: 12px;', $cssDocumentation);
        $this->assertStringContainsString('admin-primary-outline-action', $cssDocumentation);
        $this->assertStringContainsString('admin-table-has-actions', $cssDocumentation);
        $this->assertStringContainsString('x-admin-action-menu', $cssDocumentation);
    }

    public function test_backend_pagination_summary_uses_lf_translations(): void
    {
        app()->setLocale('vi');
        $this->assertSame(
            'Hiển thị 1–10 / 86 bản ghi',
            __('pagination.showing_results', ['from' => 1, 'to' => 10, 'total' => 86])
        );

        app()->setLocale('en');
        $this->assertSame(
            'Showing 1–10 of 86 records',
            __('pagination.showing_results', ['from' => 1, 'to' => 10, 'total' => 86])
        );

        $paginationView = file_get_contents(
            base_path('resources/views/vendor/pagination/tailwind.blade.php')
        );

        $this->assertStringContainsString('pagination.showing_results', $paginationView);
        $this->assertStringNotContainsString("{!! __('Showing') !!}", $paginationView);
        $this->assertStringNotContainsString("{!! __('to') !!}", $paginationView);
        $this->assertStringNotContainsString("{!! __('of') !!}", $paginationView);
        $this->assertStringNotContainsString("{!! __('results') !!}", $paginationView);
    }

    public function test_admin_tables_use_the_canonical_sticky_action_menu_contract(): void
    {
        $tableViews = [
            'resources/views/admin/users/index.blade.php',
            'resources/views/course-categories/index.blade.php',
            'resources/views/course-cohort-students/index.blade.php',
            'resources/views/course-cohorts/index.blade.php',
            'resources/views/course-cohorts/show.blade.php',
            'resources/views/course-cohorts/partials/tabs/teachers.blade.php',
            'resources/views/course-cohorts/partials/tabs/schedules.blade.php',
            'resources/views/course-cohorts/partials/tabs/sessions.blade.php',
            'resources/views/course-enrollments/index.blade.php',
            'resources/views/course-products/index.blade.php',
            'resources/views/course-products/edit.blade.php',
            'resources/views/course-template-teachers/partials/list.blade.php',
            'resources/views/course-templates/index.blade.php',
            'resources/views/course-templates/edit.blade.php',
            'resources/views/media-categories/index.blade.php',
            'resources/views/media-files/index.blade.php',
        ];

        foreach ($tableViews as $view) {
            $blade = file_get_contents(base_path($view));
            $this->assertStringContainsString('admin-table-has-actions', $blade, $view);
            $this->assertStringContainsString('<x-admin-action-menu', $blade, $view);
        }

        $componentCss = file_get_contents(base_path('resources/css/admin/admin-components.css'));
        $this->assertMatchesRegularExpression(
            '/\.lf-admin-page \.admin-table-wrap \.table > thead > tr > th\s*\{[^}]*white-space:\s*nowrap;/s',
            $componentCss
        );
        $this->assertMatchesRegularExpression(
            '/\.admin-table-has-actions > tbody > tr > td:last-child\s*\{[^}]*position:\s*sticky;[^}]*right:\s*0;/s',
            $componentCss
        );
        $this->assertStringContainsString('x-teleport="body"', file_get_contents(
            base_path('resources/views/components/admin-action-menu.blade.php')
        ));
    }

    public function test_backend_confirmations_use_the_shared_localized_dialog(): void
    {
        $layout = file_get_contents(base_path('resources/views/layouts/backend.blade.php'));
        $component = file_get_contents(base_path('resources/views/components/confirm-dialog.blade.php'));
        $script = file_get_contents(base_path('resources/js/app.js'));

        $this->assertStringContainsString('<x-confirm-dialog />', $layout);
        $this->assertStringContainsString('data-lf-confirm-dialog', $component);
        $this->assertStringContainsString('aria-labelledby=', $component);
        $this->assertStringContainsString('aria-describedby=', $component);
        $this->assertStringContainsString('window.LFConfirm', $script);
        $this->assertStringContainsString("document.addEventListener('submit'", $script);

        $bladeFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            base_path('resources/views'),
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($bladeFiles as $bladeFile) {
            if (! $bladeFile->isFile() || ! str_ends_with($bladeFile->getFilename(), '.blade.php')) {
                continue;
            }
            $blade = file_get_contents($bladeFile);
            $this->assertStringNotContainsString('window.confirm', $blade, $bladeFile->getPathname());
        }

        app()->setLocale('vi');
        $this->assertSame('Xác nhận thao tác', __('lf.LF_common_title_confirm_action'));
        $this->assertSame('Xác nhận', __('lf.LF_common_button_confirm'));

        app()->setLocale('en');
        $this->assertSame('Confirm action', __('lf.LF_common_title_confirm_action'));
        $this->assertSame('Confirm', __('lf.LF_common_button_confirm'));
    }

    public function test_backend_adaptive_form_markup_prioritizes_left_column(): void
    {
        $templateForm = file_get_contents(
            base_path('resources/views/course-templates/partials/form.blade.php')
        );
        $productForm = file_get_contents(
            base_path('resources/views/course-products/partials/form.blade.php')
        );

        $this->assertSame(1, substr_count($templateForm, 'class="admin-form-flow"'));
        $this->assertSame(5, substr_count($templateForm, 'class="admin-form-standard-section"'));
        $this->assertStringContainsString('aria-labelledby="course-template-basic"', $templateForm);
        $this->assertStringContainsString('aria-labelledby="course-template-description"', $templateForm);
        $this->assertStringContainsString('aria-labelledby="course-template-learning"', $templateForm);
        $this->assertStringContainsString('aria-labelledby="course-template-introduction"', $templateForm);
        $this->assertStringContainsString('aria-labelledby="course-template-display"', $templateForm);

        $this->assertSame(1, substr_count($productForm, 'class="admin-form-flow"'));
        $this->assertSame(5, substr_count($productForm, 'class="admin-form-standard-section"'));
        foreach ([
            'aria-labelledby="product-basic"',
            'aria-labelledby="product-description"',
            'aria-labelledby="product-config"',
            'aria-labelledby="product-pricing"',
            'aria-labelledby="product-availability"',
        ] as $sectionMarker) {
            $this->assertStringContainsString($sectionMarker, $productForm);
        }
        $this->assertStringNotContainsString('class="backend-form-column"', $productForm);
        $this->assertStringNotContainsString('course-product-form-grid', $productForm);
        $this->assertTrue(
            strpos($productForm, 'aria-labelledby="product-basic"')
                < strpos($productForm, 'aria-labelledby="product-pricing"')
        );
    }

    public function test_course_template_authoring_uses_full_available_content_width(): void
    {
        $editView = file_get_contents(
            base_path('resources/views/course-templates/edit.blade.php')
        );
        $adminPagesCss = file_get_contents(
            base_path('resources/css/admin/admin-pages.css')
        );

        $this->assertSame(1, substr_count($editView, 'class="course-template-authoring"'));
        $this->assertSame(1, substr_count($editView, 'class="course-template-tabs"'));
        $this->assertSame(5, substr_count($editView, 'class="course-template-tab-panel"'));
        $this->assertStringContainsString(
            '.course-template-authoring {'.PHP_EOL
                .'    width: 100%;'.PHP_EOL
                .'    min-width: 0;'.PHP_EOL
                .'}',
            $adminPagesCss
        );
        $this->assertStringNotContainsString(
            '.course-template-authoring {'.PHP_EOL
                .'    width: 100%;'.PHP_EOL
                .'    max-width: 960px;',
            $adminPagesCss
        );
        $this->assertStringContainsString(
            '.course-template-authoring > .course-template-tab-panel {'.PHP_EOL
                .'    width: 100%;'.PHP_EOL
                .'    min-width: 0;',
            $adminPagesCss
        );
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

    private function backendFormColumnHtml(string $html, int $index): string
    {
        $marker = '<div class="backend-form-column">';
        $offset = 0;
        $starts = [];

        while (($position = strpos($html, $marker, $offset)) !== false) {
            $starts[] = $position;
            $offset = $position + strlen($marker);
        }

        if (! isset($starts[$index])) {
            return '';
        }

        $start = $starts[$index] + strlen($marker);
        $end = $starts[$index + 1] ?? strlen($html);

        return substr($html, $start, $end - $start);
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
