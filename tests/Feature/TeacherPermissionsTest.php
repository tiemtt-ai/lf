<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Assessment\AssessmentGraderService;
use App\Services\TeacherPermissions;
use App\Services\TenantOwner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** ADR-0033: the tenant owner among admins, the teachers' permission groups and the check of every phase-1 key. */
class TeacherPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://tenant-a.localhost';

    private const URL = self::HOST.'/admin/teacher-permissions';

    private const GROUPS = TeacherPermissions::GROUPS_TABLE;

    private const GRANTS = TeacherPermissions::GRANTS_TABLE;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://localhost', 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);
    }

    // ---- Chủ trung tâm (D2) ----

    public function test_the_earliest_active_admin_is_the_owner_and_only_the_owner_manages_other_admins(): void
    {
        $customerId = $this->tenant();
        $owner = $this->user($customerId, 'customer_admin', now()->subDays(3));
        $admin = $this->user($customerId, 'customer_admin', now()->subDay());
        $teacher = $this->user($customerId, 'teacher');
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $this->assertSame($owner->id, app(TenantOwner::class)->id($customerId));
        $this->assertSame($owner->id, (int) DB::table('saas_customers')->where('id', $customerId)->value('owner_user_id'));

        // An ordinary admin sees the admins but cannot open, change, reset or create one.
        $this->actingAs($admin)->get(self::HOST.'/admin/users?role=customer_admin')->assertOk()
            ->assertSeeText($owner->name)->assertSeeText(__('lf.LF_admin_user_owner_badge'))
            ->assertDontSee('admin/users/'.$owner->id.'/edit', false)
            ->assertDontSee('admin/users/create?role=customer_admin', false)
            ->assertDontSee('transfer-ownership', false);
        $this->actingAs($admin)->withHeaders($ajax)->get(self::HOST.'/admin/users/'.$owner->id.'/edit')->assertForbidden();
        $this->actingAs($admin)->withHeaders($ajax)->get(self::HOST.'/admin/users/'.$owner->id.'/password')->assertForbidden();
        $this->flushHeaders();
        $this->actingAs($admin)->get(self::HOST.'/admin/users/create?role=customer_admin')->assertForbidden();
        $this->actingAs($admin)->putJson(self::HOST.'/admin/users/'.$owner->id, $this->profile($owner, ['name' => 'Changed']))->assertForbidden();
        $this->actingAs($admin)->putJson(self::HOST.'/admin/users/'.$owner->id, [
            'password_reset' => '1', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ])->assertForbidden();
        $this->actingAs($admin)->post(self::HOST.'/admin/users/'.$owner->id.'/toggle-status')->assertForbidden();
        $this->actingAs($admin)->postJson(self::HOST.'/admin/users', $this->newUser('customer_admin'))
            ->assertStatus(422)->assertJsonValidationErrors(['role' => __('lf.LF_admin_user_error_owner_only')]);
        // Promoting someone to admin is the owner's too.
        $this->actingAs($admin)->putJson(self::HOST.'/admin/users/'.$teacher->id, $this->profile($teacher, ['role' => 'customer_admin']))
            ->assertStatus(422)->assertJsonValidationErrors(['role' => __('lf.LF_admin_user_error_owner_only')]);
        $this->assertSame('teacher', DB::table('users')->where('id', $teacher->id)->value('role'));
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'name' => $owner->name, 'status' => 'active']);

        // The ordinary admin still runs their own account and every teacher and student.
        $this->actingAs($admin)->putJson(self::HOST.'/admin/users/'.$admin->id, $this->profile($admin, ['name' => 'Admin renamed']))->assertOk();
        $this->actingAs($admin)->putJson(self::HOST.'/admin/users/'.$teacher->id, $this->profile($teacher, ['name' => 'Teacher renamed']))->assertOk();
        $this->actingAs($admin)->postJson(self::HOST.'/admin/users', $this->newUser('teacher'))->assertCreated();

        // The owner does all of it.
        $this->actingAs($owner)->get(self::HOST.'/admin/users?role=customer_admin')->assertOk()
            ->assertSee('admin/users/create?role=customer_admin', false)->assertSee('transfer-ownership', false);
        $this->actingAs($owner)->putJson(self::HOST.'/admin/users/'.$admin->id, $this->profile($admin, ['name' => 'By owner']))->assertOk();
        $this->actingAs($owner)->postJson(self::HOST.'/admin/users', $this->newUser('customer_admin', 'second-admin@example.test'))->assertCreated();
        $this->actingAs($owner)->putJson(self::HOST.'/admin/users/'.$teacher->id, $this->profile($teacher, ['role' => 'customer_admin']))->assertOk();
    }

    public function test_the_owner_is_never_demoted_or_locked_and_hands_the_role_over_with_an_audit(): void
    {
        $customerId = $this->tenant();
        $owner = $this->user($customerId, 'customer_admin', now()->subDays(3));
        $admin = $this->user($customerId, 'customer_admin', now()->subDay());
        $teacher = $this->user($customerId, 'teacher');
        $lockedAdmin = $this->user($customerId, 'customer_admin', status: 'inactive');

        $this->actingAs($owner)->putJson(self::HOST.'/admin/users/'.$owner->id, $this->profile($owner, ['role' => 'teacher']))
            ->assertStatus(422)->assertJsonValidationErrors(['role' => __('lf.LF_admin_user_error_owner_demote')]);
        $this->actingAs($owner)->post(self::HOST.'/admin/users/'.$owner->id.'/toggle-status')->assertSessionHasErrors('status');
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'role' => 'customer_admin', 'status' => 'active']);
        // The owner's role list offers nothing but admin.
        $this->actingAs($owner)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get(self::HOST.'/admin/users/'.$owner->id.'/edit')
            ->assertOk()->assertDontSee('<option value="teacher"', false)->assertSeeText(__('lf.LF_admin_user_owner_role_help'));
        $this->flushHeaders();

        // Only to another active admin, and only by the owner.
        $this->actingAs($admin)->post(self::HOST.'/admin/users/'.$admin->id.'/transfer-ownership')->assertForbidden();
        foreach ([$teacher, $lockedAdmin, $owner] as $target) {
            $this->actingAs($owner)->post(self::HOST.'/admin/users/'.$target->id.'/transfer-ownership')->assertSessionHasErrors('owner');
        }
        $this->assertSame($owner->id, app(TenantOwner::class)->id($customerId));

        $this->actingAs($owner)->post(self::HOST.'/admin/users/'.$admin->id.'/transfer-ownership')
            ->assertRedirect(self::HOST.'/admin/users?role=customer_admin')->assertSessionHas('success', __('lf.LF_admin_user_owner_transferred'));
        $this->assertSame($admin->id, (int) DB::table('saas_customers')->where('id', $customerId)->value('owner_user_id'));
        $this->assertDatabaseHas('saas_audit_logs', [
            'customer_id' => $customerId, 'actor_id' => $owner->id, 'target_user_id' => $admin->id, 'action' => 'customer.owner_transferred',
        ]);
        // The former owner is an ordinary admin now.
        $this->actingAs($owner)->putJson(self::HOST.'/admin/users/'.$admin->id, $this->profile($admin, ['name' => 'X']))->assertForbidden();
        $this->actingAs($admin)->putJson(self::HOST.'/admin/users/'.$owner->id, $this->profile($owner, ['role' => 'teacher']))->assertOk();
    }

    public function test_the_owner_stays_inside_the_tenant_and_registration_names_the_first_admin(): void
    {
        $a = $this->tenant();
        $b = $this->tenant('tenant-b');
        $ownerA = $this->user($a, 'customer_admin');
        $adminB = $this->user($b, 'customer_admin');

        app(TenantOwner::class)->id($a);
        // Another tenant's admin is out of reach of the owner's transfer.
        $this->actingAs($ownerA)->post(self::HOST.'/admin/users/'.$adminB->id.'/transfer-ownership')->assertSessionHasErrors('owner');
        $this->assertSame($ownerA->id, app(TenantOwner::class)->id($a));

        config(['app.url' => 'https://localhost']);
        $this->post('https://localhost/register-customer', [
            'customer_name' => 'New Centre', 'slug' => 'new-centre', 'organization_type' => 'corporate',
            'name' => 'Founder', 'email' => 'founder@example.test', 'phone' => '0900000000',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect();
        $customer = DB::table('saas_customers')->where('slug', 'new-centre')->first();
        $this->assertSame((int) DB::table('users')->where('email', 'founder@example.test')->value('id'), (int) $customer->owner_user_id);
    }

    // ---- Nhóm quyền (D4–D5) ----

    public function test_the_default_group_holds_the_former_rights_and_groups_are_managed_by_admins_only(): void
    {
        $customerId = $this->tenant();
        $admin = $this->user($customerId, 'customer_admin');
        foreach (['teacher', 'student'] as $role) {
            $this->actingAs($this->user($customerId, $role))->get(self::URL)->assertForbidden();
        }

        $this->actingAs($admin)->get(self::URL)->assertOk()
            ->assertSeeText(__('lf.LF_teacher_permission_group_default_badge'))
            ->assertSeeText(__('lf.LF_navigation_menu_admin_teacher_permissions'))
            // The group form lists the permissions by area, in the order of the menu.
            ->assertSeeTextInOrder([__('lf.LF_teacher_permission_area_content'), __('lf.LF_teacher_permission_area_assessment'), __('lf.LF_teacher_permission_area_class')]);
        // Every permission sits in exactly one area, so the form never leaves one out.
        $this->assertSame(TeacherPermissions::CATALOG, array_merge(...array_values(TeacherPermissions::AREAS)));
        $default = DB::table(self::GROUPS)->where('customer_id', $customerId)->where('is_default', 1)->first();
        $this->assertNotNull($default);
        $this->assertEqualsCanonicalizing(TeacherPermissions::DEFAULT_KEYS,
            DB::table(self::GRANTS)->where('permission_group_id', $default->id)->pluck('permission_key')->all());

        $this->actingAs($admin)->post(self::URL, ['name' => ' Trợ giảng ', 'keys' => ['class.operate', 'not.a.key']])
            ->assertSessionHasErrors('keys.1', null, 'groupCreate');
        $this->actingAs($admin)->post(self::URL, ['name' => 'Mặc định', 'keys' => []])->assertSessionHasErrors('name', null, 'groupCreate');
        $this->actingAs($admin)->post(self::URL, ['name' => ' Trợ giảng ', 'keys' => ['class.operate']])
            ->assertRedirect(self::URL)->assertSessionHas('success', __('lf.LF_teacher_permission_group_created'));
        $group = DB::table(self::GROUPS)->where('customer_id', $customerId)->where('name', 'Trợ giảng')->first();
        $this->assertNull($group->is_default);
        $this->assertSame(['class.operate'], DB::table(self::GRANTS)->where('permission_group_id', $group->id)->pluck('permission_key')->all());

        // Saving replaces the keys and is audited with before and after.
        $this->actingAs($admin)->put(self::URL.'/'.$group->id, ['name' => 'Trợ giảng', 'keys' => ['assessment.grading', 'class.operate']])
            ->assertRedirect(self::URL);
        $this->assertEqualsCanonicalizing(['assessment.grading', 'class.operate'],
            DB::table(self::GRANTS)->where('permission_group_id', $group->id)->pluck('permission_key')->all());
        $audit = DB::table('saas_audit_logs')->where('action', 'teacher_permission_group.updated')->first();
        $this->assertSame(['class.operate'], json_decode($audit->before, true)['keys']);

        // The default group's keys can change; the group cannot be deleted.
        $this->actingAs($admin)->put(self::URL.'/'.$default->id, ['name' => 'Mặc định', 'keys' => ['class.operate']])->assertRedirect(self::URL);
        $this->assertSame(['class.operate'], DB::table(self::GRANTS)->where('permission_group_id', $default->id)->pluck('permission_key')->all());
        $this->actingAs($admin)->delete(self::URL.'/'.$default->id)->assertSessionHasErrors('group');
        $this->assertDatabaseHas(self::GROUPS, ['id' => $default->id]);
    }

    public function test_teachers_move_between_groups_and_a_group_with_teachers_is_never_deleted(): void
    {
        $customerId = $this->tenant();
        $admin = $this->user($customerId, 'customer_admin');
        $active = $this->user($customerId, 'teacher');
        $locked = $this->user($customerId, 'teacher', status: 'inactive');
        $stays = $this->user($customerId, 'teacher');
        $group = $this->group($customerId, 'Trợ giảng', ['class.operate']);
        $shown = [$active->id, $locked->id, $stays->id];

        $this->actingAs($admin)->get(self::URL.'?group_form=teachers&group_id='.$group)->assertOk()->assertSee($active->email);
        $this->actingAs($admin)->put(self::URL.'/'.$group.'/teachers', ['shown_teacher_ids' => $shown, 'teacher_ids' => [$active->id, $locked->id]])
            ->assertRedirect(self::URL);
        $this->assertSame($group, (int) DB::table('users')->where('id', $active->id)->value('teacher_permission_group_id'));
        $this->assertNull(DB::table('users')->where('id', $stays->id)->value('teacher_permission_group_id'));
        $this->assertSame(2, DB::table('saas_audit_logs')->where('action', 'user.permission_group_changed')->count());

        // A locked teacher still holds the group.
        $this->actingAs($admin)->put(self::URL.'/'.$group.'/teachers', ['shown_teacher_ids' => $shown, 'teacher_ids' => [$locked->id]]);
        $this->assertNull(DB::table('users')->where('id', $active->id)->value('teacher_permission_group_id'));
        $this->actingAs($admin)->delete(self::URL.'/'.$group)->assertSessionHasErrors('group');
        $this->assertDatabaseHas(self::GROUPS, ['id' => $group]);

        // A teacher who stops being a teacher leaves the group in the same save.
        $this->actingAs($admin)->putJson(self::HOST.'/admin/users/'.$locked->id, $this->profile($locked, ['role' => 'student']))->assertOk();
        $this->assertNull(DB::table('users')->where('id', $locked->id)->value('teacher_permission_group_id'));
        $this->actingAs($admin)->delete(self::URL.'/'.$group)->assertSessionHas('success', __('lf.LF_teacher_permission_group_deleted'));
        $this->assertDatabaseMissing(self::GROUPS, ['id' => $group]);
        $this->assertDatabaseMissing(self::GRANTS, ['permission_group_id' => $group]);
    }

    public function test_groups_and_teachers_of_another_tenant_are_out_of_reach(): void
    {
        $a = $this->tenant();
        $b = $this->tenant('tenant-b');
        $adminA = $this->user($a, 'customer_admin');
        $teacherB = $this->user($b, 'teacher');
        $groupB = $this->group($b, 'B group', []);
        $groupA = $this->group($a, 'A group', []);

        $this->actingAs($adminA)->get(self::URL.'?group_form=edit&group_id='.$groupB)->assertNotFound();
        $this->actingAs($adminA)->put(self::URL.'/'.$groupB, ['name' => 'X', 'keys' => []])->assertNotFound();
        $this->actingAs($adminA)->delete(self::URL.'/'.$groupB)->assertNotFound();
        $this->actingAs($adminA)->put(self::URL.'/'.$groupA.'/teachers', ['shown_teacher_ids' => [$teacherB->id], 'teacher_ids' => [$teacherB->id]]);
        $this->assertNull(DB::table('users')->where('id', $teacherB->id)->value('teacher_permission_group_id'));
        $this->assertDatabaseHas(self::GROUPS, ['id' => $groupB, 'name' => 'B group']);
        // The composite key refuses a group of another tenant even when the application is bypassed.
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $teacherB->id)->update(['teacher_permission_group_id' => $groupA]);
    }

    // ---- Thực thi (D6) ----

    public function test_content_template_create_opens_and_closes_template_creation(): void
    {
        [$customerId, $with, $without] = $this->teachersWithAndWithout('content.template.create');
        $admin = $this->user($customerId, 'customer_admin');
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $this->actingAs($without)->withHeaders($ajax)->get(self::HOST.'/teacher/course-templates/create')->assertForbidden();
        $this->actingAs($without)->post(self::HOST.'/teacher/course-templates', ['title' => 'X'])->assertForbidden();
        $this->actingAs($without)->get(self::HOST.'/teacher/course-templates')->assertOk()
            ->assertDontSee('/teacher/course-templates/create', false);
        $this->actingAs($with)->withHeaders($ajax)->get(self::HOST.'/teacher/course-templates/create')->assertOk();
        $this->actingAs($with)->get(self::HOST.'/teacher/course-templates')->assertOk()
            ->assertSee('/teacher/course-templates/create', false);
        // Admins never go through the groups.
        $this->actingAs($admin)->withHeaders($ajax)->get(self::HOST.'/admin/course-templates/create')->assertOk();
    }

    public function test_content_category_manage_opens_category_management_to_a_teacher(): void
    {
        $customerId = $this->tenant();
        $reader = $this->user($customerId, 'teacher');
        $manager = $this->user($customerId, 'teacher');
        DB::table('users')->where('id', $manager->id)->update([
            'teacher_permission_group_id' => $this->group($customerId, 'Quản lý danh mục', ['content.category.manage']),
        ]);
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $url = self::HOST.'/teacher/course-categories';

        // Off by default: the teacher reads the list only.
        $this->actingAs($reader)->get($url)->assertOk()->assertDontSee('data-ui-dialog-form-open', false);
        $this->actingAs($reader)->withHeaders($ajax)->get($url.'/create')->assertForbidden();
        $this->flushHeaders();
        $this->actingAs($reader)->post($url, ['name' => 'Không được', 'status' => 'active'])->assertForbidden();
        $this->assertDatabaseMissing('core_course_categories', ['name' => 'Không được']);

        // With the key the teacher manages every category of the tenant.
        $this->actingAs($manager)->get($url)->assertOk()->assertSee($url.'/create', false);
        $this->actingAs($manager)->withHeaders($ajax)->get($url.'/create')->assertOk();
        $this->flushHeaders();
        $this->actingAs($manager)->post($url, ['name' => 'Giao tiếp', 'status' => 'active', 'is_featured' => '0'])->assertSessionHasNoErrors()->assertRedirect();
        $id = (int) DB::table('core_course_categories')->where('customer_id', $customerId)->where('name', 'Giao tiếp')->value('id');
        $this->assertGreaterThan(0, $id);
        $this->actingAs($reader)->post($url.'/'.$id.'/toggle-status')->assertForbidden();
        $this->actingAs($manager)->post($url.'/'.$id.'/toggle-status')->assertRedirect();
        $this->assertSame('inactive', DB::table('core_course_categories')->where('id', $id)->value('status'));
        $this->actingAs($reader)->delete($url, ['ids' => [$id]])->assertForbidden();
        $this->actingAs($manager)->delete($url, ['ids' => [$id]])->assertRedirect();
        $this->assertDatabaseMissing('core_course_categories', ['id' => $id]);
    }

    public function test_assessment_authoring_and_grading_split_the_tests_menu(): void
    {
        [$customerId, $with, $without] = $this->teachersWithAndWithout('assessment.authoring');
        $grader = $this->user($customerId, 'teacher');
        DB::table('users')->where('id', $grader->id)->update(['teacher_permission_group_id' => $this->group($customerId, 'Chấm', ['assessment.grading'])]);
        $nothing = $this->user($customerId, 'teacher');
        DB::table('users')->where('id', $nothing->id)->update(['teacher_permission_group_id' => $this->group($customerId, 'Không', [])]);

        foreach (['questions', 'passages', 'rubrics', 'quizzes', 'registrations'] as $section) {
            $this->actingAs($without)->get(self::HOST.'/teacher/assessments/'.$section)->assertForbidden();
            $this->actingAs($with)->get(self::HOST.'/teacher/assessments/'.$section)->assertOk();
        }
        $this->actingAs($without)->post(self::HOST.'/teacher/assessments/questions', [])->assertForbidden();

        // Results: read by whoever writes or grades; claiming and grading need `assessment.grading`.
        $this->actingAs($grader)->get(self::HOST.'/teacher/assessments/results')->assertOk();
        $this->actingAs($grader)->get(self::HOST.'/teacher/assessments')->assertRedirect(self::HOST.'/teacher/assessments/results');
        $this->actingAs($nothing)->get(self::HOST.'/teacher/assessments/results')->assertForbidden();
        $this->actingAs($nothing)->get(self::HOST.'/teacher/assessments')->assertForbidden();
        $this->actingAs($nothing)->post(self::HOST.'/teacher/assessments/results/1/claim')->assertForbidden();
        $this->actingAs($grader)->post(self::HOST.'/teacher/assessments/results/1/claim')->assertNotFound();

        // The menu follows: the grader sees only the results; a teacher with nothing sees no Kiểm tra group.
        $menu = $this->actingAs($grader)->get(self::HOST.'/teacher')->assertOk();
        $menu->assertSee('/teacher/assessments/results', false)->assertDontSee('/teacher/assessments/questions', false);
        $this->actingAs($nothing)->get(self::HOST.'/teacher')->assertOk()
            ->assertDontSee('/teacher/assessments/results', false)->assertDontSeeText(__('lf.LF_navigation_group_assessment'));
        $this->actingAs($with)->get(self::HOST.'/teacher')->assertOk()->assertSee('/teacher/assessments/questions', false);

        // Only teachers holding `assessment.grading` can be named graders.
        $graders = app(AssessmentGraderService::class)->teachers($customerId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($grader->id, $graders);
        $this->assertContains($with->id, $graders);
        $this->assertNotContains($nothing->id, $graders);
        $this->assertContains($without->id, $graders);
    }

    public function test_class_operate_guards_manual_attendance_and_session_edits_only(): void
    {
        [, $with, $without] = $this->teachersWithAndWithout('class.operate');

        $this->actingAs($without)->put(self::HOST.'/teacher/cohorts/1/sessions/1/attendance', [])->assertForbidden();
        $this->actingAs($without)->put(self::HOST.'/teacher/live-sessions/1', [])->assertForbidden();
        // With the key the request reaches the assignment checks (no such Cohort or Session here).
        $this->assertNotSame(403, $this->actingAs($with)->put(self::HOST.'/teacher/cohorts/1/sessions/1/attendance', [])->status());
        $this->assertNotSame(403, $this->actingAs($with)->put(self::HOST.'/teacher/live-sessions/1', [])->status());
        // Teaching itself is never taken away.
        $this->actingAs($without)->get(self::HOST.'/teacher/live-sessions')->assertOk();
        $this->actingAs($without)->get(self::HOST.'/teacher/cohorts')->assertOk();
    }

    public function test_a_change_applies_from_the_teachers_next_request(): void
    {
        $customerId = $this->tenant();
        $admin = $this->user($customerId, 'customer_admin');
        $teacher = $this->user($customerId, 'teacher');
        $this->actingAs($teacher)->get(self::HOST.'/teacher/assessments/questions')->assertOk();

        $default = app(TeacherPermissions::class)->defaultGroup($customerId);
        $this->actingAs($admin)->put(self::URL.'/'.$default->id, ['name' => $default->name, 'keys' => ['class.operate']]);
        $this->actingAs($teacher)->get(self::HOST.'/teacher/assessments/questions')->assertForbidden();
    }

    // ---- Helpers ----

    /** @return array{0: int, 1: User, 2: User} the tenant, a teacher whose group holds the key and one whose group lacks it */
    private function teachersWithAndWithout(string $key): array
    {
        $customerId = $this->tenant();
        $with = $this->user($customerId, 'teacher');
        $without = $this->user($customerId, 'teacher');
        DB::table('users')->where('id', $without->id)->update([
            'teacher_permission_group_id' => $this->group($customerId, 'Không có '.$key, array_values(array_diff(TeacherPermissions::DEFAULT_KEYS, [$key]))),
        ]);

        return [$customerId, $with, $without];
    }

    /** @param  list<string>  $keys */
    private function group(int $customerId, string $name, array $keys): int
    {
        app(TeacherPermissions::class)->defaultGroup($customerId);
        $id = DB::table(self::GROUPS)->insertGetId(['customer_id' => $customerId, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($keys as $key) {
            DB::table(self::GRANTS)->insert(['customer_id' => $customerId, 'permission_group_id' => $id, 'permission_key' => $key, 'created_at' => now()]);
        }

        return $id;
    }

    private function profile(User $user, array $changes = []): array
    {
        return $changes + ['name' => $user->name, 'email' => $user->email, 'role' => $user->role];
    }

    private function newUser(string $role, string $email = 'new-user@example.test'): array
    {
        return ['name' => 'New', 'email' => $email, 'role' => $role, 'password' => 'password123', 'password_confirmation' => 'password123'];
    }

    private function tenant(string $slug = 'tenant-a'): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(int $customerId, string $role, $createdAt = null, string $status = 'active'): User
    {
        return User::forceCreate([
            'customer_id' => $customerId, 'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test', 'password' => Hash::make('password123'),
            'role' => $role, 'status' => $status, 'email_verified_at' => now(),
            'created_at' => $createdAt ?? now(), 'updated_at' => now(),
        ]);
    }
}
