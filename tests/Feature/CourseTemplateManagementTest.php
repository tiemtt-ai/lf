<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
            'media.disk' => 'media_local',
            'media.bucket' => 'lf-test-media',
        ]);
        Storage::fake('media_local');
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
        $formPartial = file_get_contents(
            base_path('resources/views/course-templates/partials/form.blade.php')
        );

        $this->assertStringNotContainsString('admin-form-section', $formPartial);
        $this->assertStringNotContainsString('course-template-basic-title', $formPartial);
        $this->assertStringNotContainsString('course-template-metadata-title', $formPartial);
        $this->assertStringNotContainsString('course-template-media-title', $formPartial);
        $this->assertStringNotContainsString('course-template-lifecycle-title', $formPartial);

        foreach ($responses as $response) {
            foreach ([
                'title',
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
                'cover_type',
                'cover_image_file',
                'intro_video_file',
                'cover_image_media_file_id',
                'intro_video_media_file_id',
                'difficulty_level',
                'max_lessons',
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
                    'publisher_name',
                    'difficulty_level',
                ],
                $this->backendColumnFieldNames($response->getContent(), 0)
            );
            $this->assertSame(
                [
                    'estimated_duration_minutes',
                    'max_lessons',
                    'cover_type',
                    'status',
                ],
                $this->backendColumnFieldNames($response->getContent(), 1)
            );
            $this->assertFalse($this->fieldIsInsideBackendColumn(
                $response->getContent(),
                'short_description'
            ));
            $this->assertFalse($this->fieldIsInsideBackendColumn(
                $response->getContent(),
                'description'
            ));
            $this->assertStringContainsString(
                'name="slug"',
                $response->getContent()
            );
            $this->assertStringContainsString('readonly', $response->getContent());
            $this->assertManualSeoControlsNotRendered(
                $response->getContent(),
                'course-template-seo-title',
                'LF_course_template'
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
                    'cover_type' => 'video',
                    'cover_image_file' => null,
                    'intro_video_file' => UploadedFile::fake()->create(
                        'intro-video.mp4',
                        32,
                        'video/mp4'
                    ),
                    'difficulty_level' => 'beginner',
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

    public function test_preview_media_is_optional_for_course_template(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Optional Preview Course',
                    'slug' => 'optional-preview-course',
                    'cover_image_file' => null,
                    'intro_video_file' => null,
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates');

        $this->assertDatabaseHas('core_course_templates', [
            'customer_id' => $customerId,
            'slug' => 'optional-preview-course',
            'cover_type' => 'image',
            'cover_image_media_file_id' => null,
            'intro_video_media_file_id' => null,
        ]);
        $this->assertDatabaseMissing('media_file_usages', [
            'customer_id' => $customerId,
            'owner_type' => 'course_template',
            'usage_type' => 'cover_image',
            'status' => 'active',
        ]);
    }

    public function test_template_update_without_manual_seo_inputs_preserves_existing_seo_data(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'SEO Template',
            'seo-template',
            $admin->id
        );

        DB::table('core_course_templates')
            ->where('id', $templateId)
            ->update([
                'meta_title' => 'Legacy SEO Title',
                'meta_description' => 'Legacy SEO description',
                'meta_keywords' => 'legacy,seo',
            ]);

        $data = $this->validTemplateData([
            'title' => 'SEO Template Updated',
            'slug' => 'seo-template-updated',
        ]);
        unset($data['meta_title'], $data['meta_description'], $data['meta_keywords']);

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}",
                $data
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit"
            );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'title' => 'SEO Template Updated',
            'slug' => 'seo-template-updated',
            'meta_title' => 'Legacy SEO Title',
            'meta_description' => 'Legacy SEO description',
            'meta_keywords' => 'legacy,seo',
        ]);
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
                'cover_type' => 'document',
                'estimated_duration_minutes' => -1,
                'status' => 'inactive',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates/create')
            ->assertSessionHasErrors([
                'category_id',
                'title',
                'cover_type',
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
                    'title' => 'TOPIK',
                ])
            )
            ->assertSessionHasErrors('slug');

        $this->actingAs($otherAdmin)
            ->post(
                'https://tenant-b.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'TOPIK',
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

    private function validTemplateData(array $overrides = []): array
    {
        return array_merge([
            'category_id' => null,
            'title' => 'Programming Basics',
            'short_description' => null,
            'description' => null,
            'publisher_name' => null,
            'cover_type' => 'image',
            'cover_image_file' => UploadedFile::fake()->image(
                'template-cover.png',
                120,
                80
            ),
            'difficulty_level' => null,
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

    private function backendColumnFieldNames(string $html, int $index): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $columnPosition = $index + 1;
        $labels = $xpath->query(
            '(//div[contains(concat(" ", normalize-space(@class), " "),'
            .' " backend-form-column ")])['.$columnPosition.']//label[@for]'
        );
        $fields = [];

        foreach ($labels as $label) {
            $fields[] = $label->getAttribute('for');
        }

        return $fields;
    }

    private function fieldIsInsideBackendColumn(string $html, string $field): bool
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);
        $ancestors = $xpath->query(
            '//label[@for="'.$field.'"]/ancestor::div[contains(concat(" ", normalize-space(@class), " "),'
            .' " backend-form-column ")]'
        );

        return $ancestors->length > 0;
    }

    private function assertManualSeoControlsNotRendered(
        string $html,
        string $sectionTitleId,
        string $translationPrefix
    ): void {
        $this->assertStringNotContainsString($sectionTitleId, $html);
        $this->assertStringNotContainsString('name="meta_title"', $html);
        $this->assertStringNotContainsString('name="meta_description"', $html);
        $this->assertStringNotContainsString('name="meta_keywords"', $html);
        $this->assertStringNotContainsString(
            __('lf.'.$translationPrefix.'_group_seo'),
            $html
        );
        $this->assertStringNotContainsString(
            __('lf.'.$translationPrefix.'_common_meta_title'),
            $html
        );
        $this->assertStringNotContainsString(
            __('lf.'.$translationPrefix.'_common_meta_description'),
            $html
        );
        $this->assertStringNotContainsString(
            __('lf.'.$translationPrefix.'_common_meta_keywords'),
            $html
        );
    }
}
