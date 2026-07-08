<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CourseCohortManagementTest extends TestCase
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

    public function test_admin_course_cohort_routes_exist_and_teacher_routes_do_not(): void
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
            $this->assertTrue(Route::has("admin.course-cohorts.{$route}"));
            $this->assertFalse(Route::has("teacher.course-cohorts.{$route}"));
        }
    }

    public function test_customer_admin_can_create_cohort(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id);

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-cohorts',
                $this->validCohortData([
                    'product_id' => $productId,
                    'version_id' => $versionId,
                    'teacher_id' => $teacher->id,
                    'name' => 'TOPIK Beginner Morning Class',
                    'description' => 'Morning operational class.',
                    'status' => 'active',
                    'capacity' => 30,
                    'start_date' => '2026-07-01',
                    'end_date' => '2026-09-30',
                    'notes' => 'Bring printed placement tests.',
                ])
            )
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_cohorts', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'teacher_id' => $teacher->id,
            'name' => 'TOPIK Beginner Morning Class',
            'code' => 'COH-20260704-001',
            'status' => 'active',
            'capacity' => 30,
            'notes' => 'Bring printed placement tests.',
            'metadata' => null,
        ]);
    }

    public function test_customer_admin_can_update_cohort(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id);
        $cohortId = $this->createCohort($customerId);

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}",
                $this->validCohortData([
                    'product_id' => $productId,
                    'version_id' => $versionId,
                    'teacher_id' => $teacher->id,
                    'name' => 'TOPIK Beginner Weekend Class',
                    'code' => 'MANUAL-CHANGE',
                    'status' => 'completed',
                    'capacity' => 24,
                    'start_date' => '2026-08-01',
                    'end_date' => '2026-10-31',
                    'notes' => 'Weekend cohort moved to room B.',
                ])
            )
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}");

        $this->assertDatabaseHas('core_course_cohorts', [
            'id' => $cohortId,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'teacher_id' => $teacher->id,
            'name' => 'TOPIK Beginner Weekend Class',
            'code' => 'COH-EXISTING',
            'status' => 'completed',
            'capacity' => 24,
            'notes' => 'Weekend cohort moved to room B.',
        ]);
    }

    public function test_cohort_forms_show_real_notes_and_hide_metadata_json(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohort($customerId);

        DB::table('core_course_cohorts')
            ->where('id', $cohortId)
            ->update([
                'notes' => 'Bring printed placement tests.',
                'metadata' => '{"notes":"Metadata must stay internal."}',
            ]);

        foreach ([
            $this->actingAs($admin)
                ->get('https://tenant-a.localhost/admin/course-cohorts/create')
                ->assertOk(),
            $this->actingAs($admin)
                ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/edit")
                ->assertOk()
                ->assertSee('Bring printed placement tests.'),
            $this->actingAs($admin)
                ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}")
                ->assertOk()
                ->assertSeeText('Bring printed placement tests.'),
        ] as $response) {
            $response
                ->assertSeeText(__('lf.LF_course_cohort_common_notes'))
                ->assertDontSeeText('Metadata JSON')
                ->assertDontSee('Metadata must stay internal.')
                ->assertDontSee('name="metadata"', false);
        }
    }

    public function test_cohort_update_preserves_internal_metadata(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohort($customerId);

        DB::table('core_course_cohorts')
            ->where('id', $cohortId)
            ->update(['metadata' => '{"system":"internal"}']);

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}",
                $this->validCohortData([
                    'name' => 'Updated Cohort',
                    'notes' => 'Visible operational note',
                    'metadata' => '{"system":"user submitted"}',
                ])
            )
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}");

        $this->assertDatabaseHas('core_course_cohorts', [
            'id' => $cohortId,
            'name' => 'Updated Cohort',
            'notes' => 'Visible operational note',
            'metadata' => '{"system":"internal"}',
        ]);
    }

    public function test_customer_admin_can_archive_cohort_without_hard_delete(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohort($customerId, status: 'active');

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/archive")
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}");

        $this->assertDatabaseHas('core_course_cohorts', [
            'id' => $cohortId,
            'customer_id' => $customerId,
            'status' => 'archived',
        ]);
    }

    public function test_tenant_isolation_on_list_detail_update_and_archive(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $ownCohortId = $this->createCohort($customerId, name: 'Tenant A Morning');
        $otherCohortId = $this->createCohort($otherCustomerId, name: 'Tenant B Evening');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-cohorts')
            ->assertOk()
            ->assertSeeText('Tenant A Morning')
            ->assertDontSeeText('Tenant B Evening');

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$otherCohortId}")
            ->assertNotFound();

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-cohorts/{$otherCohortId}",
                $this->validCohortData(['name' => 'Changed Other Cohort'])
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$otherCohortId}/archive")
            ->assertNotFound();

        $this->assertDatabaseHas('core_course_cohorts', [
            'id' => $ownCohortId,
            'customer_id' => $customerId,
            'name' => 'Tenant A Morning',
        ]);
        $this->assertDatabaseHas('core_course_cohorts', [
            'id' => $otherCohortId,
            'customer_id' => $otherCustomerId,
            'name' => 'Tenant B Evening',
            'status' => 'active',
        ]);
    }

    public function test_teacher_and_student_cannot_access_admin_cohort_routes(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $cohortId = $this->createCohort($customerId);

        foreach ([$teacher, $student] as $user) {
            $this->actingAs($user)
                ->get('https://tenant-a.localhost/admin/course-cohorts')
                ->assertForbidden();

            $this->actingAs($user)
                ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/archive")
                ->assertForbidden();
        }

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher/course-cohorts')
            ->assertNotFound();
    }

    public function test_cross_tenant_teacher_product_and_version_are_rejected(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $otherTeacher = $this->createUser($otherCustomerId, 'teacher');
        $otherProductId = $this->createProduct(
            $otherCustomerId,
            'Tenant B Product',
            'tenant-b-product'
        );
        $otherVersionId = $this->createVersion($otherCustomerId, $otherAdmin->id);

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-cohorts/create')
            ->post(
                'https://tenant-a.localhost/admin/course-cohorts',
                $this->validCohortData(['teacher_id' => $otherTeacher->id])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-cohorts/create')
            ->assertSessionHasErrors('teacher_id');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-cohorts/create')
            ->post(
                'https://tenant-a.localhost/admin/course-cohorts',
                $this->validCohortData(['product_id' => $otherProductId])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-cohorts/create')
            ->assertSessionHasErrors('product_id');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-cohorts/create')
            ->post(
                'https://tenant-a.localhost/admin/course-cohorts',
                $this->validCohortData(['version_id' => $otherVersionId])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-cohorts/create')
            ->assertSessionHasErrors('version_id');

        $this->assertDatabaseCount('core_course_cohorts', 0);
    }

    public function test_cohort_code_sequence_is_tenant_scoped_and_ignores_manual_input(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-cohorts',
                $this->validCohortData([
                    'name' => 'Tenant A Morning',
                    'code' => 'MANUAL-CODE',
                ])
            )
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-cohorts',
                $this->validCohortData(['name' => 'Tenant A Evening'])
            )
            ->assertRedirect();

        $this->actingAs($otherAdmin)
            ->post(
                'https://tenant-b.localhost/admin/course-cohorts',
                $this->validCohortData(['name' => 'Tenant B Morning'])
            )
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_cohorts', [
            'customer_id' => $customerId,
            'name' => 'Tenant A Morning',
            'code' => 'COH-20260704-001',
        ]);
        $this->assertDatabaseHas('core_course_cohorts', [
            'customer_id' => $customerId,
            'name' => 'Tenant A Evening',
            'code' => 'COH-20260704-002',
        ]);
        $this->assertDatabaseHas('core_course_cohorts', [
            'customer_id' => $otherCustomerId,
            'name' => 'Tenant B Morning',
            'code' => 'COH-20260704-001',
        ]);
    }

    public function test_cohort_code_input_is_not_rendered_on_create_or_edit_forms(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohort($customerId);

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-cohorts/create')
            ->assertOk()
            ->assertDontSee('name="code"', false);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/edit")
            ->assertOk()
            ->assertDontSee('name="code"', false)
            ->assertSeeText('COH-EXISTING');
    }

    public function test_non_teacher_and_unpublished_version_are_rejected(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $draftVersionId = $this->createVersion(
            $customerId,
            $admin->id,
            status: 'draft_snapshot'
        );

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-cohorts/create')
            ->post(
                'https://tenant-a.localhost/admin/course-cohorts',
                $this->validCohortData([
                    'teacher_id' => $student->id,
                    'version_id' => $draftVersionId,
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-cohorts/create')
            ->assertSessionHasErrors(['teacher_id', 'version_id']);

        $this->assertDatabaseCount('core_course_cohorts', 0);
    }

    public function test_course_cohort_module_has_no_eloquent_models(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/CoreCourseCohort.php'));
        $this->assertFileDoesNotExist(app_path('Models/CourseCohort.php'));
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

    private function createProduct(
        int $customerId,
        string $title,
        string $slug,
        string $status = 'active'
    ): int {
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
            'status' => $status,
            'created_by' => null,
            'published_at' => $status === 'active' ? $now : null,
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

    private function createVersion(
        int $customerId,
        int $userId,
        string $title = 'TOPIK Version',
        string $status = 'published',
        ?int $templateId = null,
        int $versionNumber = 1
    ): int {
        $now = now();
        $templateId ??= $this->createTemplate($customerId, $userId, $title);

        return DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'version_number' => $versionNumber,
            'version_code' => 'VERSION-'.$templateId.'-'.$versionNumber,
            'is_current' => $status === 'published',
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
            'status' => $status,
            'published_at' => $status === 'published' ? $now : null,
            'published_by' => $userId,
            'source_template_updated_at' => $now,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createCohort(
        int $customerId,
        string $name = 'TOPIK Beginner Morning',
        string $status = 'active'
    ): int {
        $now = now();

        return DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $customerId,
            'product_id' => null,
            'version_id' => null,
            'teacher_id' => null,
            'name' => $name,
            'code' => 'COH-EXISTING',
            'description' => null,
            'status' => $status,
            'capacity' => null,
            'start_date' => null,
            'end_date' => null,
            'notes' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function validCohortData(array $overrides = []): array
    {
        return array_merge([
            'product_id' => null,
            'version_id' => null,
            'teacher_id' => null,
            'name' => 'TOPIK Beginner Morning',
            'description' => null,
            'status' => 'active',
            'capacity' => null,
            'start_date' => null,
            'end_date' => null,
            'notes' => null,
        ], $overrides);
    }
}
