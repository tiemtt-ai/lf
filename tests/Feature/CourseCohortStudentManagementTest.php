<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CourseCohortStudentManagementTest extends TestCase
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

    public function test_admin_course_cohort_student_routes_exist_and_teacher_routes_do_not(): void
    {
        foreach ([
            'index',
            'create',
            'store',
            'show',
            'edit',
            'update',
            'archive',
        ] as $route) {
            $this->assertTrue(Route::has("admin.course-cohort-students.{$route}"));
            $this->assertFalse(Route::has("teacher.course-cohort-students.{$route}"));
        }
    }

    public function test_customer_admin_can_create_cohort_membership(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-cohort-students',
                $this->validMembershipData([
                    'cohort_id' => $cohortId,
                    'enrollment_id' => $enrollmentId,
                    'joined_at' => '2026-07-04 09:00:00',
                    'note' => 'Seat A1',
                ])
            )
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_cohort_students', [
            'customer_id' => $customerId,
            'cohort_id' => $cohortId,
            'enrollment_id' => $enrollmentId,
            'product_id' => $productId,
            'student_id' => $student->id,
            'assigned_by' => $admin->id,
            'status' => 'active',
            'note' => 'Seat A1',
            'metadata' => null,
        ]);
    }

    public function test_customer_admin_can_update_and_transfer_cohort_membership(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId, name: 'Morning');
        $newCohortId = $this->createCohort($customerId, $productId, $versionId, name: 'Evening');
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $membershipId = $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}",
                $this->validMembershipData([
                    'cohort_id' => $newCohortId,
                    'enrollment_id' => null,
                    'joined_at' => '2026-07-15 10:00:00',
                    'status' => 'completed',
                    'transfer_reason' => 'Schedule change',
                    'note' => 'Moved by admin',
                ])
            )
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}");

        $this->assertDatabaseHas('core_course_cohort_students', [
            'id' => $membershipId,
            'cohort_id' => $newCohortId,
            'transfer_from_cohort_id' => $cohortId,
            'status' => 'completed',
            'transfer_reason' => 'Schedule change',
            'note' => 'Moved by admin',
        ]);
    }

    public function test_cohort_student_update_preserves_internal_metadata(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId, name: 'Morning');
        $newCohortId = $this->createCohort($customerId, $productId, $versionId, name: 'Evening');
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $membershipId = $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);

        DB::table('core_course_cohort_students')
            ->where('id', $membershipId)
            ->update(['metadata' => '{"system":"internal"}']);

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}",
                $this->validMembershipData([
                    'cohort_id' => $newCohortId,
                    'enrollment_id' => null,
                    'joined_at' => '2026-07-15 10:00:00',
                    'note' => 'Visible operational note',
                    'metadata' => '{"system":"user submitted"}',
                ])
            )
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}");

        $this->assertDatabaseHas('core_course_cohort_students', [
            'id' => $membershipId,
            'note' => 'Visible operational note',
            'metadata' => '{"system":"internal"}',
        ]);
    }

    public function test_cohort_student_forms_show_note_and_hide_metadata_json(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $membershipId = $this->createMembership(
            $customerId,
            $cohortId,
            $enrollmentId,
            $productId,
            $student->id
        );

        foreach ([
            $this->actingAs($admin)
                ->get('https://tenant-a.localhost/admin/course-cohort-students/create')
                ->assertOk(),
            $this->actingAs($admin)
                ->get("https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}/edit")
                ->assertOk(),
            $this->actingAs($admin)
                ->get("https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}")
                ->assertOk(),
        ] as $response) {
            $response
                ->assertSeeText(__('lf.LF_course_cohort_student_common_note'))
                ->assertDontSeeText('Metadata JSON')
                ->assertDontSee('name="metadata"', false);
        }
    }

    public function test_customer_admin_can_archive_membership_without_hard_delete(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $membershipId = $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}/archive")
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}");

        $this->assertDatabaseHas('core_course_cohort_students', [
            'id' => $membershipId,
            'customer_id' => $customerId,
            'status' => 'removed',
        ]);
        $this->assertSame(1, DB::table('core_course_cohort_students')->count());
    }

    public function test_tenant_isolation_on_list_detail_update_and_archive(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $otherCustomerId = $this->createTenant('tenant-b');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $otherStudent = $this->createUser($otherCustomerId, 'student');
        $cohortId = $this->createCohort($customerId, $productId, $versionId, name: 'Tenant A Morning');
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $ownMembershipId = $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);
        $otherProductId = $this->createProduct($otherCustomerId, 'Tenant B Product', 'tenant-b-product');
        $otherVersionId = $this->createVersion($otherCustomerId, $otherAdmin->id, 'Tenant B Version');
        $otherCohortId = $this->createCohort($otherCustomerId, $otherProductId, $otherVersionId, name: 'Tenant B Evening');
        $otherEnrollmentId = $this->createEnrollment($otherCustomerId, $otherStudent->id, $otherProductId, $otherVersionId);
        $otherMembershipId = $this->createMembership($otherCustomerId, $otherCohortId, $otherEnrollmentId, $otherProductId, $otherStudent->id);

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-cohort-students')
            ->assertOk()
            ->assertSeeText('Tenant A Morning')
            ->assertDontSeeText('Tenant B Evening');

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohort-students/{$otherMembershipId}")
            ->assertNotFound();

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-cohort-students/{$otherMembershipId}",
                $this->validMembershipData(['cohort_id' => $cohortId])
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohort-students/{$otherMembershipId}/archive")
            ->assertNotFound();

        $this->assertDatabaseHas('core_course_cohort_students', [
            'id' => $ownMembershipId,
            'customer_id' => $customerId,
        ]);
        $this->assertDatabaseHas('core_course_cohort_students', [
            'id' => $otherMembershipId,
            'customer_id' => $otherCustomerId,
            'status' => 'active',
        ]);
    }

    public function test_teacher_and_student_cannot_access_admin_membership_routes(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $teacher = $this->createUser($customerId, 'teacher');
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $membershipId = $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);

        foreach ([$teacher, $student] as $user) {
            $this->actingAs($user)
                ->get('https://tenant-a.localhost/admin/course-cohort-students')
                ->assertForbidden();

            $this->actingAs($user)
                ->post("https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}/archive")
                ->assertForbidden();
        }

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher/course-cohort-students')
            ->assertNotFound();
    }

    public function test_cross_tenant_cohort_and_enrollment_are_rejected(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $otherCustomerId = $this->createTenant('tenant-b');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $otherStudent = $this->createUser($otherCustomerId, 'student');
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $otherProductId = $this->createProduct($otherCustomerId, 'Tenant B Product', 'tenant-b-product');
        $otherVersionId = $this->createVersion($otherCustomerId, $otherAdmin->id, 'Tenant B Version');
        $otherCohortId = $this->createCohort($otherCustomerId, $otherProductId, $otherVersionId);
        $otherEnrollmentId = $this->createEnrollment($otherCustomerId, $otherStudent->id, $otherProductId, $otherVersionId);

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-cohort-students/create')
            ->post(
                'https://tenant-a.localhost/admin/course-cohort-students',
                $this->validMembershipData([
                    'cohort_id' => $otherCohortId,
                    'enrollment_id' => $enrollmentId,
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-cohort-students/create')
            ->assertSessionHasErrors('cohort_id');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-cohort-students/create')
            ->post(
                'https://tenant-a.localhost/admin/course-cohort-students',
                $this->validMembershipData([
                    'cohort_id' => $cohortId,
                    'enrollment_id' => $otherEnrollmentId,
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-cohort-students/create')
            ->assertSessionHasErrors('enrollment_id');

        $this->assertDatabaseCount('core_course_cohort_students', 0);
    }

    public function test_duplicate_membership_archived_cohort_and_product_mismatch_are_rejected(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);
        $otherProductId = $this->createProduct($customerId, 'Other Product', 'other-product');
        $otherVersionId = $this->createVersion($customerId, $admin->id, 'Other Version');
        $mismatchCohortId = $this->createCohort($customerId, $otherProductId, $otherVersionId);
        $archivedCohortId = $this->createCohort($customerId, $productId, $versionId, status: 'archived');
        $newStudent = $this->createUser($customerId, 'student');
        $newEnrollmentId = $this->createEnrollment($customerId, $newStudent->id, $productId, $versionId);

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-cohort-students/create')
            ->post(
                'https://tenant-a.localhost/admin/course-cohort-students',
                $this->validMembershipData([
                    'cohort_id' => $cohortId,
                    'enrollment_id' => $enrollmentId,
                ])
            )
            ->assertSessionHasErrors('enrollment_id');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-cohort-students/create')
            ->post(
                'https://tenant-a.localhost/admin/course-cohort-students',
                $this->validMembershipData([
                    'cohort_id' => $archivedCohortId,
                    'enrollment_id' => $newEnrollmentId,
                ])
            )
            ->assertSessionHasErrors('cohort_id');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-cohort-students/create')
            ->post(
                'https://tenant-a.localhost/admin/course-cohort-students',
                $this->validMembershipData([
                    'cohort_id' => $mismatchCohortId,
                    'enrollment_id' => $newEnrollmentId,
                ])
            )
            ->assertSessionHasErrors('enrollment_id');

        $this->assertSame(1, DB::table('core_course_cohort_students')->count());
    }

    public function test_no_eloquent_model_was_added_for_cohort_students(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/CoreCourseCohortStudent.php'));
        $this->assertFileDoesNotExist(app_path('Models/CourseCohortStudent.php'));
    }

    private function learningContext(): array
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id, 'TOPIK Beginner');

        return [$customerId, $admin, $student, $productId, $versionId];
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
            'product_code' => strtoupper($slug),
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
            'thumbnail_type_snapshot' => 'image',
            'thumbnail_image_snapshot' => null,
            'thumbnail_video_source_snapshot' => null,
            'thumbnail_video_url_snapshot' => null,
            'thumbnail_video_media_id_snapshot' => null,
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

    private function createCohort(
        int $customerId,
        int $productId,
        int $versionId,
        string $name = 'TOPIK Beginner Morning',
        string $status = 'active',
        ?int $capacity = null
    ): int {
        $now = now();

        return DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'teacher_id' => null,
            'name' => $name,
            'code' => 'COH-'.uniqid(),
            'description' => null,
            'status' => $status,
            'capacity' => $capacity,
            'start_date' => null,
            'end_date' => null,
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

    private function createMembership(
        int $customerId,
        int $cohortId,
        int $enrollmentId,
        int $productId,
        int $studentId
    ): int {
        $now = now();

        return DB::table('core_course_cohort_students')->insertGetId([
            'customer_id' => $customerId,
            'cohort_id' => $cohortId,
            'enrollment_id' => $enrollmentId,
            'product_id' => $productId,
            'student_id' => $studentId,
            'assigned_by' => null,
            'joined_at' => $now,
            'left_at' => null,
            'status' => 'active',
            'transfer_from_cohort_id' => null,
            'transfer_reason' => null,
            'note' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function validMembershipData(array $overrides = []): array
    {
        return array_merge([
            'cohort_id' => 1,
            'enrollment_id' => 1,
            'joined_at' => '2026-07-04 09:00:00',
            'left_at' => null,
            'status' => 'active',
            'transfer_reason' => null,
            'note' => null,
        ], $overrides);
    }
}
