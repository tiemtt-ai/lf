<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LiveClassAttendancePolicies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** ADR-0002 § Attendance Policy And Recording Amendment, part A: a tenant's policies and their assignment to Cohorts. */
class LiveClassAttendancePolicyManagementTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = LiveClassAttendancePolicies::TABLE;

    private const URL = 'https://tenant-a.localhost/admin/attendance-policies';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://localhost', 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);
    }

    public function test_only_the_administrator_opens_the_page_and_the_tenant_default_is_created_once(): void
    {
        $customerId = $this->tenant();
        $admin = $this->user($customerId, 'customer_admin');
        $this->assertDatabaseMissing(self::TABLE, ['customer_id' => $customerId]);

        foreach (['teacher', 'student'] as $role) {
            $this->actingAs($this->user($customerId, $role))->get(self::URL)->assertForbidden();
        }
        $this->assertDatabaseMissing(self::TABLE, ['customer_id' => $customerId]);

        $this->actingAs($admin)->get(self::URL)->assertOk()
            ->assertSeeText(LiveClassAttendancePolicies::DEFAULT_NAME)
            ->assertSeeText(__('lf.LF_attendance_policy_default_badge'));
        $this->actingAs($admin)->get(self::URL)->assertOk();

        $this->assertSame(1, DB::table(self::TABLE)->where('customer_id', $customerId)->count());
        // The documented defaults.
        $this->assertDatabaseHas(self::TABLE, [
            'customer_id' => $customerId, 'is_default' => 1, 'opens_before_minutes' => 15, 'late_after_minutes' => 10,
            'absent_after_minutes' => 30, 'auto_absent_after_minutes' => 30, 'count_from_actual_start' => 1,
            'offline_recording' => 'system', 'online_recording' => 'system', 'makeup_enabled' => 1,
            'makeup_within_days' => null, 'teacher_edit_days' => 7,
        ]);
    }

    public function test_the_admin_menu_groups_the_class_list_and_the_policies_once_attendance_is_on(): void
    {
        $customerId = $this->tenant();
        $admin = $this->user($customerId, 'customer_admin');
        $cohorts = 'https://tenant-a.localhost/admin/course-cohorts';
        $current = fn (string $html, string $url): bool => (bool) preg_match(
            '/<a class="admin-nav__child" href="'.preg_quote($url, '/').'"\s+aria-current="page"/', $html);

        // Off (the default): one plain "Lớp học" entry, no policies link in the menu or on the class list.
        $page = $this->actingAs($admin)->get($cohorts)->assertOk();
        $page->assertSee('class="admin-nav__item" href="'.$cohorts.'"', false)
            ->assertDontSee('href="'.self::URL.'"', false);

        config(['liveclass.attendance_enabled' => true]);
        $list = $this->actingAs($admin)->get($cohorts)->assertOk()
            ->assertSeeText(__('lf.LF_navigation_menu_admin_cohort_list'))
            ->assertSeeText(__('lf.LF_navigation_menu_admin_attendance_policies'))->getContent();
        $this->assertTrue($current($list, $cohorts));
        $this->assertFalse($current($list, self::URL));
        // The policies page marks its own entry, not the class list.
        $policies = $this->actingAs($admin)->get(self::URL)->assertOk()->getContent();
        $this->assertTrue($current($policies, self::URL));
        $this->assertFalse($current($policies, $cohorts));
        // The class list no longer carries a second way in.
        $this->assertSame(1, substr_count($list, 'href="'.self::URL.'"'));
    }

    public function test_a_policy_is_created_within_the_documented_limits(): void
    {
        $customerId = $this->tenant();
        $admin = $this->user($customerId, 'customer_admin');
        $otherAdmin = $this->user($this->tenant('tenant-b'), 'customer_admin');

        $this->actingAs($admin)->post(self::URL, $this->payload(['name' => ' Lớp buổi tối ', 'late_after_minutes' => 15, 'absent_unlimited' => '1']))
            ->assertRedirect(self::URL)->assertSessionHas('success', __('lf.LF_attendance_policy_created'));
        $this->assertDatabaseHas(self::TABLE, [
            'customer_id' => $customerId, 'name' => 'Lớp buổi tối', 'is_default' => null, 'late_after_minutes' => 15,
            'absent_after_minutes' => null, 'makeup_within_days' => null, 'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);

        // The name is unique inside a tenant only.
        $this->actingAs($admin)->post(self::URL, $this->payload(['name' => 'Lớp buổi tối']))
            ->assertSessionHasErrorsIn('policyCreate', ['name']);
        $this->actingAs($otherAdmin)->post('https://tenant-b.localhost/admin/attendance-policies', $this->payload(['name' => 'Lớp buổi tối']))
            ->assertSessionHasNoErrors();

        foreach ([
            'opens_before_minutes' => ['opens_before_minutes' => 241],
            'late_after_minutes' => ['late_after_minutes' => -1],
            'absent_after_minutes' => ['late_after_minutes' => 20, 'absent_after_minutes' => 19],
            'auto_absent_after_minutes' => ['auto_absent_after_minutes' => 1441],
            'offline_recording' => ['offline_recording' => 'qr'],
            'online_recording' => ['online_recording' => ''],
            'makeup_within_days' => ['makeup_unlimited' => '0', 'makeup_within_days' => 0],
            'teacher_edit_days' => ['teacher_edit_days' => 366],
        ] as $field => $values) {
            $this->actingAs($admin)->post(self::URL, $this->payload(['name' => 'Bad '.$field] + $values))
                ->assertSessionHasErrorsIn('policyCreate', [$field]);
            $this->assertDatabaseMissing(self::TABLE, ['name' => 'Bad '.$field]);
        }
        $this->actingAs($admin)->post(self::URL, $this->payload(['name' => 'Bad absent', 'absent_after_minutes' => 481]))
            ->assertSessionHasErrorsIn('policyCreate', ['absent_after_minutes']);

        // A failed create reopens the popup with what was typed.
        $this->actingAs($admin)->from(self::URL)->followingRedirects()
            ->post(self::URL, $this->payload(['name' => 'Typed name', 'teacher_edit_days' => 999]))
            ->assertOk()->assertSee('value="Typed name"', false);
    }

    public function test_a_policy_is_edited_and_deleted_only_inside_its_tenant(): void
    {
        $customerId = $this->tenant();
        $admin = $this->user($customerId, 'customer_admin');
        $otherCustomerId = $this->tenant('tenant-b');
        $default = app(LiveClassAttendancePolicies::class)->defaultFor($customerId);
        $ownId = $this->policy($customerId, 'Own');
        $foreignId = $this->policy($otherCustomerId, 'Foreign');

        $this->actingAs($admin)->put(self::URL."/{$ownId}", $this->payload(['name' => 'Own renamed', 'opens_before_minutes' => 30]))
            ->assertRedirect(self::URL)->assertSessionHas('success', __('lf.LF_attendance_policy_updated'));
        $this->assertDatabaseHas(self::TABLE, ['id' => $ownId, 'name' => 'Own renamed', 'opens_before_minutes' => 30, 'updated_by' => $admin->id]);
        // The default policy is edited like any other and stays the default.
        $this->actingAs($admin)->put(self::URL."/{$default->id}", $this->payload(['name' => $default->name, 'late_after_minutes' => 5]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas(self::TABLE, ['id' => $default->id, 'is_default' => 1, 'late_after_minutes' => 5]);
        $this->actingAs($admin)->put(self::URL."/{$ownId}", $this->payload(['name' => $default->name]))
            ->assertSessionHasErrorsIn('policyEdit', ['name']);

        $this->actingAs($admin)->put(self::URL."/{$foreignId}", $this->payload(['name' => 'Taken over']))->assertNotFound();
        $this->actingAs($admin)->delete(self::URL."/{$foreignId}")->assertNotFound();
        $this->actingAs($admin)->get(self::URL."?policy_form=edit&policy_id={$foreignId}")->assertNotFound();
        $this->assertDatabaseHas(self::TABLE, ['id' => $foreignId, 'name' => 'Foreign']);

        // The default and a policy a Cohort points to cannot go.
        $this->actingAs($admin)->delete(self::URL."/{$default->id}")
            ->assertSessionHasErrors(['policy' => __('lf.LF_attendance_policy_delete_default')]);
        $cohortId = $this->cohort($customerId, 'Evening', 'archived', $ownId);
        $this->actingAs($admin)->delete(self::URL."/{$ownId}")
            ->assertSessionHasErrors(['policy' => __('lf.LF_attendance_policy_delete_assigned')]);
        $this->assertDatabaseHas(self::TABLE, ['id' => $ownId]);

        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['attendance_policy_id' => null]);
        $this->actingAs($admin)->delete(self::URL."/{$ownId}")->assertSessionHas('success', __('lf.LF_attendance_policy_deleted'));
        $this->assertDatabaseMissing(self::TABLE, ['id' => $ownId]);
    }

    public function test_applying_a_policy_ticks_cohorts_in_and_unticked_ones_return_to_the_default(): void
    {
        $customerId = $this->tenant();
        $admin = $this->user($customerId, 'customer_admin');
        $otherCustomerId = $this->tenant('tenant-b');
        $default = app(LiveClassAttendancePolicies::class)->defaultFor($customerId);
        $evening = $this->policy($customerId, 'Evening');
        $weekend = $this->policy($customerId, 'Weekend');
        $a = $this->cohort($customerId, 'A', 'active', null);
        $b = $this->cohort($customerId, 'B', 'draft', $evening);
        $c = $this->cohort($customerId, 'C', 'completed', $weekend);
        $hidden = $this->cohort($customerId, 'Created meanwhile', 'active', $evening);
        $archived = $this->cohort($customerId, 'Archived', 'archived', $evening);
        $foreign = $this->cohort($otherCustomerId, 'Foreign', 'active', null);
        $policyOf = fn (int $cohortId) => DB::table('core_course_cohorts')->where('id', $cohortId)->value('attendance_policy_id');

        $page = $this->actingAs($admin)->get(self::URL."?policy_form=apply&policy_id={$evening}")->assertOk()
            ->assertSeeText(__('lf.LF_attendance_policy_apply_title', ['name' => 'Evening']));
        $page->assertSee('Created meanwhile')->assertDontSee('Archived')->assertDontSee('Foreign');

        // A and C join, B (unticked) returns to the default; a Cohort the form did not show, an archived one and
        // another tenant's are left alone even when their ids are sent.
        $this->actingAs($admin)->put(self::URL."/{$evening}/cohorts", [
            'shown_cohort_ids' => [$a, $b, $c, $archived, $foreign],
            'cohort_ids' => [$a, $c, $archived, $foreign, $hidden],
        ])->assertRedirect(self::URL)->assertSessionHas('success', trans_choice('lf.LF_attendance_policy_applied', 3, ['count' => 3]));
        $this->assertSame([$evening, null, $evening, $evening, $evening, null], array_map($policyOf, [$a, $b, $c, $hidden, $archived, $foreign]));

        // On the default policy a tick brings the Cohort back; its own Cohorts cannot be unticked away.
        $this->actingAs($admin)->put(self::URL."/{$default->id}/cohorts", [
            'shown_cohort_ids' => [$a, $b, $c], 'cohort_ids' => [$a],
        ])->assertSessionHas('success', trans_choice('lf.LF_attendance_policy_applied', 1, ['count' => 1]));
        $this->assertSame([null, null, $evening], array_map($policyOf, [$a, $b, $c]));

        $this->actingAs($admin)->put(self::URL.'/'.$this->policy($otherCustomerId, 'Foreign policy').'/cohorts', [
            'shown_cohort_ids' => [$a], 'cohort_ids' => [$a],
        ])->assertNotFound();
        $this->actingAs($this->user($customerId, 'teacher'))->put(self::URL."/{$evening}/cohorts", [
            'shown_cohort_ids' => [$a], 'cohort_ids' => [$a],
        ])->assertForbidden();
        $this->assertNull($policyOf($a));
    }

    public function test_a_cohort_chooses_a_policy_of_its_tenant_and_resolves_to_the_default_without_one(): void
    {
        $customerId = $this->tenant();
        $admin = $this->user($customerId, 'customer_admin');
        $policies = app(LiveClassAttendancePolicies::class);
        $default = $policies->defaultFor($customerId);
        $evening = $this->policy($customerId, 'Evening', ['late_after_minutes' => 20]);
        $foreign = $this->policy($this->tenant('tenant-b'), 'Foreign');
        $cohortId = $this->cohort($customerId, 'Class', 'draft', null);
        $url = "https://tenant-a.localhost/admin/course-cohorts/{$cohortId}";
        $policyOf = fn () => DB::table('core_course_cohorts')->where('id', $cohortId)->value('attendance_policy_id');
        // The fields the edit popup always sends.
        $form = fn (array $values): array => $values + [
            'name' => 'Class', 'capacity' => '', 'start_date' => '', 'end_date' => '', 'notes' => '', 'default_meeting_url' => '',
        ];

        $this->assertSame((int) $default->id, (int) $policies->forCohort($customerId, $cohortId)->id);
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('name="attendance_policy_id"', false)->assertSeeText('Evening');

        $this->actingAs($admin)->put($url, $form(['attendance_policy_id' => $evening]))->assertSessionHasNoErrors();
        $this->assertSame($evening, (int) $policyOf());
        $this->assertSame(20, (int) $policies->forCohort($customerId, $cohortId)->late_after_minutes);

        // Another tenant's policy is refused; a form without the field changes nothing.
        $this->actingAs($admin)->put($url, $form(['attendance_policy_id' => $foreign]))
            ->assertSessionHasErrors(['attendance_policy_id' => __('lf.LF_attendance_policy_cohort_invalid')]);
        $this->actingAs($admin)->put($url, $form(['name' => 'Class renamed']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_cohorts', ['id' => $cohortId, 'name' => 'Class renamed', 'attendance_policy_id' => $evening]);

        // Choosing the default, by its id or by the empty option, stores NULL so the Cohort keeps following it.
        $this->actingAs($admin)->put($url, $form(['attendance_policy_id' => $default->id]))->assertSessionHasNoErrors();
        $this->assertNull($policyOf());
        DB::table('core_course_cohorts')->where('id', $cohortId)->update(['attendance_policy_id' => $evening]);
        $this->actingAs($admin)->put($url, $form(['attendance_policy_id' => '']))->assertSessionHasNoErrors();
        $this->assertNull($policyOf());
        $this->assertSame((int) $default->id, (int) $policies->forCohort($customerId, $cohortId)->id);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Policy', 'opens_before_minutes' => 15, 'late_after_minutes' => 10, 'absent_after_minutes' => 30,
            'auto_absent_after_minutes' => 30, 'count_from_actual_start' => '1', 'offline_recording' => 'system',
            'online_recording' => 'system', 'makeup_enabled' => '1', 'makeup_unlimited' => '1', 'teacher_edit_days' => 7,
        ];
    }

    private function policy(int $customerId, string $name, array $values = []): int
    {
        return DB::table(self::TABLE)->insertGetId($values + [
            'customer_id' => $customerId, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cohort(int $customerId, string $name, string $status, ?int $policyId): int
    {
        return DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $customerId, 'name' => $name, 'code' => 'COH-'.strtoupper(substr(md5($name.$customerId), 0, 8)),
            'status' => $status, 'attendance_policy_id' => $policyId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function tenant(string $slug = 'tenant-a'): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(int $customerId, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customerId, 'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test', 'password' => Hash::make('password123'),
            'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }
}
