<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CourseProductManagementTest extends TestCase
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

        Carbon::setTestNow('2026-07-04 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_course_product_routes_exist_and_teacher_routes_do_not(): void
    {
        foreach ([
            'index',
            'create',
            'store',
            'edit',
            'update',
            'destroy',
        ] as $route) {
            $this->assertTrue(Route::has("admin.course-products.{$route}"));
            $this->assertFalse(Route::has("teacher.course-products.{$route}"));
        }

        foreach ([
            'store',
            'destroy',
        ] as $route) {
            $this->assertTrue(Route::has("admin.course-products.items.{$route}"));
            $this->assertFalse(Route::has("teacher.course-products.items.{$route}"));
            $this->assertTrue(Route::has("admin.course-products.relations.{$route}"));
            $this->assertFalse(Route::has("teacher.course-products.relations.{$route}"));
        }

        foreach ([
            'admin.course-product-items.index',
            'admin.course-products.items.index',
            'admin.course-product-relations.index',
            'admin.course-products.relations.index',
        ] as $route) {
            $this->assertFalse(Route::has($route));
        }
    }

    public function test_admin_can_list_only_their_tenant_products(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $this->createProduct(
            $otherCustomerId,
            'Private Tenant Product',
            'private-tenant-product'
        );

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-products')
            ->assertOk()
            ->assertSeeText('TOPIK Beginner')
            ->assertSeeText('Sản phẩm khóa học')
            ->assertDontSeeText('Private Tenant Product');
    }

    public function test_admin_can_create_a_product_with_documented_fields(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-products', $this->validProductData([
                'product_type' => 'single_course',
                'title' => 'TOPIK Beginner',
                'slug' => 'topik-beginner',
                'short_description' => 'TOPIK foundation',
                'description' => 'Detailed commercial product description.',
                'thumbnail_type' => 'video',
                'thumbnail_image' => '/images/topik.jpg',
                'thumbnail_video_source' => 'youtube',
                'thumbnail_video_url' => 'https://www.youtube.com/watch?v=example',
                'thumbnail_video_media_id' => 12,
                'price' => '299000.00',
                'sale_price' => '199000.00',
                'sale_starts_at' => '2026-07-01 00:00:00',
                'sale_ends_at' => '2026-07-15 23:59:59',
                'currency' => 'VND',
                'enrollment_type' => 'paid',
                'max_students' => 100,
                'access_duration_days' => 180,
                'review_duration_days' => 30,
                'is_refundable' => 1,
                'refund_days' => 7,
                'tags' => '["TOPIK","Beginner","Korean"]',
                'badge_type' => 'hot',
                'show_enrollment_count' => 1,
                'display_enrollment_count' => 83,
                'is_featured' => 1,
                'sort_order' => 10,
                'visibility' => 'public',
                'available_from' => '2026-07-01 00:00:00',
                'available_until' => '2026-12-31 23:59:59',
                'registration_starts_at' => '2026-07-01 00:00:00',
                'registration_ends_at' => '2026-08-01 00:00:00',
                'meta_title' => 'TOPIK Beginner',
                'meta_description' => 'Learn TOPIK from the beginning.',
                'meta_keywords' => 'topik,korean',
                'status' => 'active',
            ]))
            ->assertRedirect('https://tenant-a.localhost/admin/course-products');

        $this->assertDatabaseHas('core_course_products', [
            'customer_id' => $customerId,
            'product_code' => 'PRD-20260704-001',
            'product_type' => 'single_course',
            'title' => 'TOPIK Beginner',
            'slug' => 'topik-beginner',
            'short_description' => 'TOPIK foundation',
            'thumbnail_type' => 'video',
            'thumbnail_image' => '/images/topik.jpg',
            'thumbnail_video_source' => 'youtube',
            'thumbnail_video_url' => 'https://www.youtube.com/watch?v=example',
            'thumbnail_video_media_id' => 12,
            'currency' => 'VND',
            'enrollment_type' => 'paid',
            'max_students' => 100,
            'access_duration_days' => 180,
            'review_duration_days' => 30,
            'is_refundable' => 1,
            'refund_days' => 7,
            'tags' => '["TOPIK","Beginner","Korean"]',
            'badge_type' => 'hot',
            'show_enrollment_count' => 1,
            'display_enrollment_count' => 83,
            'is_featured' => 1,
            'sort_order' => 10,
            'visibility' => 'public',
            'meta_title' => 'TOPIK Beginner',
            'meta_description' => 'Learn TOPIK from the beginning.',
            'meta_keywords' => 'topik,korean',
            'status' => 'active',
            'created_by' => $admin->id,
            'enrollment_count' => 0,
            'is_certificate_enabled' => 0,
        ]);

        $this->assertNotNull(
            DB::table('core_course_products')
                ->where('customer_id', $customerId)
                ->where('slug', 'topik-beginner')
                ->value('published_at')
        );
    }

    public function test_admin_can_edit_update_and_archive_product_without_hard_delete(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct(
            $customerId,
            'TOPIK Beginner',
            'topik-beginner',
            status: 'draft'
        );

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()
            ->assertSee('value="TOPIK Beginner"', false)
            ->assertSeeText('Thông tin sản phẩm');

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-products/{$productId}",
                $this->validProductData([
                    'product_code' => 'TOPIK-BEG-UPDATED',
                    'title' => 'TOPIK Beginner Updated',
                    'slug' => 'topik-beginner-updated',
                    'status' => 'inactive',
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            );

        $this->assertDatabaseHas('core_course_products', [
            'id' => $productId,
            'customer_id' => $customerId,
            'product_code' => 'TOPIK-BEGINNER',
            'title' => 'TOPIK Beginner Updated',
            'slug' => 'topik-beginner-updated',
            'status' => 'inactive',
        ]);

        $this->actingAs($admin)
            ->delete("https://tenant-a.localhost/admin/course-products/{$productId}")
            ->assertRedirect('https://tenant-a.localhost/admin/course-products');

        $this->assertDatabaseHas('core_course_products', [
            'id' => $productId,
            'customer_id' => $customerId,
            'status' => 'archived',
        ]);
    }

    public function test_product_queries_and_mutations_are_tenant_isolated(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $ownProductId = $this->createProduct($customerId, 'Own Product', 'own-product');
        $otherProductId = $this->createProduct(
            $otherCustomerId,
            'Other Product',
            'other-product'
        );

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$otherProductId}/edit")
            ->assertNotFound();

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-products/{$otherProductId}",
                $this->validProductData([
                    'title' => 'Changed Other Product',
                    'slug' => 'changed-other-product',
                ])
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->delete("https://tenant-a.localhost/admin/course-products/{$otherProductId}")
            ->assertNotFound();

        $this->assertDatabaseHas('core_course_products', [
            'id' => $ownProductId,
            'customer_id' => $customerId,
            'title' => 'Own Product',
        ]);
        $this->assertDatabaseHas('core_course_products', [
            'id' => $otherProductId,
            'customer_id' => $otherCustomerId,
            'title' => 'Other Product',
            'status' => 'draft',
        ]);
    }

    public function test_product_code_sequence_is_tenant_scoped_and_ignores_manual_input(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-products',
                $this->validProductData([
                    'product_code' => 'MANUAL-CODE',
                    'title' => 'TOPIK A',
                    'slug' => 'topik',
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-products');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-products',
                $this->validProductData([
                    'title' => 'TOPIK B',
                    'slug' => 'topik-b',
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-products');

        $this->actingAs($otherAdmin)
            ->post(
                'https://tenant-b.localhost/admin/course-products',
                $this->validProductData([
                    'title' => 'Tenant B TOPIK',
                    'slug' => 'topik',
                ])
            )
            ->assertRedirect('https://tenant-b.localhost/admin/course-products');

        $this->assertDatabaseHas('core_course_products', [
            'customer_id' => $customerId,
            'product_code' => 'PRD-20260704-001',
            'slug' => 'topik',
        ]);
        $this->assertDatabaseHas('core_course_products', [
            'customer_id' => $customerId,
            'product_code' => 'PRD-20260704-002',
            'slug' => 'topik-b',
        ]);
        $this->assertDatabaseHas('core_course_products', [
            'customer_id' => $otherCustomerId,
            'product_code' => 'PRD-20260704-001',
            'slug' => 'topik',
        ]);
    }

    public function test_product_code_input_is_not_rendered_on_create_or_edit_forms(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-products/create')
            ->assertOk()
            ->assertDontSee('name="product_code"', false);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()
            ->assertDontSee('name="product_code"', false)
            ->assertSeeText('TOPIK');
    }

    public function test_product_forms_do_not_render_manual_seo_controls(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');

        foreach ([
            $this->actingAs($admin)
                ->get('https://tenant-a.localhost/admin/course-products/create')
                ->assertOk(),
            $this->actingAs($admin)
                ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
                ->assertOk(),
        ] as $response) {
            $this->assertManualSeoControlsNotRendered(
                $response->getContent(),
                'course-product-seo-title',
                'LF_course_product'
            );
        }
    }

    public function test_product_update_without_manual_seo_inputs_preserves_existing_seo_data(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'SEO Product', 'seo-product');

        DB::table('core_course_products')
            ->where('id', $productId)
            ->update([
                'meta_title' => 'Legacy SEO Title',
                'meta_description' => 'Legacy SEO description',
                'meta_keywords' => 'legacy,seo',
            ]);

        $data = $this->validProductData([
            'title' => 'SEO Product Updated',
            'slug' => 'seo-product-updated',
        ]);
        unset($data['meta_title'], $data['meta_description'], $data['meta_keywords']);

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-products/{$productId}",
                $data
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            );

        $this->assertDatabaseHas('core_course_products', [
            'id' => $productId,
            'customer_id' => $customerId,
            'title' => 'SEO Product Updated',
            'slug' => 'seo-product-updated',
            'meta_title' => 'Legacy SEO Title',
            'meta_description' => 'Legacy SEO description',
            'meta_keywords' => 'legacy,seo',
        ]);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-products/create')
            ->post(
                'https://tenant-a.localhost/admin/course-products',
                $this->validProductData(['status' => 'published'])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-products/create')
            ->assertSessionHasErrors('status');

        $this->assertDatabaseCount('core_course_products', 0);
    }

    public function test_all_documented_statuses_are_accepted(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        foreach (['draft', 'active', 'inactive', 'archived'] as $status) {
            $this->actingAs($admin)
                ->post(
                    'https://tenant-a.localhost/admin/course-products',
                    $this->validProductData([
                        'title' => 'Product '.$status,
                        'slug' => 'product-'.$status,
                        'status' => $status,
                    ])
                )
                ->assertRedirect('https://tenant-a.localhost/admin/course-products');

            $this->assertDatabaseHas('core_course_products', [
                'customer_id' => $customerId,
                'slug' => 'product-'.$status,
                'status' => $status,
            ]);
        }
    }

    public function test_teacher_student_and_guest_cannot_access_product_crud(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');

        $this->get('https://tenant-a.localhost/admin/course-products')
            ->assertRedirect('https://tenant-a.localhost/login');

        foreach ([$teacher, $student] as $user) {
            $this->actingAs($user)
                ->get('https://tenant-a.localhost/admin/course-products')
                ->assertForbidden();

            $this->actingAs($user)
                ->post(
                    'https://tenant-a.localhost/admin/course-products',
                    $this->validProductData()
                )
                ->assertForbidden();

            $this->actingAs($user)
                ->put(
                    "https://tenant-a.localhost/admin/course-products/{$productId}",
                    $this->validProductData(['title' => 'Forbidden'])
                )
                ->assertForbidden();

            $this->actingAs($user)
                ->delete("https://tenant-a.localhost/admin/course-products/{$productId}")
                ->assertForbidden();
        }

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher/course-products')
            ->assertNotFound();
    }

    public function test_admin_can_attach_published_version_to_product(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');
        $versionId = $this->createVersion($customerId, $admin->id);

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/items",
                [
                    'version_id' => $versionId,
                    'title_override' => 'Commercial TOPIK',
                    'short_description_override' => 'Override description',
                    'sort_order' => 5,
                    'is_required' => 1,
                    'status' => 'active',
                ]
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            );

        $this->assertDatabaseHas('core_course_product_items', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'title_override' => 'Commercial TOPIK',
            'short_description_override' => 'Override description',
            'sort_order' => 5,
            'is_required' => 1,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
    }

    public function test_product_cannot_attach_two_active_versions_from_same_template(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');
        $templateId = $this->createTemplate($customerId, $admin->id, 'TOPIK Template');
        $oldVersionId = $this->createVersion(
            $customerId,
            $admin->id,
            title: 'TOPIK Template',
            templateId: $templateId,
            versionNumber: 4,
            isCurrent: false
        );
        $newVersionId = $this->createVersion(
            $customerId,
            $admin->id,
            title: 'TOPIK Template',
            templateId: $templateId,
            versionNumber: 7
        );
        $this->createProductItem($customerId, $productId, $oldVersionId);

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/items",
                $this->validProductItemData([
                    'version_id' => $newVersionId,
                    'status' => 'active',
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            )
            ->assertSessionHasErrors('version_id');

        $this->assertDatabaseMissing('core_course_product_items', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $newVersionId,
        ]);
    }

    public function test_product_can_attach_active_versions_from_different_templates(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK Bundle', 'topik-bundle');
        $firstVersionId = $this->createVersion(
            $customerId,
            $admin->id,
            title: 'TOPIK Beginner'
        );
        $secondVersionId = $this->createVersion(
            $customerId,
            $admin->id,
            title: 'TOPIK Intermediate'
        );
        $this->createProductItem($customerId, $productId, $firstVersionId);

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/items",
                $this->validProductItemData([
                    'version_id' => $secondVersionId,
                    'status' => 'active',
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('core_course_product_items', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $secondVersionId,
            'status' => 'active',
        ]);
    }

    public function test_inactive_old_version_does_not_block_new_active_version_from_same_template(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');
        $templateId = $this->createTemplate($customerId, $admin->id, 'TOPIK Template');
        $oldVersionId = $this->createVersion(
            $customerId,
            $admin->id,
            title: 'TOPIK Template',
            templateId: $templateId,
            versionNumber: 4,
            isCurrent: false
        );
        $newVersionId = $this->createVersion(
            $customerId,
            $admin->id,
            title: 'TOPIK Template',
            templateId: $templateId,
            versionNumber: 7
        );
        $this->createProductItem(
            $customerId,
            $productId,
            $oldVersionId,
            status: 'inactive'
        );

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/items",
                $this->validProductItemData([
                    'version_id' => $newVersionId,
                    'status' => 'active',
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('core_course_product_items', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $newVersionId,
            'status' => 'active',
        ]);
    }

    public function test_same_template_active_version_rule_is_tenant_isolated(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');
        $otherProductId = $this->createProduct(
            $otherCustomerId,
            'Tenant B TOPIK',
            'tenant-b-topik'
        );
        $templateId = $this->createTemplate($customerId, $admin->id, 'TOPIK Template');
        $otherTemplateId = $this->createTemplate($otherCustomerId, $otherAdmin->id, 'TOPIK Template');
        $versionId = $this->createVersion(
            $customerId,
            $admin->id,
            title: 'TOPIK Template',
            templateId: $templateId
        );
        $otherVersionId = $this->createVersion(
            $otherCustomerId,
            $otherAdmin->id,
            title: 'TOPIK Template',
            templateId: $otherTemplateId
        );
        $this->createProductItem($otherCustomerId, $otherProductId, $otherVersionId);

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/items",
                $this->validProductItemData([
                    'version_id' => $versionId,
                    'status' => 'active',
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('core_course_product_items', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'status' => 'active',
        ]);
    }

    public function test_admin_can_list_product_items_inside_product_management(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');
        $versionId = $this->createVersion(
            $customerId,
            $admin->id,
            title: 'TOPIK Beginner'
        );
        $this->createProductItem(
            $customerId,
            $productId,
            $versionId,
            titleOverride: 'TOPIK Sale Page',
            sortOrder: 3
        );

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()
            ->assertSeeText('Nội dung trong sản phẩm')
            ->assertSeeText('TOPIK Beginner')
            ->assertSeeText('TOPIK Sale Page')
            ->assertSeeText('Phiên bản 1');
    }

    public function test_admin_can_remove_product_item_link_only(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');
        $versionId = $this->createVersion($customerId, $admin->id);
        $itemId = $this->createProductItem($customerId, $productId, $versionId);

        $this->actingAs($admin)
            ->delete(
                "https://tenant-a.localhost/admin/course-products/{$productId}/items/{$itemId}"
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            );

        $this->assertDatabaseMissing('core_course_product_items', [
            'id' => $itemId,
            'customer_id' => $customerId,
        ]);
        $this->assertDatabaseHas('core_course_products', [
            'id' => $productId,
            'customer_id' => $customerId,
            'title' => 'TOPIK',
        ]);
        $this->assertDatabaseHas('core_course_template_versions', [
            'id' => $versionId,
            'customer_id' => $customerId,
            'status' => 'published',
        ]);
    }

    public function test_duplicate_product_item_attach_is_rejected(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');
        $versionId = $this->createVersion($customerId, $admin->id);
        $this->createProductItem($customerId, $productId, $versionId);

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/items",
                $this->validProductItemData([
                    'version_id' => $versionId,
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            )
            ->assertSessionHasErrors('version_id');

        $this->assertDatabaseCount('core_course_product_items', 1);
    }

    public function test_draft_or_unpublished_version_is_rejected(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');
        $versionId = $this->createVersion(
            $customerId,
            $admin->id,
            status: 'draft_snapshot'
        );

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/items",
                $this->validProductItemData([
                    'version_id' => $versionId,
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            )
            ->assertSessionHasErrors('version_id');

        $this->assertDatabaseCount('core_course_product_items', 0);
    }

    public function test_product_item_attach_is_tenant_isolated(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');
        $otherProductId = $this->createProduct(
            $otherCustomerId,
            'Tenant B Product',
            'tenant-b-product'
        );
        $otherVersionId = $this->createVersion($otherCustomerId, $otherAdmin->id);

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$otherProductId}/items",
                $this->validProductItemData([
                    'version_id' => $otherVersionId,
                ])
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/items",
                $this->validProductItemData([
                    'version_id' => $otherVersionId,
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            )
            ->assertSessionHasErrors('version_id');

        $this->assertDatabaseCount('core_course_product_items', 0);
    }

    public function test_teacher_student_and_guest_cannot_access_product_item_routes(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');
        $versionId = $this->createVersion($customerId, $admin->id);
        $itemId = $this->createProductItem($customerId, $productId, $versionId);

        $this->post(
            "https://tenant-a.localhost/admin/course-products/{$productId}/items",
            $this->validProductItemData(['version_id' => $versionId])
        )->assertRedirect('https://tenant-a.localhost/login');

        $this->delete(
            "https://tenant-a.localhost/admin/course-products/{$productId}/items/{$itemId}"
        )->assertRedirect('https://tenant-a.localhost/login');

        foreach ([$teacher, $student] as $user) {
            $this->actingAs($user)
                ->post(
                    "https://tenant-a.localhost/admin/course-products/{$productId}/items",
                    $this->validProductItemData([
                        'version_id' => $versionId,
                    ])
                )
                ->assertForbidden();

            $this->actingAs($user)
                ->delete(
                    "https://tenant-a.localhost/admin/course-products/{$productId}/items/{$itemId}"
                )
                ->assertForbidden();
        }

        $this->actingAs($teacher)
            ->post(
                "https://tenant-a.localhost/teacher/course-products/{$productId}/items",
                $this->validProductItemData(['version_id' => $versionId])
            )
            ->assertNotFound();
    }

    public function test_product_management_does_not_expose_standalone_product_relation_routes(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-products')
            ->assertOk()
            ->assertDontSeeText('Product Relations');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-product-items')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-product-relations')
            ->assertNotFound();
    }

    public function test_admin_can_attach_valid_product_relation(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $relatedProductId = $this->createProduct(
            $customerId,
            'TOPIK Mock Test',
            'topik-mock-test'
        );

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/relations",
                [
                    'related_product_id' => $relatedProductId,
                    'relation_type' => 'gift',
                    'title_override' => 'Mock test bonus',
                    'description_override' => 'Use as marketing copy only.',
                    'sort_order' => 7,
                    'is_featured' => 1,
                    'starts_at' => null,
                    'ends_at' => null,
                    'status' => 'active',
                ]
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            );

        $this->assertDatabaseHas('core_course_product_relations', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'related_product_id' => $relatedProductId,
            'relation_type' => 'gift',
            'title_override' => 'Mock test bonus',
            'description_override' => 'Use as marketing copy only.',
            'sort_order' => 7,
            'is_featured' => 1,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
    }

    public function test_admin_can_list_product_relations_inside_product_management(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $relatedProductId = $this->createProduct(
            $customerId,
            'TOPIK Intermediate',
            'topik-intermediate',
            'TOPIK-INT'
        );
        $this->createProductRelation(
            $customerId,
            $productId,
            $relatedProductId,
            relationType: 'upsell',
            titleOverride: 'Go Intermediate',
            sortOrder: 4
        );

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()
            ->assertSeeText('Quan hệ sản phẩm')
            ->assertSeeText('TOPIK Intermediate')
            ->assertSeeText('TOPIK-INT')
            ->assertSeeText('Nâng cấp')
            ->assertSeeText('Go Intermediate');
    }

    public function test_admin_can_remove_product_relation_link_only(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $relatedProductId = $this->createProduct(
            $customerId,
            'TOPIK Intermediate',
            'topik-intermediate'
        );
        $relationId = $this->createProductRelation(
            $customerId,
            $productId,
            $relatedProductId
        );

        $this->actingAs($admin)
            ->delete(
                "https://tenant-a.localhost/admin/course-products/{$productId}/relations/{$relationId}"
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            );

        $this->assertDatabaseMissing('core_course_product_relations', [
            'id' => $relationId,
            'customer_id' => $customerId,
        ]);
        $this->assertDatabaseHas('core_course_products', [
            'id' => $productId,
            'customer_id' => $customerId,
            'title' => 'TOPIK Beginner',
        ]);
        $this->assertDatabaseHas('core_course_products', [
            'id' => $relatedProductId,
            'customer_id' => $customerId,
            'title' => 'TOPIK Intermediate',
        ]);
    }

    public function test_duplicate_product_relation_is_rejected(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $relatedProductId = $this->createProduct(
            $customerId,
            'TOPIK Intermediate',
            'topik-intermediate'
        );
        $this->createProductRelation(
            $customerId,
            $productId,
            $relatedProductId,
            relationType: 'related'
        );

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/relations",
                $this->validProductRelationData([
                    'related_product_id' => $relatedProductId,
                    'relation_type' => 'related',
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            )
            ->assertSessionHasErrors('related_product_id');

        $this->assertDatabaseCount('core_course_product_relations', 1);
    }

    public function test_self_relation_is_rejected(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/relations",
                $this->validProductRelationData([
                    'related_product_id' => $productId,
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            )
            ->assertSessionHasErrors('related_product_id');

        $this->assertDatabaseCount('core_course_product_relations', 0);
    }

    public function test_invalid_relation_type_is_rejected(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $relatedProductId = $this->createProduct(
            $customerId,
            'TOPIK Intermediate',
            'topik-intermediate'
        );

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/relations",
                $this->validProductRelationData([
                    'related_product_id' => $relatedProductId,
                    'relation_type' => 'checkout_bundle',
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            )
            ->assertSessionHasErrors('relation_type');

        $this->assertDatabaseCount('core_course_product_relations', 0);
    }

    public function test_product_relation_attach_is_tenant_isolated(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $otherProductId = $this->createProduct(
            $otherCustomerId,
            'Tenant B Product',
            'tenant-b-product'
        );

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$otherProductId}/relations",
                $this->validProductRelationData([
                    'related_product_id' => $otherProductId,
                ])
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/relations",
                $this->validProductRelationData([
                    'related_product_id' => $otherProductId,
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit"
            )
            ->assertSessionHasErrors('related_product_id');

        $this->assertDatabaseCount('core_course_product_relations', 0);
    }

    public function test_teacher_student_and_guest_cannot_access_product_relation_routes(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $relatedProductId = $this->createProduct(
            $customerId,
            'TOPIK Intermediate',
            'topik-intermediate'
        );
        $relationId = $this->createProductRelation(
            $customerId,
            $productId,
            $relatedProductId
        );

        $this->post(
            "https://tenant-a.localhost/admin/course-products/{$productId}/relations",
            $this->validProductRelationData([
                'related_product_id' => $relatedProductId,
            ])
        )->assertRedirect('https://tenant-a.localhost/login');

        $this->delete(
            "https://tenant-a.localhost/admin/course-products/{$productId}/relations/{$relationId}"
        )->assertRedirect('https://tenant-a.localhost/login');

        foreach ([$teacher, $student] as $user) {
            $this->actingAs($user)
                ->post(
                    "https://tenant-a.localhost/admin/course-products/{$productId}/relations",
                    $this->validProductRelationData([
                        'related_product_id' => $relatedProductId,
                    ])
                )
                ->assertForbidden();

            $this->actingAs($user)
                ->delete(
                    "https://tenant-a.localhost/admin/course-products/{$productId}/relations/{$relationId}"
                )
                ->assertForbidden();
        }

        $this->actingAs($teacher)
            ->post(
                "https://tenant-a.localhost/teacher/course-products/{$productId}/relations",
                $this->validProductRelationData([
                    'related_product_id' => $relatedProductId,
                ])
            )
            ->assertNotFound();
    }

    public function test_course_product_module_has_no_eloquent_models(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/CoreCourseProduct.php'));
        $this->assertFileDoesNotExist(app_path('Models/CourseProduct.php'));
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

    private function createProduct(
        int $customerId,
        string $title,
        string $slug,
        ?string $productCode = null,
        string $status = 'draft'
    ): int {
        $now = now();

        return DB::table('core_course_products')->insertGetId([
            'customer_id' => $customerId,
            'product_code' => $productCode ?? strtoupper($slug),
            'product_type' => 'single_course',
            'title' => $title,
            'slug' => $slug,
            'short_description' => null,
            'description' => null,
            'thumbnail_type' => 'image',
            'thumbnail_image' => null,
            'thumbnail_video_source' => null,
            'thumbnail_video_url' => null,
            'thumbnail_video_media_id' => null,
            'price' => 0,
            'sale_price' => null,
            'sale_starts_at' => null,
            'sale_ends_at' => null,
            'currency' => 'VND',
            'enrollment_type' => 'paid',
            'max_students' => null,
            'enrollment_count' => 0,
            'access_duration_days' => null,
            'review_duration_days' => null,
            'is_certificate_enabled' => false,
            'is_refundable' => false,
            'refund_days' => null,
            'tags' => null,
            'badge_type' => null,
            'show_enrollment_count' => true,
            'display_enrollment_count' => null,
            'is_featured' => false,
            'sort_order' => 0,
            'visibility' => 'public',
            'available_from' => null,
            'available_until' => null,
            'registration_starts_at' => null,
            'registration_ends_at' => null,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'status' => $status,
            'created_by' => null,
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createTemplate(
        int $customerId,
        int $userId,
        string $title = 'TOPIK Template'
    ): int {
        $now = now();

        return DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId,
            'category_id' => null,
            'title' => $title,
            'slug' => str($title)->slug()->toString().'-'.uniqid(),
            'short_description' => null,
            'description' => null,
            'publisher_name' => null,
            'thumbnail_type' => 'image',
            'thumbnail_image' => null,
            'thumbnail_video_source' => null,
            'thumbnail_video_url' => null,
            'thumbnail_video_media_id' => null,
            'difficulty_level' => null,
            'language' => null,
            'estimated_duration_minutes' => 0,
            'max_lessons' => null,
            'lesson_count' => 0,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'working_revision' => 1,
            'status' => 'active',
            'created_by' => $userId,
            'last_version_published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createVersion(
        int $customerId,
        int $userId,
        string $title = 'TOPIK Version',
        string $status = 'published',
        ?int $templateId = null,
        int $versionNumber = 1,
        ?bool $isCurrent = null
    ): int {
        $now = now();
        $templateId ??= $this->createTemplate($customerId, $userId, $title);

        return DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'version_number' => $versionNumber,
            'version_code' => 'VERSION-'.$templateId.'-'.$versionNumber,
            'is_current' => $isCurrent ?? $status === 'published',
            'source_category_id' => null,
            'category_name_snapshot' => null,
            'title_snapshot' => $title,
            'slug_snapshot' => str($title)->slug()->toString().'-version',
            'short_description_snapshot' => null,
            'description_snapshot' => null,
            'publisher_name_snapshot' => null,
            'thumbnail_type_snapshot' => 'image',
            'thumbnail_image_snapshot' => null,
            'thumbnail_video_source_snapshot' => null,
            'thumbnail_video_url_snapshot' => null,
            'thumbnail_video_media_id_snapshot' => null,
            'difficulty_level_snapshot' => null,
            'language_snapshot' => null,
            'estimated_duration_minutes_snapshot' => 0,
            'max_lessons_snapshot' => null,
            'lesson_count_snapshot' => 0,
            'meta_title_snapshot' => null,
            'meta_description_snapshot' => null,
            'meta_keywords_snapshot' => null,
            'source_working_revision' => 1,
            'status' => $status,
            'published_at' => $status === 'published' ? $now : null,
            'published_by' => $userId,
            'source_template_updated_at' => $now,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createProductItem(
        int $customerId,
        int $productId,
        int $versionId,
        ?string $titleOverride = null,
        int $sortOrder = 0,
        string $status = 'active'
    ): int {
        $now = now();

        return DB::table('core_course_product_items')->insertGetId([
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'title_override' => $titleOverride,
            'short_description_override' => null,
            'sort_order' => $sortOrder,
            'is_required' => true,
            'status' => $status,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createProductRelation(
        int $customerId,
        int $productId,
        int $relatedProductId,
        string $relationType = 'related',
        ?string $titleOverride = null,
        int $sortOrder = 0
    ): int {
        $now = now();

        return DB::table('core_course_product_relations')->insertGetId([
            'customer_id' => $customerId,
            'product_id' => $productId,
            'related_product_id' => $relatedProductId,
            'relation_type' => $relationType,
            'title_override' => $titleOverride,
            'description_override' => null,
            'sort_order' => $sortOrder,
            'is_featured' => false,
            'starts_at' => null,
            'ends_at' => null,
            'status' => 'active',
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function validProductData(array $overrides = []): array
    {
        return array_merge([
            'product_type' => 'single_course',
            'title' => 'Programming Basics',
            'slug' => 'programming-basics',
            'short_description' => null,
            'description' => null,
            'thumbnail_type' => 'image',
            'thumbnail_image' => null,
            'thumbnail_video_source' => null,
            'thumbnail_video_url' => null,
            'thumbnail_video_media_id' => null,
            'price' => 0,
            'sale_price' => null,
            'sale_starts_at' => null,
            'sale_ends_at' => null,
            'currency' => 'VND',
            'enrollment_type' => 'paid',
            'max_students' => null,
            'access_duration_days' => null,
            'review_duration_days' => null,
            'is_refundable' => 0,
            'refund_days' => null,
            'tags' => null,
            'badge_type' => null,
            'show_enrollment_count' => 1,
            'display_enrollment_count' => null,
            'is_featured' => 0,
            'sort_order' => 0,
            'visibility' => 'public',
            'available_from' => null,
            'available_until' => null,
            'registration_starts_at' => null,
            'registration_ends_at' => null,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'status' => 'draft',
        ], $overrides);
    }

    private function validProductItemData(array $overrides = []): array
    {
        return array_merge([
            'version_id' => 1,
            'title_override' => null,
            'short_description_override' => null,
            'sort_order' => 0,
            'is_required' => 1,
            'status' => 'active',
        ], $overrides);
    }

    private function validProductRelationData(array $overrides = []): array
    {
        return array_merge([
            'related_product_id' => 1,
            'relation_type' => 'related',
            'title_override' => null,
            'description_override' => null,
            'sort_order' => 0,
            'is_featured' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'status' => 'active',
        ], $overrides);
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
}
