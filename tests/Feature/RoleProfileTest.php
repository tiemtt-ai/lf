<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleProfileTest extends TestCase
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

    public function test_teacher_and_student_can_only_access_their_own_profile_area(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');

        $teacherProfileResponse = $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher/profile')
            ->assertOk()
            ->assertSeeText(__('lf.LF_profile_title_common_information'))
            ->assertSeeText(__('lf.LF_common_button_common_change_password'))
            ->assertSee('open-modal')
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSeeText(__('lf.LF_common_label_name'))
            ->assertSeeText(__('lf.LF_common_label_email'))
            ->assertSeeText(__('lf.LF_common_label_phone'))
            ->assertSeeText(__('lf.LF_common_label_date_of_birth'))
            ->assertSeeText(__('lf.LF_common_label_gender'))
            ->assertSeeText(__('lf.LF_common_label_role'))
            ->assertSee('value="'.__('lf.LF_common_role_teacher_teacher').'"', false);

        $teacherProfileResponse
            ->assertSee('href="https://tenant-a.localhost/teacher"', false)
            ->assertSee('href="https://tenant-a.localhost/teacher/profile"', false)
            ->assertSee('href="https://tenant-a.localhost/teacher/course-categories"', false)
            ->assertSee('href="https://tenant-a.localhost/teacher/course-templates"', false)
            ->assertDontSee('href="https://tenant-a.localhost/admin"', false)
            ->assertDontSeeText(__('lf.LF_navigation_menu_student_my_courses'))
            ->assertDontSeeText(__('lf.LF_navigation_menu_teacher_live_classes'))
            ->assertDontSeeText(__('lf.LF_navigation_menu_teacher_ai_assistant'))
            ->assertDontSee('class="admin-sidebar-link is-disabled"', false)
            ->assertSee('class="admin-sidebar-menu is-teacher"', false);

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher')
            ->assertOk()
            ->assertSeeText(__('lf.LF_teacher_title_teacher_pending_gradings'))
            ->assertSeeText(__('lf.LF_teacher_title_teacher_upcoming_classes'))
            ->assertSee('href="https://tenant-a.localhost/teacher/profile"', false)
            ->assertDontSee('href="https://tenant-a.localhost/admin"', false);

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/profile')
            ->assertForbidden();

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/profile')
            ->assertOk()
            ->assertSee('value="'.__('lf.LF_common_role_student_student').'"', false);

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/teacher/profile')
            ->assertForbidden();
    }

    public function test_profile_update_only_changes_the_authenticated_user_and_never_the_role(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $otherTeacher = $this->createUser($customerId, 'teacher');
        $originalPassword = $teacher->password;

        $this->actingAs($teacher)
            ->patch('https://tenant-a.localhost/teacher/profile', [
                'name' => 'Updated Teacher',
                'email' => $teacher->email,
                'phone' => '123456789',
                'date_of_birth' => '1990-01-02',
                'gender' => 'other',
                'role' => 'student',
                'id' => $otherTeacher->id,
            ])
            ->assertRedirect('https://tenant-a.localhost/teacher/profile');

        $teacher->refresh();
        $otherTeacher->refresh();

        $this->assertSame('Updated Teacher', $teacher->name);
        $this->assertSame('teacher', $teacher->role);
        $this->assertSame($originalPassword, $teacher->password);
        $this->assertNotSame('Updated Teacher', $otherTeacher->name);

        $this->actingAs($student)
            ->patch('https://tenant-a.localhost/profile', [
                'name' => 'Updated Student',
                'email' => $student->email,
                'phone' => null,
                'date_of_birth' => null,
                'gender' => null,
                'role' => 'teacher',
            ])
            ->assertRedirect('https://tenant-a.localhost/profile');

        $this->assertSame('student', $student->fresh()->role);
    }

    public function test_password_can_be_changed_with_the_correct_current_password(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $otherTeacher = $this->createUser($customerId, 'teacher');
        $crossTenantStudent = $this->createUser($otherCustomerId, 'student');

        $this->actingAs($teacher)
            ->patch('https://tenant-a.localhost/teacher/profile/password', [
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertRedirect('https://tenant-a.localhost/login')
            ->assertSessionHas('status', __('lf.LF_auth_message_password_changed_login_again'));

        $this->assertGuest();
        $this->assertTrue(Hash::check('new-password-456', $teacher->fresh()->password));
        $this->assertTrue(Hash::check('password123', $otherTeacher->fresh()->password));

        $this->post('https://tenant-a.localhost/login', [
            'email' => $teacher->email,
            'password' => 'password123',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('https://tenant-a.localhost/login', [
            'email' => $teacher->email,
            'password' => 'new-password-456',
        ])->assertRedirect('https://tenant-a.localhost/teacher');
        $this->assertAuthenticatedAs($teacher->fresh());

        $this->actingAs($student)
            ->patch('https://tenant-a.localhost/profile/password', [
                'current_password' => 'password123',
                'password' => 'student-password-456',
                'password_confirmation' => 'student-password-456',
            ])
            ->assertRedirect('https://tenant-a.localhost/login')
            ->assertSessionHas('status', __('lf.LF_auth_message_password_changed_login_again'));

        $this->assertGuest();
        $this->assertTrue(Hash::check('student-password-456', $student->fresh()->password));
        $this->assertTrue(Hash::check('password123', $crossTenantStudent->fresh()->password));

        $this->post('https://tenant-a.localhost/login', [
            'email' => $student->email,
            'password' => 'password123',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('https://tenant-a.localhost/login', [
            'email' => $student->email,
            'password' => 'student-password-456',
        ])->assertRedirect('https://tenant-a.localhost');
        $this->assertAuthenticatedAs($student->fresh());
    }

    public function test_password_change_rejects_wrong_current_password_mismatch_and_reuse(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');

        $this->actingAs($teacher)
            ->from('https://tenant-a.localhost/teacher/profile')
            ->patch('https://tenant-a.localhost/teacher/profile/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertRedirect('https://tenant-a.localhost/teacher/profile')
            ->assertSessionHasErrors('current_password', null, 'updatePassword');

        $this->actingAs($teacher)
            ->from('https://tenant-a.localhost/teacher/profile')
            ->patch('https://tenant-a.localhost/teacher/profile/password', [
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'different-password',
            ])
            ->assertRedirect('https://tenant-a.localhost/teacher/profile')
            ->assertSessionHasErrors('password', null, 'updatePassword');

        $this->actingAs($teacher)
            ->from('https://tenant-a.localhost/teacher/profile')
            ->patch('https://tenant-a.localhost/teacher/profile/password', [
                'current_password' => 'password123',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertRedirect('https://tenant-a.localhost/teacher/profile')
            ->assertSessionHasErrors('password', null, 'updatePassword');

        $this->assertTrue(Hash::check('password123', $teacher->fresh()->password));
    }

    public function test_teacher_and_student_cannot_access_each_others_password_route(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $passwordData = [
            'current_password' => 'password123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ];

        $this->actingAs($teacher)
            ->patch('https://tenant-a.localhost/profile/password', $passwordData)
            ->assertForbidden();

        $this->actingAs($student)
            ->patch('https://tenant-a.localhost/teacher/profile/password', $passwordData)
            ->assertForbidden();

        $this->assertTrue(Hash::check('password123', $teacher->fresh()->password));
        $this->assertTrue(Hash::check('password123', $student->fresh()->password));
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
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
