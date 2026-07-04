<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CourseLearningPathFoundationTest extends TestCase
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

    public function test_learning_path_tables_exist_with_documented_fields(): void
    {
        $this->assertTrue(Schema::hasTable('core_course_learning_paths'));
        $this->assertTrue(Schema::hasTable('core_course_learning_path_items'));

        foreach ([
            'customer_id',
            'path_code',
            'name',
            'description',
            'thumbnail_file_id',
            'difficulty_level',
            'estimated_duration_days',
            'certificate_available',
            'visibility',
            'sort_order',
            'status',
            'metadata',
            'created_by',
            'updated_by',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('core_course_learning_paths', $column));
        }

        foreach ([
            'customer_id',
            'learning_path_id',
            'product_id',
            'prerequisite_product_id',
            'item_type',
            'is_required',
            'unlock_rule',
            'completion_required',
            'title_override',
            'description_override',
            'sort_order',
            'status',
            'metadata',
            'created_by',
            'updated_by',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('core_course_learning_path_items', $column));
        }
    }

    public function test_learning_path_code_is_unique_per_tenant_only(): void
    {
        $firstCustomerId = $this->createTenant('tenant-a');
        $secondCustomerId = $this->createTenant('tenant-b');

        $this->createLearningPath($firstCustomerId, 'TOPIK-PATH');
        $this->createLearningPath($secondCustomerId, 'TOPIK-PATH');

        $this->assertSame(
            2,
            DB::table('core_course_learning_paths')
                ->where('path_code', 'TOPIK-PATH')
                ->count()
        );

        $this->expectException(QueryException::class);

        $this->createLearningPath($firstCustomerId, 'TOPIK-PATH');
    }

    public function test_items_reference_learning_path_product_and_prerequisite_product(): void
    {
        $context = $this->learningPathContext();

        $itemId = $this->createLearningPathItem(
            $context['customer_id'],
            $context['learning_path_id'],
            $context['intermediate_product_id'],
            [
                'prerequisite_product_id' => $context['beginner_product_id'],
                'sort_order' => 2,
                'unlock_rule' => 'after_prerequisite_completed',
            ]
        );

        $this->assertDatabaseHas('core_course_learning_path_items', [
            'id' => $itemId,
            'customer_id' => $context['customer_id'],
            'learning_path_id' => $context['learning_path_id'],
            'product_id' => $context['intermediate_product_id'],
            'prerequisite_product_id' => $context['beginner_product_id'],
            'sort_order' => 2,
            'unlock_rule' => 'after_prerequisite_completed',
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);

        $this->createLearningPathItem(
            $context['customer_id'],
            999999,
            $context['beginner_product_id']
        );
    }

    public function test_items_are_ordered_within_learning_path(): void
    {
        $context = $this->learningPathContext();

        $this->createLearningPathItem(
            $context['customer_id'],
            $context['learning_path_id'],
            $context['advanced_product_id'],
            ['sort_order' => 3, 'title_override' => 'TOPIK Advanced']
        );
        $this->createLearningPathItem(
            $context['customer_id'],
            $context['learning_path_id'],
            $context['beginner_product_id'],
            ['sort_order' => 1, 'title_override' => 'TOPIK Beginner']
        );
        $this->createLearningPathItem(
            $context['customer_id'],
            $context['learning_path_id'],
            $context['intermediate_product_id'],
            ['sort_order' => 2, 'title_override' => 'TOPIK Intermediate']
        );

        $orderedTitles = DB::table('core_course_learning_path_items')
            ->where('customer_id', $context['customer_id'])
            ->where('learning_path_id', $context['learning_path_id'])
            ->orderBy('sort_order')
            ->pluck('title_override')
            ->all();

        $this->assertSame([
            'TOPIK Beginner',
            'TOPIK Intermediate',
            'TOPIK Advanced',
        ], $orderedTitles);
    }

    public function test_same_product_cannot_be_duplicated_in_same_path_but_can_appear_in_different_paths(): void
    {
        $context = $this->learningPathContext();
        $secondPathId = $this->createLearningPath($context['customer_id'], 'TOPIK-PATH-ALT');

        $this->createLearningPathItem(
            $context['customer_id'],
            $context['learning_path_id'],
            $context['beginner_product_id']
        );
        $this->createLearningPathItem(
            $context['customer_id'],
            $secondPathId,
            $context['beginner_product_id']
        );

        $this->assertSame(
            2,
            DB::table('core_course_learning_path_items')
                ->where('customer_id', $context['customer_id'])
                ->where('product_id', $context['beginner_product_id'])
                ->count()
        );

        $this->expectException(QueryException::class);

        $this->createLearningPathItem(
            $context['customer_id'],
            $context['learning_path_id'],
            $context['beginner_product_id'],
            ['sort_order' => 9]
        );
    }

    public function test_invalid_product_reference_is_rejected(): void
    {
        $context = $this->learningPathContext();

        $this->expectException(QueryException::class);

        $this->createLearningPathItem(
            $context['customer_id'],
            $context['learning_path_id'],
            999999
        );
    }

    public function test_no_manual_learning_path_crud_routes_exist(): void
    {
        foreach ([
            'course-learning-paths',
            'course-learning-path-items',
        ] as $resource) {
            foreach ([
                'index',
                'create',
                'store',
                'show',
                'edit',
                'update',
                'destroy',
                'archive',
            ] as $route) {
                $this->assertFalse(Route::has("admin.{$resource}.{$route}"));
                $this->assertFalse(Route::has("teacher.{$resource}.{$route}"));
                $this->assertFalse(Route::has("student.{$resource}.{$route}"));
            }
        }
    }

    public function test_learning_path_module_has_no_eloquent_models(): void
    {
        foreach ([
            'CoreCourseLearningPath',
            'CourseLearningPath',
            'CoreCourseLearningPathItem',
            'CourseLearningPathItem',
        ] as $model) {
            $this->assertFileDoesNotExist(app_path("Models/{$model}.php"));
        }
    }

    public function test_learning_path_tables_do_not_store_runtime_or_out_of_scope_state(): void
    {
        foreach ([
            'core_course_learning_paths',
            'core_course_learning_path_items',
        ] as $table) {
            foreach ([
                'enrollment_id',
                'progress_id',
                'course_progress_id',
                'completion_id',
                'certificate_id',
                'tracking_event_id',
                'ai_context_id',
            ] as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column));
            }
        }
    }

    private function learningPathContext(): array
    {
        $customerId = $this->createTenant();
        $learningPathId = $this->createLearningPath($customerId, 'TOPIK-PATH');

        return [
            'customer_id' => $customerId,
            'learning_path_id' => $learningPathId,
            'beginner_product_id' => $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner'),
            'intermediate_product_id' => $this->createProduct($customerId, 'TOPIK Intermediate', 'topik-intermediate'),
            'advanced_product_id' => $this->createProduct($customerId, 'TOPIK Advanced', 'topik-advanced'),
        ];
    }

    private function createLearningPath(int $customerId, string $pathCode): int
    {
        $now = now();

        return DB::table('core_course_learning_paths')->insertGetId([
            'customer_id' => $customerId,
            'path_code' => $pathCode,
            'name' => 'TOPIK Full Learning Path',
            'description' => null,
            'thumbnail_file_id' => null,
            'difficulty_level' => 'mixed',
            'estimated_duration_days' => 365,
            'certificate_available' => true,
            'visibility' => 'public',
            'sort_order' => 1,
            'status' => 'active',
            'metadata' => null,
            'created_by' => null,
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createLearningPathItem(
        int $customerId,
        int $learningPathId,
        int $productId,
        array $overrides = []
    ): int {
        $now = now();

        return DB::table('core_course_learning_path_items')->insertGetId(array_merge([
            'customer_id' => $customerId,
            'learning_path_id' => $learningPathId,
            'product_id' => $productId,
            'prerequisite_product_id' => null,
            'item_type' => 'course_product',
            'is_required' => true,
            'unlock_rule' => 'always_available',
            'completion_required' => true,
            'title_override' => null,
            'description_override' => null,
            'sort_order' => 1,
            'status' => 'active',
            'metadata' => null,
            'created_by' => null,
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
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

    private function createProduct(int $customerId, string $title, string $slug): int
    {
        $now = now();

        return DB::table('core_course_products')->insertGetId([
            'customer_id' => $customerId,
            'product_code' => strtoupper($slug).'-'.uniqid(),
            'product_type' => 'single_course',
            'title' => $title,
            'slug' => $slug.'-'.uniqid(),
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
            'status' => 'active',
            'created_by' => null,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
