<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        ]);
    }

    public function test_admin_and_teacher_can_view_their_tenant_category_list(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $this->createCategory($customerId, 'Korean', 'korean');
        $this->createCategory($otherCustomerId, 'Private Tenant Category', 'private-category');

        foreach ([
            [$admin, 'admin'],
            [$teacher, 'teacher'],
        ] as [$user, $area]) {
            $this->actingAs($user)
                ->get("https://tenant-a.localhost/{$area}/course-categories")
                ->assertOk()
                ->assertSeeText('Korean')
                ->assertSeeText(__('lf.LF_navigation_menu_common_product_categories'))
                ->assertDontSeeText('Private Tenant Category');
        }
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
            'sort_order' => 10,
            'is_featured' => 1,
            'meta_title' => 'Learn Korean',
            'meta_description' => 'Korean course category',
            'meta_keywords' => 'korean,language',
            'status' => 'active',
            'created_by' => $admin->id,
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

    public function test_category_validation_rejects_invalid_data(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-categories/create')
            ->post('https://tenant-a.localhost/admin/course-categories', [
                'name' => '',
                'slug' => '',
                'sort_order' => 'not-an-integer',
                'is_featured' => 'not-a-boolean',
                'status' => 'archived',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories/create')
            ->assertSessionHasErrors([
                'name',
                'slug',
                'sort_order',
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
            ->get("https://tenant-a.localhost/admin/course-categories/{$otherCategoryId}/edit")
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
                'name' => 'Duplicate Korean',
                'slug' => 'korean',
            ]))
            ->assertSessionHasErrors('slug');

        $this->actingAs($otherAdmin)
            ->post('https://tenant-b.localhost/admin/course-categories', $this->validCategoryData([
                'name' => 'Tenant B Korean',
                'slug' => 'korean',
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
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-categories/{$categoryId}/edit"
            );

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
        string $status = 'active'
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
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validCategoryData(array $overrides = []): array
    {
        return array_merge([
            'parent_id' => null,
            'name' => 'Programming',
            'slug' => 'programming',
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
}
