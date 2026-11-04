<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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
                ->assertSeeText('Thêm bài học')
                ->assertSeeText('Chưa có bài học')
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

    public function test_a_lesson_just_added_opens_by_itself_only_on_the_next_page_view(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Open Course', 'open-course');
        $collectionUrl = $this->directLessonCollectionUrl('admin', $templateId);
        $editUrl = "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure";

        $this->actingAs($admin)->post($collectionUrl, $this->validLessonData(['title' => 'First Lesson']));
        $this->actingAs($admin)->get($editUrl)->assertOk();

        $this->actingAs($admin)->post($collectionUrl, $this->validLessonData(['title' => 'Second Lesson']));
        $this->actingAs($admin)->get($editUrl)->assertOk()
            ->assertSee('data-pager-focus', false)
            ->assertSee('courseTemplateRevealLesson($el)', false)
            ->assertSee('aria-expanded="true"', false);

        // The flag is flashed: reloading no longer forces the lesson open or scrolls to it.
        $this->actingAs($admin)->get($editUrl)->assertOk()
            ->assertDontSee('courseTemplateRevealLesson($el)', false);
    }

    public function test_a_lesson_just_added_to_a_section_opens_the_same_way(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Section Course', 'section-course');
        $sectionId = $this->createSection($customerId, $templateId, 'Unit One');
        $editUrl = "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure";
        $collectionUrl = "https://tenant-a.localhost/admin/course-templates/{$templateId}/sections/{$sectionId}/lessons";

        $this->actingAs($admin)->post($collectionUrl, $this->validLessonData(['title' => 'First Section Lesson']))
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->get($editUrl)->assertOk();

        $this->actingAs($admin)->post($collectionUrl, $this->validLessonData(['title' => 'Second Section Lesson']))
            ->assertRedirect($editUrl."#course-template-section-{$sectionId}-lessons");
        $this->actingAs($admin)->get($editUrl)->assertOk()
            ->assertSee('courseTemplateRevealLesson($el)', false);

        $this->actingAs($admin)->get($editUrl)->assertOk()
            ->assertDontSee('courseTemplateRevealLesson($el)', false);
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
                ->withHeaders($this->ajaxHeaders())
                ->get($collectionUrl.'/create')
                ->assertOk()
                ->assertSeeText('Bài học độc lập');

            // The form exists only as the popup fragment.
            $this->actingAs($user)->withoutHeader('X-Requested-With')->get($collectionUrl.'/create')->assertNotFound();

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
                ->assertSeeText('Bài học độc lập')
                ->assertSeeText('2 bài học')
                ->assertSeeText('Admin Direct Lesson')
                ->assertSeeText('Teacher Direct Lesson')
                ->assertSee(
                    "/{$area}/course-templates/{$templateId}/lessons/create",
                    false
                )
                ->assertSeeText('Bài học độc lập')
                ->assertSeeText('Chia theo phần')
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
                .'//button[normalize-space()="Thêm bài học" and @data-ui-dialog-form-open]'
            )->item(0);

            $this->assertNotNull($createAction);
            $this->assertSame(
                1,
                $xpath->query(
                    '//section[@id="course-template-direct-lessons"]'
                    .'//button[normalize-space()="Thêm bài học"]'
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

        $directCreate = $this->actingAs($admin)->withHeaders($this->ajaxHeaders())->get(
            $this->directLessonCollectionUrl('admin', $templateId).'/create'
        );
        $directCreate->assertOk()->assertDontSee('name="sort_order"', false);
        $firstSectionCreate = $this->actingAs($admin)->withHeaders($this->ajaxHeaders())->get(
            $this->lessonCollectionUrl(
                'admin',
                $templateId,
                $firstSectionId
            ).'/create'
        );
        $firstSectionCreate->assertOk()->assertDontSee('name="sort_order"', false);
        $secondSectionCreate = $this->actingAs($admin)->withHeaders($this->ajaxHeaders())->get(
            $this->lessonCollectionUrl(
                'admin',
                $templateId,
                $secondSectionId
            ).'/create'
        );
        $secondSectionCreate->assertOk()->assertDontSee('name="sort_order"', false);

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
            // The direct-lesson empty state also says "Thêm bài học", so check the section's create URL instead.
            ->assertDontSee("/admin/course-templates/{$templateId}/sections/{$sectionId}/lessons/create", false);

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

    public function test_create_rejects_a_prerequisite_that_would_follow_the_new_lesson_in_direct_and_section_lanes(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Prospective Create Course',
            'prospective-create-course'
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Prospective Section'
        );
        $directFutureId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Direct Future'
        );
        $sectionFutureId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Section Future'
        );
        DB::table('core_course_template_lessons')
            ->whereIn('id', [$directFutureId, $sectionFutureId])
            ->update(['sort_order' => 10]);

        $this->actingAs($admin)
            ->post(
                $this->directLessonCollectionUrl('admin', $templateId),
                $this->validLessonData([
                    'title' => 'Invalid Direct Earlier',
                    'sort_order' => 5,
                    'unlock_rule' => 'selected_lessons_completed',
                    'prerequisite_match' => 'all',
                    'prerequisite_lesson_ids' => [$directFutureId],
                ])
            )
            ->assertSessionHasErrors('prerequisite_lesson_ids');

        $this->actingAs($admin)
            ->post(
                $this->lessonCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId
                ),
                $this->validLessonData([
                    'title' => 'Invalid Section Earlier',
                    'sort_order' => 5,
                    'unlock_rule' => 'selected_lessons_completed',
                    'prerequisite_match' => 'all',
                    'prerequisite_lesson_ids' => [$sectionFutureId],
                ])
            )
            ->assertSessionHasErrors('prerequisite_lesson_ids');

        $this->assertDatabaseMissing('core_course_template_lessons', [
            'template_id' => $templateId,
            'title' => 'Invalid Direct Earlier',
        ]);
        $this->assertDatabaseMissing('core_course_template_lessons', [
            'template_id' => $templateId,
            'title' => 'Invalid Section Earlier',
        ]);
    }

    public function test_update_rejects_moving_a_lesson_before_its_selected_prerequisite_in_direct_and_section_lanes(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Prospective Update Course',
            'prospective-update-course'
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Prospective Section'
        );
        $directPrerequisiteId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Direct Prerequisite'
        );
        $directDependentId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Direct Dependent'
        );
        $sectionPrerequisiteId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Section Prerequisite'
        );
        $sectionDependentId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Section Dependent'
        );
        DB::table('core_course_template_lessons')
            ->whereIn('id', [$directPrerequisiteId, $sectionPrerequisiteId])
            ->update(['sort_order' => 5]);
        DB::table('core_course_template_lessons')
            ->whereIn('id', [$directDependentId, $sectionDependentId])
            ->update(['sort_order' => 10]);

        $this->actingAs($admin)
            ->put(
                $this->directLessonCollectionUrl(
                    'admin',
                    $templateId
                )."/{$directDependentId}",
                $this->validLessonData([
                    'title' => 'Direct Dependent',
                    'sort_order' => 1,
                    'unlock_rule' => 'selected_lessons_completed',
                    'prerequisite_match' => 'all',
                    'prerequisite_lesson_ids' => [$directPrerequisiteId],
                ])
            )
            ->assertSessionHasErrors('prerequisite_lesson_ids');

        $this->actingAs($admin)
            ->put(
                $this->lessonCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId
                )."/{$sectionDependentId}",
                $this->validLessonData([
                    'title' => 'Section Dependent',
                    'sort_order' => 1,
                    'unlock_rule' => 'selected_lessons_completed',
                    'prerequisite_match' => 'all',
                    'prerequisite_lesson_ids' => [$sectionPrerequisiteId],
                ])
            )
            ->assertSessionHasErrors('prerequisite_lesson_ids');

        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $directDependentId,
            'sort_order' => 10,
            'unlock_rule' => 'none',
        ]);
        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $sectionDependentId,
            'sort_order' => 10,
            'unlock_rule' => 'none',
        ]);
        $this->assertSame(
            0,
            DB::table('core_course_template_lesson_prerequisites')
                ->whereIn('lesson_id', [
                    $directDependentId,
                    $sectionDependentId,
                ])
                ->count()
        );

        $this->actingAs($admin)
            ->put(
                $this->directLessonCollectionUrl(
                    'admin',
                    $templateId
                )."/{$directDependentId}",
                $this->validLessonData([
                    'title' => 'Direct Dependent',
                    'sort_order' => 20,
                    'unlock_rule' => 'selected_lessons_completed',
                    'prerequisite_match' => 'all',
                    'prerequisite_lesson_ids' => [$directPrerequisiteId],
                ])
            )
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($admin)
            ->put(
                $this->lessonCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId
                )."/{$sectionDependentId}",
                $this->validLessonData([
                    'title' => 'Section Dependent',
                    'sort_order' => 20,
                    'unlock_rule' => 'selected_lessons_completed',
                    'prerequisite_match' => 'all',
                    'prerequisite_lesson_ids' => [$sectionPrerequisiteId],
                ])
            )
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('core_course_template_lesson_prerequisites', [
            'lesson_id' => $directDependentId,
            'prerequisite_lesson_id' => $directPrerequisiteId,
        ]);
        $this->assertDatabaseHas('core_course_template_lesson_prerequisites', [
            'lesson_id' => $sectionDependentId,
            'prerequisite_lesson_id' => $sectionPrerequisiteId,
        ]);
    }

    public function test_http_authoring_persists_selected_all_any_and_all_previous_rules_idempotently(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate(
            $customerId,
            'Prerequisite Authoring Course',
            'prerequisite-authoring-course',
            null,
            $teacher->id
        );
        $firstId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'First Direct'
        );
        $secondId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Second Direct'
        );
        DB::table('core_course_template_lessons')
            ->where('id', $firstId)
            ->update(['sort_order' => 1]);
        DB::table('core_course_template_lessons')
            ->where('id', $secondId)
            ->update(['sort_order' => 2]);
        $collectionUrl = $this->directLessonCollectionUrl(
            'admin',
            $templateId
        );

        $this->actingAs($admin)
            ->post(
                $collectionUrl,
                $this->validLessonData([
                    'title' => 'Selected Direct',
                    'sort_order' => 3,
                    'unlock_rule' => 'selected_lessons_completed',
                    'prerequisite_match' => 'all',
                    'prerequisite_lesson_ids' => [$firstId, $secondId],
                ])
            )
            ->assertSessionDoesntHaveErrors();
        $selectedId = (int) DB::table('core_course_template_lessons')
            ->where('template_id', $templateId)
            ->where('title', 'Selected Direct')
            ->value('id');
        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $selectedId,
            'unlock_rule' => 'selected_lessons_completed',
            'prerequisite_match' => 'all',
        ]);
        $this->assertSame(
            [$firstId, $secondId],
            DB::table('core_course_template_lesson_prerequisites')
                ->where('lesson_id', $selectedId)
                ->orderBy('sort_order')
                ->pluck('prerequisite_lesson_id')
                ->map(fn ($id): int => (int) $id)
                ->all()
        );

        $update = $this->validLessonData([
            'title' => 'Selected Direct',
            'sort_order' => 3,
            'unlock_rule' => 'selected_lessons_completed',
            'prerequisite_match' => 'any',
            'prerequisite_lesson_ids' => [$firstId, $secondId],
        ]);
        $teacherCollectionUrl = $this->directLessonCollectionUrl(
            'teacher',
            $templateId
        );
        foreach ([1, 2] as $attempt) {
            $this->actingAs($teacher)
                ->put("{$teacherCollectionUrl}/{$selectedId}", $update)
                ->assertSessionDoesntHaveErrors();
        }
        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $selectedId,
            'prerequisite_match' => 'any',
        ]);
        $this->assertSame(
            2,
            DB::table('core_course_template_lesson_prerequisites')
                ->where('lesson_id', $selectedId)
                ->count()
        );

        $this->actingAs($admin)
            ->post(
                $collectionUrl,
                $this->validLessonData([
                    'title' => 'All Previous Direct',
                    'sort_order' => 4,
                    'unlock_rule' => 'all_previous_lessons_completed',
                ])
            )
            ->assertSessionDoesntHaveErrors();
        $allPreviousId = (int) DB::table('core_course_template_lessons')
            ->where('template_id', $templateId)
            ->where('title', 'All Previous Direct')
            ->value('id');
        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $allPreviousId,
            'unlock_rule' => 'all_previous_lessons_completed',
            'prerequisite_match' => null,
        ]);
        $this->assertSame(
            0,
            DB::table('core_course_template_lesson_prerequisites')
                ->where('lesson_id', $allPreviousId)
                ->count()
        );
    }

    public function test_selected_prerequisite_request_tampering_is_rejected_by_tenant_lane_and_role(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $student = $this->createUser($customerId, 'student');
        $templateId = $this->createTemplate(
            $customerId,
            'Tampering Course',
            'tampering-course'
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Section Lane'
        );
        $sectionLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Section Prerequisite'
        );
        $otherTemplateId = $this->createTemplate(
            $otherCustomerId,
            'Other Tenant Course',
            'other-tenant-course'
        );
        $otherLessonId = $this->createLesson(
            $otherCustomerId,
            $otherTemplateId,
            null,
            'Other Tenant Prerequisite'
        );
        $collectionUrl = $this->directLessonCollectionUrl(
            'admin',
            $templateId
        );
        $data = $this->validLessonData([
            'title' => 'Tampered Direct',
            'sort_order' => 10,
            'unlock_rule' => 'selected_lessons_completed',
            'prerequisite_match' => 'all',
        ]);

        $this->actingAs($admin)
            ->post(
                $collectionUrl,
                array_merge($data, [
                    'prerequisite_lesson_ids' => [$otherLessonId],
                ])
            )
            ->assertSessionHasErrors('prerequisite_lesson_ids.0');
        $this->actingAs($admin)
            ->post(
                $collectionUrl,
                array_merge($data, [
                    'prerequisite_lesson_ids' => [$sectionLessonId],
                ])
            )
            ->assertSessionHasErrors('prerequisite_lesson_ids');
        $this->actingAs($student)
            ->post(
                $collectionUrl,
                array_merge($data, [
                    'prerequisite_lesson_ids' => [$sectionLessonId],
                ])
            )
            ->assertForbidden();

        $this->assertDatabaseMissing('core_course_template_lessons', [
            'template_id' => $templateId,
            'title' => 'Tampered Direct',
        ]);
        $this->assertSame(
            0,
            DB::table('core_course_template_lesson_prerequisites')
                ->where('template_id', $templateId)
                ->count()
        );
    }

    public function test_prerequisite_validation_renders_error_and_preserves_old_selected_input(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Prerequisite UI Course',
            'prerequisite-ui-course'
        );
        $futureId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Future Prerequisite'
        );
        DB::table('core_course_template_lessons')
            ->where('id', $futureId)
            ->update(['sort_order' => 10]);
        $collectionUrl = $this->directLessonCollectionUrl(
            'admin',
            $templateId
        );
        $this->actingAs($admin)
            ->postJson(
                $collectionUrl,
                $this->validLessonData([
                    'title' => 'Preserved Invalid Lesson',
                    'sort_order' => 5,
                    'unlock_rule' => 'selected_lessons_completed',
                    'prerequisite_match' => 'any',
                    'prerequisite_lesson_ids' => [$futureId],
                ])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prerequisite_lesson_ids');

        $this->assertDatabaseMissing('core_course_template_lessons', [
            'customer_id' => $customerId,
            'title' => 'Preserved Invalid Lesson',
        ]);

        // The popup keeps what was typed (it is never reloaded); the fragment marks where the error goes.
        $xpath = $this->xpath($this->actingAs($admin)->withHeaders($this->ajaxHeaders())->get($collectionUrl.'/create')->assertOk()->getContent());
        $this->assertSame(1, $xpath->query('//*[@data-ui-error-for="prerequisite_lesson_ids"]//select[@name="prerequisite_lesson_ids[]"]/option[@value="'.$futureId.'"]')->length);
    }

    public function test_wildcard_prerequisite_error_is_rendered_adjacent_to_the_accessible_control(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Wildcard Error Course',
            'wildcard-error-course'
        );
        $otherTemplateId = $this->createTemplate(
            $otherCustomerId,
            'Other Wildcard Course',
            'other-wildcard-course'
        );
        $otherLessonId = $this->createLesson(
            $otherCustomerId,
            $otherTemplateId,
            null,
            'Foreign Prerequisite'
        );
        $collectionUrl = $this->directLessonCollectionUrl(
            'admin',
            $templateId
        );
        $this->actingAs($admin)
            ->postJson(
                $collectionUrl,
                $this->validLessonData([
                    'title' => 'Invalid Foreign Prerequisite',
                    'sort_order' => 2,
                    'unlock_rule' => 'selected_lessons_completed',
                    'prerequisite_match' => 'all',
                    'prerequisite_lesson_ids' => [$otherLessonId],
                ])
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prerequisite_lesson_ids.0');

        $this->assertDatabaseMissing('core_course_template_lessons', [
            'customer_id' => $customerId,
            'title' => 'Invalid Foreign Prerequisite',
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
            ->assertSeeText('Thêm bài học')
            ->assertSeeText('Sửa')
            ->assertSeeText('Xóa')
            ->assertSee('data-ui-row-delete', false)
            ->assertSee('data-confirm-title="Xoá bài học?"', false)
            ->assertSee('data-confirm-message="Bài học «UI Lesson» sẽ bị xoá vĩnh viễn và không thể hoàn tác."', false)
            ->assertDontSeeText('Bạn có chắc chắn muốn xóa bài học này không?');

        $response = $this->actingAs($admin)
            ->withHeaders($this->ajaxHeaders())
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
            'prerequisite_match',
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
                ->withHeaders($this->ajaxHeaders())
                ->get("{$collectionUrl}/create")
                ->assertOk(),
            $this->actingAs($admin)
                ->withHeaders($this->ajaxHeaders())
                ->get("{$collectionUrl}/{$lessonId}/edit")
                ->assertOk(),
        ];

        $responses[0]
            ->assertSee('name="prerequisite_lesson_ids[]" multiple', false);

        foreach ($responses as $response) {
            foreach ([
                'title',
                'short_description',
                'description',
                'is_preview',
                'lesson_type',
                'unlock_rule',
                'unlock_after_lesson_id',
                'unlock_at',
            ] as $field) {
                $response->assertSee('name="'.$field.'"', false);
            }

            $response->assertDontSee('name="sort_order"', false);

            $content = $response->getContent();
            $this->assertLessThan(
                strpos($content, 'name="is_preview"'),
                strpos($content, 'name="lesson_type"')
            );
            $this->assertLessThan(
                strpos($content, 'name="unlock_rule"'),
                strpos($content, 'name="lesson_type"')
            );
            $response
                ->assertSee('class="ui-modal__form"', false)
                ->assertSee('data-ui-dialog-form-submit', false)
                ->assertSee('data-ui-dialog-form-cancel', false)
                ->assertDontSee('class="admin-form-standard"', false)
                ->assertSeeText(__('lf.LF_course_template_lesson_common_type'));
            $footerContent = substr($content, strpos($content, 'class="ui-modal__footer"'));
            $this->assertLessThan(
                strpos($footerContent, 'data-ui-dialog-form-submit'),
                strpos($footerContent, 'data-ui-dialog-form-cancel')
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

    public function test_popup_save_and_delete_answer_json_and_flash_the_toast_for_the_reloaded_structure_tab(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate($customerId, 'Popup Course', 'popup-course', null, $teacher->id);
        $sectionId = $this->createSection($customerId, $templateId, 'Popup Section');

        foreach ([
            [$admin, 'admin'],
            [$teacher, 'teacher'],
        ] as [$user, $area]) {
            $directUrl = $this->directLessonCollectionUrl($area, $templateId);
            $structure = "https://tenant-a.localhost/{$area}/course-templates/{$templateId}/edit?tab=structure";

            $this->actingAs($user)
                ->postJson($directUrl, $this->validLessonData(['title' => "{$area} direct"]))
                ->assertCreated()
                ->assertJson([
                    'message' => __('lf.LF_course_template_lesson_common_created'),
                    'redirect' => $structure.'#course-template-direct-lessons',
                ])
                ->assertSessionHas('success', __('lf.LF_course_template_lesson_common_created'));

            $lessonId = (int) DB::table('core_course_template_lessons')
                ->where('title', "{$area} direct")->value('id');

            $this->actingAs($user)
                ->putJson("{$directUrl}/{$lessonId}", $this->validLessonData(['title' => "{$area} direct renamed", 'sort_order' => 1]))
                ->assertOk()
                ->assertJson([
                    'message' => __('lf.LF_course_template_lesson_common_updated'),
                    'redirect' => $structure.'#course-template-direct-lessons',
                ]);
            $this->assertDatabaseHas('core_course_template_lessons', ['id' => $lessonId, 'title' => "{$area} direct renamed"]);

            $this->actingAs($user)
                ->postJson($this->lessonCollectionUrl($area, $templateId, $sectionId), $this->validLessonData(['title' => "{$area} in section"]))
                ->assertCreated()
                ->assertJsonPath('redirect', $structure."#course-template-section-{$sectionId}-lessons");

            $this->actingAs($user)
                ->deleteJson("{$directUrl}/{$lessonId}")
                ->assertOk()
                ->assertJson([
                    'message' => __('lf.LF_course_template_lesson_common_deleted'),
                    'redirect' => $structure.'#course-template-direct-lessons',
                ]);
            $this->assertDatabaseMissing('core_course_template_lessons', ['id' => $lessonId]);
        }
    }

    public function test_delete_json_refuses_with_the_reason_and_the_button_is_disabled_for_a_blocked_lesson(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Blocked Course', 'blocked-course');
        $prerequisiteId = $this->createLesson($customerId, $templateId, null, 'Needed First');
        $this->createLesson($customerId, $templateId, null, 'Dependent', unlockAfterLessonId: $prerequisiteId);
        $withActivityId = $this->createLesson($customerId, $templateId, null, 'Has Activity');
        $freeId = $this->createLesson($customerId, $templateId, null, 'Free Lesson');

        DB::table('core_course_template_activities')->insert([
            'customer_id' => $customerId, 'template_id' => $templateId, 'template_lesson_id' => $withActivityId,
            'title' => 'Attached', 'description' => null, 'sort_order' => 0, 'activity_type' => 'text',
            'external_video_url' => null, 'live_class_url' => null, 'assessment_quiz_id' => null, 'duration_seconds' => 0,
            'is_required' => true, 'completion_rule' => 'view', 'completion_threshold' => null, 'is_preview' => false,
            'unlock_rule' => 'none', 'unlock_after_activity_id' => null, 'unlock_at' => null, 'created_by' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $directUrl = $this->directLessonCollectionUrl('admin', $templateId);

        $this->actingAs($admin)->deleteJson("{$directUrl}/{$prerequisiteId}")
            ->assertStatus(409)->assertJson(['message' => __('lf.LF_course_template_lesson_delete_blocked_referenced')]);
        $this->actingAs($admin)->deleteJson("{$directUrl}/{$withActivityId}")
            ->assertStatus(409)->assertJson(['message' => __('lf.LF_course_template_lesson_delete_blocked_activities')]);
        $this->assertDatabaseHas('core_course_template_lessons', ['id' => $prerequisiteId]);
        $this->assertDatabaseHas('core_course_template_lessons', ['id' => $withActivityId]);

        $xpath = $this->xpath($this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")->getContent());
        foreach ([$prerequisiteId => true, $withActivityId => true, $freeId => false] as $id => $blocked) {
            $button = $xpath->query('//button[@data-ui-row-delete and contains(@data-url, "/lessons/'.$id.'")]')->item(0);
            $this->assertNotNull($button);
            $this->assertSame($blocked, $button->hasAttribute('disabled'));
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
        $this->assertFalse(Schema::hasColumn('core_course_template_lessons', 'learning_objective'));
        $this->assertFalse(Schema::hasColumn('core_course_template_lessons', 'status'));
        $this->assertFalse(Schema::hasColumn('core_course_template_lessons', 'slug'));
        $this->assertFalse(Schema::hasColumn('core_course_template_version_lessons', 'learning_objective_snapshot'));
        $this->assertFalse(Schema::hasColumn('core_course_template_version_lessons', 'status_snapshot'));

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

    public function test_admin_and_teacher_can_reorder_direct_lessons_and_other_groups_stay(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate($customerId, 'Order', 'order', null, $teacher->id);
        $sectionId = $this->createSection($customerId, $templateId, 'Section');
        [$a, $b, $c] = $this->createOrderedLessons($customerId, $templateId, null, ['A', 'B', 'C']);
        [$inSection] = $this->createOrderedLessons($customerId, $templateId, $sectionId, ['In section']);
        DB::table('core_course_template_lessons')->where('id', $inSection)->update(['sort_order' => 7]);

        $this->actingAs($admin)
            ->patchJson($this->reorderUrl('admin', $templateId), ['lesson_ids' => [$c, $a, $b]])
            ->assertOk()
            ->assertJsonPath('message', __('lf.LF_course_template_lesson_reorder_saved'));
        $this->assertLessonOrders([$c => 1, $a => 2, $b => 3, $inSection => 7]);

        $this->actingAs($teacher)
            ->patchJson($this->reorderUrl('teacher', $templateId), ['lesson_ids' => [$b, $c, $a]])
            ->assertOk();
        $this->assertLessonOrders([$b => 1, $c => 2, $a => 3, $inSection => 7]);
    }

    public function test_reorder_of_a_section_touches_only_that_section(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        $sectionId = $this->createSection($customerId, $templateId, 'One');
        $otherSectionId = $this->createSection($customerId, $templateId, 'Two');
        [$a, $b] = $this->createOrderedLessons($customerId, $templateId, $sectionId, ['A', 'B']);
        [$other] = $this->createOrderedLessons($customerId, $templateId, $otherSectionId, ['Other']);
        [$direct] = $this->createOrderedLessons($customerId, $templateId, null, ['Direct']);

        $this->actingAs($admin)
            ->patchJson($this->reorderUrl('admin', $templateId, $sectionId), ['lesson_ids' => [$b, $a]])
            ->assertOk();
        $this->assertLessonOrders([$b => 1, $a => 2, $other => 1, $direct => 1]);

        foreach ([[$b, $a, $other], [$b, $a, $direct], [$a]] as $wrongGroup) {
            $this->actingAs($admin)
                ->patchJson($this->reorderUrl('admin', $templateId, $sectionId), ['lesson_ids' => $wrongGroup])
                ->assertStatus(409)
                ->assertJsonPath('message', __('lf.LF_course_template_lesson_reorder_stale'));
        }
        $this->assertLessonOrders([$b => 1, $a => 2, $other => 1, $direct => 1]);
    }

    public function test_reorder_refuses_a_tampered_list_without_writing(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        $otherTemplateId = $this->createTemplate($otherCustomerId, 'Foreign', 'foreign');
        [$a, $b] = $this->createOrderedLessons($customerId, $templateId, null, ['A', 'B']);
        [$foreign] = $this->createOrderedLessons($otherCustomerId, $otherTemplateId, null, ['Foreign']);
        $url = $this->reorderUrl('admin', $templateId);

        $this->actingAs($admin)->patchJson($url, ['lesson_ids' => [$b, $a, $foreign]])->assertStatus(409);
        $this->actingAs($admin)->patchJson($url, ['lesson_ids' => [$foreign, $b]])->assertStatus(409);
        $this->actingAs($admin)->patchJson($url, ['lesson_ids' => [$b, $b]])->assertUnprocessable();
        $this->actingAs($admin)->patchJson($url, ['lesson_ids' => ['x', $a]])->assertUnprocessable();
        $this->actingAs($admin)->patchJson($url, ['lesson_ids' => []])->assertUnprocessable();
        $this->actingAs($admin)->patchJson($url, [])->assertUnprocessable();

        $this->assertLessonOrders([$a => 1, $b => 2, $foreign => 1]);
    }

    public function test_reorder_blocks_a_new_prerequisite_violation_in_both_directions_and_names_the_lessons(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$a, $b, $c] = $this->createOrderedLessons($customerId, $templateId, null, ['Alpha', 'Beta', 'Gamma']);
        $this->selectPrerequisite($customerId, $templateId, $c, $a);
        $url = $this->reorderUrl('admin', $templateId);
        $message = __('lf.LF_course_template_lesson_reorder_blocked_prerequisite', [
            'lesson' => 'Gamma',
            'prerequisite' => 'Alpha',
        ]);

        // The dependent moved before its prerequisite, and the prerequisite moved after its dependent.
        foreach ([[$c, $a, $b], [$b, $c, $a]] as $order) {
            $this->actingAs($admin)
                ->patchJson($url, ['lesson_ids' => $order])
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);
            $this->assertLessonOrders([$a => 1, $b => 2, $c => 3]);
        }

        $this->actingAs($admin)->patchJson($url, ['lesson_ids' => [$b, $a, $c]])->assertOk();
        $this->assertLessonOrders([$b => 1, $a => 2, $c => 3]);
    }

    public function test_reorder_blocks_all_previous_rule_in_first_place_and_the_legacy_single_prerequisite(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$a, $b, $c] = $this->createOrderedLessons($customerId, $templateId, null, ['Alpha', 'Beta', 'Gamma']);
        DB::table('core_course_template_lessons')->where('id', $b)
            ->update(['unlock_rule' => 'all_previous_lessons_completed']);
        DB::table('core_course_template_lessons')->where('id', $c)
            ->update(['unlock_rule' => 'previous_lesson_completed', 'unlock_after_lesson_id' => $a]);
        $url = $this->reorderUrl('admin', $templateId);

        $this->actingAs($admin)->patchJson($url, ['lesson_ids' => [$b, $a, $c]])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('lf.LF_course_template_lesson_reorder_blocked_first', ['lesson' => 'Beta']));
        $this->actingAs($admin)->patchJson($url, ['lesson_ids' => [$c, $a, $b]])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('lf.LF_course_template_lesson_reorder_blocked_prerequisite', [
                'lesson' => 'Gamma',
                'prerequisite' => 'Alpha',
            ]));
        $this->assertLessonOrders([$a => 1, $b => 2, $c => 3]);
    }

    public function test_reorder_checks_prerequisites_across_sections_of_the_lane_and_ignores_older_problems(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        $firstSectionId = $this->createSection($customerId, $templateId, 'One');
        $secondSectionId = $this->createSection($customerId, $templateId, 'Two');
        DB::table('core_course_template_sections')->where('id', $firstSectionId)->update(['display_order' => 1]);
        DB::table('core_course_template_sections')->where('id', $secondSectionId)->update(['display_order' => 2]);
        [$a, $b] = $this->createOrderedLessons($customerId, $templateId, $firstSectionId, ['Alpha', 'Beta']);
        [$c, $d] = $this->createOrderedLessons($customerId, $templateId, $secondSectionId, ['Gamma', 'Delta']);
        // An edge from a later Section stays valid whatever the order inside the first one.
        $this->selectPrerequisite($customerId, $templateId, $c, $a);
        // Already wrong before this move: Delta is required by an earlier lesson. It must not block unrelated work.
        $this->selectPrerequisite($customerId, $templateId, $b, $d);

        $this->actingAs($admin)
            ->patchJson($this->reorderUrl('admin', $templateId, $firstSectionId), ['lesson_ids' => [$b, $a]])
            ->assertOk();
        $this->assertLessonOrders([$b => 1, $a => 2, $c => 1, $d => 2]);
    }

    public function test_reorder_respects_tenant_role_and_template_assignment(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $unassignedTeacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        $otherTemplateId = $this->createTemplate($otherCustomerId, 'Foreign', 'foreign');
        $foreignSectionId = $this->createSection($otherCustomerId, $otherTemplateId, 'Foreign');
        [$a, $b] = $this->createOrderedLessons($customerId, $templateId, null, ['A', 'B']);
        [$x, $y] = $this->createOrderedLessons($otherCustomerId, $otherTemplateId, null, ['X', 'Y']);
        $payload = ['lesson_ids' => [$b, $a]];

        $this->patchJson($this->reorderUrl('admin', $templateId), $payload)->assertUnauthorized();
        $this->actingAs($student)->patchJson($this->reorderUrl('admin', $templateId), $payload)->assertForbidden();
        $this->actingAs($student)->patchJson($this->reorderUrl('teacher', $templateId), $payload)->assertForbidden();
        $this->actingAs($unassignedTeacher)->patchJson($this->reorderUrl('teacher', $templateId), $payload)->assertNotFound();
        $this->actingAs($unassignedTeacher)->patchJson($this->reorderUrl('admin', $templateId), $payload)->assertForbidden();
        $this->actingAs($admin)
            ->patchJson($this->reorderUrl('admin', $otherTemplateId), ['lesson_ids' => [$y, $x]])
            ->assertNotFound();
        $this->actingAs($admin)
            ->patchJson($this->reorderUrl('admin', $templateId, $foreignSectionId), $payload)
            ->assertNotFound();

        $this->assertLessonOrders([$a => 1, $b => 2, $x => 1, $y => 2]);
    }

    public function test_reorder_answers_only_json_requests(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$a, $b] = $this->createOrderedLessons($customerId, $templateId, null, ['A', 'B']);

        $this->actingAs($admin)
            ->patch($this->reorderUrl('admin', $templateId), ['lesson_ids' => [$b, $a]])
            ->assertNotFound();
        $this->assertLessonOrders([$a => 1, $b => 2]);
    }

    public function test_the_drag_handle_appears_only_on_a_group_with_two_lessons(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        $sectionId = $this->createSection($customerId, $templateId, 'Section');
        $this->createOrderedLessons($customerId, $templateId, $sectionId, ['Only one']);
        $edit = "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure";

        $this->actingAs($admin)->get($edit)->assertOk()->assertDontSee('course-template-order-handle');

        $this->createOrderedLessons($customerId, $templateId, null, ['First', 'Second']);
        $this->actingAs($admin)->get($edit)->assertOk()
            ->assertSee('course-template-order-handle')
            ->assertSee($this->reorderUrl('admin', $templateId))
            ->assertDontSee($this->reorderUrl('admin', $templateId, $sectionId))
            ->assertSee(__('lf.LF_course_template_lesson_reorder_handle', ['title' => 'Second']));
    }

    public function test_update_rejects_moving_a_prerequisite_after_its_dependent_and_saves_nothing(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        $sectionId = $this->createSection($customerId, $templateId, 'Section');
        // The message names the lesson as the author just typed it; that title is not saved.
        $message = __('lf.LF_course_template_lesson_reorder_blocked_prerequisite', [
            'lesson' => 'Gamma',
            'prerequisite' => 'Renamed',
        ]);

        foreach ([
            [null, $this->directLessonCollectionUrl('admin', $templateId)],
            [$sectionId, $this->lessonCollectionUrl('admin', $templateId, $sectionId)],
        ] as [$lane, $collectionUrl]) {
            [$a, $b, $c] = $this->createOrderedLessons($customerId, $templateId, $lane, ['Alpha', 'Beta', 'Gamma']);
            $this->selectPrerequisite($customerId, $templateId, $c, $a);
            $moved = $this->validLessonData(['title' => 'Renamed', 'sort_order' => 9]);

            $this->actingAs($admin)
                ->from($collectionUrl)
                ->put("{$collectionUrl}/{$a}", $moved)
                ->assertSessionHasErrors(['sort_order' => $message]);
            $this->actingAs($admin)
                ->putJson("{$collectionUrl}/{$a}", $moved)
                ->assertUnprocessable()
                ->assertJsonPath('errors.sort_order.0', $message);

            $this->assertLessonOrders([$a => 1, $b => 2, $c => 3]);
            $this->assertDatabaseHas('core_course_template_lessons', ['id' => $a, 'title' => 'Alpha']);
        }
    }

    public function test_update_refusal_also_rolls_back_the_prerequisites_of_the_moved_lesson(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$first, $a, $c] = $this->createOrderedLessons($customerId, $templateId, null, ['First', 'Alpha', 'Gamma']);
        $this->selectPrerequisite($customerId, $templateId, $a, $first);
        $this->selectPrerequisite($customerId, $templateId, $c, $a);

        $this->actingAs($admin)
            ->putJson($this->directLessonCollectionUrl('admin', $templateId)."/{$a}", $this->validLessonData([
                'title' => 'Alpha',
                'sort_order' => 9,
                'unlock_rule' => 'none',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort_order');

        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $a,
            'sort_order' => 2,
            'unlock_rule' => 'selected_lessons_completed',
        ]);
        $this->assertDatabaseHas('core_course_template_lesson_prerequisites', [
            'lesson_id' => $a,
            'prerequisite_lesson_id' => $first,
        ]);
    }

    public function test_update_rejects_leaving_an_all_previous_lesson_or_a_legacy_dependent_in_a_wrong_place(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$a, $b, $c] = $this->createOrderedLessons($customerId, $templateId, null, ['Alpha', 'Beta', 'Gamma']);
        DB::table('core_course_template_lessons')->where('id', $b)
            ->update(['unlock_rule' => 'all_previous_lessons_completed']);
        $url = $this->directLessonCollectionUrl('admin', $templateId);

        $this->actingAs($admin)
            ->putJson("{$url}/{$a}", $this->validLessonData(['title' => 'Alpha', 'sort_order' => 5]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.sort_order.0', __('lf.LF_course_template_lesson_reorder_blocked_first', ['lesson' => 'Beta']));

        DB::table('core_course_template_lessons')->where('id', $b)->update(['unlock_rule' => 'none']);
        DB::table('core_course_template_lessons')->where('id', $c)
            ->update(['unlock_rule' => 'previous_lesson_completed', 'unlock_after_lesson_id' => $b]);

        $this->actingAs($admin)
            ->putJson("{$url}/{$b}", $this->validLessonData(['title' => 'Beta', 'sort_order' => 5]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.sort_order.0', __('lf.LF_course_template_lesson_reorder_blocked_prerequisite', [
                'lesson' => 'Gamma',
                'prerequisite' => 'Beta',
            ]));
        $this->assertLessonOrders([$a => 1, $b => 2, $c => 3]);
    }

    public function test_update_still_allows_safe_moves_and_edits_when_an_older_problem_exists(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$a, $b, $c, $d] = $this->createOrderedLessons($customerId, $templateId, null, ['Alpha', 'Beta', 'Gamma', 'Delta']);
        $this->selectPrerequisite($customerId, $templateId, $c, $a);
        // Already wrong before this edit: Beta requires a lesson placed after it.
        $this->selectPrerequisite($customerId, $templateId, $b, $d);
        $url = $this->directLessonCollectionUrl('admin', $templateId);

        // A prerequisite that stays before its dependent, the dependent moved later, and a plain rename.
        $this->actingAs($admin)
            ->putJson("{$url}/{$c}", $this->validLessonData([
                'title' => 'Gamma',
                'sort_order' => 3,
                'unlock_rule' => 'selected_lessons_completed',
                'prerequisite_match' => 'all',
                'prerequisite_lesson_ids' => [$a],
            ]))
            ->assertOk();
        $this->actingAs($admin)
            ->putJson("{$url}/{$a}", $this->validLessonData(['title' => 'Alpha renamed', 'sort_order' => 1]))
            ->assertOk();
        $this->assertDatabaseHas('core_course_template_lessons', ['id' => $a, 'title' => 'Alpha renamed']);

        // Dropping the dependent's rule in the same save frees its prerequisite on the next one.
        $this->actingAs($admin)
            ->putJson("{$url}/{$c}", $this->validLessonData(['title' => 'Gamma', 'sort_order' => 3, 'unlock_rule' => 'none']))
            ->assertOk();
        $this->actingAs($admin)
            ->putJson("{$url}/{$a}", $this->validLessonData(['title' => 'Alpha renamed', 'sort_order' => 9]))
            ->assertOk();
        $this->assertLessonOrders([$a => 9, $b => 2, $c => 3, $d => 4]);
    }

    public function test_update_without_an_order_keeps_the_position_and_still_checks_prerequisites_against_it(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$a, $b, $c] = $this->createOrderedLessons($customerId, $templateId, null, ['Alpha', 'Beta', 'Gamma']);
        $url = $this->directLessonCollectionUrl('admin', $templateId);
        $withoutOrder = function (array $overrides): array {
            $data = $this->validLessonData($overrides);
            unset($data['sort_order']);

            return $data;
        };

        $this->actingAs($admin)
            ->putJson("{$url}/{$b}", $withoutOrder(['title' => 'Beta renamed']))
            ->assertOk();
        $this->assertLessonOrders([$a => 1, $b => 2, $c => 3]);
        $this->assertDatabaseHas('core_course_template_lessons', ['id' => $b, 'title' => 'Beta renamed']);

        // Beta stays second: a lesson after it cannot become its prerequisite, one before it can.
        $this->actingAs($admin)
            ->putJson("{$url}/{$b}", $withoutOrder([
                'title' => 'Beta',
                'unlock_rule' => 'selected_lessons_completed',
                'prerequisite_match' => 'all',
                'prerequisite_lesson_ids' => [$c],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prerequisite_lesson_ids');
        $this->actingAs($admin)
            ->putJson("{$url}/{$b}", $withoutOrder([
                'title' => 'Beta',
                'unlock_rule' => 'selected_lessons_completed',
                'prerequisite_match' => 'all',
                'prerequisite_lesson_ids' => [$a],
            ]))
            ->assertOk();
        $this->assertLessonOrders([$a => 1, $b => 2, $c => 3]);

        // The first lesson stays first, so it still has no lesson before it.
        $this->actingAs($admin)
            ->putJson("{$url}/{$a}", $withoutOrder(['title' => 'Alpha', 'unlock_rule' => 'all_previous_lessons_completed']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unlock_rule');
    }

    public function test_admin_and_teacher_reorder_root_and_child_sections_and_other_groups_stay(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate($customerId, 'Order', 'order', null, $teacher->id);
        [$one, $two, $three] = $this->createOrderedSections($customerId, $templateId, ['One', 'Two', 'Three']);
        [$childA, $childB] = $this->createOrderedSections($customerId, $templateId, ['Child A', 'Child B']);
        DB::table('core_course_template_sections')->whereIn('id', [$childA, $childB])->update(['parent_section_id' => $two]);

        $this->actingAs($admin)
            ->patchJson($this->sectionReorderUrl('admin', $templateId), ['section_ids' => [$three, $one, $two]])
            ->assertOk()
            ->assertJsonPath('message', __('lf.LF_course_template_section_reorder_saved'));
        $this->assertSectionOrders([$three => 1, $one => 2, $two => 3, $childA => 1, $childB => 2]);

        $this->actingAs($teacher)
            ->patchJson($this->sectionReorderUrl('teacher', $templateId, $two), ['section_ids' => [$childB, $childA]])
            ->assertOk();
        $this->assertSectionOrders([$three => 1, $one => 2, $two => 3, $childB => 1, $childA => 2]);
    }

    public function test_section_reorder_refuses_a_list_that_is_not_exactly_the_group(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        $secondTemplateId = $this->createTemplate($customerId, 'Second', 'second');
        $otherTemplateId = $this->createTemplate($otherCustomerId, 'Foreign', 'foreign');
        [$one, $two] = $this->createOrderedSections($customerId, $templateId, ['One', 'Two']);
        [$child] = $this->createOrderedSections($customerId, $templateId, ['Child']);
        DB::table('core_course_template_sections')->where('id', $child)->update(['parent_section_id' => $one]);
        [$sameTenant] = $this->createOrderedSections($customerId, $secondTemplateId, ['Same tenant']);
        [$foreign] = $this->createOrderedSections($otherCustomerId, $otherTemplateId, ['Foreign']);
        $url = $this->sectionReorderUrl('admin', $templateId);

        foreach ([[$two], [$two, $one, $child], [$two, $one, $sameTenant], [$two, $one, $foreign], [$two, $one, 999999]] as $wrong) {
            $this->actingAs($admin)->patchJson($url, ['section_ids' => $wrong])
                ->assertStatus(409)
                ->assertJsonPath('message', __('lf.LF_course_template_section_reorder_stale'));
        }
        // A parent of another Template or tenant is an empty group, whatever ids come with it.
        $this->actingAs($admin)
            ->patchJson($this->sectionReorderUrl('admin', $templateId, $foreign), ['section_ids' => [$two, $one]])
            ->assertStatus(409);
        $this->actingAs($admin)->patchJson($url, ['section_ids' => [$two, $two]])->assertUnprocessable();
        $this->actingAs($admin)->patchJson($url, ['section_ids' => []])->assertUnprocessable();
        $this->actingAs($admin)->patchJson($url.'?parent_section_id=x', ['section_ids' => [$two, $one]])->assertUnprocessable();
        $this->actingAs($admin)->patch($url, ['section_ids' => [$two, $one]])->assertNotFound();

        $this->assertSectionOrders([$one => 1, $two => 2, $child => 1, $sameTenant => 1, $foreign => 1]);
    }

    public function test_section_reorder_blocks_new_unlock_violations_in_a_branch_and_ignores_older_ones(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$one, $two, $three] = $this->createOrderedSections($customerId, $templateId, ['One', 'Two', 'Three']);
        [$deep] = $this->createOrderedSections($customerId, $templateId, ['Deep']);
        DB::table('core_course_template_sections')->where('id', $deep)->update(['parent_section_id' => $one]);
        // The prerequisite sits in a child: moving its root moves the branch past the dependent Lesson.
        [$alpha] = $this->createOrderedLessons($customerId, $templateId, $deep, ['Alpha']);
        [$gamma] = $this->createOrderedLessons($customerId, $templateId, $two, ['Gamma']);
        [$older] = $this->createOrderedLessons($customerId, $templateId, $three, ['Older']);
        $this->selectPrerequisite($customerId, $templateId, $gamma, $alpha);
        $url = $this->sectionReorderUrl('admin', $templateId);

        $this->actingAs($admin)
            ->patchJson($url, ['section_ids' => [$two, $one, $three]])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('lf.LF_course_template_lesson_reorder_blocked_prerequisite', [
                'lesson' => 'Gamma',
                'prerequisite' => 'Alpha',
            ]));
        $this->assertSectionOrders([$one => 1, $two => 2, $three => 3]);

        // A safe move goes through although an older problem elsewhere stays.
        $this->selectPrerequisite($customerId, $templateId, $alpha, $older);
        $this->actingAs($admin)
            ->patchJson($url, ['section_ids' => [$one, $three, $two]])
            ->assertOk();
        $this->assertSectionOrders([$one => 1, $three => 2, $two => 3]);
    }

    public function test_section_reorder_blocks_leaving_an_all_previous_lesson_first(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$one, $two] = $this->createOrderedSections($customerId, $templateId, ['One', 'Two']);
        $this->createOrderedLessons($customerId, $templateId, $one, ['Alpha']);
        [$gamma] = $this->createOrderedLessons($customerId, $templateId, $two, ['Gamma']);
        DB::table('core_course_template_lessons')->where('id', $gamma)->update(['unlock_rule' => 'all_previous_lessons_completed']);

        $this->actingAs($admin)
            ->patchJson($this->sectionReorderUrl('admin', $templateId), ['section_ids' => [$two, $one]])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('lf.LF_course_template_lesson_reorder_blocked_first', ['lesson' => 'Gamma']));
        $this->assertSectionOrders([$one => 1, $two => 2]);
    }

    public function test_section_reorder_respects_tenant_role_and_template_assignment(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $unassignedTeacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        $otherTemplateId = $this->createTemplate($otherCustomerId, 'Foreign', 'foreign');
        [$a, $b] = $this->createOrderedSections($customerId, $templateId, ['A', 'B']);
        [$x, $y] = $this->createOrderedSections($otherCustomerId, $otherTemplateId, ['X', 'Y']);
        $payload = ['section_ids' => [$b, $a]];

        $this->patchJson($this->sectionReorderUrl('admin', $templateId), $payload)->assertUnauthorized();
        $this->actingAs($student)->patchJson($this->sectionReorderUrl('admin', $templateId), $payload)->assertForbidden();
        $this->actingAs($student)->patchJson($this->sectionReorderUrl('teacher', $templateId), $payload)->assertForbidden();
        $this->actingAs($unassignedTeacher)->patchJson($this->sectionReorderUrl('teacher', $templateId), $payload)->assertNotFound();
        $this->actingAs($unassignedTeacher)->patchJson($this->sectionReorderUrl('admin', $templateId), $payload)->assertForbidden();
        $this->actingAs($admin)
            ->patchJson($this->sectionReorderUrl('admin', $otherTemplateId), ['section_ids' => [$y, $x]])
            ->assertNotFound();

        $this->assertSectionOrders([$a => 1, $b => 2, $x => 1, $y => 2]);
    }

    public function test_section_form_without_an_order_keeps_the_place_and_a_new_parent_puts_it_last(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$one, $two, $three] = $this->createOrderedSections($customerId, $templateId, ['One', 'Two', 'Three']);
        [$child] = $this->createOrderedSections($customerId, $templateId, ['Child']);
        DB::table('core_course_template_sections')->where('id', $child)->update(['parent_section_id' => $one, 'display_order' => 4]);
        $url = "https://tenant-a.localhost/admin/course-templates/{$templateId}/sections/{$two}";
        $data = fn (array $overrides): array => array_diff_key($this->validSectionData($overrides), ['display_order' => true]);

        $this->actingAs($admin)->putJson($url, $data(['title' => 'Renamed']))->assertOk();
        $this->assertDatabaseHas('core_course_template_sections', ['id' => $two, 'title' => 'Renamed', 'display_order' => 2]);

        $this->actingAs($admin)->putJson($url, $data(['title' => 'Renamed', 'parent_section_id' => $one]))->assertOk();
        $this->assertDatabaseHas('core_course_template_sections', ['id' => $two, 'parent_section_id' => $one, 'display_order' => 5]);

        $this->actingAs($admin)->putJson($url, $data(['title' => 'Renamed']))->assertOk();
        $this->assertDatabaseHas('core_course_template_sections', ['id' => $two, 'parent_section_id' => null, 'display_order' => 4]);
        $this->assertSectionOrders([$one => 1, $three => 3, $child => 4]);
    }

    public function test_the_section_handle_appears_only_among_two_or_more_siblings(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$only] = $this->createOrderedSections($customerId, $templateId, ['Only root']);
        [$lonely] = $this->createOrderedSections($customerId, $templateId, ['Lonely child']);
        DB::table('core_course_template_sections')->where('id', $lonely)->update(['parent_section_id' => $only]);
        $edit = "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure";

        $this->actingAs($admin)->get($edit)->assertOk()->assertDontSee('data-reorder-field="section_ids"', false);

        [$second] = $this->createOrderedSections($customerId, $templateId, ['Second child']);
        DB::table('core_course_template_sections')->where('id', $second)->update(['parent_section_id' => $only]);
        $reorderUrl = $this->sectionReorderUrl('admin', $templateId);
        $this->actingAs($admin)->get($edit)->assertOk()
            ->assertSee($reorderUrl.'?parent_section_id='.$only, false)
            ->assertDontSee('data-reorder-url="'.$reorderUrl.'"', false)
            ->assertSee(__('lf.LF_course_template_section_reorder_handle', ['title' => 'Second child']))
            ->assertDontSee(__('lf.LF_course_template_section_reorder_handle', ['title' => 'Only root']));
    }

    public function test_section_update_rejects_an_order_that_breaks_a_lesson_in_another_section(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$one, $two, $three] = $this->createOrderedSections($customerId, $templateId, ['One', 'Two', 'Three']);
        [$alpha] = $this->createOrderedLessons($customerId, $templateId, $one, ['Alpha']);
        [$gamma] = $this->createOrderedLessons($customerId, $templateId, $two, ['Gamma']);
        $this->selectPrerequisite($customerId, $templateId, $gamma, $alpha);
        $message = __('lf.LF_course_template_lesson_reorder_blocked_prerequisite', [
            'lesson' => 'Gamma',
            'prerequisite' => 'Alpha',
        ]);
        $sectionUrl = fn (int $id): string => "https://tenant-a.localhost/admin/course-templates/{$templateId}/sections/{$id}";

        $this->actingAs($admin)
            ->putJson($sectionUrl($one), $this->validSectionData(['title' => 'Renamed', 'display_order' => 9]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.display_order.0', $message);
        $this->actingAs($admin)
            ->putJson($sectionUrl($two), $this->validSectionData(['title' => 'Two', 'display_order' => 0]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.display_order.0', $message);
        // A new parent carries the Section after the Lesson that depends on it: the error goes to the parent field.
        $this->actingAs($admin)
            ->putJson($sectionUrl($one), $this->validSectionData([
                'title' => 'One',
                'display_order' => 1,
                'parent_section_id' => $three,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.parent_section_id.0', $message);

        $this->assertDatabaseHas('core_course_template_sections', [
            'id' => $one,
            'title' => 'One',
            'display_order' => 1,
            'parent_section_id' => null,
        ]);
        $this->assertDatabaseHas('core_course_template_sections', ['id' => $two, 'display_order' => 2]);
    }

    public function test_section_update_rejects_leaving_an_all_previous_lesson_first_and_allows_safe_changes(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$one, $two, $three] = $this->createOrderedSections($customerId, $templateId, ['One', 'Two', 'Three']);
        [$alpha] = $this->createOrderedLessons($customerId, $templateId, $one, ['Alpha']);
        [$beta] = $this->createOrderedLessons($customerId, $templateId, $two, ['Beta']);
        [$delta] = $this->createOrderedLessons($customerId, $templateId, $three, ['Delta']);
        DB::table('core_course_template_lessons')->where('id', $beta)
            ->update(['unlock_rule' => 'all_previous_lessons_completed']);
        $sectionUrl = fn (int $id): string => "https://tenant-a.localhost/admin/course-templates/{$templateId}/sections/{$id}";

        $this->actingAs($admin)
            ->putJson($sectionUrl($one), $this->validSectionData(['title' => 'One', 'display_order' => 9]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.display_order.0', __('lf.LF_course_template_lesson_reorder_blocked_first', ['lesson' => 'Beta']));

        // Three moves freely, and a rename leaves the order alone. An older problem does not block either.
        $this->selectPrerequisite($customerId, $templateId, $alpha, $delta);
        $this->actingAs($admin)
            ->putJson($sectionUrl($three), $this->validSectionData(['title' => 'Three', 'display_order' => 7]))
            ->assertOk();
        $this->actingAs($admin)
            ->putJson($sectionUrl($two), $this->validSectionData(['title' => 'Two renamed', 'display_order' => 2]))
            ->assertOk();
        $this->assertDatabaseHas('core_course_template_sections', ['id' => $three, 'display_order' => 7]);
        $this->assertDatabaseHas('core_course_template_sections', ['id' => $two, 'title' => 'Two renamed']);
    }

    public function test_section_update_ignores_rules_of_direct_lessons(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$one] = $this->createOrderedSections($customerId, $templateId, ['One']);
        [$a, $b] = $this->createOrderedLessons($customerId, $templateId, null, ['Alpha', 'Beta']);
        $this->selectPrerequisite($customerId, $templateId, $a, $b);

        $this->actingAs($admin)
            ->putJson(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/sections/{$one}",
                $this->validSectionData(['title' => 'One', 'display_order' => 9])
            )
            ->assertOk();
    }

    public function test_deleting_the_lesson_that_would_leave_an_all_previous_lesson_first_is_refused(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$one, $two] = $this->createOrderedSections($customerId, $templateId, ['One', 'Two']);
        $collections = [
            $this->directLessonCollectionUrl('admin', $templateId) => [null, null],
            $this->lessonCollectionUrl('admin', $templateId, $one) => [$one, $two],
        ];

        foreach ($collections as $url => [$firstGroup, $secondGroup]) {
            [$a] = $this->createOrderedLessons($customerId, $templateId, $firstGroup, ['Alpha']);
            [$b] = $secondGroup === null
                ? [$this->createLesson($customerId, $templateId, null, 'Beta')]
                : $this->createOrderedLessons($customerId, $templateId, $secondGroup, ['Beta']);
            DB::table('core_course_template_lessons')->where('id', $b)
                ->update(['sort_order' => 2, 'unlock_rule' => 'all_previous_lessons_completed']);
            $message = __('lf.LF_course_template_lesson_delete_blocked_first', ['lesson' => 'Beta']);

            $this->actingAs($admin)
                ->deleteJson("{$url}/{$a}")
                ->assertStatus(409)
                ->assertJsonPath('message', $message);
            $this->assertDatabaseHas('core_course_template_lessons', ['id' => $a]);

            // The button says so up front.
            $this->actingAs($admin)
                ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure")
                ->assertOk()
                ->assertSee('data-tooltip="'.e($message).'"', false);

            // Beta needs no lesson before it any more: Alpha can go.
            DB::table('core_course_template_lessons')->where('id', $b)->update(['unlock_rule' => 'none']);
            $this->actingAs($admin)->deleteJson("{$url}/{$a}")->assertOk();
            $this->assertDatabaseMissing('core_course_template_lessons', ['id' => $a]);
            DB::table('core_course_template_lessons')->where('id', $b)->delete();
        }
    }

    public function test_deleting_a_lesson_that_is_not_first_ignores_a_later_all_previous_lesson(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Order', 'order');
        [$a, $b, $c] = $this->createOrderedLessons($customerId, $templateId, null, ['Alpha', 'Beta', 'Gamma']);
        DB::table('core_course_template_lessons')->where('id', $c)
            ->update(['unlock_rule' => 'all_previous_lessons_completed']);

        $this->actingAs($admin)
            ->deleteJson($this->directLessonCollectionUrl('admin', $templateId)."/{$b}")
            ->assertOk();
        $this->assertDatabaseMissing('core_course_template_lessons', ['id' => $b]);
        $this->assertDatabaseHas('core_course_template_lessons', ['id' => $a]);

        // Gamma, second now, keeps Alpha before it: the lesson after it can still go.
        $delta = $this->createLesson($customerId, $templateId, null, 'Delta');
        DB::table('core_course_template_lessons')->where('id', $delta)->update(['sort_order' => 4]);
        $this->actingAs($admin)
            ->deleteJson($this->directLessonCollectionUrl('admin', $templateId)."/{$delta}")
            ->assertOk();
        $this->assertDatabaseMissing('core_course_template_lessons', ['id' => $delta]);
    }

    /** @return array<int, int> ids, display_order numbered from 1 in the given order */
    private function createOrderedSections(int $customerId, int $templateId, array $titles): array
    {
        $ids = [];
        foreach ($titles as $index => $title) {
            $id = $this->createSection($customerId, $templateId, $title);
            DB::table('core_course_template_sections')->where('id', $id)->update(['display_order' => $index + 1]);
            $ids[] = $id;
        }

        return $ids;
    }

    private function validSectionData(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Section',
            'description' => null,
            'parent_section_id' => null,
            'allows_lessons' => 1,
            'display_order' => 1,
        ], $overrides);
    }

    /** @return array<int, int> ids, numbered from 1 in the given order */
    private function createOrderedLessons(int $customerId, int $templateId, ?int $sectionId, array $titles): array
    {
        $ids = [];
        foreach ($titles as $index => $title) {
            $id = $this->createLesson($customerId, $templateId, $sectionId, $title);
            DB::table('core_course_template_lessons')->where('id', $id)->update(['sort_order' => $index + 1]);
            $ids[] = $id;
        }

        return $ids;
    }

    private function selectPrerequisite(int $customerId, int $templateId, int $lessonId, int $prerequisiteId): void
    {
        DB::table('core_course_template_lessons')->where('id', $lessonId)->update([
            'unlock_rule' => 'selected_lessons_completed',
            'prerequisite_match' => 'all',
        ]);
        DB::table('core_course_template_lesson_prerequisites')->insert([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'lesson_id' => $lessonId,
            'prerequisite_lesson_id' => $prerequisiteId,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function reorderUrl(string $area, int $templateId, ?int $sectionId = null): string
    {
        return "https://tenant-a.localhost/{$area}/course-templates/{$templateId}"
            .($sectionId === null ? '' : "/sections/{$sectionId}")
            .'/lessons/reorder';
    }

    private function sectionReorderUrl(string $area, int $templateId, ?int $parentSectionId = null): string
    {
        return "https://tenant-a.localhost/{$area}/course-templates/{$templateId}/sections/reorder"
            .($parentSectionId === null ? '' : "?parent_section_id={$parentSectionId}");
    }

    /** @param array<int, int> $orders display_order by section id */
    private function assertSectionOrders(array $orders): void
    {
        foreach ($orders as $sectionId => $displayOrder) {
            $this->assertSame(
                $displayOrder,
                (int) DB::table('core_course_template_sections')->where('id', $sectionId)->value('display_order'),
                "display_order of section {$sectionId}"
            );
        }
    }

    /** @param array<int, int> $orders sort_order by lesson id */
    private function assertLessonOrders(array $orders): void
    {
        foreach ($orders as $lessonId => $sortOrder) {
            $this->assertSame(
                $sortOrder,
                (int) DB::table('core_course_template_lessons')->where('id', $lessonId)->value('sort_order'),
                "sort_order of lesson {$lessonId}"
            );
        }
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

        return DB::table('core_course_templates')->insertGetId(['course_type' => 'blended',
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

    /** The lesson form is a popup fragment: it answers only XHR. */
    private function ajaxHeaders(): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest'];
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
            '//label[@for="lesson-form-%s"]//span[contains(concat(" ", normalize-space(@class), " "), " ui-field__required ")]',
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
