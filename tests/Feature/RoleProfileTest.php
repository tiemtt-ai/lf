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
                'password' => '',
                'password_confirmation' => '',
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
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect('https://tenant-a.localhost/student/profile');

        $this->assertSame('student', $student->fresh()->role);
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
