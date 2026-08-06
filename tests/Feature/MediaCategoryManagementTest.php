<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MediaCategoryManagementTest extends TestCase
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

    public function test_media_categories_table_matches_sprint_one_scope(): void
    {
        $this->assertTrue(Schema::hasTable('media_categories'));

        foreach ([
            'id',
            'customer_id',
            'parent_id',
            'name',
            'slug',
            'description',
            'icon',
            'color',
            'sort_order',
            'status',
            'metadata',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('media_categories', $column));
        }

        foreach (['storage_key', 'storage_bucket', 'storage_region', 'path'] as $column) {
            $this->assertFalse(Schema::hasColumn('media_categories', $column));
        }
    }

    public function test_admin_can_view_only_their_tenant_media_categories(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->createCategory($customerId, 'Learning Videos', 'learning-videos');
        $this->createCategory($otherCustomerId, 'Private Media', 'private-media');

        $response = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media-categories')
            ->assertOk()
            ->assertSeeText('Learning Videos')
            ->assertSeeText(__('lf.LF_media_category_common_title'))
            ->assertDontSeeText(__('lf.LF_media_category_common_sort_order'))
            ->assertDontSeeText('Private Media');
        $this->assertSame(6, preg_match_all('/<th\b/', $response->getContent()));
    }

    public function test_admin_can_create_media_category_with_documented_fields(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $createResponse = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media-categories/create')
            ->assertOk()
            ->assertDontSee('name="sort_order"', false)
            ->assertDontSee('id="sort_order"', false);
        $createContent = $createResponse->getContent();
        $this->assertStringContainsString('class="admin-form-standard"', $createContent);
        $this->assertStringContainsString('class="admin-form-flow"', $createContent);
        $this->assertStringContainsString('aria-labelledby="media-category-general"', $createContent);
        $this->assertStringContainsString('aria-labelledby="media-category-display"', $createContent);
        $this->assertStringContainsString('class="admin-form-footer"', $createContent);

        $this
            ->post('https://tenant-a.localhost/admin/media-categories', $this->validCategoryData([
                'name' => 'Learning Videos',
                'slug' => 'learning-videos',
                'description' => 'Video assets',
                'icon' => 'video',
                'color' => '#2563EB',
                'sort_order' => 10,
                'metadata' => '{"purpose":"lesson"}',
            ]))
            ->assertRedirect('https://tenant-a.localhost/admin/media-categories');

        $this->assertDatabaseHas('media_categories', [
            'customer_id' => $customerId,
            'parent_id' => null,
            'name' => 'Learning Videos',
            'slug' => 'learning-videos',
            'description' => 'Video assets',
            'icon' => 'video',
            'color' => '#2563EB',
            'sort_order' => 1,
            'status' => 'active',
            'metadata' => '{"purpose":"lesson"}',
        ]);
    }

    public function test_admin_can_create_child_category_with_same_tenant_parent(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $parentId = $this->createCategory($customerId, 'Documents', 'documents');

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/media-categories', $this->validCategoryData([
                'parent_id' => $parentId,
                'name' => 'PDF',
                'slug' => 'pdf',
            ]))
            ->assertRedirect('https://tenant-a.localhost/admin/media-categories');

        $this->assertDatabaseHas('media_categories', [
            'customer_id' => $customerId,
            'parent_id' => $parentId,
            'name' => 'PDF',
            'slug' => 'pdf',
        ]);
    }

    public function test_validation_rejects_invalid_media_category_data(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/media-categories/create')
            ->post('https://tenant-a.localhost/admin/media-categories', [
                'name' => '',
                'slug' => '',
                'sort_order' => -1,
                'status' => 'inactive',
                'metadata' => '{invalid-json}',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/media-categories/create')
            ->assertSessionHasErrors([
                'name',
                'slug',
                'status',
                'metadata',
            ]);

        $this->assertDatabaseCount('media_categories', 0);
    }

    public function test_parent_selection_and_record_access_are_tenant_isolated(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $ownCategoryId = $this->createCategory($customerId, 'Own Media', 'own-media');
        $otherCategoryId = $this->createCategory($otherCustomerId, 'Other Media', 'other-media');

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/media-categories/{$otherCategoryId}/edit")
            ->assertNotFound();

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/media-categories/{$otherCategoryId}",
                $this->validCategoryData(['name' => 'Changed', 'slug' => 'changed'])
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/media-categories/{$otherCategoryId}/archive")
            ->assertNotFound();

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/media-categories', $this->validCategoryData([
                'parent_id' => $otherCategoryId,
                'name' => 'Invalid Child',
                'slug' => 'invalid-child',
            ]))
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseHas('media_categories', [
            'id' => $ownCategoryId,
            'customer_id' => $customerId,
            'name' => 'Own Media',
        ]);
        $this->assertDatabaseHas('media_categories', [
            'id' => $otherCategoryId,
            'customer_id' => $otherCustomerId,
            'name' => 'Other Media',
        ]);
        $this->assertDatabaseMissing('media_categories', [
            'customer_id' => $customerId,
            'slug' => 'invalid-child',
        ]);
    }

    public function test_slug_is_unique_per_tenant(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $this->createCategory($customerId, 'Images', 'images');

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/media-categories', $this->validCategoryData([
                'name' => 'Duplicate Images',
                'slug' => 'images',
            ]))
            ->assertSessionHasErrors('slug');

        $this->actingAs($otherAdmin)
            ->post('https://tenant-b.localhost/admin/media-categories', $this->validCategoryData([
                'name' => 'Tenant B Images',
                'slug' => 'images',
            ]))
            ->assertRedirect('https://tenant-b.localhost/admin/media-categories');

        $this->assertDatabaseHas('media_categories', [
            'customer_id' => $otherCustomerId,
            'slug' => 'images',
        ]);
    }

    public function test_media_category_can_be_updated_and_archived_without_hard_delete(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'Audio', 'audio');

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/media-categories/{$categoryId}",
                $this->validCategoryData([
                    'name' => 'Audio Assets',
                    'slug' => 'audio-assets',
                    'status' => 'active',
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/media-categories/{$categoryId}/edit"
            );

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/media-categories/{$categoryId}/archive")
            ->assertRedirect();

        $this->assertSame(1, DB::table('media_categories')->count());
        $this->assertDatabaseHas('media_categories', [
            'id' => $categoryId,
            'customer_id' => $customerId,
            'name' => 'Audio Assets',
            'slug' => 'audio-assets',
            'status' => 'archived',
        ]);
    }

    public function test_category_with_children_cannot_be_archived(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $parentId = $this->createCategory($customerId, 'Videos', 'videos');
        $this->createCategory($customerId, 'Lesson Videos', 'lesson-videos', $parentId);

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/media-categories/{$parentId}/archive")
            ->assertSessionHasErrors('category');

        $this->assertDatabaseHas('media_categories', [
            'id' => $parentId,
            'status' => 'active',
        ]);
    }

    public function test_category_hierarchy_cannot_be_made_circular(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $parentId = $this->createCategory($customerId, 'Images', 'images');
        $childId = $this->createCategory($customerId, 'Covers', 'covers', $parentId);

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/media-categories/{$parentId}",
                $this->validCategoryData([
                    'parent_id' => $childId,
                    'name' => 'Images',
                    'slug' => 'images',
                ])
            )
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseHas('media_categories', [
            'id' => $parentId,
            'parent_id' => null,
        ]);
    }

    public function test_guest_student_and_teacher_cannot_access_media_category_admin(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');
        $teacher = $this->createUser($customerId, 'teacher');

        $this->get('https://tenant-a.localhost/admin/media-categories')
            ->assertRedirect('https://tenant-a.localhost/login');

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/admin/media-categories')
            ->assertForbidden();

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/admin/media-categories')
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
        return DB::table('media_categories')->insertGetId([
            'customer_id' => $customerId,
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'description' => null,
            'icon' => null,
            'color' => null,
            'sort_order' => 1,
            'status' => $status,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validCategoryData(array $overrides = []): array
    {
        return array_merge([
            'parent_id' => null,
            'name' => 'Images',
            'slug' => 'images',
            'description' => null,
            'icon' => null,
            'color' => null,
            'sort_order' => 1,
            'status' => 'active',
            'metadata' => null,
        ], $overrides);
    }
}
