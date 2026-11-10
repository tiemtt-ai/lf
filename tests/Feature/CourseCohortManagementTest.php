<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CourseCohortContentTree;
use App\Services\CourseCohortLifecycleService;
use App\Services\LiveClassCohortSessionReadModel;
use App\Services\LiveClassScheduleSessionGenerator;
use App\Services\LiveClassWhiteboardService;
use App\Support\SimpleXlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
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
            ->assertSee('lf-admin-ui lf-admin-ui--list', false)
            ->assertSee('class="ui-status ui-status--', false)
            ->assertSeeText(__('lf.LF_course_cohort_common_version'));
    }

    public function test_cohort_index_uses_compact_filters_responsive_rows_and_business_empty_state(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-cohorts')
            ->assertOk()
            ->assertSee('data-ui-live-filter', false)
            ->assertSeeText(__('lf.LF_course_cohort_index_heading'))
            ->assertSeeText('Chưa có lớp học nào.')
            ->assertSeeText('Tạo lớp học đầu tiên')
            ->assertDontSeeText('Chưa có Cohort nào.');

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

    public function test_only_an_empty_draft_cohort_can_be_deleted(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $emptyDraft = $this->createCohort($customerId, 'Empty Draft', 'draft');
        $activeClass = $this->createCohort($customerId, 'Running Class', 'active');
        $usedDraft = $this->createCohort($customerId, 'Used Draft', 'draft');
        $otherTenantDraft = $this->createCohort($otherCustomerId, 'Other Tenant Draft', 'draft');
        DB::table('core_course_cohort_teachers')->insert([
            'customer_id' => $customerId, 'cohort_id' => $usedDraft, 'teacher_id' => $teacher->id,
            'role' => 'teacher', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $base = 'https://tenant-a.localhost/admin/course-cohorts';

        $selectable = fn (int $id): string => 'data-ui-bulk-item value="'.$id.'"';
        $this->actingAs($admin)->get($base)->assertOk()
            ->assertSee('data-ui-bulk-delete', false)
            ->assertSee($selectable($emptyDraft), false)
            ->assertDontSee($selectable($activeClass), false)
            ->assertDontSee($selectable($usedDraft), false)
            ->assertSee('data-tooltip="'.e(__('lf.LF_course_cohort_delete_blocked_status')).'"', false)
            ->assertSee('data-tooltip="'.e(__('lf.LF_course_cohort_delete_blocked_records')).'"', false);

        $this->actingAs($admin)->deleteJson($base, ['ids' => [$emptyDraft, $activeClass]])
            ->assertStatus(409)->assertJson(['message' => __('lf.LF_course_cohort_delete_blocked_status')]);
        $this->actingAs($admin)->deleteJson($base, ['ids' => [$emptyDraft, $usedDraft]])
            ->assertStatus(409)->assertJson(['message' => __('lf.LF_course_cohort_delete_blocked_records')]);
        $this->actingAs($admin)->deleteJson($base, ['ids' => [$emptyDraft, $otherTenantDraft]])->assertNotFound();
        $this->actingAs($teacher)->deleteJson($base, ['ids' => [$emptyDraft]])->assertForbidden();
        $this->assertSame(4, DB::table('core_course_cohorts')->count());

        $this->actingAs($admin)->deleteJson($base, ['ids' => [$emptyDraft]])
            ->assertOk()->assertJsonStructure(['message']);
        $this->assertDatabaseMissing('core_course_cohorts', ['id' => $emptyDraft]);
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $usedDraft]);
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
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?edit=1")
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

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/edit")
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?edit=1");

        $response = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?edit=1")
            ->assertOk()
            ->assertSeeText('Sửa lớp học')
            ->assertSeeText('Sản phẩm')
            ->assertSeeText('Phiên bản nội dung')
            ->assertSeeText(__('lf.LF_course_cohort_product_registration_window'))
            ->assertSeeText('01/08/2026 09:00')
            ->assertSeeText('15/08/2026 18:00')
            ->assertSeeText(__('lf.LF_course_cohort_product_registration_help'))
            ->assertSee('id="cohort-edit-title"', false)
            ->assertSee('class="course-template-tab is-active"', false)
            ->assertSee('course-cohort-detail-tabs-help', false)
            ->assertViewHas('cohortTabs', fn (array $tabs): bool => collect($tabs)
                ->whereIn('key', ['attendance', 'recordings'])
                ->count() === 2 && collect($tabs)
                ->whereIn('key', ['attendance', 'recordings'])
                ->every(fn (array $tab): bool => ! $tab['accessible']))
            ->assertDontSee('🔒', false)
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
            strpos($html, 'id="cohort-edit-name"'),
            strpos($html, 'id="cohort-edit-capacity"'),
            strpos($html, 'id="cohort-edit-start_date"'),
            strpos($html, 'id="cohort-edit-end_date"'),
            strpos($html, 'id="cohort-edit-notes"'),
        ];
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);

        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['status' => 'completed']);
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?edit=1")
            ->assertOk()
            ->assertDontSee('id="cohort-edit-title"', false)
            ->assertDontSeeText(__('lf.LF_common_button_save_changes'));
    }

    public function test_edit_action_lives_on_the_overview_tab_only(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohort($customerId);
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['status' => 'active']);
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}";

        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('id="cohort-edit-title"', false)
            ->assertSeeText(__('lf.LF_course_cohort_action_complete'));

        $this->actingAs($admin)->get($url.'?tab=teachers')->assertOk()
            ->assertDontSee('id="cohort-edit-title"', false)
            ->assertSeeText(__('lf.LF_course_cohort_action_complete'));
    }

    public function test_schedule_request_reports_the_row_and_blocks_new_schedules_on_a_self_paced_class(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohort($customerId);
        $versionId = $this->createVersion($customerId, $admin->id);
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'status' => 'active', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'version_id' => $versionId,
        ]);
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules";
        $slots = [
            ['weekday' => 2, 'start_time' => '08:00', 'end_time' => '09:00'],
            ['weekday' => 3, 'start_time' => '08:00', 'end_time' => '09:00'],
            ['weekday' => 3, 'start_time' => '11:00', 'end_time' => '10:00'],
        ];

        // Row 2 is wrong, whatever its position among the slots of that weekday.
        $this->actingAs($admin)->from($url)->post($url, $this->validScheduleData(['slots' => $slots]))
            ->assertSessionHasErrors('slots.2.end_time')
            ->assertSessionDoesntHaveErrors('slots.1.end_time');

        // The live preview does not need a name yet.
        $this->actingAs($admin)->postJson($url.'/preview', array_diff_key(
            $this->validScheduleData(),
            ['name' => true]
        ))->assertOk();

        DB::table('core_course_template_versions')->where('id', $versionId)->update(['course_type_snapshot' => 'self_paced']);

        $this->actingAs($admin)->from($url)->post($url, $this->validScheduleData())->assertSessionHasErrors('starts_on');
        $this->assertSame(0, DB::table('core_liveclass_schedules')->where('cohort_id', $cohortId)->count());

        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules")->assertOk()
            ->assertSeeText(__('lf.LF_course_type_cohort_legacy_self_paced'))
            ->assertDontSee('schedule-create-open', false);
    }

    public function test_schedule_detail_opens_on_a_read_only_class(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohort($customerId);
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'status' => 'active', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
        ]);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules", $this->validScheduleData())
            ->assertSessionHasNoErrors();
        $scheduleId = (int) DB::table('core_liveclass_schedules')->where('cohort_id', $cohortId)->value('id');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['status' => 'completed']);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=view&schedule_id={$scheduleId}")
            ->assertOk()
            ->assertSee('ui-modal ui-cohort-schedule-detail', false)
            ->assertDontSee('schedule_form=edit', false);
    }

    public function test_create_and_detail_link_to_the_content_version(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Linked Product', 'linked-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $templateId = DB::table('core_course_template_versions')->where('id', $versionId)->value('template_id');
        DB::table('core_course_product_items')->where('product_id', $productId)->update(['version_id' => $versionId, 'template_id' => $templateId, 'status' => 'active']);
        $cohortId = $this->createCohort($customerId);
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['product_id' => $productId, 'version_id' => $versionId, 'status' => 'draft']);
        $versionPath = "/admin/course-templates/{$templateId}/versions/{$versionId}\"";

        $create = $this->actingAs($admin)->get('https://tenant-a.localhost/admin/course-cohorts/create')->assertOk();
        $this->assertTrue(str_contains($create->getContent(), 'selectedVersion.viewUrl'));
        $this->assertSame($versionId, (int) $create->viewData('products')->firstWhere('id', $productId)->version_id);
        $this->assertSame((int) $templateId, (int) $create->viewData('products')->firstWhere('id', $productId)->template_id);

        // Once on the overview facts and once in the edit popup.
        $detail = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}")->assertOk();
        $this->assertSame(2, substr_count($detail->getContent(), $versionPath));
    }

    public function test_product_registration_is_shown_apart_from_the_class_period(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Window Product', 'window-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        $cohortId = $this->createCohort($customerId);
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['product_id' => $productId, 'version_id' => $versionId]);
        DB::table('core_course_products')->where('id', $productId)->update([
            'registration_starts_at' => '2026-08-01 09:00:00', 'registration_ends_at' => '2026-08-15 18:00:00',
        ]);

        $html = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}")->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_product_registration_window'))
            ->assertSeeText(__('lf.LF_course_cohort_product_registration_edit'))
            ->assertSee("/admin/course-products/{$productId}/edit", false)
            ->getContent();
        // Inside the class information card, before the operating period card starts.
        $this->assertLessThan(
            strpos($html, 'id="cohort-show-dates"'),
            strpos($html, 'id="cohort-show-product-registration-window')
        );
    }

    public function test_overview_note_follows_the_class_status(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohort($customerId);
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}";
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['status' => 'draft']);

        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_tab_overview_detail_note'))
            ->assertDontSeeText(__('lf.LF_course_cohort_tab_overview_running_note'));

        foreach (['active', 'completed', 'archived'] as $status) {
            DB::table('core_course_cohorts')->where('id', $cohortId)->update(['status' => $status]);
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertSeeText(__('lf.LF_course_cohort_tab_overview_running_note'))
                ->assertDontSeeText(__('lf.LF_course_cohort_tab_overview_detail_note'));
        }
    }

    public function test_complete_confirmation_warns_about_what_is_left_without_blocking(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohort($customerId);
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'status' => 'active', 'start_date' => now()->toDateString(), 'end_date' => now()->addDays(10)->toDateString(),
        ]);
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}";

        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_lifecycle_complete_body'))
            ->assertSeeText(__('lf.LF_course_cohort_complete_warning_end_date', ['date' => now()->addDays(10)->format('d/m/Y')]));

        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['end_date' => now()->subDay()->toDateString()]);
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertDontSee('ui-cohort-complete-warnings', false);

        $this->actingAs($admin)->post("{$url}/complete")->assertRedirect($url);
        $this->assertSame('completed', DB::table('core_course_cohorts')->where('id', $cohortId)->value('status'));
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

        // Ba model Schedule từng tồn tại nhưng không có caller nào: module dùng
        // DB::table() xuyên suốt. Chúng là kiến trúc chết, và vì dùng $guarded
        // (blacklist) nên còn là rủi ro mass-assignment tiềm ẩn nếu ai đó sau
        // này vô tình dùng tới.
        foreach (['LiveClassSchedule', 'LiveClassScheduleSlot', 'LiveClassScheduleExclusion'] as $model) {
            $this->assertFileDoesNotExist(app_path("Models/{$model}.php"));
        }
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
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}")
            ->assertOk()
            ->assertSee('course-cohort-activation-action', false)
            ->assertSee('id="cohort-activate-requirements"', false)
            ->assertSee('aria-describedby="cohort-activate-requirements"', false)
            ->assertSee('class="ui-cohort-alert"', false);

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
        $unassignedSessionId = DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId,
            'template_version_id' => $versionId, 'session_type' => 'operational',
            'version_lesson_id' => null, 'version_activity_id' => null,
            'title' => 'Unassigned setup session', 'session_no' => 1,
            'delivery_mode' => 'online', 'scheduled_start_at' => '2026-08-01 09:00:00',
            'scheduled_end_at' => '2026-08-01 10:00:00', 'timezone' => config('app.timezone'),
            'status' => 'scheduled', 'created_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/activate")
            ->assertSessionHasErrors([
                'lifecycle' => __('lf.LF_course_cohort_activation_sessions_unassigned', ['count' => 1]),
            ]);
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_activation_sessions_unassigned', ['count' => 1]));
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $cohortId, 'status' => 'draft']);

        $sessionTeacher = $this->createUser($customerId, 'teacher');
        DB::table('core_liveclass_session_teachers')->insert([
            'customer_id' => $customerId, 'session_id' => $unassignedSessionId,
            'teacher_id' => $sessionTeacher->id, 'role' => 'teacher',
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
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
        foreach (['draft', 'active', 'completed', 'archived'] as $status) {
            $index->assertSee(route('admin.course-cohorts.show', $ids[$status]), false);
            $index->assertDontSee(route('admin.course-cohorts.edit', $ids[$status]), false);
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
        $this->assertStringContainsString('ui-enroll-head__actions', $draft->getContent());
        $this->assertStringContainsString('class="ui-modal"', $draft->getContent());
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
            ->assertSee('class="ui-cohort-form"', false)
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
            strpos($html, 'id="cohort-create-content-version"'),
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

    public function test_a_class_only_runs_scheduled_or_blended_courses(): void
    {
        // ADR-0022: a self-paced course needs no class; a legacy class bound to one cannot take new members.
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Self Study Product', 'self-study-product');
        $versionId = $this->createVersion($customerId, $admin->id);
        DB::table('core_course_template_versions')->where('id', $versionId)->update(['course_type_snapshot' => 'self_paced']);
        $payload = ['product_id' => $productId, 'name' => 'Self Class', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31'];

        $this->actingAs($admin)->get('https://tenant-a.localhost/admin/course-cohorts/create')
            ->assertOk()->assertDontSee('Self Study Product');
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-cohorts', $payload)
            ->assertSessionHasErrors(['product_id' => __('lf.LF_course_type_cohort_product_not_allowed')]);
        $this->assertDatabaseCount('core_course_cohorts', 0);

        DB::table('core_course_template_versions')->where('id', $versionId)->update(['course_type_snapshot' => 'blended']);
        $this->actingAs($admin)->get('https://tenant-a.localhost/admin/course-cohorts/create')
            ->assertOk()->assertSee('Self Study Product · '.DB::table('core_course_products')->where('id', $productId)->value('product_code').' · Theo lớp', false);
        $this->actingAs($admin)->post('https://tenant-a.localhost/admin/course-cohorts', $payload)->assertRedirect();
        $cohortId = (int) DB::table('core_course_cohorts')->where('name', 'Self Class')->value('id');

        // The same class bound to a self-paced Version (legacy data) cannot be activated.
        DB::table('core_course_template_versions')->where('id', $versionId)->update(['course_type_snapshot' => 'self_paced']);
        $issues = app(CourseCohortLifecycleService::class)->activationIssues($customerId, $cohortId);
        $this->assertContains(__('lf.LF_course_type_cohort_legacy_self_paced'), $issues);
    }

    public function test_cohort_operational_tabs_manage_session_evidence(): void
    {
        // Điểm danh và Bản ghi hiện bị khóa bằng cờ cấu hình vì chưa hoàn
        // thiện (config/liveclass.php). Test này bảo vệ hành vi của tính năng
        // khi bật, nên nó bật cờ thay vì hạ thấp kỳ vọng — lớp bảo vệ vẫn còn
        // nguyên cho lúc hai tab được mở lại.
        config(['liveclass.attendance_enabled' => true, 'liveclass.recording_enabled' => true]);

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

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")
            ->assertOk()
            ->assertSeeText('Live Lesson 1')
            ->assertSeeText(__('lf.LF_course_cohort_session_tree_unscheduled'))
            ->assertDontSeeText(__('lf.LF_course_cohort_session_manual_open'));

        $detail = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=teachers")
            ->assertOk();
        foreach (['overview', 'students', 'teachers', 'schedules', 'sessions'] as $tab) {
            $detail->assertSee(__('lf.LF_course_cohort_tab_'.$tab));
        }
        $detail->assertSee('min="2026-08-01"', false)
            ->assertSee('max="2026-08-14"', false)
            ->assertSee('x-show="role !== \'primary_teacher\'"', false)
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

        // Choosing a second primary demotes the current one, so the form says so and does not default to it.
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=teachers")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_teacher_primary_replace_notice', ['name' => $teacher->name]))
            ->assertSee('defaultRole: \'teacher\'', false);

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
            ->assertSee('x-model="startsAt"', false)
            ->assertSee('x-model="endsAt"', false);
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
        // The name comes from the Template and is not edited on the class.
        $this->assertDatabaseMissing('core_liveclass_sessions', ['id' => $sessionId, 'title' => 'Session 1 updated']);
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'id' => $sessionId,
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
            ->assertDontSee('admin-action-menu__dots', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_current_schedule'))
            ->assertSeeText(__('lf.LF_course_cohort_session_change_reason_help'))
            ->assertSeeText(__('lf.LF_course_cohort_session_edit_scope_help'))
            ->assertSee('relationLabels[detailSession?.schedule_relation]', false)
            ->assertSee('relationLabels[rescheduleSession?.schedule_relation]', false)
            ->assertSee('formatSessionDateTime(detailSession?.scheduled_start_at)', false)
            ->assertSee('activity_meeting_url', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_join'))
            ->assertSee('x-model="rescheduleStart"', false)
            ->assertSee('x-model="rescheduleEnd"', false);

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
        // Điểm danh và Bản ghi hiện bị khóa bằng cờ cấu hình vì chưa hoàn
        // thiện (config/liveclass.php). Test này bảo vệ hành vi của tính năng
        // khi bật, nên nó bật cờ thay vì hạ thấp kỳ vọng — lớp bảo vệ vẫn còn
        // nguyên cho lúc hai tab được mở lại.
        config(['liveclass.attendance_enabled' => true, 'liveclass.recording_enabled' => true]);

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
            // Every activity shows in the class's programme, but only live ones can carry a Session.
            ->assertSee('data-version-activity-id="'.$videoActivityId.'"', false)
            ->assertSee('if (this.submitting)', false)
            ->assertSee('titleDirty', false)
            ->assertDontSee('if (matches.length === 1)', false)
            ->assertDontSee('`${lesson.title_snapshot} – ${activity.title_snapshot}`', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_curriculum_meeting_help'))
            ->assertSee('copyCurriculumMeetingLink()', false)
            ->assertSee('placeholder="'.__('lf.LF_course_cohort_session_name_placeholder').'"', false)
            ->assertSee('placeholder="'.__('lf.LF_course_cohort_session_provider_placeholder').'"', false)
            ->assertSee('placeholder="'.__('lf.LF_course_cohort_session_meeting_url_placeholder').'"', false)
            ->assertSee('placeholder="'.__('lf.LF_course_cohort_session_room_placeholder').'"', false)
            ->assertSee('placeholder="'.__('lf.LF_course_cohort_session_address_placeholder').'"', false)
            ->assertSee('id="session_teachers" name="teacher_ids[]" multiple data-ui-kit-select', false)
            ->assertSee('x-bind:disabled="String('.$lessonId.') !== String(lessonId)"', false)
            ->assertDontSee('<template x-for="activity in availableActivities()"', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_operational_meeting_help'));
        $this->assertNotContains((int) $videoActivityId, $tab->viewData('versionActivities')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $tab->assertDontSeeText(__('lf.LF_course_cohort_session_manual_open'))
            ->assertDontSee("sourceMode === 'schedule'", false);

        $payload = [
            'title' => 'Locked Lesson – Conversation Practice',
            'session_type' => 'curriculum',
            'version_lesson_id' => $lessonId,
            'version_activity_id' => $activityId,
            'delivery_mode' => 'online',
            'scheduled_start_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'scheduled_end_at' => now()->addDays(2)->addHour()->format('Y-m-d H:i:s'),
            'teacher_ids' => [$teacher->id, $teacher->id],
            'online_provider' => 'zoom',
            'meeting_url' => 'https://example.com/live',
        ];

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", $payload)
            ->assertSessionHasNoErrors();
        // The Session owns its join URL; the provider follows that URL, not client input.
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'title' => 'Locked Lesson – Conversation Practice',
            'online_provider' => 'example.com',
            'meeting_url_snapshot' => 'https://example.com/live',
        ]);
        $firstSessionId = (int) DB::table('core_liveclass_sessions')
            ->where('title', 'Locked Lesson – Conversation Practice')->value('id');
        $this->assertSame(1, DB::table('core_liveclass_session_teachers')
            ->where('customer_id', $customerId)->where('session_id', $firstSessionId)->count());
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", array_merge($payload, [
            'title' => 'Overlapping off-schedule session',
            'session_type' => 'operational',
            'version_lesson_id' => null,
            'version_activity_id' => null,
            'scheduled_start_at' => now()->addDays(2)->addMinutes(30)->format('Y-m-d H:i:s'),
            'scheduled_end_at' => now()->addDays(2)->addHours(2)->format('Y-m-d H:i:s'),
        ]))->assertSessionHasErrors([
            'scheduled_start_at' => __('lf.LF_course_cohort_session_schedule_overlap'),
        ]);
        $this->assertDatabaseMissing('core_liveclass_sessions', ['title' => 'Overlapping off-schedule session']);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", array_merge($payload, [
            'title' => 'Second realization',
            'meeting_url' => null,
            'scheduled_start_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'scheduled_end_at' => now()->addDays(3)->addHour()->format('Y-m-d H:i:s'),
        ]))->assertSessionHasNoErrors();
        // Without a Session URL the Activity link is used as the default.
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'title' => 'Second realization',
            'online_provider' => 'Google Meet',
            'meeting_url_snapshot' => 'https://meet.google.com/source-room',
        ]);
        $this->assertSame(2, DB::table('core_liveclass_sessions')->where('version_activity_id', $activityId)->count());
        $secondSessionId = (int) DB::table('core_liveclass_sessions')->where('title', 'Second realization')->value('id');
        $rescheduleResponse = $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$secondSessionId}/schedule", [
                'form_context' => 'reschedule',
                'reschedule_session_id' => $secondSessionId,
                'scheduled_start_at' => now()->addDays(2)->addMinutes(45)->format('Y-m-d H:i:s'),
                'scheduled_end_at' => now()->addDays(2)->addHours(2)->format('Y-m-d H:i:s'),
                'reason' => 'Kiểm tra xung đột lịch',
            ]);
        $rescheduleResponse->assertSessionHasErrors([
            'scheduled_start_at' => __('lf.LF_course_cohort_session_schedule_overlap'),
        ])->assertSessionHasInput('reason', 'Kiểm tra xung đột lịch');
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")
            ->assertOk()
            ->assertSee('x-show="showRescheduleErrors && rescheduleStartError"', false)
            ->assertSee('rescheduleReason:', false)
            ->assertSee('rescheduleStartError:', false);
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'id' => $secondSessionId,
            'scheduled_start_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ]);

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
            ->assertDontSee('id="session-batch-form"', false);
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
        $sessionCount = DB::table('core_liveclass_sessions')->count();
        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", array_merge($originPayload, [
                'title' => 'Duplicate occurrence',
            ]))->assertSessionHasErrors('source_local_date');
        $this->assertSame($sessionCount, DB::table('core_liveclass_sessions')->count());
        $this->assertDatabaseCount('core_liveclass_session_schedule_origins', 1);

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
            'scheduled_start_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'scheduled_end_at' => now()->addDays(7)->addHour()->format('Y-m-d H:i:s'),
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
        // A session on the programme stays on it: the form no longer offers to detach it.
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'id' => $curriculumId, 'title' => 'Second realization', 'session_type' => 'curriculum',
        ]);
    }

    public function test_create_and_draft_detail_use_canonical_accessibility_aware_tabs(): void
    {
        // Điểm danh và Bản ghi hiện bị khóa bằng cờ cấu hình vì chưa hoàn
        // thiện (config/liveclass.php). Test này bảo vệ hành vi của tính năng
        // khi bật, nên nó bật cờ thay vì hạ thấp kỳ vọng — lớp bảo vệ vẫn còn
        // nguyên cho lúc hai tab được mở lại.
        config(['liveclass.attendance_enabled' => true, 'liveclass.recording_enabled' => true]);

        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Tab Product', 'tab-product');
        $versionId = $this->createVersion($customerId, $admin->id);

        $create = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-cohorts/create')->assertOk();
        foreach (['overview', 'students', 'teachers', 'schedules', 'sessions'] as $tab) {
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
        $detail->assertDontSeeText(__('lf.LF_course_cohort_tab_attendance'))
            ->assertDontSeeText(__('lf.LF_course_cohort_tab_recordings'))
            ->assertSeeText(__('lf.LF_course_cohort_tab_students_detail_note'))
            ->assertSeeText(__('lf.LF_course_cohort_tab_overview_detail_note'))
            ->assertDontSeeText(__('lf.LF_course_cohort_tab_overview_note'));

        $students = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=students")
            ->assertOk();
        $students->assertSee(route('admin.course-cohorts.students.sync', $cohortId), false)
            ->assertSee('course-cohort-student-inline-form', false)
            ->assertSee('ui-cohort-roster-table', false)
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
        // Điểm danh và Bản ghi hiện bị khóa bằng cờ cấu hình vì chưa hoàn
        // thiện (config/liveclass.php). Test này bảo vệ hành vi của tính năng
        // khi bật, nên nó bật cờ thay vì hạ thấp kỳ vọng — lớp bảo vệ vẫn còn
        // nguyên cho lúc hai tab được mở lại.
        config(['liveclass.attendance_enabled' => true, 'liveclass.recording_enabled' => true]);

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
            ->assertSee('ui-modal ui-cohort-schedule-detail', false)
            ->assertSee('ui-cohort-col-slots', false);
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
            ->assertSee('exclusionHitsLabel(exclusion.excluded_on)', false)
            ->assertSee('previewSummary()', false)
            ->assertSee('x-on:click="addSlot(2)"', false)
            ->assertSeeText(__('lf.LF_course_cohort_schedule_preview_empty'))
            ->assertSee('placeholder="'.__('lf.LF_course_cohort_schedule_name_placeholder').'"', false)
            ->assertSee('placeholder="'.__('lf.LF_course_cohort_schedule_reason_placeholder').'"', false)
            ->assertSee('ui-cohort-col-slots', false)
            ->assertSee('id="schedule_starts_on"', false)
            ->assertSee('data-ui-kit-date', false)
            ->assertSee('min="2026-08-01"', false)
            ->assertSee('max="2026-08-31"', false)
            ->assertSee('schedule-create-open', false)
            ->assertDontSee('id="schedule_starts_on" disabled', false)
            ->assertDontSee('id="schedule_starts_on" readonly', false);
        $createResponse
            ->assertSee('this.slots.push', false)
            ->assertSee('this.slots.splice(this.slots.indexOf(slot), 1)', false)
            ->assertSee('toggleDay(2, $el)', false)
            ->assertSee('maxSlots: 50', false)
            ->assertSee('maxExclusions: 366', false)
            ->assertSee('this.exclusions.push', false);

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
            ->assertSee('class="ui-status ui-status--', false)
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

    public function test_cohort_operating_period_cannot_shrink_past_a_session_or_be_cleared_under_one(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId, status: 'draft');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'product_id' => $this->createProduct($customerId, 'Period Product', 'period-product'),
            'version_id' => $this->createVersion($customerId, $admin->id, 'Period Version'),
        ]);
        $cohortUrl = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}";
        $session = fn (string $date, string $status) => DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'session_type' => 'operational',
            'template_version_id' => DB::table('core_course_cohorts')->where('id', $cohortId)->value('version_id'),
            'title' => 'Moved session', 'session_no' => $status === 'cancelled' ? 2 : 1, 'delivery_mode' => 'online',
            'scheduled_start_at' => "{$date} 16:00:00", 'scheduled_end_at' => "{$date} 17:30:00",
            'timezone' => 'Asia/Ho_Chi_Minh', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $session('2026-08-20', 'scheduled');
        // A cancelled Session no longer needs a place in the period.
        $session('2026-08-30', 'cancelled');
        $outside = __('lf.LF_course_cohort_validation_session_outside_operation', ['title' => 'Moved session', 'at' => '20/08/2026 16:00']);

        $this->actingAs($admin)->put($cohortUrl, $this->validCohortData(['end_date' => '2026-08-10']))
            ->assertSessionHasErrors(['start_date' => $outside]);
        $this->actingAs($admin)->put($cohortUrl, $this->validCohortData(['start_date' => '2026-08-21']))
            ->assertSessionHasErrors(['start_date' => $outside]);
        $this->actingAs($admin)->put($cohortUrl, $this->validCohortData(['start_date' => null, 'end_date' => null]))
            ->assertSessionHasErrors('start_date');
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $cohortId, 'start_date' => '2026-08-01', 'end_date' => '2026-08-31']);

        $this->actingAs($admin)->put($cohortUrl, $this->validCohortData(['start_date' => '2026-08-20', 'end_date' => '2026-08-20']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $cohortId, 'start_date' => '2026-08-20', 'end_date' => '2026-08-20']);
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

    public function test_sessions_tab_of_a_legacy_cohort_offers_no_scheduling_window(): void
    {
        // Lớp legacy thiếu thời gian vận hành: view từng tự parse NULL thành
        // now() và dựng ra một khoảng lịch trông hợp lệ. Giờ khoảng đó là NULL
        // và backend từ chối mọi buổi học.
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Legacy Product', 'legacy-product');
        $versionId = $this->createVersion($customerId, $admin->id, 'Legacy Version');
        $cohortId = $this->createCohort($customerId, status: 'active');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'product_id' => $productId, 'version_id' => $versionId,
            'start_date' => null, 'end_date' => null,
        ]);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")
            ->assertOk()
            ->assertSee("scheduleMin: ''", false)
            ->assertSee("scheduleMax: ''", false);

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions",
            $this->operationalPayload('Impossible class', '2026-08-10 19:00:00', '2026-08-10 21:00:00')
        )->assertSessionHasErrors([
            'scheduled_start_at' => __('lf.LF_course_cohort_session_schedule_cohort_period_required'),
        ]);
        $this->assertDatabaseCount('core_liveclass_sessions', 0);
    }

    public function test_sessions_tab_does_not_scale_its_query_count_with_schedule_count(): void
    {
        // Tab Buổi học từng chạy một truy vấn Slot và một truy vấn Exclusion cho
        // MỖI Schedule, và nạp lại toàn bộ danh sách Session hai lần trong cùng
        // một request. Test đếm truy vấn để hai vấn đề đó không quay lại.
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        foreach (range(1, 4) as $index) {
            $scheduleId = DB::table('core_liveclass_schedules')->insertGetId([
                'customer_id' => $customerId, 'cohort_id' => $cohortId,
                'name' => "Schedule {$index}", 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31',
                'timezone' => 'Asia/Ho_Chi_Minh', 'created_by' => $admin->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('core_liveclass_schedule_slots')->insert([
                'customer_id' => $customerId, 'schedule_id' => $scheduleId,
                'weekday' => $index, 'start_time' => '19:00:00', 'end_time' => '21:00:00',
                'sort_order' => 1, 'created_by' => $admin->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->operationalSession($customerId, $cohortId, $admin, '2026-08-11 08:00:00', '2026-08-11 09:00:00');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")
            ->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $count = fn (string $table): int => $queries
            ->filter(fn (string $sql): bool => str_contains($sql, $table))->count();

        $this->assertSame(1, $count('core_liveclass_schedule_slots'),
            'Slot phải được nạp gộp một lần cho mọi Schedule, không phải một truy vấn mỗi Schedule.');
        $this->assertSame(1, $count('core_liveclass_schedule_exclusions'),
            'Exclusion phải được nạp gộp một lần cho mọi Schedule.');
        $this->assertSame(1, $queries->filter(
            fn (string $sql): bool => str_contains($sql, 'from "core_liveclass_sessions" as "sessions"')
        )->count(), 'Danh sách Session chỉ được nạp một lần trong mỗi request.');
    }

    public function test_session_lifecycle_transitions_follow_the_canonical_map(): void
    {
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        $sessionId = $this->operationalSession($customerId, $cohortId, $admin, '2026-08-10 19:00:00', '2026-08-10 21:00:00');
        $base = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}";

        // UI chỉ chào đúng các chuyển tiếp hợp lệ của trạng thái hiện tại.
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")
            ->assertOk()
            ->assertSee(route('admin.course-cohorts.sessions.start', [$cohortId, $sessionId]), false)
            ->assertSee(route('admin.course-cohorts.sessions.cancel', [$cohortId, $sessionId]), false)
            ->assertSee(route('admin.course-cohorts.sessions.no-show', [$cohortId, $sessionId]), false)
            ->assertDontSee(route('admin.course-cohorts.sessions.complete', [$cohortId, $sessionId]), false);

        // scheduled → completed không nằm trong map: phải đi qua live.
        $this->actingAs($admin)->post("{$base}/complete")->assertSessionHasErrors('lifecycle');
        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $sessionId, 'status' => 'scheduled']);

        $this->actingAs($admin)->post("{$base}/start")->assertRedirect();
        $session = DB::table('core_liveclass_sessions')->find($sessionId);
        $this->assertSame('live', $session->status);
        $this->assertNotNull($session->actual_start_at, 'Chuyển sang live phải ghi thời điểm bắt đầu thật.');
        $this->assertNull($session->actual_end_at);

        $this->actingAs($admin)->post("{$base}/complete")->assertRedirect();
        $session = DB::table('core_liveclass_sessions')->find($sessionId);
        $this->assertSame('completed', $session->status);
        $this->assertNotNull($session->actual_end_at);

        // Terminal: phát lại và mọi chuyển tiếp tiếp theo đều fail-closed.
        foreach (['start', 'complete', 'cancel', 'no-show'] as $action) {
            $this->actingAs($admin)->post("{$base}/{$action}")->assertSessionHasErrors('lifecycle');
        }
        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $sessionId, 'status' => 'completed']);

        // Trạng thái cuối thì UI không còn chào hành động lifecycle nào.
        $detail = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")->assertOk();
        foreach (['start', 'complete', 'cancel', 'no-show'] as $action) {
            $detail->assertDontSee(route('admin.course-cohorts.sessions.'.$action, [$cohortId, $sessionId]), false);
        }
    }

    public function test_cancelling_a_session_records_the_reason_and_releases_its_time_range(): void
    {
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        $sessionId = $this->operationalSession($customerId, $cohortId, $admin, '2026-08-10 19:00:00', '2026-08-10 21:00:00');

        // Khung giờ đang bị giữ chỗ.
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions",
            $this->operationalPayload('Clashing class', '2026-08-10 19:30:00', '2026-08-10 20:30:00')
        )->assertSessionHasErrors([
            'scheduled_start_at' => __('lf.LF_course_cohort_session_schedule_overlap'),
        ]);

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/cancel",
            ['reason' => 'Giáo viên ốm']
        )->assertRedirect();
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'id' => $sessionId, 'status' => 'cancelled', 'cancellation_reason' => 'Giáo viên ốm',
        ]);

        // Buổi đã hủy không còn giữ chỗ, nhưng vẫn được lưu lại.
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions",
            $this->operationalPayload('Make-up class', '2026-08-10 19:30:00', '2026-08-10 20:30:00')
        )->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $sessionId]);
        $this->assertDatabaseHas('core_liveclass_sessions', ['title' => 'Make-up class', 'status' => 'scheduled']);
    }

    public function test_session_lifecycle_requires_an_active_cohort_and_admin_role(): void
    {
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        $sessionId = $this->operationalSession($customerId, $cohortId, $admin, '2026-08-10 19:00:00', '2026-08-10 21:00:00');
        $base = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}";

        foreach (['teacher', 'student'] as $role) {
            $this->actingAs($this->createUser($customerId, $role))
                ->post("{$base}/start")->assertForbidden();
        }

        // Cohort quay về draft: Session là dữ liệu setup, không phải vận hành.
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['status' => 'draft']);
        $this->actingAs($admin)->post("{$base}/start")->assertStatus(422);
        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $sessionId, 'status' => 'scheduled']);

        // Tenant khác không thấy Session này.
        $otherCustomerId = $this->createTenant('tenant-b');
        $this->actingAs($this->createUser($otherCustomerId, 'customer_admin'))
            ->post("https://tenant-b.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/start")
            ->assertNotFound();
    }

    public function test_cancelled_sessions_no_longer_block_cohort_activation(): void
    {
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        $sessionId = $this->operationalSession($customerId, $cohortId, $admin, '2026-08-10 19:00:00', '2026-08-10 21:00:00');

        // Buổi chưa có giáo viên là điều kiện chặn activation.
        $this->assertContains(
            __('lf.LF_course_cohort_activation_sessions_unassigned', ['count' => 1]),
            app(CourseCohortLifecycleService::class)->activationIssues($customerId, $cohortId)
        );

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/cancel"
        )->assertRedirect();

        $this->assertSame(
            [],
            app(CourseCohortLifecycleService::class)->activationIssues($customerId, $cohortId),
            'Buổi đã hủy không còn là điều kiện chặn activation.'
        );
    }

    /**
     * @return array{0: int, 1: User, 2: int}
     */
    private function lifecycleCohort(): array
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Lifecycle Product', 'lifecycle-product');
        $versionId = $this->createVersion($customerId, $admin->id, 'Lifecycle Version');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId);
        DB::table('core_course_cohorts')->where('id', $cohortId)
            ->update(['product_id' => $productId, 'version_id' => $versionId]);

        return [$customerId, $admin, $cohortId];
    }

    public function test_sessions_tab_shows_the_locked_version_tree_with_each_session_on_its_activity(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $versionId = $this->createVersion($customerId, $admin->id, 'Locked Version');
        $otherVersionId = $this->createVersion($customerId, $admin->id, 'Other Version');
        $cohortId = $this->createCohort($customerId, status: 'draft');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'version_id' => $versionId,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $makeSection = fn (string $title, ?int $parent, int $order): int => DB::table('core_course_template_version_sections')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId,
            'source_template_section_id' => 40000 + $order, 'parent_version_section_id' => $parent,
            'allows_lessons' => true, 'title_snapshot' => $title, 'display_order' => $order,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $makeLesson = fn (int $version, string $title, ?int $section, int $order): int => DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $version, 'version_section_id' => $section,
            'source_template_lesson_id' => 41000 + $order + $version, 'title_snapshot' => $title,
            'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $makeActivity = fn (int $version, int $lesson, string $type, string $title, int $order): int => DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $version, 'version_lesson_id' => $lesson,
            'source_template_activity_id' => 42000 + $order + $version, 'title_snapshot' => $title, 'activity_type' => $type,
            'sort_order' => $order, 'completion_rule' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $chapter = $makeSection('Chapter One', null, 1);
        $topic = $makeSection('Topic Inside', $chapter, 2);
        $directLesson = $makeLesson($versionId, 'Direct Lesson', null, 1);
        $chapterLesson = $makeLesson($versionId, 'Chapter Lesson', $chapter, 2);
        $topicLesson = $makeLesson($versionId, 'Topic Lesson', $topic, 3);
        $foreignLesson = $makeLesson($otherVersionId, 'Foreign Lesson', null, 4);
        $directLive = $makeActivity($versionId, $directLesson, 'live_class', 'Direct Live', 1);
        $document = $makeActivity($versionId, $directLesson, 'document', 'Direct Reading', 2);
        $chapterLive = $makeActivity($versionId, $chapterLesson, 'live_class', 'Chapter Live', 3);
        $topicLive = $makeActivity($versionId, $topicLesson, 'live_class', 'Topic Live', 4);
        $makeActivity($otherVersionId, $foreignLesson, 'live_class', 'Foreign Live', 5);

        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions";
        $at = fn (int $days): string => now()->addDays($days)->format('Y-m-d H:i:s');
        $this->actingAs($admin)->post($url, [
            'title' => 'Second session of chapter', 'session_type' => 'curriculum',
            'version_lesson_id' => $chapterLesson, 'version_activity_id' => $chapterLive, 'delivery_mode' => 'online',
            'scheduled_start_at' => $at(3), 'scheduled_end_at' => now()->addDays(3)->addHour()->format('Y-m-d H:i:s'),
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post($url, [
            'title' => 'First session of chapter', 'session_type' => 'curriculum',
            'version_lesson_id' => $chapterLesson, 'version_activity_id' => $chapterLive, 'delivery_mode' => 'online',
            'scheduled_start_at' => $at(2), 'scheduled_end_at' => now()->addDays(2)->addHour()->format('Y-m-d H:i:s'),
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post($url, $this->operationalPayload(
            'Class meeting', $at(4), now()->addDays(4)->addHour()->format('Y-m-d H:i:s')
        ))->assertSessionHasNoErrors();

        // A cancelled Session no longer delivers its Activity, so the Activity is open for scheduling again.
        $cancelled = (int) DB::table('core_liveclass_sessions')->where('title', 'First session of chapter')->value('id');
        $this->actingAs($admin)->post($url, [
            'title' => 'Direct to cancel', 'session_type' => 'curriculum',
            'version_lesson_id' => $directLesson, 'version_activity_id' => $directLive, 'delivery_mode' => 'online',
            'scheduled_start_at' => $at(5), 'scheduled_end_at' => now()->addDays(5)->addHour()->format('Y-m-d H:i:s'),
        ])->assertSessionHasNoErrors();
        $cancelledDirect = (int) DB::table('core_liveclass_sessions')->where('title', 'Direct to cancel')->value('id');
        DB::table('core_liveclass_sessions')->where('id', $cancelledDirect)->update(['status' => 'cancelled']);

        $sessions = app(LiveClassCohortSessionReadModel::class)->forCohort($customerId, $cohortId);
        $tree = app(CourseCohortContentTree::class)->build($customerId, $cohortId, $versionId, $sessions, collect([
            ['schedule_id' => 9, 'schedule_slot_id' => 1, 'date' => now()->subDay()->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00', 'timezone' => 'UTC', 'consumed' => false],
            ['schedule_id' => 9, 'schedule_slot_id' => 1, 'date' => now()->addDays(10)->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00', 'timezone' => 'UTC', 'consumed' => true],
            ['schedule_id' => 9, 'schedule_slot_id' => 1, 'date' => now()->addDays(11)->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00', 'timezone' => 'UTC', 'consumed' => false],
        ]));

        $this->assertSame(
            [$cancelled, (int) DB::table('core_liveclass_sessions')->where('title', 'Second session of chapter')->value('id')],
            $tree['sessionsByActivity']->get($chapterLive)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'sessions of one activity run in time order'
        );
        $this->assertSame(['Class meeting'], $tree['looseSessions']->pluck('title')->all());
        // Every Session appears exactly once.
        $this->assertSame(
            $sessions->pluck('id')->sort()->values()->all(),
            $tree['sessionsByActivity']->flatten(1)->concat($tree['looseSessions'])->pluck('id')->sort()->values()->all()
        );
        // Proposals follow the order of the tree: documents and the other Version never join, a past or used date is
        // never proposed, and an activity beyond the available dates stays without one.
        $this->assertSame([$directLive, $topicLive], array_keys($tree['proposals']));
        $this->assertSame(now()->addDays(11)->toDateString(), $tree['proposals'][$directLive]['occurrence']['date']);
        $this->assertNull($tree['proposals'][$topicLive]['occurrence']);

        // A date another Session was moved onto looks free by its Origin, but is not proposed: saving it would overlap.
        $free = ['schedule_id' => 9, 'schedule_slot_id' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'timezone' => 'UTC', 'consumed' => false];
        $movedOnto = now()->addDays(12)->setTime(9, 30);
        $occupying = (object) ['id' => 0, 'version_activity_id' => null, 'version_lesson_id' => null, 'position_lock_reason' => null, 'status' => 'scheduled', 'timezone' => 'UTC',
            'scheduled_start_at' => $movedOnto->format('Y-m-d H:i:s'), 'scheduled_end_at' => $movedOnto->copy()->addHour()->format('Y-m-d H:i:s')];
        $occupied = app(CourseCohortContentTree::class)->build($customerId, $cohortId, $versionId, $sessions->concat([$occupying]), collect([
            $free + ['date' => now()->addDays(12)->toDateString()],
            $free + ['date' => now()->addDays(13)->toDateString()],
        ]));
        $this->assertSame(now()->addDays(13)->toDateString(), $occupied['proposals'][$directLive]['occurrence']['date']);

        $tab = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")->assertOk();
        $tab->assertSeeInOrder(['Direct Lesson', 'Direct Live', 'Direct Reading', 'Chapter One', 'Chapter Lesson', 'Chapter Live', 'Topic Inside', 'Topic Lesson', 'Topic Live'])
            ->assertSee('data-version-activity-id="'.$document.'"', false)
            ->assertDontSee('Foreign Lesson')
            ->assertDontSee('Foreign Live')
            ->assertSee('data-session-id="'.$cancelled.'"', false)
            ->assertSeeText('Class meeting')
            ->assertSeeText(__('lf.LF_course_cohort_session_tree_unscheduled'))
            ->assertSee('openProposal(', false)
            ->assertSee('course-template-structure-tabs', false)
            ->assertSee('course-template-content-overview', false)
            ->assertDontSeeText('Xếp lịch mới');

        // Nothing is created by opening the tab, and the template's Version is untouched.
        $this->assertSame(4, DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->count());
        $this->assertSame(1, DB::table('core_course_template_version_activities')->where('template_version_id', $otherVersionId)->count());
    }

    public function test_content_tree_is_scoped_to_the_tenant(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $versionId = $this->createVersion($customerId, $admin->id, 'Locked Version');
        DB::table('core_course_template_version_lessons')->insert([
            'customer_id' => $customerId, 'template_version_id' => $versionId,
            'source_template_lesson_id' => 43001, 'title_snapshot' => 'Own Lesson',
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherCustomerId = $this->createTenant('tenant-b');

        $tree = app(CourseCohortContentTree::class);

        $this->assertTrue($tree->build($customerId, 1, $versionId, collect())['hasContent']);
        $this->assertFalse($tree->build($otherCustomerId, 1, $versionId, collect())['hasContent']);
        $this->assertFalse($tree->build($customerId, 1, null, collect())['hasContent']);
    }

    public function test_session_form_moves_a_session_only_when_it_says_so_and_audits_the_move(): void
    {
        Carbon::setTestNow('2026-08-04 12:00:00');
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        $start = now()->addDays(2)->startOfHour();
        $sessionId = $this->operationalSession($customerId, $cohortId, $admin, $start->format('Y-m-d H:i:s'), $start->copy()->addHour()->format('Y-m-d H:i:s'));
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}";
        $moved = $start->copy()->addDay();
        $payload = $this->operationalPayload('Lifecycle class', $moved->format('Y-m-d H:i:s'), $moved->copy()->addHour()->format('Y-m-d H:i:s'));

        // Without the flag the submitted date is ignored, as it always was.
        $this->actingAs($admin)->put($url, $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $sessionId, 'scheduled_start_at' => $start->format('Y-m-d H:i:s')]);
        $this->assertSame(0, DB::table('core_liveclass_session_schedule_changes')->where('session_id', $sessionId)->count());

        $this->actingAs($admin)->put($url, $payload + ['apply_schedule' => '1', 'reason' => 'Teacher away'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $sessionId, 'scheduled_start_at' => $moved->format('Y-m-d H:i:s')]);
        $this->assertDatabaseHas('core_liveclass_session_schedule_changes', [
            'session_id' => $sessionId, 'reason' => 'Teacher away',
            'previous_start_at' => $start->format('Y-m-d H:i:s'), 'new_start_at' => $moved->format('Y-m-d H:i:s'),
        ]);

        // A date outside the class's period is refused, and the audit row written before the check is rolled back.
        $this->actingAs($admin)->put($url, $this->operationalPayload('Lifecycle class', '2026-09-15 09:00:00', '2026-09-15 10:00:00') + ['apply_schedule' => '1'])
            ->assertSessionHasErrors('scheduled_end_at');
        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $sessionId, 'scheduled_start_at' => $moved->format('Y-m-d H:i:s')]);
        $this->assertSame(1, DB::table('core_liveclass_session_schedule_changes')->where('session_id', $sessionId)->count());
    }

    public function test_a_session_past_its_start_cannot_be_moved_or_edited(): void
    {
        Carbon::setTestNow('2026-08-04 12:00:00');
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        $start = now()->addHour()->startOfHour();
        $sessionId = $this->operationalSession($customerId, $cohortId, $admin, $start->format('Y-m-d H:i:s'), $start->copy()->addHour()->format('Y-m-d H:i:s'));
        $this->travelTo($start->copy()->addMinutes(5));
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}";
        $later = now()->addDays(2);
        $interval = ['scheduled_start_at' => $later->format('Y-m-d H:i:s'), 'scheduled_end_at' => $later->copy()->addHour()->format('Y-m-d H:i:s')];

        $this->actingAs($admin)->put("{$url}/schedule", $interval)->assertUnprocessable();
        $this->actingAs($admin)->put($url, $this->operationalPayload('Lifecycle class', $interval['scheduled_start_at'], $interval['scheduled_end_at']) + ['apply_schedule' => '1'])->assertUnprocessable();

        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $sessionId, 'scheduled_start_at' => $start->format('Y-m-d H:i:s')]);
        $this->assertSame(0, DB::table('core_liveclass_session_schedule_changes')->where('session_id', $sessionId)->count());
    }

    public function test_creating_from_a_proposed_date_with_another_time_keeps_the_occurrence_and_audits_the_move(): void
    {
        Carbon::setTestNow('2026-08-04 12:00:00');
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        $date = now()->addWeek()->startOfDay();
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules", $this->validScheduleData([
            'starts_on' => $date->toDateString(), 'ends_on' => $date->toDateString(),
            'slots' => [['weekday' => $date->isoWeekday(), 'start_time' => '09:00', 'end_time' => '10:00']],
        ]))->assertSessionHasNoErrors();
        $schedule = DB::table('core_liveclass_schedules')->where('cohort_id', $cohortId)->first();
        $slot = DB::table('core_liveclass_schedule_slots')->where('schedule_id', $schedule->id)->first();
        $moved = $date->copy()->addDay()->setTime(14, 0);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", $this->operationalPayload(
            'From proposal', $moved->format('Y-m-d H:i:s'), $moved->copy()->addHour()->format('Y-m-d H:i:s')
        ) + [
            'schedule_id' => $schedule->id, 'schedule_slot_id' => $slot->id, 'source_local_date' => $date->toDateString(),
            'apply_schedule' => '1', 'reason' => 'Holiday',
        ])->assertSessionHasNoErrors();

        $session = DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->where('title', 'From proposal')->first();
        $this->assertSame($moved->format('Y-m-d H:i:s'), Carbon::parse($session->scheduled_start_at)->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('core_liveclass_session_schedule_origins', ['session_id' => $session->id, 'source_local_date' => $date->toDateString()]);
        $this->assertDatabaseHas('core_liveclass_session_schedule_changes', ['session_id' => $session->id, 'reason' => 'Holiday']);
    }

    /** @return array<int, array{lesson: int, activity: int}> direct Lessons, each with one live Activity */
    public function test_saving_a_schedule_dates_a_programme_longer_than_one_hundred_live_activities(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['start_date' => '2026-08-01', 'end_date' => '2026-12-31']);
        $this->programme($customerId, $versionId, array_map(fn (int $n): string => "Lesson {$n}", range(1, 105)));
        $daily = $this->validScheduleData([
            'starts_on' => '2026-08-10', 'ends_on' => '2026-12-31',
            'slots' => array_map(fn (int $day): array => ['weekday' => $day, 'start_time' => '09:00', 'end_time' => '10:00'], range(1, 7)),
        ]);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules", $daily)
            ->assertSessionHasNoErrors();

        $this->assertSame(105, DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->count());
        $this->assertSame('2026-11-22 09:00:00', DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->max('scheduled_start_at'));
    }

    private function programme(int $customerId, int $versionId, array $titles): array
    {
        static $source = 50000;

        return array_map(function (string $title, int $index) use ($customerId, $versionId, &$source): array {
            $lesson = DB::table('core_course_template_version_lessons')->insertGetId([
                'customer_id' => $customerId, 'template_version_id' => $versionId, 'source_template_lesson_id' => ++$source,
                'title_snapshot' => $title, 'sort_order' => $index + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $activity = DB::table('core_course_template_version_activities')->insertGetId([
                'customer_id' => $customerId, 'template_version_id' => $versionId, 'version_lesson_id' => $lesson,
                'source_template_activity_id' => ++$source, 'title_snapshot' => $title.' Live', 'activity_type' => 'live_class',
                'live_class_url_snapshot' => 'https://zoom.us/j/'.$source, 'sort_order' => 1,
                'completion_rule' => 'manual', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['lesson' => (int) $lesson, 'activity' => (int) $activity];
        }, $titles, array_keys($titles));
    }

    public function test_an_in_class_session_needs_a_room_but_no_address_and_remote_learners_make_it_hybrid(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B']);
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions";
        $payload = fn (array $item, array $extra) => $extra + [
            'title' => 'Lesson', 'session_type' => 'curriculum',
            'version_lesson_id' => $item['lesson'], 'version_activity_id' => $item['activity'],
            'scheduled_start_at' => '2026-08-10 09:00:00', 'scheduled_end_at' => '2026-08-10 10:00:00',
        ];

        $this->actingAs($admin)->post($url, $payload($a, ['delivery_mode' => 'offline', 'room_name' => '']))->assertSessionHasErrors('room_name');
        $this->actingAs($admin)->post($url, $payload($a, ['delivery_mode' => 'offline', 'room_name' => 'Room 201']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_sessions', ['version_activity_id' => $a['activity'], 'delivery_mode' => 'offline', 'room_name_snapshot' => 'Room 201', 'address_snapshot' => null]);

        $this->actingAs($admin)->post($url, $payload($b, [
            'delivery_mode' => 'hybrid', 'room_name' => 'Room 202', 'meeting_url' => 'https://meet.example.test/b',
            'scheduled_start_at' => '2026-08-11 09:00:00', 'scheduled_end_at' => '2026-08-11 10:00:00',
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_sessions', ['version_activity_id' => $b['activity'], 'delivery_mode' => 'hybrid', 'meeting_url_snapshot' => 'https://meet.example.test/b']);

        // The form picks In class or Online, with a box for remote learners; the lesson row shows the mode.
        $sessionsTab = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")->assertOk();
        // One edit per session, on its lesson row: the live activity row has none (2026-11-09).
        $this->assertSame(2, substr_count($sessionsTab->getContent(), 'x-on:click="editSession('));
        $sessionsTab
            ->assertSee('name="session_place" value="offline"', false)
            ->assertSee('name="session_place" value="online"', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_remote'))
            ->assertSee('data-session-mode="offline"', false)
            ->assertSee('data-session-mode="hybrid"', false)
            // A lesson holds one live session, edited on the lesson row only; its activity row keeps view.
            ->assertSee(e(__('lf.LF_course_template_lesson_common_edit').': Lesson A'), false)
            ->assertSee(e(__('lf.LF_course_cohort_session_details')).': ', false);
    }

    public function test_the_class_default_meeting_link_fills_new_sessions_and_sessions_without_a_link(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a] = $this->programme($customerId, $versionId, ['Lesson A']);
        $cohortUrl = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}";

        $this->actingAs($admin)->put($cohortUrl, $this->validCohortData(['default_meeting_url' => 'ftp://meet.example.test/x']))
            ->assertSessionHasErrors('default_meeting_url');
        $this->actingAs($admin)->put($cohortUrl, $this->validCohortData(['default_meeting_url' => ' https://meet.example.test/class ']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $cohortId, 'default_meeting_url' => 'https://meet.example.test/class']);
        $this->actingAs($admin)->get($cohortUrl)->assertOk()->assertSee('data-cohort-default-meeting', false)
            ->assertSee('value="https://meet.example.test/class"', false);

        // A new lesson session and a new operational session without a link take the class's.
        $this->programmeSession($admin, $cohortId, $a, 'Lesson A', '2026-08-10');
        $this->assertDatabaseHas('core_liveclass_sessions', ['version_activity_id' => $a['activity'], 'meeting_url_snapshot' => 'https://meet.example.test/class']);
        $this->actingAs($admin)->post("{$cohortUrl}/sessions", [
            'title' => 'Extra', 'session_type' => 'operational', 'delivery_mode' => 'online',
            'scheduled_start_at' => '2026-08-12 09:00:00', 'scheduled_end_at' => '2026-08-12 10:00:00',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_sessions', ['title' => 'Extra', 'meeting_url_snapshot' => 'https://meet.example.test/class']);

        // Saving a session as In class + remote with its link cleared takes the class's link again.
        $sessionId = (int) DB::table('core_liveclass_sessions')->where('version_activity_id', $a['activity'])->value('id');
        DB::table('core_liveclass_sessions')->where('id', $sessionId)->update(['meeting_url_snapshot' => 'https://meet.example.test/old']);
        $this->actingAs($admin)->put("{$cohortUrl}/sessions/{$sessionId}", [
            'title' => 'Lesson A', 'session_type' => 'curriculum',
            'version_lesson_id' => $a['lesson'], 'version_activity_id' => $a['activity'],
            'delivery_mode' => 'hybrid', 'room_name' => 'Room 201', 'meeting_url' => '',
            'scheduled_start_at' => '2026-08-10 09:00:00', 'scheduled_end_at' => '2026-08-10 10:00:00',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $sessionId, 'delivery_mode' => 'hybrid', 'meeting_url_snapshot' => 'https://meet.example.test/class']);

        // The admin detail never offers the meeting of an in-class session.
        $this->actingAs($admin)->get("{$cohortUrl}?tab=sessions")->assertOk()
            ->assertSee("data-session-meeting-link x-show=\"detailSession?.delivery_mode !== 'offline'", false);

        // A session's own link wins; one left without a link reads the class's at join time, an in-class one never.
        $read = fn (?string $own, string $mode) => LiveClassWhiteboardService::meetingUrl((object) [
            'meeting_url_snapshot' => $own, 'delivery_mode' => $mode, 'cohort_meeting_url' => 'https://meet.example.test/class',
        ]);
        $this->assertSame('https://meet.example.test/own', $read('https://meet.example.test/own', 'online'));
        $this->assertSame('https://meet.example.test/class', $read(null, 'hybrid'));
        $this->assertNull($read(null, 'offline'));
    }

    public function test_a_cancelled_session_reads_as_history_and_its_replacement_as_a_make_up(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a] = $this->programme($customerId, $versionId, ['Lesson A']);
        $page = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions";
        $first = $this->programmeSession($admin, $cohortId, $a, 'Lesson A', '2026-08-10');
        DB::table('core_liveclass_sessions')->where('id', $first)->update(['status' => 'cancelled', 'cancellation_reason' => 'Teacher ill']);

        // Cancelled with nothing in its place yet: history, no make-up.
        $response = $this->actingAs($admin)->get($page)->assertOk();
        $this->assertMatchesRegularExpression('/data-cancelled-sessions="1"\s+open/', $response->getContent());
        $response
            ->assertSee('ui-cohort-session-tree__slot--cancelled', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_status_reason').': Teacher ill');

        $this->programmeSession($admin, $cohortId, $a, 'Lesson A', '2026-08-12');
        $this->actingAs($admin)->get($page)->assertOk()
            ->assertSee('data-session-makeup', false)
            ->assertSee(e(__('lf.LF_course_cohort_session_makeup_for', ['date' => '10/08/2026 · 09:00'])), false)
            ->assertSeeText(__('lf.LF_course_cohort_session_cancelled_group'));

        // Several cancelled sessions fold into one line.
        $second = (int) DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->orderByDesc('id')->value('id');
        DB::table('core_liveclass_sessions')->where('id', $second)->update(['status' => 'cancelled']);
        $response = $this->actingAs($admin)->get($page)->assertOk();
        $this->assertMatchesRegularExpression('/data-cancelled-sessions="2"\s*>/', $response->getContent());
        $response->assertSeeText(__('lf.LF_course_cohort_session_cancelled_group_count', ['count' => 2]));
    }

    public function test_cancelling_a_session_moves_its_lesson_and_the_later_ones_one_date_down(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b, $c] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B', 'Lesson C']);
        $this->weeklySchedule($admin, $cohortId, '2026-08-10', '2026-08-31');
        $sessionOf = fn (array $item) => DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)
            ->where('version_activity_id', $item['activity'])->where('status', 'scheduled')->first();
        $dates = fn () => [substr((string) $sessionOf($a)?->scheduled_start_at, 0, 10), substr((string) $sessionOf($b)?->scheduled_start_at, 0, 10), substr((string) $sessionOf($c)?->scheduled_start_at, 0, 10)];
        $this->assertSame(['2026-08-10', '2026-08-17', '2026-08-24'], $dates());
        $first = $sessionOf($a);
        $rowB = $sessionOf($b);
        // Lesson B is taught in room R-B: the room travels with the lesson.
        DB::table('core_liveclass_sessions')->where('id', $rowB->id)->update(['delivery_mode' => 'offline', 'room_name_snapshot' => 'R-B', 'meeting_url_snapshot' => null]);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$first->id}/cancel", ['reason' => 'Teacher ill'])
            ->assertSessionHas('success', __('lf.LF_course_cohort_session_lifecycle_cancelled_shifted'));

        // A takes B's date, B takes C's, C the next free Monday; nothing changed time.
        $this->assertSame(['2026-08-17', '2026-08-24', '2026-08-31'], $dates());
        $this->assertSame((int) $rowB->id, (int) $sessionOf($a)->id);
        $this->assertSame('offline', $sessionOf($b)->delivery_mode);
        $this->assertSame('R-B', $sessionOf($b)->room_name_snapshot);
        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $first->id, 'status' => 'cancelled', 'version_activity_id' => $a['activity'], 'superseded_by_session_id' => $rowB->id]);
        $this->assertSame(0, DB::table('core_liveclass_session_schedule_changes')->whereIn('session_id', [$rowB->id])->count());

        // Cancelling the last lesson moves nothing.
        $last = $sessionOf($c);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$last->id}/cancel")
            ->assertSessionHas('success', __('lf.LF_course_cohort_session_lifecycle_cancelled'));
        $this->assertSame(['2026-08-17', '2026-08-24', ''], $dates());
    }

    public function test_a_cancel_that_would_move_a_locked_session_cancels_without_moving(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B']);
        $this->weeklySchedule($admin, $cohortId, '2026-08-10', '2026-08-31');
        $rowA = DB::table('core_liveclass_sessions')->where('version_activity_id', $a['activity'])->first();
        $rowB = DB::table('core_liveclass_sessions')->where('version_activity_id', $b['activity'])->first();
        DB::table('core_liveclass_sessions')->where('id', $rowB->id)->update(['actual_start_at' => now()]);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$rowA->id}/cancel")
            ->assertSessionHas('success', __('lf.LF_course_cohort_session_lifecycle_cancelled'));
        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $rowB->id, 'version_activity_id' => $b['activity'], 'scheduled_start_at' => $rowB->scheduled_start_at]);
        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $rowA->id, 'status' => 'cancelled', 'superseded_by_session_id' => null]);
    }

    private function programmeSession(User $admin, int $cohortId, array $item, string $title, string $date, array $extra = []): int
    {
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", $extra + [
            'title' => $title, 'session_type' => 'curriculum', 'delivery_mode' => 'online',
            'version_lesson_id' => $item['lesson'], 'version_activity_id' => $item['activity'],
            'scheduled_start_at' => "{$date} 09:00:00", 'scheduled_end_at' => "{$date} 10:00:00",
        ])->assertSessionHasNoErrors();

        return (int) DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->orderByDesc('id')->value('id');
    }

    private function dragLessons(User $admin, int $cohortId, array $lessonIds, ?int $sectionId = null)
    {
        return $this->actingAs($admin)->patchJson(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/content/lessons/order".($sectionId ? "?version_section_id={$sectionId}" : ''),
            ['lesson_ids' => $lessonIds]
        );
    }

    /** @return array{0: int, 1: User, 2: int, 3: int} */
    private function reorderCohort(): array
    {
        Carbon::setTestNow('2026-08-04 12:00:00');
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();

        return [$customerId, $admin, $cohortId, (int) DB::table('core_course_cohorts')->where('id', $cohortId)->value('version_id')];
    }

    public function test_a_drag_moves_the_whole_box_and_only_this_class_changes(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b, $c] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B', 'Lesson C']);
        $teacher = $this->createUser($customerId, 'teacher');
        DB::table('core_course_cohort_teachers')->insert([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'teacher_id' => $teacher->id,
            'role' => 'teacher', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $first = $this->programmeSession($admin, $cohortId, $a, 'Typed title', '2026-08-10', [
            'meeting_url' => 'https://meet.google.com/typed', 'teacher_ids' => [$teacher->id],
        ]);
        $second = $this->programmeSession($admin, $cohortId, $b, 'Lesson B', '2026-08-11', [
            'delivery_mode' => 'offline', 'room_name' => 'Room 1', 'address' => '1 Main St',
        ]);
        $third = $this->programmeSession($admin, $cohortId, $c, 'Lesson C', '2026-08-12');
        $versionOrder = DB::table('core_course_template_version_lessons')->where('template_version_id', $versionId)->orderBy('id')->pluck('sort_order')->all();

        $this->dragLessons($admin, $cohortId, [$c['lesson'], $a['lesson'], $b['lesson']])->assertOk();

        $row = fn (int $id): object => DB::table('core_liveclass_sessions')->where('id', $id)->first();
        $teachers = fn (int $id): array => DB::table('core_liveclass_session_teachers')->where('session_id', $id)->pluck('teacher_id')->map(fn ($id): int => (int) $id)->all();

        $this->assertSame($c['activity'], (int) $row($first)->version_activity_id, 'the first date now teaches lesson C');
        $this->assertSame($c['lesson'], (int) $row($first)->version_lesson_id);
        $this->assertSame('2026-08-10 09:00:00', $row($first)->scheduled_start_at, 'the date stays at the position');
        $this->assertSame('Lesson C', $row($first)->title);
        $this->assertSame([], $teachers($first), 'lesson A took its teacher with it');

        $this->assertSame($a['activity'], (int) $row($second)->version_activity_id);
        $this->assertSame('2026-08-11 09:00:00', $row($second)->scheduled_start_at);
        $this->assertSame('Typed title', $row($second)->title, 'the name follows the content');
        $this->assertSame('https://meet.google.com/typed', $row($second)->meeting_url_snapshot);
        $this->assertSame('Google Meet', $row($second)->online_provider);
        $this->assertSame([(int) $teacher->id], $teachers($second), 'the teacher follows the content');
        $this->assertSame('online', $row($second)->delivery_mode);
        $this->assertNull($row($second)->room_name_snapshot);

        $this->assertSame($b['activity'], (int) $row($third)->version_activity_id);
        $this->assertSame('2026-08-12 09:00:00', $row($third)->scheduled_start_at);
        $this->assertSame('offline', $row($third)->delivery_mode, 'the room follows the content');
        $this->assertSame('Room 1', $row($third)->room_name_snapshot);
        $this->assertSame('1 Main St', $row($third)->address_snapshot);

        $this->assertSame(0, DB::table('core_liveclass_session_schedule_changes')->count(), 'no time changed, so nothing is audited');
        $this->assertSame($versionOrder, DB::table('core_course_template_version_lessons')->where('template_version_id', $versionId)->orderBy('id')->pluck('sort_order')->all());

        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")->assertOk()
            ->assertSeeInOrder(['Lesson C', 'Lesson A', 'Lesson B']);

        // Another class on the same Version keeps the Version's order.
        $otherCohort = $this->createCohort($customerId, 'Other class');
        DB::table('core_course_cohorts')->where('id', $otherCohort)->update(['version_id' => $versionId]);
        $tree = app(CourseCohortContentTree::class);
        $this->assertSame(
            [$a['activity'], $b['activity'], $c['activity']],
            $tree->liveActivities($customerId, $otherCohort, $versionId)->pluck('id')->map(fn ($id): int => (int) $id)->all()
        );
        $this->assertSame(
            [$c['activity'], $a['activity'], $b['activity']],
            $tree->liveActivities($customerId, $cohortId, $versionId)->pluck('id')->map(fn ($id): int => (int) $id)->all()
        );
    }

    /** @return array{0: object, 1: object} a Schedule with one weekly slot, and that slot */
    private function weeklySchedule(User $admin, int $cohortId, string $from, string $to): array
    {
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules", $this->validScheduleData([
            'starts_on' => $from, 'ends_on' => $to,
            'slots' => [['weekday' => Carbon::parse($from)->isoWeekday(), 'start_time' => '09:00', 'end_time' => '10:00']],
        ]))->assertSessionHasNoErrors();
        $schedule = DB::table('core_liveclass_schedules')->where('cohort_id', $cohortId)->first();

        return [$schedule, DB::table('core_liveclass_schedule_slots')->where('schedule_id', $schedule->id)->first()];
    }

    public function test_saved_content_moved_to_an_unsaved_date_is_saved_there_with_its_box(): void
    {
        $this->keepDatesUnsaved();
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B']);
        [$schedule, $slot] = $this->weeklySchedule($admin, $cohortId, '2026-08-10', '2026-08-24');
        $teacher = $this->createUser($customerId, 'teacher');
        DB::table('core_course_cohort_teachers')->insert([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'teacher_id' => $teacher->id,
            'role' => 'teacher', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions", [
            'title' => 'Lesson A', 'session_type' => 'curriculum', 'delivery_mode' => 'online',
            'version_lesson_id' => $a['lesson'], 'version_activity_id' => $a['activity'], 'opened_version_activity_id' => $a['activity'],
            'schedule_id' => $schedule->id, 'schedule_slot_id' => $slot->id, 'source_local_date' => '2026-08-10',
            'teacher_ids' => [$teacher->id],
        ])->assertSessionHasNoErrors();
        $first = (int) DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->value('id');

        $this->dragLessons($admin, $cohortId, [$b['lesson'], $a['lesson']])->assertOk();

        $sessions = DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->orderBy('scheduled_start_at')->get();
        $this->assertCount(2, $sessions);
        $this->assertSame($first, (int) $sessions[0]->id, 'the first date keeps its Session');
        $this->assertSame($b['activity'], (int) $sessions[0]->version_activity_id);
        $this->assertSame('Lesson B', $sessions[0]->title);
        $this->assertSame(0, DB::table('core_liveclass_session_teachers')->where('session_id', $first)->count());

        $this->assertSame($a['activity'], (int) $sessions[1]->version_activity_id);
        $this->assertSame('2026-08-17 09:00:00', $sessions[1]->scheduled_start_at, 'the next date of the Schedule');
        $this->assertSame('Lesson A', $sessions[1]->title);
        $this->assertSame([(int) $teacher->id], DB::table('core_liveclass_session_teachers')->where('session_id', $sessions[1]->id)->pluck('teacher_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame('2026-08-17', (string) DB::table('core_liveclass_session_schedule_origins')->where('session_id', $sessions[1]->id)->value('source_local_date'));
    }

    public function test_a_drag_is_refused_when_saved_content_would_have_no_date_or_its_teacher_cannot_teach(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B']);
        $teacher = $this->createUser($customerId, 'teacher');
        DB::table('core_course_cohort_teachers')->insert([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'teacher_id' => $teacher->id, 'role' => 'teacher',
            'status' => 'active', 'assigned_from' => '2026-08-01', 'assigned_to' => '2026-08-10', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $first = $this->programmeSession($admin, $cohortId, $a, 'Lesson A', '2026-08-10', ['teacher_ids' => [$teacher->id]]);
        $swap = [$b['lesson'], $a['lesson']];

        $this->dragLessons($admin, $cohortId, $swap)->assertStatus(422)
            ->assertJsonPath('errors.order.0', __('lf.LF_course_cohort_order_no_date', ['activity' => 'Lesson A Live']));

        $this->programmeSession($admin, $cohortId, $b, 'Lesson B', '2026-08-11');
        $this->dragLessons($admin, $cohortId, $swap)->assertStatus(422)
            ->assertJsonPath('errors.order.0', __('lf.LF_course_cohort_order_teacher_unavailable', ['teacher' => $teacher->name, 'date' => '11/08/2026']));

        $this->assertSame(0, DB::table('core_course_cohort_lesson_orders')->count());
        $this->assertSame($a['activity'], (int) DB::table('core_liveclass_sessions')->where('id', $first)->value('version_activity_id'));
    }

    public function test_a_class_shows_the_kind_its_version_already_is_without_the_switch(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        $this->programme($customerId, $versionId, ['Lesson A']);
        $tab = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions";

        $this->actingAs($admin)->get($tab)->assertOk()->assertSee('Lesson A')
            ->assertSee('ui-structure--one-kind', false);

        // A Version holding both kinds keeps the switch, or one kind could not be reached.
        DB::table('core_course_template_version_sections')->insert([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'source_template_section_id' => 59998,
            'title_snapshot' => 'Part 1', 'allows_lessons' => true, 'display_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($admin)->get($tab)->assertOk()->assertDontSee('ui-structure--one-kind', false);
    }

    public function test_every_past_session_locks_its_place_whatever_its_outcome(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B']);
        $session = $this->programmeSession($admin, $cohortId, $a, 'Lesson A', '2026-08-10');
        $swap = [$b['lesson'], $a['lesson']];
        $tab = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions";

        $this->actingAs($admin)->get($tab)->assertOk()->assertSee('x-sort:item="'.$a['lesson'].'"', false);

        foreach ([
            'past' => ['scheduled_start_at' => '2026-08-01 09:00:00', 'scheduled_end_at' => '2026-08-01 10:00:00'],
            'cancelled' => ['scheduled_start_at' => '2026-08-01 09:00:00', 'scheduled_end_at' => '2026-08-01 10:00:00', 'status' => 'cancelled'],
            'no_show' => ['status' => 'no_show'],
            'completed' => ['status' => 'completed'],
            'live' => ['status' => 'live'],
            'started' => ['actual_start_at' => '2026-08-04 09:00:00'],
        ] as $case => $state) {
            $fresh = ['scheduled_start_at' => '2026-08-10 09:00:00', 'scheduled_end_at' => '2026-08-10 10:00:00', 'status' => 'scheduled', 'actual_start_at' => null];
            DB::table('core_liveclass_sessions')->where('id', $session)->update($state + $fresh);

            $this->dragLessons($admin, $cohortId, $swap)->assertStatus(422);
            $this->assertSame(0, DB::table('core_course_cohort_lesson_orders')->count(), $case);
            $this->actingAs($admin)->get($tab)->assertOk()->assertDontSee('x-sort:item="'.$a['lesson'].'"', false)
                ->assertSee('data-tooltip="'.e(__('lf.LF_course_cohort_order_lock_'.$case)).'"', false);
        }

        // A cancelled Session that has not come yet locks nothing, and stays with its Activity.
        DB::table('core_liveclass_sessions')->where('id', $session)->update(['status' => 'cancelled', 'actual_start_at' => null]);
        $this->dragLessons($admin, $cohortId, $swap)->assertOk();
        $this->assertSame($a['activity'], (int) DB::table('core_liveclass_sessions')->where('id', $session)->value('version_activity_id'));
    }

    public function test_attendance_locks_a_session_place(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B']);
        $session = $this->programmeSession($admin, $cohortId, $a, 'Lesson A', '2026-08-10');
        $this->mock(LiveClassCohortSessionReadModel::class, function ($mock) use ($session): void {
            $mock->shouldReceive('sessionIdsWithEvidence')->andReturn(collect([$session]));
        });

        $this->dragLessons($admin, $cohortId, [$b['lesson'], $a['lesson']])->assertStatus(422);
        $this->assertSame($a['activity'], (int) DB::table('core_liveclass_sessions')->where('id', $session)->value('version_activity_id'));
    }

    public function test_a_drag_cannot_break_the_unlock_rules(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b, $c] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B', 'Lesson C']);
        DB::table('core_course_template_version_lesson_prerequisites')->insert([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'version_lesson_id' => $b['lesson'],
            'prerequisite_version_lesson_id' => $a['lesson'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->dragLessons($admin, $cohortId, [$b['lesson'], $a['lesson'], $c['lesson']])->assertStatus(422)
            ->assertJsonPath('message', __('lf.LF_course_template_lesson_reorder_blocked_prerequisite', ['lesson' => 'Lesson B', 'prerequisite' => 'Lesson A']));
        $this->assertSame(0, DB::table('core_course_cohort_lesson_orders')->count());
        // Lessons the rule does not relate stay free to move.
        $this->dragLessons($admin, $cohortId, [$a['lesson'], $c['lesson'], $b['lesson']])->assertOk();

        // A live prerequisite may not end up behind the live Activity that needs it.
        DB::table('core_course_cohort_lesson_orders')->delete();
        DB::table('core_course_template_version_activities')->where('id', $c['activity'])->update(['unlock_after_version_activity_id' => $a['activity']]);
        $this->dragLessons($admin, $cohortId, [$c['lesson'], $a['lesson'], $b['lesson']])->assertStatus(422)
            ->assertJsonPath('message', __('lf.LF_course_cohort_order_activity_prerequisite', ['activity' => 'Lesson C Live', 'prerequisite' => 'Lesson A Live']));
        $this->assertSame(0, DB::table('core_course_cohort_lesson_orders')->count(), 'a refusal rolls the order back');
    }

    public function test_a_drag_is_refused_when_an_activity_has_several_sessions_or_the_list_is_wrong(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B']);
        $this->programmeSession($admin, $cohortId, $a, 'Lesson A', '2026-08-10');
        $this->programmeSession($admin, $cohortId, $a, 'Lesson A again', '2026-08-11');

        $this->dragLessons($admin, $cohortId, [$b['lesson'], $a['lesson']])->assertStatus(422)
            ->assertJsonPath('message', __('lf.LF_course_cohort_order_activity_many_sessions', ['activity' => 'Lesson A Live']));
        $this->dragLessons($admin, $cohortId, [$a['lesson']])->assertStatus(409);
        $this->dragLessons($admin, $cohortId, [$b['lesson'], $a['lesson'], 999999])->assertStatus(409);
        $this->assertSame(0, DB::table('core_course_cohort_lesson_orders')->count());
    }

    public function test_activities_reorder_inside_their_lesson_and_only_for_an_allowed_admin(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B']);
        $second = DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'version_lesson_id' => $a['lesson'],
            'source_template_activity_id' => 59999, 'title_snapshot' => 'Second Live', 'activity_type' => 'live_class',
            'live_class_url_snapshot' => 'https://zoom.us/j/second', 'sort_order' => 2,
            'completion_rule' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $session = $this->programmeSession($admin, $cohortId, $a, 'Lesson A', '2026-08-10');
        $this->programmeSession($admin, $cohortId, ['lesson' => $a['lesson'], 'activity' => (int) $second], 'Lesson A', '2026-08-11');
        $order = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/content/lessons/{$a['lesson']}/activities/order";

        $this->actingAs($admin)->patchJson($order, ['activity_ids' => [$second, $b['activity']]])->assertStatus(409);
        $this->actingAs($this->createUser($customerId, 'student'))->patchJson($order, ['activity_ids' => [$second, $a['activity']]])->assertForbidden();
        $this->actingAs($admin)->patch($order, ['activity_ids' => [$second, $a['activity']]])->assertNotFound();
        $this->assertSame(0, DB::table('core_course_cohort_activity_orders')->count());

        $this->actingAs($admin)->patchJson($order, ['activity_ids' => [$second, $a['activity']]])->assertOk();
        $row = DB::table('core_liveclass_sessions')->where('id', $session)->first();
        $this->assertSame((int) $second, (int) $row->version_activity_id);
        $this->assertSame($a['lesson'], (int) $row->version_lesson_id);
        $this->assertSame('Lesson A', $row->title);
        $this->assertSame('https://zoom.us/j/second', $row->meeting_url_snapshot);

        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['status' => 'completed']);
        $this->actingAs($admin)->patchJson($order, ['activity_ids' => [$a['activity'], $second]])->assertStatus(422);
    }

    public function test_lessons_reorder_only_inside_their_own_section(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b, $direct] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B', 'Direct']);
        $section = DB::table('core_course_template_version_sections')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId,
            'source_template_section_id' => 58001, 'parent_version_section_id' => null,
            'allows_lessons' => true, 'title_snapshot' => 'Chapter', 'display_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_template_version_lessons')->whereIn('id', [$a['lesson'], $b['lesson']])->update(['version_section_id' => $section]);

        $this->dragLessons($admin, $cohortId, [$b['lesson'], $direct['lesson']], $section)->assertStatus(409);
        $this->dragLessons($admin, $cohortId, [$b['lesson'], $a['lesson']])->assertStatus(409);
        $this->dragLessons($admin, $cohortId, [$b['lesson'], $a['lesson']], $section)->assertOk();

        $this->assertSame(
            [$direct['activity'], $b['activity'], $a['activity']],
            app(CourseCohortContentTree::class)->liveActivities($customerId, $cohortId, $versionId)->pluck('id')->map(fn ($id): int => (int) $id)->all()
        );
    }

    public function test_saving_a_programme_session_says_the_lesson_was_edited(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a] = $this->programme($customerId, $versionId, ['Lesson A']);
        $session = $this->programmeSession($admin, $cohortId, $a, 'Lesson A', '2026-08-10');
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$session}";
        $fields = ['session_type' => 'curriculum', 'title' => 'Lesson A', 'delivery_mode' => 'online', 'version_lesson_id' => $a['lesson'], 'version_activity_id' => $a['activity'], 'opened_version_activity_id' => $a['activity'], 'scheduled_start_at' => '2026-08-10 09:00:00', 'scheduled_end_at' => '2026-08-10 10:00:00'];

        $this->actingAs($admin)->put($url, $fields)->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('lf.LF_course_cohort_session_saved_lesson'));
    }

    public function test_the_lesson_popup_shows_each_error_under_its_field(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a] = $this->programme($customerId, $versionId, ['Lesson A']);
        $session = $this->programmeSession($admin, $cohortId, $a, 'Lesson A', '2026-08-10');
        $page = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions";
        $summary = '<div class="ui-field__error ui-cohort-manage-error" role="alert"';

        // The room error sits under the room field, not in a summary at the top of the popup.
        $this->actingAs($admin)->from($page)->followingRedirects()
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$session}", [
                'form_context' => 'session', 'editing_session_id' => $session,
                'opened_version_activity_id' => $a['activity'], 'original_start_at' => '2026-08-10T09:00',
                'session_type' => 'curriculum', 'title' => 'Lesson A', 'delivery_mode' => 'offline', 'room_name' => '', 'address' => '',
            ])->assertOk()->assertDontSee($summary, false);
    }

    public function test_the_session_form_edits_schedule_and_teachers_but_only_shows_template_fields(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B']);
        DB::table('core_course_template_version_activities')->where('id', $a['activity'])->update(['description_snapshot' => 'From the template']);
        DB::table('core_course_template_version_lessons')->where('id', $a['lesson'])->update(['short_description_snapshot' => 'Lesson summary from the template']);
        $link = (string) DB::table('core_course_template_version_activities')->where('id', $a['activity'])->value('live_class_url_snapshot');
        $session = $this->programmeSession($admin, $cohortId, $a, 'Lesson A', '2026-08-10');
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions";

        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")->assertOk()
            ->assertSee('From the template')
            ->assertSee(__('lf.LF_course_cohort_session_lesson_form_help'))
            ->assertSee('Lesson summary from the template')
            ->assertSee('x-on:click="editSession(', false);

        $this->actingAs($admin)->put("{$url}/{$session}", [
            'title' => 'Forged', 'session_type' => 'operational', 'delivery_mode' => 'offline', 'room_name' => 'R1', 'address' => 'A1',
            'version_lesson_id' => $b['lesson'], 'version_activity_id' => $b['activity'], 'meeting_url' => 'https://forged.example/x',
            'apply_schedule' => '1', 'scheduled_start_at' => '2026-08-12 09:00:00', 'scheduled_end_at' => '2026-08-12 10:00:00',
        ])->assertSessionHasNoErrors();
        $row = DB::table('core_liveclass_sessions')->where('id', $session)->first();
        $this->assertSame('Lesson A', $row->title);
        $this->assertSame('curriculum', $row->session_type);
        $this->assertSame($a['activity'], (int) $row->version_activity_id);
        $this->assertSame('2026-08-12 09:00:00', $row->scheduled_start_at, 'the date is editable');
        $this->assertSame('R1', $row->room_name_snapshot);

        $this->actingAs($admin)->put("{$url}/{$session}", [
            'title' => 'Forged', 'session_type' => 'curriculum', 'delivery_mode' => 'online',
            'version_lesson_id' => $a['lesson'], 'version_activity_id' => $a['activity'], 'meeting_url' => 'https://forged.example/x',
            'scheduled_start_at' => '2026-08-12 09:00:00', 'scheduled_end_at' => '2026-08-12 10:00:00',
        ])->assertSessionHasNoErrors();
        $own = 'https://meet.example/own-room';
        $saveLink = fn (array $link): mixed => $this->actingAs($admin)->put("{$url}/{$session}", $link + [
            'title' => 'Forged', 'session_type' => 'curriculum', 'delivery_mode' => 'online',
            'version_lesson_id' => $a['lesson'], 'version_activity_id' => $a['activity'],
            'scheduled_start_at' => '2026-08-12 09:00:00', 'scheduled_end_at' => '2026-08-12 10:00:00',
        ]);
        $sessionLink = fn (): ?string => DB::table('core_liveclass_sessions')->where('id', $session)->value('meeting_url_snapshot');

        // The link is the session's own; the Template's Version stays as published.
        $saveLink(['meeting_url' => $own])->assertSessionHasNoErrors();
        $this->assertSame($own, $sessionLink());
        $this->assertSame('Lesson A', DB::table('core_liveclass_sessions')->where('id', $session)->value('title'));
        $this->assertSame($link, DB::table('core_course_template_version_activities')->where('id', $a['activity'])->value('live_class_url_snapshot'));
        // A request without a link keeps it, an invalid one is refused, an empty one goes back to the Template's.
        $saveLink([])->assertSessionHasNoErrors();
        $this->assertSame($own, $sessionLink());
        $saveLink(['meeting_url' => 'not a link'])->assertSessionHasErrors('meeting_url');
        $this->assertSame($own, $sessionLink());
        $saveLink(['meeting_url' => ''])->assertSessionHasNoErrors();
        $this->assertSame($link, $sessionLink());

        // A date saved from the programme takes the Template's name, and its link unless the form gives its own.
        $this->actingAs($admin)->post($url, [
            'title' => 'Forged', 'session_type' => 'curriculum', 'delivery_mode' => 'online', 'opened_version_activity_id' => $b['activity'],
            'version_lesson_id' => $b['lesson'], 'version_activity_id' => $b['activity'], 'meeting_url' => $own,
            'scheduled_start_at' => '2026-08-14 09:00:00', 'scheduled_end_at' => '2026-08-14 10:00:00',
        ])->assertSessionHasNoErrors();
        $created = DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->orderByDesc('id')->first();
        $this->assertSame('Lesson B', $created->title);
        $this->assertSame($own, $created->meeting_url_snapshot);

        DB::table('core_liveclass_sessions')->where('id', $created->id)->delete();
        $this->actingAs($admin)->post($url, [
            'title' => 'Forged', 'session_type' => 'curriculum', 'delivery_mode' => 'online', 'opened_version_activity_id' => $b['activity'],
            'version_lesson_id' => $b['lesson'], 'version_activity_id' => $b['activity'], 'meeting_url' => '',
            'scheduled_start_at' => '2026-08-14 09:00:00', 'scheduled_end_at' => '2026-08-14 10:00:00',
        ])->assertSessionHasNoErrors();
        $this->assertStringStartsWith('https://zoom.us/', DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->orderByDesc('id')->value('meeting_url_snapshot'));
    }

    public function test_the_class_teachers_show_by_default_on_a_saved_session_without_any(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a] = $this->programme($customerId, $versionId, ['Lesson A']);
        $session = $this->programmeSession($admin, $cohortId, $a, 'Lesson A', '2026-08-10');
        $teacher = $this->createUser($customerId, 'teacher');
        DB::table('core_course_cohort_teachers')->insert([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'teacher_id' => $teacher->id,
            'role' => 'teacher', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tree = fn (): array => app(CourseCohortContentTree::class)->build(
            $customerId, $cohortId, $versionId,
            app(LiveClassCohortSessionReadModel::class)->forCohort($customerId, $cohortId),
            collect(),
            DB::table('core_course_cohort_teachers as a')->join('users as t', 't.id', '=', 'a.teacher_id')->where('a.cohort_id', $cohortId)
                ->get(['t.id', 't.name', 'a.assigned_from', 'a.assigned_to'])
        );

        $shown = $tree()['sessionsByActivity']->get($a['activity'])->first();
        $this->assertSame([$teacher->name], $shown->teacher_team->pluck('name')->all());
        $this->assertSame([(int) $teacher->id], $shown->teacher_ids->map(fn ($id): int => (int) $id)->all(), 'the form opens with them selected');

        // A session that has its own teachers keeps them.
        $other = $this->createUser($customerId, 'teacher');
        DB::table('core_liveclass_session_teachers')->insert([
            'customer_id' => $customerId, 'session_id' => $session, 'teacher_id' => $other->id, 'role' => 'teacher', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame([$other->name], $tree()['sessionsByActivity']->get($a['activity'])->first()->teacher_team->pluck('name')->all());
    }

    public function test_a_form_opened_before_a_drag_cannot_undo_it(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B']);
        $session = $this->programmeSession($admin, $cohortId, $a, 'Lesson A', '2026-08-10');
        $this->programmeSession($admin, $cohortId, $b, 'Lesson B', '2026-08-11');
        $this->dragLessons($admin, $cohortId, [$b['lesson'], $a['lesson']])->assertOk();

        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$session}", [
            'title' => 'Lesson A', 'session_type' => 'curriculum', 'delivery_mode' => 'online',
            'version_lesson_id' => $a['lesson'], 'version_activity_id' => $a['activity'], 'opened_version_activity_id' => $a['activity'],
            'scheduled_start_at' => '2026-08-10 09:00:00', 'scheduled_end_at' => '2026-08-10 10:00:00',
        ])->assertSessionHasErrors('opened_version_activity_id');
        $this->assertSame($b['activity'], (int) DB::table('core_liveclass_sessions')->where('id', $session)->value('version_activity_id'));
    }

    public function test_a_date_opened_before_a_drag_cannot_be_saved_on_the_old_activity(): void
    {
        $this->keepDatesUnsaved();
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B']);
        $date = Carbon::parse('2026-08-10');
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules", $this->validScheduleData([
            'starts_on' => $date->toDateString(), 'ends_on' => $date->toDateString(),
            'slots' => [['weekday' => $date->isoWeekday(), 'start_time' => '09:00', 'end_time' => '10:00']],
        ]))->assertSessionHasNoErrors();
        $schedule = DB::table('core_liveclass_schedules')->where('cohort_id', $cohortId)->first();
        $slot = DB::table('core_liveclass_schedule_slots')->where('schedule_id', $schedule->id)->first();
        $form = fn (array $item): array => [
            'title' => 'Dated', 'session_type' => 'curriculum', 'delivery_mode' => 'online',
            'version_lesson_id' => $item['lesson'], 'version_activity_id' => $item['activity'], 'opened_version_activity_id' => $item['activity'],
            'schedule_id' => $schedule->id, 'schedule_slot_id' => $slot->id, 'source_local_date' => '2026-08-10',
        ];
        $store = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions";

        // The first date belonged to lesson A when the form opened; after the drag it belongs to lesson B.
        $this->dragLessons($admin, $cohortId, [$b['lesson'], $a['lesson']])->assertOk();
        $this->actingAs($admin)->post($store, $form($a))->assertSessionHasErrors('opened_version_activity_id');
        $this->assertSame(0, DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->count());

        $this->actingAs($admin)->post($store, $form($b))->assertSessionHasNoErrors();
        $this->assertSame($b['activity'], (int) DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->value('version_activity_id'));
    }

    public function test_a_session_cannot_move_onto_the_date_shown_on_another_lesson(): void
    {
        $this->keepDatesUnsaved();
        Carbon::setTestNow('2026-08-04 12:00:00');
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        $versionId = (int) DB::table('core_course_cohorts')->where('id', $cohortId)->value('version_id');
        $lessonId = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'source_template_lesson_id' => 44001,
            'title_snapshot' => 'Dated Lesson', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_template_version_activities')->insert([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'version_lesson_id' => $lessonId,
            'source_template_activity_id' => 44002, 'title_snapshot' => 'Dated Live', 'activity_type' => 'live_class',
            'completion_rule' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $date = Carbon::parse('2026-08-10');
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules", $this->validScheduleData([
            'starts_on' => $date->toDateString(), 'ends_on' => $date->toDateString(),
            'slots' => [['weekday' => $date->isoWeekday(), 'start_time' => '09:00', 'end_time' => '10:00']],
        ]))->assertSessionHasNoErrors();
        $store = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions";

        $this->actingAs($admin)->post($store, $this->operationalPayload('Clash', '2026-08-10 09:30:00', '2026-08-10 10:30:00'))
            ->assertSessionHasErrors('scheduled_start_at');
        $this->assertSame(0, DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->count());

        // Touching the date is no clash, and saving elsewhere then moving onto it is refused too.
        $sessionId = $this->operationalSession($customerId, $cohortId, $admin, '2026-08-10 10:00:00', '2026-08-10 11:00:00');
        $this->actingAs($admin)->put("{$store}/{$sessionId}/schedule", ['scheduled_start_at' => '2026-08-10 09:00:00', 'scheduled_end_at' => '2026-08-10 10:00:00'])
            ->assertSessionHasErrors('scheduled_start_at');
        $this->actingAs($admin)->put("{$store}/{$sessionId}", $this->operationalPayload('Lifecycle class', '2026-08-10 09:00:00', '2026-08-10 10:00:00') + ['apply_schedule' => '1'])
            ->assertSessionHasErrors('scheduled_start_at');
        $this->assertDatabaseHas('core_liveclass_sessions', ['id' => $sessionId, 'scheduled_start_at' => '2026-08-10 10:00:00']);
    }

    public function test_sessions_tab_shows_dates_like_sessions_with_the_class_teachers_and_their_contact(): void
    {
        $this->keepDatesUnsaved();
        Carbon::setTestNow('2026-08-04 12:00:00');
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        $versionId = (int) DB::table('core_course_cohorts')->where('id', $cohortId)->value('version_id');
        $lessonId = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'source_template_lesson_id' => 45001,
            'title_snapshot' => 'Dated Lesson', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_template_version_activities')->insert([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'version_lesson_id' => $lessonId,
            'source_template_activity_id' => 45002, 'title_snapshot' => 'Dated Live', 'activity_type' => 'live_class',
            'completion_rule' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $teacher = $this->createUser($customerId, 'teacher');
        DB::table('users')->where('id', $teacher->id)->update(['phone' => '0900111222']);
        DB::table('core_course_cohort_teachers')->insert([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'teacher_id' => $teacher->id, 'role' => 'primary_teacher',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $date = Carbon::parse('2026-08-10');
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules", $this->validScheduleData([
            'starts_on' => $date->toDateString(), 'ends_on' => $date->toDateString(),
            'slots' => [['weekday' => $date->isoWeekday(), 'start_time' => '09:00', 'end_time' => '10:00']],
        ]))->assertSessionHasNoErrors();

        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")
            ->assertOk()
            ->assertSee('10/08/2026 · 09:00–10:00', false)
            ->assertSeeText($teacher->name)
            ->assertDontSee('ui-cohort-session-tree__slot', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_teacher_contact'))
            ->assertSee('0900111222', false)
            ->assertSee('data-proposed-activity', false)
            ->assertDontSee('data-session-id', false);
    }

    public function test_saving_a_schedule_opens_a_popup_leading_to_the_sessions_it_created(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b, $c] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B', 'Lesson C']);
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules";
        $payload = $this->validScheduleData([
            'starts_on' => '2026-08-10', 'ends_on' => '2026-08-31',
            'slots' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00']],
        ]);

        $response = $this->actingAs($admin)->post($url, $payload)->assertSessionHasNoErrors()->assertSessionMissing('success');
        $ids = DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertCount(3, $ids);
        $response->assertSessionHas('schedule_applied.session_ids', $ids);

        $link = route('admin.course-cohorts.show', [$cohortId, 'tab' => 'sessions', 'applied' => implode(',', $ids)], false);
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules")->assertOk()
            ->assertSee('cohort-schedule-applied-title', false)
            ->assertSeeText(__('lf.LF_course_cohort_schedule_applied_message'))
            ->assertSee(e($link), false);

        // The popup is once only.
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=create")->assertOk()
            ->assertDontSee('cohort-schedule-applied-title', false);

        // Only the sessions named stand out, and only the earliest is brought into view.
        $page = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions&applied={$ids[1]},{$ids[2]},999999")->assertOk()->getContent();
        $this->assertSame(2, substr_count($page, 'course-template-lesson-item is-applied'));
        $this->assertSame(1, substr_count($page, 'data-pager-focus'));
        $this->assertStringContainsString('data-pager-focus', $this->lessonMarkup($page, $b['lesson']));

        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions")->assertOk()
            ->assertDontSee('is-applied', false)->assertDontSee('data-pager-focus', false);
    }

    private function lessonMarkup(string $page, int $lessonId): string
    {
        $start = strpos($page, 'data-version-lesson-id="'.$lessonId.'"');

        return substr($page, (int) $start, 200);
    }

    /** A date without a Session still exists, for example after a cancellation: these tests start from one. */
    private function keepDatesUnsaved(): void
    {
        $this->mock(LiveClassScheduleSessionGenerator::class, fn ($mock) => $mock->shouldReceive('generate')->andReturn([]));
    }

    public function test_saving_a_schedule_creates_sessions_in_programme_order_with_the_class_teachers(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        [$a, $b, $c] = $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B', 'Lesson C']);
        DB::table('core_course_template_version_activities')->where('id', $a['activity'])->update(['live_class_url_snapshot' => 'https://meet.example.test/a']);
        $teacher = $this->createUser($customerId, 'teacher');
        DB::table('core_course_cohort_teachers')->insert([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'teacher_id' => $teacher->id,
            'role' => 'teacher', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules";
        $weekly = fn (string $to, array $extra = []): array => $this->validScheduleData($extra + [
            'starts_on' => '2026-08-10', 'ends_on' => $to,
            'slots' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00']],
        ]);

        // Two dates for three activities: refused, and nothing is stored.
        $this->actingAs($admin)->postJson("{$url}/preview", $weekly('2026-08-17'))->assertOk()->assertJsonPath('missing', 1);
        $this->actingAs($admin)->post($url, $weekly('2026-08-17'))
            ->assertSessionHasErrors(['slots' => __('lf.LF_course_cohort_schedule_not_enough_dates', ['missing' => 1])]);
        $this->assertSame(0, DB::table('core_liveclass_schedules')->where('cohort_id', $cohortId)->count());
        $this->assertSame(0, DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->count());

        // More dates than activities: the last date stays unused.
        $this->actingAs($admin)->postJson("{$url}/preview", $weekly('2026-08-31'))->assertOk()->assertJsonPath('missing', 0);
        $this->actingAs($admin)->post($url, $weekly('2026-08-31'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('schedule_applied.message', __('lf.LF_course_cohort_schedule_applied_message'));

        $sessions = DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->orderBy('scheduled_start_at')->get();
        $this->assertSame([$a['activity'], $b['activity'], $c['activity']], $sessions->map(fn ($row): int => (int) $row->version_activity_id)->all());
        $this->assertSame('Lesson A', $sessions[0]->title);
        $this->assertSame('2026-08-10 09:00:00', $sessions[0]->scheduled_start_at);
        $this->assertSame('https://meet.example.test/a', $sessions[0]->meeting_url_snapshot);
        $this->assertSame('scheduled', $sessions[0]->status);
        $this->assertSame(3, DB::table('core_liveclass_session_schedule_origins')->where('customer_id', $customerId)->count());
        $this->assertSame((int) $teacher->id, (int) DB::table('core_liveclass_session_teachers')->where('session_id', $sessions[0]->id)->value('teacher_id'));

        // Saving again leaves what exists and creates nothing; the form agrees it has enough.
        DB::table('core_liveclass_sessions')->where('id', $sessions[0]->id)->update(['title' => 'Kept']);
        $scheduleId = (int) DB::table('core_liveclass_schedules')->where('cohort_id', $cohortId)->value('id');
        $this->actingAs($admin)->postJson("{$url}/preview", $weekly('2026-08-31', ['editing_schedule_id' => $scheduleId]))->assertOk()->assertJsonPath('missing', 0);
        $this->actingAs($admin)->put("{$url}/{$scheduleId}", $weekly('2026-08-31'))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('success')
            ->assertSessionHas('schedule_applied.message', __('lf.LF_course_cohort_schedule_applied_message'))
            ->assertSessionHas('schedule_applied.session_ids', $sessions->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all());
        $this->assertSame(3, DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->count());
        $this->assertSame('Kept', DB::table('core_liveclass_sessions')->where('id', $sessions[0]->id)->value('title'));
    }

    public function test_a_cancelled_session_needs_a_new_date_when_the_schedule_is_saved_again(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        $this->programme($customerId, $versionId, ['Lesson A']);
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules";
        $weekly = fn (string $to): array => $this->validScheduleData([
            'starts_on' => '2026-08-10', 'ends_on' => $to,
            'slots' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00']],
        ]);
        $this->actingAs($admin)->post($url, $weekly('2026-08-10'))->assertSessionHasNoErrors();
        DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->update(['status' => 'cancelled']);
        $scheduleId = (int) DB::table('core_liveclass_schedules')->where('cohort_id', $cohortId)->value('id');

        $this->actingAs($admin)->put("{$url}/{$scheduleId}", $weekly('2026-08-10'))->assertSessionHasErrors('slots');
        $this->actingAs($admin)->put("{$url}/{$scheduleId}", $weekly('2026-08-17'))->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->where('status', 'scheduled')->count());
    }

    public function test_a_session_moved_from_its_schedule_date_is_marked_and_can_go_back(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        $this->programme($customerId, $versionId, ['Lesson A']);
        $base = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}";
        $this->actingAs($admin)->post("{$base}/schedules", $this->validScheduleData([
            'starts_on' => '2026-08-10', 'ends_on' => '2026-08-10',
            'slots' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00']],
        ]))->assertSessionHasNoErrors();
        $session = DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->first();
        $label = __('lf.LF_course_cohort_session_date_edited');
        $marked = '<span class="ui-status ui-status--draft ui-cohort-session-edited"';
        $this->actingAs($admin)->get("{$base}?tab=sessions")->assertOk()->assertDontSee($marked, false)->assertSee('2026-08-10T09:00', false);

        $save = fn (string $start, string $end) => $this->actingAs($admin)->put("{$base}/sessions/{$session->id}", [
            'session_type' => 'curriculum', 'title' => $session->title, 'delivery_mode' => 'online',
            'version_lesson_id' => $session->version_lesson_id, 'version_activity_id' => $session->version_activity_id,
            'apply_schedule' => '1', 'scheduled_start_at' => $start, 'scheduled_end_at' => $end,
        ])->assertSessionHasNoErrors();

        $save('2026-08-11 14:00:00', '2026-08-11 15:00:00');
        $this->actingAs($admin)->get("{$base}?tab=sessions")->assertOk()->assertSee($marked, false)->assertSeeText($label);

        $save('2026-08-10 09:00:00', '2026-08-10 10:00:00');
        $this->actingAs($admin)->get("{$base}?tab=sessions")->assertOk()->assertDontSee($marked, false);
    }

    public function test_two_schedules_at_the_same_time_do_not_create_overlapping_sessions(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B']);
        $earlier = DB::table('core_liveclass_schedules')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'name' => 'Earlier', 'starts_on' => '2026-08-10', 'ends_on' => '2026-08-10',
            'timezone' => config('app.timezone'), 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_liveclass_schedule_slots')->insert([
            'customer_id' => $customerId, 'schedule_id' => $earlier, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00',
            'sort_order' => 1, 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules", $this->validScheduleData([
            'starts_on' => '2026-08-10', 'ends_on' => '2026-08-17',
            'slots' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['2026-08-10 09:00:00', '2026-08-17 09:00:00'], DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->orderBy('scheduled_start_at')->pluck('scheduled_start_at')->all());
    }

    public function test_a_class_teacher_added_later_joins_upcoming_sessions_without_a_team(): void
    {
        [$customerId, $admin, $cohortId, $versionId] = $this->reorderCohort();
        $this->programme($customerId, $versionId, ['Lesson A', 'Lesson B']);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules", $this->validScheduleData([
            'starts_on' => '2026-08-10', 'ends_on' => '2026-08-17',
            'slots' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '10:00']],
        ]))->assertSessionHasNoErrors();
        $sessions = DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->orderBy('scheduled_start_at')->pluck('id');
        $this->assertCount(2, $sessions);
        $this->assertSame(0, DB::table('core_liveclass_session_teachers')->whereIn('session_id', $sessions)->count());

        $teacher = $this->createUser($customerId, 'teacher');
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/teachers", [
            'teacher_id' => $teacher->id, 'role' => 'teacher', 'assigned_from' => '2026-08-15',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('core_liveclass_session_teachers')->where('session_id', $sessions[0])->count(), 'before the teacher starts');
        $this->assertSame((int) $teacher->id, (int) DB::table('core_liveclass_session_teachers')->where('session_id', $sessions[1])->value('teacher_id'));
    }

    private function operationalPayload(string $title, string $start, string $end): array
    {
        return [
            'title' => $title, 'session_type' => 'operational', 'delivery_mode' => 'online',
            'scheduled_start_at' => $start, 'scheduled_end_at' => $end,
        ];
    }

    private function operationalSession(int $customerId, int $cohortId, User $admin, string $start, string $end): int
    {
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions",
            $this->operationalPayload('Lifecycle class', $start, $end)
        )->assertSessionHasNoErrors();

        return (int) DB::table('core_liveclass_sessions')
            ->where('customer_id', $customerId)->where('cohort_id', $cohortId)
            ->where('title', 'Lifecycle class')->value('id');
    }

    public function test_attendance_and_recording_are_locked_at_the_server_while_unfinished(): void
    {
        // Mặc định của config/liveclass.php là khóa. Test này không bật cờ.
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $productId = $this->createProduct($customerId, 'Locked Feature', 'locked-feature');
        $versionId = $this->createVersion($customerId, $admin->id, 'Locked Feature Version');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId);
        DB::table('core_course_cohorts')->where('id', $cohortId)
            ->update(['product_id' => $productId, 'version_id' => $versionId]);
        DB::table('core_course_cohort_teachers')->insert([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'teacher_id' => $teacher->id,
            'role' => 'teacher', 'status' => 'active',
            'assigned_from' => '2026-08-01', 'assigned_to' => '2026-08-31',
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Buổi học đã kết thúc: mọi điều kiện lifecycle cho điểm danh và bản ghi
        // đều thỏa, nên thứ duy nhất chặn được là cờ tính năng.
        $sessionId = DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId,
            'template_version_id' => $versionId, 'session_type' => 'operational',
            'title' => 'Finished class', 'session_no' => 1, 'delivery_mode' => 'online',
            'scheduled_start_at' => '2026-07-01 19:00:00', 'scheduled_end_at' => '2026-07-01 21:00:00',
            'timezone' => 'Asia/Ho_Chi_Minh', 'status' => 'scheduled',
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_liveclass_session_teachers')->insert([
            'customer_id' => $customerId, 'session_id' => $sessionId, 'teacher_id' => $teacher->id,
            'role' => 'teacher', 'created_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = $this->createUser($customerId, 'student');
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);

        // Request trực tiếp bị chặn ở backend, không chỉ ẩn trên UI.
        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/attendance",
            ['attendance' => [['enrollment_id' => $enrollmentId, 'status' => 'present']]]
        )->assertForbidden();
        $this->assertDatabaseCount('core_liveclass_attendances', 0);

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/recordings",
            ['title' => 'Blocked recording', 'recording_url' => 'https://example.test/rec']
        )->assertForbidden();
        $this->assertDatabaseCount('core_liveclass_recordings', 0);

        // Deferred tabs are omitted from navigation; direct access still falls back to Overview.
        $detail = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=attendance")
            ->assertOk()
            ->assertViewHas('activeTab', 'overview')
            ->assertViewHas('cohortTabs', function (array $tabs): bool {
                $attendance = collect($tabs)->firstWhere('key', 'attendance');
                $recordings = collect($tabs)->firstWhere('key', 'recordings');

                return ! $attendance['accessible'] && ! $recordings['accessible']
                    && $attendance['locked_reason'] === __('lf.LF_course_cohort_tab_attendance_locked_development')
                    && $recordings['locked_reason'] === __('lf.LF_course_cohort_tab_recordings_locked_development');
            })
            ->assertDontSee('tab=attendance', false)
            ->assertDontSee('tab=recordings', false);
        $detail->assertDontSeeText(__('lf.LF_course_cohort_attendance_save'));

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=recordings")
            ->assertOk()
            ->assertViewHas('activeTab', 'overview')
            ->assertDontSeeText(__('lf.LF_course_cohort_recording_add'));

        // Bật cờ thì cùng request đó hoạt động trở lại — khóa là tạm thời và
        // hoàn toàn nằm ở cấu hình.
        config(['liveclass.attendance_enabled' => true]);
        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/attendance",
            ['attendance' => [['enrollment_id' => $enrollmentId, 'status' => 'present']]]
        )->assertRedirect();
        $this->assertDatabaseCount('core_liveclass_attendances', 1);
    }

    public function test_editing_a_cohort_teacher_changes_role_and_period_but_never_session_teams(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $primary = $this->createUser($customerId, 'teacher');
        $versionId = $this->createVersion($customerId, $admin->id, 'Teacher Edit Version');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId);
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['version_id' => $versionId]);
        $cohort = DB::table('core_course_cohorts')->where('id', $cohortId)->first();

        $assign = fn (object $user, string $role): int => DB::table('core_course_cohort_teachers')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId,
            'teacher_id' => $user->id, 'role' => $role, 'status' => 'active',
            'assigned_from' => $cohort->start_date, 'assigned_to' => $cohort->end_date,
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $teacherAssignment = $assign($teacher, 'teacher');
        $primaryAssignment = $assign($primary, 'primary_teacher');
        $sessionId = DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId,
            'template_version_id' => $versionId, 'session_type' => 'operational',
            'title' => 'Upcoming class', 'session_no' => 7, 'delivery_mode' => 'online',
            'scheduled_start_at' => '2026-08-10 19:00:00', 'scheduled_end_at' => '2026-08-10 21:00:00',
            'timezone' => 'Asia/Ho_Chi_Minh', 'status' => 'scheduled',
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_liveclass_session_teachers')->insert([
            'customer_id' => $customerId, 'session_id' => $sessionId,
            'teacher_id' => $teacher->id, 'role' => 'teacher',
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/teachers";
        $tab = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=teachers";

        $this->actingAs($admin)->get($tab)->assertOk()
            ->assertSee('id="cohort-teacher-edit-'.$teacherAssignment.'"', false)
            ->assertSeeText(__('lf.LF_course_cohort_teacher_edit'));

        // Khoảng mới bỏ rơi buổi sắp diễn ra: từ chối, nêu tên buổi, mở lại đúng popup, không ghi gì.
        $this->followingRedirects()->actingAs($admin)->from($tab)->put("{$url}/{$teacherAssignment}", [
            '_form' => 'teacher_edit_'.$teacherAssignment,
            'role' => 'assistant', 'assigned_from' => '2026-08-11', 'assigned_to' => null,
        ])->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_teacher_period_blocked'))
            ->assertSeeText('#7 Upcoming class');
        $this->assertDatabaseHas('core_course_cohort_teachers', ['id' => $teacherAssignment, 'role' => 'teacher']);

        // Khoảng vẫn bao buổi đó: lưu được; đội ngũ của buổi giữ nguyên.
        $this->actingAs($admin)->from($tab)->put("{$url}/{$teacherAssignment}", [
            'role' => 'assistant', 'assigned_from' => '2026-08-10', 'assigned_to' => '2026-08-10',
        ])->assertSessionHasNoErrors();
        $row = DB::table('core_course_cohort_teachers')->where('id', $teacherAssignment)->first();
        $this->assertSame('assistant', $row->role);
        $this->assertSame('2026-08-10', substr((string) $row->assigned_from, 0, 10));
        $this->assertSame('2026-08-10', substr((string) $row->assigned_to, 0, 10));
        $this->assertSame((int) $teacher->id, (int) $row->teacher_id);
        $this->assertDatabaseHas('core_liveclass_session_teachers', [
            'session_id' => $sessionId, 'teacher_id' => $teacher->id, 'role' => 'teacher',
        ]);

        // Ngoài thời gian vận hành: bị từ chối ở túi lỗi riêng của popup sửa.
        $this->actingAs($admin)->from($tab)->put("{$url}/{$teacherAssignment}", [
            'role' => 'teacher', 'assigned_from' => '2020-01-01', 'assigned_to' => null,
        ])->assertSessionHasErrors(['assigned_from'], null, 'teacherEdit');

        // Lên giáo viên chính: khoảng theo lớp, người chính cũ về Giáo viên và giữ khoảng của mình.
        $this->actingAs($admin)->from($tab)->put("{$url}/{$teacherAssignment}", [
            'role' => 'primary_teacher', 'assigned_from' => '2026-08-10', 'assigned_to' => '2026-08-10',
        ])->assertSessionHasNoErrors();
        $promoted = DB::table('core_course_cohort_teachers')->where('id', $teacherAssignment)->first();
        $this->assertSame('primary_teacher', $promoted->role);
        $this->assertSame(substr((string) $cohort->start_date, 0, 10), substr((string) $promoted->assigned_from, 0, 10));
        $this->assertSame(substr((string) $cohort->end_date, 0, 10), substr((string) $promoted->assigned_to, 0, 10));
        $this->assertDatabaseHas('core_course_cohort_teachers', ['id' => $primaryAssignment, 'role' => 'teacher', 'status' => 'active']);
        $this->assertSame(1, DB::table('core_course_cohort_teachers')
            ->where('cohort_id', $cohortId)->where('role', 'primary_teacher')->where('status', 'active')->count());

        // Dòng đã gỡ, lớp khác và người không phải quản trị đều không sửa được.
        DB::table('core_course_cohort_teachers')->where('id', $primaryAssignment)->update(['status' => 'inactive']);
        $this->actingAs($admin)->put("{$url}/{$primaryAssignment}", ['role' => 'assistant'])->assertNotFound();
        $otherCohortId = $this->createCohortWithOperatingPeriod($customerId);
        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-cohorts/{$otherCohortId}/teachers/{$teacherAssignment}",
            ['role' => 'assistant']
        )->assertNotFound();
        $this->actingAs($teacher)->put("{$url}/{$teacherAssignment}", ['role' => 'assistant'])->assertForbidden();
        $this->assertDatabaseHas('core_course_cohort_teachers', ['id' => $teacherAssignment, 'role' => 'primary_teacher']);
    }

    public function test_removing_a_cohort_teacher_is_blocked_by_upcoming_sessions_but_not_by_history(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $historyTeacher = $this->createUser($customerId, 'teacher');
        $idleTeacher = $this->createUser($customerId, 'teacher');
        $productId = $this->createProduct($customerId, 'Teacher Removal', 'teacher-removal');
        $versionId = $this->createVersion($customerId, $admin->id, 'Teacher Removal Version');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId);
        DB::table('core_course_cohorts')->where('id', $cohortId)
            ->update(['product_id' => $productId, 'version_id' => $versionId]);

        $assignmentIds = [];
        foreach ([$teacher, $historyTeacher, $idleTeacher] as $assignable) {
            $assignmentIds[$assignable->id] = DB::table('core_course_cohort_teachers')->insertGetId([
                'customer_id' => $customerId, 'cohort_id' => $cohortId,
                'teacher_id' => $assignable->id, 'role' => 'teacher', 'status' => 'active',
                'assigned_from' => '2026-08-01', 'assigned_to' => '2026-08-31',
                'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $makeSession = function (string $title, int $no, string $start, string $end, int $teacherId) use ($customerId, $cohortId, $versionId, $admin): int {
            $sessionId = DB::table('core_liveclass_sessions')->insertGetId([
                'customer_id' => $customerId, 'cohort_id' => $cohortId,
                'template_version_id' => $versionId, 'session_type' => 'operational',
                'title' => $title, 'session_no' => $no, 'delivery_mode' => 'online',
                'scheduled_start_at' => $start, 'scheduled_end_at' => $end,
                'timezone' => 'Asia/Ho_Chi_Minh', 'status' => 'scheduled',
                'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('core_liveclass_session_teachers')->insert([
                'customer_id' => $customerId, 'session_id' => $sessionId,
                'teacher_id' => $teacherId, 'role' => 'teacher',
                'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $sessionId;
        };

        // now() trong bộ test là 2026-07-04, nên buổi tháng 8 là "sắp diễn ra".
        $upcomingSessionId = $makeSession('Upcoming class', 1, '2026-08-10 19:00:00', '2026-08-10 21:00:00', $teacher->id);
        $additionalUpcomingSessionIds = [
            $makeSession('Second upcoming class', 3, '2026-08-12 19:00:00', '2026-08-12 21:00:00', $teacher->id),
            $makeSession('Third upcoming class', 4, '2026-08-14 19:00:00', '2026-08-14 21:00:00', $teacher->id),
            $makeSession('Fourth upcoming class', 5, '2026-08-16 19:00:00', '2026-08-16 21:00:00', $teacher->id),
        ];
        // Buổi đã diễn ra: assignment là bằng chứng lịch sử, không được chặn.
        $makeSession('Past class', 2, '2026-06-10 19:00:00', '2026-06-10 21:00:00', $historyTeacher->id);

        $blockedResponse = $this->followingRedirects()->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=teachers")
            ->delete(
                "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/teachers/{$assignmentIds[$teacher->id]}"
            )->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_teacher_remove_blocked_title', ['name' => $teacher->name]))
            ->assertSeeText('#1 Upcoming class')
            ->assertSeeText('#5 Fourth upcoming class')
            ->assertSeeText(trans_choice('lf.LF_course_cohort_teacher_remove_blocked_count', 4, ['count' => 4]))
            ->assertSeeText(__('lf.LF_course_cohort_teacher_remove_show_more', ['count' => 1]))
            ->assertSeeText(__('lf.LF_course_cohort_teacher_remove_collapse'))
            ->assertSeeText(__('lf.LF_course_cohort_teacher_remove_reassign', ['count' => 4]))
            ->assertSee('tab=sessions', false);
        $this->assertDatabaseHas('core_course_cohort_teachers', [
            'id' => $assignmentIds[$teacher->id], 'status' => 'active',
        ]);
        $this->assertDatabaseHas('core_liveclass_session_teachers', [
            'session_id' => $upcomingSessionId, 'teacher_id' => $teacher->id,
        ]);

        $this->assertLessThan(
            strpos($blockedResponse->getContent(), 'data-for="cohort-teacher-row-'.$assignmentIds[$teacher->id].'"'),
            strpos($blockedResponse->getContent(), 'id="cohort-teacher-row-'.$assignmentIds[$teacher->id].'"')
        );

        // Giáo viên chỉ còn dính buổi quá khứ vẫn gỡ được, và dòng lịch sử ở
        // cấp Session không bị xóa theo.
        $this->actingAs($admin)->delete(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/teachers/{$assignmentIds[$historyTeacher->id]}"
        )->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_cohort_teachers', [
            'id' => $assignmentIds[$historyTeacher->id], 'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('core_liveclass_session_teachers', ['teacher_id' => $historyTeacher->id]);

        // Giáo viên chưa phụ trách buổi nào: hành vi cũ được giữ nguyên.
        $this->actingAs($admin)->delete(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/teachers/{$assignmentIds[$idleTeacher->id]}"
        )->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_cohort_teachers', [
            'id' => $assignmentIds[$idleTeacher->id], 'status' => 'inactive',
        ]);

        // Buổi sắp diễn ra nhưng đã có evidence cũng không chặn: dòng phân công
        // lúc đó đã là lịch sử vận hành.
        DB::table('core_liveclass_recordings')->insert([
            'customer_id' => $customerId, 'session_id' => $upcomingSessionId,
            'title' => 'Evidence recording', 'status' => 'processing', 'visibility' => 'cohort',
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_liveclass_sessions')->whereIn('id', $additionalUpcomingSessionIds)
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
        $this->actingAs($admin)->delete(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/teachers/{$assignmentIds[$teacher->id]}"
        )->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_cohort_teachers', [
            'id' => $assignmentIds[$teacher->id], 'status' => 'inactive',
        ]);
    }

    public function test_failed_session_edit_reopens_that_session_and_never_the_create_form(): void
    {
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        $sessionId = $this->operationalSession($customerId, $cohortId, $admin, '2026-08-10 19:00:00', '2026-08-10 21:00:00');
        $tab = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions";

        // Trước đây lỗi lúc sửa mở lại form ở chế độ tạo mới: lưu lần nữa sẽ sinh buổi trùng.
        $this->actingAs($admin)->from($tab)->followingRedirects()->put(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}",
            array_merge($this->operationalPayload('Edited', '2026-08-10 19:00:00', '2026-08-10 21:00:00'), [
                'meeting_url' => 'not-a-url', 'form_context' => 'session', 'editing_session_id' => $sessionId,
            ])
        )->assertOk()
            ->assertSee('editingId: '.$sessionId.',', false)
            ->assertSee('else if (true) this.showSessionDialog()', false);

        // Lỗi lifecycle hiện ở đầu trang và không được bật popup tạo buổi.
        $this->actingAs($admin)->from($tab)->followingRedirects()
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/complete")
            ->assertOk()
            ->assertSee('editingId: null,', false)
            ->assertSee('else if (false) this.showSessionDialog()', false);
    }

    public function test_rescheduling_to_the_same_interval_is_rejected_without_an_audit_row(): void
    {
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        $sessionId = $this->operationalSession($customerId, $cohortId, $admin, '2026-08-10 19:00:00', '2026-08-10 21:00:00');

        $this->actingAs($admin)->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions/{$sessionId}/schedule", [
            'scheduled_start_at' => '2026-08-10T19:00',
            'scheduled_end_at' => '2026-08-10T21:00',
        ])->assertSessionHasErrors(['scheduled_start_at' => __('lf.LF_course_cohort_session_reschedule_unchanged')]);

        $this->assertSame(0, DB::table('core_liveclass_session_schedule_changes')->where('session_id', $sessionId)->count());
    }

    public function test_sessions_tab_has_no_create_button_and_says_when_the_class_cannot_take_a_session(): void
    {
        [, $admin, $cohortId] = $this->lifecycleCohort();
        $tab = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=sessions";
        $createButton = 'x-on:click="openCreate()"';
        $this->actingAs($admin)->get($tab)->assertOk()->assertDontSee($createButton, false);

        $cohort = DB::table('core_course_cohorts')->where('id', $cohortId);
        $versionId = $cohort->value('version_id');
        $periodEnd = $cohort->value('end_date');
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'start_date' => now()->subMonths(2)->toDateString(), 'end_date' => now()->subMonth()->toDateString(),
        ]);
        $this->actingAs($admin)->get($tab)->assertOk()
            ->assertDontSee($createButton, false)
            ->assertSeeText(__('lf.LF_course_cohort_session_window_passed'));

        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['end_date' => $periodEnd]);
        DB::table('core_course_template_versions')->where('id', $versionId)->update(['course_type_snapshot' => 'self_paced']);
        $this->actingAs($admin)->get($tab)->assertOk()
            ->assertDontSee($createButton, false)
            ->assertSeeText(__('lf.LF_course_type_cohort_legacy_self_paced'));
    }

    public function test_planned_occurrences_are_chronological_and_used_rows_do_not_hide_open_ones(): void
    {
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'start_date' => now()->toDateString(), 'end_date' => now()->addMonths(2)->toDateString(),
        ]);
        $start = now()->startOfWeek()->addWeek();
        $slot = fn (int $scheduleId, string $time): int => DB::table('core_liveclass_schedule_slots')->insertGetId([
            'customer_id' => $customerId, 'schedule_id' => $scheduleId, 'weekday' => 1,
            'start_time' => $time, 'end_time' => '23:00:00', 'sort_order' => 1,
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $schedule = fn (string $name, int $weeks): int => DB::table('core_liveclass_schedules')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'name' => $name,
            'starts_on' => $start->toDateString(), 'ends_on' => $start->copy()->addWeeks($weeks)->toDateString(),
            'timezone' => 'Asia/Ho_Chi_Minh', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $evening = $schedule('Evening', 2);
        $eveningSlot = $slot($evening, '19:00:00');
        $slot($schedule('Morning', 2), '08:00:00');

        $sessionId = $this->operationalSession($customerId, $cohortId, $admin, $start->format('Y-m-d').' 19:00:00', $start->format('Y-m-d').' 21:00:00');
        DB::table('core_liveclass_session_schedule_origins')->insert([
            'customer_id' => $customerId, 'session_id' => $sessionId, 'schedule_id' => $evening, 'schedule_slot_id' => $eveningSlot,
            'source_local_date' => $start->toDateString(), 'source_local_start_time' => '19:00:00', 'source_local_end_time' => '23:00:00',
            'source_timezone' => 'Asia/Ho_Chi_Minh', 'source_start_at' => $start->format('Y-m-d').' 12:00:00', 'source_end_at' => $start->format('Y-m-d').' 16:00:00',
            'created_by' => $admin->id, 'created_at' => now(),
        ]);

        $model = app(LiveClassCohortSessionReadModel::class);
        $rows = $model->plannedOccurrences($customerId, $cohortId);
        $keys = $rows->map(fn (array $row): string => $row['date'].' '.$row['start_time'])->all();
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys, 'Hai lịch đan xen vẫn phải theo thứ tự thời gian.');

        // Giới hạn 2 dòng: dòng đã tạo buổi không được chiếm chỗ của dòng còn mở.
        $limited = $model->plannedOccurrences($customerId, $cohortId, 2);
        $this->assertCount(2, $limited);
        $this->assertFalse($limited->contains(fn (array $row): bool => $row['consumed']));
    }

    public function test_overlapping_slots_report_the_error_on_their_own_group(): void
    {
        [, $admin, $cohortId] = $this->lifecycleCohort();
        $tab = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules";
        $response = $this->actingAs($admin)->from($tab)->followingRedirects()->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/schedules",
            $this->validScheduleData([
                '_form' => 'schedule_create',
                'slots' => [
                    ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '11:00'],
                    ['weekday' => 1, 'start_time' => '10:00', 'end_time' => '12:00'],
                ],
            ])
        )->assertOk()->assertSee('slotConflict(slot)', false);

        $this->assertDatabaseCount('core_liveclass_schedules', 0);
        // Không còn hiện ở khối lỗi đầu popup: lỗi nằm trong slotsError của nhóm khung giờ.
        $this->assertDoesNotMatchRegularExpression('/ui-cohort-manage-error[^>]*>\\s*<p>/', $response->getContent());
        $this->assertStringNotContainsString("slotsError: ''", $response->getContent());
    }

    public function test_schedule_list_shows_created_sessions_against_the_plan(): void
    {
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'start_date' => now()->toDateString(), 'end_date' => now()->addMonths(3)->toDateString(),
        ]);
        $monday = now()->addMonth()->startOfWeek();
        $scheduleId = DB::table('core_liveclass_schedules')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'name' => 'Progress plan',
            'starts_on' => $monday->toDateString(), 'ends_on' => $monday->copy()->addDays(13)->toDateString(),
            'timezone' => config('app.timezone'), 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $slot = fn (int $weekday, string $start, string $end): int => DB::table('core_liveclass_schedule_slots')->insertGetId([
            'customer_id' => $customerId, 'schedule_id' => $scheduleId, 'weekday' => $weekday, 'start_time' => $start,
            'end_time' => $end, 'sort_order' => $weekday, 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mondaySlot = $slot(1, '09:00:00', '10:00:00');
        $slot(1, '14:00:00', '15:00:00');
        foreach ([2, 3, 4, 5] as $weekday) {
            $slot($weekday, '09:00:00', '10:00:00');
        }
        $origin = fn (string $date, int $sessionId): bool => DB::table('core_liveclass_session_schedule_origins')->insert([
            'customer_id' => $customerId, 'session_id' => $sessionId, 'schedule_id' => $scheduleId, 'schedule_slot_id' => $mondaySlot,
            'source_local_date' => $date, 'source_local_start_time' => '09:00:00', 'source_local_end_time' => '10:00:00',
            'source_timezone' => config('app.timezone'), 'source_start_at' => $date.' 02:00:00', 'source_end_at' => $date.' 03:00:00',
            'created_by' => $admin->id, 'created_at' => now(),
        ]);
        $date = $monday->toDateString();
        $origin($date, $this->operationalSession($customerId, $cohortId, $admin, "$date 09:00:00", "$date 10:00:00"));
        // Ngày này nằm ngoài kế hoạch hiện tại nên không được tính vào tiến độ.
        $outside = $monday->copy()->addDays(21)->toDateString();
        $this->operationalSession($customerId, $cohortId, $admin, "$outside 09:00:00", "$outside 10:00:00");
        $origin($outside, (int) DB::table('core_liveclass_sessions')->where('cohort_id', $cohortId)->max('id'));

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules")
            ->assertOk()
            ->assertSeeText('1/12')
            ->assertSeeText(__('lf.LF_course_cohort_schedule_list_sessions_progress', ['created' => 1, 'planned' => 12]))
            // Hai giờ của Thứ Hai gộp một dòng; 5 thứ thì thứ thứ năm thu vào "+1".
            ->assertSeeInOrder(['09:00–10:00,', '14:00–15:00'])
            ->assertSeeText(__('lf.LF_course_cohort_schedule_more_slots', ['count' => 1]))
            ->assertDontSee('<span class="ui-caption ui-framework-description">'.config('app.timezone').'</span>', false);
    }

    public function test_schedule_detail_shows_excluded_occurrences_where_they_fall(): void
    {
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        $monday = now()->addMonth()->startOfWeek();
        $scheduleId = DB::table('core_liveclass_schedules')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'name' => 'Plan view',
            'starts_on' => $monday->toDateString(), 'ends_on' => $monday->copy()->addDays(13)->toDateString(),
            'timezone' => 'Asia/Ho_Chi_Minh', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_liveclass_schedule_slots')->insert([
            'customer_id' => $customerId, 'schedule_id' => $scheduleId, 'weekday' => 1, 'start_time' => '09:00:00',
            'end_time' => '10:30:00', 'sort_order' => 1, 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_liveclass_schedule_exclusions')->insert([
            ['customer_id' => $customerId, 'schedule_id' => $scheduleId, 'excluded_on' => $monday->toDateString(), 'reason' => 'Public holiday', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()],
            ['customer_id' => $customerId, 'schedule_id' => $scheduleId, 'excluded_on' => $monday->copy()->addDay()->toDateString(), 'reason' => 'No class anyway', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $page = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=view&schedule_id={$scheduleId}")
            ->assertOk()
            // Hai tuần, tuần đầu bị loại trừ: còn 1 buổi nhưng ngày nghỉ vẫn hiện đúng chỗ.
            ->assertSeeText(trans_choice('lf.LF_course_cohort_schedule_detail_sessions', 1, ['count' => 1]))
            ->assertSeeText('Public holiday')
            ->assertSeeText(trans_choice('lf.LF_course_cohort_schedule_detail_exclusion_hits', 1, ['count' => 1]))
            ->assertSeeText(__('lf.LF_course_cohort_schedule_exclusion_no_session'))
            ->assertSeeText(__('lf.LF_course_cohort_schedule_detail_duration_hours_minutes', ['hours' => 1, 'minutes' => 30]))
            ->assertSee('ui-cohort-schedule-detail__listrow is-excluded', false)
            // Lịch chưa áp dụng thì không có buổi tiếp theo.
            ->assertDontSee('ui-cohort-schedule-detail__listrow is-next', false);

        $this->assertSame(2, substr_count($page->getContent(), 'ui-cohort-schedule-detail__listrow'));

        // Lịch vắt qua hai tháng có hai lưới tháng để chuyển qua lại.
        $firstOfMonth = now()->addMonths(2)->startOfMonth();
        DB::table('core_liveclass_schedules')->where('id', $scheduleId)->update([
            'starts_on' => $firstOfMonth->copy()->addDays(20)->toDateString(),
            'ends_on' => $firstOfMonth->copy()->addMonth()->addDays(5)->toDateString(),
        ]);
        $crossing = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=schedules&schedule_form=view&schedule_id={$scheduleId}")
            ->assertOk()
            ->assertSee('ui-cohort-schedule-detail__day is-out', false);
        $this->assertSame(2, substr_count($crossing->getContent(), 'class="ui-cohort-schedule-detail__calendar"'));
    }

    public function test_past_unassigned_session_of_a_draft_class_can_only_receive_teachers(): void
    {
        [$customerId, $admin, $cohortId] = $this->lifecycleCohort();
        $teacher = $this->createUser($customerId, 'teacher');
        DB::table('core_course_cohort_teachers')->insert([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'teacher_id' => $teacher->id, 'role' => 'teacher',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_cohorts')->where('id', $cohortId)->update([
            'status' => 'draft', 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
        ]);
        $sessionId = DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'session_no' => 1, 'session_type' => 'operational',
            'template_version_id' => DB::table('core_course_cohorts')->where('id', $cohortId)->value('version_id'),
            'title' => 'Past class', 'delivery_mode' => 'online', 'status' => 'scheduled', 'timezone' => config('app.timezone'),
            'scheduled_start_at' => now()->subDays(2)->format('Y-m-d H:i:s'),
            'scheduled_end_at' => now()->subDays(2)->addHour()->format('Y-m-d H:i:s'),
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $base = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}";
        $url = "{$base}/sessions/{$sessionId}/teachers";

        $this->actingAs($admin)->get("{$base}?tab=sessions")->assertOk()->assertSee('x-on:click="openAssign(', false);
        $this->actingAs($admin)->put($url, ['teacher_ids' => []])->assertSessionHasErrors('teacher_ids');

        // Lớp đang hoạt động không thuộc ngoại lệ này.
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['status' => 'active']);
        $this->actingAs($admin)->put($url, ['teacher_ids' => [$teacher->id]])->assertStatus(422);
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['status' => 'draft']);

        $before = DB::table('core_liveclass_sessions')->where('id', $sessionId)->first();
        // Trường khác gửi kèm không được ghi vào buổi.
        $this->actingAs($admin)->put($url, ['teacher_ids' => [$teacher->id, $teacher->id], 'title' => 'Hacked', 'scheduled_start_at' => '2030-01-01 00:00:00'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('lf.LF_course_cohort_session_assign_teachers_saved'));
        $after = DB::table('core_liveclass_sessions')->where('id', $sessionId)->first();
        $this->assertSame('Past class', $after->title);
        $this->assertSame($before->scheduled_start_at, $after->scheduled_start_at);
        $this->assertSame(1, DB::table('core_liveclass_session_teachers')->where('session_id', $sessionId)->where('teacher_id', $teacher->id)->count());

        // Đã có giáo viên thì hành động đóng lại, kể cả khi gọi trực tiếp.
        $this->actingAs($admin)->get("{$base}?tab=sessions")->assertOk()->assertDontSee('x-on:click="openAssign(', false);
        $this->actingAs($admin)->put($url, ['teacher_ids' => [$teacher->id]])->assertStatus(422);
    }

    public function test_session_overlap_is_evaluated_across_different_session_timezones(): void
    {
        // Session lưu giờ địa phương theo cột `timezone` của chính nó, nên hai
        // Session trong cùng một Cohort có thể nằm ở hai múi giờ khác nhau. So
        // sánh chuỗi thô sẽ sai theo CẢ HAI chiều; test này khóa cả hai chiều.
        //
        // Buổi A: Schedule Asia/Tokyo (UTC+9), thứ Hai 09:00–11:00 → 00:00–02:00 UTC.
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Cross TZ Product', 'cross-tz-product');
        $versionId = $this->createVersion($customerId, $admin->id, 'Cross TZ Version');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId);
        DB::table('core_course_cohorts')->where('id', $cohortId)
            ->update(['product_id' => $productId, 'version_id' => $versionId]);
        $lessonId = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId,
            'source_template_lesson_id' => 41001, 'title_snapshot' => 'Cross TZ Lesson',
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $activityId = DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId,
            'version_lesson_id' => $lessonId, 'source_template_activity_id' => 41101,
            'title_snapshot' => 'Cross TZ Live', 'activity_type' => 'live_class',
            'completion_rule' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $scheduleId = DB::table('core_liveclass_schedules')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'name' => 'Tokyo schedule',
            'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31', 'timezone' => 'Asia/Tokyo',
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $slotId = DB::table('core_liveclass_schedule_slots')->insertGetId([
            'customer_id' => $customerId, 'schedule_id' => $scheduleId, 'weekday' => 1,
            'start_time' => '09:00:00', 'end_time' => '11:00:00', 'sort_order' => 1,
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $payload = fn (string $title, array $overrides = []): array => array_merge([
            'title' => $title, 'session_type' => 'curriculum',
            'version_lesson_id' => $lessonId, 'version_activity_id' => $activityId,
            'delivery_mode' => 'online',
            'scheduled_start_at' => '2026-08-03 09:00:00',
            'scheduled_end_at' => '2026-08-03 11:00:00',
        ], $overrides);

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions",
            $payload('Tokyo session', [
                'schedule_id' => $scheduleId, 'schedule_slot_id' => $slotId,
                'source_local_date' => '2026-08-03',
            ])
        )->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'title' => 'Tokyo session', 'timezone' => 'Asia/Tokyo',
            'scheduled_start_at' => '2026-08-03 09:00:00',
        ]);

        // Chiều 1 — buổi KHÔNG chồng lấn nhưng so chuỗi thô sẽ từ chối nhầm.
        // 09:30–10:30 giờ VN = 02:30–03:30 UTC, bắt đầu sau khi buổi A kết thúc.
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions",
            $payload('Later Hanoi session', [
                'scheduled_start_at' => '2026-08-03 09:30:00',
                'scheduled_end_at' => '2026-08-03 10:30:00',
            ])
        )->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_sessions', [
            'title' => 'Later Hanoi session', 'timezone' => 'Asia/Ho_Chi_Minh',
        ]);

        // Chiều 2 — buổi CHỒNG LẤN thật nhưng so chuỗi thô sẽ chấp nhận nhầm,
        // vì '09:00' (kết thúc) không lớn hơn '09:00' (bắt đầu của buổi A).
        // 08:00–09:00 giờ VN = 01:00–02:00 UTC, nằm trọn trong 00:00–02:00 UTC.
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions",
            $payload('Overlapping Hanoi session', [
                'scheduled_start_at' => '2026-08-03 08:00:00',
                'scheduled_end_at' => '2026-08-03 09:00:00',
            ])
        )->assertSessionHasErrors([
            'scheduled_start_at' => __('lf.LF_course_cohort_session_schedule_overlap'),
        ]);
        $this->assertDatabaseMissing('core_liveclass_sessions', ['title' => 'Overlapping Hanoi session']);

        // Ranh giới chạm nhau vẫn hợp lệ: 09:00–09:30 VN = 02:00–02:30 UTC,
        // bắt đầu đúng lúc buổi Tokyo kết thúc và kết thúc đúng lúc buổi
        // "Later Hanoi" bắt đầu — chạm cả hai đầu, không chồng đầu nào.
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/sessions",
            $payload('Boundary Hanoi session', [
                'scheduled_start_at' => '2026-08-03 09:00:00',
                'scheduled_end_at' => '2026-08-03 09:30:00',
            ])
        )->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_liveclass_sessions', ['title' => 'Boundary Hanoi session']);
    }

    public function test_cohort_roster_and_attendance_export_stay_inside_the_tenant_and_the_tab(): void
    {
        config(['liveclass.attendance_enabled' => true]);
        Carbon::setTestNow('2026-08-12 12:00:00');

        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $productId = $this->createProduct($customerId, 'Export Product', 'export-product');
        $versionId = $this->createVersion($customerId, $admin->id, 'Export Version');
        $cohortId = $this->createCohortWithOperatingPeriod($customerId);
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['product_id' => $productId, 'version_id' => $versionId]);
        $enrol = function (string $name, string $phone, string $membershipStatus = 'active') use ($customerId, $cohortId, $productId, $versionId): array {
            $student = $this->createUser($customerId, 'student');
            DB::table('users')->where('id', $student->id)->update(['name' => $name, 'phone' => $phone]);
            $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
            $membershipId = $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);
            DB::table('core_course_cohort_students')->where('id', $membershipId)->update(['status' => $membershipStatus]);

            return [$student, $enrollmentId];
        };
        [$an, $anEnrollment] = $enrol('An Nguyễn', '0901');
        [$binh, $binhEnrollment] = $enrol('Bình Trần', '0902');
        $enrol('Cũ Đã Rời', '0903', 'removed');

        $lessonId = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'source_template_lesson_id' => 9901,
            'title_snapshot' => 'Export Lesson', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $activityId = DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $customerId, 'template_version_id' => $versionId, 'version_lesson_id' => $lessonId,
            'source_template_activity_id' => 9902, 'title_snapshot' => 'Export Live', 'activity_type' => 'live_class',
            'completion_rule' => 'attendance', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $session = fn (int $no, string $start, string $status) => DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $customerId, 'cohort_id' => $cohortId, 'template_version_id' => $versionId,
            'session_type' => 'curriculum', 'version_lesson_id' => $lessonId, 'version_activity_id' => $activityId,
            'title' => 'Buổi '.$no, 'session_no' => $no, 'delivery_mode' => 'online',
            'scheduled_start_at' => $start, 'scheduled_end_at' => substr($start, 0, 11).'21:00:00',
            'timezone' => 'Asia/Ho_Chi_Minh', 'status' => $status, 'created_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $first = $session(1, '2026-08-05 19:00:00', 'completed');
        $second = $session(2, '2026-08-10 19:00:00', 'scheduled');
        $session(3, '2026-08-20 19:00:00', 'scheduled'); // not started: not on the Điểm danh tab, not in the file
        $mark = fn (int $sessionId, int $enrollmentId, int $userId, string $status) => DB::table('core_liveclass_attendances')->insert([
            'customer_id' => $customerId, 'session_id' => $sessionId, 'enrollment_id' => $enrollmentId, 'user_id' => $userId,
            'version_activity_id' => $activityId, 'status' => $status, 'attendance_mode' => 'online', 'duration_seconds' => 0,
            'attendance_percentage' => 0, 'attendance_source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $mark($first, $anEnrollment, $an->id, 'present');
        $mark($second, $anEnrollment, $an->id, 'late');
        $mark($first, $binhEnrollment, $binh->id, 'absent');

        $otherTenant = $this->createTenant('tenant-b');
        $otherCohort = $this->createCohort($otherTenant, 'Other Cohort');
        $base = 'https://tenant-a.localhost/admin/course-cohorts';

        $this->actingAs($admin)->get("{$base}/{$cohortId}?tab=students")->assertOk()
            ->assertSee('href="'.$base.'/'.$cohortId.'/students/export"', false);
        $this->actingAs($admin)->get("{$base}/{$cohortId}?tab=attendance")->assertOk()
            ->assertSee('href="'.$base.'/'.$cohortId.'/attendance/export"', false);

        $roster = $this->readXlsx($this->actingAs($admin)->get("{$base}/{$cohortId}/students/export"), 'hoc-vien-lop-coh-existing-');
        $this->assertSame(__('lf.LF_course_cohort_student_common_student'), $roster[0][1]);
        $this->assertCount(3, $roster);
        $this->assertEqualsCanonicalizing(['An Nguyễn', 'Bình Trần'], array_column(array_slice($roster, 1), 1));
        $this->assertContains('0901', array_column($roster, 3));
        $filtered = $this->readXlsx($this->actingAs($admin)->get("{$base}/{$cohortId}/students/export?student_keyword=Bình"), 'hoc-vien-lop-');
        $this->assertSame(['Bình Trần'], array_column(array_slice($filtered, 1), 1));

        $grid = $this->readXlsx($this->actingAs($admin)->get("{$base}/{$cohortId}/attendance/export"), 'diem-danh-coh-existing-');
        $this->assertSame([
            __('lf.table_no'), __('lf.LF_course_cohort_student_common_student'), __('lf.LF_common_label_email'),
            '#1 · 05/08/2026', '#2 · 10/08/2026',
            __('lf.LF_course_cohort_attendance_status_present'), __('lf.LF_course_cohort_attendance_status_late'),
            __('lf.LF_course_cohort_attendance_status_absent'), __('lf.LF_course_cohort_attendance_status_excused'),
            __('lf.LF_export_col_attendance_rate'),
        ], $grid[0]);
        $label = fn (string $status) => __('lf.LF_course_cohort_attendance_status_'.$status);
        $this->assertSame(['1', 'An Nguyễn', $an->email, $label('present'), $label('late'), '1', '1', '0', '0', '100%'], $grid[1]);
        $this->assertSame(['2', 'Bình Trần', $binh->email, $label('absent'), $label('registered'), '0', '0', '1', '0', '0%'], $grid[2]);
        $this->assertCount(3, $grid);

        // Another tenant's Cohort is not found; non-admins are kept out; the grid is off while attendance is off.
        $this->actingAs($admin)->get("{$base}/{$otherCohort}/students/export")->assertNotFound();
        $this->actingAs($admin)->get("{$base}/{$otherCohort}/attendance/export")->assertNotFound();
        $this->actingAs($teacher)->get("{$base}/{$cohortId}/students/export")->assertForbidden();
        $this->actingAs($teacher)->get("{$base}/{$cohortId}/attendance/export")->assertForbidden();
        config(['liveclass.attendance_enabled' => false]);
        $this->actingAs($admin)->get("{$base}/{$cohortId}/attendance/export")->assertNotFound();
    }

    /** @return list<list<string>> */
    private function readXlsx(TestResponse $response, string $filePrefix): array
    {
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('attachment; filename="'.$filePrefix, $response->headers->get('Content-Disposition'));
        $path = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($path, $response->getContent());

        return SimpleXlsx::read($path);
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

        return DB::table('core_course_templates')->insertGetId(['course_type' => 'blended',
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

        $versionId = DB::table('core_course_template_versions')->insertGetId(['course_type_snapshot' => 'blended',
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
