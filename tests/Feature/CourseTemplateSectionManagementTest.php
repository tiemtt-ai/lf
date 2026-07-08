<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CourseTemplateSectionManagementTest extends TestCase
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

    public function test_admin_and_teacher_can_view_sections_on_the_template_edit_page(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate(
            $customerId,
            'TOPIK Beginner',
            'topik-beginner',
            $teacher->id
        );
        $otherTemplateId = $this->createTemplate(
            $otherCustomerId,
            'Private Template',
            'private-template'
        );
        $this->createSection($customerId, $templateId, 'Hangul Fundamentals', 1);
        $this->createSection(
            $otherCustomerId,
            $otherTemplateId,
            'Private Tenant Section',
            1
        );

        foreach ([
            [$admin, 'admin'],
            [$teacher, 'teacher'],
        ] as [$user, $area]) {
            $this->actingAs($user)
                ->get(
                    "https://tenant-a.localhost/{$area}/course-templates/"
                    ."{$templateId}/edit"
                )
                ->assertOk()
                ->assertSeeText('Cấu trúc khóa học')
                ->assertSeeText('Hangul Fundamentals')
                ->assertDontSeeText('Private Tenant Section');
        }
    }

    public function test_admin_can_create_a_section_with_documented_fields(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'TOPIK Beginner',
            'topik-beginner'
        );

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/sections",
                $this->validSectionData([
                    'code' => 'M01',
                    'title' => 'Hangul Fundamentals',
                    'short_title' => 'Hangul',
                    'description' => 'Korean alphabet foundation',
                    'thumbnail_file_id' => 10,
                    'sort_order' => 2,
                    'is_required' => 1,
                    'unlock_rule' => 'immediate',
                    'estimated_duration_minutes' => 240,
                    'status' => 'active',
                    'metadata' => '{"color":"#0EA5E9"}',
                ])
            )
            ->assertRedirect(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit?tab=structure#course-template-sections"
            );

        $this->assertDatabaseHas('core_course_template_sections', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'parent_section_id' => null,
            'code' => 'M01',
            'title' => 'Hangul Fundamentals',
            'short_title' => 'Hangul',
            'thumbnail_file_id' => 10,
            'sort_order' => 2,
            'is_required' => 1,
            'unlock_rule' => 'immediate',
            'estimated_duration_minutes' => 240,
            'total_lessons' => 0,
            'status' => 'active',
            'metadata' => '{"color":"#0EA5E9"}',
        ]);
    }

    public function test_teacher_can_create_a_section_for_an_own_tenant_template(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate(
            $customerId,
            'Teacher Course',
            'teacher-course',
            $teacher->id
        );

        $this->actingAs($teacher)
            ->post(
                'https://tenant-a.localhost/teacher/course-templates/'
                ."{$templateId}/sections",
                $this->validSectionData(['title' => 'Teacher Section'])
            )
            ->assertRedirect(
                'https://tenant-a.localhost/teacher/course-templates/'
                ."{$templateId}/edit?tab=structure#course-template-sections"
            );

        $this->assertDatabaseHas('core_course_template_sections', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'title' => 'Teacher Section',
        ]);
    }

    public function test_validation_and_root_order_uniqueness_are_enforced(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Validation Course',
            'validation-course'
        );
        $this->createSection($customerId, $templateId, 'Existing', 1, code: 'M01');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/sections",
                [
                    'code' => 'M01',
                    'title' => '',
                    'sort_order' => 1,
                    'is_required' => 'invalid',
                    'unlock_rule' => 'invalid',
                    'estimated_duration_minutes' => -1,
                    'status' => 'draft',
                    'metadata' => '{invalid-json}',
                ]
            )
            ->assertSessionHasErrors([
                'code',
                'title',
                'sort_order',
                'is_required',
                'unlock_rule',
                'estimated_duration_minutes',
                'status',
                'metadata',
            ]);

        $this->assertDatabaseCount('core_course_template_sections', 1);
    }

    public function test_template_and_section_access_are_tenant_isolated(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $ownTemplateId = $this->createTemplate(
            $customerId,
            'Own Template',
            'own-template'
        );
        $otherTemplateId = $this->createTemplate(
            $otherCustomerId,
            'Other Template',
            'other-template'
        );
        $otherSectionId = $this->createSection(
            $otherCustomerId,
            $otherTemplateId,
            'Other Section',
            1
        );

        $this->actingAs($admin)
            ->get(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$otherTemplateId}/sections/create"
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$otherTemplateId}/sections",
                $this->validSectionData(['title' => 'Cross Tenant'])
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$ownTemplateId}/sections/{$otherSectionId}/edit"
            )
            ->assertNotFound();

        $this->assertDatabaseMissing('core_course_template_sections', [
            'customer_id' => $customerId,
            'title' => 'Cross Tenant',
        ]);
    }

    public function test_parent_section_must_belong_to_the_same_template_and_tenant(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Own Template',
            'own-template'
        );
        $secondTemplateId = $this->createTemplate(
            $customerId,
            'Second Template',
            'second-template'
        );
        $otherTemplateId = $this->createTemplate(
            $otherCustomerId,
            'Other Template',
            'other-template'
        );
        $wrongTemplateParentId = $this->createSection(
            $customerId,
            $secondTemplateId,
            'Wrong Template Parent',
            1
        );
        $wrongTenantParentId = $this->createSection(
            $otherCustomerId,
            $otherTemplateId,
            'Wrong Tenant Parent',
            1
        );

        foreach ([$wrongTemplateParentId, $wrongTenantParentId] as $parentId) {
            $this->actingAs($admin)
                ->post(
                    'https://tenant-a.localhost/admin/course-templates/'
                    ."{$templateId}/sections",
                    $this->validSectionData([
                        'parent_section_id' => $parentId,
                        'title' => 'Invalid Child '.$parentId,
                    ])
                )
                ->assertSessionHasErrors('parent_section_id');
        }
    }

    public function test_section_hierarchy_cannot_be_made_circular(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Hierarchy Course',
            'hierarchy-course'
        );
        $parentId = $this->createSection(
            $customerId,
            $templateId,
            'Parent',
            1
        );
        $childId = $this->createSection(
            $customerId,
            $templateId,
            'Child',
            1,
            $parentId
        );

        $this->actingAs($admin)
            ->put(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/sections/{$parentId}",
                $this->validSectionData([
                    'parent_section_id' => $childId,
                    'title' => 'Parent',
                    'sort_order' => 1,
                ])
            )
            ->assertSessionHasErrors('parent_section_id');

        $this->assertDatabaseHas('core_course_template_sections', [
            'id' => $parentId,
            'parent_section_id' => null,
        ]);
    }

    public function test_delete_is_blocked_when_a_child_section_exists(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Protected Course',
            'protected-course'
        );
        $parentId = $this->createSection(
            $customerId,
            $templateId,
            'Parent',
            1
        );
        $this->createSection(
            $customerId,
            $templateId,
            'Child',
            1,
            $parentId
        );

        $this->actingAs($admin)
            ->delete(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/sections/{$parentId}"
            )
            ->assertSessionHasErrors('section');

        $this->assertDatabaseHas('core_course_template_sections', [
            'id' => $parentId,
        ]);
    }

    public function test_delete_is_blocked_when_the_lessons_table_has_a_reference(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Lesson Course',
            'lesson-course'
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Referenced Section',
            1
        );

        DB::table('core_course_template_lessons')->insert([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_section_id' => $sectionId,
            'title' => 'Referenced Lesson',
            'slug' => 'referenced-lesson',
            'short_description' => null,
            'description' => null,
            'sort_order' => 0,
            'is_preview' => false,
            'learning_objective' => null,
            'duration_seconds' => 0,
            'activity_count' => 0,
            'unlock_rule' => 'none',
            'unlock_after_lesson_id' => null,
            'unlock_at' => null,
            'status' => 'draft',
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->delete(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/sections/{$sectionId}"
            )
            ->assertSessionHasErrors('section');

        $this->assertDatabaseHas('core_course_template_sections', [
            'id' => $sectionId,
        ]);
    }

    public function test_delete_confirmation_and_required_indicators_are_rendered(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'UI Course',
            'ui-course'
        );
        $this->createSection($customerId, $templateId, 'UI Section', 1);

        $this->actingAs($admin)
            ->get(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit"
            )
            ->assertOk()
            ->assertSeeText('+ Thêm phần học')
            ->assertSeeText('Phần học')
            ->assertSeeText('Sửa phần học')
            ->assertSeeText('Xóa')
            ->assertSeeText('Bạn có chắc chắn muốn xóa phần học này không?')
            ->assertSeeText('Có, xóa')
            ->assertSeeText('Không');

        $response = $this->actingAs($admin)
            ->get(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/sections/create"
            )
            ->assertOk();

        foreach ([
            'title',
            'sort_order',
            'is_required',
            'unlock_rule',
            'status',
        ] as $field) {
            $this->assertSame(
                1,
                $this->requiredIndicatorCount($response->getContent(), $field)
            );
        }

        foreach ([
            'parent_section_id',
            'code',
            'short_title',
            'description',
            'thumbnail_file_id',
            'estimated_duration_minutes',
            'metadata',
        ] as $field) {
            $this->assertSame(
                0,
                $this->requiredIndicatorCount($response->getContent(), $field)
            );
        }
    }

    public function test_guest_and_student_cannot_access_section_management(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');
        $templateId = $this->createTemplate(
            $customerId,
            'Restricted Course',
            'restricted-course'
        );

        $url = 'https://tenant-a.localhost/admin/course-templates/'
            ."{$templateId}/sections/create";

        $this->get($url)->assertRedirect('https://tenant-a.localhost/login');
        $this->actingAs($student)->get($url)->assertForbidden();
    }

    public function test_course_template_section_module_has_no_eloquent_models(): void
    {
        $this->assertFileDoesNotExist(
            app_path('Models/CoreCourseTemplateSection.php')
        );
        $this->assertFileDoesNotExist(
            app_path('Models/CourseTemplateSection.php')
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

    private function createUser(int $customerId, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function createTemplate(
        int $customerId,
        string $title,
        string $slug,
        ?int $createdBy = null
    ): int {
        $now = now();

        return DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId,
            'category_id' => null,
            'title' => $title,
            'slug' => $slug,
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
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createSection(
        int $customerId,
        int $templateId,
        string $title,
        int $sortOrder,
        ?int $parentSectionId = null,
        ?string $code = null
    ): int {
        return DB::table('core_course_template_sections')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'parent_section_id' => $parentSectionId,
            'code' => $code,
            'title' => $title,
            'short_title' => null,
            'description' => null,
            'thumbnail_file_id' => null,
            'sort_order' => $sortOrder,
            'is_required' => true,
            'unlock_rule' => 'immediate',
            'estimated_duration_minutes' => null,
            'total_lessons' => 0,
            'status' => 'active',
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validSectionData(array $overrides = []): array
    {
        return array_merge([
            'parent_section_id' => null,
            'code' => null,
            'title' => 'Course Introduction',
            'short_title' => null,
            'description' => null,
            'thumbnail_file_id' => null,
            'sort_order' => 1,
            'is_required' => 1,
            'unlock_rule' => 'immediate',
            'estimated_duration_minutes' => null,
            'status' => 'active',
            'metadata' => null,
        ], $overrides);
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
