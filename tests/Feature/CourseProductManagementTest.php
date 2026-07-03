<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                'product_code' => 'TOPIK-BEG-SLF',
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
            'product_code' => 'TOPIK-BEG-SLF',
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
            'product_code' => 'TOPIK-BEG-UPDATED',
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

    public function test_slug_and_product_code_are_unique_within_tenant_only(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $this->createProduct($customerId, 'TOPIK', 'topik', 'TOPIK-001');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-products',
                $this->validProductData([
                    'product_code' => 'TOPIK-001',
                    'title' => 'Duplicate TOPIK',
                    'slug' => 'topik',
                ])
            )
            ->assertSessionHasErrors(['product_code', 'slug']);

        $this->actingAs($otherAdmin)
            ->post(
                'https://tenant-b.localhost/admin/course-products',
                $this->validProductData([
                    'product_code' => 'TOPIK-001',
                    'title' => 'Tenant B TOPIK',
                    'slug' => 'topik',
                ])
            )
            ->assertRedirect('https://tenant-b.localhost/admin/course-products');

        $this->assertDatabaseHas('core_course_products', [
            'customer_id' => $otherCustomerId,
            'product_code' => 'TOPIK-001',
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
                        'product_code' => 'PRODUCT-'.strtoupper($status),
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

    public function test_product_crud_does_not_expose_product_items_or_relations(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-products')
            ->assertOk()
            ->assertDontSeeText('Product Items')
            ->assertDontSeeText('Product Relations');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-product-items')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-product-relations')
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

    private function validProductData(array $overrides = []): array
    {
        return array_merge([
            'product_code' => 'PROGRAMMING-BASICS',
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
}
