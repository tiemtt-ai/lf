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

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher/profile')
            ->assertOk()
            ->assertSeeText('Profile Information')
            ->assertSeeText('Change Password')
            ->assertSeeText('Name')
            ->assertSeeText('Email')
            ->assertSeeText('Phone')
            ->assertSeeText('Date of Birth')
            ->assertSeeText('Gender')
            ->assertSeeText('Role')
            ->assertSee('value="Teacher"', false);

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/student/profile')
            ->assertForbidden();

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/student/profile')
            ->assertOk()
            ->assertSee('value="Student"', false);

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
            ->patch('https://tenant-a.localhost/student/profile', [
                'name' => 'Updated Student',
                'email' => $student->email,
                'phone' => null,
                'date_of_birth' => null,
                'gender' => null,
                'role' => 'teacher',
            ])
            ->assertRedirect('https://tenant-a.localhost/student/profile');

        $this->assertSame('student', $student->fresh()->role);
    }

    public function test_password_can_be_changed_with_the_correct_current_password(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');

        $this->actingAs($teacher)
            ->patch('https://tenant-a.localhost/teacher/profile/password', [
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertRedirect('https://tenant-a.localhost/teacher/profile');

        $this->assertTrue(Hash::check('new-password-456', $teacher->fresh()->password));
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
            ->patch('https://tenant-a.localhost/student/profile/password', $passwordData)
            ->assertForbidden();

        $this->actingAs($student)
            ->patch('https://tenant-a.localhost/teacher/profile/password', $passwordData)
            ->assertForbidden();

        $this->assertTrue(Hash::check('password123', $teacher->fresh()->password));
        $this->assertTrue(Hash::check('password123', $student->fresh()->password));
    }

    private function createTenant(): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'subdomain' => 'tenant-a',
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
