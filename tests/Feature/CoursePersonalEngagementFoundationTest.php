<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoursePersonalEngagementFoundationTest extends TestCase
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

    public function test_personal_engagement_tables_exist_with_documented_identity_fields(): void
    {
        foreach ([
            'core_course_favorites',
            'core_course_notes',
            'core_course_bookmarks',
            'core_course_reviews',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertTrue(Schema::hasColumn($table, 'customer_id'));
        }

        $this->assertTrue(Schema::hasColumn('core_course_favorites', 'student_id'));
        $this->assertFalse(Schema::hasColumn('core_course_favorites', 'enrollment_id'));

        foreach ([
            'core_course_notes',
            'core_course_bookmarks',
            'core_course_reviews',
        ] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'enrollment_id'));
            $this->assertTrue(Schema::hasColumn($table, 'user_id'));
            $this->assertTrue(Schema::hasColumn($table, 'product_id'));
            $this->assertTrue(Schema::hasColumn($table, 'version_id'));
            $this->assertFalse(Schema::hasColumn($table, 'template_version_id'));
        }

        foreach ([
            'core_course_notes',
            'core_course_bookmarks',
            'core_course_reviews',
        ] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'student_id'));
        }

        foreach ([
            'core_course_notes',
            'core_course_bookmarks',
        ] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'version_lesson_id'));
            $this->assertTrue(Schema::hasColumn($table, 'version_activity_id'));
            $this->assertFalse(Schema::hasColumn($table, 'template_lesson_id'));
            $this->assertFalse(Schema::hasColumn($table, 'template_activity_id'));
        }
    }

    public function test_favorites_do_not_require_enrollment_and_are_unique_per_student_product_tenant(): void
    {
        $first = $this->learningContext('tenant-a');
        $second = $this->learningContext('tenant-b');

        $this->insertFavorite([
            'customer_id' => $first['customer_id'],
            'student_id' => $first['student']->id,
            'product_id' => $first['product_id'],
        ]);
        $this->insertFavorite([
            'customer_id' => $second['customer_id'],
            'student_id' => $second['student']->id,
            'product_id' => $second['product_id'],
        ]);

        $this->assertSame(2, DB::table('core_course_favorites')->count());

        $this->expectException(QueryException::class);

        $this->insertFavorite([
            'customer_id' => $first['customer_id'],
            'student_id' => $first['student']->id,
            'product_id' => $first['product_id'],
        ]);
    }

    public function test_notes_bookmarks_and_reviews_are_created_from_enrollment_context(): void
    {
        $context = $this->engagementContext();

        $noteId = $this->createNoteFromEnrollment(
            $context['customer_id'],
            $context['enrollment_id'],
            $context['version_lesson_id'],
            $context['version_activity_id'],
            [
                'product_id' => 999999,
                'version_id' => 999999,
                'user_id' => 999999,
            ]
        );
        $bookmarkId = $this->createBookmarkFromEnrollment(
            $context['customer_id'],
            $context['enrollment_id'],
            $context['version_lesson_id'],
            $context['version_activity_id'],
            [
                'product_id' => 999999,
                'version_id' => 999999,
                'user_id' => 999999,
            ]
        );
        $reviewId = $this->createReviewFromEnrollment(
            $context['customer_id'],
            $context['enrollment_id'],
            [
                'product_id' => 999999,
                'version_id' => 999999,
                'user_id' => 999999,
            ]
        );

        foreach ([
            'core_course_notes' => $noteId,
            'core_course_bookmarks' => $bookmarkId,
            'core_course_reviews' => $reviewId,
        ] as $table => $id) {
            $this->assertDatabaseHas($table, [
                'id' => $id,
                'customer_id' => $context['customer_id'],
                'enrollment_id' => $context['enrollment_id'],
                'user_id' => $context['student']->id,
                'product_id' => $context['product_id'],
                'version_id' => $context['version_id'],
                'status' => 'active',
            ]);
        }
    }

    public function test_notes_bookmarks_and_reviews_require_valid_enrollment_reference(): void
    {
        $context = $this->engagementContext();

        foreach ([
            'core_course_notes' => fn () => $this->insertNote([
                'customer_id' => $context['customer_id'],
                'enrollment_id' => 999999,
                'user_id' => $context['student']->id,
                'product_id' => $context['product_id'],
                'version_id' => $context['version_id'],
                'version_lesson_id' => $context['version_lesson_id'],
                'version_activity_id' => $context['version_activity_id'],
            ]),
            'core_course_bookmarks' => fn () => $this->insertBookmark([
                'customer_id' => $context['customer_id'],
                'enrollment_id' => 999999,
                'user_id' => $context['student']->id,
                'product_id' => $context['product_id'],
                'version_id' => $context['version_id'],
                'version_lesson_id' => $context['version_lesson_id'],
                'version_activity_id' => $context['version_activity_id'],
            ]),
            'core_course_reviews' => fn () => $this->insertReview([
                'customer_id' => $context['customer_id'],
                'enrollment_id' => 999999,
                'user_id' => $context['student']->id,
                'product_id' => $context['product_id'],
                'version_id' => $context['version_id'],
            ]),
        ] as $table => $insert) {
            try {
                $insert();
                $this->fail("{$table} accepted an invalid enrollment reference.");
            } catch (QueryException) {
                $this->assertDatabaseMissing($table, ['enrollment_id' => 999999]);
            }
        }
    }

    public function test_re_enrollment_supports_separate_notes_bookmarks_and_reviews(): void
    {
        $context = $this->learningContext();
        $versionLessonId = $this->createVersionLesson(
            $context['customer_id'],
            $context['version_id']
        );
        $versionActivityId = $this->createVersionActivity(
            $context['customer_id'],
            $context['version_id'],
            $versionLessonId
        );
        $firstEnrollmentId = $this->createEnrollment(
            $context['customer_id'],
            $context['student']->id,
            $context['product_id'],
            $context['version_id']
        );
        $secondEnrollmentId = $this->createEnrollment(
            $context['customer_id'],
            $context['student']->id,
            $context['product_id'],
            $context['version_id']
        );

        foreach ([$firstEnrollmentId, $secondEnrollmentId] as $enrollmentId) {
            $this->createNoteFromEnrollment(
                $context['customer_id'],
                $enrollmentId,
                $versionLessonId,
                $versionActivityId
            );
            $this->createBookmarkFromEnrollment(
                $context['customer_id'],
                $enrollmentId,
                $versionLessonId,
                $versionActivityId
            );
            $this->createReviewFromEnrollment($context['customer_id'], $enrollmentId);
        }

        foreach ([
            'core_course_notes',
            'core_course_bookmarks',
            'core_course_reviews',
        ] as $table) {
            $this->assertSame(
                2,
                DB::table($table)
                    ->where('customer_id', $context['customer_id'])
                    ->where('user_id', $context['student']->id)
                    ->where('product_id', $context['product_id'])
                    ->count()
            );
        }

        $this->expectException(QueryException::class);

        $this->createReviewFromEnrollment($context['customer_id'], $firstEnrollmentId);
    }

    public function test_review_uses_user_id_not_student_id_and_has_no_assessment_ownership(): void
    {
        $this->assertTrue(Schema::hasColumn('core_course_reviews', 'user_id'));
        $this->assertFalse(Schema::hasColumn('core_course_reviews', 'student_id'));
        $this->assertFalse(Schema::hasColumn('core_course_reviews', 'assessment_id'));
        $this->assertFalse(Schema::hasColumn('core_course_reviews', 'assessment_attempt_id'));
        $this->assertFalse(Schema::hasColumn('core_course_reviews', 'assessment_result_id'));
    }

    public function test_no_manual_engagement_crud_routes_exist(): void
    {
        foreach ([
            'course-favorites',
            'course-notes',
            'course-bookmarks',
            'course-reviews',
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

    public function test_personal_engagement_module_has_no_eloquent_models(): void
    {
        foreach ([
            'CoreCourseFavorite',
            'CourseFavorite',
            'CoreCourseNote',
            'CourseNote',
            'CoreCourseBookmark',
            'CourseBookmark',
            'CoreCourseReview',
            'CourseReview',
        ] as $model) {
            $this->assertFileDoesNotExist(app_path("Models/{$model}.php"));
        }
    }

    public function test_personal_engagement_does_not_add_out_of_scope_runtime_domains(): void
    {
        foreach ([
            'core_course_notes',
            'core_course_bookmarks',
            'core_course_reviews',
        ] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'progress_id'));
            $this->assertFalse(Schema::hasColumn($table, 'completion_id'));
            $this->assertFalse(Schema::hasColumn($table, 'certificate_id'));
            $this->assertFalse(Schema::hasColumn($table, 'assessment_result_id'));
            $this->assertFalse(Schema::hasColumn($table, 'tracking_event_id'));
            $this->assertFalse(Schema::hasColumn($table, 'ai_context_id'));
        }

        $this->assertFalse(Schema::hasTable('track_events'));
        $this->assertFalse(Schema::hasTable('ai_recommendations'));
        $this->assertFalse(Schema::hasTable('core_assessment_results'));
    }

    private function engagementContext(): array
    {
        $context = $this->learningContext();
        $enrollmentId = $this->createEnrollment(
            $context['customer_id'],
            $context['student']->id,
            $context['product_id'],
            $context['version_id']
        );
        $versionLessonId = $this->createVersionLesson(
            $context['customer_id'],
            $context['version_id']
        );
        $versionActivityId = $this->createVersionActivity(
            $context['customer_id'],
            $context['version_id'],
            $versionLessonId
        );

        return array_merge($context, [
            'enrollment_id' => $enrollmentId,
            'version_lesson_id' => $versionLessonId,
            'version_activity_id' => $versionActivityId,
        ]);
    }

    private function learningContext(string $tenantSlug = 'tenant-a'): array
    {
        $customerId = $this->createTenant($tenantSlug);
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id, 'TOPIK Beginner');

        return [
            'customer_id' => $customerId,
            'admin' => $admin,
            'student' => $student,
            'product_id' => $productId,
            'version_id' => $versionId,
        ];
    }

    private function createNoteFromEnrollment(
        int $customerId,
        int $enrollmentId,
        int $versionLessonId,
        ?int $versionActivityId = null,
        array $ignoredUserInput = []
    ): int {
        $enrollment = $this->findTenantEnrollment($customerId, $enrollmentId);

        return $this->insertNote(array_merge($ignoredUserInput, [
            'customer_id' => $customerId,
            'enrollment_id' => $enrollment->id,
            'user_id' => $enrollment->student_id,
            'product_id' => $enrollment->product_id,
            'version_id' => $enrollment->version_id,
            'version_lesson_id' => $versionLessonId,
            'version_activity_id' => $versionActivityId,
        ]));
    }

    private function createBookmarkFromEnrollment(
        int $customerId,
        int $enrollmentId,
        int $versionLessonId,
        ?int $versionActivityId = null,
        array $ignoredUserInput = []
    ): int {
        $enrollment = $this->findTenantEnrollment($customerId, $enrollmentId);

        return $this->insertBookmark(array_merge($ignoredUserInput, [
            'customer_id' => $customerId,
            'enrollment_id' => $enrollment->id,
            'user_id' => $enrollment->student_id,
            'product_id' => $enrollment->product_id,
            'version_id' => $enrollment->version_id,
            'version_lesson_id' => $versionLessonId,
            'version_activity_id' => $versionActivityId,
        ]));
    }

    private function createReviewFromEnrollment(
        int $customerId,
        int $enrollmentId,
        array $ignoredUserInput = []
    ): int {
        $enrollment = $this->findTenantEnrollment($customerId, $enrollmentId);

        return $this->insertReview(array_merge($ignoredUserInput, [
            'customer_id' => $customerId,
            'enrollment_id' => $enrollment->id,
            'user_id' => $enrollment->student_id,
            'product_id' => $enrollment->product_id,
            'version_id' => $enrollment->version_id,
        ]));
    }

    private function findTenantEnrollment(int $customerId, int $enrollmentId): object
    {
        $enrollment = DB::table('core_course_enrollments')
            ->where('customer_id', $customerId)
            ->where('id', $enrollmentId)
            ->first();

        if (! $enrollment) {
            throw new \RuntimeException('Enrollment was not found for the current tenant.');
        }

        return $enrollment;
    }

    private function insertFavorite(array $overrides = []): int
    {
        $now = now();

        return DB::table('core_course_favorites')->insertGetId(array_merge([
            'customer_id' => 1,
            'product_id' => 1,
            'student_id' => 1,
            'source' => 'manual',
            'note' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function insertNote(array $overrides = []): int
    {
        $now = now();

        return DB::table('core_course_notes')->insertGetId(array_merge([
            'customer_id' => 1,
            'product_id' => 1,
            'version_id' => 1,
            'enrollment_id' => 1,
            'user_id' => 1,
            'version_section_id' => null,
            'version_lesson_id' => null,
            'version_activity_id' => null,
            'note_type' => 'text',
            'title' => 'Grammar reminder',
            'content' => 'Review this structure before speaking practice.',
            'position_seconds' => null,
            'page_number' => null,
            'visibility' => 'private',
            'pinned' => false,
            'status' => 'active',
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function insertBookmark(array $overrides = []): int
    {
        $now = now();

        return DB::table('core_course_bookmarks')->insertGetId(array_merge([
            'customer_id' => 1,
            'product_id' => 1,
            'version_id' => 1,
            'enrollment_id' => 1,
            'user_id' => 1,
            'version_section_id' => null,
            'version_lesson_id' => null,
            'version_activity_id' => null,
            'bookmark_type' => 'activity',
            'title' => 'Review later',
            'position_seconds' => null,
            'page_number' => null,
            'note' => null,
            'status' => 'active',
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function insertReview(array $overrides = []): int
    {
        $now = now();

        return DB::table('core_course_reviews')->insertGetId(array_merge([
            'customer_id' => 1,
            'product_id' => 1,
            'enrollment_id' => 1,
            'version_id' => 1,
            'user_id' => 1,
            'rating' => 5,
            'title' => 'Excellent course',
            'comment' => 'Useful and well structured.',
            'is_public' => true,
            'is_verified_purchase' => false,
            'status' => 'active',
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

    private function createUser(int $customerId, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => ucfirst(str_replace('_', ' ', $role)).' '.uniqid(),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
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

    private function createTemplate(int $customerId, int $userId, string $title): int
    {
        $now = now();

        return DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId,
            'category_id' => null,
            'title' => $title,
            'slug' => str($title)->slug()->toString().'-'.uniqid(),
            'short_description' => null,
            'description' => null,
            'publisher_name' => null,
            'cover_type' => 'image',
            'cover_image_media_file_id' => null,
            'intro_video_media_file_id' => null,
            'difficulty_level' => null,
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

    private function createVersion(int $customerId, int $userId, string $title): int
    {
        $now = now();
        $templateId = $this->createTemplate($customerId, $userId, $title);

        return DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'version_number' => 1,
            'version_code' => 'VERSION-'.$templateId.'-1',
            'is_current' => true,
            'source_category_id' => null,
            'category_name_snapshot' => null,
            'title_snapshot' => $title,
            'slug_snapshot' => str($title)->slug()->toString().'-version',
            'short_description_snapshot' => null,
            'description_snapshot' => null,
            'publisher_name_snapshot' => null,
            'cover_type_snapshot' => 'image',
            'cover_image_media_file_id_snapshot' => null,
            'intro_video_media_file_id_snapshot' => null,
            'difficulty_level_snapshot' => null,
            'estimated_duration_minutes_snapshot' => 0,
            'max_lessons_snapshot' => null,
            'lesson_count_snapshot' => 0,
            'meta_title_snapshot' => null,
            'meta_description_snapshot' => null,
            'meta_keywords_snapshot' => null,
            'source_working_revision' => 1,
            'status' => 'published',
            'published_at' => $now,
            'published_by' => $userId,
            'source_template_updated_at' => $now,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createVersionLesson(int $customerId, int $versionId): int
    {
        $now = now();

        return DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customerId,
            'template_version_id' => $versionId,
            'version_section_id' => null,
            'source_template_lesson_id' => random_int(100000, 999999),
            'title_snapshot' => 'Lesson 1',
            'short_description_snapshot' => null,
            'description_snapshot' => null,
            'sort_order' => 1,
            'is_preview' => false,
            'duration_seconds' => 0,
            'activity_count' => 0,
            'unlock_rule_snapshot' => 'none',
            'unlock_after_version_lesson_id' => null,
            'unlock_at_snapshot' => null,
            'created_by_snapshot' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createVersionActivity(
        int $customerId,
        int $versionId,
        int $versionLessonId
    ): int {
        $now = now();

        return DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $customerId,
            'template_version_id' => $versionId,
            'version_lesson_id' => $versionLessonId,
            'source_template_activity_id' => random_int(100000, 999999),
            'title_snapshot' => 'Lesson 1 Video',
            'description_snapshot' => null,
            'sort_order' => 1,
            'activity_type' => 'video',
            'activity_ref_type_snapshot' => null,
            'activity_ref_id_snapshot' => null,
            'external_url_snapshot' => null,
            'embed_code_snapshot' => null,
            'duration_seconds' => 0,
            'is_required' => true,
            'completion_rule' => 'watch_percent',
            'completion_threshold' => 80,
            'is_preview' => false,
            'unlock_rule_snapshot' => 'none',
            'unlock_after_version_activity_id' => null,
            'unlock_at_snapshot' => null,
            'status_snapshot' => 'active',
            'created_by_snapshot' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createEnrollment(
        int $customerId,
        int $studentId,
        int $productId,
        int $versionId
    ): int {
        $now = now();

        return DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $customerId,
            'student_id' => $studentId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'source' => 'admin',
            'source_id' => null,
            'enrolled_by' => null,
            'enrolled_at' => $now,
            'access_starts_at' => null,
            'access_ends_at' => null,
            'review_starts_at' => null,
            'review_ends_at' => null,
            'status' => 'active',
            'completed_at' => null,
            'cancelled_at' => null,
            'expired_at' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
