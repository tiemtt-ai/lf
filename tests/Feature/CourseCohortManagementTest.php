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
            ->assertRedirect()
            ->assertSessionHasNoErrors();

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

        $response = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/edit")
            ->assertOk()
            ->assertSeeText('Sửa lớp học')
            ->assertSeeText('Mã lớp học')
            ->assertSeeText('Trạng thái')
            ->assertSeeText('Sản phẩm')
            ->assertSeeText('Phiên bản nội dung')
            ->assertSee('class="admin-form-standard"', false)
            ->assertDontSee('name="code"', false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="product_id"', false)
            ->assertDontSee('name="version_id"', false)
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
            ->assertRedirect();
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
        $draft->assertSeeText(__('lf.LF_course_cohort_action_activate'))
            ->assertSeeText(__('lf.LF_course_cohort_action_edit'))
            ->assertSeeText(__('lf.LF_course_cohort_common_archive'))
            ->assertSeeText(__('lf.LF_course_cohort_lifecycle_activate_body'));
        $this->assertStringContainsString('cohort-lifecycle-dialog', $draft->getContent());
        $this->assertStringContainsString('x-bind:aria-busy="submitting"', $draft->getContent());

        $active = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$ids['active']}")->assertOk();
        $active->assertSeeText(__('lf.LF_course_cohort_action_complete'))
            ->assertSeeText(__('lf.LF_course_cohort_action_edit'))
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
        ])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('core_course_cohorts', 0);

        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-cohorts', [
            'product_id' => $productId, 'name' => 'Secure Class', 'code' => 'FORGED',
            'status' => 'active', 'description' => 'Must be ignored', 'notes' => 'Admin only',
        ])->assertRedirect();

        $this->assertDatabaseHas('core_course_cohorts', [
            'customer_id' => $customerId, 'product_id' => $productId, 'version_id' => $versionId,
            'name' => 'Secure Class', 'code' => 'COH-20260704-001', 'status' => 'draft',
            'description' => null, 'notes' => 'Admin only',
        ]);
        $this->assertDatabaseMissing('core_course_cohorts', ['code' => 'FORGED']);
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
            'start_date' => null,
            'end_date' => null,
            'notes' => null,
        ], $overrides);
    }
}
