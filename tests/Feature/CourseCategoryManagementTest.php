<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourseCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
            'media.disk' => 'media_local',
            'media.bucket' => 'lf-test-media',
            'media.region' => 'ap-southeast-1',
        ]);

        Storage::fake('media_local');
    }

    public function test_admin_and_teacher_can_view_their_tenant_category_list(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $this->createCategory($customerId, 'Admin Category', 'admin-category');
        $this->createCategory(
            $customerId,
            'Teacher Category',
            'teacher-category',
            createdBy: $teacher->id
        );
        $this->createCategory($otherCustomerId, 'Private Tenant Category', 'private-category');

        $adminResponse = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-categories')
            ->assertOk()
            ->assertSeeText('Admin Category')
            ->assertSeeText('Teacher Category')
            ->assertSeeText(__('lf.LF_navigation_menu_common_product_categories'))
            ->assertSee('mk-tablebar--filters', false)
            ->assertSee('lf-admin-ui', false)
            ->assertSee('mk-tablebar', false)
            ->assertSee('ui-caption', false)
            ->assertSee('mk-table-scroll', false)
            ->assertSee('ux-combo__control', false)
            ->assertSee('mk-table-foot', false)
            ->assertDontSee('ui-list-', false)
            ->assertSee('ux-row-edit', false)
            ->assertDontSeeText(__('lf.LF_course_category_index_sort_order'))
            ->assertDontSee('class="admin-table-action-icon"', false)
            ->assertDontSeeText(__('lf.LF_course_category_common_thumbnail_image'))
            ->assertDontSeeText('Private Tenant Category');
        $this->assertSame(
            __('lf.table_no'),
            $this->tableHeaderText($adminResponse->getContent(), 1)
        );
        $this->assertSame(
            __('lf.LF_course_category_common_name'),
            $this->tableHeaderText($adminResponse->getContent(), 2)
        );
        $this->assertSame(
            __('lf.LF_course_category_common_parent'),
            $this->tableHeaderText($adminResponse->getContent(), 3)
        );
        $this->assertSame(
            __('lf.LF_course_category_index_product_count'),
            $this->tableHeaderText($adminResponse->getContent(), 4)
        );
        $this->assertSame(6, preg_match_all('/<th\b/', $adminResponse->getContent()));
        $this->assertMatchesRegularExpression(
            '/<th\b[^>]*\bclass="(?:[^" ]+ )*mk-sequence(?: [^"]*)?"/',
            $adminResponse->getContent()
        );
        $adminResponse->assertSee('mk-text-cell mk-na', false)
            ->assertSee('data-ui-live-filter', false)
            ->assertSee('data-ui-live-region="table"', false)
            ->assertSee('data-ui-live-region="foot"', false)
            ->assertSee('data-ui-live-region="clear-filters"', false)
            ->assertSee('requestSubmit()', false);
        $css = file_get_contents(resource_path('css/admin/admin-ui-kit.css'));
        $this->assertStringContainsString(
            '.lf-admin-ui .mk-text-cell',
            $css
        );

        $teacherResponse = $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher/course-categories')
            ->assertOk()
            ->assertSeeText('Teacher Category')
            ->assertSeeText(__('lf.LF_navigation_menu_common_product_categories'))
            ->assertDontSeeText(__('lf.LF_course_category_common_thumbnail_image'))
            ->assertDontSeeText('Admin Category')
            ->assertDontSeeText('Private Tenant Category');
        $this->assertSame(
            __('lf.table_no'),
            $this->tableHeaderText($teacherResponse->getContent(), 1)
        );
        $this->assertSame(
            __('lf.LF_course_category_common_name'),
            $this->tableHeaderText($teacherResponse->getContent(), 2)
        );
        $this->assertSame(
            __('lf.LF_course_category_common_parent'),
            $this->tableHeaderText($teacherResponse->getContent(), 3)
        );
    }

    public function test_category_index_shows_tenant_scoped_product_count_and_status_switch(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $withProducts = $this->createCategory($customerId, 'Counted Category', 'counted-category');
        $this->createCategory($customerId, 'Paused Category', 'paused-category', status: 'inactive');

        $this->createProduct($customerId, $withProducts, 'counted-1');
        $this->createProduct($customerId, $withProducts, 'counted-2');
        $this->createProduct($otherCustomerId, $withProducts, 'foreign-1');

        $html = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-categories')
            ->assertOk()
            ->getContent();

        $xpath = new \DOMXPath(tap(new \DOMDocument, fn ($dom) => @$dom->loadHTML($html)));
        $counts = [];
        $statuses = [];
        foreach ($xpath->query('//table/tbody/tr') as $row) {
            $name = trim($xpath->query('./td[2]/span', $row)->item(0)->textContent);
            $counts[$name] = trim($xpath->query('./td[4]', $row)->item(0)->textContent);
            $switch = $xpath->query('./td[5]/form/button[@role="switch"]', $row)->item(0);
            $statuses[$name] = [
                $switch->getAttribute('class'),
                $switch->getAttribute('aria-checked'),
                $xpath->query('./td[5]/form', $row)->item(0)->getAttribute('action'),
            ];
        }

        $this->assertSame(['Paused Category' => '0', 'Counted Category' => '2'], $counts);
        $this->assertSame('ux-row-switch cursor-interaction', $statuses['Counted Category'][0]);
        $this->assertSame('true', $statuses['Counted Category'][1]);
        $this->assertSame('false', $statuses['Paused Category'][1]);
        $this->assertSame(
            route('admin.course-categories.toggle-status', $withProducts),
            $statuses['Counted Category'][2]
        );
    }

    public function test_category_index_opens_create_and_edit_in_one_two_column_dialog(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'Dialog Category', 'dialog-category');

        $content = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-categories')
            ->assertOk()
            ->assertSee('data-ui-dialog-form-scope', false)
            ->assertSee('data-columns="2"', false)
            ->assertSee('resources/js/admin-ui-kit-dialog-form.js', false)
            ->assertSee('data-msg-required-input="'.e(__('lf.LF_course_category_dialog_required_input')).'"', false)
            ->assertSee('data-msg-required-select="'.e(__('lf.LF_course_category_dialog_required_select')).'"', false)
            ->assertSee('M18.375 2.625a1 1 0 0 1 3 3', false)
            ->getContent();

        $this->assertSame(1, $this->htmlElementCount($content, '//dialog[@data-ui-dialog-form]'));
        $this->assertEqualsCanonicalizing(
            ['https://tenant-a.localhost/admin/course-categories/create', "https://tenant-a.localhost/admin/course-categories/{$categoryId}/edit"],
            collect((new \DOMXPath(tap(new \DOMDocument, fn ($dom) => @$dom->loadHTML($content))))->query('//button[@data-ui-dialog-form-open]'))
                ->map(fn ($node) => $node->getAttribute('data-url'))
                ->all()
        );
        $this->assertSame(
            0,
            $this->htmlElementCount($content, '//a[contains(@href, "/course-categories/create") or contains(@href, "/edit")]')
        );
    }

    public function test_category_index_reports_results_in_a_toast_region(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $content = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-categories')
            ->assertOk()
            ->assertDontSee('admin-alert-success', false)
            ->getContent();
        $this->assertSame(1, $this->htmlElementCount($content, '//div[@data-ui-toast-region]'));
        $this->assertSame(
            1,
            $this->htmlElementCount($content, '//div[@data-ui-toast-region]/div[@data-ui-dialog-form-flash and @data-ui-toast-timeout="4000" and @hidden]')
        );
        $this->assertSame(
            1,
            $this->htmlElementCount($content, '//div[@data-ui-toast-region]/div[@data-ui-dialog-form-page-error and @data-ui-toast-timeout="0" and @hidden]')
        );

        $flashed = $this->actingAs($admin)
            ->withSession(['success' => 'Saved from a redirect'])
            ->get('https://tenant-a.localhost/admin/course-categories')
            ->getContent();
        $this->assertSame(
            1,
            $this->htmlElementCount($flashed, '//div[@data-ui-dialog-form-flash and not(@hidden)]/span[@data-ui-toast-text and contains(., "Saved from a redirect")]')
        );
    }

    public function test_empty_category_can_be_deleted_and_its_images_are_detached(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'Disposable', 'disposable');
        $otherId = $this->createCategory($customerId, 'Keeper', 'keeper');

        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-categories/{$categoryId}", $this->validCategoryData([
                'name' => 'Disposable',
                'thumbnail_image_file' => UploadedFile::fake()->image('thumb.png', 120, 120),
            ]))
            ->assertRedirect();
        $this->assertSame(1, DB::table('media_file_usages')->where('owner_type', 'course_category')->where('owner_id', $categoryId)->where('status', 'active')->count());

        $this->actingAs($admin)
            ->deleteJson("https://tenant-a.localhost/admin/course-categories/{$categoryId}")
            ->assertOk()
            ->assertJson(['message' => __('lf.LF_course_category_common_deleted')]);

        $this->assertNull(DB::table('core_course_categories')->where('id', $categoryId)->first());
        $this->assertNotNull(DB::table('core_course_categories')->where('id', $otherId)->first());
        $this->assertSame(0, DB::table('media_file_usages')->where('owner_type', 'course_category')->where('owner_id', $categoryId)->where('status', 'active')->count());
    }

    public function test_category_with_products_templates_or_children_cannot_be_deleted(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $withProduct = $this->createCategory($customerId, 'Has product', 'has-product');
        $withTemplate = $this->createCategory($customerId, 'Has template', 'has-template');
        $withChild = $this->createCategory($customerId, 'Has child', 'has-child');
        $this->createCategory($customerId, 'Child', 'child', parentId: $withChild);
        $this->createProduct($customerId, $withProduct, 'product-a');
        $this->createProduct($customerId, $withProduct, 'product-b');
        DB::table('core_course_templates')->insert([
            'customer_id' => $customerId, 'category_id' => $withTemplate, 'title' => 'T',
            'estimated_minutes_per_lesson' => 0, 'lesson_count' => 0, 'working_revision' => 1,
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([
            [$withProduct, 'products', 2],
            [$withTemplate, 'templates', 1],
            [$withChild, 'children', 1],
        ] as [$id, $reason, $count]) {
            $this->actingAs($admin)
                ->deleteJson("https://tenant-a.localhost/admin/course-categories/{$id}")
                ->assertStatus(409)
                ->assertJson(['message' => __('lf.LF_course_category_delete_blocked_'.$reason, ['count' => $count])]);
            $this->assertNotNull(DB::table('core_course_categories')->where('id', $id)->first(), $reason);
        }
    }

    public function test_products_of_another_tenant_do_not_block_and_other_tenants_categories_are_not_deletable(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $mine = $this->createCategory($customerId, 'Mine', 'mine');
        $theirs = $this->createCategory($otherCustomerId, 'Theirs', 'theirs');

        $this->actingAs($admin)
            ->deleteJson("https://tenant-a.localhost/admin/course-categories/{$theirs}")
            ->assertNotFound();
        $this->assertNotNull(DB::table('core_course_categories')->where('id', $theirs)->first());

        $this->actingAs($admin)
            ->deleteJson("https://tenant-a.localhost/admin/course-categories/{$mine}")
            ->assertOk();
    }

    public function test_teacher_can_delete_only_their_own_empty_category_and_guests_cannot_delete(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $admin = $this->createUser($customerId, 'customer_admin');
        $own = $this->createCategory($customerId, 'Own', 'own', createdBy: $teacher->id);
        $others = $this->createCategory($customerId, 'Others', 'others', createdBy: $admin->id);

        $this->deleteJson("https://tenant-a.localhost/teacher/course-categories/{$own}")->assertUnauthorized();
        $this->actingAs($teacher)
            ->deleteJson("https://tenant-a.localhost/teacher/course-categories/{$others}")
            ->assertForbidden();
        $this->assertNotNull(DB::table('core_course_categories')->where('id', $others)->first());
        $this->actingAs($teacher)
            ->deleteJson("https://tenant-a.localhost/teacher/course-categories/{$own}")
            ->assertOk();
        $this->assertNull(DB::table('core_course_categories')->where('id', $own)->first());
    }

    public function test_non_json_delete_redirects_with_a_message_or_the_reason(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $free = $this->createCategory($customerId, 'Free', 'free');
        $used = $this->createCategory($customerId, 'Used', 'used');
        $this->createProduct($customerId, $used, 'p1');

        $this->actingAs($admin)
            ->delete("https://tenant-a.localhost/admin/course-categories/{$free}")
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories')
            ->assertSessionHas('success', __('lf.LF_course_category_common_deleted'));
        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-categories')
            ->delete("https://tenant-a.localhost/admin/course-categories/{$used}")
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories')
            ->assertSessionHasErrors(['category']);
        $this->assertNotNull(DB::table('core_course_categories')->where('id', $used)->first());
    }

    public function test_index_enables_delete_only_for_unused_categories_and_explains_why_not(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $free = $this->createCategory($customerId, 'Free category', 'free-category');
        $used = $this->createCategory($customerId, 'Used category', 'used-category');
        $this->createProduct($customerId, $used, 'p1');

        $content = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-categories')
            ->assertOk()
            ->getContent();

        $this->assertSame(2, $this->htmlElementCount($content, '//button[@data-ui-row-delete]'));
        $this->assertSame(
            1,
            $this->htmlElementCount($content, '//button[@data-ui-row-delete and not(@disabled) and @data-url="https://tenant-a.localhost/admin/course-categories/'.$free.'"]')
        );
        $this->assertSame(
            1,
            $this->htmlElementCount($content, '//button[@data-ui-row-delete and @disabled and @aria-disabled="true" and contains(@data-tooltip, "1 ") and not(@title)]')
        );
        $this->assertStringContainsString(__('lf.LF_course_category_delete_blocked_products', ['count' => 1]), $content);
        $this->assertStringContainsString('data-csrf=', $content);
        $this->assertStringContainsString('resources/js/admin-ui-kit-row-actions.js', $content);
    }

    public function test_featured_categories_show_a_star_with_an_accessible_name_instead_of_caption_text(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->createCategory($customerId, 'Plain category', 'plain-category');
        $featuredId = $this->createCategory($customerId, 'Starred category', 'starred-category');
        DB::table('core_course_categories')->where('id', $featuredId)->update(['is_featured' => true]);

        $content = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-categories')
            ->assertOk()
            ->getContent();

        $label = __('lf.LF_course_category_common_featured');
        $this->assertSame(1, $this->htmlElementCount($content, '//span[contains(concat(" ", normalize-space(@class), " "), " mk-featured ")]'));
        $this->assertSame(
            1,
            $this->htmlElementCount(
                $content,
                '//span[contains(@class, "mk-name-line")][span[contains(@class, "mk-name-text") and normalize-space(.)="Starred category"]]'
                .'/span[contains(@class, "mk-featured") and @title="'.$label.'"][svg[@aria-hidden="true"]][span[contains(@class, "ui-sr-only") and normalize-space(.)="'.$label.'"]]'
            )
        );
        $this->assertSame(
            0,
            $this->htmlElementCount($content, '//span[@class="ui-caption" and normalize-space(.)="'.$label.'"]'),
            'the featured label is no longer plain caption text next to the name'
        );
    }

    public function test_category_index_paginates_ten_records_with_a_continuous_sequence_column(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        foreach (range(1, 11) as $number) {
            $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $this->createCategory(
                $customerId,
                "Paginated Category {$suffix}",
                "paginated-category-{$suffix}"
            );
        }

        $firstPage = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-categories')
            ->assertOk()
            ->assertSeeText('Paginated Category 11')
            ->assertSeeText('Paginated Category 10')
            ->assertDontSeeText('Paginated Category 01')
            ->assertSeeTextInOrder(['Paginated Category 11', 'Paginated Category 10']);

        $secondPage = $this->get('https://tenant-a.localhost/admin/course-categories?page=2')
            ->assertOk()
            ->assertSeeText('Paginated Category 01')
            ->assertDontSeeText('Paginated Category 11');

        $filteredPage = $this->get('https://tenant-a.localhost/admin/course-categories?keyword=Paginated&status=active')
            ->assertOk();
        $filteredDocument = new \DOMDocument;
        @$filteredDocument->loadHTML($filteredPage->getContent());
        $nextLink = (new \DOMXPath($filteredDocument))->query('//a[@rel="next"]')->item(0);
        $this->assertNotNull($nextLink);
        parse_str(parse_url($nextLink->getAttribute('href'), PHP_URL_QUERY), $nextQuery);
        $this->assertSame(['keyword' => 'Paginated', 'status' => 'active', 'page' => '2'], $nextQuery);

        $firstPageDocument = new \DOMDocument;
        @$firstPageDocument->loadHTML($firstPage->getContent());
        $firstPageSequence = (new \DOMXPath($firstPageDocument))
            ->query('//tbody/tr/td[contains(@class, "mk-sequence")]');
        $this->assertCount(10, $firstPageSequence);
        $this->assertSame('10', trim($firstPageSequence->item(9)->textContent));

        $secondPageDocument = new \DOMDocument;
        @$secondPageDocument->loadHTML($secondPage->getContent());
        $secondPageSequence = (new \DOMXPath($secondPageDocument))
            ->query('//tbody/tr/td[contains(@class, "mk-sequence")]');
        $this->assertCount(1, $secondPageSequence);
        $this->assertSame('11', trim($secondPageSequence->item(0)->textContent));
    }

    public function test_category_index_uses_standard_empty_states_for_default_and_filtered_lists(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-categories')
            ->assertOk()
            ->assertSee('mk-empty', false)
            ->assertSeeText(__('lf.LF_course_category_common_empty'))
            ->assertSeeText(__('lf.LF_course_category_empty_help'))
            ->assertSeeText(__('lf.LF_course_category_common_create_short'));

        $this->createCategory($customerId, 'Existing Category', 'existing-category');

        $filtered = $this->get('https://tenant-a.localhost/admin/course-categories?keyword=missing')
            ->assertOk()
            ->assertSee('mk-empty', false)
            ->assertSeeText(__('lf.LF_course_category_filter_empty'))
            ->assertSeeText(__('lf.LF_course_category_filter_empty_help'))
            ->assertSeeText(__('lf.LF_course_category_common_clear_filters'))
            ->assertDontSeeText(__('lf.LF_course_category_common_empty'));
        $this->assertSame(
            1,
            substr_count($filtered->getContent(), '>'.__('lf.LF_course_category_common_clear_filters').'<')
            + substr_count(preg_replace('/\s+/', ' ', $filtered->getContent()), '> '.__('lf.LF_course_category_common_clear_filters').' <'),
            'Clear-filters appears once; the empty state does not repeat it.'
        );
    }

    public function test_admin_can_create_a_category_with_documented_fields(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-categories', $this->validCategoryData([
                'name' => 'Korean',
                'slug' => 'korean',
                'description' => 'Korean courses',
                'thumbnail_image' => '/images/korean-thumbnail.jpg',
                'banner_image' => '/images/korean-banner.jpg',
                'sort_order' => 10,
                'is_featured' => 1,
                'meta_title' => 'Learn Korean',
                'meta_description' => 'Korean course category',
                'meta_keywords' => 'korean,language',
            ]))
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $this->assertDatabaseHas('core_course_categories', [
            'customer_id' => $customerId,
            'parent_id' => null,
            'name' => 'Korean',
            'slug' => 'korean',
            'description' => 'Korean courses',
            'thumbnail_image' => '/images/korean-thumbnail.jpg',
            'banner_image' => '/images/korean-banner.jpg',
            'sort_order' => 1,
            'is_featured' => 1,
            'meta_title' => 'Learn Korean',
            'meta_description' => 'Korean course category',
            'meta_keywords' => 'korean,language',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
    }

    public function test_category_forms_do_not_render_manual_seo_controls(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $categoryId = $this->createCategory(
            $customerId,
            'Teacher Category',
            'teacher-category',
            createdBy: $teacher->id
        );

        $fragments = [
            'create' => $this->actingAs($admin)
                ->withHeader('X-Requested-With', 'XMLHttpRequest')
                ->get('https://tenant-a.localhost/admin/course-categories/create')
                ->assertOk(),
            'edit' => $this->actingAs($teacher)
                ->withHeader('X-Requested-With', 'XMLHttpRequest')
                ->get("https://tenant-a.localhost/teacher/course-categories/{$categoryId}/edit")
                ->assertOk(),
        ];

        foreach ($fragments as $mode => $response) {
            $content = $response->getContent();
            $count = fn (string $xpath) => $this->htmlElementCount($content, $xpath);
            $hasClass = fn (string $class) => 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';

            $this->assertSame(1, $count('//form['.$hasClass('ui-modal__form').']'), $mode);
            $this->assertSame(1, $count('//form[@novalidate]'), 'required fields are reported inline, not by the browser bubble');
            $this->assertSame(1, $count('//input[@name="name" and @required]'));
            $this->assertSame(1, $count('//form['.$hasClass('ui-modal__form').']/header['.$hasClass('ui-modal__header').']'));
            $this->assertSame(1, $count('//form['.$hasClass('ui-modal__form').']/div['.$hasClass('ui-modal__body').']'));
            $this->assertSame(1, $count('//form['.$hasClass('ui-modal__form').']/footer['.$hasClass('ui-modal__footer').']'));
            $this->assertSame(1, $count('//div['.$hasClass('ui-modal__body').']//div['.$hasClass('ui-modal__grid').']'));
            $this->assertSame(0, $count('//*['.$hasClass('ui-section').' or '.$hasClass('ui-form-flow').']'));
            $this->assertSame(2, $count('//button[@data-ui-dialog-form-cancel]'));
            $this->assertSame(1, $count('//button[@type="submit" and @data-ui-dialog-form-submit]'));
            $this->assertSame(1, $count('//dialog'), 'only the image preview dialog: unsaved changes use the shared LF confirm dialog');
            $this->assertSame(1, $count('//input[@name="_method" and @value="PUT"]') + ($mode === 'create' ? 1 : 0));

            foreach (['parent_id', 'name', 'slug', 'description', 'status'] as $field) {
                $this->assertSame(1, $count('//*[@id="category-form-'.$field.'"]'), $field);
            }
            $this->assertSame(0, $count('//*[@id="status" or @id="name" or @id="parent_id"]'), 'ids must not collide with list filters');
            $this->assertStringContainsString('ui-check ui-switch', $content);
            $this->assertStringContainsString('name="is_featured"', $content);
            $this->assertSame(2, $count('//select[@data-ui-kit-select]'));
            $this->assertStringContainsString('name="slug"', $content);
            $this->assertStringContainsString('readonly', $content);
            $this->assertStringContainsString('x-model="selectedParentId"', $content);
            $this->assertStringNotContainsString('lf-form-control', $content);
            $this->assertStringContainsString(__('lf.LF_course_category_select_parent'), $content);
            foreach ([
                'LF_course_category_placeholder_name',
                'LF_course_category_placeholder_slug',
                'LF_course_category_placeholder_description',
            ] as $translation) {
                $this->assertStringContainsString('placeholder="'.__('lf.'.$translation).'"', $content);
            }
            $this->assertStringNotContainsString('name="sort_order"', $content);
            $this->assertStringNotContainsString('id="sort_order"', $content);
            $this->assertManualSeoControlsNotRendered(
                $content,
                'course-category-seo-title',
                'LF_course_category'
            );
        }

        $this->assertStringContainsString(
            __('lf.LF_common_button_save_changes'),
            $fragments['edit']->getContent()
        );
        $this->assertStringContainsString(
            "/teacher/course-categories/{$categoryId}",
            $fragments['edit']->getContent()
        );
    }

    public function test_category_form_fragments_are_only_served_to_ajax_requests(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'Existing', 'existing');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-categories/create')
            ->assertNotFound();
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-categories/{$categoryId}/edit")
            ->assertNotFound();
    }

    public function test_json_save_reports_validation_errors_per_field_and_success_messages(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->postJson('https://tenant-a.localhost/admin/course-categories', $this->validCategoryData(['name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
        $this->assertSame(0, DB::table('core_course_categories')->where('customer_id', $customerId)->count());

        $this->actingAs($admin)
            ->postJson('https://tenant-a.localhost/admin/course-categories', $this->validCategoryData(['name' => 'Saved via dialog']))
            ->assertCreated()
            ->assertJson(['message' => __('lf.LF_course_category_common_created')]);

        $categoryId = (int) DB::table('core_course_categories')->where('name', 'Saved via dialog')->value('id');
        $this->actingAs($admin)
            ->putJson("https://tenant-a.localhost/admin/course-categories/{$categoryId}", $this->validCategoryData(['name' => 'Renamed via dialog']))
            ->assertOk()
            ->assertJson(['message' => __('lf.LF_course_category_common_updated')]);
        $this->assertSame('Renamed via dialog', DB::table('core_course_categories')->where('id', $categoryId)->value('name'));
    }

    public function test_create_order_is_server_assigned_and_ignores_forged_input(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $existingId = $this->createCategory($customerId, 'Existing', 'existing');
        DB::table('core_course_categories')->where('id', $existingId)->update(['sort_order' => 8]);

        $this->actingAs($admin)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')->get('https://tenant-a.localhost/admin/course-categories/create')
            ->assertOk()
            ->assertDontSee('name="sort_order"', false)
            ->assertDontSee('id="sort_order"', false);

        $this->post(
            'https://tenant-a.localhost/admin/course-categories',
            $this->validCategoryData(['name' => 'Next Category', 'sort_order' => 1])
        )->assertRedirect();

        $this->assertSame(
            9,
            (int) DB::table('core_course_categories')
                ->where('customer_id', $customerId)
                ->where('name', 'Next Category')
                ->value('sort_order')
        );
    }

    public function test_category_update_without_manual_seo_inputs_preserves_existing_seo_data(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'SEO Category', 'seo-category');

        DB::table('core_course_categories')
            ->where('id', $categoryId)
            ->update([
                'meta_title' => 'Legacy SEO Title',
                'meta_description' => 'Legacy SEO description',
                'meta_keywords' => 'legacy,seo',
            ]);

        $data = $this->validCategoryData([
            'name' => 'SEO Category Updated',
            'slug' => 'seo-category-updated',
        ]);
        unset($data['meta_title'], $data['meta_description'], $data['meta_keywords']);

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-categories/{$categoryId}",
                $data
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $this->assertDatabaseHas('core_course_categories', [
            'id' => $categoryId,
            'customer_id' => $customerId,
            'name' => 'SEO Category Updated',
            'slug' => 'seo-category-updated',
            'meta_title' => 'Legacy SEO Title',
            'meta_description' => 'Legacy SEO description',
            'meta_keywords' => 'legacy,seo',
        ]);
    }

    public function test_teacher_can_create_a_child_category(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $parentId = $this->createCategory($customerId, 'Korean', 'korean');

        $this->actingAs($teacher)
            ->post('https://tenant-a.localhost/teacher/course-categories', $this->validCategoryData([
                'parent_id' => $parentId,
                'name' => 'TOPIK',
                'slug' => 'topik',
            ]))
            ->assertRedirect('https://tenant-a.localhost/teacher/course-categories');

        $this->assertDatabaseHas('core_course_categories', [
            'customer_id' => $customerId,
            'parent_id' => $parentId,
            'name' => 'TOPIK',
            'slug' => 'topik',
            'created_by' => $teacher->id,
        ]);
    }

    public function test_customer_admin_can_upload_category_thumbnail(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-categories', $this->validCategoryData([
                'name' => 'Media Korean',
                'slug' => 'media-korean',
                'thumbnail_image_file' => UploadedFile::fake()->image(
                    'category-thumbnail.png',
                    120,
                    120
                ),
            ]))
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $categoryId = (int) DB::table('core_course_categories')
            ->where('customer_id', $customerId)
            ->where('slug', 'media-korean')
            ->value('id');

        $mediaFile = $this->assertActiveMediaUsage(
            $customerId,
            $categoryId,
            'thumbnail'
        );

        $this->assertStringStartsWith(
            "tenants/{$customerId}/course/categories/{$categoryId}/thumbnail/",
            $mediaFile->storage_key
        );
        Storage::disk('media_local')->assertExists($mediaFile->storage_key);
    }

    public function test_customer_admin_can_upload_category_banner(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'Media Korean', 'media-korean');

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-categories/{$categoryId}",
                $this->validCategoryData([
                    'name' => 'Media Korean',
                    'slug' => 'media-korean',
                    'banner_image_file' => UploadedFile::fake()->image(
                        'category-banner.jpg',
                        1200,
                        320
                    ),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $mediaFile = $this->assertActiveMediaUsage(
            $customerId,
            $categoryId,
            'banner_image'
        );

        $this->assertStringStartsWith(
            "tenants/{$customerId}/course/categories/{$categoryId}/banner/",
            $mediaFile->storage_key
        );
        Storage::disk('media_local')->assertExists($mediaFile->storage_key);
    }

    public function test_category_thumbnail_upload_reuses_existing_media_file(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-categories', $this->validCategoryData([
                'name' => 'Thumbnail Korean',
                'slug' => 'thumbnail-korean',
                'thumbnail_image_file' => $this->uploadedPngFile('thumbnail-a.png'),
            ]))
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-categories', $this->validCategoryData([
                'name' => 'Thumbnail Japanese',
                'slug' => 'thumbnail-japanese',
                'thumbnail_image_file' => $this->uploadedPngFile('thumbnail-b.png'),
            ]))
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $mediaFile = DB::table('media_files')->first();

        $this->assertNotNull($mediaFile);
        $this->assertSame(1, DB::table('media_files')->count());
        $this->assertSame(
            2,
            DB::table('media_file_usages')
                ->where('customer_id', $customerId)
                ->where('media_file_id', $mediaFile->id)
                ->where('owner_type', 'course_category')
                ->where('usage_type', 'thumbnail')
                ->where('status', 'active')
                ->count()
        );
        Storage::disk('media_local')->assertExists($mediaFile->storage_key);
    }

    public function test_category_banner_upload_reuses_existing_media_file(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $firstCategoryId = $this->createCategory($customerId, 'Banner Korean', 'banner-korean');
        $secondCategoryId = $this->createCategory($customerId, 'Banner Japanese', 'banner-japanese');

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-categories/{$firstCategoryId}",
                $this->validCategoryData([
                    'name' => 'Banner Korean',
                    'slug' => 'banner-korean',
                    'banner_image_file' => $this->uploadedPngFile('banner-a.png'),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-categories/{$secondCategoryId}",
                $this->validCategoryData([
                    'name' => 'Banner Japanese',
                    'slug' => 'banner-japanese',
                    'banner_image_file' => $this->uploadedPngFile('banner-b.png'),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $mediaFile = DB::table('media_files')->first();

        $this->assertNotNull($mediaFile);
        $this->assertSame(1, DB::table('media_files')->count());
        $this->assertSame(
            2,
            DB::table('media_file_usages')
                ->where('customer_id', $customerId)
                ->where('media_file_id', $mediaFile->id)
                ->where('owner_type', 'course_category')
                ->where('usage_type', 'banner_image')
                ->where('status', 'active')
                ->count()
        );
        Storage::disk('media_local')->assertExists($mediaFile->storage_key);
    }

    public function test_teacher_can_upload_images_for_authorized_category(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $categoryId = $this->createCategory(
            $customerId,
            'Teacher Category',
            'teacher-category',
            createdBy: $teacher->id
        );

        $this->actingAs($teacher)
            ->put(
                "https://tenant-a.localhost/teacher/course-categories/{$categoryId}",
                $this->validCategoryData([
                    'name' => 'Teacher Category',
                    'slug' => 'teacher-category',
                    'thumbnail_image_file' => UploadedFile::fake()->image(
                        'teacher-thumbnail.png',
                        120,
                        120
                    ),
                    'banner_image_file' => UploadedFile::fake()->image(
                        'teacher-banner.jpg',
                        1200,
                        320
                    ),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/teacher/course-categories');

        $this->assertActiveMediaUsage($customerId, $categoryId, 'thumbnail');
        $this->assertActiveMediaUsage($customerId, $categoryId, 'banner_image');
    }

    public function test_unauthorized_teacher_receives_403_for_category_image_upload(): void
    {
        $customerId = $this->createTenant();
        $owner = $this->createUser($customerId, 'teacher');
        $teacher = $this->createUser($customerId, 'teacher');
        $categoryId = $this->createCategory(
            $customerId,
            'Owner Category',
            'owner-category',
            createdBy: $owner->id
        );

        $this->actingAs($teacher)
            ->put(
                "https://tenant-a.localhost/teacher/course-categories/{$categoryId}",
                $this->validCategoryData([
                    'name' => 'Owner Category',
                    'slug' => 'owner-category',
                    'thumbnail_image_file' => UploadedFile::fake()->image(
                        'blocked-thumbnail.png',
                        120,
                        120
                    ),
                ])
            )
            ->assertForbidden();

        $this->assertDatabaseMissing('media_file_usages', [
            'customer_id' => $customerId,
            'owner_type' => 'course_category',
            'owner_id' => $categoryId,
            'usage_type' => 'thumbnail',
        ]);
    }

    public function test_category_validation_rejects_invalid_data(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-categories/create')
            ->post('https://tenant-a.localhost/admin/course-categories', [
                'name' => '',
                'sort_order' => 'not-an-integer',
                'is_featured' => 'not-a-boolean',
                'status' => 'archived',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories/create')
            ->assertSessionHasErrors([
                'name',
                'is_featured',
                'status',
            ]);

        $this->assertDatabaseCount('core_course_categories', 0);
    }

    public function test_queries_and_parent_selection_are_tenant_isolated(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $ownCategoryId = $this->createCategory($customerId, 'Own Category', 'own-category');
        $otherCategoryId = $this->createCategory(
            $otherCustomerId,
            'Other Category',
            'other-category',
            status: 'inactive'
        );

        $this->actingAs($admin)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')->withHeader('X-Requested-With', 'XMLHttpRequest')->get("https://tenant-a.localhost/admin/course-categories/{$otherCategoryId}/edit")
            ->assertNotFound();

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-categories/{$otherCategoryId}",
                $this->validCategoryData(['name' => 'Changed', 'slug' => 'changed'])
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-categories/{$otherCategoryId}/toggle-status")
            ->assertNotFound();

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-categories', $this->validCategoryData([
                'parent_id' => $otherCategoryId,
                'name' => 'Invalid Child',
                'slug' => 'invalid-child',
            ]))
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseHas('core_course_categories', [
            'id' => $ownCategoryId,
            'customer_id' => $customerId,
            'name' => 'Own Category',
        ]);
        $this->assertDatabaseHas('core_course_categories', [
            'id' => $otherCategoryId,
            'customer_id' => $otherCustomerId,
            'name' => 'Other Category',
            'status' => 'inactive',
        ]);
        $this->assertDatabaseMissing('core_course_categories', [
            'customer_id' => $customerId,
            'slug' => 'invalid-child',
        ]);
    }

    public function test_slug_is_unique_within_a_tenant_but_reusable_by_another_tenant(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $this->createCategory($customerId, 'Korean', 'korean');

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-categories', $this->validCategoryData([
                'name' => 'Korean',
            ]))
            ->assertSessionHasErrors('slug');

        $this->actingAs($otherAdmin)
            ->post('https://tenant-b.localhost/admin/course-categories', $this->validCategoryData([
                'name' => 'Korean',
            ]))
            ->assertRedirect('https://tenant-b.localhost/admin/course-categories');

        $this->assertDatabaseHas('core_course_categories', [
            'customer_id' => $otherCustomerId,
            'slug' => 'korean',
        ]);
    }

    public function test_category_can_be_updated_and_deactivated_without_crossing_tenants(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'Korean', 'korean');

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-categories/{$categoryId}",
                $this->validCategoryData([
                    'name' => 'Korean Language',
                    'slug' => 'korean-language',
                    'status' => 'active',
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-categories/{$categoryId}/toggle-status")
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_categories', [
            'id' => $categoryId,
            'customer_id' => $customerId,
            'name' => 'Korean Language',
            'slug' => 'korean-language',
            'status' => 'inactive',
        ]);
    }

    public function test_category_hierarchy_cannot_be_made_circular(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $parentId = $this->createCategory($customerId, 'Korean', 'korean');
        $childId = $this->createCategory($customerId, 'TOPIK', 'topik', $parentId);

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-categories/{$parentId}",
                $this->validCategoryData([
                    'parent_id' => $childId,
                    'name' => 'Korean',
                    'slug' => 'korean',
                ])
            )
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseHas('core_course_categories', [
            'id' => $parentId,
            'parent_id' => null,
        ]);
    }

    public function test_guest_and_student_cannot_access_category_management(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');

        $this->get('https://tenant-a.localhost/admin/course-categories')
            ->assertRedirect('https://tenant-a.localhost/login');

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/admin/course-categories')
            ->assertForbidden();

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/teacher/course-categories')
            ->assertForbidden();
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

    private function createProduct(int $customerId, int $categoryId, string $code): int
    {
        return DB::table('core_course_products')->insertGetId([
            'customer_id' => $customerId,
            'category_id' => $categoryId,
            'product_code' => $code,
            'product_type' => 'course',
            'title' => $code,
            'slug' => $code,
            'thumbnail_type' => 'image',
            'price' => 0,
            'currency' => 'VND',
            'enrollment_type' => 'open',
            'visibility' => 'public',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function htmlElementCount(string $html, string $xpath): int
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return (new \DOMXPath($document))->query($xpath)->length;
    }

    private function tableHeaderText(string $html, int $position): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);

        $xpath = new \DOMXPath($dom);
        $header = $xpath->query("//table/thead/tr/th[{$position}]")->item(0);

        return trim((string) $header?->textContent);
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
        string $slug,
        ?int $parentId = null,
        string $status = 'active',
        ?int $createdBy = null
    ): int {
        return DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId,
            'parent_id' => $parentId,
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
            'status' => $status,
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validCategoryData(array $overrides = []): array
    {
        return array_merge([
            'parent_id' => null,
            'name' => 'Programming',
            'description' => null,
            'thumbnail_image' => null,
            'banner_image' => null,
            'sort_order' => 0,
            'is_featured' => 0,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'status' => 'active',
        ], $overrides);
    }

    private function assertActiveMediaUsage(
        int $customerId,
        int $categoryId,
        string $usageType
    ): object {
        $usage = DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('owner_type', 'course_category')
            ->where('owner_id', $categoryId)
            ->where('usage_type', $usageType)
            ->where('status', 'active')
            ->first();

        $this->assertNotNull($usage);

        return DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('id', $usage->media_file_id)
            ->first();
    }

    private function uploadedPngFile(string $filename): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $filename,
            base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
            )
        );
    }

    private function assertManualSeoControlsNotRendered(
        string $html,
        string $sectionTitleId,
        string $translationPrefix
    ): void {
        $this->assertStringNotContainsString($sectionTitleId, $html);
        $this->assertStringNotContainsString('name="meta_title"', $html);
        $this->assertStringNotContainsString('name="meta_description"', $html);
        $this->assertStringNotContainsString('name="meta_keywords"', $html);
        $this->assertStringNotContainsString(
            __('lf.'.$translationPrefix.'_group_seo'),
            $html
        );
        $this->assertStringNotContainsString(
            __('lf.'.$translationPrefix.'_common_meta_title'),
            $html
        );
        $this->assertStringNotContainsString(
            __('lf.'.$translationPrefix.'_common_meta_description'),
            $html
        );
        $this->assertStringNotContainsString(
            __('lf.'.$translationPrefix.'_common_meta_keywords'),
            $html
        );
    }

    private function backendColumnFieldNames(string $html, int $index): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);
        $columnPosition = $index + 1;
        $labels = $xpath->query(
            '(//div[contains(concat(" ", normalize-space(@class), " "),'
            .' " backend-form-column ")])['.$columnPosition.']//label[@for]'
        );
        $fields = [];

        foreach ($labels as $label) {
            $fields[] = $label->getAttribute('for');
        }

        return $fields;
    }

    private function fieldIsInsideBackendColumn(string $html, string $field): bool
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);
        $ancestors = $xpath->query(
            '//label[@for="'.$field.'"]/ancestor::div[contains(concat(" ", normalize-space(@class), " "),'
            .' " backend-form-column ")]'
        );

        return $ancestors->length > 0;
    }
}
