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

class CourseCompletionRuntimeTest extends TestCase
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

    public function test_completion_schema_uses_version_id_and_has_no_certificate_state_fields(): void
    {
        $this->assertTrue(Schema::hasTable('core_course_completions'));
        $this->assertTrue(Schema::hasColumn('core_course_completions', 'version_id'));
        $this->assertFalse(Schema::hasColumn('core_course_completions', 'template_version_id'));
        $this->assertFalse(Schema::hasColumn('core_course_completions', 'certificate_eligible'));
        $this->assertFalse(Schema::hasColumn('core_course_completions', 'certificate_issued'));
        $this->assertFalse(Schema::hasColumn('core_course_completions', 'certificate_issued_at'));
    }

    public function test_runtime_completion_can_be_created_from_course_progress_context(): void
    {
        $context = $this->runtimeContext();

        $completionId = $this->createCompletionFromCourseProgress(
            $context['customer_id'],
            $context['course_progress_id'],
            [
                'enrollment_id' => 999999,
                'product_id' => 999999,
                'version_id' => 999999,
                'student_id' => 999999,
            ]
        );

        $this->assertDatabaseHas('core_course_completions', [
            'id' => $completionId,
            'customer_id' => $context['customer_id'],
            'enrollment_id' => $context['enrollment_id'],
            'course_progress_id' => $context['course_progress_id'],
            'student_id' => $context['student']->id,
            'product_id' => $context['product_id'],
            'version_id' => $context['version_id'],
            'completion_rule' => 'all_required_activities',
            'completion_source' => 'system',
            'status' => 'completed',
        ]);
    }

    public function test_one_completion_per_enrollment_cycle_but_re_enrollment_is_allowed(): void
    {
        $context = $this->learningContext();
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

        $this->createCompletionFromCourseProgress($context['customer_id'], $firstProgressId);
        $this->createCompletionFromCourseProgress($context['customer_id'], $secondProgressId);

        $this->assertSame(
            2,
            DB::table('core_course_completions')
                ->where('customer_id', $context['customer_id'])
                ->where('student_id', $context['student']->id)
                ->where('product_id', $context['product_id'])
                ->count()
        );

        $this->expectException(QueryException::class);

        $this->createCompletionFromCourseProgress($context['customer_id'], $firstProgressId);
    }

    public function test_completion_requires_valid_enrollment_reference(): void
    {
        $context = $this->runtimeContext();

        $this->expectException(QueryException::class);

        $this->insertCompletion([
            'customer_id' => $context['customer_id'],
            'enrollment_id' => 999999,
            'course_progress_id' => $context['course_progress_id'],
            'student_id' => $context['student']->id,
            'product_id' => $context['product_id'],
            'version_id' => $context['version_id'],
        ]);
    }

    public function test_completion_requires_valid_course_progress_reference(): void
    {
        $context = $this->runtimeContext();

        $this->expectException(QueryException::class);

        $this->insertCompletion([
            'customer_id' => $context['customer_id'],
            'enrollment_id' => $context['enrollment_id'],
            'course_progress_id' => 999999,
            'student_id' => $context['student']->id,
            'product_id' => $context['product_id'],
            'version_id' => $context['version_id'],
        ]);
    }

    public function test_no_manual_completion_crud_routes_exist(): void
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
            $this->assertFalse(Route::has("admin.course-completions.{$route}"));
            $this->assertFalse(Route::has("teacher.course-completions.{$route}"));
            $this->assertFalse(Route::has("student.course-completions.{$route}"));
            $this->assertFalse(Route::has("admin.course-completion.{$route}"));
            $this->assertFalse(Route::has("teacher.course-completion.{$route}"));
            $this->assertFalse(Route::has("student.course-completion.{$route}"));
        }
    }

    public function test_course_completion_module_has_no_eloquent_models(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/CoreCourseCompletion.php'));
        $this->assertFileDoesNotExist(app_path('Models/CourseCompletion.php'));
    }

    public function test_completion_does_not_store_certificate_state_or_create_out_of_scope_domains(): void
    {
        $this->assertFalse(Schema::hasColumn('core_course_completions', 'certificate_eligible'));
        $this->assertFalse(Schema::hasColumn('core_course_completions', 'certificate_issued'));
        $this->assertFalse(Schema::hasColumn('core_course_completions', 'certificate_issued_at'));
        $this->assertFalse(Schema::hasTable('track_events'));
        $this->assertFalse(Schema::hasTable('ai_recommendations'));
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

        return array_merge($context, [
            'enrollment_id' => $enrollmentId,
            'course_progress_id' => $courseProgressId,
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

    private function createCompletionFromCourseProgress(
        int $customerId,
        int $courseProgressId,
        array $ignoredUserInput = []
    ): int {
        $courseProgress = DB::table('core_course_progress')
            ->where('customer_id', $customerId)
            ->where('id', $courseProgressId)
            ->first();

        return $this->insertCompletion(array_merge($ignoredUserInput, [
            'customer_id' => $customerId,
            'enrollment_id' => $courseProgress->enrollment_id,
            'course_progress_id' => $courseProgress->id,
            'student_id' => $courseProgress->student_id,
            'product_id' => $courseProgress->product_id,
            'version_id' => $courseProgress->version_id,
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

    private function insertCompletion(array $overrides = []): int
    {
        $now = now();

        return DB::table('core_course_completions')->insertGetId(array_merge([
            'customer_id' => 1,
            'enrollment_id' => 1,
            'course_progress_id' => 1,
            'student_id' => 1,
            'product_id' => 1,
            'version_id' => 1,
            'completion_rule' => 'all_required_activities',
            'required_progress_percentage' => 100,
            'final_progress_percentage' => 100,
            'completed_lessons' => 0,
            'total_lessons' => 0,
            'completed_activities' => 0,
            'total_activities' => 0,
            'required_activities_completed' => 0,
            'required_activities_total' => 0,
            'assessment_completed' => 0,
            'assessment_total' => 0,
            'final_score' => null,
            'max_score' => null,
            'passed' => null,
            'completed_at' => $now,
            'completed_by' => null,
            'completion_source' => 'system',
            'status' => 'completed',
            'revoked_at' => null,
            'revoked_by' => null,
            'revoked_reason' => null,
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
            'status' => 'published',
            'published_at' => $now,
            'published_by' => $userId,
            'source_template_updated_at' => $now,
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
