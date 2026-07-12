<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CourseTemplateLessonManagementTest extends TestCase
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

    public function test_admin_and_teacher_can_view_lessons_inside_their_template_sections(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate(
            $customerId,
            'TOPIK Beginner',
            'topik-beginner',
            null,
            $teacher->id
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Hangul'
        );
        $otherTemplateId = $this->createTemplate(
            $otherCustomerId,
            'Private Template',
            'private-template'
        );
        $otherSectionId = $this->createSection(
            $otherCustomerId,
            $otherTemplateId,
            'Private Section'
        );
        $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Korean Alphabet'
        );
        $this->createLesson(
            $otherCustomerId,
            $otherTemplateId,
            $otherSectionId,
            'Private Tenant Lesson'
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
                ->assertSeeText('Gắn bài học')
                ->assertSeeText('Tổng số bài học: 0')
                ->assertSeeText('Korean Alphabet')
                ->assertDontSeeText('Private Tenant Lesson');
        }
    }

    public function test_admin_can_create_a_lesson_with_documented_fields(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'TOPIK Course',
            'topik-course'
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Alphabet'
        );

        $this->actingAs($admin)
            ->post(
                $this->lessonCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId
                ),
                $this->validLessonData([
                    'title' => 'Korean Alphabet',
                    'short_description' => 'Learn the alphabet',
                    'description' => 'Detailed lesson description',
                    'sort_order' => 2,
                    'is_preview' => 1,
                    'learning_objective' => 'Read basic Hangul',
                    'status' => 'active',
                ])
            )
            ->assertRedirect(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit"
                ."?tab=structure#course-template-section-{$sectionId}-lessons"
            );

        $this->assertDatabaseHas('core_course_template_lessons', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_section_id' => $sectionId,
            'title' => 'Korean Alphabet',
            'short_description' => 'Learn the alphabet',
            'description' => 'Detailed lesson description',
            'sort_order' => 2,
            'is_preview' => 1,
            'duration_seconds' => 0,
            'activity_count' => 0,
            'unlock_rule' => 'none',
            'created_by' => $admin->id,
        ]);
    }

    public function test_admin_and_teacher_can_create_direct_lessons_without_sections(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate(
            $customerId,
            'Flat Course',
            'flat-course',
            null,
            $teacher->id
        );

        foreach ([
            [$admin, 'admin', 'Admin Direct Lesson'],
            [$teacher, 'teacher', 'Teacher Direct Lesson'],
        ] as [$user, $area, $title]) {
            $collectionUrl = $this->directLessonCollectionUrl(
                $area,
                $templateId
            );

            $this->actingAs($user)
                ->get($collectionUrl.'/create')
                ->assertOk()
                ->assertSeeText('Trực tiếp trong Template khóa học');

            $this->actingAs($user)
                ->post(
                    $collectionUrl,
                    $this->validLessonData(['title' => $title])
                )
                ->assertRedirect(
                    "https://tenant-a.localhost/{$area}/course-templates/"
                    ."{$templateId}/edit?tab=structure#course-template-direct-lessons"
                );

            $this->assertDatabaseHas('core_course_template_lessons', [
                'customer_id' => $customerId,
                'template_id' => $templateId,
                'template_section_id' => null,
                'title' => $title,
                'created_by' => $user->id,
            ]);
        }

        $this->assertDatabaseCount('core_course_template_sections', 0);

        foreach ([[$admin, 'admin'], [$teacher, 'teacher']] as [$user, $area]) {
            $response = $this->actingAs($user)
                ->get(
                    "https://tenant-a.localhost/{$area}/course-templates/"
                    ."{$templateId}/edit"
                )
                ->assertOk()
                ->assertSeeText('Bài học trực tiếp')
                ->assertSeeText('Tổng số bài học: 2')
                ->assertSeeText('Admin Direct Lesson')
                ->assertSeeText('Teacher Direct Lesson')
                ->assertSee(
                    "/{$area}/course-templates/{$templateId}/lessons/create",
                    false
                )
                ->assertSeeText('Bài học trực tiếp')
                ->assertSeeText('Theo phần học')
                ->assertSee('x-show="activeStructureTab === \'direct\'"', false)
                ->assertSee('x-show="activeStructureTab === \'sections\'"', false);

            $previous = libxml_use_internal_errors(true);
            $document = new \DOMDocument;
            $document->loadHTML($response->getContent());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new \DOMXPath($document);
            $createAction = $xpath->query(
                '//section[@id="course-template-direct-lessons"]'
                .'//div[contains(concat(" ", normalize-space(@class), " "), " course-template-section-action-bar ")]'
                .'//a[normalize-space()="+ Thêm bài học"]'
            )->item(0);

            $this->assertNotNull($createAction);
            $this->assertSame(
                1,
                $xpath->query(
                    '//section[@id="course-template-direct-lessons"]'
                    .'//a[normalize-space()="+ Thêm bài học"]'
                )->length
            );
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

    public function test_lesson_order_is_automatic_and_scoped_by_location(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Scoped Lesson Order',
            'scoped-lesson-order'
        );
        $firstSectionId = $this->createSection(
            $customerId,
            $templateId,
            'First Section'
        );
        $secondSectionId = $this->createSection(
            $customerId,
            $templateId,
            'Second Section'
        );
        DB::table('core_course_template_lessons')
            ->where('id', $this->createLesson(
                $customerId,
                $templateId,
                null,
                'Direct Gap'
            ))
            ->update(['sort_order' => 6]);
        DB::table('core_course_template_lessons')
            ->where('id', $this->createLesson(
                $customerId,
                $templateId,
                $firstSectionId,
                'Section Gap'
            ))
            ->update(['sort_order' => 9]);

        $directCreate = $this->actingAs($admin)->get(
            $this->directLessonCollectionUrl('admin', $templateId).'/create'
        );
        $this->assertSame(
            '7',
            $this->xpath($directCreate->getContent())
                ->query('//input[@name="sort_order"]')
                ->item(0)
                ->getAttribute('value')
        );
        $firstSectionCreate = $this->actingAs($admin)->get(
            $this->lessonCollectionUrl(
                'admin',
                $templateId,
                $firstSectionId
            ).'/create'
        );
        $this->assertSame(
            '10',
            $this->xpath($firstSectionCreate->getContent())
                ->query('//input[@name="sort_order"]')
                ->item(0)
                ->getAttribute('value')
        );
        $secondSectionCreate = $this->actingAs($admin)->get(
            $this->lessonCollectionUrl(
                'admin',
                $templateId,
                $secondSectionId
            ).'/create'
        );
        $this->assertSame(
            '1',
            $this->xpath($secondSectionCreate->getContent())
                ->query('//input[@name="sort_order"]')
                ->item(0)
                ->getAttribute('value')
        );

        $directData = $this->validLessonData(['title' => 'Automatic Direct']);
        unset($directData['sort_order']);
        $this->actingAs($admin)->post(
            $this->directLessonCollectionUrl('admin', $templateId),
            $directData
        )->assertSessionDoesntHaveErrors();

        foreach ([
            [$firstSectionId, 'Automatic First Section', 10],
            [$secondSectionId, 'Automatic Second Section', 1],
        ] as [$sectionId, $title, $expectedOrder]) {
            $data = $this->validLessonData(['title' => $title]);
            unset($data['sort_order']);
            $this->actingAs($admin)->post(
                $this->lessonCollectionUrl('admin', $templateId, $sectionId),
                $data
            )->assertSessionDoesntHaveErrors();

            $this->assertDatabaseHas('core_course_template_lessons', [
                'template_id' => $templateId,
                'template_section_id' => $sectionId,
                'title' => $title,
                'sort_order' => $expectedOrder,
            ]);
        }

        $this->assertDatabaseHas('core_course_template_lessons', [
            'template_id' => $templateId,
            'template_section_id' => null,
            'title' => 'Automatic Direct',
            'sort_order' => 7,
        ]);
    }

    public function test_section_that_disallows_lessons_hides_action_and_rejects_attachment(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Container Course', 'container-course');
        $sectionId = $this->createSection($customerId, $templateId, 'Container Only');

        DB::table('core_course_template_sections')
            ->where('id', $sectionId)
            ->update(['allows_lessons' => false]);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()
            ->assertDontSeeText('Gắn bài học');

        $this->actingAs($admin)
            ->post(
                $this->lessonCollectionUrl('admin', $templateId, $sectionId),
                $this->validLessonData(['title' => 'Rejected Lesson'])
            )
            ->assertSessionHasErrors('template_section_id');

        $this->assertDatabaseMissing('core_course_template_lessons', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'title' => 'Rejected Lesson',
        ]);
    }

    public function test_flat_and_sectioned_lesson_routes_enforce_lesson_location(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Mixed Course',
            'mixed-course'
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Mixed Section'
        );
        $directLessonId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Direct Lesson'
        );
        $sectionedLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Sectioned Lesson'
        );

        $this->actingAs($admin)
            ->get(
                $this->directLessonCollectionUrl('admin', $templateId)
                ."/{$sectionedLessonId}/edit"
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(
                $this->lessonCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId
                )."/{$directLessonId}/edit"
            )
            ->assertNotFound();
    }

    public function test_teacher_can_create_a_lesson_for_an_own_tenant_section(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate(
            $customerId,
            'Teacher Course',
            'teacher-course',
            null,
            $teacher->id
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Teacher Section'
        );

        $this->actingAs($teacher)
            ->post(
                $this->lessonCollectionUrl(
                    'teacher',
                    $templateId,
                    $sectionId
                ),
                $this->validLessonData(['title' => 'Teacher Lesson'])
            )
            ->assertRedirect(
                'https://tenant-a.localhost/teacher/course-templates/'
                ."{$templateId}/edit"
                ."?tab=structure#course-template-section-{$sectionId}-lessons"
            );

        $this->assertDatabaseHas('core_course_template_lessons', [
            'customer_id' => $customerId,
            'template_section_id' => $sectionId,
            'title' => 'Teacher Lesson',
            'created_by' => $teacher->id,
        ]);
    }

    public function test_validation_and_conditional_unlock_fields_are_enforced(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Validation Course',
            'validation-course'
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Validation Section'
        );

        $this->actingAs($admin)
            ->post(
                $this->lessonCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId
                ),
                [
                    'title' => '',
                    'sort_order' => -1,
                    'is_preview' => 'invalid',
                    'unlock_rule' => 'previous_lesson_completed',
                    'status' => 'published',
                ]
            )
            ->assertSessionHasErrors([
                'title',
                'sort_order',
                'is_preview',
                'unlock_after_lesson_id',
            ]);

        $this->actingAs($admin)
            ->post(
                $this->lessonCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId
                ),
                $this->validLessonData([
                    'unlock_rule' => 'date_based',
                    'unlock_at' => null,
                ])
            )
            ->assertSessionHasErrors('unlock_at');

        $this->assertDatabaseCount('core_course_template_lessons', 0);
    }

    public function test_lesson_role_allowlist_is_required_and_all_values_are_accepted(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Lesson Roles', 'lesson-roles');

        foreach (['regular', 'review', 'midterm_exam', 'final_exam', 'other_exam'] as $index => $lessonType) {
            $this->actingAs($admin)->post(
                $this->directLessonCollectionUrl('admin', $templateId),
                $this->validLessonData(['title' => 'Role '.$index, 'lesson_type' => $lessonType])
            )->assertRedirect();
            $this->assertDatabaseHas('core_course_template_lessons', [
                'customer_id' => $customerId,
                'template_id' => $templateId,
                'title' => 'Role '.$index,
                'lesson_type' => $lessonType,
            ]);
        }

        $missing = $this->validLessonData(['title' => 'Missing Role']);
        unset($missing['lesson_type']);
        $this->actingAs($admin)->post($this->directLessonCollectionUrl('admin', $templateId), $missing)
            ->assertSessionHasErrors('lesson_type');
        $this->actingAs($admin)->post(
            $this->directLessonCollectionUrl('admin', $templateId),
            $this->validLessonData(['title' => 'Invalid Role', 'lesson_type' => 'introduction'])
        )->assertSessionHasErrors('lesson_type');
    }

    public function test_estimated_lesson_count_does_not_limit_authoring(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Limited Course',
            'limited-course',
            maxLessons: 1
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Limited Section'
        );
        $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Only Lesson'
        );

        $this->actingAs($admin)
            ->post(
                $this->lessonCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId
                ),
                $this->validLessonData(['title' => 'Too Many'])
            )
            ->assertRedirect();

        $this->assertDatabaseCount('core_course_template_lessons', 2);
    }

    public function test_template_section_and_lesson_access_are_tenant_isolated(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $ownTemplateId = $this->createTemplate(
            $customerId,
            'Own Template',
            'own-template'
        );
        $ownSectionId = $this->createSection(
            $customerId,
            $ownTemplateId,
            'Own Section'
        );
        $secondTemplateId = $this->createTemplate(
            $customerId,
            'Second Template',
            'second-template'
        );
        $wrongSectionId = $this->createSection(
            $customerId,
            $secondTemplateId,
            'Wrong Template Section'
        );
        $otherTemplateId = $this->createTemplate(
            $otherCustomerId,
            'Other Template',
            'other-template'
        );
        $otherSectionId = $this->createSection(
            $otherCustomerId,
            $otherTemplateId,
            'Other Section'
        );
        $otherLessonId = $this->createLesson(
            $otherCustomerId,
            $otherTemplateId,
            $otherSectionId,
            'Other Lesson'
        );

        foreach ([
            [$otherTemplateId, $otherSectionId],
            [$ownTemplateId, $wrongSectionId],
        ] as [$templateId, $sectionId]) {
            $this->actingAs($admin)
                ->post(
                    $this->lessonCollectionUrl(
                        'admin',
                        $templateId,
                        $sectionId
                    ),
                    $this->validLessonData(['title' => 'Invalid Lesson'])
                )
                ->assertNotFound();
        }

        $this->actingAs($admin)
            ->get(
                $this->lessonCollectionUrl(
                    'admin',
                    $ownTemplateId,
                    $ownSectionId
                )."/{$otherLessonId}/edit"
            )
            ->assertNotFound();

        $this->assertDatabaseMissing('core_course_template_lessons', [
            'customer_id' => $customerId,
            'title' => 'Invalid Lesson',
        ]);
    }

    public function test_prerequisite_must_belong_to_the_same_template_and_tenant(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Own Template',
            'own-template'
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Own Section'
        );
        $otherOwnTemplateId = $this->createTemplate(
            $customerId,
            'Other Own Template',
            'other-own-template'
        );
        $otherOwnSectionId = $this->createSection(
            $customerId,
            $otherOwnTemplateId,
            'Other Own Section'
        );
        $wrongTemplateLessonId = $this->createLesson(
            $customerId,
            $otherOwnTemplateId,
            $otherOwnSectionId,
            'Wrong Template Lesson'
        );
        $otherTemplateId = $this->createTemplate(
            $otherCustomerId,
            'Other Tenant Template',
            'other-tenant-template'
        );
        $otherSectionId = $this->createSection(
            $otherCustomerId,
            $otherTemplateId,
            'Other Tenant Section'
        );
        $wrongTenantLessonId = $this->createLesson(
            $otherCustomerId,
            $otherTemplateId,
            $otherSectionId,
            'Wrong Tenant Lesson'
        );

        foreach (
            [$wrongTemplateLessonId, $wrongTenantLessonId] as $prerequisiteId
        ) {
            $this->actingAs($admin)
                ->post(
                    $this->lessonCollectionUrl(
                        'admin',
                        $templateId,
                        $sectionId
                    ),
                    $this->validLessonData([
                        'title' => 'Invalid Prerequisite '.$prerequisiteId,
                        'unlock_rule' => 'previous_lesson_completed',
                        'unlock_after_lesson_id' => $prerequisiteId,
                    ])
                )
                ->assertSessionHasErrors('unlock_after_lesson_id');
        }
    }

    public function test_unlock_prerequisite_cannot_be_self_or_create_a_cycle(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Dependency Course',
            'dependency-course'
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Dependency Section'
        );
        $firstLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'First Lesson'
        );
        $secondLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Second Lesson',
            unlockAfterLessonId: $firstLessonId
        );

        $this->actingAs($admin)
            ->put(
                $this->lessonCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId
                )."/{$firstLessonId}",
                $this->validLessonData([
                    'title' => 'First Lesson',
                    'unlock_rule' => 'previous_lesson_completed',
                    'unlock_after_lesson_id' => $firstLessonId,
                ])
            )
            ->assertSessionHasErrors('unlock_after_lesson_id');

        $this->actingAs($admin)
            ->put(
                $this->lessonCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId
                )."/{$firstLessonId}",
                $this->validLessonData([
                    'title' => 'First Lesson',
                    'unlock_rule' => 'previous_lesson_completed',
                    'unlock_after_lesson_id' => $secondLessonId,
                ])
            )
            ->assertSessionHasErrors('unlock_after_lesson_id');

        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $firstLessonId,
            'unlock_after_lesson_id' => null,
        ]);
    }

    public function test_delete_is_blocked_when_another_lesson_uses_the_prerequisite(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Protected Course',
            'protected-course'
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Protected Section'
        );
        $prerequisiteId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Prerequisite'
        );
        $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Dependent',
            unlockAfterLessonId: $prerequisiteId
        );

        $this->actingAs($admin)
            ->delete(
                $this->lessonCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId
                )."/{$prerequisiteId}"
            )
            ->assertSessionHasErrors('lesson');

        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $prerequisiteId,
        ]);
    }

    public function test_delete_is_blocked_when_the_activities_table_has_a_reference(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Activity Course',
            'activity-course'
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Activity Section'
        );
        $lessonId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Referenced Lesson'
        );

        DB::table('core_course_template_activities')->insert([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_lesson_id' => $lessonId,
            'title' => 'Referenced Activity',
            'description' => null,
            'sort_order' => 0,
            'activity_type' => 'text',
            'external_video_url' => null,
            'live_class_url' => null,
            'assessment_quiz_id' => null,
            'duration_seconds' => 0,
            'is_required' => true,
            'completion_rule' => 'view',
            'completion_threshold' => null,
            'is_preview' => false,
            'unlock_rule' => 'none',
            'unlock_after_activity_id' => null,
            'unlock_at' => null,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->delete(
                $this->lessonCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId
                )."/{$lessonId}"
            )
            ->assertSessionHasErrors('lesson');

        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $lessonId,
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
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'UI Section'
        );
        $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'UI Lesson'
        );

        $this->actingAs($admin)
            ->get(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit"
            )
            ->assertOk()
            ->assertSeeText('Gắn bài học')
            ->assertSeeText('Sửa')
            ->assertSeeText('Xóa')
            ->assertSeeText('Bạn có chắc chắn muốn xóa bài học này không?')
            ->assertSeeText('Có, xóa')
            ->assertSeeText('Không');

        $response = $this->actingAs($admin)
            ->get(
                $this->lessonCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId
                ).'/create'
            )
            ->assertOk();

        foreach ([
            'title',
            'is_preview',
            'lesson_type',
            'unlock_rule',
        ] as $field) {
            $this->assertSame(
                1,
                $this->requiredIndicatorCount($response->getContent(), $field)
            );
        }

        foreach ([
            'short_description',
            'description',
            'unlock_after_lesson_id',
            'unlock_at',
            'sort_order',
        ] as $field) {
            $this->assertSame(
                0,
                $this->requiredIndicatorCount($response->getContent(), $field)
            );
        }

        $response
            ->assertDontSee('name="duration_seconds"', false)
            ->assertDontSee('name="activity_count"', false);
    }

    public function test_create_and_edit_forms_expose_only_documented_editable_fields(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Documented Fields Course',
            'documented-fields-course'
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Documented Fields Section'
        );
        $lessonId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Documented Fields Lesson'
        );
        $collectionUrl = $this->lessonCollectionUrl(
            'admin',
            $templateId,
            $sectionId
        );
        $responses = [
            $this->actingAs($admin)
                ->get("{$collectionUrl}/create")
                ->assertOk(),
            $this->actingAs($admin)
                ->get("{$collectionUrl}/{$lessonId}/edit")
                ->assertOk(),
        ];

        foreach ($responses as $response) {
            foreach ([
                'title',
                'short_description',
                'description',
                'sort_order',
                'is_preview',
                'lesson_type',
                'unlock_rule',
                'unlock_after_lesson_id',
                'unlock_at',
            ] as $field) {
                $response->assertSee('name="'.$field.'"', false);
            }

            $content = $response->getContent();
            $this->assertLessThan(
                strpos($content, 'name="lesson_type"'),
                strpos($content, 'name="is_preview"')
            );
            $this->assertLessThan(
                strpos($content, 'name="unlock_rule"'),
                strpos($content, 'name="lesson_type"')
            );

            foreach ([
                'id',
                'customer_id',
                'template_id',
                'template_section_id',
                'duration_seconds',
                'activity_count',
                'learning_objective',
                'status',
                'media_video_file',
                'media_audio_file',
                'media_document_file',
                'created_by',
                'updated_by',
                'created_at',
                'updated_at',
            ] as $field) {
                $response->assertDontSee('name="'.$field.'"', false);
            }
        }
    }

    public function test_guest_and_student_cannot_access_lesson_management(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');
        $templateId = $this->createTemplate(
            $customerId,
            'Restricted Course',
            'restricted-course'
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Restricted Section'
        );
        $url = $this->lessonCollectionUrl(
            'admin',
            $templateId,
            $sectionId
        ).'/create';

        $this->get($url)->assertRedirect('https://tenant-a.localhost/login');
        $this->actingAs($student)->get($url)->assertForbidden();
    }

    public function test_course_template_lesson_module_has_no_eloquent_models(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('core_course_template_lessons', 'learning_objective'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('core_course_template_lessons', 'status'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('core_course_template_lessons', 'slug'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('core_course_template_version_lessons', 'learning_objective_snapshot'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('core_course_template_version_lessons', 'status_snapshot'));

        $this->assertFileDoesNotExist(
            app_path('Models/CoreCourseTemplateLesson.php')
        );
        $this->assertFileDoesNotExist(
            app_path('Models/CourseTemplateLesson.php')
        );
    }

    public function test_lesson_upload_validation_uses_filtered_request_input(): void
    {
        $source = file_get_contents(
            app_path('Http/Controllers/CourseTemplateLessonController.php')
        );

        $this->assertStringNotContainsString('$request->all()', $source);
        $this->assertStringContainsString(
            '$request->request->all()',
            $source
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
        ?int $maxLessons = null,
        ?int $createdBy = null
    ): int {
        $now = now();

        return DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId,
            'category_id' => null,
            'title' => $title,
            'short_description' => null,
            'description' => null,
            'publisher_name' => null,
            'intro_video_source' => null,
            'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => null,
            'difficulty_level' => null,
            'estimated_minutes_per_lesson' => 0,
            'estimated_lesson_count' => $maxLessons,
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
        string $title
    ): int {
        return DB::table('core_course_template_sections')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'parent_section_id' => null,
            'allows_lessons' => true,
            'title' => $title,
            'description' => null,
            'display_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLesson(
        int $customerId,
        int $templateId,
        ?int $sectionId,
        string $title,
        ?int $unlockAfterLessonId = null
    ): int {
        return DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_section_id' => $sectionId,
            'title' => $title,
            'short_description' => null,
            'description' => null,
            'sort_order' => 0,
            'is_preview' => false,
            'duration_seconds' => 0,
            'activity_count' => 0,
            'unlock_rule' => $unlockAfterLessonId === null
                ? 'none'
                : 'previous_lesson_completed',
            'unlock_after_lesson_id' => $unlockAfterLessonId,
            'unlock_at' => null,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validLessonData(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Lesson Introduction',
            'short_description' => null,
            'description' => null,
            'sort_order' => 0,
            'is_preview' => 0,
            'lesson_type' => 'regular',
            'unlock_rule' => 'none',
            'unlock_after_lesson_id' => null,
            'unlock_at' => null,
        ], $overrides);
    }

    private function lessonCollectionUrl(
        string $area,
        int $templateId,
        int $sectionId
    ): string {
        return "https://tenant-a.localhost/{$area}/course-templates/"
            ."{$templateId}/sections/{$sectionId}/lessons";
    }

    private function directLessonCollectionUrl(
        string $area,
        int $templateId
    ): string {
        return "https://tenant-a.localhost/{$area}/course-templates/"
            ."{$templateId}/lessons";
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
