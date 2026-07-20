<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CourseEnrollmentManagementTest extends TestCase
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

    public function test_admin_course_enrollment_routes_exist_and_teacher_routes_do_not(): void
    {
        foreach (['index', 'create', 'store', 'show', 'edit', 'update'] as $route) {
            $this->assertTrue(Route::has("admin.course-enrollments.{$route}"));
            $this->assertFalse(Route::has("teacher.course-enrollments.{$route}"));
        }
    }

    public function test_customer_admin_can_create_enrollment_with_resolved_version(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id, title: 'TOPIK Beginner');
        $this->createProductItem($customerId, $productId, $versionId);

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-enrollments',
                $this->validEnrollmentData([
                    'student_id' => $student->id,
                    'product_id' => $productId,
                    'access_starts_at' => '2026-07-04 09:00:00',
                    'access_ends_at' => '2026-10-04 23:59:59',
                    'notes' => 'Manual admin assignment.',
                ])
            )
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_enrollments', [
            'customer_id' => $customerId,
            'student_id' => $student->id,
            'product_id' => $productId,
            'version_id' => $versionId,
            'source' => 'admin',
            'status' => 'active',
            'enrolled_by' => $admin->id,
            'notes' => 'Manual admin assignment.',
            'metadata' => null,
        ]);
        $this->assertSame(
            1,
            DB::table('core_course_products')
                ->where('customer_id', $customerId)
                ->where('id', $productId)
                ->value('enrollment_count')
        );
    }

    public function test_enrollment_forms_show_real_notes_and_hide_metadata_json(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id, title: 'TOPIK Beginner');
        $this->createProductItem($customerId, $productId, $versionId);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);

        DB::table('core_course_enrollments')
            ->where('id', $enrollmentId)
            ->update([
                'notes' => 'Manual enrollment approved by admin.',
                'metadata' => '{"notes":"Metadata must stay internal."}',
            ]);

        foreach ([
            $this->actingAs($admin)
                ->get('https://tenant-a.localhost/admin/course-enrollments/create')
                ->assertOk(),
            $this->actingAs($admin)
                ->get("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}/edit")
                ->assertOk()
                ->assertSee('Manual enrollment approved by admin.'),
            $this->actingAs($admin)
                ->get("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}")
                ->assertOk()
                ->assertSeeText('Manual enrollment approved by admin.'),
        ] as $response) {
            $response
                ->assertSeeText(__('lf.LF_course_enrollment_common_notes'))
                ->assertDontSeeText('Metadata JSON')
                ->assertDontSee('Metadata must stay internal.')
                ->assertDontSee('name="metadata"', false);
        }
    }

    public function test_enrollment_update_preserves_internal_metadata(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id, title: 'TOPIK Beginner');
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);

        DB::table('core_course_enrollments')
            ->where('id', $enrollmentId)
            ->update(['metadata' => '{"system":"internal"}']);

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}",
                [
                    'status' => 'suspended',
                    'access_starts_at' => null,
                    'access_ends_at' => null,
                    'notes' => 'Visible enrollment note',
                    'metadata' => '{"system":"user submitted"}',
                ]
            )
            ->assertRedirect("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}");

        $this->assertDatabaseHas('core_course_enrollments', [
            'id' => $enrollmentId,
            'status' => 'suspended',
            'notes' => 'Visible enrollment note',
            'metadata' => '{"system":"internal"}',
        ]);
    }

    public function test_enrollment_detail_uses_grouped_readonly_form_contract(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id, title: 'TOPIK Beginner');
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);

        DB::table('core_course_enrollments')->where('id', $enrollmentId)->update([
            'review_starts_at' => '2026-08-01 09:00:00',
            'review_ends_at' => '2026-08-31 18:00:00',
        ]);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}")
            ->assertOk()
            ->assertSee('class="cohort-detail-toolbar"', false)
            ->assertSee('class="admin-card admin-form-card admin-form-surface"', false)
            ->assertSee('id="enrollment-show-access"', false)
            ->assertSee('id="enrollment-show-information"', false)
            ->assertSee('id="enrollment-show-access-window"', false)
            ->assertSee('id="enrollment-show-review-window"', false)
            ->assertSee('id="enrollment-show-additional"', false)
            ->assertSeeText('2026-08-01 09:00:00')
            ->assertSeeText('2026-08-31 18:00:00')
            ->assertDontSee('<table', false);
    }

    public function test_admin_cannot_submit_version_id_manually(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id);
        $otherVersionId = $this->createVersion($customerId, $admin->id, title: 'Other Version');
        $this->createProductItem($customerId, $productId, $versionId);

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-enrollments/create')
            ->post(
                'https://tenant-a.localhost/admin/course-enrollments',
                $this->validEnrollmentData([
                    'student_id' => $student->id,
                    'product_id' => $productId,
                    'version_id' => $otherVersionId,
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-enrollments/create')
            ->assertSessionHasErrors('version_id');

        $this->assertDatabaseCount('core_course_enrollments', 0);
    }

    public function test_product_without_active_product_item_fails(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-enrollments/create')
            ->post(
                'https://tenant-a.localhost/admin/course-enrollments',
                $this->validEnrollmentData([
                    'student_id' => $student->id,
                    'product_id' => $productId,
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-enrollments/create')
            ->assertSessionHasErrors('product_id');

        $this->assertDatabaseCount('core_course_enrollments', 0);
    }

    public function test_inactive_and_archived_products_do_not_accept_new_enrollments(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');

        foreach (['inactive', 'archived'] as $status) {
            $productId = $this->createProduct($customerId, ucfirst($status).' Product', $status.'-product', status: $status);
            $versionId = $this->createVersion($customerId, $admin->id, title: ucfirst($status).' Version');
            $this->createProductItem($customerId, $productId, $versionId);

            $this->actingAs($admin)->from('https://tenant-a.localhost/admin/course-enrollments/create')
                ->post('https://tenant-a.localhost/admin/course-enrollments', $this->validEnrollmentData([
                    'student_id' => $student->id, 'product_id' => $productId,
                ]))->assertSessionHasErrors('product_id');
        }

        $this->assertDatabaseCount('core_course_enrollments', 0);
    }

    public function test_unpublished_resolved_version_fails(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion(
            $customerId,
            $admin->id,
            status: 'draft_snapshot'
        );
        $this->createProductItem($customerId, $productId, $versionId);

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-enrollments/create')
            ->post(
                'https://tenant-a.localhost/admin/course-enrollments',
                $this->validEnrollmentData([
                    'student_id' => $student->id,
                    'product_id' => $productId,
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-enrollments/create')
            ->assertSessionHasErrors('product_id');

        $this->assertDatabaseCount('core_course_enrollments', 0);
    }

    public function test_cross_tenant_student_product_and_non_student_are_rejected(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $teacher = $this->createUser($customerId, 'teacher');
        $otherStudent = $this->createUser($otherCustomerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $otherProductId = $this->createProduct(
            $otherCustomerId,
            'Tenant B TOPIK',
            'tenant-b-topik'
        );
        $versionId = $this->createVersion($customerId, $admin->id);
        $this->createProductItem($customerId, $productId, $versionId);

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-enrollments/create')
            ->post(
                'https://tenant-a.localhost/admin/course-enrollments',
                $this->validEnrollmentData([
                    'student_id' => $otherStudent->id,
                    'product_id' => $productId,
                ])
            )
            ->assertSessionHasErrors('student_id');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-enrollments/create')
            ->post(
                'https://tenant-a.localhost/admin/course-enrollments',
                $this->validEnrollmentData([
                    'student_id' => $student->id,
                    'product_id' => $otherProductId,
                ])
            )
            ->assertSessionHasErrors('product_id');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-enrollments/create')
            ->post(
                'https://tenant-a.localhost/admin/course-enrollments',
                $this->validEnrollmentData([
                    'student_id' => $teacher->id,
                    'product_id' => $productId,
                ])
            )
            ->assertSessionHasErrors('student_id');

        $this->assertDatabaseCount('core_course_enrollments', 0);
    }

    public function test_existing_enrollment_keeps_old_version_after_product_changes(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $studentA = $this->createUser($customerId, 'student');
        $studentB = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $templateId = $this->createTemplate($customerId, $admin->id, 'TOPIK Template');
        $version7Id = $this->createVersion(
            $customerId,
            $admin->id,
            title: 'TOPIK Template',
            templateId: $templateId,
            versionNumber: 7,
            isCurrent: false
        );
        $version8Id = $this->createVersion(
            $customerId,
            $admin->id,
            title: 'TOPIK Template',
            templateId: $templateId,
            versionNumber: 8
        );
        $itemId = $this->createProductItem($customerId, $productId, $version7Id);

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-enrollments',
                $this->validEnrollmentData([
                    'student_id' => $studentA->id,
                    'product_id' => $productId,
                ])
            )
            ->assertRedirect();

        DB::table('core_course_product_items')
            ->where('customer_id', $customerId)
            ->where('id', $itemId)
            ->update(['status' => 'inactive', 'updated_at' => now()]);
        $this->createProductItem($customerId, $productId, $version8Id);

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-enrollments',
                $this->validEnrollmentData([
                    'student_id' => $studentB->id,
                    'product_id' => $productId,
                ])
            )
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_enrollments', [
            'student_id' => $studentA->id,
            'product_id' => $productId,
            'version_id' => $version7Id,
        ]);
        $this->assertDatabaseHas('core_course_enrollments', [
            'student_id' => $studentB->id,
            'product_id' => $productId,
            'version_id' => $version8Id,
        ]);
    }

    public function test_version_id_cannot_be_updated_after_creation(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id);
        $otherVersionId = $this->createVersion($customerId, $admin->id, title: 'Other Version');
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}/edit")
            ->put(
                "https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}",
                [
                    'status' => 'suspended',
                    'access_starts_at' => null,
                    'access_ends_at' => null,
                    'notes' => null,
                    'version_id' => $otherVersionId,
                ]
            )
            ->assertRedirect("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}/edit")
            ->assertSessionHasErrors('version_id');

        $this->assertDatabaseHas('core_course_enrollments', [
            'id' => $enrollmentId,
            'version_id' => $versionId,
            'status' => 'active',
        ]);
    }

    public function test_teacher_student_and_guest_cannot_access_admin_enrollment_management(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);

        $this->get('https://tenant-a.localhost/admin/course-enrollments')
            ->assertRedirect('https://tenant-a.localhost/login');

        foreach ([$teacher, $student] as $user) {
            $this->actingAs($user)
                ->get('https://tenant-a.localhost/admin/course-enrollments')
                ->assertForbidden();

            $this->actingAs($user)
                ->put(
                    "https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}",
                    ['status' => 'suspended']
                )
                ->assertForbidden();
        }

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher/course-enrollments')
            ->assertNotFound();
    }

    public function test_tenant_isolation_on_list_detail_and_update(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $otherStudent = $this->createUser($otherCustomerId, 'student');
        $productId = $this->createProduct($customerId, 'Tenant A Product', 'tenant-a-product');
        $otherProductId = $this->createProduct(
            $otherCustomerId,
            'Tenant B Product',
            'tenant-b-product'
        );
        $versionId = $this->createVersion($customerId, $admin->id, title: 'Tenant A Version');
        $otherVersionId = $this->createVersion($otherCustomerId, $otherStudent->id, title: 'Tenant B Version');
        $ownEnrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $otherEnrollmentId = $this->createEnrollment(
            $otherCustomerId,
            $otherStudent->id,
            $otherProductId,
            $otherVersionId
        );

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-enrollments')
            ->assertOk()
            ->assertSeeText('Tenant A Product')
            ->assertDontSeeText('Tenant B Product');

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-enrollments/{$otherEnrollmentId}")
            ->assertNotFound();

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-enrollments/{$otherEnrollmentId}",
                ['status' => 'suspended']
            )
            ->assertNotFound();

        $this->assertDatabaseHas('core_course_enrollments', [
            'id' => $ownEnrollmentId,
            'customer_id' => $customerId,
        ]);
        $this->assertDatabaseHas('core_course_enrollments', [
            'id' => $otherEnrollmentId,
            'customer_id' => $otherCustomerId,
            'status' => 'active',
        ]);
    }

    public function test_re_enrollment_for_same_student_and_product_is_allowed(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id);
        $this->createProductItem($customerId, $productId, $versionId);

        foreach ([1, 2] as $cycle) {
            $this->actingAs($admin)
                ->post(
                    'https://tenant-a.localhost/admin/course-enrollments',
                    $this->validEnrollmentData([
                        'student_id' => $student->id,
                        'product_id' => $productId,
                    ])
                )
                ->assertRedirect();
        }

        $this->assertSame(
            2,
            DB::table('core_course_enrollments')
                ->where('customer_id', $customerId)
                ->where('student_id', $student->id)
                ->where('product_id', $productId)
                ->count()
        );
    }

    public function test_create_form_uses_business_order_and_server_owned_metadata(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $response = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-enrollments/create')
            ->assertOk()
            ->assertSee('class="admin-form-standard"', false)
            ->assertSee('role="combobox"', false)
            ->assertSeeText(__('lf.LF_course_enrollment_common_source_admin'))
            ->assertSeeText(__('lf.LF_course_enrollment_common_active'))
            ->assertSeeText(__('lf.LF_course_enrollment_recorded_on_save'))
            ->assertDontSee('name="version_id"', false)
            ->assertDontSee('name="source"', false)
            ->assertDontSee('name="source_id"', false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="enrolled_at"', false);

        $html = $response->getContent();
        $positions = array_map(fn (string $id) => strpos($html, 'id="'.$id.'"'), [
            'student_search', 'product_search', 'enrollment-metadata-title',
            'access_starts_at', 'access_ends_at', 'review_starts_at',
            'review_ends_at', 'notes',
        ]);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertLessThan(
            strpos($html, 'type="submit" class="btn btn-primary"'),
            strpos($html, 'class="btn btn-secondary"')
        );
        $response->assertDontSee('class="admin-form-cancel"', false);
        $response->assertSeeText(__('lf.LF_course_enrollment_create_submitting'));
    }

    public function test_index_uses_the_approved_class_list_table_contract(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-enrollments')
            ->assertOk()
            ->assertSee('class="course-cohort-index-toolbar"', false)
            ->assertSee('course-cohort-index-table-wrap', false)
            ->assertSee('course-enrollment-index-table', false)
            ->assertSee('course-cohort-index-status', false)
            ->assertSee('course-cohort-index-actions', false);
    }

    public function test_search_endpoints_are_tenant_scoped_and_products_include_version_summary(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $otherStudent = $this->createUser($otherCustomerId, 'student');
        $productId = $this->createProduct($customerId, 'Eligible TOPIK', 'eligible-topik');
        $versionId = $this->createVersion($customerId, $admin->id, title: 'Eligible Version');
        $this->createProductItem($customerId, $productId, $versionId);

        $this->actingAs($admin)
            ->getJson('https://tenant-a.localhost/admin/course-enrollments/students/search?q=student')
            ->assertOk()
            ->assertJsonFragment(['id' => $student->id, 'email' => $student->email])
            ->assertJsonMissing(['email' => $otherStudent->email]);

        $this->actingAs($admin)
            ->getJson('https://tenant-a.localhost/admin/course-enrollments/products/search?q=eligible')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $productId,
                'title' => 'Eligible TOPIK',
                'code' => 'ELIGIBLE-TOPIK',
                'lesson_count' => 0,
                'activity_count' => 0,
            ]);
    }

    public function test_admin_create_derives_authority_and_validates_time_windows(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Secure Product', 'secure-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $this->createProductItem($customerId, $productId, $versionId);

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-enrollments/create')
            ->post('https://tenant-a.localhost/admin/course-enrollments', [
                'student_id' => $student->id,
                'product_id' => $productId,
                'source' => 'api',
                'source_id' => 99,
                'status' => 'completed',
                'enrolled_at' => '2000-01-01 00:00:00',
                'version_id' => 999,
            ])
            ->assertSessionHasErrors(['source', 'source_id', 'status', 'enrolled_at', 'version_id']);

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-enrollments', [
                'student_id' => $student->id,
                'product_id' => $productId,
                'access_starts_at' => '2026-08-02 10:00:00',
                'access_ends_at' => '2026-08-01 10:00:00',
                'review_starts_at' => '2026-09-02 10:00:00',
                'review_ends_at' => '2026-09-01 10:00:00',
            ])
            ->assertSessionHasErrors(['access_ends_at', 'review_ends_at']);

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-enrollments', [
                'student_id' => $student->id,
                'product_id' => $productId,
                'notes' => 'Internal only',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_enrollments', [
            'student_id' => $student->id,
            'product_id' => $productId,
            'version_id' => $versionId,
            'source' => 'admin',
            'source_id' => null,
            'status' => 'active',
            'notes' => 'Internal only',
        ]);
        $this->assertDatabaseCount('core_course_cohort_students', 0);
    }

    public function test_multiple_active_product_items_are_not_eligible_for_enrollment(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Ambiguous Product', 'ambiguous-product');
        $versionA = $this->createVersion($customerId, $admin->id, title: 'Version A');
        $versionB = $this->createVersion($customerId, $admin->id, title: 'Version B');
        $this->createProductItem($customerId, $productId, $versionA);
        $this->createProductItem($customerId, $productId, $versionB, sortOrder: 1);

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-enrollments', [
                'student_id' => $student->id,
                'product_id' => $productId,
            ])
            ->assertSessionHasErrors('product_id');

        $this->actingAs($admin)
            ->getJson('https://tenant-a.localhost/admin/course-enrollments/products/search?q=ambiguous')
            ->assertOk()
            ->assertJsonMissing(['id' => $productId]);
        $this->assertDatabaseCount('core_course_enrollments', 0);
    }

    public function test_course_enrollment_module_has_no_eloquent_models(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/CoreCourseEnrollment.php'));
        $this->assertFileDoesNotExist(app_path('Models/CourseEnrollment.php'));
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

    private function createVersion(
        int $customerId,
        int $userId,
        string $title = 'TOPIK Version',
        string $status = 'published',
        ?int $templateId = null,
        int $versionNumber = 1,
        ?bool $isCurrent = null
    ): int {
        $now = now();
        $templateId ??= $this->createTemplate($customerId, $userId, $title);

        return DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'version_number' => $versionNumber,
            'version_code' => 'VERSION-'.$templateId.'-'.$versionNumber,
            'is_current' => $isCurrent ?? $status === 'published',
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
            'status' => $status,
            'published_at' => $status === 'published' ? $now : null,
            'published_by' => $userId,
            'source_template_updated_at' => $now,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createProductItem(
        int $customerId,
        int $productId,
        int $versionId,
        string $status = 'active',
        int $sortOrder = 0
    ): int {
        $now = now();

        return DB::table('core_course_product_items')->insertGetId([
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'title_override' => null,
            'short_description_override' => null,
            'sort_order' => $sortOrder,
            'is_required' => true,
            'status' => $status,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createEnrollment(
        int $customerId,
        int $studentId,
        int $productId,
        int $versionId,
        string $status = 'active'
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
            'status' => $status,
            'completed_at' => null,
            'cancelled_at' => null,
            'expired_at' => null,
            'notes' => null,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function validEnrollmentData(array $overrides = []): array
    {
        return array_merge([
            'student_id' => 1,
            'product_id' => 1,
            'access_starts_at' => null,
            'access_ends_at' => null,
            'review_starts_at' => null,
            'review_ends_at' => null,
            'notes' => null,
        ], $overrides);
    }
}
