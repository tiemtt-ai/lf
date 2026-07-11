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

        foreach ([[$admin, 'admin'], [$teacher, 'teacher']] as [$user, $area]) {
            $response = $this->actingAs($user)
                ->get(
                    "https://tenant-a.localhost/{$area}/course-templates/"
                    ."{$templateId}/edit"
                )
                ->assertOk()
                ->assertSeeText('Cấu trúc khóa học')
                ->assertSeeText('Hangul Fundamentals')
                ->assertDontSeeText('Private Tenant Section');

            $xpath = $this->xpath($response->getContent());
            $createAction = $xpath->query(
                '//div[contains(concat(" ", normalize-space(@class), " "), " course-template-section-action-bar ")]'
                .'//a[normalize-space()="+ Thêm phần học"]'
            )->item(0);

            $this->assertNotNull($createAction);
            $this->assertStringContainsString(
                'admin-primary-outline-action',
                $createAction->getAttribute('class')
            );
            $this->assertStringNotContainsString(
                'admin-text-action',
                $createAction->getAttribute('class')
            );
        }
    }

    public function test_admin_can_create_a_section_with_the_final_business_fields(): void
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
                    'title' => 'Hangul Fundamentals',
                    'description' => 'Korean alphabet foundation',
                    'display_order' => 2,
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
            'title' => 'Hangul Fundamentals',
            'description' => 'Korean alphabet foundation',
            'allows_lessons' => 1,
            'display_order' => 2,
        ]);

        $sectionId = (int) DB::table('core_course_template_sections')
            ->where('template_id', $templateId)
            ->value('id');
        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/sections/{$sectionId}",
                $this->validSectionData([
                    'title' => 'Hangul Fundamentals',
                    'allows_lessons' => 0,
                    'display_order' => 2,
                ])
            )
            ->assertRedirect();
        $this->assertDatabaseHas('core_course_template_sections', [
            'id' => $sectionId,
            'allows_lessons' => 0,
        ]);
    }

    public function test_admin_can_create_nested_sections_without_depth_limit(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Nested Course',
            'nested-course'
        );

        $levelOneId = $this->createSection(
            $customerId,
            $templateId,
            'Section cấp 1',
            1
        );

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/sections",
                $this->validSectionData([
                    'parent_section_id' => $levelOneId,
                    'title' => 'Section cấp 2',
                    'display_order' => 1,
                ])
            )
            ->assertRedirect();

        $levelTwoId = (int) DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('title', 'Section cấp 2')
            ->value('id');

        $levelThreeId = $this->createSection(
            $customerId,
            $templateId,
            'Section cấp 3',
            1,
            $levelTwoId
        );

        DB::table('core_course_template_lessons')->insert([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_section_id' => $levelThreeId,
            'title' => 'Nested Lesson',
            'slug' => 'nested-lesson',
            'short_description' => null,
            'description' => null,
            'sort_order' => 0,
            'is_preview' => false,
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

        $this->assertDatabaseHas('core_course_template_sections', [
            'id' => $levelTwoId,
            'parent_section_id' => $levelOneId,
        ]);
        $this->assertDatabaseHas('core_course_template_sections', [
            'id' => $levelThreeId,
            'parent_section_id' => $levelTwoId,
        ]);

        $this->actingAs($admin)
            ->get(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit"
            )
            ->assertOk()
            ->assertSeeText('Section cấp 1')
            ->assertSeeText('Section cấp 2')
            ->assertSeeText('Section cấp 3')
            ->assertSeeText('Nested Lesson')
            ->assertSeeText('+ Thêm phần học con');
    }

    public function test_each_section_renders_an_independent_accessible_collapse_branch(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Collapsible Structure',
            'collapsible-structure'
        );
        $firstRootId = $this->createSection(
            $customerId,
            $templateId,
            'First Root',
            1
        );
        $secondRootId = $this->createSection(
            $customerId,
            $templateId,
            'Second Root',
            2
        );
        $childId = $this->createSection(
            $customerId,
            $templateId,
            'Nested Child',
            1,
            $firstRootId
        );
        $this->createLesson(
            $customerId,
            $templateId,
            $childId,
            'Nested Branch Lesson'
        );
        $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Unrelated Direct Lesson'
        );

        $response = $this->actingAs($admin)->get(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure"
        )->assertOk();
        $xpath = $this->xpath($response->getContent());
        $sectionIds = [$firstRootId, $secondRootId, $childId];

        foreach ($sectionIds as $sectionId) {
            $branchId = "course-template-section-{$sectionId}-branch";
            $toggle = $xpath->query(
                '//article[@data-section-id="'.$sectionId.'"]'
                .'/header//button[contains(concat(" ", normalize-space(@class), " "), " course-template-section-toggle ")]'
            )->item(0);

            $this->assertNotNull($toggle);
            $this->assertSame('button', $toggle->getAttribute('type'));
            $this->assertSame($branchId, $toggle->getAttribute('aria-controls'));
            $this->assertSame('true', $toggle->getAttribute('aria-expanded'));
            $this->assertSame(
                'expanded.toString()',
                $toggle->getAttribute('x-bind:aria-expanded')
            );
            $this->assertSame(
                1,
                $xpath->query('//div[@id="'.$branchId.'"][@x-show="expanded"]')->length
            );
        }

        $this->assertSame(count($sectionIds), $xpath->query(
            '//button[contains(concat(" ", normalize-space(@class), " "), " course-template-section-toggle ")]'
        )->length);
        $this->assertSame(0, $xpath->query(
            '//div[@id="course-template-direct-panel"]//button[contains(concat(" ", normalize-space(@class), " "), " course-template-section-toggle ")]'
        )->length);
        $response
            ->assertSeeText('Nested Branch Lesson')
            ->assertSeeText('Unrelated Direct Lesson')
            ->assertSeeText('+ Thêm phần học con')
            ->assertSeeText('Sửa')
            ->assertSeeText('Xóa');
    }

    public function test_section_collapse_script_restores_only_valid_scoped_state(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString(
            'window.courseTemplateSectionCollapse = (tenantId, templateId, sectionId)',
            $script
        );
        $this->assertStringContainsString(
            'lf.course-template.section.${tenantId}.${templateId}.${sectionId}.expanded',
            $script
        );
        $this->assertStringContainsString(
            "storedState === 'true' || storedState === 'false'",
            $script
        );
        $this->assertStringContainsString(
            'this.expanded = ! this.expanded',
            $script
        );
    }

    public function test_section_order_is_automatic_per_sibling_scope_and_duplicates_remain_valid(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $emptyTemplateId = $this->createTemplate(
            $customerId,
            'Empty Section Scope',
            'empty-section-scope'
        );
        $emptyCreate = $this->actingAs($admin)->get(
            "https://tenant-a.localhost/admin/course-templates/{$emptyTemplateId}/sections/create"
        );
        $this->assertSame(
            '1',
            $this->xpath($emptyCreate->getContent())
                ->query('//input[@name="display_order"]')
                ->item(0)
                ->getAttribute('value')
        );
        $firstData = $this->validSectionData(['title' => 'First Automatic']);
        unset($firstData['display_order']);
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$emptyTemplateId}/sections",
            $firstData
        )->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('core_course_template_sections', [
            'template_id' => $emptyTemplateId,
            'title' => 'First Automatic',
            'display_order' => 1,
        ]);

        $templateId = $this->createTemplate(
            $customerId,
            'Ordered Sections',
            'ordered-sections'
        );
        $parentId = $this->createSection(
            $customerId,
            $templateId,
            'Parent',
            7
        );
        $this->createSection($customerId, $templateId, 'Root Gap', 10);
        $this->createSection(
            $customerId,
            $templateId,
            'Child Gap',
            4,
            $parentId
        );

        $rootCreate = $this->actingAs($admin)->get(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/sections/create"
        );
        $this->assertSame(
            '11',
            $this->xpath($rootCreate->getContent())
                ->query('//input[@name="display_order"]')
                ->item(0)
                ->getAttribute('value')
        );
        $childCreate = $this->actingAs($admin)->get(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/sections/create?parent_section_id={$parentId}"
        );
        $this->assertSame(
            '5',
            $this->xpath($childCreate->getContent())
                ->query('//input[@name="display_order"]')
                ->item(0)
                ->getAttribute('value')
        );
        $oldInputCreate = $this->withSession([
            '_old_input' => ['display_order' => '3'],
        ])->actingAs($admin)->get(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/sections/create"
        );
        $this->assertSame(
            '3',
            $this->xpath($oldInputCreate->getContent())
                ->query('//input[@name="display_order"]')
                ->item(0)
                ->getAttribute('value')
        );
        $edit = $this->withSession(['_old_input' => []])
            ->actingAs($admin)->get(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/sections/{$parentId}/edit"
            );
        $this->assertSame(
            '7',
            $this->xpath($edit->getContent())
                ->query('//input[@name="display_order"]')
                ->item(0)
                ->getAttribute('value')
        );

        $rootData = $this->validSectionData([
            'title' => 'Automatic Root',
        ]);
        unset($rootData['display_order']);
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/sections",
            $rootData
        )->assertSessionDoesntHaveErrors();

        $childData = $this->validSectionData([
            'title' => 'Automatic Child',
            'parent_section_id' => $parentId,
        ]);
        unset($childData['display_order']);
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/sections",
            $childData
        )->assertSessionDoesntHaveErrors();

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/sections",
            $this->validSectionData([
                'title' => 'Duplicate Root',
                'display_order' => 7,
            ])
        )->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('core_course_template_sections', [
            'template_id' => $templateId,
            'title' => 'Automatic Root',
            'display_order' => 11,
        ]);
        $this->assertDatabaseHas('core_course_template_sections', [
            'template_id' => $templateId,
            'parent_section_id' => $parentId,
            'title' => 'Automatic Child',
            'display_order' => 5,
        ]);
        $this->assertDatabaseHas('core_course_template_sections', [
            'template_id' => $templateId,
            'title' => 'Duplicate Root',
            'display_order' => 7,
        ]);

        $orderedIds = DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->whereNull('parent_section_id')
            ->orderBy('display_order')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame($parentId, $orderedIds[0]);
    }

    public function test_admin_and_teacher_render_compact_section_tree_rows_without_metadata(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate(
            $customerId,
            'Tree Layout Course',
            'tree-layout-course',
            $teacher->id
        );
        $parentId = $this->createSection(
            $customerId,
            $templateId,
            'Chương 1',
            8
        );
        $childId = $this->createSection(
            $customerId,
            $templateId,
            'Nhóm 1',
            12,
            $parentId
        );
        DB::table('core_course_template_sections')
            ->whereIn('id', [$parentId, $childId])
            ->update(['description' => 'Section metadata must remain hidden.']);

        foreach ([[$admin, 'admin'], [$teacher, 'teacher']] as [$user, $area]) {
            $response = $this->actingAs($user)
                ->get(
                    "https://tenant-a.localhost/{$area}/course-templates/"
                    ."{$templateId}/edit?tab=structure"
                )
                ->assertOk()
                ->assertSeeText('Chương 1')
                ->assertSeeText('Nhóm 1')
                ->assertDontSeeText('Section metadata must remain hidden.');

            $xpath = $this->xpath($response->getContent());
            $parent = $xpath->query("//article[@data-section-id='{$parentId}']")->item(0);
            $child = $xpath->query("//article[@data-section-id='{$childId}']")->item(0);

            $this->assertNotNull($parent);
            $this->assertNotNull($child);
            $this->assertSame('0', $parent->getAttribute('data-section-depth'));
            $this->assertSame('1', $child->getAttribute('data-section-depth'));
            $this->assertFalse($parent->hasAttribute('class') && str_contains(
                $parent->getAttribute('class'),
                'admin-card'
            ));
            $this->assertSame(
                1,
                $xpath->query(
                    './div[contains(concat(" ", normalize-space(@class), " "), " course-template-section-branch ")]'
                    .'/div[contains(concat(" ", normalize-space(@class), " "), " course-template-outline-children ")]'
                    ."/article[@data-section-id='{$childId}']",
                    $parent
                )->length
            );

            foreach ([$parent, $child] as $sectionNode) {
                $header = $xpath->query('./header', $sectionNode)->item(0);
                $this->assertNotNull($header);
                $this->assertSame(
                    0,
                    $xpath->query(
                        './/*[contains(concat(" ", normalize-space(@class), " "), " course-template-outline-section-meta ")]',
                        $header
                    )->length
                );
                $this->assertSame(
                    ['+ Thêm phần học con', 'Sửa', 'Xóa'],
                    array_map(
                        static fn (\DOMNode $node): string => trim($node->textContent),
                        iterator_to_array($xpath->query(
                            './/div[contains(concat(" ", normalize-space(@class), " "), " admin-table-actions ")]/*'
                            .'[not(contains(concat(" ", normalize-space(@class), " "), " course-template-section-toggle "))]',
                            $header
                        ))
                    )
                );
                $this->assertSame(
                    3,
                    $xpath->query(
                        './/*[self::a or self::button][contains(concat(" ", normalize-space(@class), " "), " admin-text-action ")]'
                        .'[not(contains(concat(" ", normalize-space(@class), " "), " course-template-section-toggle "))]',
                        $header
                    )->length
                );
            }
        }

        $css = file_get_contents(base_path('resources/css/admin/admin-pages.css'));
        $this->assertStringContainsString(
            '.course-template-outline-children::before',
            $css
        );
        $this->assertStringContainsString(
            '.course-template-outline-children > .course-template-outline-section::before',
            $css
        );
    }

    public function test_lesson_block_follows_allows_lessons_without_hiding_children(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Section Capability Course',
            'section-capability-course'
        );
        $containerId = $this->createSection(
            $customerId,
            $templateId,
            'Container Section',
            1
        );
        DB::table('core_course_template_sections')
            ->where('id', $containerId)
            ->update(['allows_lessons' => false]);
        $lessonSectionId = $this->createSection(
            $customerId,
            $templateId,
            'Lesson Section',
            1,
            $containerId
        );

        $response = $this->actingAs($admin)
            ->get(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit"
            )
            ->assertOk();

        $xpath = $this->xpath($response->getContent());
        $container = $xpath->query(
            "//article[@data-section-id='{$containerId}']"
        )->item(0);
        $lessonSection = $xpath->query(
            "//article[@data-section-id='{$lessonSectionId}']"
        )->item(0);

        $this->assertNotNull($container);
        $this->assertNotNull($lessonSection);
        $this->assertSame(
            0,
            $xpath->query(
                './section[contains(concat(" ", normalize-space(@class), " "), " course-template-lesson-panel ")]',
                $container
            )->length
        );
        $this->assertStringNotContainsString(
            'Chưa có bài học',
            $xpath->query('./header', $container)->item(0)->textContent
        );
        foreach (['Bản nháp', 'Hoạt động', 'Không hoạt động', 'Ngừng sử dụng'] as $status) {
            $this->assertStringNotContainsString(
                $status,
                $xpath->query('./header', $container)->item(0)->textContent
            );
        }
        $this->assertSame(
            1,
            $xpath->query(
                './/article[@data-section-id="'.$lessonSectionId.'"]',
                $container
            )->length
        );

        $lessonPanel = $xpath->query(
            './div[contains(concat(" ", normalize-space(@class), " "), " course-template-section-branch ")]'
            .'/section[contains(concat(" ", normalize-space(@class), " "), " course-template-lesson-panel ")]',
            $lessonSection
        )->item(0);
        $this->assertNotNull($lessonPanel);
        $this->assertStringContainsString('Bài học', $lessonPanel->textContent);
        $this->assertStringContainsString('Chưa có bài học', $lessonPanel->textContent);
        $this->assertStringContainsString(
            'Chưa có bài học nào trong phần này.',
            $lessonPanel->textContent
        );
        $this->assertStringContainsString('Gắn bài học', $lessonPanel->textContent);
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

    public function test_section_validation_allows_automatic_display_order(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Validation Course',
            'validation-course'
        );

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/sections",
                [
                    'description' => 'Only optional data',
                ]
            )
            ->assertSessionHasErrors([
                'title',
                'allows_lessons',
            ])
            ->assertSessionDoesntHaveErrors([
                'description',
                'display_order',
            ]);

        $this->assertDatabaseCount('core_course_template_sections', 0);
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

    public function test_parent_section_must_belong_to_the_same_template_and_hierarchy_cannot_cycle(): void
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

        foreach ([$wrongTemplateParentId, $wrongTenantParentId] as $invalidParentId) {
            $this->actingAs($admin)
                ->post(
                    'https://tenant-a.localhost/admin/course-templates/'
                    ."{$templateId}/sections",
                    $this->validSectionData([
                        'parent_section_id' => $invalidParentId,
                        'title' => 'Invalid Child '.$invalidParentId,
                    ])
                )
                ->assertSessionHasErrors('parent_section_id');
        }

        $this->actingAs($admin)
            ->put(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/sections/{$parentId}",
                $this->validSectionData([
                    'parent_section_id' => $childId,
                    'title' => 'Parent',
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
            ->assertSeeText('Sửa')
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

        foreach (['title', 'allows_lessons'] as $field) {
            $this->assertSame(
                1,
                $this->requiredIndicatorCount($response->getContent(), $field)
            );
        }

        foreach (['parent_section_id', 'description', 'display_order'] as $field) {
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
        int $displayOrder,
        ?int $parentSectionId = null
    ): int {
        return DB::table('core_course_template_sections')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'parent_section_id' => $parentSectionId,
            'allows_lessons' => true,
            'title' => $title,
            'description' => null,
            'display_order' => $displayOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLesson(
        int $customerId,
        int $templateId,
        ?int $sectionId,
        string $title
    ): int {
        return DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_section_id' => $sectionId,
            'title' => $title,
            'slug' => str($title)->slug()->toString().'-'.uniqid(),
            'short_description' => null,
            'description' => null,
            'sort_order' => 1,
            'is_preview' => false,
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
    }

    private function validSectionData(array $overrides = []): array
    {
        return array_merge([
            'parent_section_id' => null,
            'allows_lessons' => 1,
            'title' => 'Course Introduction',
            'description' => null,
            'display_order' => 1,
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

    private function xpath(string $html): \DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }
}
