<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CourseEnrollmentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
        foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'activate', 'suspend', 'reactivate', 'cancel'] as $route) {
            $this->assertTrue(Route::has("admin.course-enrollments.{$route}"));
            $this->assertFalse(Route::has("teacher.course-enrollments.{$route}"));
        }
        $this->assertFalse(Route::has('admin.course-enrollments.bulk-update'));
        $this->assertFalse(Route::has('teacher.course-enrollments.bulk-update'));
        $this->assertTrue(Route::has('admin.course-enrollments.bulk-lifecycle'));
        $this->assertFalse(Route::has('teacher.course-enrollments.bulk-lifecycle'));
        foreach (['bulk-store', 'bulk-preflight', 'bulk-invalidate', 'bulk-result'] as $route) {
            $this->assertTrue(Route::has("admin.course-enrollments.{$route}"));
            $this->assertFalse(Route::has("teacher.course-enrollments.{$route}"));
        }
    }

    public function test_bulk_submission_schema_is_durable_and_does_not_change_enrollment_identity(): void
    {
        $this->assertTrue(Schema::hasColumns('core_course_enrollment_submissions', [
            'id', 'customer_id', 'admin_id', 'token_hash', 'payload_hash', 'student_ids', 'product_ids',
            'reenrollment_confirmations', 'configuration', 'pair_count', 'status', 'expires_at',
            'committed_at', 'invalidated_at', 'result', 'created_at', 'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('core_course_enrollments', 'submission_id'));
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
        $enrollment = DB::table('core_course_enrollments')->where('product_id', $productId)->first();
        $this->assertSame($enrollment->enrolled_at, $enrollment->access_starts_at);
        $this->assertSame(
            Carbon::parse($enrollment->enrolled_at)->addDays(30)->format('Y-m-d H:i:s'),
            $enrollment->access_ends_at
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
                    'access_starts_at' => null,
                    'access_ends_at' => null,
                    'notes' => 'Visible enrollment note',
                    'metadata' => '{"system":"user submitted"}',
                ]
            )
            ->assertRedirect("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}");

        $this->assertDatabaseHas('core_course_enrollments', [
            'id' => $enrollmentId,
            'status' => 'active',
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
            ->assertSee('class="cohort-detail-toolbar course-enrollment-detail-toolbar"', false)
            ->assertSee('course-enrollment-detail', false)
            ->assertSee('course-enrollment-detail-metadata-grid', false)
            ->assertSee('course-enrollment-detail-item', false)
            ->assertSee('id="enrollment-show-access"', false)
            ->assertSee('id="enrollment-show-information"', false)
            ->assertSee('course-enrollment-detail-information-panel', false)
            ->assertSee('course-enrollment-detail-window-grid', false)
            ->assertSeeInOrder([
                __('lf.LF_course_enrollment_common_enrolled_at'),
                __('lf.LF_course_enrollment_common_source'),
                __('lf.LF_course_enrollment_common_status'),
            ])
            ->assertDontSee('id="enrollment-show-access-window"', false)
            ->assertDontSee('id="enrollment-show-review-window"', false)
            ->assertDontSee('id="enrollment-show-additional"', false)
            ->assertSeeText('01/08/2026 09:00')
            ->assertSeeText('31/08/2026 18:00')
            ->assertDontSee('<table', false);
    }

    public function test_edit_shows_computed_windows_readonly_and_preserves_them_when_notes_change(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id, title: 'TOPIK Beginner');
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);

        $response = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}/edit")
            ->assertOk()
            ->assertSee('class="admin-form-standard"', false)
            ->assertSee('class="admin-form-footer"', false)
            ->assertSee('id="review_starts_at"', false)
            ->assertSee('id="review_ends_at"', false)
            ->assertDontSee('name="review_starts_at"', false)
            ->assertDontSee('name="review_ends_at"', false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('<table', false);

        $html = $response->getContent();
        $positions = array_map(fn (string $id) => strpos($html, 'id="'.$id.'"'), [
            'enrollment-edit-access',
            'enrollment-edit-information',
            'enrollment-edit-access-window',
            'enrollment-edit-review-window',
            'enrollment-edit-additional',
        ]);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}/edit")
            ->put("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}", [
                'notes' => 'Review window approved.',
            ])
            ->assertRedirect("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}");

        $this->assertDatabaseHas('core_course_enrollments', [
            'id' => $enrollmentId,
            'review_starts_at' => null,
            'review_ends_at' => null,
            'notes' => 'Review window approved.',
        ]);

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}/edit")
            ->put("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}", [
                'review_starts_at' => '2026-09-01 09:00:00',
            ])
            ->assertRedirect("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}/edit")
            ->assertSessionHasErrors('review_starts_at');
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
            ->assertSeeText(__('lf.LF_bulk_enrollment_select_students_products'))
            ->assertSeeText(__('lf.LF_bulk_enrollment_setup_confirm'))
            ->assertSee('aria-label="'.__('lf.LF_bulk_enrollment_information_section').'"', false)
            ->assertDontSee('configuration[access_starts_at]', false)
            ->assertDontSee('configuration[access_ends_at]', false)
            ->assertDontSee('configuration[review_starts_at]', false)
            ->assertDontSee('configuration[review_ends_at]', false)
            ->assertSee('configuration[notes]', false)
            ->assertDontSeeText(__('lf.LF_course_enrollment_times_automatic'))
            ->assertDontSee('name="version_id"', false)
            ->assertDontSee('name="source"', false)
            ->assertDontSee('name="source_id"', false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="enrolled_at"', false);

        $response->assertSee('aria-live="polite"', false)
            ->assertSeeText(__('lf.LF_bulk_enrollment_select_visible'))
            ->assertSeeText(__('lf.LF_bulk_enrollment_start_title'))
            ->assertSeeText(__('lf.LF_bulk_enrollment_start_content'))
            ->assertSeeText(__('lf.LF_bulk_enrollment_select_student_first'))
            ->assertSeeText(__('lf.LF_bulk_enrollment_students_panel'))
            ->assertSeeText(__('lf.LF_bulk_enrollment_products_panel'))
            ->assertSee('name="submission_token"', false)
            ->assertSee('class="admin-table-wrap bulk-enrollment-review-table"', false)
            ->assertDontSee('class="bulk-enrollment-summary"', false);
        $html = $response->getContent();
        $this->assertSame(2, substr_count($html, '<li :class='));
        $this->assertStringContainsString('class="bulk-enrollment-stepper"', $html);
        $this->assertStringContainsString(':aria-current="step === 1 ? \'step\' : null"', $html);
        $this->assertStringNotContainsString('role="radiogroup"', $html);
        $this->assertStringNotContainsString('name="mode"', $html);
        $this->assertStringContainsString('class="bulk-enrollment-dual-selectors"', $html);
        $this->assertStringContainsString('student_ids[]', $html);
        $this->assertStringContainsString('product_ids[]', $html);
        $this->assertStringContainsString('get pairCount()', $html);
        $this->assertStringContainsString('runPreflight(false, document.activeElement)', $html);
        $this->assertStringContainsString('runPreflight(true, document.activeElement)', $html);
        $this->assertStringContainsString('scheduleProductEligibility()', $html);
        $this->assertStringContainsString('requestVersion !== this.productRequestVersion', $html);
        $this->assertStringNotContainsString('x-show="productLoading && selectedStudents.length > 0"', $html);
        $this->assertStringNotContainsString('bulk-enrollment-eligibility-progress', $html);
        $this->assertStringContainsString('this.productAbortController?.abort()', $html);
        $this->assertStringNotContainsString('this.productResults = this.productResults.map(item => ({ ...item, eligibility: null }))', $html);
        $this->assertStringContainsString('get hasInvalidSelectedProducts()', $html);
        $this->assertStringContainsString('x-show="errorMessage" x-text="errorMessage" role="alert" x-cloak', $html);
        $this->assertStringContainsString('aria-labelledby="bulk-selection-error-title" x-cloak', $html);
        $this->assertStringContainsString('x-show="productEligibilityError" class="admin-alert admin-alert-danger" role="alert" x-cloak', $html);
        $this->assertStringContainsString('class="admin-alert admin-alert-info bulk-enrollment-start-guide" role="note"', $html);
        $this->assertStringNotContainsString('bulk-enrollment-time-preview', $html);
        $this->assertStringNotContainsString('previewProductTime', $html);
        $this->assertStringContainsString('class="bulk-enrollment-date-setting"', $html);
        $this->assertStringContainsString('class="lf-form-control bulk-enrollment-date-input"', $html);
        $this->assertStringContainsString('class="lf-required-indicator" aria-hidden="true">*</span>', $html);
        $this->assertStringNotContainsString('id="bulk-enrolled-at-error"', $html);
        $this->assertStringContainsString("'has-value': configuration.enrolled_at", $html);
        $this->assertStringNotContainsString('enrollmentDateCustomized', $html);
        $this->assertStringNotContainsString('resetEnrollmentDateToNow', $html);
        $this->assertStringContainsString('async validateEnrollmentDate(trigger = null)', $html);
        $this->assertStringContainsString('x-show="enrollmentDatePromptVisible"', $html);
        $this->assertStringContainsString('x-ref="enrollmentDatePromptClose"', $html);
        $this->assertStringContainsString('closeEnrollmentDatePrompt()', $html);
        $this->assertStringContainsString("document.getElementById('bulk-enrolled-at')?.focus()", $html);
        $this->assertStringContainsString(__('lf.LF_course_enrollment_enrolled_at_popup_title'), $html);
        $this->assertStringContainsString('class="bulk-enrollment-entry-row"', $html);
        $this->assertStringContainsString("year: 'numeric'", $html);
        $this->assertStringContainsString('students.length === this.selectedStudents.length ? reason', $html);
        $this->assertStringContainsString("'is-selected-invalid': productEligibilityReady && hasProduct(item.id) && item.eligibility === 'ineligible'", $html);
        $this->assertStringContainsString('class="bulk-enrollment-invalid-reason"', $html);
        $this->assertStringContainsString("'is-selected': hasProduct(item.id)", $html);
        $this->assertStringContainsString('class="bulk-enrollment-eligibility-badge is-checking"', $html);
        $this->assertStringContainsString('confirmationPerPage: 10', $html);
        $this->assertStringContainsString('class="bulk-enrollment-reenrollment-confirmation"', $html);
        $this->assertStringContainsString(__('lf.LF_bulk_enrollment_confirm_reenroll'), $html);
        $this->assertStringContainsString('in paginatedPairs', $html);
        $this->assertStringContainsString('bulk-enrollment-review-table__number', $html);
        $this->assertStringContainsString('class="bulk-enrollment-product-window"', $html);
        $this->assertStringContainsString('pair.time_windows?.access_starts_at', $html);
        $this->assertStringContainsString('pair.time_windows?.review_ends_at', $html);
        $this->assertStringContainsString(__('lf.LF_bulk_enrollment_access_time'), $html);
        $this->assertStringContainsString(__('lf.LF_bulk_enrollment_review_time'), $html);
        $this->assertStringContainsString('class="bulk-enrollment-confirmation__facts"', $html);
        $this->assertStringNotContainsString('x-show="selectedStudents.length > 0 && selectedProducts.length === 0"', $html);
        $this->assertStringNotContainsString('class="bulk-enrollment-empty-state"', $html);
        $this->assertStringContainsString('x-show="productSelectionPromptVisible" x-cloak class="admin-modal-backdrop"', $html);
        $this->assertStringContainsString('class="admin-modal bulk-enrollment-guidance-modal" role="dialog" aria-modal="true"', $html);
        $this->assertStringNotContainsString('x-on:click.capture="promptForStudentSelection"', $html);
        $this->assertStringContainsString('x-on:click="if (selectedStudents.length === 0) { $event.preventDefault(); promptForStudentSelection($event.currentTarget) }"', $html);
        $this->assertStringContainsString(':disabled="selectedStudents.length > 0 && (!productEligibilityReady || item.eligibility !== \'eligible\')"', $html);
        $this->assertStringContainsString(':aria-disabled="selectedStudents.length === 0 || !productEligibilityReady || item.eligibility !== \'eligible\'"', $html);
        $this->assertStringContainsString('productSelectionPromptVisible: false', $html);
        $this->assertStringContainsString('async promptForStudentSelection(trigger)', $html);
        $this->assertStringContainsString('this.$refs.productPromptClose.focus()', $html);
        $this->assertSame(1, substr_count($html, 'x-ref="productPromptClose"'));
        $this->assertStringContainsString(__('lf.LF_bulk_enrollment_acknowledge'), $html);
        $this->assertStringContainsString('this.productPromptTrigger?.focus()', $html);
        $this->assertStringContainsString('if (this.selectedStudents.length > 0) this.productSelectionPromptVisible = false', $html);
        $this->assertStringContainsString('id="bulk-products-title" tabindex="-1"', $html);
        $this->assertStringContainsString('productOnboardingShown: false', $html);
        $this->assertStringContainsString('if (shouldGuideProducts) this.guideToProductsOnce()', $html);
        $this->assertStringContainsString('if (this.productOnboardingShown) return', $html);
        $this->assertStringContainsString("panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' })", $html);
        $this->assertStringContainsString('smallViewport || !panelIsVisible', $html);
        $this->assertStringNotContainsString('selectedStudents.length === 0" class="admin-alert admin-alert-danger', $html);
        $this->assertStringContainsString('removeInvalidProducts()', $html);
        $this->assertStringContainsString('item.eligibility !== \'eligible\'', $html);
        $this->assertStringContainsString('eligibleVisibleProducts', $html);
        $this->assertStringContainsString('x-text="item.code"', $html);
        $this->assertStringContainsString('x-text="item.version?.code"', $html);
        $this->assertStringContainsString('class="bulk-enrollment-product-meta__label"', $html);
        $this->assertStringContainsString('class="bulk-enrollment-pagination"', $html);
        $this->assertStringContainsString('aria-label="'.__('lf.LF_bulk_enrollment_students_pagination').'"', $html);
        $this->assertStringContainsString('aria-label="'.__('lf.LF_bulk_enrollment_products_pagination').'"', $html);
        $this->assertStringNotContainsString('item.product_code', $html);
        $this->assertStringNotContainsString('item.version?.version_code', $html);
        $this->assertStringNotContainsString('LF_course_enrollment_common_students', $html);
        $this->assertStringNotContainsString('LF_course_enrollment_common_products', $html);
        $this->assertStringNotContainsString('selectedProducts = productResults', $html);
        $this->assertSame('Start enrollment', trans('lf.LF_bulk_enrollment_start_title', [], 'en'));
        $this->assertSame('Select students to check eligible products.', trans('lf.LF_bulk_enrollment_select_student_first', [], 'en'));
        $this->assertSame('Bắt đầu ghi danh', trans('lf.LF_bulk_enrollment_start_title', [], 'vi'));
        $this->assertSame('Chọn học viên để kiểm tra sản phẩm phù hợp.', trans('lf.LF_bulk_enrollment_select_student_first', [], 'vi'));
        $transferCss = file_get_contents(resource_path('css/admin/admin-pages.css'));
        $this->assertStringContainsString('.bulk-enrollment-dual-selectors', $transferCss);
        $this->assertStringContainsString('.bulk-enrollment-selector.is-onboarding-highlight', $transferCss);
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr));', $transferCss);
        $this->assertStringContainsString('.bulk-enrollment-wizard-section', $transferCss);
        $this->assertStringContainsString('width: min(720px, 100%);', $transferCss);
        $this->assertStringContainsString('justify-items: center;', $transferCss);
        $this->assertStringContainsString('margin: 0 auto 18px;', $transferCss);
        $this->assertLessThan(strpos($html, 'class="admin-card admin-form-card admin-form-surface"'), strpos($html, 'class="bulk-enrollment-stepper"'));
        $this->assertLessThan(
            strpos($html, 'class="admin-table-wrap bulk-enrollment-review-table"'),
            strpos($html, 'id="bulk-setup-title"')
        );
        $this->assertStringNotContainsString('id="bulk-enrollment-information"', $html);
        $this->assertLessThan(
            strpos($html, 'id="bulk-notes-label"'),
            strpos($html, 'class="admin-table-wrap bulk-enrollment-review-table"')
        );
        $response->assertSeeText(__('lf.LF_bulk_enrollment_submit'));
    }

    public function test_bulk_preflight_is_tenant_scoped_for_both_selection_sets(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $studentA = $this->createUser($customerId, 'student');
        $studentB = $this->createUser($customerId, 'student');
        $otherStudent = $this->createUser($otherCustomerId, 'student');
        $productA = $this->createProduct($customerId, 'Bootstrap Product A', 'bootstrap-product-a');
        $productB = $this->createProduct($customerId, 'Bootstrap Product B', 'bootstrap-product-b');
        $otherProduct = $this->createProduct($otherCustomerId, 'Other Product', 'other-product');
        foreach ([[$customerId, $productA], [$customerId, $productB], [$otherCustomerId, $otherProduct]] as [$tenantId, $productId]) {
            $owner = $tenantId === $customerId ? $admin : $this->createUser($tenantId, 'customer_admin');
            $this->createProductItem($tenantId, $productId, $this->createVersion($tenantId, $owner->id));
        }

        $this->actingAs($admin)->postJson('https://tenant-a.localhost/admin/course-enrollments/bulk/preflight', [
            'student_ids' => [$studentA->id, $studentB->id, $otherStudent->id],
            'product_ids' => [$productA, $productB, $otherProduct],
            'configuration' => ['enrolled_at' => now()->format('Y-m-d H:i:s')],
        ])->assertOk()->assertJson(['can_continue' => false, 'submission_token' => null]);

        $this->assertDatabaseCount('core_course_enrollments', 0);
        $this->assertDatabaseCount('core_course_enrollment_submissions', 0);
    }

    public function test_bulk_enrollment_requires_an_enrollment_date_in_preflight_and_commit(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $payload = [
            'student_ids' => [1],
            'product_ids' => [1],
            'configuration' => ['enrolled_at' => null],
        ];

        $this->actingAs($admin)
            ->postJson('https://tenant-a.localhost/admin/course-enrollments/bulk/preflight', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('configuration.enrolled_at');

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-enrollments/bulk', $payload + ['submission_token' => str_repeat('a', 64)])
            ->assertSessionHasErrors('configuration.enrolled_at');

        $this->assertDatabaseCount('core_course_enrollments', 0);
    }

    public function test_admin_lifecycle_transition_matrix_is_explicit_and_preserves_binding(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Lifecycle Product', 'lifecycle-product');
        $versionId = $this->createVersion($customerId, $admin->id);

        foreach ([
            ['pending', 'activate', 'active'],
            ['pending', 'cancel', 'cancelled'],
            ['active', 'suspend', 'suspended'],
            ['active', 'cancel', 'cancelled'],
            ['suspended', 'reactivate', 'active'],
            ['suspended', 'cancel', 'cancelled'],
        ] as [$source, $action, $target]) {
            $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
            DB::table('core_course_enrollments')->where('id', $enrollmentId)->update(['status' => $source]);

            $this->actingAs($admin)
                ->post("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}/{$action}")
                ->assertRedirect("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}");

            $this->assertDatabaseHas('core_course_enrollments', [
                'id' => $enrollmentId,
                'status' => $target,
                'version_id' => $versionId,
            ]);
            if ($target === 'cancelled') {
                $this->assertNotNull(DB::table('core_course_enrollments')->where('id', $enrollmentId)->value('cancelled_at'));
            }
        }
    }

    public function test_lifecycle_fails_closed_and_keeps_cohort_membership(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Lifecycle Product', 'lifecycle-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        DB::table('core_course_cohort_students')->insert([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'enrollment_id' => $enrollmentId,
            'product_id' => $productId, 'student_id' => $student->id, 'assigned_by' => $admin->id,
            'joined_at' => now(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}/suspend")->assertRedirect();
        $this->assertDatabaseHas('core_course_cohort_students', ['cohort_id' => $cohortId, 'enrollment_id' => $enrollmentId, 'status' => 'active']);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}/cancel")->assertRedirect();
        $this->assertDatabaseHas('core_course_cohort_students', ['cohort_id' => $cohortId, 'enrollment_id' => $enrollmentId, 'status' => 'active']);

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}/reactivate")
            ->assertSessionHasErrors('lifecycle');
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}/edit")->assertStatus(422);
        $this->actingAs($teacher)->post("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}/cancel")->assertForbidden();
    }

    public function test_edit_rejects_forged_status_and_show_uses_localized_action_matrix(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Lifecycle Product', 'lifecycle-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);

        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}", ['status' => 'cancelled'])
            ->assertSessionHasErrors('status');
        $this->assertDatabaseHas('core_course_enrollments', ['id' => $enrollmentId, 'status' => 'active']);

        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}")
            ->assertOk()->assertSeeText(__('lf.LF_course_enrollment_lifecycle_suspend'))
            ->assertSeeText(__('lf.LF_course_enrollment_lifecycle_cancel'))
            ->assertDontSeeText(__('lf.LF_course_enrollment_lifecycle_reactivate'));

        $this->withSession(['locale' => 'en'])->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}")
            ->assertOk()->assertSeeText('Suspend')->assertSeeText('Cancel enrollment');

        DB::table('core_course_enrollments')->where('id', $enrollmentId)->update(['status' => 'completed', 'completed_at' => now()]);
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-enrollments/{$enrollmentId}")
            ->assertOk()->assertDontSee('course-enrollments/'.$enrollmentId.'/suspend', false)
            ->assertDontSee('course-enrollments/'.$enrollmentId.'/cancel', false);
    }

    public function test_index_uses_the_approved_class_list_table_contract(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-enrollments')
            ->assertOk()
            ->assertSee('course-cohort-index-toolbar', false)
            ->assertSee('course-enrollment-index-toolbar', false)
            ->assertSee('course-enrollment-index-count', false)
            ->assertSee('course-enrollment-filter-grid', false)
            ->assertSee('course-cohort-index-table-wrap', false)
            ->assertSee('course-enrollment-index-table', false)
            ->assertSee('course-cohort-index-status', false)
            ->assertSee('course-cohort-index-actions', false)
            ->assertSeeText(__('lf.LF_course_enrollment_common_empty'))
            ->assertSeeText(__('lf.LF_course_enrollment_empty_help'))
            ->assertSeeText(__('lf.LF_course_enrollment_common_create'));

        $pagesCss = file_get_contents(resource_path('css/admin/admin-pages.css'));
        $this->assertStringContainsString('.lf-admin-page .badge.course-enrollment-status-badge {', $pagesCss);
        $this->assertStringContainsString('width: 96px;', $pagesCss);
        $this->assertStringContainsString('height: 30px;', $pagesCss);

        $this->get('https://tenant-a.localhost/admin/course-enrollments?keyword=missing')
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_enrollment_filter_empty'))
            ->assertSeeText(__('lf.LF_course_enrollment_filter_empty_help'))
            ->assertSeeText(__('lf.LF_course_enrollment_common_clear_filters'))
            ->assertDontSeeText(__('lf.LF_course_enrollment_common_empty'));
    }

    public function test_index_paginates_ten_and_orders_newest_enrollments_first(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Priority Product', 'priority-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $versionCode = DB::table('core_course_template_versions')->where('id', $versionId)->value('version_code');

        $records = [
            ['status' => 'cancelled', 'created_at' => '2026-07-30 10:00:00'],
            ['status' => 'completed', 'created_at' => '2026-07-28 10:00:00'],
            ['status' => 'active', 'created_at' => '2026-07-24 10:00:00'],
            ['status' => 'pending', 'created_at' => '2026-07-21 10:00:00'],
            ['status' => 'suspended', 'created_at' => '2026-07-26 10:00:00'],
            ['status' => 'expired', 'created_at' => '2026-07-27 10:00:00'],
            ['status' => 'active', 'created_at' => '2026-07-23 10:00:00'],
            ['status' => 'pending', 'created_at' => '2026-07-20 10:00:00'],
            ['status' => 'cancelled', 'created_at' => '2026-07-29 10:00:00'],
            ['status' => 'suspended', 'created_at' => '2026-07-25 10:00:00'],
            ['status' => 'active', 'created_at' => '2026-07-22 10:00:00'],
            ['status' => 'pending', 'created_at' => '2026-07-19 10:00:00'],
        ];

        $idsByStatus = [];
        foreach ($records as $record) {
            $id = $this->createEnrollment($customerId, $student->id, $productId, $versionId, $record['status']);
            DB::table('core_course_enrollments')->where('id', $id)->update([
                'created_at' => $record['created_at'],
            ]);
            $idsByStatus[$record['status']][] = $id;
        }

        $firstResponse = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-enrollments?keyword=Priority&per_page=100')
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_enrollment_information'))
            ->assertSeeText(__('lf.LF_course_enrollment_information_source').':')
            ->assertSeeText(__('lf.LF_course_enrollment_information_date').':')
            ->assertSeeText(__('lf.LF_course_enrollment_index_release').':')
            ->assertSeeText(__('lf.LF_course_product_item_common_version_number', ['number' => 1]))
            ->assertSeeText($versionCode)
            ->assertDontSeeText('TOPIK Version')
            ->assertSee('course-enrollment-status-badge--suspended', false);
        $firstPage = $firstResponse->viewData('enrollments');

        $this->assertSame(10, $firstPage->perPage());
        $this->assertSame(12, $firstPage->total());
        $this->assertStringContainsString('keyword=Priority', $firstPage->url(2));
        $this->assertStringContainsString('per_page=100', $firstPage->url(2));
        $this->assertSame([
            $idsByStatus['cancelled'][0], $idsByStatus['cancelled'][1],
            $idsByStatus['completed'][0], $idsByStatus['expired'][0],
            $idsByStatus['suspended'][0], $idsByStatus['suspended'][1],
            $idsByStatus['active'][0], $idsByStatus['active'][1], $idsByStatus['active'][2],
            $idsByStatus['pending'][0],
        ], collect($firstPage->items())->pluck('id')->all());

        $secondResponse = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-enrollments?keyword=Priority&per_page=100&page=2')
            ->assertOk();
        $secondPage = $secondResponse->viewData('enrollments');

        $this->assertSame(10, $secondPage->perPage());
        $this->assertSame([
            $idsByStatus['pending'][1], $idsByStatus['pending'][2],
        ], collect($secondPage->items())->pluck('id')->all());
    }

    public function test_enrollment_index_filters_and_bulk_selection_markup_use_canonical_contracts(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Filter Product', 'filter-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $activeId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $terminalId = $this->createEnrollment($customerId, $student->id, $productId, $versionId, 'completed');
        DB::table('core_course_enrollments')->where('id', $activeId)->update([
            'source' => 'self_registration', 'enrolled_by' => $admin->id,
            'enrolled_at' => '2026-07-10 10:00:00',
        ]);

        $response = $this->actingAs($admin)->get('https://tenant-a.localhost/admin/course-enrollments?source=self_registration&status=active&product_id='.$productId.'&student_id='.$student->id.'&enrolled_by='.$admin->id.'&enrolled_from=2026-07-10&enrolled_to=2026-07-10')
            ->assertOk()->assertSee('Filter Product')->assertSee('value="self_registration" selected', false)
            ->assertSee('name="product_id"', false)->assertSee('name="student_id"', false)
            ->assertSee('name="enrolled_from"', false)->assertSee('name="enrolled_by"', false)
            ->assertSee('name="enrollment_ids[]"', false)
            ->assertDontSee('bulk-update', false);
        $html = $response->getContent();
        $this->assertStringContainsString('value="'.$activeId.'" x-model="selectedIds"', $html);
        $this->assertStringNotContainsString('value="'.$terminalId.'" x-model="selectedIds"', $html);
        $this->assertStringContainsString('get canSuspend()', $html);
        $this->assertStringContainsString("status === 'active'", $html);
        $this->assertStringContainsString('get canReactivate()', $html);
        $this->assertStringContainsString("status === 'suspended'", $html);
        $this->assertStringContainsString('state.reactivationEligible', $html);
        $this->assertStringContainsString('get canCancel()', $html);
        $this->assertStringContainsString("['pending', 'active', 'suspended'].includes(status)", $html);
        $this->assertStringContainsString('course-enrollment-bulk-action--suspend', $html);
        $this->assertStringContainsString('course-enrollment-bulk-action--reactivate', $html);
        $this->assertStringContainsString('course-enrollment-bulk-action--cancel', $html);
        $this->assertStringContainsString('role="dialog" aria-modal="true" aria-labelledby="bulk-lifecycle-title" aria-describedby="bulk-lifecycle-body"', $html);
        $this->assertStringContainsString('name="advanced_filters" :value="advancedFiltersOpen ? \'1\' : \'0\'"', $html);
        $this->assertStringContainsString('aria-controls="course-enrollment-advanced-filters"', $html);
        $this->assertStringContainsString('x-show="advancedFiltersOpen" class="course-enrollment-advanced-filter-grid"', $html);
        $this->assertStringContainsString(':aria-busy="submitting"', $html);
        $this->assertStringContainsString('if (this.submitting || this.lifecycleModalOpen', $html);
        $this->assertStringContainsString('x-ref="lifecycleForm"', $html);
        $this->assertStringContainsString('event.preventDefault(); if (this.submitting) return; const form = event.currentTarget; this.submitting = true;', $html);
        $this->assertStringContainsString(':disabled="submitting || !canReactivate"', $html);
        $this->assertStringContainsString("lifecycleAction === 'cancel' ? 'btn btn-danger' : 'btn btn-primary'", $html);
        $lifecycleForm = substr($html, strpos($html, 'action="'.route('admin.course-enrollments.bulk-lifecycle').'"'));
        $lifecycleForm = substr($lifecycleForm, 0, strpos($lifecycleForm, '</form>'));
        $this->assertStringNotContainsString('name="status"', $lifecycleForm);
        $indexView = file_get_contents(resource_path('views/course-enrollments/index.blade.php'));
        $this->assertStringContainsString("'reactivate' => ['title' => __('lf.LF_course_enrollment_bulk_reactivate_title')", $indexView);
        $this->assertStringContainsString("'confirm' => __('lf.LF_course_enrollment_bulk_reactivate_confirm')", $indexView);

        DB::table('core_course_enrollments')->where('id', $activeId)->update([
            'status' => 'suspended',
            'access_ends_at' => now()->subMinute(),
        ]);
        $expiredAccessResponse = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-enrollments?status=suspended')
            ->assertOk();
        $this->assertFalse((bool) $expiredAccessResponse->viewData('enrollments')->getCollection()->first()->reactivation_eligible);

        DB::table('core_course_enrollments')->where('id', $activeId)->update([
            'access_ends_at' => now()->subDay(),
            'review_starts_at' => now()->subDay(),
            'review_ends_at' => now()->addDay(),
        ]);
        $eligibleResponse = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-enrollments?status=suspended')
            ->assertOk();
        $this->assertTrue((bool) $eligibleResponse->viewData('enrollments')->getCollection()->first()->reactivation_eligible);

        $this->withSession(['locale' => 'en'])->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-enrollments')
            ->assertOk()
            ->assertSeeText('Suspend')
            ->assertSeeText('Reactivate')
            ->assertSeeText('Cancel enrollment');
        $this->assertSame('Suspend enrollments', trans('lf.LF_course_enrollment_bulk_suspend_confirm', [], 'en'));
        $this->assertSame('Cancel enrollments', trans('lf.LF_course_enrollment_bulk_cancel_confirm', [], 'en'));
        $this->assertSame('Tạm dừng ghi danh', trans('lf.LF_course_enrollment_bulk_suspend_confirm', [], 'vi'));
        $this->assertSame('Hủy ghi danh', trans('lf.LF_course_enrollment_bulk_cancel_confirm', [], 'vi'));

        $invalidFilterResponse = $this->actingAs($admin)->get('https://tenant-a.localhost/admin/course-enrollments?source=not-real&product_id=999999&enrolled_from=2026-99-99');

        $invalidFilterResponse->assertOk()
            ->assertDontSee('value="not-real" selected', false)
            ->assertDontSee('value="999999" selected', false)
            ->assertSee('name="enrolled_from"', false)
            ->assertSee('value=""', false);

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-enrollments?advanced_filters=1')
            ->assertOk()
            ->assertSee('x-data="{ advancedFiltersOpen: true }"', false);
    }

    public function test_bulk_lifecycle_applies_only_canonical_atomic_transitions_and_preserves_binding(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Bulk Lifecycle', 'bulk-lifecycle');
        $versionId = $this->createVersion($customerId, $admin->id);

        $activeIds = [
            $this->createEnrollment($customerId, $student->id, $productId, $versionId),
            $this->createEnrollment($customerId, $student->id, $productId, $versionId),
        ];
        $this->from('https://tenant-a.localhost/admin/course-enrollments?status=active')
            ->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk-lifecycle', [
                'enrollment_ids' => $activeIds,
                'action' => 'suspend',
            ])->assertRedirect('https://tenant-a.localhost/admin/course-enrollments?status=active')
            ->assertSessionHas('success', __('lf.LF_course_enrollment_bulk_lifecycle_suspend_success', ['count' => 2]));
        foreach ($activeIds as $id) {
            $this->assertDatabaseHas('core_course_enrollments', [
                'id' => $id, 'status' => 'suspended', 'product_id' => $productId,
                'version_id' => $versionId, 'source' => 'admin', 'cancelled_at' => null,
            ]);
        }

        $preservedCancelledAt = '2026-07-01 00:00:00';
        DB::table('core_course_enrollments')->where('id', $activeIds[0])->update(['cancelled_at' => $preservedCancelledAt]);
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk-lifecycle', [
            'enrollment_ids' => array_reverse($activeIds),
            'action' => 'reactivate',
        ])->assertRedirect();
        $this->assertDatabaseHas('core_course_enrollments', ['id' => $activeIds[0], 'status' => 'active', 'cancelled_at' => $preservedCancelledAt]);
        $this->assertDatabaseHas('core_course_enrollments', ['id' => $activeIds[1], 'status' => 'active', 'cancelled_at' => null]);

        $cancelIds = [
            $this->createEnrollment($customerId, $student->id, $productId, $versionId, 'pending'),
            $this->createEnrollment($customerId, $student->id, $productId, $versionId, 'active'),
            $this->createEnrollment($customerId, $student->id, $productId, $versionId, 'suspended'),
        ];
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        DB::table('core_course_cohort_students')->insert([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'enrollment_id' => $cancelIds[1],
            'product_id' => $productId, 'student_id' => $student->id, 'assigned_by' => $admin->id,
            'joined_at' => now(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $progressId = DB::table('core_course_progress')->insertGetId([
            'customer_id' => $customerId, 'enrollment_id' => $cancelIds[1], 'student_id' => $student->id,
            'product_id' => $productId, 'version_id' => $versionId, 'progress_percentage' => 42.50,
            'completed_lessons' => 2, 'total_lessons' => 5, 'completed_activities' => 3,
            'total_activities' => 8, 'required_activities_completed' => 2, 'required_activities_total' => 6,
            'assessment_completed' => 0, 'assessment_total' => 1, 'total_learning_seconds' => 900,
            'last_version_activity_id' => null, 'last_version_lesson_id' => null, 'last_accessed_at' => now(),
            'started_at' => now(), 'completed_at' => null, 'status' => 'in_progress', 'recalculated_at' => null,
            'metadata' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk-lifecycle', [
            'enrollment_ids' => $cancelIds,
            'action' => 'cancel',
        ])->assertRedirect()->assertSessionHas('success', __('lf.LF_course_enrollment_bulk_lifecycle_cancel_success', ['count' => 3]));
        foreach ($cancelIds as $id) {
            $this->assertSame('cancelled', DB::table('core_course_enrollments')->where('id', $id)->value('status'));
            $this->assertNotNull(DB::table('core_course_enrollments')->where('id', $id)->value('cancelled_at'));
        }
        $this->assertDatabaseHas('core_course_cohort_students', ['cohort_id' => $cohortId, 'enrollment_id' => $cancelIds[1], 'status' => 'active']);
        $this->assertDatabaseHas('core_course_progress', ['id' => $progressId, 'progress_percentage' => 42.50, 'status' => 'in_progress']);
    }

    public function test_bulk_lifecycle_rejects_stale_cross_tenant_terminal_and_forged_requests_atomically(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $otherStudent = $this->createUser($otherCustomerId, 'student');
        $productId = $this->createProduct($customerId, 'Atomic Lifecycle', 'atomic-lifecycle');
        $otherProductId = $this->createProduct($otherCustomerId, 'Other Lifecycle', 'other-lifecycle');
        $versionId = $this->createVersion($customerId, $admin->id);
        $otherVersionId = $this->createVersion($otherCustomerId, $otherAdmin->id);
        $activeId = $this->createEnrollment($customerId, $student->id, $productId, $versionId, 'active');
        $suspendedId = $this->createEnrollment($customerId, $student->id, $productId, $versionId, 'suspended');
        $eligibleSuspendedId = $this->createEnrollment($customerId, $student->id, $productId, $versionId, 'suspended');
        $terminalId = $this->createEnrollment($customerId, $student->id, $productId, $versionId, 'completed');
        $otherId = $this->createEnrollment($otherCustomerId, $otherStudent->id, $otherProductId, $otherVersionId, 'active');

        foreach ([[$activeId, $suspendedId], [$activeId, $terminalId], [$activeId, $otherId], [$activeId, 999999]] as $ids) {
            $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk-lifecycle', [
                'enrollment_ids' => $ids, 'action' => 'suspend',
            ])->assertSessionHasErrors('enrollment_ids');
            $this->assertDatabaseHas('core_course_enrollments', ['id' => $activeId, 'status' => 'active']);
        }

        DB::table('core_course_enrollments')->where('id', $suspendedId)->update([
            'access_ends_at' => now()->subDay(),
            'review_starts_at' => now()->subDay(),
            'review_ends_at' => now()->subMinute(),
        ]);
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk-lifecycle', [
            'enrollment_ids' => [$suspendedId, $eligibleSuspendedId], 'action' => 'reactivate',
        ])->assertSessionHasErrors('enrollment_ids');
        $this->assertDatabaseHas('core_course_enrollments', ['id' => $eligibleSuspendedId, 'status' => 'suspended']);

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk-lifecycle', [
            'enrollment_ids' => [$activeId], 'action' => 'activate', 'status' => 'cancelled',
            'cancelled_at' => now(), 'source_id' => 99,
        ])->assertSessionHasErrors(['action', 'status', 'cancelled_at', 'source_id']);
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk-lifecycle', [
            'enrollment_ids' => [], 'action' => 'cancel',
        ])->assertSessionHasErrors('enrollment_ids');
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk-lifecycle', [
            'enrollment_ids' => range(1, 101), 'action' => 'cancel',
        ])->assertSessionHasErrors('enrollment_ids');
        $this->assertDatabaseHas('core_course_enrollments', ['id' => $activeId, 'status' => 'active']);
        $this->assertDatabaseHas('core_course_enrollments', ['id' => $otherId, 'status' => 'active']);
    }

    public function test_bulk_lifecycle_normalizes_duplicates_and_competing_replay_fails_without_rewriting_cancelled_at(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Replay Lifecycle', 'replay-lifecycle');
        $versionId = $this->createVersion($customerId, $admin->id);
        $id = $this->createEnrollment($customerId, $student->id, $productId, $versionId, 'active');

        $payload = ['enrollment_ids' => [$id, $id], 'action' => 'cancel'];
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk-lifecycle', $payload)
            ->assertSessionHas('success', __('lf.LF_course_enrollment_bulk_lifecycle_cancel_success', ['count' => 1]));
        $cancelledAt = DB::table('core_course_enrollments')->where('id', $id)->value('cancelled_at');
        $this->travel(1)->minute();
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk-lifecycle', $payload)
            ->assertSessionHasErrors('enrollment_ids');
        $this->assertEquals($cancelledAt, DB::table('core_course_enrollments')->where('id', $id)->value('cancelled_at'));

        $suspendedId = $this->createEnrollment($customerId, $student->id, $productId, $versionId, 'suspended');
        DB::table('core_course_enrollments')->where('id', $suspendedId)->update([
            'access_ends_at' => now()->subDay(),
            'review_starts_at' => now()->subDay(),
            'review_ends_at' => now()->addDay(),
        ]);
        $reactivatePayload = ['enrollment_ids' => [$suspendedId], 'action' => 'reactivate'];
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk-lifecycle', $reactivatePayload)
            ->assertSessionHas('success', __('lf.LF_course_enrollment_bulk_lifecycle_reactivate_success', ['count' => 1]));
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk-lifecycle', $reactivatePayload)
            ->assertSessionHasErrors('enrollment_ids');
        $this->assertDatabaseHas('core_course_enrollments', ['id' => $suspendedId, 'status' => 'active']);
    }

    public function test_search_endpoints_are_tenant_scoped_and_products_include_version_summary(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $otherStudent = $this->createUser($otherCustomerId, 'student');
        $productId = $this->createProduct($customerId, 'Eligible TOPIK', 'eligible-topik');
        DB::table('core_course_products')->where('id', $productId)->update([
            'offering_type' => 'self_paced_course',
            'review_duration_days' => 7,
        ]);
        $versionId = $this->createVersion($customerId, $admin->id, title: 'Eligible Version');
        $this->createProductItem($customerId, $productId, $versionId);

        $studentSearch = $this->actingAs($admin)
            ->getJson('https://tenant-a.localhost/admin/course-enrollments/students/search?q=student')
            ->assertOk()
            ->assertJsonFragment(['id' => $student->id, 'email' => $student->email])
            ->assertJsonMissing(['email' => $otherStudent->email]);
        $this->assertSame(10, $studentSearch->json('pagination.per_page'));

        $productSearch = $this->actingAs($admin)
            ->getJson('https://tenant-a.localhost/admin/course-enrollments/products/search?q=eligible')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $productId,
                'title' => 'Eligible TOPIK',
                'code' => 'ELIGIBLE-TOPIK',
                'supports_review' => true,
                'lesson_count' => 0,
                'activity_count' => 0,
            ]);
        $this->assertSame(10, $productSearch->json('pagination.per_page'));
    }

    public function test_product_search_classifies_every_selected_student_and_stably_sorts_the_current_page(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $studentA = $this->createUser($customerId, 'student');
        $studentB = $this->createUser($customerId, 'student');
        $otherStudent = $this->createUser($otherCustomerId, 'student');
        $products = collect([
            'Alpha Product' => 'alpha-product',
            'Beta Product' => 'beta-product',
            'Gamma Product' => 'gamma-product',
        ])->mapWithKeys(function (string $slug, string $title) use ($customerId, $admin): array {
            $productId = $this->createProduct($customerId, $title, $slug);
            $versionId = $this->createVersion($customerId, $admin->id, title: $title);
            $this->createProductItem($customerId, $productId, $versionId);

            return [$title => ['product' => $productId, 'version' => $versionId]];
        });
        $this->createEnrollment($customerId, $studentA->id, $products['Beta Product']['product'], $products['Beta Product']['version']);
        $this->createEnrollment($customerId, $studentB->id, $products['Gamma Product']['product'], $products['Gamma Product']['version']);

        $oneStudent = $this->actingAs($admin)->getJson(
            'https://tenant-a.localhost/admin/course-enrollments/products/search?student_ids[]='.$studentA->id
        )->assertOk();
        $this->assertSame(['Alpha Product', 'Gamma Product'], collect($oneStudent->json('data'))->pluck('title')->all());
        $this->assertSame(['eligible', 'eligible'], collect($oneStudent->json('data'))->pluck('eligibility')->all());
        $this->assertSame(['Beta Product'], collect($oneStudent->json('ineligible.data'))->pluck('title')->all());
        $this->assertSame(1, $oneStudent->json('ineligible.data.0.invalid_pair_count'));
        $this->assertSame(['eligible' => 2, 'ineligible' => 1], $oneStudent->json('counts'));

        $selectedOffPage = $this->actingAs($admin)->getJson(
            'https://tenant-a.localhost/admin/course-enrollments/products/search?q=Alpha&student_ids[]='.$studentA->id.'&selected_product_ids[]='.$products['Beta Product']['product']
        )->assertOk();
        $this->assertSame('ineligible', $selectedOffPage->json('selected_eligibility.'.$products['Beta Product']['product'].'.eligibility'));

        $manyStudents = $this->actingAs($admin)->getJson(
            'https://tenant-a.localhost/admin/course-enrollments/products/search?student_ids[]='.$studentA->id.'&student_ids[]='.$studentB->id
        )->assertOk();
        $this->assertSame(['Alpha Product'], collect($manyStudents->json('data'))->pluck('title')->all());
        $this->assertSame(['Beta Product', 'Gamma Product'], collect($manyStudents->json('ineligible.data'))->pluck('title')->all());
        $this->assertSame(2, $manyStudents->json('data.0.valid_pair_count'));

        $this->actingAs($admin)->getJson(
            'https://tenant-a.localhost/admin/course-enrollments/products/search?student_ids[]='.$otherStudent->id
        )->assertUnprocessable();
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
            ->assertSessionHasErrors(['source', 'source_id', 'status', 'version_id']);

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-enrollments', [
                'student_id' => $student->id,
                'product_id' => $productId,
                'access_starts_at' => '2026-08-02 10:00:00',
                'access_ends_at' => '2026-08-01 10:00:00',
                'review_starts_at' => '2026-09-02 10:00:00',
                'review_ends_at' => '2026-09-01 10:00:00',
            ])
            ->assertSessionHasErrors([
                'access_starts_at', 'access_ends_at', 'review_starts_at', 'review_ends_at',
            ]);

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

    public function test_registration_boundaries_are_inclusive_and_projected_windows_are_frozen(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $students = collect(range(1, 4))->map(fn () => $this->createUser($customerId, 'student'));
        $productId = $this->createProduct($customerId, 'Timed Product', 'timed-product');
        $this->createProductItem($customerId, $productId, $this->createVersion($customerId, $admin->id));
        DB::table('core_course_products')->where('id', $productId)->update([
            'registration_starts_at' => '2026-08-10 09:00:00',
            'registration_ends_at' => '2026-08-12 09:00:00',
            'access_duration_days' => 30,
            'review_duration_days' => 5,
        ]);

        try {
            Carbon::setTestNow('2026-08-10 08:59:59');
            $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments', [
                'student_id' => $students[0]->id, 'product_id' => $productId,
            ])->assertSessionHasErrors('product_id');

            Carbon::setTestNow('2026-08-10 09:00:00');
            $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments', [
                'student_id' => $students[1]->id, 'product_id' => $productId,
            ])->assertRedirect();

            Carbon::setTestNow('2026-08-12 09:00:00');
            $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments', [
                'student_id' => $students[2]->id, 'product_id' => $productId,
            ])->assertRedirect();

            Carbon::setTestNow('2026-08-12 09:00:01');
            $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments', [
                'student_id' => $students[3]->id, 'product_id' => $productId,
            ])->assertSessionHasErrors('product_id');
        } finally {
            Carbon::setTestNow();
        }

        $enrollment = DB::table('core_course_enrollments')->where('student_id', $students[1]->id)->first();
        $this->assertSame('2026-08-10 09:00:00', $enrollment->access_starts_at);
        $this->assertSame('2026-09-09 09:00:00', $enrollment->access_ends_at);
        $this->assertSame($enrollment->access_ends_at, $enrollment->review_starts_at);
        $this->assertSame('2026-09-14 09:00:00', $enrollment->review_ends_at);

        DB::table('core_course_products')->where('id', $productId)->update([
            'access_duration_days' => 60, 'review_duration_days' => 10,
        ]);
        $frozen = DB::table('core_course_enrollments')->where('id', $enrollment->id)->first();
        $this->assertSame($enrollment->access_ends_at, $frozen->access_ends_at);
        $this->assertSame($enrollment->review_ends_at, $frozen->review_ends_at);
    }

    public function test_registration_configuration_fails_closed_and_runtime_windows_are_half_open(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Invalid Window', 'invalid-window');
        $this->createProductItem($customerId, $productId, $this->createVersion($customerId, $admin->id));
        DB::table('core_course_products')->where('id', $productId)->update([
            'registration_starts_at' => now()->subDay(), 'registration_ends_at' => null,
        ]);

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments', [
            'student_id' => $student->id, 'product_id' => $productId,
        ])->assertSessionHasErrors('product_id');

        $policy = app(CourseEnrollmentLifecycleService::class);
        $enrollment = (object) [
            'access_starts_at' => '2026-08-01 00:00:00',
            'access_ends_at' => '2026-08-02 00:00:00',
            'review_starts_at' => '2026-08-02 00:00:00',
            'review_ends_at' => '2026-08-03 00:00:00',
        ];
        $this->assertTrue($policy->allowsLearningAccessAt($enrollment, Carbon::parse('2026-08-01 00:00:00')));
        $this->assertTrue($policy->allowsLearningAccessAt($enrollment, Carbon::parse('2026-08-02 00:00:00')));
        $this->assertFalse($policy->allowsLearningAccessAt($enrollment, Carbon::parse('2026-08-03 00:00:00')));
    }

    public function test_selected_enrollment_date_snapshots_durations_and_edit_reprojects_from_snapshots(): void
    {
        $this->assertTrue(Schema::hasColumns('core_course_enrollments', [
            'access_duration_days', 'review_duration_days',
        ]));
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Snapshot Product', 'snapshot-product');
        $this->createProductItem($customerId, $productId, $this->createVersion($customerId, $admin->id));
        DB::table('core_course_products')->where('id', $productId)->update([
            'registration_starts_at' => '2026-07-01 00:00:00',
            'registration_ends_at' => '2026-12-31 23:59:59',
            'access_duration_days' => 30,
            'review_duration_days' => 5,
        ]);

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments', [
            'student_id' => $student->id,
            'product_id' => $productId,
            'enrolled_at' => '2026-08-05 10:00:00',
        ])->assertRedirect();

        $enrollment = DB::table('core_course_enrollments')->where('student_id', $student->id)->first();
        $this->assertSame(30, $enrollment->access_duration_days);
        $this->assertSame(5, $enrollment->review_duration_days);
        $this->assertSame('2026-08-05 10:00:00', $enrollment->access_starts_at);

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-enrollments/'.$enrollment->id.'/edit')
            ->assertOk()
            ->assertSee('course-enrollment-time-impact', false)
            ->assertSee('class="lf-form-control course-enrollment-edit-date-input"', false)
            ->assertSee("'has-value': enrolledAt", false)
            ->assertDontSee('enrollment-edit-access-window', false)
            ->assertDontSee('enrollment-edit-review-window', false)
            ->assertSeeText(__('lf.LF_course_enrollment_time_impact_title'))
            ->assertSeeText(__('lf.LF_course_enrollment_time_impact_durations', [
                'access' => 30,
                'review' => 5,
            ]))
            ->assertSeeText(__('lf.LF_course_enrollment_time_impact_unchanged'));

        DB::table('core_course_products')->where('id', $productId)->update([
            'access_duration_days' => 60,
            'review_duration_days' => 10,
        ]);
        $this->actingAs($admin)->put('https://tenant-a.localhost/admin/course-enrollments/'.$enrollment->id, [
            'enrolled_at' => '2026-08-10 10:00:00',
            'notes' => 'Changed date',
        ])->assertRedirect();

        $updated = DB::table('core_course_enrollments')->where('id', $enrollment->id)->first();
        $this->assertSame(30, $updated->access_duration_days);
        $this->assertSame(5, $updated->review_duration_days);
        $this->assertSame('2026-08-10 10:00:00', $updated->access_starts_at);
        $this->assertSame('2026-09-09 10:00:00', $updated->access_ends_at);
        $this->assertSame('2026-09-09 10:00:00', $updated->review_starts_at);
        $this->assertSame('2026-09-14 10:00:00', $updated->review_ends_at);

        $this->actingAs($admin)->put('https://tenant-a.localhost/admin/course-enrollments/'.$enrollment->id, [
            'enrolled_at' => '2027-01-01 00:00:00',
        ])->assertSessionHasErrors('enrolled_at');
        $this->assertSame('2026-08-10 10:00:00', DB::table('core_course_enrollments')->where('id', $enrollment->id)->value('enrolled_at'));
    }

    public function test_legacy_enrollment_cannot_change_enrollment_date_and_product_search_uses_selected_date(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Dated Product', 'dated-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $this->createProductItem($customerId, $productId, $versionId);
        DB::table('core_course_products')->where('id', $productId)->update([
            'registration_starts_at' => '2026-08-10 09:00:00',
            'registration_ends_at' => '2026-08-12 09:00:00',
        ]);
        $legacyId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);

        $this->actingAs($admin)->put('https://tenant-a.localhost/admin/course-enrollments/'.$legacyId, [
            'enrolled_at' => '2026-08-11 09:00:00',
        ])->assertSessionHasErrors('enrolled_at');

        $inside = $this->actingAs($admin)->getJson(
            'https://tenant-a.localhost/admin/course-enrollments/products/search?student_ids[]='.$student->id.'&enrolled_at=2026-08-11%2009:00:00'
        )->assertOk();
        $outside = $this->actingAs($admin)->getJson(
            'https://tenant-a.localhost/admin/course-enrollments/products/search?student_ids[]='.$student->id.'&selected_product_ids[]='.$productId.'&enrolled_at=2026-08-13%2009:00:00'
        )->assertOk();
        $this->assertFalse($inside->json('ineligible.data.0.outside_registration_window'));
        $this->assertTrue($outside->json('ineligible.data.0.outside_registration_window'));
        $this->assertStringContainsString('13/08/2026 09:00', $outside->json('ineligible.data.0.invalid_pairs.0.reason'));
        $this->assertSame('ineligible', $outside->json('selected_eligibility.'.$productId.'.eligibility'));
    }

    public function test_course_enrollment_module_has_no_eloquent_models(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/CoreCourseEnrollment.php'));
        $this->assertFileDoesNotExist(app_path('Models/CourseEnrollment.php'));
    }

    public function test_bulk_enrollment_creates_the_full_cartesian_product_and_freezes_versions(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $students = collect([1, 2])->map(fn () => $this->createUser($customerId, 'student'));
        $products = collect(['A', 'B'])->map(function (string $suffix) use ($customerId, $admin): array {
            $productId = $this->createProduct($customerId, 'Product '.$suffix, 'product-'.strtolower($suffix));
            $versionId = $this->createVersion($customerId, $admin->id, title: 'Version '.$suffix);
            $this->createProductItem($customerId, $productId, $versionId);

            return [$productId, $versionId];
        });
        $studentIds = $students->pluck('id')->all();
        $productIds = $products->pluck(0)->all();
        $configuration = $this->bulkConfiguration();
        $token = $this->prepareBulkSubmission($admin, $studentIds, $productIds, [], $configuration);

        $payload = $this->bulkStoreData($studentIds, $productIds, $token, [], $configuration);
        $storeResponse = $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk', $payload);

        $this->assertDatabaseCount('core_course_enrollments', 4);
        $submissionId = (int) DB::table('core_course_enrollment_submissions')->value('id');
        $resultUrl = 'https://tenant-a.localhost/admin/course-enrollments/bulk/result?submission='.$submissionId;
        $storeResponse->assertRedirect($resultUrl);
        $firstEnrollmentId = (int) DB::table('core_course_enrollments')->min('id');
        $firstVersionCode = DB::table('core_course_template_versions')->where('id', $products->first()[1])->value('version_code');
        $this->actingAs($admin)
            ->get($resultUrl)
            ->assertOk()
            ->assertSeeText(__('lf.LF_bulk_enrollment_result_success_title'))
            ->assertSeeText(__('lf.LF_bulk_enrollment_result_success_content', ['count' => 4]))
            ->assertSeeText(__('lf.LF_bulk_enrollment_result_details'))
            ->assertSeeText($admin->name)
            ->assertSee('href="'.route('admin.course-enrollments.show', $firstEnrollmentId).'"', false)
            ->assertDontSeeText(__('lf.LF_bulk_enrollment_summary_skipped_existing'))
            ->assertDontSeeText(__('lf.LF_bulk_enrollment_summary_re_enrollment_required'))
            ->assertDontSeeText(__('lf.LF_bulk_enrollment_summary_failed'));
        $this->assertFileDoesNotExist(resource_path('views/course-enrollments/bulk-result.blade.php'));
        $this->actingAs($admin)->get($resultUrl)->assertOk();
        $otherAdmin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($otherAdmin)->get($resultUrl)->assertNotFound();
        $storedResult = json_decode(DB::table('core_course_enrollment_submissions')->value('result'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($admin->name, $storedResult['context']['completed_by_name']);
        $this->assertEquals($configuration, $storedResult['context']['configuration']);
        $this->assertSame($firstVersionCode, $storedResult['items'][0]['version_code']);
        $this->assertSame(30, $storedResult['items'][0]['time_windows']['access_duration_days']);
        $this->assertNotNull($storedResult['items'][0]['time_windows']['access_starts_at']);
        $this->assertNotNull($storedResult['items'][0]['time_windows']['access_ends_at']);
        foreach ($students as $student) {
            foreach ($products as [$productId, $versionId]) {
                $this->assertDatabaseHas('core_course_enrollments', [
                    'customer_id' => $customerId, 'student_id' => $student->id,
                    'product_id' => $productId, 'version_id' => $versionId,
                    'source' => 'admin', 'status' => 'active',
                ]);
            }
        }
    }

    public function test_bulk_result_paginates_ten_rows_and_places_sequence_before_enrollment_id(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $items = collect(range(1, 11))->map(fn (int $number): array => [
            'student_id' => $number,
            'student_name' => 'Result Student '.$number,
            'product_id' => 1,
            'product_title' => 'Result Product',
            'version_code' => 'VER-001',
            'enrollment_id' => 100 + $number,
            'status' => 'created',
        ])->all();
        $result = [
            'context' => [
                'submission_id' => 1,
                'completed_at' => now()->toIso8601String(),
                'completed_by_name' => $admin->name,
                'configuration' => ['notes' => null],
            ],
            'summary' => ['total' => 11, 'created' => 11, 'reenrolled' => 0],
            'items' => $items,
        ];
        $submissionId = DB::table('core_course_enrollment_submissions')->insertGetId([
            'customer_id' => $customerId,
            'admin_id' => $admin->id,
            'token_hash' => hash('sha256', 'result-token'),
            'payload_hash' => hash('sha256', 'result-payload'),
            'student_ids' => '[]',
            'product_ids' => '[]',
            'reenrollment_confirmations' => '[]',
            'configuration' => '{}',
            'pair_count' => 11,
            'status' => 'completed',
            'expires_at' => now()->addMinutes(30),
            'committed_at' => now(),
            'invalidated_at' => null,
            'result' => json_encode($result, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $url = 'https://tenant-a.localhost/admin/course-enrollments/bulk/result?submission='.$submissionId;

        $pageOne = $this->actingAs($admin)->get($url)->assertOk();
        $pageOne->assertSeeInOrder([
            __('lf.table_no'),
            __('lf.LF_course_enrollment_common_student'),
            __('lf.LF_course_enrollment_common_product'),
            __('lf.LF_bulk_enrollment_enrollment_id'),
        ])->assertSee('Result Student 10')
            ->assertDontSee('Result Student 11');

        $this->actingAs($admin)->get($url.'&page=2')->assertOk()
            ->assertSee('<td class="bulk-enrollment-review-table__number">11</td>', false)
            ->assertSee('Result Student 11')
            ->assertDontSee('Result Student 10');
    }

    public function test_bulk_submission_is_atomic_when_eligibility_changes_after_preflight(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $students = [$this->createUser($customerId, 'student')->id, $this->createUser($customerId, 'student')->id];
        $productId = $this->createProduct($customerId, 'Atomic Product', 'atomic-product');
        $this->createProductItem($customerId, $productId, $this->createVersion($customerId, $admin->id));
        $configuration = $this->bulkConfiguration();
        $token = $this->prepareBulkSubmission($admin, $students, [$productId], [], $configuration);

        DB::table('users')->where('id', $students[1])->update(['status' => 'inactive']);

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk',
            $this->bulkStoreData($students, [$productId], $token, [], $configuration))
            ->assertRedirect('https://tenant-a.localhost/admin/course-enrollments/create')
            ->assertSessionHasErrors('submission');
        $this->assertDatabaseCount('core_course_enrollments', 0);
        $this->assertDatabaseHas('core_course_enrollment_submissions', ['token_hash' => hash('sha256', $token), 'status' => 'prepared']);
    }

    public function test_bulk_preflight_uses_the_shared_registration_window_policy(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Future Product', 'future-product');
        $this->createProductItem($customerId, $productId, $this->createVersion($customerId, $admin->id));
        DB::table('core_course_products')->where('id', $productId)->update([
            'registration_starts_at' => '2026-08-02 09:00:00',
            'registration_ends_at' => '2026-08-03 09:00:00',
        ]);

        try {
            Carbon::setTestNow('2026-08-01 09:00:00');
            $this->actingAs($admin)->postJson('https://tenant-a.localhost/admin/course-enrollments/bulk/preflight', [
                'student_ids' => [$student->id],
                'product_ids' => [$productId],
                'reenrollment_confirmations' => [],
                'configuration' => $this->bulkConfiguration(),
                'finalize' => true,
            ])->assertOk()->assertJson([
                'valid' => false,
                'submission_token' => null,
            ]);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertDatabaseCount('core_course_enrollments', 0);
        $this->assertDatabaseCount('core_course_enrollment_submissions', 0);
    }

    public function test_reenrollment_requires_exact_confirmation_and_completed_token_replays_result(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Lifecycle Product', 'lifecycle-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $this->createProductItem($customerId, $productId, $versionId);
        $oldId = $this->createEnrollment($customerId, $student->id, $productId, $versionId, 'completed');
        $configuration = $this->bulkConfiguration();

        $this->actingAs($admin)->postJson('https://tenant-a.localhost/admin/course-enrollments/bulk/preflight', [
            'student_ids' => [$student->id], 'product_ids' => [$productId],
            'reenrollment_confirmations' => [], 'configuration' => $configuration, 'finalize' => true,
        ])->assertOk()->assertJson(['valid' => false, 'submission_token' => null]);

        $confirmations = [['student_id' => $student->id, 'product_id' => $productId, 'previous_enrollment_id' => $oldId]];
        $token = $this->prepareBulkSubmission($admin, [$student->id], [$productId], $confirmations, $configuration);
        $payload = $this->bulkStoreData([$student->id], [$productId], $token, $confirmations, $configuration);
        $firstCommit = $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk', $payload);
        $submissionId = (int) DB::table('core_course_enrollment_submissions')->where('token_hash', hash('sha256', $token))->value('id');
        $resultUrl = 'https://tenant-a.localhost/admin/course-enrollments/bulk/result?submission='.$submissionId;
        $firstCommit->assertRedirect($resultUrl);
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk', $payload)
            ->assertRedirect($resultUrl);

        $this->assertSame('completed', DB::table('core_course_enrollments')->where('id', $oldId)->value('status'));
        $this->assertDatabaseCount('core_course_enrollments', 2);
        $this->assertDatabaseHas('core_course_enrollment_submissions', ['token_hash' => hash('sha256', $token), 'status' => 'completed']);
        $this->assertDatabaseMissing('core_course_enrollment_submissions', ['token_hash' => $token]);
    }

    public function test_bulk_configuration_pair_limit_payload_binding_and_automatic_time_policy_are_enforced(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $reviewProduct = $this->createProduct($customerId, 'Review Product', 'review-product');
        $plainProduct = $this->createProduct($customerId, 'Plain Product', 'plain-product');
        DB::table('core_course_products')->where('id', $reviewProduct)->update(['offering_type' => 'self_paced_course', 'review_duration_days' => 14]);
        DB::table('core_course_products')->where('id', $plainProduct)->update(['offering_type' => 'instructor_led_course', 'review_duration_days' => null]);
        foreach ([$reviewProduct, $plainProduct] as $productId) {
            $this->createProductItem($customerId, $productId, $this->createVersion($customerId, $admin->id));
        }
        $configuration = $this->bulkConfiguration([
            'enrolled_at' => '2026-08-01 09:00:00',
            'notes' => ' Shared note ',
        ]);
        $preview = $this->actingAs($admin)->postJson('https://tenant-a.localhost/admin/course-enrollments/bulk/preflight', [
            'student_ids' => [$student->id],
            'product_ids' => [$reviewProduct, $plainProduct],
            'reenrollment_confirmations' => [],
            'configuration' => $configuration,
        ])->assertOk();
        $previewPairs = collect($preview->json('pairs'))->keyBy('product_id');
        $this->assertSame(30, $previewPairs[$reviewProduct]['time_windows']['access_duration_days']);
        $this->assertSame(14, $previewPairs[$reviewProduct]['time_windows']['review_duration_days']);
        $this->assertNotNull($previewPairs[$reviewProduct]['time_windows']['access_starts_at']);
        $this->assertNotNull($previewPairs[$reviewProduct]['time_windows']['review_ends_at']);
        $this->assertNull($previewPairs[$plainProduct]['time_windows']['review_starts_at']);
        $this->assertNull($previewPairs[$plainProduct]['time_windows']['review_ends_at']);

        $token = $this->prepareBulkSubmission($admin, [$student->id], [$reviewProduct, $plainProduct], [], $configuration);
        $payload = $this->bulkStoreData([$student->id], [$reviewProduct, $plainProduct], $token, [], $configuration);

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk', array_replace_recursive($payload, ['configuration' => ['notes' => 'tampered']]))
            ->assertSessionHasErrors('submission_token');
        $this->assertDatabaseCount('core_course_enrollments', 0);

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-enrollments/bulk', $payload)->assertRedirect();
        $reviewEnrollment = DB::table('core_course_enrollments')->where('product_id', $reviewProduct)->first();
        $this->assertSame('2026-08-01 09:00:00', $reviewEnrollment->enrolled_at);
        $this->assertSame(30, $reviewEnrollment->access_duration_days);
        $this->assertSame(14, $reviewEnrollment->review_duration_days);
        $this->assertSame($reviewEnrollment->access_ends_at, $reviewEnrollment->review_starts_at);
        $this->assertEquals(
            14,
            now()->parse($reviewEnrollment->review_starts_at)->diffInDays(now()->parse($reviewEnrollment->review_ends_at))
        );
        $this->assertDatabaseHas('core_course_enrollments', ['product_id' => $reviewProduct, 'notes' => 'Shared note']);
        $this->assertDatabaseHas('core_course_enrollments', ['product_id' => $plainProduct, 'notes' => 'Shared note', 'review_starts_at' => null, 'review_ends_at' => null]);

        $this->actingAs($admin)->postJson('https://tenant-a.localhost/admin/course-enrollments/bulk/preflight', [
            'student_ids' => range(1, 11), 'product_ids' => range(1, 10), 'configuration' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('pair_count');
    }

    private function prepareBulkSubmission(object $admin, array $studentIds, array $productIds, array $confirmations, array $configuration): string
    {
        return (string) $this->actingAs($admin)->postJson('https://tenant-a.localhost/admin/course-enrollments/bulk/preflight', [
            'student_ids' => $studentIds, 'product_ids' => $productIds,
            'reenrollment_confirmations' => $confirmations, 'configuration' => $configuration, 'finalize' => true,
        ])->assertOk()->assertJson(['valid' => true])->json('submission_token');
    }

    private function bulkStoreData(array $studentIds, array $productIds, string $token, array $confirmations, array $configuration): array
    {
        return ['student_ids' => $studentIds, 'product_ids' => $productIds,
            'reenrollment_confirmations' => $confirmations, 'configuration' => $configuration,
            'submission_token' => $token];
    }

    private function bulkConfiguration(array $overrides = []): array
    {
        return array_merge(['enrolled_at' => now()->format('Y-m-d H:i:s'), 'notes' => null], $overrides);
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
            'access_duration_days' => 30,
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

    private function createCohort(int $customerId, int $productId, int $versionId): int
    {
        return DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'teacher_id' => null,
            'name' => 'Lifecycle Cohort',
            'code' => 'COH-'.uniqid(),
            'status' => 'active',
            'capacity' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
