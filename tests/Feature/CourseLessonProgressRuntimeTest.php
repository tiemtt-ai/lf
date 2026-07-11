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

class CourseLessonProgressRuntimeTest extends TestCase
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

    public function test_lesson_progress_schema_uses_version_id_not_template_version_id(): void
    {
        $this->assertTrue(Schema::hasTable('core_course_lesson_progress'));
        $this->assertTrue(Schema::hasColumn('core_course_lesson_progress', 'version_id'));
        $this->assertFalse(Schema::hasColumn('core_course_lesson_progress', 'template_version_id'));
    }

    public function test_runtime_lesson_progress_can_be_created_from_course_progress_context(): void
    {
        $context = $this->learningContext();
        $enrollmentId = $this->createEnrollment(
            $context['customer_id'],
            $context['student']->id,
            $context['product_id'],
            $context['version_id']
        );
        $courseProgressId = $this->createProgressFromEnrollment(
            $context['customer_id'],
            $enrollmentId
        );
        $versionLessonId = $this->createVersionLesson(
            $context['customer_id'],
            $context['version_id']
        );

        $lessonProgressId = $this->createLessonProgressFromCourseProgress(
            $context['customer_id'],
            $courseProgressId,
            $versionLessonId,
            [
                'product_id' => 999999,
                'version_id' => 999999,
                'student_id' => 999999,
            ]
        );

        $this->assertDatabaseHas('core_course_lesson_progress', [
            'id' => $lessonProgressId,
            'customer_id' => $context['customer_id'],
            'enrollment_id' => $enrollmentId,
            'course_progress_id' => $courseProgressId,
            'student_id' => $context['student']->id,
            'product_id' => $context['product_id'],
            'version_id' => $context['version_id'],
            'version_lesson_id' => $versionLessonId,
            'status' => 'not_started',
            'completed_activities' => 0,
        ]);
    }

    public function test_one_lesson_progress_per_enrollment_cycle_and_version_lesson_but_re_enrollment_is_allowed(): void
    {
        $context = $this->learningContext();
        $versionLessonId = $this->createVersionLesson(
            $context['customer_id'],
            $context['version_id']
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
        $firstProgressId = $this->createProgressFromEnrollment(
            $context['customer_id'],
            $firstEnrollmentId
        );
        $secondProgressId = $this->createProgressFromEnrollment(
            $context['customer_id'],
            $secondEnrollmentId
        );

        $this->createLessonProgressFromCourseProgress(
            $context['customer_id'],
            $firstProgressId,
            $versionLessonId
        );
        $this->createLessonProgressFromCourseProgress(
            $context['customer_id'],
            $secondProgressId,
            $versionLessonId
        );

        $this->assertSame(
            2,
            DB::table('core_course_lesson_progress')
                ->where('customer_id', $context['customer_id'])
                ->where('student_id', $context['student']->id)
                ->where('version_lesson_id', $versionLessonId)
                ->count()
        );

        $this->expectException(QueryException::class);

        $this->createLessonProgressFromCourseProgress(
            $context['customer_id'],
            $firstProgressId,
            $versionLessonId
        );
    }

    public function test_lesson_progress_requires_valid_runtime_references(): void
    {
        $context = $this->learningContext();
        $enrollmentId = $this->createEnrollment(
            $context['customer_id'],
            $context['student']->id,
            $context['product_id'],
            $context['version_id']
        );
        $courseProgressId = $this->createProgressFromEnrollment(
            $context['customer_id'],
            $enrollmentId
        );

        $this->expectException(QueryException::class);

        $this->insertLessonProgress([
            'customer_id' => $context['customer_id'],
            'enrollment_id' => $enrollmentId,
            'course_progress_id' => $courseProgressId,
            'student_id' => $context['student']->id,
            'product_id' => $context['product_id'],
            'version_id' => $context['version_id'],
            'version_lesson_id' => 999999,
        ]);
    }

    public function test_no_manual_lesson_progress_crud_routes_exist(): void
    {
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
            $this->assertFalse(Route::has("admin.course-lesson-progress.{$route}"));
            $this->assertFalse(Route::has("teacher.course-lesson-progress.{$route}"));
            $this->assertFalse(Route::has("student.course-lesson-progress.{$route}"));
        }
    }

    public function test_course_lesson_progress_module_has_no_eloquent_models(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/CoreCourseLessonProgress.php'));
        $this->assertFileDoesNotExist(app_path('Models/CourseLessonProgress.php'));
    }

    public function test_lesson_progress_does_not_create_out_of_scope_runtime_tables(): void
    {
        $this->assertFalse(Schema::hasTable('core_course_completion'));
        $this->assertFalse(Schema::hasTable('core_course_certificates'));
        $this->assertFalse(Schema::hasTable('track_events'));
    }

    private function learningContext(): array
    {
        $customerId = $this->createTenant();
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

    private function createLessonProgressFromCourseProgress(
        int $customerId,
        int $courseProgressId,
        int $versionLessonId,
        array $ignoredUserInput = []
    ): int {
        $courseProgress = DB::table('core_course_progress')
            ->where('customer_id', $customerId)
            ->where('id', $courseProgressId)
            ->first();

        return $this->insertLessonProgress(array_merge($ignoredUserInput, [
            'customer_id' => $customerId,
            'enrollment_id' => $courseProgress->enrollment_id,
            'course_progress_id' => $courseProgress->id,
            'student_id' => $courseProgress->student_id,
            'product_id' => $courseProgress->product_id,
            'version_id' => $courseProgress->version_id,
            'version_lesson_id' => $versionLessonId,
        ]));
    }

    private function createProgressFromEnrollment(int $customerId, int $enrollmentId): int
    {
        $enrollment = DB::table('core_course_enrollments')
            ->where('customer_id', $customerId)
            ->where('id', $enrollmentId)
            ->first();

        return $this->insertProgress([
            'customer_id' => $customerId,
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'product_id' => $enrollment->product_id,
            'version_id' => $enrollment->version_id,
        ]);
    }

    private function insertLessonProgress(array $overrides = []): int
    {
        $now = now();

        return DB::table('core_course_lesson_progress')->insertGetId(array_merge([
            'customer_id' => 1,
            'enrollment_id' => 1,
            'course_progress_id' => 1,
            'student_id' => 1,
            'product_id' => 1,
            'version_id' => 1,
            'version_section_id' => null,
            'version_lesson_id' => 1,
            'sort_order' => 1,
            'progress_percentage' => 0,
            'completed_activities' => 0,
            'total_activities' => 0,
            'required_activities_completed' => 0,
            'required_activities_total' => 0,
            'total_learning_seconds' => 0,
            'first_accessed_at' => null,
            'last_accessed_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'status' => 'not_started',
            'recalculated_at' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function insertProgress(array $overrides = []): int
    {
        $now = now();

        return DB::table('core_course_progress')->insertGetId(array_merge([
            'customer_id' => 1,
            'enrollment_id' => 1,
            'student_id' => 1,
            'product_id' => 1,
            'version_id' => 1,
            'progress_percentage' => 0,
            'completed_lessons' => 0,
            'total_lessons' => 0,
            'completed_activities' => 0,
            'total_activities' => 0,
            'required_activities_completed' => 0,
            'required_activities_total' => 0,
            'assessment_completed' => 0,
            'assessment_total' => 0,
            'total_learning_seconds' => 0,
            'last_version_activity_id' => null,
            'last_version_lesson_id' => null,
            'last_accessed_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'status' => 'not_started',
            'recalculated_at' => null,
            'metadata' => null,
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
