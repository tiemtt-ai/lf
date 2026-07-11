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

class CourseActivityProgressRuntimeTest extends TestCase
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

    public function test_activity_progress_schema_uses_version_id_not_template_version_id(): void
    {
        $this->assertTrue(Schema::hasTable('core_course_activity_progress'));
        $this->assertTrue(Schema::hasColumn('core_course_activity_progress', 'version_id'));
        $this->assertFalse(Schema::hasColumn('core_course_activity_progress', 'template_version_id'));
    }

    public function test_runtime_activity_progress_can_be_created_from_lesson_progress_context(): void
    {
        $context = $this->runtimeContext();

        $activityProgressId = $this->createActivityProgressFromLessonProgress(
            $context['customer_id'],
            $context['lesson_progress_id'],
            $context['version_activity_id'],
            [
                'enrollment_id' => 999999,
                'course_progress_id' => 999999,
                'product_id' => 999999,
                'version_id' => 999999,
                'student_id' => 999999,
                'version_lesson_id' => 999999,
            ]
        );

        $this->assertDatabaseHas('core_course_activity_progress', [
            'id' => $activityProgressId,
            'customer_id' => $context['customer_id'],
            'enrollment_id' => $context['enrollment_id'],
            'course_progress_id' => $context['course_progress_id'],
            'lesson_progress_id' => $context['lesson_progress_id'],
            'student_id' => $context['student']->id,
            'product_id' => $context['product_id'],
            'version_id' => $context['version_id'],
            'version_lesson_id' => $context['version_lesson_id'],
            'version_activity_id' => $context['version_activity_id'],
            'activity_type' => 'video',
            'status' => 'not_started',
        ]);
    }

    public function test_one_activity_progress_per_enrollment_cycle_and_version_activity_but_re_enrollment_is_allowed(): void
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
        $firstCourseProgressId = $this->createProgressFromEnrollment(
            $context['customer_id'],
            $firstEnrollmentId
        );
        $secondCourseProgressId = $this->createProgressFromEnrollment(
            $context['customer_id'],
            $secondEnrollmentId
        );
        $firstLessonProgressId = $this->createLessonProgressFromCourseProgress(
            $context['customer_id'],
            $firstCourseProgressId,
            $versionLessonId
        );
        $secondLessonProgressId = $this->createLessonProgressFromCourseProgress(
            $context['customer_id'],
            $secondCourseProgressId,
            $versionLessonId
        );

        $this->createActivityProgressFromLessonProgress(
            $context['customer_id'],
            $firstLessonProgressId,
            $versionActivityId
        );
        $this->createActivityProgressFromLessonProgress(
            $context['customer_id'],
            $secondLessonProgressId,
            $versionActivityId
        );

        $this->assertSame(
            2,
            DB::table('core_course_activity_progress')
                ->where('customer_id', $context['customer_id'])
                ->where('student_id', $context['student']->id)
                ->where('version_activity_id', $versionActivityId)
                ->count()
        );

        $this->expectException(QueryException::class);

        $this->createActivityProgressFromLessonProgress(
            $context['customer_id'],
            $firstLessonProgressId,
            $versionActivityId
        );
    }

    public function test_activity_progress_requires_valid_runtime_references(): void
    {
        $context = $this->runtimeContext();

        $this->expectException(QueryException::class);

        $this->insertActivityProgress([
            'customer_id' => $context['customer_id'],
            'enrollment_id' => $context['enrollment_id'],
            'course_progress_id' => $context['course_progress_id'],
            'lesson_progress_id' => $context['lesson_progress_id'],
            'student_id' => $context['student']->id,
            'product_id' => $context['product_id'],
            'version_id' => $context['version_id'],
            'version_lesson_id' => $context['version_lesson_id'],
            'version_activity_id' => 999999,
        ]);
    }

    public function test_no_manual_activity_progress_crud_routes_exist(): void
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
            $this->assertFalse(Route::has("admin.course-activity-progress.{$route}"));
            $this->assertFalse(Route::has("teacher.course-activity-progress.{$route}"));
            $this->assertFalse(Route::has("student.course-activity-progress.{$route}"));
        }
    }

    public function test_course_activity_progress_module_has_no_eloquent_models(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/CoreCourseActivityProgress.php'));
        $this->assertFileDoesNotExist(app_path('Models/CourseActivityProgress.php'));
    }

    public function test_activity_progress_does_not_create_out_of_scope_runtime_tables(): void
    {
        $this->assertFalse(Schema::hasTable('core_course_completion'));
        $this->assertFalse(Schema::hasTable('core_course_certificates'));
        $this->assertFalse(Schema::hasTable('track_events'));
    }

    private function runtimeContext(): array
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
            $versionLessonId
        );
        $versionActivityId = $this->createVersionActivity(
            $context['customer_id'],
            $context['version_id'],
            $versionLessonId
        );

        return array_merge($context, [
            'enrollment_id' => $enrollmentId,
            'course_progress_id' => $courseProgressId,
            'lesson_progress_id' => $lessonProgressId,
            'version_lesson_id' => $versionLessonId,
            'version_activity_id' => $versionActivityId,
        ]);
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

    private function createActivityProgressFromLessonProgress(
        int $customerId,
        int $lessonProgressId,
        int $versionActivityId,
        array $ignoredUserInput = []
    ): int {
        $lessonProgress = DB::table('core_course_lesson_progress')
            ->where('customer_id', $customerId)
            ->where('id', $lessonProgressId)
            ->first();

        return $this->insertActivityProgress(array_merge($ignoredUserInput, [
            'customer_id' => $customerId,
            'enrollment_id' => $lessonProgress->enrollment_id,
            'course_progress_id' => $lessonProgress->course_progress_id,
            'lesson_progress_id' => $lessonProgress->id,
            'student_id' => $lessonProgress->student_id,
            'product_id' => $lessonProgress->product_id,
            'version_id' => $lessonProgress->version_id,
            'version_lesson_id' => $lessonProgress->version_lesson_id,
            'version_activity_id' => $versionActivityId,
        ]));
    }

    private function createLessonProgressFromCourseProgress(
        int $customerId,
        int $courseProgressId,
        int $versionLessonId
    ): int {
        $courseProgress = DB::table('core_course_progress')
            ->where('customer_id', $customerId)
            ->where('id', $courseProgressId)
            ->first();

        return $this->insertLessonProgress([
            'customer_id' => $customerId,
            'enrollment_id' => $courseProgress->enrollment_id,
            'course_progress_id' => $courseProgress->id,
            'student_id' => $courseProgress->student_id,
            'product_id' => $courseProgress->product_id,
            'version_id' => $courseProgress->version_id,
            'version_lesson_id' => $versionLessonId,
        ]);
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

    private function insertActivityProgress(array $overrides = []): int
    {
        $now = now();

        return DB::table('core_course_activity_progress')->insertGetId(array_merge([
            'customer_id' => 1,
            'enrollment_id' => 1,
            'course_progress_id' => 1,
            'lesson_progress_id' => 1,
            'student_id' => 1,
            'product_id' => 1,
            'version_id' => 1,
            'version_section_id' => null,
            'version_lesson_id' => 1,
            'version_activity_id' => 1,
            'activity_type' => 'video',
            'sort_order' => 1,
            'is_required' => true,
            'progress_percentage' => 0,
            'completion_rule' => 'manual',
            'completion_threshold' => null,
            'score' => null,
            'max_score' => null,
            'passed' => null,
            'attempt_count' => 0,
            'total_learning_seconds' => 0,
            'last_position_seconds' => null,
            'first_accessed_at' => null,
            'last_accessed_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'submitted_at' => null,
            'graded_at' => null,
            'status' => 'not_started',
            'recalculated_at' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
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
            'status_snapshot' => 'published',
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
