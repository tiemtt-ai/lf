<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CourseTemplateManagementTest extends TestCase
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

    public function test_admin_and_teacher_can_view_only_their_tenant_templates(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $this->createTemplate(
            $customerId,
            'TOPIK Beginner',
            'topik-beginner',
            $teacher->id
        );
        $this->createTemplate(
            $otherCustomerId,
            'Private Tenant Template',
            'private-template'
        );

        foreach ([
            [$admin, 'admin'],
            [$teacher, 'teacher'],
        ] as [$user, $area]) {
            $this->actingAs($user)
                ->get("https://tenant-a.localhost/{$area}/course-templates")
                ->assertOk()
                ->assertSeeText('TOPIK Beginner')
                ->assertSeeText('Template khóa học')
                ->assertDontSeeText('Private Tenant Template');
        }
    }

    public function test_template_edit_uses_the_five_authoring_tabs(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate(
            $customerId,
            'Tabbed Template',
            'tabbed-template',
            $teacher->id
        );
        $tabs = [
            'information' => 'Thông tin',
            'structure' => 'Nội dung',
            'teachers' => 'Giáo viên',
            'publish' => 'Xuất bản',
            'history' => 'Lịch sử',
        ];

        foreach ([
            [$admin, 'admin'],
            [$teacher, 'teacher'],
        ] as [$user, $area]) {
            foreach ($tabs as $tab => $label) {
                $response = $this->actingAs($user)
                    ->get(
                        "https://tenant-a.localhost/{$area}/course-templates/"
                        ."{$templateId}/edit?tab={$tab}"
                    )
                    ->assertOk()
                    ->assertSeeText($label);

                $this->assertActiveAuthoringTab(
                    $response->getContent(),
                    $tab,
                    array_keys($tabs)
                );
            }
        }

        $this->actingAs($admin)
            ->get(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit?tab=publish"
            )
            ->assertSeeText('Bản nháp hiện tại')
            ->assertSeeText('Bản nháp · Bản chỉnh sửa 1')
            ->assertSeeText('Phiên bản đã xuất bản hiện tại')
            ->assertSeeText('Lần xuất bản gần nhất')
            ->assertSeeText('Xuất bản')
            ->assertDontSee('course-template-publish-button" disabled', false);

        $this->actingAs($admin)
            ->get(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit?tab=history"
            )
            ->assertSeeText('Chưa có phiên bản nào được xuất bản.');

        $emptyStructure = $this->actingAs($admin)
            ->get(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit?tab=structure"
            )
            ->assertOk()
            ->assertSeeText('Cấu trúc khóa học')
            ->assertSeeText('Danh sách bài học')
            ->assertSeeText('Theo phần học')
            ->assertSeeText('+ Thêm bài học')
            ->assertSeeText('+ Thêm phần học')
            ->assertSeeText('Chưa có bài học trực tiếp.')
            ->assertSeeText('Chưa có phần học nào.')
            ->assertDontSeeText(
                'Khóa học này đang sử dụng cả bài học trực tiếp và phần học.'
            )
            ->assertSee('role="tablist"', false)
            ->assertSee('x-on:click="selectStructureTab(\'direct\')"', false)
            ->assertSee('x-on:click="selectStructureTab(\'sections\')"', false)
            ->assertSee('x-show="activeStructureTab === \'direct\'"', false)
            ->assertSee('x-show="activeStructureTab === \'sections\'"', false)
            ->assertDontSee('course-template-mode-card', false);
    }

    public function test_create_and_edit_labels_follow_the_validation_required_rules(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Required Label Course',
            'required-label-course'
        );

        $responses = [
            $this->actingAs($admin)
                ->get('https://tenant-a.localhost/admin/course-templates/create')
                ->assertOk(),
            $this->actingAs($admin)
                ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
                ->assertOk(),
        ];

        foreach ($responses as $response) {
            foreach ([
                'title',
                'slug',
                'thumbnail_type',
                'estimated_duration_minutes',
                'status',
            ] as $field) {
                $this->assertSame(
                    1,
                    $this->requiredIndicatorCount($response->getContent(), $field),
                    "Expected {$field} to have one required indicator."
                );
            }

            foreach ([
                'category_id',
                'short_description',
                'description',
                'publisher_name',
                'thumbnail_image',
                'cover_image_file',
                'thumbnail_video_source',
                'thumbnail_video_url',
                'thumbnail_video_media_id',
                'difficulty_level',
                'language',
                'max_lessons',
                'meta_title',
                'meta_description',
                'meta_keywords',
            ] as $field) {
                $this->assertSame(
                    0,
                    $this->requiredIndicatorCount($response->getContent(), $field),
                    "Expected {$field} to remain optional."
                );
            }

            $this->assertSame(
                [
                    'category_id',
                    'title',
                    'slug',
                    'short_description',
                    'description',
                    'publisher_name',
                ],
                $this->sectionFieldNames(
                    $response->getContent(),
                    'course-template-basic-title'
                )
            );
            $this->assertSame(
                [
                    'thumbnail_type',
                    'thumbnail_image',
                    'cover_image_file',
                    'thumbnail_video_source',
                    'thumbnail_video_url',
                    'thumbnail_video_media_id',
                ],
                $this->sectionFieldNames(
                    $response->getContent(),
                    'course-template-media-title'
                )
            );
            $this->assertSame(
                [
                    'difficulty_level',
                    'language',
                    'estimated_duration_minutes',
                    'max_lessons',
                ],
                $this->sectionFieldNames(
                    $response->getContent(),
                    'course-template-metadata-title'
                )
            );
            $this->assertSame(
                ['meta_title', 'meta_description', 'meta_keywords'],
                $this->sectionFieldNames(
                    $response->getContent(),
                    'course-template-seo-title'
                )
            );
            $this->assertSame(
                ['status'],
                $this->sectionFieldNames(
                    $response->getContent(),
                    'course-template-lifecycle-title'
                )
            );
        }
    }

    public function test_admin_can_create_an_independent_draft_template(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'Korean', 'korean');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'category_id' => $categoryId,
                    'title' => 'TOPIK Beginner 1',
                    'slug' => 'topik-beginner-1',
                    'short_description' => 'TOPIK foundation',
                    'description' => 'Detailed TOPIK foundation course.',
                    'publisher_name' => 'Visang',
                    'thumbnail_type' => 'video',
                    'thumbnail_video_source' => 'youtube',
                    'thumbnail_video_url' => 'https://www.youtube.com/watch?v=example',
                    'difficulty_level' => 'beginner',
                    'language' => 'ko',
                    'estimated_duration_minutes' => 2400,
                    'max_lessons' => 40,
                    'meta_title' => 'TOPIK Beginner',
                    'meta_description' => 'Learn TOPIK from the beginning.',
                    'meta_keywords' => 'topik,korean',
                    'status' => 'draft',
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates');

        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('slug', 'topik-beginner-1')
            ->first();

        $this->assertNotNull($template);
        $this->assertSame($admin->id, (int) $template->created_by);
        $this->assertSame(0, (int) $template->lesson_count);
        $this->assertSame(1, (int) $template->working_revision);
        $this->assertSame('draft', $template->status);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('core_course_template_sections'));
        $this->assertDatabaseCount('core_course_template_sections', 0);
    }

    public function test_teacher_can_create_an_independent_draft_template(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');

        $this->actingAs($teacher)
            ->post(
                'https://tenant-a.localhost/teacher/course-templates',
                $this->validTemplateData([
                    'title' => 'Teacher Course',
                    'slug' => 'teacher-course',
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/teacher/course-templates');

        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('slug', 'teacher-course')
            ->first();

        $this->assertNotNull($template);
        $this->assertSame('draft', $template->status);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('core_course_template_sections'));
        $this->assertDatabaseCount('core_course_template_sections', 0);
    }

    public function test_validation_and_tenant_scoped_category_selection_are_enforced(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherCategoryId = $this->createCategory(
            $otherCustomerId,
            'Private Category',
            'private-category'
        );

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-templates/create')
            ->post('https://tenant-a.localhost/admin/course-templates', [
                'category_id' => $otherCategoryId,
                'title' => '',
                'slug' => '',
                'thumbnail_type' => 'document',
                'estimated_duration_minutes' => -1,
                'status' => 'inactive',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates/create')
            ->assertSessionHasErrors([
                'category_id',
                'title',
                'slug',
                'thumbnail_type',
                'estimated_duration_minutes',
                'status',
            ]);

        $this->assertDatabaseCount('core_course_templates', 0);
    }

    public function test_slug_is_unique_per_tenant_and_reusable_by_another_tenant(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $this->createTemplate($customerId, 'TOPIK', 'topik');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Duplicate TOPIK',
                    'slug' => 'topik',
                ])
            )
            ->assertSessionHasErrors('slug');

        $this->actingAs($otherAdmin)
            ->post(
                'https://tenant-b.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Tenant B TOPIK',
                    'slug' => 'topik',
                ])
            )
            ->assertRedirect('https://tenant-b.localhost/admin/course-templates');

        $this->assertDatabaseHas('core_course_templates', [
            'customer_id' => $otherCustomerId,
            'slug' => 'topik',
        ]);
    }

    public function test_update_and_delete_are_tenant_isolated(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $ownTemplateId = $this->createTemplate(
            $customerId,
            'Own Course',
            'own-course'
        );
        $otherTemplateId = $this->createTemplate(
            $otherCustomerId,
            'Other Course',
            'other-course'
        );

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$otherTemplateId}/edit")
            ->assertNotFound();

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-templates/{$otherTemplateId}",
                $this->validTemplateData([
                    'title' => 'Changed Other Course',
                    'slug' => 'changed-other-course',
                ])
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->delete(
                "https://tenant-a.localhost/admin/course-templates/{$otherTemplateId}"
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-templates/{$ownTemplateId}",
                $this->validTemplateData([
                    'title' => 'Archived Own Course',
                    'slug' => 'archived-own-course',
                    'status' => 'archived',
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$ownTemplateId}/edit"
            );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $ownTemplateId,
            'customer_id' => $customerId,
            'title' => 'Archived Own Course',
            'status' => 'archived',
            'working_revision' => 2,
        ]);
        $this->assertDatabaseHas('core_course_templates', [
            'id' => $otherTemplateId,
            'customer_id' => $otherCustomerId,
            'title' => 'Other Course',
        ]);
    }

    public function test_unreferenced_draft_template_can_be_deleted(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Protected Course',
            'protected-course'
        );

        $this->actingAs($admin)
            ->delete(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}"
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates');

        $this->assertDatabaseMissing('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
        ]);
    }

    public function test_guest_and_student_cannot_access_template_management(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');

        $this->get('https://tenant-a.localhost/admin/course-templates')
            ->assertRedirect('https://tenant-a.localhost/login');

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/admin/course-templates')
            ->assertForbidden();

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/teacher/course-templates')
            ->assertForbidden();
    }

    public function test_course_template_module_has_no_eloquent_models(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/CoreCourseTemplate.php'));
        $this->assertFileDoesNotExist(app_path('Models/CourseTemplate.php'));
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

    private function createCategory(int $customerId, string $name, string $slug): int
    {
        return DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId,
            'parent_id' => null,
            'name' => $name,
            'slug' => $slug,
            'description' => null,
            'thumbnail_image' => null,
            'banner_image' => null,
            'sort_order' => 0,
            'is_featured' => false,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'status' => 'active',
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
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
            'thumbnail_type' => 'image',
            'thumbnail_image' => null,
            'thumbnail_video_source' => null,
            'thumbnail_video_url' => null,
            'thumbnail_video_media_id' => null,
            'difficulty_level' => null,
            'language' => null,
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

    private function validTemplateData(array $overrides = []): array
    {
        return array_merge([
            'category_id' => null,
            'title' => 'Programming Basics',
            'slug' => 'programming-basics',
            'short_description' => null,
            'description' => null,
            'publisher_name' => null,
            'thumbnail_type' => 'image',
            'thumbnail_image' => null,
            'thumbnail_video_source' => null,
            'thumbnail_video_url' => null,
            'thumbnail_video_media_id' => null,
            'difficulty_level' => null,
            'language' => 'vi',
            'estimated_duration_minutes' => 0,
            'max_lessons' => null,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'status' => 'draft',
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

    private function assertActiveAuthoringTab(
        string $html,
        string $activeTab,
        array $tabs
    ): void {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);

        foreach ($tabs as $tab) {
            $panel = $xpath->query(
                sprintf('//*[@id="course-template-tab-%s"]', $tab)
            )->item(0);

            $this->assertNotNull($panel);
            $this->assertSame(
                $tab !== $activeTab,
                $panel->hasAttribute('hidden')
            );
        }

        $activeLinks = $xpath->query(
            '//a[contains(concat(" ", normalize-space(@class), " "),'
            .' " course-template-tab ") and @aria-current="page"]'
        );

        $this->assertSame(1, $activeLinks->length);
    }

    private function sectionFieldNames(string $html, string $titleId): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $labels = $xpath->query(sprintf(
            '//section[@aria-labelledby="%s"]//label[@for]',
            $titleId
        ));
        $fields = [];

        foreach ($labels as $label) {
            $fields[] = $label->getAttribute('for');
        }

        return $fields;
    }
}
