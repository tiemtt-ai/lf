<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CourseTemplateTeacherManagementTest extends TestCase
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

    public function test_admin_and_teacher_can_view_assignments_on_template_edit(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $viewer = $this->createUser($customerId, 'teacher', 'Viewer');
        $assignedTeacher = $this->createUser(
            $customerId,
            'teacher',
            'Assigned Teacher'
        );
        $otherTeacher = $this->createUser(
            $otherCustomerId,
            'teacher',
            'Private Teacher'
        );
        $templateId = $this->createTemplate(
            $customerId,
            'Own Template',
            $viewer->id
        );
        $otherTemplateId = $this->createTemplate(
            $otherCustomerId,
            'Private Template'
        );
        $assignmentId = $this->createAssignment(
            $customerId,
            $templateId,
            $assignedTeacher->id,
            'primary'
        );
        $this->createAssignment(
            $otherCustomerId,
            $otherTemplateId,
            $otherTeacher->id,
            'assistant'
        );

        foreach ([
            [$admin, 'admin'],
            [$viewer, 'teacher'],
        ] as [$user, $area]) {
            $this->actingAs($user)
                ->get(
                    "https://tenant-a.localhost/{$area}/course-templates/"
                    ."{$templateId}/edit?tab=teachers"
                )
                ->assertOk()
                ->assertSeeText('Giáo viên phụ trách')
                ->assertSeeText('+ Thêm giáo viên')
                ->assertSeeText('Assigned Teacher')
                ->assertSeeText($assignedTeacher->email)
                ->assertSeeText('Giáo viên chính')
                ->assertSeeText(
                    'Bạn có chắc chắn muốn xóa giáo viên khỏi Template này không?'
                )
                ->assertDontSeeText('Private Teacher')
                ->assertSee(
                    $this->assignmentCollectionUrl(
                        $area,
                        $templateId
                    )."/{$assignmentId}/edit",
                    false
                );
        }
    }

    public function test_admin_can_assign_and_update_an_own_tenant_teacher(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser(
            $customerId,
            'teacher',
            'Course Teacher'
        );
        $templateId = $this->createTemplate($customerId, 'Admin Template');
        $url = $this->assignmentCollectionUrl('admin', $templateId);

        $this->actingAs($admin)
            ->post($url, $this->validAssignmentData([
                'teacher_id' => $teacher->id,
                'role' => 'primary',
                'sort_order' => 2,
            ]))
            ->assertRedirect(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit?tab=teachers#course-template-teachers"
            );

        $assignment = DB::table('core_course_template_teachers')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('teacher_id', $teacher->id)
            ->first();

        $this->assertNotNull($assignment);
        $this->assertSame('primary', $assignment->role);
        $this->assertSame(2, (int) $assignment->sort_order);
        $this->assertSame($admin->id, (int) $assignment->assigned_by);
        $this->assertNotNull($assignment->assigned_at);

        $this->actingAs($admin)
            ->put("{$url}/{$assignment->id}", [
                'role' => 'reviewer',
                'sort_order' => 5,
                'status' => 'inactive',
            ])
            ->assertRedirect("{$url}/{$assignment->id}/edit");

        $this->assertDatabaseHas('core_course_template_teachers', [
            'id' => $assignment->id,
            'customer_id' => $customerId,
            'teacher_id' => $teacher->id,
            'role' => 'reviewer',
            'sort_order' => 5,
            'status' => 'inactive',
        ]);
    }

    public function test_teacher_can_assign_teacher_inside_own_tenant(): void
    {
        $customerId = $this->createTenant();
        $actor = $this->createUser($customerId, 'teacher', 'Actor Teacher');
        $assignedTeacher = $this->createUser(
            $customerId,
            'teacher',
            'Peer Teacher'
        );
        $templateId = $this->createTemplate(
            $customerId,
            'Teacher Template',
            $actor->id
        );

        $this->actingAs($actor)
            ->post(
                $this->assignmentCollectionUrl('teacher', $templateId),
                $this->validAssignmentData([
                    'teacher_id' => $assignedTeacher->id,
                ])
            )
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_template_teachers', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'teacher_id' => $assignedTeacher->id,
            'assigned_by' => $actor->id,
        ]);
    }

    public function test_assignment_validation_rejects_invalid_users_and_values(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student', 'Student');
        $teacher = $this->createUser($customerId, 'teacher', 'Teacher');
        $otherTeacher = $this->createUser(
            $otherCustomerId,
            'teacher',
            'Other Teacher'
        );
        $templateId = $this->createTemplate($customerId, 'Validation Template');
        $url = $this->assignmentCollectionUrl('admin', $templateId);

        foreach ([$student->id, $otherTeacher->id] as $invalidTeacherId) {
            $this->actingAs($admin)
                ->post($url, $this->validAssignmentData([
                    'teacher_id' => $invalidTeacherId,
                ]))
                ->assertSessionHasErrors('teacher_id');
        }

        $this->actingAs($admin)
            ->post($url, [
                'teacher_id' => $teacher->id,
                'role' => 'owner',
                'sort_order' => -1,
                'status' => 'archived',
            ])
            ->assertSessionHasErrors([
                'role',
                'sort_order',
                'status',
            ]);

        $this->assertDatabaseCount('core_course_template_teachers', 0);
    }

    public function test_same_teacher_cannot_be_assigned_twice_to_a_template(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher', 'Teacher');
        $templateId = $this->createTemplate($customerId, 'Unique Template');
        $url = $this->assignmentCollectionUrl('admin', $templateId);
        $this->createAssignment(
            $customerId,
            $templateId,
            $teacher->id
        );

        $this->actingAs($admin)
            ->post($url, $this->validAssignmentData([
                'teacher_id' => $teacher->id,
            ]))
            ->assertSessionHasErrors('teacher_id');

        $this->assertDatabaseCount('core_course_template_teachers', 1);
    }

    public function test_assignment_access_and_mutations_are_tenant_isolated(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherTeacher = $this->createUser(
            $otherCustomerId,
            'teacher',
            'Other Teacher'
        );
        $ownTemplateId = $this->createTemplate($customerId, 'Own Template');
        $otherTemplateId = $this->createTemplate(
            $otherCustomerId,
            'Other Template'
        );
        $otherAssignmentId = $this->createAssignment(
            $otherCustomerId,
            $otherTemplateId,
            $otherTeacher->id
        );
        $otherUrl = $this->assignmentCollectionUrl(
            'admin',
            $otherTemplateId
        );

        $this->actingAs($admin)
            ->get("{$otherUrl}/create")
            ->assertNotFound();
        $this->actingAs($admin)
            ->get("{$otherUrl}/{$otherAssignmentId}/edit")
            ->assertNotFound();
        $this->actingAs($admin)
            ->put(
                "{$otherUrl}/{$otherAssignmentId}",
                $this->validAssignmentData()
            )
            ->assertNotFound();
        $this->actingAs($admin)
            ->delete("{$otherUrl}/{$otherAssignmentId}")
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(
                $this->assignmentCollectionUrl(
                    'admin',
                    $ownTemplateId
                )."/{$otherAssignmentId}/edit"
            )
            ->assertNotFound();

        $this->assertDatabaseHas('core_course_template_teachers', [
            'id' => $otherAssignmentId,
            'status' => 'active',
        ]);
    }

    public function test_remove_deactivates_assignment_without_deleting_it(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher', 'Teacher');
        $templateId = $this->createTemplate($customerId, 'Removal Template');
        $assignmentId = $this->createAssignment(
            $customerId,
            $templateId,
            $teacher->id
        );

        $this->actingAs($admin)
            ->delete(
                $this->assignmentCollectionUrl('admin', $templateId)
                ."/{$assignmentId}"
            )
            ->assertRedirect(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit?tab=teachers#course-template-teachers"
            );

        $this->assertDatabaseHas('core_course_template_teachers', [
            'id' => $assignmentId,
            'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
        ]);
    }

    public function test_assignment_forms_render_documented_fields_and_indicators(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher', 'Teacher');
        $templateId = $this->createTemplate($customerId, 'UI Template');
        $assignmentId = $this->createAssignment(
            $customerId,
            $templateId,
            $teacher->id
        );
        $url = $this->assignmentCollectionUrl('admin', $templateId);

        $createResponse = $this->actingAs($admin)
            ->get("{$url}/create")
            ->assertOk()
            ->assertSeeText('Thông tin giáo viên')
            ->assertSeeText('Vai trò trong Template')
            ->assertSeeText('Trạng thái phân công');

        foreach (['teacher_id', 'role', 'sort_order', 'status'] as $field) {
            $this->assertSame(
                1,
                $this->requiredIndicatorCount(
                    $createResponse->getContent(),
                    $field
                )
            );
        }

        $editResponse = $this->actingAs($admin)
            ->get("{$url}/{$assignmentId}/edit")
            ->assertOk()
            ->assertSeeText('Teacher')
            ->assertDontSee('name="teacher_id"', false);

        foreach (['role', 'sort_order', 'status'] as $field) {
            $this->assertSame(
                1,
                $this->requiredIndicatorCount(
                    $editResponse->getContent(),
                    $field
                )
            );
        }
    }

    public function test_guest_and_student_cannot_access_teacher_assignments(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student', 'Student');
        $templateId = $this->createTemplate($customerId, 'Protected Template');
        $url = $this->assignmentCollectionUrl('admin', $templateId).'/create';

        $this->get($url)
            ->assertRedirect('https://tenant-a.localhost/login');
        $this->actingAs($student)
            ->get($url)
            ->assertForbidden();
    }

    public function test_course_template_teacher_module_has_no_eloquent_models(): void
    {
        $this->assertFileDoesNotExist(
            app_path('Models/CoreCourseTemplateTeacher.php')
        );
        $this->assertFileDoesNotExist(
            app_path('Models/CourseTemplateTeacher.php')
        );
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

    private function createUser(
        int $customerId,
        string $role,
        string $name = 'User'
    ): User {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name))
                .'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function createTemplate(
        int $customerId,
        string $title,
        ?int $createdBy = null
    ): int {
        return DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId,
            'category_id' => null,
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)).'-'.uniqid(),
            'short_description' => null,
            'description' => null,
            'publisher_name' => null,
            'cover_type' => 'image',
            'cover_image_media_file_id' => null,
            'intro_video_media_file_id' => null,
            'difficulty_level' => null,
            'estimated_duration_minutes' => 0,
            'max_lessons' => null,
            'lesson_count' => 0,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'working_revision' => 1,
            'status' => 'draft',
            'created_by' => $createdBy,
            'last_version_published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAssignment(
        int $customerId,
        int $templateId,
        int $teacherId,
        string $role = 'assistant'
    ): int {
        return DB::table('core_course_template_teachers')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'teacher_id' => $teacherId,
            'role' => $role,
            'sort_order' => 0,
            'status' => 'active',
            'assigned_by' => null,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validAssignmentData(array $overrides = []): array
    {
        return array_merge([
            'teacher_id' => null,
            'role' => 'assistant',
            'sort_order' => 0,
            'status' => 'active',
        ], $overrides);
    }

    private function assignmentCollectionUrl(
        string $area,
        int $templateId
    ): string {
        return "https://tenant-a.localhost/{$area}/course-templates/"
            ."{$templateId}/teachers";
    }

    private function requiredIndicatorCount(string $html, string $field): int
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $query = sprintf(
            '//label[@for="%s"]//span[contains(concat(" ", normalize-space(@class), " "), " lf-required-indicator ")]',
            $field
        );

        return $xpath->query($query)->length;
    }
}
