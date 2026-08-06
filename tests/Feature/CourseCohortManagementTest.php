<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
            'activate',
            'complete',
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
        $productId = $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $versionId = $this->createVersion($customerId, $admin->id);

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-cohorts',
                $this->validCohortData([
                    'product_id' => $productId,
                    'name' => 'TOPIK Beginner Morning Class',
                    'description' => 'Morning operational class.',
                    'status' => 'active',
                    'capacity' => 30,
                    'start_date' => '2026-07-01',
                    'end_date' => '2026-09-30',
                    'notes' => 'Bring printed placement tests.',
                ])
            )
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-cohorts',
            $this->validCohortData(['product_id' => $productId, 'name' => 'Redirect check class'])
        );

        $response
            ->assertRedirect('https://tenant-a.localhost/admin/course-cohorts/2')
            ->assertSessionHas('success', __('lf.LF_course_cohort_common_created'));

        $this->assertDatabaseHas('core_course_cohorts', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'teacher_id' => null,
            'name' => 'TOPIK Beginner Morning Class',
            'code' => 'COH-20260704-001',
            'status' => 'draft',
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
            'teacher_id' => null,
            'name' => 'TOPIK Beginner Weekend Class',
            'code' => 'COH-EXISTING',
            'status' => 'active',
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
        $this->createProduct($customerId, 'TOPIK Beginner', 'topik-beginner');
        $this->createVersion($customerId, $admin->id);
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
        $cohortId = $this->createCohort($customerId, status: 'completed');

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

    public function test_cohort_list_shows_newest_records_first(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        Carbon::setTestNow('2026-07-04 09:00:00');
        $this->createCohort($customerId, name: 'A Older Class');

        Carbon::setTestNow('2026-07-04 10:00:00');
        $this->createCohort($customerId, name: 'Z Newest Class');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-cohorts')
            ->assertOk()
            ->assertSeeInOrder(['Z Newest Class', 'A Older Class'])
            ->assertSee('course-cohort-index-table', false)
            ->assertSee('course-cohort-index-toolbar', false)
            ->assertSee('course-cohort-status-badge', false)
            ->assertSeeText(__('lf.LF_course_cohort_common_version'));
    }

    public function test_cohort_index_uses_compact_filters_responsive_rows_and_business_empty_state(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $empty = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-cohorts')
            ->assertOk()
            ->assertSee('course-cohort-filter-grid', false)
            ->assertSee('course-cohort-index-count', false)
            ->assertSeeText('Chưa có lớp học nào.')
            ->assertSeeText('Tạo lớp học đầu tiên')
            ->assertDontSeeText('Chưa có Cohort nào.');

        $this->assertSame(
            1,
            substr_count($empty->getContent(), 'course-cohort-index-toolbar'),
            'The create action should live in one compact top toolbar.'
        );

        $this->createCohort($customerId, 'Responsive Class');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-cohorts')
            ->assertOk()
            ->assertSee('data-label="Tên lớp học"', false)
            ->assertSee('data-label="Sản phẩm"', false)
            ->assertSee('data-label="Trạng thái"', false)
            ->assertSee('data-label="Thao tác"', false)
            ->assertSeeText('Sản phẩm')
            ->assertSeeText('Phiên bản nội dung');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-cohorts?keyword=missing')
            ->assertOk()
            ->assertSeeText('Không tìm thấy lớp học phù hợp.')
            ->assertSeeText('Xóa bộ lọc');
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
        $this->createProduct($customerId, 'Tenant A Product', 'tenant-a-product');
        $this->createVersion($customerId, $admin->id);
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
        $productId = $this->createProduct($customerId, 'Tenant A Product', 'tenant-a-sequence-product');
        $this->createVersion($customerId, $admin->id);
        $otherProductId = $this->createProduct($otherCustomerId, 'Tenant B Product', 'tenant-b-sequence-product');
        $this->createVersion($otherCustomerId, $otherAdmin->id);

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-cohorts',
                $this->validCohortData([
                    'name' => 'Tenant A Morning',
                    'code' => 'MANUAL-CODE',
                    'product_id' => $productId,
                ])
            )
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-cohorts',
                $this->validCohortData(['name' => 'Tenant A Evening', 'product_id' => $productId])
            )
            ->assertRedirect();

        $this->actingAs($otherAdmin)
            ->post(
                'https://tenant-b.localhost/admin/course-cohorts',
                $this->validCohortData(['name' => 'Tenant B Morning', 'product_id' => $otherProductId])
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

    public function test_edit_class_matches_approved_readonly_layout_and_field_contract(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Approved Product', 'approved-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $cohortId = $this->createCohort($customerId);
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'product_id' => $productId,
            'version_id' => $versionId,
        ]);
        DB::table('core_course_products')->where('id', $productId)->update([
            'registration_starts_at' => '2026-08-01 09:00:00',
            'registration_ends_at' => '2026-08-15 18:00:00',
        ]);

        $response = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/edit")
            ->assertOk()
            ->assertSeeText('Sửa lớp học')
            ->assertSeeText('Mã lớp học')
            ->assertSeeText('Trạng thái')
            ->assertSeeText('Sản phẩm')
            ->assertSeeText('Phiên bản nội dung')
            ->assertSeeText(__('lf.LF_course_cohort_product_registration_window'))
            ->assertSeeText('01/08/2026 09:00')
            ->assertSeeText('15/08/2026 18:00')
            ->assertSeeText(__('lf.LF_course_cohort_product_registration_help'))
            ->assertSeeText(__('lf.LF_course_cohort_create_group_dates'))
            ->assertSee('class="admin-form-standard"', false)
            ->assertSee('course-cohort-edit-tabs', false)
            ->assertSee('course-cohort-detail-tabs-help', false)
            ->assertSee('cohort-edit-status-panel', false)
            ->assertDontSee('name="code"', false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="product_id"', false)
            ->assertDontSee('name="version_id"', false)
            ->assertDontSee('name="registration_starts_at"', false)
            ->assertDontSee('name="registration_ends_at"', false)
            ->assertDontSee('name="description"', false)
            ->assertDontSee('name="cohort_document_file"', false)
            ->assertDontSee('name="cohort_attachment_file"', false);

        $html = $response->getContent();
        $positions = [
            strpos($html, 'id="cohort-edit-code"'),
            strpos($html, 'id="cohort-edit-status"'),
            strpos($html, 'id="cohort-edit-product"'),
            strpos($html, 'id="cohort-edit-version"'),
            strpos($html, 'id="name"'),
            strpos($html, 'id="capacity"'),
            strpos($html, 'id="start_date"'),
            strpos($html, 'id="end_date"'),
            strpos($html, 'id="notes"'),
        ];
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['status' => 'completed']);
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/edit")
            ->assertOk()
            ->assertSee('readonly', false)
            ->assertDontSeeText(__('lf.LF_common_button_save_changes'));
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

    public function test_product_item_resolution_capacity_dates_and_managed_version_contract(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Resolved Product', 'resolved-product');
        $versionId = $this->createVersion($customerId, $admin->id);

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-cohorts',
            $this->validCohortData(['product_id' => $productId, 'version_id' => $versionId]))
            ->assertSessionHasErrors('version_id');

        foreach ([0, -1] as $capacity) {
            $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-cohorts',
                $this->validCohortData(['product_id' => $productId, 'capacity' => $capacity]))
                ->assertSessionHasErrors('capacity');
        }

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-cohorts',
            $this->validCohortData(['product_id' => $productId, 'start_date' => '2026-08-02', 'end_date' => '2026-08-01']))
            ->assertSessionHasErrors('end_date');

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-cohorts',
            $this->validCohortData(['product_id' => $productId, 'capacity' => 1]))->assertRedirect();
        $this->assertDatabaseHas('core_course_cohorts', ['product_id' => $productId, 'version_id' => $versionId, 'capacity' => 1]);
    }

    public function test_lifecycle_binding_freeze_legacy_teacher_and_contextual_ui_contract(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $productId = $this->createProduct($customerId, 'Lifecycle Product', 'lifecycle-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $cohortId = $this->createCohort($customerId, status: 'draft');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['teacher_id' => $teacher->id]);

        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}",
            $this->validCohortData(['product_id' => $productId, 'status' => 'active']))->assertRedirect();
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $cohortId, 'status' => 'draft']);

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/activate")
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}")
            ->assertSessionHas('success', __('lf.LF_course_cohort_lifecycle_activated'));
        $this->assertDatabaseHas('core_course_cohorts', [
            'id' => $cohortId, 'product_id' => $productId, 'version_id' => $versionId,
            'teacher_id' => $teacher->id, 'status' => 'active',
        ]);

        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}",
            $this->validCohortData(['product_id' => $productId, 'status' => 'draft']))->assertRedirect();
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $cohortId, 'status' => 'active']);

        $detail = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}")->assertOk();
        $detail->assertSeeText(__('lf.LF_course_cohort_tab_overview'))
            ->assertSeeText(__('lf.LF_course_cohort_tab_students'))
            ->assertSee('course-cohort-detail', false)
            ->assertSeeText(__('lf.LF_course_cohort_create_group_information'))
            ->assertSeeText(__('lf.LF_course_cohort_create_group_dates'))
            ->assertSeeText(__('lf.LF_course_cohort_create_group_additional'))
            ->assertDontSeeText(__('lf.LF_course_cohort_common_description'))
            ->assertDontSeeText('Cohort media')
            ->assertDontSeeText(__('lf.LF_course_cohort_group_context'))
            ->assertDontSee('name="teacher_id"', false)
            ->assertDontSeeText('LiveClass')->assertDontSeeText('Schedule');

        $layout = file_get_contents(resource_path('views/layouts/backend.blade.php'));
        $this->assertSame(1, substr_count($layout, 'LF_navigation_menu_admin_course_cohorts'));
        $this->assertStringNotContainsString('LF_navigation_menu_admin_course_cohort_students', $layout);
    }

    public function test_canonical_lifecycle_transitions_and_replays_fail_closed(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Lifecycle Product', 'lifecycle-canonical');
        $versionId = $this->createVersion($customerId, $admin->id);
        $cohortId = $this->createCohort($customerId, status: 'draft');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'product_id' => $productId,
            'version_id' => $versionId,
        ]);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/complete")
            ->assertSessionHasErrors('lifecycle');
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/activate")
            ->assertRedirect();
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $cohortId, 'status' => 'active', 'version_id' => $versionId]);

        foreach (['activate', 'archive'] as $action) {
            $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/{$action}")
                ->assertSessionHasErrors('lifecycle');
        }

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/complete")
            ->assertRedirect();
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $cohortId, 'status' => 'completed']);

        foreach (['activate', 'complete'] as $action) {
            $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/{$action}")
                ->assertSessionHasErrors('lifecycle');
        }

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/archive")
            ->assertRedirect();
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $cohortId, 'status' => 'archived']);

        foreach (['activate', 'complete', 'archive'] as $action) {
            $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/{$action}")
                ->assertSessionHasErrors('lifecycle');
        }

        $draftArchiveId = $this->createCohort($customerId, 'Draft Archive', 'draft');
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$draftArchiveId}/archive")
            ->assertRedirect();
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $draftArchiveId, 'status' => 'archived']);
    }

    public function test_activation_revalidates_locked_product_item_and_version_without_migration(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Activation Product', 'activation-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $cohortId = $this->createCohort($customerId, status: 'draft');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'product_id' => $productId,
            'version_id' => $versionId,
        ]);

        DB::table('core_course_products')->where('id', $productId)->update(['status' => 'inactive']);
        $blockedView = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}")
            ->assertOk()
            ->assertSee('course-cohort-activation-action', false)
            ->assertSee('id="cohort-activate-requirements"', false)
            ->assertSee('aria-describedby="cohort-activate-requirements"', false)
            ->assertSee('course-cohort-activation-alert__icon', false);
        $blockedDocument = new \DOMDocument;
        @$blockedDocument->loadHTML($blockedView->getContent());
        $blockedXpath = new \DOMXPath($blockedDocument);
        $this->assertSame(1, $blockedXpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " course-cohort-activation-action ")]'
            .'/*[1][contains(concat(" ", normalize-space(@class), " "), " cohort-lifecycle-action ")]'
            .'/following-sibling::div[@id="cohort-activate-requirements"]'
        )->length);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/activate")
            ->assertSessionHasErrors('lifecycle');

        DB::table('core_course_products')->where('id', $productId)->update(['status' => 'active']);
        DB::table('core_course_product_items')->where('product_id', $productId)->update(['status' => 'inactive']);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/activate")
            ->assertSessionHasErrors('lifecycle');

        DB::table('core_course_product_items')->where('product_id', $productId)->update(['status' => 'active']);
        DB::table('core_course_template_versions')->where('id', $versionId)->update(['status' => 'draft_snapshot']);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/activate")
            ->assertSessionHasErrors('lifecycle');

        DB::table('core_course_template_versions')->where('id', $versionId)->update(['status' => 'published']);
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['capacity' => 0]);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/activate")
            ->assertSessionHasErrors('lifecycle');

        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'capacity' => null,
            'start_date' => '2026-08-02',
            'end_date' => '2026-08-01',
        ]);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/activate")
            ->assertSessionHasErrors('lifecycle');

        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['end_date' => '2026-08-02']);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/activate")
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_cohorts', [
            'id' => $cohortId,
            'status' => 'active',
            'product_id' => $productId,
            'version_id' => $versionId,
        ]);
    }

    public function test_completion_and_archive_preserve_enrollment_membership_and_downstream_data(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Downstream Product', 'downstream-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $cohortId = $this->createCohort($customerId, status: 'active');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'product_id' => $productId,
            'version_id' => $versionId,
        ]);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $membershipId = $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/complete")
            ->assertRedirect();
        $this->assertDatabaseHas('core_course_enrollments', ['id' => $enrollmentId, 'status' => 'active', 'version_id' => $versionId]);
        $this->assertDatabaseHas('core_course_cohort_students', ['id' => $membershipId, 'status' => 'active']);
        $this->assertDatabaseCount('core_course_progress', 0);
        $this->assertDatabaseCount('core_certificate_issued_certificates', 0);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/archive")
            ->assertRedirect();
        $this->assertDatabaseHas('core_course_cohorts', [
            'id' => $cohortId,
            'status' => 'archived',
            'product_id' => $productId,
            'version_id' => $versionId,
        ]);
        $this->assertDatabaseHas('core_course_cohort_students', ['id' => $membershipId]);
        $this->assertDatabaseHas('core_course_enrollments', ['id' => $enrollmentId]);
    }

    public function test_draft_archive_fails_closed_when_legacy_membership_usage_exists(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Legacy Product', 'legacy-archive-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $cohortId = $this->createCohort($customerId, status: 'draft');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['product_id' => $productId, 'version_id' => $versionId]);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $membershipId = $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/archive")
            ->assertSessionHasErrors('lifecycle');
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $cohortId, 'status' => 'draft']);
        $this->assertDatabaseHas('core_course_cohort_students', ['id' => $membershipId]);
    }

    public function test_lifecycle_authorization_tenant_scope_method_contract_and_ui_matrix(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $otherCustomerId = $this->createTenant('Tenant B', 'tenant-b');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');

        $ids = [];
        foreach (['draft', 'active', 'completed', 'archived'] as $status) {
            $ids[$status] = $this->createCohort($customerId, ucfirst($status).' Class', $status);
        }

        $this->actingAs($teacher)->post("https://tenant-a.localhost/admin/course-cohorts/{$ids['draft']}/activate")
            ->assertForbidden();
        $this->actingAs($otherAdmin)->post("https://tenant-b.localhost/admin/course-cohorts/{$ids['draft']}/activate")
            ->assertNotFound();

        foreach (['activate', 'complete', 'archive'] as $route) {
            $routeDefinition = Route::getRoutes()->getByName("admin.course-cohorts.{$route}");
            $this->assertNotNull($routeDefinition);
            $this->assertSame(['POST'], $routeDefinition->methods());
        }
        $this->assertFalse(Route::has('admin.course-cohorts.transition'));

        $index = $this->actingAs($admin)->get('https://tenant-a.localhost/admin/course-cohorts')->assertOk();
        foreach (['draft', 'active'] as $status) {
            $index->assertSee(route('admin.course-cohorts.edit', $ids[$status]), false);
        }
        foreach (['completed', 'archived'] as $status) {
            $index->assertDontSee(route('admin.course-cohorts.edit', $ids[$status]), false);
        }
        foreach (['draft', 'active', 'completed', 'archived'] as $status) {
            $index->assertSeeText(__('lf.LF_course_cohort_common_'.$status));
            $this->assertNotSame(
                "lf.LF_course_cohort_common_{$status}",
                app('translator')->get("lf.LF_course_cohort_common_{$status}", [], 'en')
            );
        }

        $draft = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$ids['draft']}")->assertOk();
        $draft->assertSeeText(__('lf.LF_course_cohort_setup_title'))
            ->assertSeeText(__('lf.LF_course_cohort_action_activate'))
            ->assertSeeText(__('lf.LF_course_cohort_action_edit_overview'))
            ->assertSeeText(__('lf.LF_course_cohort_product_registration_window'))
            ->assertSeeText(__('lf.LF_course_cohort_product_registration_help'))
            ->assertSeeText(__('lf.LF_course_cohort_common_archive'))
            ->assertSeeText(__('lf.LF_course_cohort_lifecycle_activate_body'));
        $this->assertStringContainsString('cohort-overview-heading-actions', $draft->getContent());
        $this->assertStringContainsString('btn btn-secondary', $draft->getContent());
        $this->assertStringContainsString('cohort-lifecycle-dialog', $draft->getContent());
        $this->assertStringContainsString('x-bind:aria-busy="submitting"', $draft->getContent());

        $active = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$ids['active']}")->assertOk();
        $active->assertSeeText(__('lf.LF_course_cohort_action_complete'))
            ->assertSeeText(__('lf.LF_course_cohort_action_edit_overview'))
            ->assertDontSeeText(__('lf.LF_course_cohort_common_archive'));

        $completed = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$ids['completed']}")->assertOk();
        $completed->assertSeeText(__('lf.LF_course_cohort_common_archive'))
            ->assertDontSeeText(__('lf.LF_course_cohort_action_edit'))
            ->assertDontSeeText(__('lf.LF_course_cohort_action_complete'));

        $archived = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$ids['archived']}")->assertOk();
        $archived->assertDontSeeText(__('lf.LF_course_cohort_common_archive'))
            ->assertDontSeeText(__('lf.LF_course_cohort_action_edit'))
            ->assertDontSeeText(__('lf.LF_course_cohort_action_activate'))
            ->assertDontSeeText(__('lf.LF_course_cohort_action_complete'));

        foreach (['completed', 'archived'] as $status) {
            $this->actingAs($admin)->put(
                "https://tenant-a.localhost/admin/course-cohorts/{$ids[$status]}",
                ['name' => 'Forged Update']
            )->assertUnprocessable();
            $this->assertDatabaseMissing('core_course_cohorts', ['id' => $ids[$status], 'name' => 'Forged Update']);
        }
    }

    public function test_create_class_uses_approved_business_form_and_dom_order(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Eligible Product', 'eligible-product');
        $this->createVersion($customerId, $admin->id);
        $ineligibleProductId = $this->createProduct($customerId, 'No Version Product', 'no-version-product');

        $response = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-cohorts/create')
            ->assertOk()
            ->assertSeeText('Tạo lớp học')
            ->assertSeeText(__('lf.LF_course_cohort_create_and_continue'))
            ->assertDontSeeText('Tạo Cohort')
            ->assertSeeText('Bảng điều khiển')
            ->assertSeeText('Lớp học')
            ->assertSee('value="'.$productId.'"', false)
            ->assertDontSee('value="'.$ineligibleProductId.'"', false)
            ->assertSeeText('Chọn sản phẩm')
            ->assertSeeText('Phiên bản nội dung')
            ->assertDontSeeText('Mã lớp học')
            ->assertDontSeeText('Tự động tạo sau khi lưu')
            ->assertSee('aria-live="polite"', false)
            ->assertSee('class="admin-form-standard"', false)
            ->assertSee('class="admin-form-field-grid"', false)
            ->assertSee('x-bind:disabled="submitting"', false)
            ->assertDontSee('name="version_id"', false)
            ->assertDontSee('name="code"', false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="description"', false)
            ->assertDontSee('name="teacher_id"', false)
            ->assertDontSee('name="cohort_document_file"', false)
            ->assertDontSee('name="cohort_attachment_file"', false)
            ->assertDontSeeText('Ngữ cảnh vận hành');

        $html = $response->getContent();
        $orderedIds = ['product_id', 'cohort-create-content-version', 'name', 'capacity', 'start_date', 'end_date', 'notes'];
        $positions = [
            strpos($html, 'id="product_id"'),
            strpos($html, 'aria-live="polite"'),
            strpos($html, 'id="name"'),
            strpos($html, 'id="capacity"'),
            strpos($html, 'id="start_date"'),
            strpos($html, 'id="end_date"'),
            strpos($html, 'id="notes"'),
        ];
        $this->assertNotContains(false, $positions, implode(', ', $orderedIds));
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Create Class DOM order must match the approved one-column reading order.');
    }

    public function test_create_ignores_forged_code_and_status_and_validation_does_not_consume_code(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Secure Product', 'secure-product');
        $versionId = $this->createVersion($customerId, $admin->id);

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-cohorts', [
            'product_id' => $productId, 'name' => '', 'code' => 'FORGED', 'status' => 'active',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
        ])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('core_course_cohorts', 0);

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-cohorts', [
            'product_id' => $productId, 'name' => 'Secure Class', 'code' => 'FORGED',
            'status' => 'active', 'description' => 'Must be ignored', 'notes' => 'Admin only',
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
        ])->assertRedirect();

        $this->assertDatabaseHas('core_course_cohorts', [
            'customer_id' => $customerId, 'product_id' => $productId, 'version_id' => $versionId,
            'name' => 'Secure Class', 'code' => 'COH-20260704-001', 'status' => 'draft',
            'description' => null, 'notes' => 'Admin only',
        ]);
        $this->assertDatabaseMissing('core_course_cohorts', ['code' => 'FORGED']);
    }

    public function test_cohort_operational_tabs_manage_session_evidence(): void
    {
        Carbon::setTestNow('2026-08-04 12:00:00');

        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $replacementTeacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Live Product', 'live-product');
        $versionId = $this->createVersion($customerId, $admin->id, 'Live Version');
        $cohortId = $this->createCohort($customerId, 'Live Cohort');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'product_id' => $productId, 'version_id' => $versionId,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-14',
        ]);
        $lessonId = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId,
            'source_template_lesson_id' => 9001, 'title_snapshot' => 'Lesson 1',
            'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $activityId = DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId,
            'version_lesson_id' => $lessonId, 'source_template_activity_id' => 9101,
            'title_snapshot' => 'Live Lesson 1', 'activity_type' => 'live_class',
            'completion_rule' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $detail = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=teachers")
            ->assertOk();
        foreach (['overview', 'students', 'teachers', 'schedules', 'sessions', 'attendance', 'recordings'] as $tab) {
            $detail->assertSee(__('lf.LF_course_cohort_tab_'.$tab));
        }
        $detail->assertSee('min="2026-08-01"', false)
            ->assertSee('max="2026-08-14"', false)
            ->assertSee('x-bind:readonly="role === \'primary_teacher\'"', false)
            ->assertSeeText(__('lf.LF_course_cohort_teacher_primary_period_summary', [
                'from' => '01/08/2026',
                'to' => '14/08/2026',
            ]));

        $undatedCohortId = $this->createCohort($customerId, 'Undated Cohort');
        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$undatedCohortId}/teachers", [
                'teacher_id' => $teacher->id, 'role' => 'primary_teacher',
            ])->assertSessionHasErrors([
                'role' => __('lf.LF_course_cohort_teacher_validation_primary_period_required'),
            ]);

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/teachers", [
                'teacher_id' => $teacher->id,
                'role' => 'primary_teacher',
                'assigned_from' => '2026-07-31',
                'assigned_to' => '2026-08-05',
            ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_cohort_teachers', [
            'cohort_id' => $cohortId, 'teacher_id' => $teacher->id,
            'role' => 'primary_teacher', 'status' => 'active',
            'assigned_from' => '2026-08-01', 'assigned_to' => '2026-08-14',
        ]);

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/teachers", [
                'teacher_id' => $teacher->id, 'role' => 'teacher',
            ])->assertSessionHasErrors('teacher_id');

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/teachers", [
                'teacher_id' => $replacementTeacher->id, 'role' => 'primary_teacher',
            ])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('core_course_cohort_teachers')
            ->where('cohort_id', $cohortId)->where('role', 'primary_teacher')
            ->where('status', 'active')->count());
        $this->assertDatabaseHas('core_course_cohort_teachers', [
            'cohort_id' => $cohortId, 'teacher_id' => $teacher->id, 'role' => 'teacher',
        ]);
        $this->assertDatabaseHas('core_course_cohort_teachers', [
            'cohort_id' => $cohortId, 'teacher_id' => $replacementTeacher->id,
            'role' => 'primary_teacher', 'assigned_from' => '2026-08-01', 'assigned_to' => '2026-08-14',
        ]);

        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}", [
                'name' => 'Live Cohort', 'capacity' => 20,
                'start_date' => '2026-08-02', 'end_date' => '2026-08-20',
            ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_cohort_teachers', [
            'cohort_id' => $cohortId, 'teacher_id' => $replacementTeacher->id,
            'role' => 'primary_teacher', 'assigned_from' => '2026-08-02', 'assigned_to' => '2026-08-20',
        ]);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=teachers")
            ->assertOk()
            ->assertDontSee('<option value="'.$teacher->id.'"', false);

        $sessionsPage = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")
            ->assertOk()
            ->assertDontSee('name="primary_teacher_id"', false)
            ->assertSee('name="teacher_ids[]"', false)
            ->assertSee('selectableTeachers()', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_teachers_time_help'))
            ->assertSeeText(__('lf.LF_course_cohort_session_teachers'))
            ->assertSeeText(__('lf.LF_course_cohort_session_start'))
            ->assertSeeText(__('lf.LF_course_cohort_session_end'))
            ->assertSee('scheduleMin:', false)
            ->assertSee('scheduleMax:', false)
            ->assertSee('x-bind:min="scheduleMin"', false)
            ->assertSee('x-bind:max="scheduleMax"', false)
            ->assertSee("'has-value': startsAt", false)
            ->assertSee("'has-value': endsAt", false);

        $this->assertSame(
            2,
            substr_count($sessionsPage->getContent(), 'course-cohort-session-form__bound-control')
        );
        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", [
                'title' => 'Elapsed session', 'session_type' => 'curriculum',
                'version_lesson_id' => $lessonId, 'version_activity_id' => $activityId,
                'delivery_mode' => 'online',
                'scheduled_start_at' => '2026-08-03 09:00:00',
                'scheduled_end_at' => '2026-08-03 10:00:00',
            ])->assertSessionHasErrors([
                'scheduled_start_at' => __('lf.LF_course_cohort_session_schedule_before_minimum'),
            ]);

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", [
                'title' => 'Unavailable auxiliary teacher',
                'session_type' => 'curriculum',
                'version_lesson_id' => $lessonId,
                'version_activity_id' => $activityId,
                'teacher_ids' => [$teacher->id],
                'delivery_mode' => 'online',
                'scheduled_start_at' => '2026-08-15 09:00:00',
                'scheduled_end_at' => '2026-08-15 10:00:00',
            ])->assertSessionHasErrors([
                'teacher_ids' => __('lf.LF_course_cohort_session_teacher_unavailable'),
            ]);

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", [
                'title' => 'Session 1', 'session_type' => 'curriculum', 'version_lesson_id' => $lessonId,
                'version_activity_id' => $activityId,
                'teacher_ids' => [$teacher->id, $replacementTeacher->id],
                'delivery_mode' => 'online',
                'scheduled_start_at' => '2026-08-05 09:00:00',
                'scheduled_end_at' => '2026-08-05 10:00:00',
                'online_provider' => 'zoom', 'meeting_url' => 'https://example.com/meeting',
            ])->assertSessionHasNoErrors();
        $sessionId = (int) DB::table('core_liveclass_sessions')->value('id');
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'id' => $sessionId, 'cohort_id' => $cohortId,
            'template_version_id' => $versionId, 'version_lesson_id' => $lessonId,
            'version_activity_id' => $activityId,
            'primary_teacher_id' => null,
        ]);
        $this->assertDatabaseHas('core_liveclass_session_teachers', [
            'customer_id' => $customerId, 'session_id' => $sessionId,
            'teacher_id' => $teacher->id, 'role' => 'teacher',
        ]);
        $this->assertDatabaseHas('core_liveclass_session_teachers', [
            'customer_id' => $customerId, 'session_id' => $sessionId,
            'teacher_id' => $replacementTeacher->id, 'role' => 'teacher',
        ]);
        $this->assertSame(2, DB::table('core_liveclass_session_teachers')
            ->where('customer_id', $customerId)->where('session_id', $sessionId)->count());

        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}", [
                'title' => 'Session 1 updated', 'session_type' => 'curriculum',
                'version_lesson_id' => $lessonId, 'version_activity_id' => $activityId,
                'teacher_ids' => [$teacher->id, $replacementTeacher->id],
                'delivery_mode' => 'online',
                // A forged general-edit request must not bypass the audited
                // schedule-change endpoint.
                'scheduled_start_at' => '2026-08-15 09:00:00',
                'scheduled_end_at' => '2026-08-15 10:00:00',
            ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'id' => $sessionId,
            'title' => 'Session 1 updated',
            'scheduled_start_at' => '2026-08-05 09:00:00',
            'scheduled_end_at' => '2026-08-05 10:00:00',
        ]);
        $this->assertSame(0, DB::table('core_liveclass_session_schedule_changes')
            ->where('customer_id', $customerId)->where('session_id', $sessionId)->count());

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")
            ->assertOk()
            ->assertSeeText($replacementTeacher->name)
            ->assertSee('teacher_ids', false)
            ->assertSee('admin-action-menu__dots', false)
            ->assertDontSee('course-cohort-session-action-menu__chevron', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_detail_schedule'))
            ->assertSeeText(__('lf.LF_course_cohort_session_detail_content'))
            ->assertSeeText(__('lf.LF_course_cohort_session_detail_join'))
            ->assertSeeText(__('lf.LF_course_cohort_session_current_schedule'))
            ->assertSeeText(__('lf.LF_course_cohort_session_new_schedule'))
            ->assertSeeText(__('lf.LF_course_cohort_session_change_reason_help'))
            ->assertSeeText(__('lf.LF_course_cohort_session_edit_scope_help'))
            ->assertSeeText(__('lf.LF_course_cohort_session_edit_schedule_help'))
            ->assertSee('x-bind:readonly="Boolean(editingId)"', false)
            ->assertSee('relationLabels[detailSession?.schedule_relation]', false)
            ->assertSee('relationLabels[rescheduleSession?.schedule_relation]', false)
            ->assertSee('course-cohort-session-modal__schedule-source', false)
            ->assertSee('formatSessionDateTime(detailSession?.scheduled_start_at)', false)
            ->assertSee('activity_meeting_url', false)
            ->assertSee('batchMeetingProvider(row)', false)
            ->assertSee('batchMeetingUrl(row)', false)
            ->assertSee('copyBatchMeetingLink(row)', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_join'))
            ->assertSee("'has-value': rescheduleStart", false)
            ->assertSee("'has-value': rescheduleEnd", false);

        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/schedule", [
                'scheduled_start_at' => '2026-08-15 09:00:00',
                'scheduled_end_at' => '2026-08-15 10:00:00',
                'reason' => 'Outside auxiliary availability',
            ])->assertSessionHasErrors([
                'scheduled_start_at' => __('lf.LF_course_cohort_session_teacher_unavailable'),
            ]);
        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/schedule", [
                'scheduled_start_at' => '2026-08-06 05:00:00',
                'scheduled_end_at' => '2026-08-06 06:00:00',
                'reason' => 'Teacher availability',
            ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_session_schedule_changes', [
            'customer_id' => $customerId, 'session_id' => $sessionId,
            'reason' => 'Teacher availability',
        ]);
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'id' => $sessionId, 'status' => 'scheduled',
            'scheduled_start_at' => '2026-08-06 05:00:00',
        ]);

        Carbon::setTestNow('2026-08-07 12:00:00');
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);
        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/attendance", [
                'attendance' => [[
                    'enrollment_id' => $enrollmentId, 'status' => 'present',
                    'attendance_mode' => 'online',
                ]],
            ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_attendances', [
            'session_id' => $sessionId, 'enrollment_id' => $enrollmentId,
            'version_activity_id' => $activityId, 'status' => 'present',
        ]);

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/recordings", [
                'title' => 'Session replay', 'recording_url' => 'https://example.com/replay',
            ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_recordings', [
            'customer_id' => $customerId, 'session_id' => $sessionId,
            'title' => 'Session replay', 'status' => 'processing',
        ]);
    }

    public function test_runtime_session_operations_fail_closed_for_ineligible_states(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Runtime Product', 'runtime-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $cohortId = $this->createCohort($customerId, status: 'active');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'product_id' => $productId, 'version_id' => $versionId,
        ]);
        $lessonId = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId,
            'source_template_lesson_id' => 9201, 'title_snapshot' => 'Runtime Lesson',
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sessionId = DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId,
            'template_version_id' => $versionId, 'session_type' => 'operational',
            'version_lesson_id' => null, 'version_activity_id' => null,
            'title' => 'Future Session', 'session_no' => 1, 'delivery_mode' => 'online',
            'scheduled_start_at' => now()->addDay(), 'scheduled_end_at' => now()->addDay()->addHour(),
            'timezone' => config('app.timezone'), 'status' => 'scheduled',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);

        $attendancePayload = ['attendance' => [[
            'enrollment_id' => $enrollmentId, 'status' => 'present',
        ]]];
        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/attendance", $attendancePayload)
            ->assertUnprocessable();
        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/recordings", [
                'title' => 'Too early', 'recording_url' => 'https://example.com/future-replay',
            ])->assertUnprocessable();

        DB::table('core_liveclass_sessions')->where('id', $sessionId)->update(['status' => 'cancelled']);
        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/schedule", [
                'scheduled_start_at' => now()->addDays(2),
                'scheduled_end_at' => now()->addDays(2)->addHour(),
            ])->assertUnprocessable();

        $this->assertDatabaseCount('core_liveclass_attendances', 0);
        $this->assertDatabaseCount('core_liveclass_recordings', 0);
        $this->assertDatabaseCount('core_liveclass_session_schedule_changes', 0);
        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $sessionId, 'status' => 'cancelled']);
    }

    public function test_session_types_enforce_locked_version_activity_binding_and_operational_null_binding(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $replacementTeacher = $this->createUser($customerId, 'teacher');
        $versionId = $this->createVersion($customerId, $admin->id, 'Locked Version');
        $otherVersionId = $this->createVersion($customerId, $admin->id, 'Other Version');
        $cohortId = $this->createCohort($customerId, status: 'draft');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'version_id' => $versionId,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
        foreach ([[$teacher->id, 'teacher'], [$replacementTeacher->id, 'primary_teacher']] as [$teacherId, $role]) {
            DB::table('core_course_cohort_teachers')->insert([
                'customer_id' => $customerId, 'cohort_id' => $cohortId,
                'teacher_id' => $teacherId, 'role' => $role, 'status' => 'active',
                'assigned_from' => now()->toDateString(),
                'assigned_to' => now()->addMonth()->toDateString(),
                'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $makeLesson = function (int $tenantId, int $version, string $title, int $source): int {
            return DB::table('core_course_template_version_lessons')->insertGetId([
                'customer_id' => $tenantId, 'template_version_id' => $version,
                'source_template_lesson_id' => $source, 'title_snapshot' => $title,
                'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $makeActivity = function (int $tenantId, int $version, int $lesson, string $type, string $title, int $source): int {
            return DB::table('core_course_template_version_activities')->insertGetId([
                'customer_id' => $tenantId, 'template_version_id' => $version,
                'version_lesson_id' => $lesson, 'source_template_activity_id' => $source,
                'title_snapshot' => $title, 'activity_type' => $type,
                'live_class_url_snapshot' => $type === 'live_class' ? 'https://meet.google.com/source-room' : null,
                'completion_rule' => 'manual', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $lessonId = $makeLesson($customerId, $versionId, 'Locked Lesson', 30001);
        $secondLessonId = $makeLesson($customerId, $versionId, 'Second Lesson', 30002);
        $otherLessonId = $makeLesson($customerId, $otherVersionId, 'Foreign Version Lesson', 30003);
        $activityId = $makeActivity($customerId, $versionId, $lessonId, 'live_class', 'Conversation Practice', 31001);
        $otherLessonActivityId = $makeActivity($customerId, $versionId, $secondLessonId, 'live_class', 'Other Lesson Live', 31002);
        $videoActivityId = $makeActivity($customerId, $versionId, $lessonId, 'video', 'Recorded Video', 31003);

        $otherCustomerId = $this->createTenant('tenant-b');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $crossVersionId = $this->createVersion($otherCustomerId, $otherAdmin->id, 'Cross Tenant Version');
        $crossLessonId = $makeLesson($otherCustomerId, $crossVersionId, 'Cross Tenant Lesson', 32001);

        $tab = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")
            ->assertOk()
            ->assertSee('Locked Lesson')
            ->assertSee('Conversation Practice')
            ->assertDontSee('Foreign Version Lesson')
            ->assertDontSee('Recorded Video')
            ->assertSee('admin-form-footer--sticky', false)
            ->assertSee('if (this.submitting)', false)
            ->assertSee('titleDirty', false)
            ->assertDontSee('if (matches.length === 1)', false)
            ->assertDontSee('`${lesson.title_snapshot} – ${activity.title_snapshot}`', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_curriculum_meeting_help'))
            ->assertSee('course-cohort-session-copy-control__button', false)
            ->assertSee("'is-copied': meetingLinkCopied", false)
            ->assertSee('copyCurriculumMeetingLink()', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_operational_meeting_help'));
        $tab->assertSeeText(__('lf.LF_course_cohort_session_manual_open'))
            ->assertDontSeeText(__('lf.LF_course_cohort_session_batch_open'))
            ->assertDontSee("sourceMode === 'schedule'", false);

        $payload = [
            'title' => 'Locked Lesson – Conversation Practice',
            'session_type' => 'curriculum',
            'version_lesson_id' => $lessonId,
            'version_activity_id' => $activityId,
            'delivery_mode' => 'online',
            'scheduled_start_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'scheduled_end_at' => now()->addDays(2)->addHour()->format('Y-m-d H:i:s'),
            'online_provider' => 'zoom',
            'meeting_url' => 'https://example.com/live',
        ];

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", $payload)
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'title' => 'Locked Lesson – Conversation Practice',
            'online_provider' => 'Google Meet',
            'meeting_url_snapshot' => 'https://meet.google.com/source-room',
        ]);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", array_merge($payload, [
            'title' => 'Second realization',
            'scheduled_start_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'scheduled_end_at' => now()->addDays(3)->addHour()->format('Y-m-d H:i:s'),
        ]))->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('core_liveclass_sessions')->where('version_activity_id', $activityId)->count());

        $occurrenceDate = now()->addDays(4)->toImmutable();
        $scheduleId = DB::table('core_liveclass_schedules')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId,
            'name' => 'Canonical schedule', 'starts_on' => now()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(), 'timezone' => 'Asia/Ho_Chi_Minh',
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $slotId = DB::table('core_liveclass_schedule_slots')->insertGetId([
            'customer_id' => $customerId, 'schedule_id' => $scheduleId,
            'weekday' => $occurrenceDate->isoWeekday(), 'start_time' => '09:00:00',
            'end_time' => '10:00:00', 'sort_order' => 1, 'created_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $secondSlotId = DB::table('core_liveclass_schedule_slots')->insertGetId([
            'customer_id' => $customerId, 'schedule_id' => $scheduleId,
            'weekday' => $occurrenceDate->isoWeekday(), 'start_time' => '11:00:00',
            'end_time' => '12:00:00', 'sort_order' => 2, 'created_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_session_batch_open'))
            ->assertSeeText(__('lf.LF_course_cohort_session_batch_title'))
            ->assertSee('id="session-batch-form"', false)
            ->assertSee('occurrences[0][schedule_slot_id]', false)
            ->assertSee('occurrences[1][schedule_slot_id]', false)
            ->assertSee('batch_occurrence_0', false)
            ->assertSee('batch_occurrence_1', false)
            ->assertSee('aria-controls="batch_occurrence_panel_0"', false)
            ->assertSee('occurrences[0][teacher_ids][]', false)
            ->assertSee('course-cohort-session-batch-teachers__control', false)
            ->assertDontSee('applyBatchDefaults()', false)
            ->assertSee('selectedBatchCount()', false)
            ->assertSee('row.expanded = checked', false)
            ->assertSee('batchRows[0].expanded = !batchRows[0].expanded', false)
            ->assertSee('!batchRows[0].consumed', false)
            ->assertSee('$el.indeterminate = selectedBatchCount() > 0', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_batch_selected_count'))
            ->assertSeeText(__('lf.LF_course_cohort_session_batch_timezone', ['timezone' => 'Asia/Ho_Chi_Minh']))
            ->assertSee('batchRowHint(row)', false)
            ->assertSee('x-show="batchRowHasDraft(batchRows[0])"', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_batch_configuration'))
            ->assertSeeText(__('lf.LF_course_cohort_session_batch_reset_configuration'))
            ->assertSeeText(__('lf.LF_course_cohort_session_batch_reset'))
            ->assertSeeText(__('lf.LF_course_cohort_session_table_date_time'))
            ->assertSeeText(__('lf.LF_course_cohort_session_table_content'))
            ->assertSeeText(__('lf.LF_course_cohort_session_table_teachers'))
            ->assertSeeText('Asia/Ho_Chi_Minh');
        $originPayload = array_merge($payload, [
            'title' => 'Scheduled realization',
            'scheduled_start_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
            'scheduled_end_at' => now()->addDays(10)->addHour()->format('Y-m-d H:i:s'),
            'schedule_id' => $scheduleId,
            'schedule_slot_id' => $slotId,
            'source_local_date' => $occurrenceDate->toDateString(),
        ]);
        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", $originPayload)
            ->assertSessionHasNoErrors();
        $originSessionId = (int) DB::table('core_liveclass_sessions')->where('title', 'Scheduled realization')->value('id');
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'id' => $originSessionId,
            'scheduled_start_at' => $occurrenceDate->format('Y-m-d').' 09:00:00',
            'scheduled_end_at' => $occurrenceDate->format('Y-m-d').' 10:00:00',
            'timezone' => 'Asia/Ho_Chi_Minh',
        ]);
        $this->assertDatabaseHas('core_liveclass_session_schedule_origins', [
            'customer_id' => $customerId, 'session_id' => $originSessionId,
            'schedule_id' => $scheduleId, 'schedule_slot_id' => $slotId,
            'source_local_date' => $occurrenceDate->toDateString(),
        ]);
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_session_batch_created_state'))
            ->assertDontSeeText(__('lf.LF_course_cohort_session_batch_created_reason'))
            ->assertSee('course-cohort-session-table__date', false)
            ->assertSee('course-cohort-session-table__time', false)
            ->assertSee('x-bind:disabled="batchRows[0].consumed"', false)
            ->assertSee('!batchRows[0].consumed', false);
        $sessionCount = DB::table('core_liveclass_sessions')->count();
        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", array_merge($originPayload, [
                'title' => 'Duplicate occurrence',
            ]))->assertSessionHasErrors('source_local_date');
        $this->assertSame($sessionCount, DB::table('core_liveclass_sessions')->count());
        $this->assertDatabaseCount('core_liveclass_session_schedule_origins', 1);

        $batchOccurrence = fn ($date, string $title, array $teacherIds = []): array => [
            'selected' => 1,
            'schedule_id' => $scheduleId,
            'schedule_slot_id' => $slotId,
            'source_local_date' => $date->toDateString(),
            'title' => $title,
            'version_lesson_id' => $lessonId,
            'version_activity_id' => $activityId,
            'teacher_ids' => $teacherIds,
            'delivery_mode' => 'online',
        ];
        $batchDates = [$occurrenceDate->addWeek(), $occurrenceDate->addWeeks(2)];
        DB::table('core_course_cohort_teachers')
            ->where('customer_id', $customerId)->where('cohort_id', $cohortId)
            ->where('teacher_id', $teacher->id)->update(['assigned_to' => '2026-08-20']);
        $evidenceCounts = [
            'attendance' => DB::table('core_liveclass_attendances')->count(),
            'recording' => DB::table('core_liveclass_recordings')->count(),
            'replay' => DB::table('core_liveclass_replays')->count(),
            'progress' => DB::table('core_course_activity_progress')->count(),
        ];
        $incompleteSelectedOccurrence = $batchOccurrence($batchDates[0], 'Needs configuration');
        $incompleteSelectedOccurrence['version_lesson_id'] = null;
        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/batch", [
                'occurrences' => [
                    $incompleteSelectedOccurrence,
                    [
                        'selected' => 0,
                        'schedule_id' => $scheduleId,
                        'schedule_slot_id' => $secondSlotId,
                        'source_local_date' => $batchDates[0]->toDateString(),
                        'title' => 'Retained client draft',
                        'version_lesson_id' => 'not-an-id',
                        'version_activity_id' => 'not-an-id',
                        'teacher_ids' => ['invalid-draft-teacher'],
                        'delivery_mode' => 'invalid-draft-mode',
                    ],
                ],
            ])
            ->assertSessionHasErrors('occurrences.0.version_lesson_id')
            ->assertSessionHasInput('occurrences.1.title', 'Retained client draft')
            ->assertSessionHasInput('occurrences.1.delivery_mode', 'invalid-draft-mode');
        $this->assertSame($sessionCount, DB::table('core_liveclass_sessions')->count());

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/batch", [
                'occurrences' => [
                    $batchOccurrence($batchDates[0], 'Batch lesson 1', [$teacher->id, $replacementTeacher->id]),
                    $batchOccurrence($batchDates[1], 'Batch lesson 2'),
                    [
                        'selected' => 0,
                        'schedule_id' => $scheduleId,
                        'schedule_slot_id' => $secondSlotId,
                        'source_local_date' => $batchDates[0]->toDateString(),
                        'title' => '',
                        'version_lesson_id' => 'not-an-id',
                        'version_activity_id' => 'not-an-id',
                        'teacher_ids' => ['cross-tenant-or-invalid'],
                        'delivery_mode' => 'invalid',
                    ],
                ],
            ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('core_liveclass_session_schedule_origins', 3);
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'title' => 'Batch lesson 1',
            'online_provider' => 'Google Meet',
            'meeting_url_snapshot' => 'https://meet.google.com/source-room',
        ]);
        $this->assertDatabaseHas('core_liveclass_sessions', ['title' => 'Batch lesson 2']);
        $batchSessionId = (int) DB::table('core_liveclass_sessions')->where('title', 'Batch lesson 1')->value('id');
        $this->assertSame(2, DB::table('core_liveclass_session_teachers')
            ->where('customer_id', $customerId)->where('session_id', $batchSessionId)->count());
        $this->assertSame($evidenceCounts['attendance'], DB::table('core_liveclass_attendances')->count());
        $this->assertSame($evidenceCounts['recording'], DB::table('core_liveclass_recordings')->count());
        $this->assertSame($evidenceCounts['replay'], DB::table('core_liveclass_replays')->count());
        $this->assertSame($evidenceCounts['progress'], DB::table('core_course_activity_progress')->count());

        $sessionCountBeforeFailedBatch = DB::table('core_liveclass_sessions')->count();
        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/batch", [
                'occurrences' => [
                    $batchOccurrence($occurrenceDate->addWeeks(3), 'Must roll back'),
                    $batchOccurrence($batchDates[0], 'Duplicate occurrence'),
                ],
            ])->assertSessionHasErrors('occurrences.1.source_local_date');
        $this->assertSame($sessionCountBeforeFailedBatch, DB::table('core_liveclass_sessions')->count());
        $this->assertDatabaseCount('core_liveclass_session_schedule_origins', 3);
        $this->assertDatabaseMissing('core_liveclass_sessions', ['title' => 'Must roll back']);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", array_merge($payload, [
            'version_lesson_id' => $otherLessonId,
        ]))->assertSessionHasErrors('version_lesson_id');
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", array_merge($payload, [
            'version_activity_id' => $otherLessonActivityId,
        ]))->assertUnprocessable();
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", array_merge($payload, [
            'version_activity_id' => $videoActivityId,
        ]))->assertUnprocessable();
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", array_merge($payload, [
            'version_lesson_id' => $crossLessonId,
        ]))->assertSessionHasErrors('version_lesson_id');

        $progressCount = DB::table('core_course_activity_progress')->count();
        $operationalPayload = array_diff_key($payload, array_flip(['online_provider', 'meeting_url']));
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", array_merge($operationalPayload, [
            'title' => 'Orientation', 'session_type' => 'operational',
        ]))->assertSessionHasNoErrors();
        $operationalId = (int) DB::table('core_liveclass_sessions')->where('title', 'Orientation')->value('id');
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'id' => $operationalId, 'session_type' => 'operational',
            'version_lesson_id' => null, 'version_activity_id' => null,
            'online_provider' => null, 'meeting_url_snapshot' => null,
        ]);
        $this->assertSame($progressCount, DB::table('core_course_activity_progress')->count());

        $curriculumId = (int) DB::table('core_liveclass_sessions')->where('title', 'Second realization')->value('id');
        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$curriculumId}", array_merge($payload, [
            'title' => 'Workshop', 'session_type' => 'operational',
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'id' => $curriculumId, 'title' => 'Workshop', 'session_type' => 'operational',
            'version_lesson_id' => null, 'version_activity_id' => null,
        ]);
    }

    public function test_create_and_draft_detail_use_canonical_accessibility_aware_tabs(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Tab Product', 'tab-product');
        $versionId = $this->createVersion($customerId, $admin->id);

        $create = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-cohorts/create')->assertOk();
        foreach (['overview', 'students', 'teachers', 'schedules', 'sessions', 'attendance', 'recordings'] as $tab) {
            $create->assertSeeText(__('lf.LF_course_cohort_tab_'.$tab));
        }
        $create->assertSee('aria-disabled="true"', false)
            ->assertSeeText(__('lf.LF_course_cohort_tab_students_locked_create'));

        $cohortId = $this->createCohort($customerId, status: 'draft');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'product_id' => $productId,
            'version_id' => $versionId,
        ]);
        $detail = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=attendance")
            ->assertOk();
        $detail->assertSeeText(__('lf.LF_course_cohort_tab_attendance_locked_active'))
            ->assertSeeText(__('lf.LF_course_cohort_tab_students_detail_note'))
            ->assertSeeText(__('lf.LF_course_cohort_tab_overview_detail_note'))
            ->assertDontSeeText(__('lf.LF_course_cohort_tab_overview_note'));

        $students = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=students")
            ->assertOk();
        $students->assertSee(route('admin.course-cohorts.students.sync', $cohortId), false)
            ->assertSee('course-cohort-student-inline-form', false)
            ->assertSee('course-cohort-student-name', false)
            ->assertDontSee(route('admin.course-cohorts.edit', ['id' => $cohortId, 'tab' => 'students']), false);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=teachers")
            ->assertOk()
            ->assertSee(route('admin.course-cohorts.teachers.store', $cohortId), false);
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")
            ->assertOk()
            ->assertSee(route('admin.course-cohorts.sessions.store', $cohortId), false);
    }

    public function test_draft_allows_setup_but_rejects_runtime_operations(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $cohortId = $this->createCohort($customerId, status: 'draft');

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/teachers", [
            'teacher_id' => $teacher->id,
            'role' => 'teacher',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_cohort_teachers', [
            'customer_id' => $customerId, 'cohort_id' => $cohortId,
            'teacher_id' => $teacher->id, 'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/999/attendance", ['attendance' => []])
            ->assertStatus(422);
        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/999/recordings", ['title' => 'Draft recording'])
            ->assertStatus(422);
    }

    public function test_activation_revalidates_memberships_and_keeps_draft_on_failure(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Activation Product', 'activation-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $cohortId = $this->createCohort($customerId, status: 'draft');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'product_id' => $productId, 'version_id' => $versionId,
        ]);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);
        DB::table('core_course_enrollments')->where('id', $enrollmentId)->update(['status' => 'suspended']);

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/activate")
            ->assertSessionHasErrors('lifecycle');
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $cohortId, 'status' => 'draft']);
        $this->assertDatabaseHas('core_course_cohort_students', [
            'cohort_id' => $cohortId, 'enrollment_id' => $enrollmentId, 'status' => 'active',
        ]);
    }

    public function test_schedule_schema_routes_and_navigation_are_separate_from_sessions(): void
    {
        foreach (['core_liveclass_schedules', 'core_liveclass_schedule_slots', 'core_liveclass_schedule_exclusions'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertTrue(Schema::hasColumns($table, ['id', 'customer_id', 'created_by', 'created_at', 'updated_at']));
        }
        $this->assertFalse(Schema::hasColumn('core_liveclass_schedules', 'status'));
        $this->assertFalse(Schema::hasColumn('core_liveclass_schedules', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('core_liveclass_schedules', 'recurrence'));
        foreach (['create', 'store', 'preview', 'show', 'edit', 'update'] as $route) {
            $this->assertTrue(Route::has("admin.course-cohorts.schedules.{$route}"));
        }
        $this->assertFalse(Route::has('admin.course-cohorts.schedules.destroy'));

        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId);
        $response = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_tab_schedules'))
            ->assertSeeText(__('lf.LF_course_cohort_tab_sessions'))
            ->assertSeeText(__('lf.LF_course_cohort_schedule_empty'))
            ->assertSeeText(__('lf.LF_course_cohort_schedule_empty_help'));
        $this->assertTrue(
            strpos($response->getContent(), '?tab=schedules')
                < strpos($response->getContent(), '?tab=sessions')
        );
        $response->assertSee(route('admin.course-cohorts.show', ['id' => $cohortId, 'tab' => 'sessions']), false);
    }

    public function test_admin_can_create_schedule_with_slots_exclusion_and_canonical_preview(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId, status: 'draft');
        $payload = $this->validScheduleData([
            'starts_on' => '2026-08-03',
            'ends_on' => '2026-08-05',
            'slots' => [
                ['weekday' => 1, 'start_time' => '19:00', 'end_time' => '21:00'],
                ['weekday' => 3, 'start_time' => '18:30', 'end_time' => '20:00'],
            ],
            'exclusions' => [['excluded_on' => '2026-08-05', 'reason' => 'Holiday']],
        ]);

        $preview = $this->actingAs($admin)->postJson(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules/preview",
            $payload
        )->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('count', 1);
        $this->assertSame('2026-08-03', $preview->json('data.0.date'));
        $this->assertSame('2026-08-03T19:00:00+07:00', $preview->json('data.0.starts_at'));

        $response = $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules",
            $payload
        )->assertSessionHasNoErrors();
        $scheduleId = (int) DB::table('core_liveclass_schedules')->value('id');
        $response->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules")
            ->assertSessionHas('success', __('lf.LF_course_cohort_schedule_created'));
        $this->assertDatabaseHas('core_liveclass_schedules', [
            'id' => $scheduleId, 'customer_id' => $customerId, 'cohort_id' => $cohortId,
            'name' => 'Evening schedule', 'timezone' => 'Asia/Ho_Chi_Minh',
        ]);
        $this->assertDatabaseCount('core_liveclass_schedule_slots', 2);
        $this->assertDatabaseHas('core_liveclass_schedule_exclusions', [
            'schedule_id' => $scheduleId, 'excluded_on' => '2026-08-05', 'reason' => 'Holiday',
        ]);
        $this->assertDatabaseCount('core_liveclass_sessions', 0);
        $this->assertDatabaseCount('core_liveclass_attendances', 0);
        $this->assertDatabaseCount('core_liveclass_recordings', 0);
        $this->assertDatabaseCount('core_liveclass_replays', 0);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules/{$scheduleId}")
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=view&schedule_id={$scheduleId}#cohort-schedule-editor");
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=view&schedule_id={$scheduleId}")
            ->assertOk()
            ->assertSeeText('Evening schedule')
            ->assertSeeText(__('lf.LF_course_cohort_schedule_list_name'))
            ->assertSeeText(__('lf.LF_course_cohort_schedule_days_and_times'))
            ->assertSeeText(__('lf.LF_course_cohort_schedule_expected_count'))
            ->assertSeeText(__('lf.LF_course_cohort_schedule_detail_notice'))
            ->assertSeeText(__('lf.LF_course_cohort_schedule_status_upcoming'))
            ->assertSee('course-cohort-schedule-detail--inline', false)
            ->assertSee('course-cohort-schedule-detail__eyebrow', false)
            ->assertSee('course-cohort-schedule-detail__section-header', false)
            ->assertSee('admin-form-footer--sticky course-cohort-schedule-detail__footer', false)
            ->assertSee('admin-action-menu__trigger', false)
            ->assertSee('admin-action-menu__dots', false)
            ->assertSee('course-cohort-schedules__table-wrap', false);
    }

    public function test_schedule_preview_includes_both_date_boundaries_and_multiple_slots_per_day(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId, status: 'draft');
        $payload = $this->validScheduleData([
            'starts_on' => '2026-08-03',
            'ends_on' => '2026-08-10',
            'slots' => [
                ['weekday' => 1, 'start_time' => '08:00', 'end_time' => '09:00'],
                ['weekday' => 1, 'start_time' => '19:00', 'end_time' => '21:00'],
            ],
        ]);

        $response = $this->actingAs($admin)->postJson(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules/preview",
            $payload
        )->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('count', 4);

        $this->assertSame(
            ['2026-08-03', '2026-08-03', '2026-08-10', '2026-08-10'],
            collect($response->json('data'))->pluck('date')->all()
        );
        $this->assertSame(
            ['08:00', '19:00', '08:00', '19:00'],
            collect($response->json('data'))->pluck('start_time')->all()
        );
    }

    public function test_schedule_create_and_edit_forms_render_with_canonical_preview_controls(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId, status: 'draft');

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules/create")
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=create#cohort-schedule-editor");

        $createResponse = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=create")
            ->assertOk()
            ->assertDontSee('course-cohort-schedules__editor-header', false)
            ->assertSeeText(__('lf.LF_course_cohort_schedule_preview'))
            ->assertSeeText(__('lf.LF_course_cohort_schedule_preview_notice'))
            ->assertSeeText(__('lf.LF_course_cohort_schedule_preview_empty'))
            ->assertSee('placeholder="'.__('lf.LF_course_cohort_schedule_name_placeholder').'"', false)
            ->assertSee('placeholder="'.__('lf.LF_course_cohort_schedule_reason_placeholder').'"', false)
            ->assertSee('course-cohort-schedule-preview__timezone', false)
            ->assertSee('course-cohort-schedule-preview__summary', false)
            ->assertSee('course-cohort-schedules__col-slots', false)
            ->assertSee('id="schedule_starts_on"', false)
            ->assertSee('min="2026-08-01"', false)
            ->assertSee('max="2026-08-31"', false)
            ->assertSee("'is-lf-placeholder': !startsOn", false)
            ->assertSee('x-show="!formOpen"', false)
            ->assertSee('course-cohort-schedules__operation-window', false)
            ->assertDontSee('id="schedule_starts_on" disabled', false)
            ->assertDontSee('id="schedule_starts_on" readonly', false);
        $this->assertTrue(
            strpos($createResponse->getContent(), 'id="cohort-schedule-editor"')
                < strpos($createResponse->getContent(), 'course-cohort-schedules__table-wrap')
        );
        $this->assertSame(
            2,
            substr_count($createResponse->getContent(), 'class="course-cohort-schedule-form__append')
        );
        $this->assertSame(
            1,
            substr_count($createResponse->getContent(), 'course-cohort-schedule-form__append--exclusion')
        );
        $createResponse
            ->assertSee('this.slots.push', false)
            ->assertSee('this.slots.splice(index, 1)', false)
            ->assertSee('if (this.slots.length <= 1) return', false)
            ->assertSee('field?.focus()', false)
            ->assertSee('scrollIntoView', false)
            ->assertSee('maxSlots: 50', false)
            ->assertSee('maxExclusions: 366', false)
            ->assertSee('this.exclusions.push', false)
            ->assertDontSee('addExclusion(index)', false)
            ->assertSee('this.exclusions.length - 1', false)
            ->assertSee("closest('.course-cohort-schedule-form__exclusion-row')", false)
            ->assertSee('nextErrors[current - 1] = value', false);

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules",
            $this->validScheduleData()
        );
        $scheduleId = (int) DB::table('core_liveclass_schedules')->value('id');

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules/{$scheduleId}/edit")
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=edit&schedule_id={$scheduleId}#cohort-schedule-editor");

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=edit&schedule_id={$scheduleId}")
            ->assertOk()
            ->assertDontSee('course-cohort-schedules__editor-header', false)
            ->assertSee('course-cohort-schedules__status-badge', false)
            ->assertSee('course-cohort-schedules__expected-count', false)
            ->assertSee('Evening schedule', false);
    }

    public function test_schedule_validation_rejects_invalid_ranges_slots_and_exclusions(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId);
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules";

        $this->actingAs($admin)->post($url, $this->validScheduleData(['slots' => []]))
            ->assertSessionHasErrors('slots');
        $this->actingAs($admin)->post($url, $this->validScheduleData([
            'slots' => [['weekday' => 1, 'start_time' => '20:00', 'end_time' => '19:00']],
        ]))->assertSessionHasErrors('slots.0.end_time');
        $this->actingAs($admin)->post($url, $this->validScheduleData([
            'slots' => [
                ['weekday' => 1, 'start_time' => '19:00', 'end_time' => '21:00'],
                ['weekday' => 1, 'start_time' => '20:00', 'end_time' => '22:00'],
            ],
        ]))->assertSessionHasErrors('slots');
        $this->actingAs($admin)->post($url, $this->validScheduleData([
            'slots' => [
                ['weekday' => 1, 'start_time' => '19:00', 'end_time' => '21:00'],
                ['weekday' => 1, 'start_time' => '19:00', 'end_time' => '21:00'],
            ],
        ]))->assertSessionHasErrors('slots');
        $this->actingAs($admin)->post($url, $this->validScheduleData([
            'starts_on' => '2026-07-31',
        ]))->assertSessionHasErrors('starts_on');
        $this->actingAs($admin)->post($url, $this->validScheduleData([
            'exclusions' => [['excluded_on' => '2026-09-01', 'reason' => null]],
        ]))->assertSessionHasErrors('exclusions.0.excluded_on');
        $this->actingAs($admin)->post($url, $this->validScheduleData([
            'exclusions' => [
                ['excluded_on' => '2026-08-10', 'reason' => null],
                ['excluded_on' => '2026-08-10', 'reason' => null],
            ],
        ]))->assertSessionHasErrors('exclusions.1.excluded_on');
        $this->actingAs($admin)->post($url, array_merge($this->validScheduleData(), [
            'customer_id' => $customerId,
            'status' => 'active',
        ]))->assertSessionHasErrors(['customer_id', 'status']);
        $this->assertDatabaseCount('core_liveclass_schedules', 0);
    }

    public function test_new_cohort_requires_a_complete_valid_inclusive_operating_period(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Operating Period Product', 'operating-period-product');
        $this->createVersion($customerId, $admin->id);
        $url = 'https://tenant-a.localhost/admin/course-cohorts';

        $this->actingAs($admin)->post($url, $this->validCohortData([
            'product_id' => $productId, 'start_date' => null, 'end_date' => null,
        ]))->assertSessionHasErrors(['start_date', 'end_date']);
        $this->actingAs($admin)->post($url, $this->validCohortData([
            'product_id' => $productId, 'start_date' => '2026-08-02', 'end_date' => '2026-08-01',
        ]))->assertSessionHasErrors('end_date');

        $this->actingAs($admin)->post($url, $this->validCohortData([
            'product_id' => $productId,
            'name' => 'One day cohort',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-01',
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_cohorts', [
            'customer_id' => $customerId,
            'name' => 'One day cohort',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-01',
        ]);
    }

    public function test_legacy_cohort_without_operating_period_remains_readable_but_schedule_actions_fail_closed(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohort($customerId, status: 'draft');

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}")
            ->assertOk();
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules/create")
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=create#cohort-schedule-editor");
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=create")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_schedule_operation_required'))
            ->assertSee(route('admin.course-cohorts.edit', $cohortId), false);
        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules", $this->validScheduleData())
            ->assertSessionHasErrors('starts_on');
        $this->actingAs($admin)
            ->postJson("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules/preview", $this->validScheduleData())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_on');
        $this->assertDatabaseCount('core_liveclass_schedules', 0);
    }

    public function test_schedule_range_is_an_inclusive_subrange_of_cohort_operating_period_for_store_update_and_preview(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId, status: 'draft');
        $baseUrl = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules";

        $this->actingAs($admin)->post($baseUrl, $this->validScheduleData([
            'name' => 'Full boundary schedule',
        ]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post($baseUrl, $this->validScheduleData([
            'name' => 'Inner schedule', 'starts_on' => '2026-08-02', 'ends_on' => '2026-08-30',
        ]))->assertSessionHasNoErrors();
        $innerId = (int) DB::table('core_liveclass_schedules')->where('name', 'Inner schedule')->value('id');

        foreach ([
            ['starts_on' => '2026-07-31'],
            ['ends_on' => '2026-09-01'],
            ['starts_on' => '2026-07-31', 'ends_on' => '2026-09-01'],
        ] as $invalidRange) {
            $this->actingAs($admin)->post($baseUrl, $this->validScheduleData($invalidRange))
                ->assertSessionHasErrors('starts_on');
            $this->actingAs($admin)->postJson("{$baseUrl}/preview", $this->validScheduleData($invalidRange))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('starts_on');
        }

        $this->actingAs($admin)->put("{$baseUrl}/{$innerId}", $this->validScheduleData([
            'name' => 'Invalid update', 'starts_on' => '2026-07-31',
        ]))->assertSessionHasErrors('starts_on');
        $this->assertDatabaseHas('core_liveclass_schedules', [
            'id' => $innerId, 'name' => 'Inner schedule', 'starts_on' => '2026-08-02', 'ends_on' => '2026-08-30',
        ]);
        $this->assertDatabaseCount('core_liveclass_sessions', 0);
    }

    public function test_cohort_operating_period_cannot_shrink_past_existing_schedules_and_never_mutates_them(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId, status: 'draft');
        $scheduleUrl = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules";
        $cohortUrl = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}";

        $this->actingAs($admin)->post($scheduleUrl, $this->validScheduleData([
            'name' => 'First schedule', 'starts_on' => '2026-08-03', 'ends_on' => '2026-08-10',
        ]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post($scheduleUrl, $this->validScheduleData([
            'name' => 'Second schedule', 'starts_on' => '2026-08-20', 'ends_on' => '2026-08-29',
        ]))->assertSessionHasNoErrors();
        $before = DB::table('core_liveclass_schedules')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->actingAs($admin)->put($cohortUrl, $this->validCohortData([
            'start_date' => '2026-08-04', 'end_date' => '2026-08-28',
        ]))->assertSessionHasErrors('start_date');
        $this->assertDatabaseHas('core_course_cohorts', [
            'id' => $cohortId, 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
        ]);

        $this->actingAs($admin)->put($cohortUrl, $this->validCohortData([
            'start_date' => '2026-08-03', 'end_date' => '2026-08-29',
        ]))->assertSessionHasNoErrors();
        $this->actingAs($admin)->put($cohortUrl, $this->validCohortData([
            'start_date' => '2026-07-01', 'end_date' => '2026-09-30',
        ]))->assertSessionHasNoErrors();

        $after = DB::table('core_liveclass_schedules')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('core_liveclass_schedules', 2);
        $this->assertDatabaseCount('core_liveclass_sessions', 0);
        $this->assertDatabaseCount('core_liveclass_attendances', 0);
        $this->assertDatabaseCount('core_liveclass_recordings', 0);
        $this->assertDatabaseCount('core_liveclass_replays', 0);
    }

    public function test_schedule_update_replaces_children_atomically_without_touching_sessions(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId);
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules",
            $this->validScheduleData()
        );
        $scheduleId = (int) DB::table('core_liveclass_schedules')->value('id');
        $sessionCount = DB::table('core_liveclass_sessions')->count();

        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules/{$scheduleId}",
            $this->validScheduleData([
                'name' => 'Updated schedule',
                'slots' => [
                    ['weekday' => 2, 'start_time' => '08:00', 'end_time' => '10:00'],
                    ['weekday' => 4, 'start_time' => '13:00', 'end_time' => '15:00'],
                ],
                'exclusions' => [['excluded_on' => '2026-08-11', 'reason' => 'Break']],
            ])
        )->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules")
            ->assertSessionHas('success', __('lf.LF_course_cohort_schedule_updated'));
        $this->assertDatabaseHas('core_liveclass_schedules', ['id' => $scheduleId, 'name' => 'Updated schedule']);
        $this->assertSame(2, DB::table('core_liveclass_schedule_slots')->where('schedule_id', $scheduleId)->count());
        $this->assertSame(1, DB::table('core_liveclass_schedule_exclusions')->where('schedule_id', $scheduleId)->count());
        $this->assertSame($sessionCount, DB::table('core_liveclass_sessions')->count());

        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules/{$scheduleId}",
            $this->validScheduleData(['slots' => []])
        )->assertSessionHasErrors('slots');
        $this->assertDatabaseHas('core_liveclass_schedules', ['id' => $scheduleId, 'name' => 'Updated schedule']);
        $this->assertSame(2, DB::table('core_liveclass_schedule_slots')->where('schedule_id', $scheduleId)->count());
    }

    public function test_schedule_create_and_update_change_only_schedule_domain_data(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $productId = $this->createProduct($customerId, 'Boundary Product', 'boundary-product');
        $versionId = $this->createVersion($customerId, $admin->id, 'Boundary Version');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId, status: 'active');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'product_id' => $productId,
            'version_id' => $versionId,
            'capacity' => 20,
        ]);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $membershipId = $this->createMembership(
            $customerId,
            $cohortId,
            $enrollmentId,
            $productId,
            $student->id
        );
        $teacherAssignmentId = DB::table('core_course_cohort_teachers')->insertGetId([
            'customer_id' => $customerId,
            'cohort_id' => $cohortId,
            'teacher_id' => $teacher->id,
            'role' => 'primary_teacher',
            'assigned_from' => '2026-08-01',
            'assigned_to' => '2026-08-31',
            'status' => 'active',
            'created_by' => $admin->id,
            'created_at' => '2026-08-01 00:00:00',
            'updated_at' => '2026-08-01 00:00:00',
        ]);
        $lessonId = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customerId,
            'template_version_id' => $versionId,
            'source_template_lesson_id' => 9801,
            'title_snapshot' => 'Boundary Lesson',
            'sort_order' => 1,
            'created_at' => '2026-08-01 00:00:00',
            'updated_at' => '2026-08-01 00:00:00',
        ]);
        $activityId = DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $customerId,
            'template_version_id' => $versionId,
            'version_lesson_id' => $lessonId,
            'source_template_activity_id' => 9802,
            'title_snapshot' => 'Boundary Live Activity',
            'activity_type' => 'live_class',
            'completion_rule' => 'attendance',
            'created_at' => '2026-08-01 00:00:00',
            'updated_at' => '2026-08-01 00:00:00',
        ]);
        $sessionId = DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $customerId,
            'cohort_id' => $cohortId,
            'template_version_id' => $versionId,
            'session_type' => 'curriculum',
            'version_lesson_id' => $lessonId,
            'version_activity_id' => $activityId,
            'room_id' => null,
            'primary_teacher_id' => null,
            'superseded_by_session_id' => null,
            'title' => 'Existing Session',
            'session_no' => 1,
            'delivery_mode' => 'online',
            'scheduled_start_at' => '2026-08-10 19:00:00',
            'scheduled_end_at' => '2026-08-10 21:00:00',
            'actual_start_at' => null,
            'actual_end_at' => null,
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled',
            'online_provider' => 'zoom',
            'meeting_url_snapshot' => 'https://example.test/existing-session',
            'meeting_id_snapshot' => 'existing-001',
            'facility_name_snapshot' => null,
            'room_name_snapshot' => null,
            'address_snapshot' => null,
            'cancellation_reason' => null,
            'metadata' => json_encode(['preserve' => true]),
            'created_by' => $admin->id,
            'created_at' => '2026-08-01 01:00:00',
            'updated_at' => '2026-08-01 01:00:00',
        ]);
        $sessionTeacherId = DB::table('core_liveclass_session_teachers')->insertGetId([
            'customer_id' => $customerId,
            'session_id' => $sessionId,
            'teacher_id' => $teacher->id,
            'role' => 'teacher',
            'assigned_from' => '2026-08-10 19:00:00',
            'assigned_to' => '2026-08-10 21:00:00',
            'created_by' => $admin->id,
            'created_at' => '2026-08-01 01:00:00',
            'updated_at' => '2026-08-01 01:00:00',
        ]);
        $attendanceId = DB::table('core_liveclass_attendances')->insertGetId([
            'customer_id' => $customerId,
            'session_id' => $sessionId,
            'enrollment_id' => $enrollmentId,
            'user_id' => $student->id,
            'version_activity_id' => $activityId,
            'status' => 'registered',
            'attendance_mode' => 'online',
            'duration_seconds' => 0,
            'attendance_percentage' => 0,
            'attendance_source' => 'manual',
            'created_at' => '2026-08-01 01:00:00',
            'updated_at' => '2026-08-01 01:00:00',
        ]);
        $recordingId = DB::table('core_liveclass_recordings')->insertGetId([
            'customer_id' => $customerId,
            'session_id' => $sessionId,
            'title' => 'Existing Recording',
            'recording_url' => 'https://example.test/recording',
            'duration_seconds' => 3600,
            'visibility' => 'cohort',
            'status' => 'ready',
            'created_by' => $admin->id,
            'created_at' => '2026-08-01 01:00:00',
            'updated_at' => '2026-08-01 01:00:00',
        ]);
        $replayId = DB::table('core_liveclass_replays')->insertGetId([
            'customer_id' => $customerId,
            'recording_id' => $recordingId,
            'session_id' => $sessionId,
            'enrollment_id' => $enrollmentId,
            'user_id' => $student->id,
            'version_activity_id' => $activityId,
            'watched_seconds' => 120,
            'watched_percentage' => 3.33,
            'last_watched_at' => '2026-08-01 02:00:00',
            'created_at' => '2026-08-01 02:00:00',
            'updated_at' => '2026-08-01 02:00:00',
        ]);
        $progressId = DB::table('core_course_progress')->insertGetId([
            'customer_id' => $customerId,
            'enrollment_id' => $enrollmentId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'student_id' => $student->id,
            'progress_percentage' => 100,
            'status' => 'completed',
            'completed_at' => '2026-08-01 03:00:00',
            'created_at' => '2026-08-01 03:00:00',
            'updated_at' => '2026-08-01 03:00:00',
        ]);
        $completionId = DB::table('core_course_completions')->insertGetId([
            'customer_id' => $customerId,
            'enrollment_id' => $enrollmentId,
            'course_progress_id' => $progressId,
            'product_id' => $productId,
            'version_id' => $versionId,
            'student_id' => $student->id,
            'completion_rule' => 'progress',
            'final_progress_percentage' => 100,
            'completed_at' => '2026-08-01 03:00:00',
            'completion_source' => 'system',
            'status' => 'completed',
            'created_at' => '2026-08-01 03:00:00',
            'updated_at' => '2026-08-01 03:00:00',
        ]);

        $snapshotQueries = [
            'cohort' => ['core_course_cohorts', 'id', $cohortId],
            'enrollment' => ['core_course_enrollments', 'id', $enrollmentId],
            'membership' => ['core_course_cohort_students', 'id', $membershipId],
            'teacher_assignment' => ['core_course_cohort_teachers', 'id', $teacherAssignmentId],
            'session' => ['core_liveclass_sessions', 'id', $sessionId],
            'session_teacher' => ['core_liveclass_session_teachers', 'id', $sessionTeacherId],
            'attendance' => ['core_liveclass_attendances', 'id', $attendanceId],
            'recording' => ['core_liveclass_recordings', 'id', $recordingId],
            'replay' => ['core_liveclass_replays', 'id', $replayId],
            'progress' => ['core_course_progress', 'id', $progressId],
            'completion' => ['core_course_completions', 'id', $completionId],
        ];
        $before = $this->snapshotRows($snapshotQueries);

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules",
            $this->validScheduleData()
        )->assertSessionHasNoErrors();
        $scheduleId = (int) DB::table('core_liveclass_schedules')->where('cohort_id', $cohortId)->value('id');

        $this->assertSame($before, $this->snapshotRows($snapshotQueries));
        $this->assertDatabaseCount('core_liveclass_schedules', 1);
        $this->assertDatabaseCount('core_liveclass_schedule_slots', 1);
        $this->assertDatabaseCount('core_liveclass_schedule_exclusions', 0);

        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules/{$scheduleId}",
            $this->validScheduleData([
                'name' => 'Updated isolated schedule',
                'slots' => [
                    ['weekday' => 2, 'start_time' => '08:00', 'end_time' => '10:00'],
                    ['weekday' => 4, 'start_time' => '19:00', 'end_time' => '21:00'],
                ],
                'exclusions' => [['excluded_on' => '2026-08-13', 'reason' => 'Holiday']],
            ])
        )->assertSessionHasNoErrors();

        $this->assertSame($before, $this->snapshotRows($snapshotQueries));
        $this->assertDatabaseHas('core_liveclass_schedules', [
            'id' => $scheduleId,
            'name' => 'Updated isolated schedule',
        ]);
        $this->assertDatabaseCount('core_liveclass_schedule_slots', 2);
        $this->assertDatabaseCount('core_liveclass_schedule_exclusions', 1);
    }

    public function test_schedule_authorization_lifecycle_and_tenant_scope_fail_closed(): void
    {
        $customerA = $this->createTenant('tenant-a');
        $customerB = $this->createTenant('tenant-b');
        $adminA = $this->createUser($customerA, 'customer_admin');
        $adminB = $this->createUser($customerB, 'customer_admin');
        $teacher = $this->createUser($customerA, 'teacher');
        $draft = $this->createCohortWithOperatingPeriod($customerA, status: 'draft');
        $active = $this->createCohortWithOperatingPeriod($customerA, status: 'active');
        $completed = $this->createCohortWithOperatingPeriod($customerA, status: 'completed');
        $archived = $this->createCohortWithOperatingPeriod($customerA, status: 'archived');

        foreach ([$draft, $active] as $cohortId) {
            $this->actingAs($adminA)->post(
                "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules",
                $this->validScheduleData()
            )->assertSessionHasNoErrors();
        }
        foreach ([$completed, $archived] as $cohortId) {
            $this->actingAs($adminA)->post(
                "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules",
                $this->validScheduleData()
            )->assertForbidden();
        }
        $this->actingAs($teacher)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$draft}/schedules",
            $this->validScheduleData()
        )->assertForbidden();
        $scheduleId = (int) DB::table('core_liveclass_schedules')->where('cohort_id', $draft)->value('id');
        $this->actingAs($adminB)
            ->get("https://tenant-b.localhost/admin/course-cohorts/{$draft}/schedules/{$scheduleId}")
            ->assertNotFound();
        $this->actingAs($adminB)->put(
            "https://tenant-b.localhost/admin/course-cohorts/{$draft}/schedules/{$scheduleId}",
            $this->validScheduleData(['name' => 'Forged'])
        )->assertForbidden();
        $this->assertDatabaseMissing('core_liveclass_schedules', ['id' => $scheduleId, 'name' => 'Forged']);
    }

    public function test_completed_schedule_is_readonly_and_has_no_delete_ui_or_endpoint(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId, status: 'active');
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules",
            $this->validScheduleData()
        );
        $scheduleId = (int) DB::table('core_liveclass_schedules')->value('id');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['status' => 'completed']);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules/{$scheduleId}")
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=view&schedule_id={$scheduleId}#cohort-schedule-editor");
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=view&schedule_id={$scheduleId}")
            ->assertOk()
            ->assertDontSee('schedule_form=edit', false)
            ->assertDontSeeText(__('lf.LF_common_button_delete'));
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules/{$scheduleId}/edit")
            ->assertForbidden();
    }

    private function createCohortWithOperatingPeriod(
        int $customerId,
        string $status = 'active'
    ): int {
        $cohortId = $this->createCohort($customerId, status: $status);
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
        ]);

        return $cohortId;
    }

    private function validScheduleData(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Evening schedule',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31',
            'timezone' => 'Asia/Ho_Chi_Minh',
            'slots' => [['weekday' => 1, 'start_time' => '19:00', 'end_time' => '21:00']],
            'exclusions' => [],
        ], $overrides);
    }

    private function snapshotRows(array $queries): array
    {
        return collect($queries)->mapWithKeys(function (array $query, string $key): array {
            [$table, $column, $value] = $query;

            return [$key => (array) DB::table($table)->where($column, $value)->firstOrFail()];
        })->all();
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
        int $versionNumber = 1
    ): int {
        $now = now();
        $templateId ??= $this->createTemplate($customerId, $userId, $title);

        $versionId = DB::table('core_course_template_versions')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'version_number' => $versionNumber,
            'version_code' => 'VERSION-'.$templateId.'-'.$versionNumber,
            'is_current' => $status === 'published',
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

        $productId = DB::table('core_course_products')->where('customer_id', $customerId)
            ->where('status', 'active')->orderByDesc('id')->value('id');
        if ($productId && $status === 'published') {
            DB::table('core_course_product_items')->insert([
                'customer_id' => $customerId, 'product_id' => $productId,
                'version_id' => $versionId, 'template_id' => $templateId,
                'title_override' => null, 'short_description_override' => null,
                'sort_order' => 0, 'is_required' => true, 'status' => 'active',
                'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $versionId;
    }

    private function createCohort(
        int $customerId,
        string $name = 'TOPIK Beginner Morning',
        string $status = 'active'
    ): int {
        $now = now();
        $existingCount = DB::table('core_course_cohorts')->where('customer_id', $customerId)->count();

        return DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $customerId,
            'product_id' => DB::table('core_course_products')->orderByDesc('id')->value('id'),
            'name' => $name,
            'code' => $existingCount === 0 ? 'COH-EXISTING' : 'COH-EXISTING-'.($existingCount + 1),
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

    private function createEnrollment(int $customerId, int $studentId, int $productId, int $versionId): int
    {
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

    private function validCohortData(array $overrides = []): array
    {
        return array_merge([
            'product_id' => DB::table('core_course_products')->orderByDesc('id')->value('id'),
            'name' => 'TOPIK Beginner Morning',
            'description' => null,
            'status' => 'active',
            'capacity' => null,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'notes' => null,
        ], $overrides);
    }
}
