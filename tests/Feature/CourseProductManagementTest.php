<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
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
            ->assertRedirect('https://tenant-a.localhost/admin/course-products/1/edit')
            ->assertSessionHas('success', 'Sản phẩm đã được tạo. Bạn có thể thêm phiên bản khóa học và sản phẩm liên quan.');

        $this->assertDatabaseHas('core_course_products', [
            'customer_id' => $customerId,
            'product_code' => 'PRD-20260704-001',
            'product_type' => 'single_course',
            'title' => 'TOPIK Beginner',
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
            status: 'active'
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
            ->assertRedirect('https://tenant-a.localhost/admin/course-products/1/edit');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-products',
                $this->validProductData([
                    'title' => 'TOPIK B',
                    'slug' => 'topik-b',
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-products/2/edit');

        $this->actingAs($otherAdmin)
            ->post(
                'https://tenant-b.localhost/admin/course-products',
                $this->validProductData([
                    'title' => 'Tenant B TOPIK',
                    'slug' => 'topik',
                ])
            )
            ->assertRedirect('https://tenant-b.localhost/admin/course-products/3/edit');

        $this->assertDatabaseHas('core_course_products', [
            'customer_id' => $customerId,
            'product_code' => 'PRD-20260704-001',
            'slug' => 'topik-a',
        ]);
        $this->assertDatabaseHas('core_course_products', [
            'customer_id' => $customerId,
            'product_code' => 'PRD-20260704-002',
            'slug' => 'topik-b',
        ]);
        $this->assertDatabaseHas('core_course_products', [
            'customer_id' => $otherCustomerId,
            'product_code' => 'PRD-20260704-001',
            'slug' => 'tenant-b-topik',
        ]);
    }

    public function test_product_code_is_hidden_on_create_and_readonly_on_edit(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');
        $beforeCount = DB::table('core_course_products')->where('customer_id', $customerId)->count();

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-products/create')
            ->assertOk()
            ->assertDontSee('id="product_code"', false)
            ->assertDontSeeText('Được tạo tự động khi lưu')
            ->assertSee('name="slug"', false)
            ->assertSee('readonly', false);
        $this->assertSame($beforeCount, DB::table('core_course_products')->where('customer_id', $customerId)->count());

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()
            ->assertDontSee('name="product_code"', false)
            ->assertSee('id="product_code"', false)
            ->assertSee('value="TOPIK"', false)
            ->assertSee('name="slug"', false)
            ->assertSee('readonly', false)
            ->assertSeeText('TOPIK');
    }

    public function test_product_v2_custom_media_ui_restores_source_and_uses_template_media_structure(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $response = $this->actingAs($admin)->withSession(['_old_input' => [
            'uses_custom_intro_media' => '1',
            'intro_video_source' => 'embed',
            'intro_video_embed_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]])->get('https://tenant-a.localhost/admin/course-products/create');

        $response->assertOk()
            ->assertSee('course-template-information-grid product-introduction-media', false)
            ->assertSee('name="intro_image_file"', false)
            ->assertSee('name="intro_video_source"', false)
            ->assertSee('name="intro_video_file"', false)
            ->assertSee('name="intro_video_embed_url"', false)
            ->assertSee('name="intro_document_file"', false)
            ->assertSee('https://www.youtube.com/watch?v=dQw4w9WgXcQ', false)
            ->assertSeeText('Hình ảnh giới thiệu')
            ->assertSeeText('Video giới thiệu')
            ->assertSeeText('Tài liệu giới thiệu')
            ->assertSee('placeholder="Nhập URL HTTPS YouTube hoặc Vimeo"', false)
            ->assertDontSee('lf-product-inherited-media-preview', false);

        [$categoryId, $templateId] = $this->createProductV2CategoryAndTemplate($customerId, $admin->id);
        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-products',
            $this->validProductV2Data($categoryId, $templateId, [
                'uses_custom_intro_media' => 1,
                'intro_video_source' => 'upload',
            ])
        )->assertSessionHasErrors([
            'intro_video_file' => __('validation.required', [
                'attribute' => __('lf.LF_course_template_intro_video'),
            ]),
        ]);
    }

    public function test_product_v2_custom_media_lifecycle_and_authorized_preview(): void
    {
        Storage::fake('media_local');
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        [$categoryId, $templateId] = $this->createProductV2CategoryAndTemplate($customerId, $admin->id);

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-products', $this->validProductV2Data(
            $categoryId,
            $templateId,
            [
                'uses_custom_intro_media' => 1,
                'intro_video_source' => 'upload',
                'intro_image_file' => UploadedFile::fake()->image('product-intro.png'),
                'intro_video_file' => UploadedFile::fake()->create('product-intro.mp4', 32, 'video/mp4'),
                'intro_document_file' => UploadedFile::fake()->create('product-intro.pdf', 32, 'application/pdf'),
            ]
        ))->assertRedirect('https://tenant-a.localhost/admin/course-products/1/edit');

        $product = DB::table('core_course_products')->where('customer_id', $customerId)->where('title', 'Product media test')->first();
        $this->assertNotNull($product->intro_image_media_file_id);
        $this->assertNotNull($product->intro_video_media_file_id);
        $this->assertNotNull($product->intro_document_media_file_id);
        foreach (['intro_image', 'intro_video', 'intro_document'] as $purpose) {
            $this->assertDatabaseHas('media_file_usages', [
                'customer_id' => $customerId, 'owner_type' => 'course_product',
                'owner_id' => $product->id, 'usage_type' => $purpose, 'status' => 'active',
            ]);
        }
        $this->assertDatabaseMissing('media_file_usages', ['owner_type' => 'course_template', 'owner_id' => $templateId]);

        $imageId = (int) $product->intro_image_media_file_id;
        $preview = route('admin.course-products.media.preview', [$product->id, 'image', $imageId]);
        $this->actingAs($admin)->get($preview)->assertOk()->assertHeader('content-type', 'image/png');
        $this->actingAs($otherAdmin)->get("https://tenant-b.localhost/admin/course-products/{$product->id}/media/image/{$imageId}")->assertNotFound();
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-products/{$product->id}/media/document/{$imageId}")->assertNotFound();

        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-products/{$product->id}", $this->validProductV2Data(
            $categoryId,
            $templateId,
            [
                'uses_custom_intro_media' => 1,
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => 'https://youtu.be/dQw4w9WgXcQ',
                'remove_intro_image' => 1,
                'intro_image_file' => UploadedFile::fake()->image('replacement.png', 24, 24),
                'remove_intro_video' => 1,
                'remove_intro_document' => 1,
            ]
        ))->assertSessionHasNoErrors();

        $updated = DB::table('core_course_products')->where('id', $product->id)->first();
        $this->assertNotSame($imageId, (int) $updated->intro_image_media_file_id, 'Valid replacement must win over removal.');
        $this->assertSame('embed', $updated->intro_video_source);
        $this->assertSame('youtube', $updated->intro_video_provider);
        $this->assertNull($updated->intro_video_media_file_id);
        $this->assertNull($updated->intro_document_media_file_id);

        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-products/{$product->id}/edit")
            ->assertOk()
            ->assertSee('name="remove_intro_image"', false)
            ->assertSee('name="remove_intro_video"', false)
            ->assertDontSee('name="remove_intro_document"', false);

        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-products/{$product->id}", $this->validProductV2Data(
            $categoryId,
            $templateId,
            ['uses_custom_intro_media' => 0]
        ))->assertSessionHasNoErrors();
        $retained = DB::table('core_course_products')->where('id', $product->id)->first();
        $this->assertSame($updated->intro_image_media_file_id, $retained->intro_image_media_file_id);
        $this->assertSame($updated->intro_video_embed_url, $retained->intro_video_embed_url);
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

    public function test_product_slug_is_generated_and_unique_per_tenant(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $this->createProduct($customerId, 'TOPIK', 'topik');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-products',
                $this->validProductData(['title' => 'TOPIK'])
            )
            ->assertSessionHasErrors('slug');

        $this->actingAs($otherAdmin)
            ->post(
                'https://tenant-b.localhost/admin/course-products',
                $this->validProductData(['title' => 'TOPIK'])
            )
            ->assertRedirect('https://tenant-b.localhost/admin/course-products/2/edit');

        $this->assertDatabaseHas('core_course_products', [
            'customer_id' => $otherCustomerId,
            'slug' => 'topik',
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
                ->assertRedirectContains('/edit');

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
        $this->createDraftProductTemplateItem($customerId, $productId, $versionId);

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-products/{$productId}/items",
                $this->validProductItemData([
                    'version_id' => $versionId,
                    'title_override' => 'Commercial TOPIK',
                    'short_description_override' => 'Override description',
                    'sort_order' => 5,
                    'is_required' => 1,
                    'status' => 'active',
                ])
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
            'sort_order' => 0,
            'is_required' => 1,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
    }

    public function test_product_can_atomically_replace_the_active_version_from_same_template(): void
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
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('core_course_product_items', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $oldVersionId,
        ]);
        $this->assertDatabaseHas('core_course_product_items', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $newVersionId,
            'status' => 'active',
        ]);
    }

    public function test_product_cannot_attach_version_from_a_different_template(): void
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
            ->from("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
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
            ->assertSessionHasErrors('version_id');

        $this->assertDatabaseMissing('core_course_product_items', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $secondVersionId,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('core_course_product_items', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $firstVersionId,
            'status' => 'active',
        ]);
    }

    public function test_replacing_an_inactive_link_reuses_the_phase_one_template_item(): void
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
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('core_course_product_items', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $newVersionId,
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('core_course_product_items', 1);
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
        $this->createDraftProductTemplateItem($customerId, $productId, $versionId);
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
            title: 'TOPIK Beginner',
            versionNumber: 37
        );
        $this->createProductItem(
            $customerId,
            $productId,
            $versionId,
            titleOverride: 'TOPIK Sale Page',
            sortOrder: 3
        );

        $response = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()
            ->assertSeeText('Nội dung trong sản phẩm')
            ->assertSeeText('TOPIK Beginner')
            ->assertSeeText('Phiên bản 37')
            ->assertSeeText('Xem phiên bản')
            ->assertSeeText('Thay phiên bản')
            ->assertDontSeeText('Gỡ liên kết')
            ->assertSee(route('admin.course-templates.versions.show', [
                'templateId' => DB::table('core_course_template_versions')->where('id', $versionId)->value('template_id'),
                'versionId' => $versionId,
            ]), false)
            ->assertDontSee('<th>Chi tiết</th>', false)
            ->assertDontSee('<th>Xem</th>', false);

        $versionPanel = \Illuminate\Support\Str::between(
            $response->getContent(),
            'id="course-product-panel-versions"',
            'id="course-product-panel-relations"'
        );
        $this->assertStringContainsString('Xem phiên bản', $versionPanel);
        $this->assertStringContainsString('Phiên bản 37', $versionPanel);
        $this->assertStringContainsString('VERSION-', $versionPanel);
        $this->assertSame(1, substr_count($versionPanel, 'TOPIK Beginner'));
        $this->assertStringContainsString('class="badge badge-success"', $versionPanel);
        $this->assertStringContainsString('x-show="!replacing"', $versionPanel);
        $this->assertStringContainsString('id="course-product-version-replace-form"', $versionPanel);
        $this->assertStringContainsString('x-show="replacing" x-cloak', $versionPanel);
        $this->assertStringContainsString('Phiên bản 37 · VERSION-', $versionPanel);
        $this->assertSame(1, substr_count($versionPanel, "\n                                    Thay phiên bản\n"));
        $this->assertStringNotContainsString('name="sort_order"', $versionPanel);
        $this->assertStringNotContainsString('name="is_required"', $versionPanel);
        $this->assertStringNotContainsString('name="status"', $versionPanel);
        $this->assertStringNotContainsString('Gỡ liên kết', $versionPanel);

        $englishResponse = $this->withSession(['locale' => 'en'])->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()->assertSeeText('View version')->assertSeeText('Replace version')->assertDontSeeText('Unlink');
        $englishVersionPanel = \Illuminate\Support\Str::between(
            $englishResponse->getContent(),
            'id="course-product-panel-versions"',
            'id="course-product-panel-relations"'
        );
        $this->assertStringContainsString('View version', $englishVersionPanel);
        $this->assertStringContainsString('Version 37 · VERSION-', $englishVersionPanel);
        $this->assertStringContainsString('The course version currently used for new enrollments.', $englishVersionPanel);
    }

    public function test_version_tab_filters_versions_by_product_template_without_category_or_template_selects(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'TOPIK', 'topik');
        $publishedId = $this->createVersion($customerId, $admin->id, title: 'Allowed');
        $published = DB::table('core_course_template_versions')->where('id', $publishedId)->first();
        $categoryId = DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId, 'parent_id' => null, 'name' => 'Korean', 'slug' => 'korean-options',
            'description' => null, 'thumbnail_image' => null, 'banner_image' => null, 'sort_order' => 0,
            'is_featured' => false, 'meta_title' => null, 'meta_description' => null, 'meta_keywords' => null,
            'status' => 'active', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_templates')->where('id', $published->template_id)->update(['category_id' => $categoryId]);
        $this->createDraftProductTemplateItem($customerId, $productId, $publishedId);
        $draftId = $this->createVersion($customerId, $admin->id, title: 'Draft', status: 'draft_snapshot', templateId: $published->template_id, versionNumber: 2);
        $foreignId = $this->createVersion($otherCustomerId, $otherAdmin->id, title: 'Foreign');

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()->assertSeeText('Course Template đang sử dụng')->assertSeeText('Allowed')
            ->assertSee('value="'.$publishedId.'"', false)
            ->assertDontSee('value="'.$draftId.'"', false)->assertDontSee('value="'.$foreignId.'"', false)
            ->assertDontSee('name="item_category_id"', false)->assertDontSee('name="item_template_id"', false);

        $this->withSession(['locale' => 'en'])->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()->assertSeeText('Current course template')->assertSeeText('Published version');
    }

    public function test_version_select_distinguishes_latest_published_version_from_product_version_in_use(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Version Labels', 'version-labels');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Label Template');
        $inUseId = $this->createVersion($customerId, $admin->id, 'Version 12', templateId: $templateId, versionNumber: 12, isCurrent: false);
        $latestId = $this->createVersion($customerId, $admin->id, 'Version 13', templateId: $templateId, versionNumber: 13, isCurrent: true);
        $this->createProductItem($customerId, $productId, $inUseId);

        $response = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk();

        $date = now()->format('d/m/Y');
        $response->assertSeeTextInOrder(['Phiên bản 13', "VERSION-{$templateId}-13", $date])
            ->assertSeeTextInOrder(['Phiên bản 12', "VERSION-{$templateId}-12", $date, 'Đang sử dụng'])
            ->assertSeeText('Thay phiên bản')
            ->assertSee('value="'.$latestId.'"', false)
            ->assertSee('value="'.$inUseId.'"', false);

        $this->withSession(['locale' => 'en'])->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()->assertSeeText("Version 13 · VERSION-{$templateId}-13")
            ->assertSeeText("Version 12 · VERSION-{$templateId}-12")
            ->assertSeeText('In use');

        DB::table('core_course_product_items')->where('product_id', $productId)->update(['version_id' => $latestId]);
        $this->withSession(['locale' => 'vi'])->actingAs($admin)->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()->assertSeeTextInOrder(['Phiên bản 13', "VERSION-{$templateId}-13", $date, 'Đang sử dụng']);
    }

    public function test_admin_cannot_remove_single_course_product_item(): void
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
            ->assertSessionHasErrors('version_id');

        $this->assertDatabaseHas('core_course_product_items', [
            'id' => $itemId,
            'customer_id' => $customerId,
            'version_id' => $versionId,
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

    public function test_active_single_course_product_cannot_remove_its_final_version(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        [$categoryId, $templateId] = $this->createProductV2CategoryAndTemplate($customerId, $admin->id);
        $replacementTemplateId = $this->createTemplate($customerId, $admin->id, 'Recovery Template');
        DB::table('core_course_templates')->where('id', $replacementTemplateId)->update(['category_id' => $categoryId]);
        $versionId = $this->createVersion($customerId, $admin->id, templateId: $templateId);
        $productId = $this->createProduct($customerId, 'Active Recovery Product', 'active-recovery', status: 'active');
        $itemId = $this->createProductItem($customerId, $productId, $versionId);

        $this->actingAs($admin)->delete("https://tenant-a.localhost/admin/course-products/{$productId}/items/{$itemId}")
            ->assertSessionHasErrors('version_id');
        $this->assertDatabaseHas('core_course_products', ['id' => $productId, 'status' => 'active']);
        $this->assertDatabaseHas('core_course_product_items', ['id' => $itemId, 'template_id' => $templateId, 'version_id' => $versionId]);
    }

    public function test_existing_invalid_active_product_is_not_silently_moved_to_draft(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        [$categoryId, $templateId] = $this->createProductV2CategoryAndTemplate($customerId, $admin->id);
        $productId = $this->createProduct($customerId, 'Invalid Active', 'invalid-active', status: 'active');

        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-products/{$productId}",
            $this->validProductV2Data($categoryId, $templateId, ['title' => 'Invalid Active', 'status' => 'active']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_products', ['id' => $productId, 'status' => 'active']);
        $this->assertDatabaseHas('core_course_product_items', ['product_id' => $productId, 'template_id' => $templateId, 'version_id' => null]);
    }

    public function test_selecting_the_version_already_in_use_is_a_safe_no_op(): void
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
            ->assertSessionHasNoErrors();

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
        $localVersionId = $this->createVersion($customerId, $admin->id);
        $this->createDraftProductTemplateItem($customerId, $productId, $localVersionId);

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

        $this->assertDatabaseCount('core_course_product_items', 1);
        $this->assertDatabaseHas('core_course_product_items', [
            'customer_id' => $customerId, 'product_id' => $productId, 'version_id' => null,
        ]);
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
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit?tab=relations&focus=related_product_search"
            );

        $this->assertDatabaseHas('core_course_product_relations', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'related_product_id' => $relatedProductId,
            'relation_type' => 'related',
            'title_override' => null,
            'description_override' => null,
            'sort_order' => 1,
            'is_featured' => 0,
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
            relationType: 'related',
            sortOrder: 4
        );

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()
            ->assertSeeText('Quan hệ sản phẩm')
            ->assertSeeText('TOPIK Intermediate')
            ->assertSeeText('TOPIK-INT')
            ->assertSeeText('Xem sản phẩm')
            ->assertSeeText('Gỡ liên kết');
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
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit?tab=relations&focus=related_product_search"
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

    public function test_forged_relation_metadata_is_ignored_and_related_type_is_canonical(): void
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
                "https://tenant-a.localhost/admin/course-products/{$productId}/edit?tab=relations&focus=related_product_search"
            )
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('core_course_product_relations', [
            'product_id' => $productId,
            'related_product_id' => $relatedProductId,
            'relation_type' => 'related',
            'sort_order' => 1,
        ]);
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

    public function test_create_and_overview_do_not_expose_or_process_related_product_ids(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $relatedProductId = $this->createProduct($customerId, 'Related', 'related');

        $this->actingAs($admin)->get('https://tenant-a.localhost/admin/course-products/create')
            ->assertOk()->assertDontSee('related_product_ids', false);

        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-products',
            $this->validProductData(['title' => 'Source', 'related_product_ids' => [$relatedProductId]])
        )->assertRedirectContains('/edit');

        $sourceId = (int) DB::table('core_course_products')->where('title', 'Source')->value('id');
        $this->assertDatabaseMissing('core_course_product_relations', ['product_id' => $sourceId]);

        $relationId = $this->createProductRelation($customerId, $sourceId, $relatedProductId, sortOrder: 4);
        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-products/{$sourceId}",
            $this->validProductData(['title' => 'Source updated', 'related_product_ids' => []])
        )->assertRedirect("https://tenant-a.localhost/admin/course-products/{$sourceId}/edit");

        $this->assertDatabaseHas('core_course_product_relations', [
            'id' => $relationId, 'product_id' => $sourceId, 'related_product_id' => $relatedProductId, 'sort_order' => 4,
        ]);
    }

    public function test_related_tab_searches_name_and_code_and_excludes_existing_or_archived_targets(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $sourceId = $this->createProduct($customerId, 'Source', 'source');
        $eligibleId = $this->createProduct($customerId, 'Final Acceptance Material', 'final', 'PRD-SEARCH-001');
        $existingId = $this->createProduct($customerId, 'Already linked', 'linked', 'PRD-LINKED');
        $archivedId = $this->createProduct($customerId, 'Archived target', 'archived-target', 'PRD-ARCHIVED', 'archived');
        $this->createProductRelation($customerId, $sourceId, $existingId);

        $response = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$sourceId}/edit")
            ->assertOk()
            ->assertSee('role="combobox"', false)
            ->assertSee('PRD-SEARCH-001')
            ->assertSee('Final Acceptance Material')
            ->assertDontSee('PRD-ARCHIVED');

        $this->assertStringNotContainsString(
            'PRD-LINKED — Already linked',
            $response->getContent()
        );
        $this->assertNotSame($sourceId, $eligibleId);
        $this->assertNotSame($sourceId, $archivedId);
    }

    public function test_related_links_are_directional_counted_and_receive_next_source_order(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $sourceId = $this->createProduct($customerId, 'Source', 'source');
        $firstId = $this->createProduct($customerId, 'First', 'first');
        $secondId = $this->createProduct($customerId, 'Second', 'second');

        foreach ([$firstId, $secondId] as $targetId) {
            $this->actingAs($admin)->post(
                "https://tenant-a.localhost/admin/course-products/{$sourceId}/relations",
                ['related_product_id' => $targetId]
            )->assertRedirect("https://tenant-a.localhost/admin/course-products/{$sourceId}/edit?tab=relations&focus=related_product_search");
        }

        $this->assertSame([1, 2], DB::table('core_course_product_relations')
            ->where('customer_id', $customerId)->where('product_id', $sourceId)
            ->orderBy('id')->pluck('sort_order')->map(fn ($value) => (int) $value)->all());
        $this->assertDatabaseMissing('core_course_product_relations', [
            'product_id' => $firstId, 'related_product_id' => $sourceId,
        ]);
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-products/{$sourceId}/edit")
            ->assertOk()->assertSeeText('Sản phẩm liên quan (2)')
            ->assertSee('B\u1ea1n c\u00f3 ch\u1eafc ch\u1eafn mu\u1ed1n g\u1ee1 li\u00ean k\u1ebft s\u1ea3n ph\u1ea9m li\u00ean quan n\u00e0y kh\u00f4ng?', false);
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
            'short_description' => null,
            'description' => null,
            'publisher_name' => null,
            'intro_video_source' => null,
            'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => null,
            'difficulty_level' => null,
            'estimated_minutes_per_lesson' => 0,
            'estimated_lesson_count' => null,
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
            'short_description_snapshot' => null,
            'description_snapshot' => null,
            'publisher_name_snapshot' => null,
            'intro_video_source_snapshot' => null,
            'intro_image_media_file_id_snapshot' => null,
            'intro_video_media_file_id_snapshot' => null,
            'difficulty_level_snapshot' => null,
            'estimated_minutes_per_lesson_snapshot' => 0,
            'estimated_lesson_count_snapshot' => null,
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
            'template_id' => DB::table('core_course_template_versions')->where('id', $versionId)->value('template_id'),
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

    private function createEnrollmentUsage(
        int $customerId,
        int $productId,
        int $versionId,
        int $studentId,
        int $adminId
    ): int {
        $now = now();

        return DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'student_id' => $studentId,
            'source' => 'admin',
            'source_id' => null,
            'enrolled_by' => $adminId,
            'enrolled_at' => $now,
            'access_starts_at' => null,
            'access_ends_at' => null,
            'review_starts_at' => null,
            'review_ends_at' => null,
            'status' => 'active',
            'notes' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'expired_at' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createDraftProductTemplateItem(int $customerId, int $productId, int $versionId): int
    {
        return DB::table('core_course_product_items')->insertGetId([
            'customer_id' => $customerId,
            'product_id' => $productId,
            'template_id' => DB::table('core_course_template_versions')->where('id', $versionId)->value('template_id'),
            'version_id' => null,
            'title_override' => null,
            'short_description_override' => null,
            'sort_order' => 0,
            'is_required' => true,
            'status' => 'active',
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
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

    private function validProductV2Data(int $categoryId, int $templateId, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $categoryId,
            'template_id' => $templateId,
            'title' => 'Product media test',
            'offering_type' => 'learning_material',
            'uses_custom_description' => 0,
            'uses_custom_intro_media' => 0,
            'price' => 0,
            'currency' => 'VND',
            'promotion_enabled' => 0,
            'is_featured' => 0,
            'status' => 'draft',
        ], $overrides);
    }

    /** @return array{0: int, 1: int} */
    private function createProductV2CategoryAndTemplate(int $customerId, int $adminId): array
    {
        $categoryId = DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId, 'parent_id' => null, 'name' => 'Media',
            'slug' => 'media-'.$customerId, 'description' => null, 'thumbnail_image' => null,
            'banner_image' => null, 'sort_order' => 0, 'is_featured' => false,
            'meta_title' => null, 'meta_description' => null, 'meta_keywords' => null,
            'status' => 'active', 'created_by' => $adminId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $templateId = $this->createTemplate($customerId, $adminId, 'Product media template');
        DB::table('core_course_templates')->where('id', $templateId)->update(['category_id' => $categoryId]);

        return [$categoryId, $templateId];
    }

    public function test_product_v2_draft_binds_template_and_server_assigns_package_type(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId, 'parent_id' => null, 'name' => 'Korean', 'slug' => 'korean',
            'description' => null, 'thumbnail_image' => null, 'banner_image' => null, 'sort_order' => 0,
            'is_featured' => false, 'meta_title' => null, 'meta_description' => null, 'meta_keywords' => null,
            'status' => 'active', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $templateId = $this->createTemplate($customerId, $admin->id);
        DB::table('core_course_templates')->where('id', $templateId)->update(['category_id' => $categoryId]);

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-products', [
            'category_id' => $categoryId, 'template_id' => $templateId, 'title' => 'Self Study',
            'slug' => 'forged', 'product_type' => 'bundle', 'offering_type' => 'self_paced_course',
            'uses_custom_description' => 0, 'uses_custom_intro_media' => 0,
            'access_duration_days' => 90, 'review_duration_days' => 10, 'price' => '100000.00',
            'currency' => 'VND', 'promotion_enabled' => 0, 'is_featured' => 0,
            'registration_starts_at' => null, 'registration_ends_at' => null, 'status' => 'draft',
        ])->assertRedirect('https://tenant-a.localhost/admin/course-products/1/edit');

        $product = DB::table('core_course_products')->where('title', 'Self Study')->first();
        $this->assertSame('single_course', $product->product_type);
        $this->assertSame('self_paced_course', $product->offering_type);
        $this->assertSame('self-study', $product->slug);
        $this->assertDatabaseHas('core_course_product_items', [
            'product_id' => $product->id, 'template_id' => $templateId, 'version_id' => null,
        ]);

        $this->actingAs($admin)->get('https://tenant-a.localhost/admin/course-products')
            ->assertOk()
            ->assertSeeText('Khóa học tự học')
            ->assertSeeText('Bản nháp');
    }

    public function test_create_product_keeps_template_enabled_and_persists_selected_published_version(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        [$categoryId, $templateId] = $this->createProductV2CategoryAndTemplate($customerId, $admin->id);
        $versionId = $this->createVersion($customerId, $admin->id, 'Create Version', templateId: $templateId, versionNumber: 8);

        $response = $this->actingAs($admin)->get('https://tenant-a.localhost/admin/course-products/create')->assertOk();
        $this->assertMatchesRegularExpression('/<select(?=[^>]*\bid="template_id")(?=[^>]*\bname="template_id")(?![^>]*\bdisabled\b)[^>]*>/', $response->getContent());
        $this->assertMatchesRegularExpression('/<option\s+value="'.preg_quote((string) $templateId, '/').'"[^>]*>[^<]+<\/option>/', $response->getContent());
        $this->assertStringContainsString('id="template_version_id"', $response->getContent());
        $this->assertMatchesRegularExpression('/<option\s+value="'.preg_quote((string) $versionId, '/').'"[^>]*>\s*Phiên bản 8 · VERSION-/', $response->getContent());

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-products',
            $this->validProductV2Data($categoryId, $templateId, [
                'title' => 'Created With Version', 'template_version_id' => $versionId,
            ]))->assertSessionHasNoErrors();

        $productId = DB::table('core_course_products')->where('title', 'Created With Version')->value('id');
        $this->assertDatabaseHas('core_course_product_items', [
            'product_id' => $productId, 'template_id' => $templateId, 'version_id' => $versionId,
        ]);
    }

    public function test_product_template_can_change_before_a_version_is_linked(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        [$categoryId, $firstTemplateId] = $this->createProductV2CategoryAndTemplate($customerId, $admin->id);
        $secondTemplateId = $this->createTemplate($customerId, $admin->id, 'Replacement Template');
        DB::table('core_course_templates')->where('id', $secondTemplateId)->update(['category_id' => $categoryId]);
        $secondVersionId = $this->createVersion($customerId, $admin->id, 'Replacement Version', templateId: $secondTemplateId);

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-products', $this->validProductV2Data($categoryId, $firstTemplateId, ['title' => 'Changeable Product']))->assertSessionHasNoErrors();
        $productId = DB::table('core_course_products')->where('title', 'Changeable Product')->value('id');
        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-products/{$productId}", $this->validProductV2Data($categoryId, $secondTemplateId, [
            'title' => 'Changeable Product', 'template_version_id' => $secondVersionId,
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('core_course_product_items', ['product_id' => $productId, 'template_id' => $secondTemplateId, 'version_id' => $secondVersionId]);
        $this->assertDatabaseMissing('core_course_product_items', ['product_id' => $productId, 'template_id' => $firstTemplateId]);
    }

    public function test_unused_draft_product_can_atomically_change_template_and_linked_version(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        [$categoryId, $firstTemplateId] = $this->createProductV2CategoryAndTemplate($customerId, $admin->id);
        $secondTemplateId = $this->createTemplate($customerId, $admin->id, 'Blocked Replacement');
        DB::table('core_course_templates')->where('id', $secondTemplateId)->update(['category_id' => $categoryId]);
        $versionId = $this->createVersion($customerId, $admin->id, 'Linked Version', templateId: $firstTemplateId);
        $replacementVersionId = $this->createVersion($customerId, $admin->id, 'Replacement Version', templateId: $secondTemplateId);
        $productId = $this->createProduct($customerId, 'Frozen Product', 'frozen-product');
        $itemId = $this->createProductItem($customerId, $productId, $versionId);

        $editResponse = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")->assertOk();
        $this->assertMatchesRegularExpression('/<select(?=[^>]*\bid="template_id")(?=[^>]*\bname="template_id")(?![^>]*\bdisabled\b)[^>]*>/', $editResponse->getContent());
        $this->assertMatchesRegularExpression('/<option\s+value="'.preg_quote((string) $firstTemplateId, '/').'"[^>]*selected[^>]*>/', $editResponse->getContent());
        $this->assertMatchesRegularExpression('/<option\s+value="'.preg_quote((string) $secondTemplateId, '/').'"[^>]*>Blocked Replacement<\/option>/', $editResponse->getContent());
        $this->assertStringNotContainsString('gỡ liên kết phiên bản', mb_strtolower($editResponse->getContent()));

        $this->actingAs($admin)->from("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->put("https://tenant-a.localhost/admin/course-products/{$productId}", $this->validProductV2Data($categoryId, $secondTemplateId, [
                'title' => 'Frozen Product', 'template_version_id' => $replacementVersionId,
            ]))
            ->assertRedirect("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('core_course_product_items', ['id' => $itemId, 'template_id' => $secondTemplateId, 'version_id' => $replacementVersionId]);
        $this->assertDatabaseCount('core_course_product_items', 1);
    }

    public function test_active_unused_product_must_target_draft_before_changing_template(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        [$categoryId, $firstTemplateId] = $this->createProductV2CategoryAndTemplate($customerId, $admin->id);
        $secondTemplateId = $this->createTemplate($customerId, $admin->id, 'Active Replacement');
        DB::table('core_course_templates')->where('id', $secondTemplateId)->update(['category_id' => $categoryId]);
        $firstVersionId = $this->createVersion($customerId, $admin->id, 'Active Version', templateId: $firstTemplateId);
        $secondVersionId = $this->createVersion($customerId, $admin->id, 'Replacement Version', templateId: $secondTemplateId);
        $productId = $this->createProduct($customerId, 'Active Unused', 'active-unused', status: 'active');
        $itemId = $this->createProductItem($customerId, $productId, $firstVersionId);

        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-products/{$productId}",
            $this->validProductV2Data($categoryId, $secondTemplateId, [
                'title' => 'Active Unused', 'status' => 'active', 'template_version_id' => $secondVersionId,
            ]))->assertSessionHasErrors('template_id');
        $this->assertDatabaseHas('core_course_product_items', ['id' => $itemId, 'template_id' => $firstTemplateId]);

        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-products/{$productId}",
            $this->validProductV2Data($categoryId, $secondTemplateId, [
                'title' => 'Active Unused', 'status' => 'draft', 'template_version_id' => $secondVersionId,
            ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_products', ['id' => $productId, 'status' => 'draft']);
        $this->assertDatabaseHas('core_course_product_items', ['id' => $itemId, 'template_id' => $secondTemplateId, 'version_id' => $secondVersionId]);
    }

    public function test_enrollment_usage_blocks_template_change_in_every_product_status(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        [$categoryId, $firstTemplateId] = $this->createProductV2CategoryAndTemplate($customerId, $admin->id);
        $secondTemplateId = $this->createTemplate($customerId, $admin->id, 'Used Replacement');
        DB::table('core_course_templates')->where('id', $secondTemplateId)->update(['category_id' => $categoryId]);
        $firstVersionId = $this->createVersion($customerId, $admin->id, 'Used Version', templateId: $firstTemplateId);
        $secondVersionId = $this->createVersion($customerId, $admin->id, 'Replacement Version', templateId: $secondTemplateId);
        $productId = $this->createProduct($customerId, 'Used Draft', 'used-draft', status: 'draft');
        $itemId = $this->createProductItem($customerId, $productId, $firstVersionId);
        $this->createEnrollmentUsage($customerId, $productId, $firstVersionId, $student->id, $admin->id);

        $this->actingAs($admin)->from("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->put("https://tenant-a.localhost/admin/course-products/{$productId}",
                $this->validProductV2Data($categoryId, $secondTemplateId, [
                    'title' => 'Used Draft', 'status' => 'draft', 'template_version_id' => $secondVersionId,
                ]))->assertSessionHasErrors('template_id');

        $this->assertDatabaseHas('core_course_product_items', ['id' => $itemId, 'template_id' => $firstTemplateId, 'version_id' => $firstVersionId]);
        $lockedResponse = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()
            ->assertSeeText('Không thể đổi Course Template vì sản phẩm đã phát sinh dữ liệu sử dụng.')
            ->assertSeeText('VERSION-'.$firstTemplateId.'-1 · Đã xuất bản');
        $this->assertStringNotContainsString('<select id="template_id"', $lockedResponse->getContent());
        $this->assertMatchesRegularExpression('/<div id="template_id"[^>]*>[^<]+<\/div>/', $lockedResponse->getContent());
    }

    public function test_used_active_product_can_be_deactivated_but_cannot_return_to_draft(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        [$categoryId, $templateId] = $this->createProductV2CategoryAndTemplate($customerId, $admin->id);
        $versionId = $this->createVersion($customerId, $admin->id, 'Used Active Version', templateId: $templateId);
        $productId = $this->createProduct($customerId, 'Used Active', 'used-active', status: 'active');
        $this->createProductItem($customerId, $productId, $versionId);
        $enrollmentId = $this->createEnrollmentUsage($customerId, $productId, $versionId, $student->id, $admin->id);

        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-products/{$productId}",
            $this->validProductV2Data($categoryId, $templateId, ['title' => 'Used Active', 'status' => 'draft']))
            ->assertSessionHasErrors('status');
        $this->assertDatabaseHas('core_course_products', ['id' => $productId, 'status' => 'active']);

        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-products/{$productId}",
            $this->validProductV2Data($categoryId, $templateId, ['title' => 'Used Active', 'status' => 'inactive']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_products', ['id' => $productId, 'status' => 'inactive']);
        $this->assertDatabaseHas('core_course_enrollments', ['id' => $enrollmentId, 'version_id' => $versionId, 'status' => 'active']);
        $this->assertDatabaseHas('saas_audit_logs', ['customer_id' => $customerId, 'action' => 'course_product_deactivate']);
    }

    public function test_inactive_archive_and_archived_restore_use_dedicated_actions(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Lifecycle Product', 'lifecycle-product', status: 'inactive');

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-products/{$productId}/archive")
            ->assertRedirect('https://tenant-a.localhost/admin/course-products');
        $this->assertDatabaseHas('core_course_products', ['id' => $productId, 'status' => 'archived']);
        $this->assertDatabaseHas('saas_audit_logs', ['customer_id' => $customerId, 'action' => 'course_product_archive']);

        $this->actingAs($admin)->get('https://tenant-a.localhost/admin/course-products')
            ->assertOk()->assertDontSeeText('Lifecycle Product');
        $this->actingAs($admin)->get('https://tenant-a.localhost/admin/course-products?status=archived')
            ->assertOk()->assertSeeText('Lifecycle Product');

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-products/{$productId}/restore")
            ->assertRedirect("https://tenant-a.localhost/admin/course-products/{$productId}/edit");
        $this->assertDatabaseHas('core_course_products', ['id' => $productId, 'status' => 'inactive']);
        $this->assertDatabaseHas('saas_audit_logs', ['customer_id' => $customerId, 'action' => 'course_product_restore']);
    }

    public function test_edit_footer_uses_persisted_status_for_dedicated_archive_and_restore_actions(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $draftId = $this->createProduct($customerId, 'Footer Draft', 'footer-draft', status: 'draft');
        $activeId = $this->createProduct($customerId, 'Footer Active', 'footer-active', status: 'active');
        $inactiveId = $this->createProduct($customerId, 'Footer Inactive', 'footer-inactive', status: 'inactive');

        foreach ([$draftId, $activeId] as $productId) {
            $response = $this->actingAs($admin)
                ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
                ->assertOk()
                ->assertSee('course-product-form-footer-destructive', false)
                ->assertSee('course-product-form-footer-primary', false)
                ->assertDontSee(route('admin.course-products.archive', $productId), false);
            $this->assertStringContainsString('form="course-product-update-form"', $response->getContent());
        }

        $inactiveResponse = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$inactiveId}/edit")
            ->assertOk()
            ->assertSee(route('admin.course-products.archive', $inactiveId), false)
            ->assertSeeText(__('lf.LF_product_status_archive_action'));
        $this->assertMatchesRegularExpression(
            '/<form[^>]+action="'.preg_quote(route('admin.course-products.archive', $inactiveId), '/').'"[^>]*>.*course-product-danger-outline-action/s',
            $inactiveResponse->getContent()
        );

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-products/{$inactiveId}/archive")
            ->assertSessionHasNoErrors();
        $archivedResponse = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$inactiveId}/edit")
            ->assertOk()
            ->assertSee(route('admin.course-products.restore', $inactiveId), false)
            ->assertDontSee(route('admin.course-products.archive', $inactiveId), false)
            ->assertSeeText(__('lf.LF_product_status_restore_action'));
        $this->assertStringNotContainsString('form="course-product-update-form" class="btn btn-primary"', $archivedResponse->getContent());

        app()->setLocale('en');
        $this->assertSame('Archive product', __('lf.LF_product_status_archive_action'));
        $this->assertStringContainsString('Historical data will be preserved.', __('lf.LF_product_status_archive_confirm'));
    }

    public function test_active_product_cannot_be_archived_directly(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Direct Archive', 'direct-archive', status: 'active');

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-products/{$productId}/archive")
            ->assertSessionHasErrors('status');
        $this->assertDatabaseHas('core_course_products', ['id' => $productId, 'status' => 'active']);
        $this->assertDatabaseMissing('saas_audit_logs', ['customer_id' => $customerId, 'action' => 'course_product_archive']);
    }

    public function test_product_v2_activation_requires_current_published_version_and_validates_self_paced_fields(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId, 'parent_id' => null, 'name' => 'Korean', 'slug' => 'korean',
            'description' => null, 'thumbnail_image' => null, 'banner_image' => null, 'sort_order' => 0,
            'is_featured' => false, 'meta_title' => null, 'meta_description' => null, 'meta_keywords' => null,
            'status' => 'active', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $templateId = $this->createTemplate($customerId, $admin->id);
        DB::table('core_course_templates')->where('id', $templateId)->update(['category_id' => $categoryId]);
        $data = ['category_id' => $categoryId, 'template_id' => $templateId, 'title' => 'Active Study',
            'offering_type' => 'self_paced_course', 'uses_custom_description' => 0, 'uses_custom_intro_media' => 0,
            'access_duration_days' => null, 'review_duration_days' => 0, 'price' => 0, 'currency' => 'VND',
            'promotion_enabled' => 0, 'is_featured' => 0, 'status' => 'active'];

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-products', $data)
            ->assertSessionHasErrors(['access_duration_days', 'status']);

        $versionId = $this->createVersion($customerId, $admin->id, 'Published', 'published', $templateId);
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-products', array_merge($data, ['access_duration_days' => 30]))
            ->assertSessionHasErrors('status');
        $draftData = array_merge($data, ['access_duration_days' => 30, 'status' => 'draft']);
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-products', $draftData)->assertSessionHasNoErrors();
        $productId = DB::table('core_course_products')->where('title', 'Active Study')->value('id');
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-products/{$productId}/items", $this->validProductItemData(['version_id' => $versionId]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-products/{$productId}", array_merge($draftData, ['status' => 'active']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_product_items', ['product_id' => $productId, 'template_id' => $templateId, 'version_id' => $versionId]);
    }

    public function test_product_v2_template_summary_exposes_current_published_version_counts(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Summary Template');
        $versionId = $this->createVersion($customerId, $admin->id, 'Summary Version', 'published', $templateId);
        $lessonId = $this->createVersionLesson($customerId, $versionId);
        $this->createVersionActivity($customerId, $versionId, $lessonId);
        DB::table('core_course_templates')->where('id', $templateId)->update(['lesson_count' => 99]);

        $this->actingAs($admin)->withSession(['_old_input' => ['template_id' => (string) $templateId]])
            ->get('https://tenant-a.localhost/admin/course-products/create')
            ->assertOk()
            ->assertSeeText('Phiên bản sử dụng')
            ->assertSeeText('Sản phẩm chưa có phiên bản khóa học đang sử dụng.')
            ->assertDontSeeText('99 bài học')
            ->assertDontSee(route('admin.course-templates.versions.show', ['templateId' => $templateId, 'versionId' => $versionId]), false);
    }

    public function test_product_v2_active_edit_uses_bound_version_instead_of_newer_current_version(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Immutable Template');
        $categoryId = DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId, 'parent_id' => null, 'name' => 'Immutable Category',
            'slug' => 'immutable-category', 'description' => null, 'thumbnail_image' => null,
            'banner_image' => null, 'sort_order' => 0, 'is_featured' => false,
            'meta_title' => null, 'meta_description' => null, 'meta_keywords' => null,
            'status' => 'active', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_templates')->where('id', $templateId)->update(['category_id' => $categoryId]);
        $boundVersionId = $this->createVersion($customerId, $admin->id, 'Bound', 'published', $templateId, 37, false);
        $newerVersionId = $this->createVersion($customerId, $admin->id, 'Newer', 'published', $templateId, 2, true);
        $lessonId = $this->createVersionLesson($customerId, $boundVersionId);
        $this->createVersionActivity($customerId, $boundVersionId, $lessonId);
        $productId = $this->createProduct($customerId, 'Active Immutable', 'active-immutable', status: 'active');
        $itemId = $this->createProductItem($customerId, $productId, $boundVersionId);
        DB::table('core_course_product_items')->where('id', $itemId)->update(['template_id' => $templateId]);

        $response = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()
            ->assertSeeTextInOrder(['VERSION-'.$templateId.'-37 · Đã xuất bản', 'Phiên bản 37 · 1 bài học · 1 hoạt động'])
            ->assertDontSeeText('VERSION-'.$templateId.'-2 · Đã xuất bản')
            ->assertSee(route('admin.course-templates.versions.show', [
                'templateId' => $templateId, 'versionId' => $boundVersionId,
            ]), false)
            ->assertDontSee(route('admin.course-templates.versions.show', [
                'templateId' => $templateId, 'versionId' => $newerVersionId,
            ]), false);
        $this->assertStringNotContainsString('lf-product-version-summary-number', $response->getContent());

        $this->withSession(['locale' => 'en'])->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()
            ->assertSeeTextInOrder(['VERSION-'.$templateId.'-37 · Published', 'Version 37 · 1 lesson · 1 activity']);

        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-products/{$productId}", [
            'category_id' => $categoryId, 'template_id' => $templateId, 'title' => 'Active Immutable Updated',
            'offering_type' => 'learning_material', 'uses_custom_description' => 0,
            'uses_custom_intro_media' => 0, 'price' => 0, 'currency' => 'VND',
            'promotion_enabled' => 0, 'is_featured' => 0, 'status' => 'active',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_product_items', [
            'product_id' => $productId, 'template_id' => $templateId, 'version_id' => $boundVersionId,
        ]);
    }

    public function test_product_version_summary_query_count_does_not_grow_with_template_count(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Bound Template');
        $versionId = $this->createVersion($customerId, $admin->id, 'Bound Version', 'published', $templateId, 9);
        $productId = $this->createProduct($customerId, 'Query Product', 'query-product');
        $this->createProductItem($customerId, $productId, $versionId);

        foreach (range(1, 5) as $number) {
            $extraTemplateId = $this->createTemplate($customerId, $admin->id, 'Extra '.$number);
            $this->createVersion($customerId, $admin->id, 'Extra Version '.$number, 'published', $extraTemplateId, $number);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(\App\Support\CourseProductVersionSummaryPresenter::class)
            ->present($customerId, $productId, true);

        $this->assertCount(6, $queries);
    }

    public function test_product_v2_mismatched_bound_version_is_not_exposed_or_replaced(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Selected Template');
        $otherTemplateId = $this->createTemplate($customerId, $admin->id, 'Other Template');
        $mismatchedVersionId = $this->createVersion($customerId, $admin->id, 'Mismatched', 'published', $otherTemplateId);
        $productId = $this->createProduct($customerId, 'Invalid Binding', 'invalid-binding', status: 'draft');
        $itemId = $this->createProductItem($customerId, $productId, $mismatchedVersionId);
        DB::table('core_course_product_items')->where('id', $itemId)->update(['template_id' => $templateId]);

        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()
            ->assertSeeText('Phiên bản đã liên kết không khả dụng');
    }

    public function test_product_v2_draft_uses_bound_version_or_current_candidate_explicitly(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Draft Version Template');
        $boundVersionId = $this->createVersion($customerId, $admin->id, 'Bound Draft Version', 'published', $templateId, 1, false);
        $currentVersionId = $this->createVersion($customerId, $admin->id, 'Current Candidate', 'published', $templateId, 2, true);

        $boundProductId = $this->createProduct($customerId, 'Bound Draft Product', 'bound-draft-product', status: 'draft');
        $boundItemId = $this->createProductItem($customerId, $boundProductId, $boundVersionId);
        DB::table('core_course_product_items')->where('id', $boundItemId)->update(['template_id' => $templateId]);

        $candidateProductId = $this->createProduct($customerId, 'Candidate Draft Product', 'candidate-draft-product', status: 'draft');
        DB::table('core_course_product_items')->insert([
            'customer_id' => $customerId, 'product_id' => $candidateProductId, 'template_id' => $templateId,
            'version_id' => null, 'title_override' => null, 'short_description_override' => null,
            'sort_order' => 0, 'is_required' => true, 'status' => 'active', 'created_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-products/{$boundProductId}/edit")
            ->assertOk()->assertSeeText('VERSION-'.$templateId.'-1 · Đã xuất bản');
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-products/{$candidateProductId}/edit")
            ->assertOk()->assertSeeText('Sản phẩm chưa có phiên bản khóa học đang sử dụng.')
            ->assertDontSee(route('admin.course-templates.versions.show', [
                'templateId' => $templateId, 'versionId' => $currentVersionId,
            ]), false);
    }

    public function test_product_v2_missing_version_and_inheritance_presentation_is_compact(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Unpublished Template');

        $this->actingAs($admin)->withSession(['_old_input' => ['template_id' => (string) $templateId]])
            ->get('https://tenant-a.localhost/admin/course-products/create')
            ->assertOk()
            ->assertSeeText('Sản phẩm chưa có phiên bản khóa học đang sử dụng.')
            ->assertSeeText('Mô tả được kế thừa từ phiên bản Template.')
            ->assertSeeText('Media giới thiệu được kế thừa từ phiên bản Template.')
            ->assertSee('name="short_description"', false)
            ->assertSee('name="intro_image_file"', false)
            ->assertDontSee('lf-product-inherited-media-preview', false);
    }

    private function createVersionLesson(int $customerId, int $versionId): int
    {
        return DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'version_section_id' => null,
            'source_template_lesson_id' => random_int(100000, 999999), 'title_snapshot' => 'Snapshot Lesson',
            'short_description_snapshot' => null, 'description_snapshot' => null, 'sort_order' => 0,
            'is_preview' => false, 'lesson_type' => 'regular', 'duration_seconds' => 0, 'activity_count' => 1,
            'unlock_rule_snapshot' => 'none', 'unlock_after_version_lesson_id' => null, 'unlock_at_snapshot' => null,
            'created_by_snapshot' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createVersionActivity(int $customerId, int $versionId, int $lessonId): int
    {
        return DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'version_lesson_id' => $lessonId,
            'source_template_activity_id' => random_int(100000, 999999), 'title_snapshot' => 'Snapshot Activity',
            'description_snapshot' => null, 'sort_order' => 0, 'activity_type' => 'video', 'media_file_id' => null,
            'external_video_url_snapshot' => null, 'live_class_url_snapshot' => null,
            'assessment_quiz_id_snapshot' => null, 'duration_seconds' => 0,
            'estimated_duration_seconds_snapshot' => null, 'is_required' => true, 'completion_rule' => 'view',
            'completion_threshold' => null, 'is_preview' => false, 'unlock_rule_snapshot' => 'none',
            'unlock_after_version_activity_id' => null, 'unlock_at_snapshot' => null, 'created_by_snapshot' => null,
            'metadata' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function validProductItemData(array $overrides = []): array
    {
        $data = array_merge([
            'version_id' => 1,
            'title_override' => null,
            'short_description_override' => null,
            'sort_order' => 0,
            'is_required' => 1,
            'status' => 'active',
        ], $overrides);

        $version = DB::table('core_course_template_versions')->where('id', $data['version_id'])->first(['template_id', 'customer_id']);
        if ($version) {
            $categoryId = DB::table('core_course_templates')->where('id', $version->template_id)->value('category_id');
            if (! $categoryId) {
                $categoryId = DB::table('core_course_categories')->insertGetId([
                    'customer_id' => $version->customer_id, 'parent_id' => null,
                    'name' => 'Item category', 'slug' => 'item-category-'.$version->template_id,
                    'description' => null, 'thumbnail_image' => null, 'banner_image' => null,
                    'sort_order' => 0, 'is_featured' => false, 'meta_title' => null,
                    'meta_description' => null, 'meta_keywords' => null, 'status' => 'active',
                    'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('core_course_templates')->where('id', $version->template_id)->update(['category_id' => $categoryId]);
            }
            $data['item_category_id'] = $categoryId;
            $data['item_template_id'] = $version->template_id;
        }

        return $data;
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
