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

        foreach (['index', 'create', 'edit', 'search', 'store', 'sync'] as $route) {
            $this->assertTrue(Route::has("admin.course-cohorts.students.{$route}"));
        }
    }

    public function test_legacy_cohort_students_edit_tab_redirects_to_the_canonical_roster_form(): void
    {
        [$customerId, $admin, , $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, productId: $productId, versionId: $versionId);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/edit?tab=students")
            ->assertRedirect(route('admin.course-cohorts.students.edit', $cohortId));

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/edit")
            ->assertOk()
            ->assertSee('cohort-student-transfer-intro', false)
            ->assertSee('paginatedSelected()', false)
            ->assertSee('x-show="lastPage > 1"', false)
            ->assertSee('x-show="selectedLastPage() > 1"', false);

        $legacyEditView = file_get_contents(resource_path('views/course-cohorts/edit.blade.php'));
        $this->assertStringNotContainsString('selectedEnrollments', $legacyEditView);
        $this->assertStringNotContainsString('course-cohorts.students.sync', $legacyEditView);
        $this->assertStringNotContainsString('bulk-enrollment-pagination', $legacyEditView);
    }

    public function test_student_workspace_translations_exist_in_vietnamese_and_english(): void
    {
        foreach (['vi', 'en'] as $locale) {
            foreach ([
                'LF_course_cohort_student_view_title',
                'LF_course_cohort_student_common_create',
                'LF_course_cohort_student_edit_list_title',
                'LF_course_cohort_student_eligible_heading',
                'LF_course_cohort_student_selected_heading_count',
                'LF_course_cohort_student_search_placeholder',
                'LF_course_cohort_student_selected_search',
                'LF_course_cohort_student_add_count',
                'LF_course_cohort_student_sync_save',
                'LF_course_cohort_student_common_created_count',
                'LF_course_cohort_student_sync_updated',
                'LF_course_cohort_student_search_no_eligible',
                'LF_course_cohort_student_search_empty',
                'LF_course_cohort_student_selected_empty',
                'LF_course_cohort_student_inactive_warning',
            ] as $key) {
                $translation = app('translator')->get("lf.{$key}", [], $locale);
                $this->assertNotSame("lf.{$key}", $translation, "Missing {$locale} translation for {$key}");
                $this->assertNotSame('', trim($translation), "Empty {$locale} translation for {$key}");
            }
        }
    }

    public function test_customer_admin_can_create_cohort_membership(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students",
                [
                    'enrollment_ids' => [$enrollmentId],
                    'note' => 'Seat A1',
                ]
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
                    'status' => 'active',
                    'transfer_reason' => 'Schedule change',
                    'note' => 'Moved by admin',
                ])
            )
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}");

        $this->assertDatabaseHas('core_course_cohort_students', [
            'id' => $membershipId,
            'cohort_id' => $newCohortId,
            'transfer_from_cohort_id' => $cohortId,
            'status' => 'active',
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

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/create")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_student_empty_title'))
            ->assertSeeText(__('lf.LF_course_cohort_student_empty_help'))
            ->assertSeeText(__('lf.LF_course_cohort_student_create_enrollment_action'))
            ->assertDontSee('id="eligible_student_search"', false)
            ->assertDontSeeText(__('lf.LF_bulk_enrollment_select_visible'))
            ->assertDontSee('role="combobox"', false)
            ->assertDontSee('name="cohort_id"', false)
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="joined_at"', false)
            ->assertDontSee('name="transfer_reason"', false)
            ->assertDontSeeText('Metadata JSON')
            ->assertDontSee('name="metadata"', false);

        foreach ([
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

    public function test_membership_detail_has_cancel_and_returns_to_class_students_tab(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $membershipId = $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);
        $returnUrl = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=students";

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}")
            ->assertOk()
            ->assertSee('course-cohort-student-detail', false)
            ->assertSeeText(__('lf.LF_common_button_cancel'))
            ->assertSeeText(__('lf.LF_course_cohort_student_back_to_class_students'))
            ->assertSee($returnUrl, false)
            ->assertSeeText('ENR-'.str_pad((string) $enrollmentId, 6, '0', STR_PAD_LEFT));

        DB::table('core_course_cohort_students')->where('id', $membershipId)->update([
            'status' => 'removed',
            'left_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}")
            ->assertOk()
            ->assertDontSee(route('admin.course-cohort-students.edit', $membershipId), false)
            ->assertDontSee(route('admin.course-cohort-students.archive', $membershipId), false);
    }

    public function test_membership_edit_uses_standard_layout_and_only_exposes_effective_fields(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $membershipId = $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}/edit")
            ->assertOk()
            ->assertSee('admin-form-surface course-cohort-student-edit', false)
            ->assertSee('course-cohort-student-edit-context-grid', false)
            ->assertSee('name="cohort_id"', false)
            ->assertSee('name="joined_at"', false)
            ->assertSee('name="transfer_reason"', false)
            ->assertSee('name="note"', false)
            ->assertSee('name="status" value="active"', false)
            ->assertDontSee('name="left_at"', false)
            ->assertSeeText(__('lf.LF_common_button_cancel'))
            ->assertSeeText(__('lf.LF_common_button_save_changes'));

        DB::table('core_course_cohort_students')->where('id', $membershipId)->update([
            'status' => 'removed',
            'left_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohort-students/{$membershipId}/edit")
            ->assertStatus(422);
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

            $this->actingAs($user)
                ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/edit")
                ->assertForbidden();

            $this->actingAs($user)
                ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students")
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
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$otherCohortId}/students/create")
            ->assertNotFound();

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$otherCohortId}/students/edit")
            ->assertNotFound();

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/create")
            ->post(
                "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students",
                ['enrollment_ids' => [$otherEnrollmentId]]
            )
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/create")
            ->assertSessionHasErrors('enrollment_ids.0');

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
            ->from("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/create")
            ->post(
                "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students",
                ['enrollment_ids' => [$enrollmentId]]
            )
            ->assertSessionHasErrors('enrollment_ids.0');

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$archivedCohortId}/students/create")
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$archivedCohortId}?tab=students");

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-cohorts/{$mismatchCohortId}/students/create")
            ->post(
                "https://tenant-a.localhost/admin/course-cohorts/{$mismatchCohortId}/students",
                ['enrollment_ids' => [$newEnrollmentId]]
            )
            ->assertSessionHasErrors('enrollment_ids.0');

        $this->assertSame(1, DB::table('core_course_cohort_students')->count());
    }

    public function test_enrollment_search_is_tenant_product_version_and_membership_scoped(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $eligibleId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);

        $assignedStudent = $this->createUser($customerId, 'student');
        $assignedId = $this->createEnrollment($customerId, $assignedStudent->id, $productId, $versionId);
        $this->createMembership($customerId, $cohortId, $assignedId, $productId, $assignedStudent->id);

        $otherProduct = $this->createProduct($customerId, 'Other Product', 'other-search-product');
        $otherVersion = $this->createVersion($customerId, $admin->id, 'Other Search Version');
        $otherStudent = $this->createUser($customerId, 'student');
        $this->createEnrollment($customerId, $otherStudent->id, $otherProduct, $otherVersion);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/create")
            ->assertOk()
            ->assertSee('name="enrollment_ids[]"', false)
            ->assertSee('bulk-enrollment-selector', false)
            ->assertDontSeeText(__('lf.LF_course_cohort_student_search_no_eligible'));

        $this->actingAs($admin)
            ->getJson("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/search")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $eligibleId,
                'email' => $student->email,
                'code' => 'ENR-'.str_pad((string) $eligibleId, 6, '0', STR_PAD_LEFT),
                'status' => 'active',
                'status_label' => 'Hoạt động',
                'source_label' => 'Quản trị viên',
                'detail_url' => route('admin.course-enrollments.show', $eligibleId),
            ])
            ->assertJsonMissing(['email' => $assignedStudent->email])
            ->assertJsonMissing(['email' => $otherStudent->email]);

        $this->actingAs($admin)
            ->getJson("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/search?q=".urlencode($student->email))
            ->assertOk()
            ->assertJsonFragment(['id' => $eligibleId, 'email' => $student->email])
            ->assertJsonMissing(['email' => $assignedStudent->email])
            ->assertJsonMissing(['email' => $otherStudent->email]);

        $this->actingAs($admin)
            ->getJson("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/search?q=ENR-".str_pad((string) $eligibleId, 6, '0', STR_PAD_LEFT))
            ->assertOk()
            ->assertJsonFragment(['id' => $eligibleId]);
    }

    public function test_enrollment_search_paginates_results(): void
    {
        [$customerId, $admin, , $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId);

        for ($i = 0; $i < 17; $i++) {
            $student = $this->createUser($customerId, 'student');
            $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        }

        $firstPage = $this->actingAs($admin)
            ->getJson("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/search")
            ->assertOk()
            ->json();

        $this->assertCount(10, $firstPage['data']);
        $this->assertSame(1, $firstPage['pagination']['current_page']);
        $this->assertSame(2, $firstPage['pagination']['last_page']);
        $this->assertSame(17, $firstPage['pagination']['total']);

        $secondPage = $this->actingAs($admin)
            ->getJson("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/search?page=2")
            ->assertOk()
            ->json();

        $this->assertCount(7, $secondPage['data']);
        $this->assertSame(2, $secondPage['pagination']['current_page']);
    }

    public function test_add_derives_managed_fields_and_rejects_forged_authority(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/create")
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students", [
                'enrollment_ids' => [$enrollmentId],
                'note' => 'Admin-only note',
                'cohort_id' => 999,
                'student_id' => 999,
                'product_id' => 999,
                'status' => 'removed',
                'joined_at' => '2000-01-01 00:00:00',
            ])
            ->assertSessionHasErrors(['cohort_id', 'student_id', 'product_id', 'status', 'joined_at']);

        $this->assertDatabaseCount('core_course_cohort_students', 0);

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students", [
                'enrollment_ids' => [$enrollmentId],
                'note' => 'Admin-only note',
            ])
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=students")
            ->assertSessionHas('success', __('lf.LF_course_cohort_student_common_created_count', ['count' => 1]));

        $membership = DB::table('core_course_cohort_students')->first();
        $this->assertSame($cohortId, (int) $membership->cohort_id);
        $this->assertSame($student->id, (int) $membership->student_id);
        $this->assertSame($productId, (int) $membership->product_id);
        $this->assertSame('active', $membership->status);
        $this->assertNotNull($membership->joined_at);
        $this->assertSame('Admin-only note', $membership->note);
        $this->assertNull(DB::table('core_course_cohorts')->where('id', $cohortId)->value('notes'));
        $this->assertNull(DB::table('core_course_enrollments')->where('id', $enrollmentId)->value('notes'));
    }

    public function test_no_eloquent_model_was_added_for_cohort_students(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/CoreCourseCohortStudent.php'));
        $this->assertFileDoesNotExist(app_path('Models/CourseCohortStudent.php'));
    }

    public function test_edit_class_student_tab_can_add_and_remove_students_atomically(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId, capacity: 2);
        $currentEnrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $currentMembershipId = $this->createMembership(
            $customerId, $cohortId, $currentEnrollmentId, $productId, $student->id
        );
        $newStudent = $this->createUser($customerId, 'student');
        $newEnrollmentId = $this->createEnrollment($customerId, $newStudent->id, $productId, $versionId);

        $studentTab = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/edit")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_student_edit_list_title'))
            ->assertSeeText(__('lf.LF_course_cohort_student_eligible_heading'))
            ->assertSeeText(__('lf.LF_course_cohort_student_selected_search'))
            ->assertSee(route('admin.course-cohorts.show', ['id' => $cohortId, 'tab' => 'students']), false)
            ->assertSee("/admin/course-cohorts/{$cohortId}/students", false);

        $this->assertStringContainsString('selectedItems:', $studentTab->getContent());
        $this->assertStringContainsString('filteredSelected()', $studentTab->getContent());
        $this->assertStringContainsString('requestToken', $studentTab->getContent());

        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students", [
                'enrollment_ids' => [$newEnrollmentId],
            ])
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=students")
            ->assertSessionHas('success', __('lf.LF_course_cohort_student_sync_updated'));

        $this->assertDatabaseHas('core_course_cohort_students', [
            'id' => $currentMembershipId,
            'status' => 'removed',
        ]);
        $this->assertDatabaseHas('core_course_cohort_students', [
            'customer_id' => $customerId,
            'cohort_id' => $cohortId,
            'enrollment_id' => $newEnrollmentId,
            'status' => 'active',
        ]);
        $this->assertSame('active', DB::table('core_course_enrollments')->where('id', $currentEnrollmentId)->value('status'));
    }

    public function test_removed_student_can_be_selected_again_and_reactivates_the_same_membership(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId, capacity: 2);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $membershipId = $this->createMembership(
            $customerId, $cohortId, $enrollmentId, $productId, $student->id
        );

        DB::table('core_course_cohort_students')
            ->where('id', $membershipId)
            ->update(['status' => 'removed', 'left_at' => now()]);

        $this->actingAs($admin)
            ->getJson("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/search?manage=1")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $enrollmentId,
                'email' => $student->email,
                'current' => false,
            ]);

        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students", [
                'enrollment_ids' => [$enrollmentId],
            ])
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=students");

        $this->assertDatabaseHas('core_course_cohort_students', [
            'id' => $membershipId,
            'customer_id' => $customerId,
            'cohort_id' => $cohortId,
            'enrollment_id' => $enrollmentId,
            'status' => 'active',
            'left_at' => null,
        ]);
        $this->assertSame(
            1,
            DB::table('core_course_cohort_students')
                ->where('customer_id', $customerId)
                ->where('enrollment_id', $enrollmentId)
                ->count()
        );
    }

    public function test_student_tab_search_includes_current_members_when_class_is_full(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId, capacity: 1);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);

        $this->actingAs($admin)
            ->getJson("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/search?manage=1")
            ->assertOk()
            ->assertJsonPath('data.0.id', $enrollmentId)
            ->assertJsonPath('data.0.current', true);
    }

    public function test_edit_keeps_inactive_current_student_visible_and_does_not_recreate_unchanged_membership(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId, capacity: 2);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $membershipId = $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);
        DB::table('core_course_cohort_students')->where('id', $membershipId)->update([
            'created_at' => '2026-07-01 08:00:00',
            'updated_at' => '2026-07-01 08:00:00',
        ]);
        DB::table('core_course_enrollments')->where('id', $enrollmentId)->update(['status' => 'suspended']);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/edit")
            ->assertOk()
            ->assertSee($student->name)
            ->assertSeeText(__('lf.LF_course_cohort_student_inactive_warning'));

        $this->actingAs($admin)
            ->getJson("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/search?manage=1")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $enrollmentId,
                'current' => true,
                'eligible' => false,
            ]);

        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students", [
                'enrollment_ids' => [$enrollmentId],
            ])->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=students");

        $this->assertDatabaseCount('core_course_cohort_students', 1);
        $this->assertSame(
            '2026-07-01 08:00:00',
            DB::table('core_course_cohort_students')->where('id', $membershipId)->value('updated_at')
        );
        $this->assertSame('suspended', DB::table('core_course_enrollments')->where('id', $enrollmentId)->value('status'));
    }

    public function test_completed_class_student_workspace_is_readonly_and_direct_manage_is_rejected(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId, status: 'completed');
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=students")
            ->assertOk()
            ->assertSeeText($student->name)
            ->assertDontSee(route('admin.course-cohorts.students.create', $cohortId), false)
            ->assertDontSee(route('admin.course-cohorts.students.edit', $cohortId), false)
            ->assertDontSee('name="enrollment_ids[]"', false);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students/edit")
            ->assertRedirect("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=students");
        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students", ['enrollment_ids' => []])
            ->assertSessionHasErrors('enrollment_ids');
        $this->assertDatabaseHas('core_course_cohort_students', [
            'cohort_id' => $cohortId, 'enrollment_id' => $enrollmentId, 'status' => 'active',
        ]);
    }

    public function test_show_class_students_tab_links_directly_to_setup_operations(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId, capacity: 12);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $membershipId = $this->createMembership($customerId, $cohortId, $enrollmentId, $productId, $student->id);

        $response = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=students")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_student_view_title'))
            ->assertSeeText($student->name)
            ->assertSeeText('ENR-'.str_pad((string) $enrollmentId, 6, '0', STR_PAD_LEFT))
            ->assertSeeText('Xem ghi danh')
            ->assertDontSeeText('Xem phân lớp')
            ->assertSee('cohort-student-list-filter', false)
            ->assertSee('cohort-student-list-table', false)
            ->assertSee('cohort-student-list-heading-actions', false)
            ->assertSee('cohort-student-list-status', false)
            ->assertSee('cohort-enrollment-detail-modal', false)
            ->assertSee('data-label="Học viên"', false)
            ->assertDontSee('class="admin-table-sequence"', false)
            ->assertDontSee(route('admin.course-cohort-students.edit', $membershipId), false)
            ->assertDontSee(route('admin.course-cohorts.students.edit', $cohortId), false)
            ->assertDontSee(route('admin.course-cohorts.students.create', $cohortId), false)
            ->assertSee(route('admin.course-cohorts.students.sync', $cohortId), false)
            ->assertSee('course-cohort-student-inline-form', false)
            ->assertDontSee(route('admin.course-cohorts.edit', ['id' => $cohortId, 'tab' => 'students']), false)
            ->assertSee('name="enrollment_ids[]"', false);

        $response->assertSeeText('1/12');

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}?tab=students&student_keyword=missing")
            ->assertOk()
            ->assertSeeText('1/12')
            ->assertSeeText('Không tìm thấy học viên phù hợp.')
            ->assertDontSeeText($student->email);
    }

    public function test_student_sync_rejects_an_enrollment_from_another_tenant(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $otherCustomerId = $this->createTenant('tenant-b');
        $otherStudent = $this->createUser($otherCustomerId, 'student');
        $otherProductId = $this->createProduct($otherCustomerId, 'Other Product', 'other-product');
        $otherVersionId = $this->createVersion($otherCustomerId, $otherStudent->id, 'Other Product');
        $otherEnrollmentId = $this->createEnrollment(
            $otherCustomerId, $otherStudent->id, $otherProductId, $otherVersionId
        );

        $this->actingAs($admin)
            ->put("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students", [
                'enrollment_ids' => [$otherEnrollmentId],
            ])
            ->assertSessionHasErrors('enrollment_ids.0');

        $this->assertDatabaseMissing('core_course_cohort_students', [
            'customer_id' => $customerId,
            'enrollment_id' => $otherEnrollmentId,
        ]);
    }

    public function test_membership_requires_active_enrollment_active_cohort_and_capacity(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $activeCohort = $this->createCohort($customerId, $productId, $versionId, capacity: 1);
        $draftCohort = $this->createCohort($customerId, $productId, $versionId, status: 'draft');
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        DB::table('core_course_enrollments')->where('id', $enrollmentId)->update(['status' => 'suspended']);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$activeCohort}/students",
            ['enrollment_ids' => [$enrollmentId]])
            ->assertSessionHasErrors('enrollment_ids.0');

        DB::table('core_course_enrollments')->where('id', $enrollmentId)->update(['status' => 'active']);
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-cohorts/{$draftCohort}/students/create")
            ->assertOk();

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$activeCohort}/students",
            ['enrollment_ids' => [$enrollmentId]])->assertRedirect();
        $secondStudent = $this->createUser($customerId, 'student');
        $secondEnrollment = $this->createEnrollment($customerId, $secondStudent->id, $productId, $versionId);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-cohorts/{$activeCohort}/students",
            ['enrollment_ids' => [$secondEnrollment]])
            ->assertSessionHasErrors('enrollment_ids.0');
    }

    public function test_direct_add_rejects_an_inactive_student_account(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $enrollmentId = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        DB::table('users')->where('id', $student->id)->update(['status' => 'inactive']);

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students", [
                'enrollment_ids' => [$enrollmentId],
            ])
            ->assertSessionHasErrors('enrollment_ids.0');

        $this->assertDatabaseMissing('core_course_cohort_students', [
            'customer_id' => $customerId, 'enrollment_id' => $enrollmentId,
        ]);
    }

    public function test_batch_enrollment_ids_are_inserted_atomically(): void
    {
        [$customerId, $admin, $student, $productId, $versionId] = $this->learningContext();
        $cohortId = $this->createCohort($customerId, $productId, $versionId);
        $enrollmentIdOne = $this->createEnrollment($customerId, $student->id, $productId, $versionId);
        $secondStudent = $this->createUser($customerId, 'student');
        $enrollmentIdTwo = $this->createEnrollment($customerId, $secondStudent->id, $productId, $versionId);

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}/students",
                ['enrollment_ids' => [$enrollmentIdOne, $enrollmentIdTwo]]
            )
            ->assertRedirect();

        $this->assertSame(2, DB::table('core_course_cohort_students')->where('cohort_id', $cohortId)->count());

        $limitedCohortId = $this->createCohort($customerId, $productId, $versionId, capacity: 2);
        $thirdStudent = $this->createUser($customerId, 'student');
        $enrollmentIdThree = $this->createEnrollment($customerId, $thirdStudent->id, $productId, $versionId);
        $fourthStudent = $this->createUser($customerId, 'student');
        $enrollmentIdFour = $this->createEnrollment($customerId, $fourthStudent->id, $productId, $versionId);
        $fifthStudent = $this->createUser($customerId, 'student');
        $enrollmentIdFive = $this->createEnrollment($customerId, $fifthStudent->id, $productId, $versionId);

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-cohorts/{$limitedCohortId}/students",
                ['enrollment_ids' => [$enrollmentIdThree, $enrollmentIdFour, $enrollmentIdFive]]
            )
            ->assertSessionHasErrors('enrollment_ids.2');

        $this->assertSame(0, DB::table('core_course_cohort_students')->where('cohort_id', $limitedCohortId)->count());
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

        $versionId = DB::table('core_course_template_versions')->insertGetId([
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

        $productId = DB::table('core_course_products')->where('customer_id', $customerId)
            ->where('status', 'active')->orderByDesc('id')->value('id');
        if ($productId) {
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
