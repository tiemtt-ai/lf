<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CourseTemplateActivityManagementTest extends TestCase
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

    public function test_admin_and_teacher_can_view_activities_inside_their_lessons(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        [$otherCustomerId, $otherTemplateId, , $otherLessonId] =
            $this->createHierarchy('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $this->createActivity(
            $customerId,
            $templateId,
            $lessonId,
            'Alphabet Text'
        );
        $this->createActivity(
            $otherCustomerId,
            $otherTemplateId,
            $otherLessonId,
            'Private Tenant Activity'
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
                ->assertSeeText('+ Thêm Activity')
                ->assertSeeText('Alphabet Text')
                ->assertDontSeeText('Private Tenant Activity');
        }
    }

    public function test_admin_can_create_update_and_delete_an_activity(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $collectionUrl = $this->activityCollectionUrl(
            'admin',
            $templateId,
            $sectionId,
            $lessonId
        );

        $this->actingAs($admin)
            ->post($collectionUrl, $this->validActivityData([
                'title' => 'Reading Text',
                'description' => 'Read this introduction',
                'sort_order' => 3,
                'activity_type' => 'text',
                'duration_seconds' => 300,
                'is_required' => 1,
                'completion_rule' => 'view',
                'is_preview' => 1,
                'status' => 'active',
            ]))
            ->assertRedirect(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit"
                ."#course-template-lesson-{$lessonId}-activities"
            );

        $activity = DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('title', 'Reading Text')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, (int) $activity->created_by);
        $this->assertSame(300, (int) $activity->duration_seconds);

        $this->actingAs($admin)
            ->put(
                "{$collectionUrl}/{$activity->id}",
                $this->validActivityData([
                    'title' => 'Updated Reading Text',
                    'sort_order' => 4,
                    'activity_type' => 'text',
                    'duration_seconds' => 420,
                    'status' => 'inactive',
                ])
            )
            ->assertRedirect(
                "{$collectionUrl}/{$activity->id}/edit"
            );

        $this->assertDatabaseHas('core_course_template_activities', [
            'id' => $activity->id,
            'customer_id' => $customerId,
            'title' => 'Updated Reading Text',
            'sort_order' => 4,
            'duration_seconds' => 420,
            'status' => 'inactive',
        ]);

        $this->actingAs($admin)
            ->delete("{$collectionUrl}/{$activity->id}")
            ->assertRedirect(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit"
                ."#course-template-lesson-{$lessonId}-activities"
            );

        $this->assertDatabaseMissing('core_course_template_activities', [
            'id' => $activity->id,
        ]);
    }

    public function test_teacher_can_create_update_and_delete_an_activity(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        $teacher = $this->createUser($customerId, 'teacher');
        $collectionUrl = $this->activityCollectionUrl(
            'teacher',
            $templateId,
            $sectionId,
            $lessonId
        );

        $this->actingAs($teacher)
            ->post($collectionUrl, $this->validActivityData([
                'title' => 'Practice Link',
                'activity_type' => 'external_link',
                'external_url' => 'https://example.com/practice',
                'duration_seconds' => 180,
            ]))
            ->assertRedirect();

        $activity = DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('title', 'Practice Link')
            ->first();

        $this->assertNotNull($activity);

        $this->actingAs($teacher)
            ->put(
                "{$collectionUrl}/{$activity->id}",
                $this->validActivityData([
                    'title' => 'Updated Practice Link',
                    'activity_type' => 'external_link',
                    'external_url' => 'https://example.com/updated',
                    'duration_seconds' => 240,
                ])
            )
            ->assertRedirect("{$collectionUrl}/{$activity->id}/edit");

        $this->actingAs($teacher)
            ->delete("{$collectionUrl}/{$activity->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('core_course_template_activities', [
            'id' => $activity->id,
        ]);
    }

    public function test_activity_type_and_dependent_field_validation_are_enforced(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $url = $this->activityCollectionUrl(
            'admin',
            $templateId,
            $sectionId,
            $lessonId
        );

        $this->actingAs($admin)
            ->post($url, [
                'title' => '',
                'sort_order' => -1,
                'activity_type' => 'unsupported',
                'activity_ref_id' => 99,
                'is_required' => 'invalid',
                'completion_rule' => 'unsupported',
                'is_preview' => 'invalid',
                'unlock_rule' => 'unsupported',
                'status' => 'published',
            ])
            ->assertSessionHasErrors([
                'title',
                'sort_order',
                'activity_type',
                'activity_ref_type',
                'is_required',
                'completion_rule',
                'is_preview',
                'unlock_rule',
                'status',
            ]);

        $this->actingAs($admin)
            ->post($url, $this->validActivityData([
                'activity_type' => 'external_link',
                'external_url' => null,
            ]))
            ->assertSessionHasErrors('external_url');

        $this->actingAs($admin)
            ->post($url, $this->validActivityData([
                'activity_type' => 'video',
                'duration_seconds' => 120,
            ]))
            ->assertSessionHasErrors('duration_seconds');

        $this->actingAs($admin)
            ->post($url, $this->validActivityData([
                'completion_rule' => 'watch_percent',
                'completion_threshold' => null,
            ]))
            ->assertSessionHasErrors('completion_threshold');

        $this->assertDatabaseCount('core_course_template_activities', 0);
    }

    public function test_reference_fields_are_paired_and_documented_types_are_stored(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $url = $this->activityCollectionUrl(
            'admin',
            $templateId,
            $sectionId,
            $lessonId
        );

        $this->actingAs($admin)
            ->post($url, $this->validActivityData([
                'title' => 'Video Activity',
                'activity_type' => 'video',
                'activity_ref_type' => 'media_videos',
                'activity_ref_id' => 101,
                'duration_seconds' => null,
                'completion_rule' => 'watch_percent',
                'completion_threshold' => 80,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_template_activities', [
            'customer_id' => $customerId,
            'template_lesson_id' => $lessonId,
            'activity_type' => 'video',
            'activity_ref_type' => 'media_videos',
            'activity_ref_id' => 101,
            'duration_seconds' => 0,
            'completion_rule' => 'watch_percent',
            'completion_threshold' => 80,
        ]);
    }

    public function test_template_section_lesson_and_activity_access_are_tenant_isolated(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        [$otherCustomerId, $otherTemplateId, $otherSectionId, $otherLessonId] =
            $this->createHierarchy('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherActivityId = $this->createActivity(
            $otherCustomerId,
            $otherTemplateId,
            $otherLessonId,
            'Private Activity'
        );

        $this->actingAs($admin)
            ->post(
                $this->activityCollectionUrl(
                    'admin',
                    $otherTemplateId,
                    $otherSectionId,
                    $otherLessonId
                ),
                $this->validActivityData(['title' => 'Cross Tenant'])
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->post(
                $this->activityCollectionUrl(
                    'admin',
                    $templateId,
                    $otherSectionId,
                    $lessonId
                ),
                $this->validActivityData(['title' => 'Wrong Section'])
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(
                $this->activityCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId,
                    $lessonId
                )."/{$otherActivityId}/edit"
            )
            ->assertNotFound();

        $this->assertDatabaseMissing('core_course_template_activities', [
            'customer_id' => $customerId,
            'title' => 'Cross Tenant',
        ]);
    }

    public function test_unlock_prerequisite_must_belong_to_same_template_and_tenant(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        [$otherCustomerId, $otherTemplateId, , $otherLessonId] =
            $this->createHierarchy('tenant-b');
        $secondTemplateId = $this->createTemplate(
            $customerId,
            'Second Template',
            'second-template'
        );
        $secondSectionId = $this->createSection(
            $customerId,
            $secondTemplateId,
            'Second Section'
        );
        $secondLessonId = $this->createLesson(
            $customerId,
            $secondTemplateId,
            $secondSectionId,
            'Second Lesson'
        );
        $admin = $this->createUser($customerId, 'customer_admin');
        $wrongTenantActivityId = $this->createActivity(
            $otherCustomerId,
            $otherTemplateId,
            $otherLessonId,
            'Wrong Tenant Activity'
        );
        $wrongTemplateActivityId = $this->createActivity(
            $customerId,
            $secondTemplateId,
            $secondLessonId,
            'Wrong Template Activity'
        );
        $url = $this->activityCollectionUrl(
            'admin',
            $templateId,
            $sectionId,
            $lessonId
        );

        foreach (
            [$wrongTenantActivityId, $wrongTemplateActivityId] as $activityId
        ) {
            $this->actingAs($admin)
                ->post($url, $this->validActivityData([
                    'title' => 'Invalid Prerequisite '.$activityId,
                    'unlock_rule' => 'previous_activity_completed',
                    'unlock_after_activity_id' => $activityId,
                ]))
                ->assertSessionHasErrors('unlock_after_activity_id');
        }
    }

    public function test_unlock_prerequisite_cannot_be_self_or_create_a_cycle(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $firstId = $this->createActivity(
            $customerId,
            $templateId,
            $lessonId,
            'First Activity'
        );
        $secondId = $this->createActivity(
            $customerId,
            $templateId,
            $lessonId,
            'Second Activity',
            unlockAfterActivityId: $firstId
        );
        $url = $this->activityCollectionUrl(
            'admin',
            $templateId,
            $sectionId,
            $lessonId
        );

        foreach ([$firstId, $secondId] as $prerequisiteId) {
            $this->actingAs($admin)
                ->put("{$url}/{$firstId}", $this->validActivityData([
                    'title' => 'First Activity',
                    'unlock_rule' => 'previous_activity_completed',
                    'unlock_after_activity_id' => $prerequisiteId,
                ]))
                ->assertSessionHasErrors('unlock_after_activity_id');
        }

        $this->assertDatabaseHas('core_course_template_activities', [
            'id' => $firstId,
            'unlock_after_activity_id' => null,
        ]);
    }

    public function test_delete_is_blocked_when_activity_is_an_unlock_prerequisite(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $firstId = $this->createActivity(
            $customerId,
            $templateId,
            $lessonId,
            'Prerequisite Activity'
        );
        $this->createActivity(
            $customerId,
            $templateId,
            $lessonId,
            'Dependent Activity',
            unlockAfterActivityId: $firstId
        );

        $this->actingAs($admin)
            ->delete(
                $this->activityCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId,
                    $lessonId
                )."/{$firstId}"
            )
            ->assertSessionHasErrors('activity');

        $this->assertDatabaseHas('core_course_template_activities', [
            'id' => $firstId,
        ]);
    }

    public function test_activities_are_displayed_in_documented_sort_order(): void
    {
        [$customerId, $templateId, , $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->createActivity(
            $customerId,
            $templateId,
            $lessonId,
            'Second Activity',
            sortOrder: 2
        );
        $this->createActivity(
            $customerId,
            $templateId,
            $lessonId,
            'First Activity',
            sortOrder: 1
        );

        $this->actingAs($admin)
            ->get(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit"
            )
            ->assertOk()
            ->assertSeeInOrder(['First Activity', 'Second Activity']);
    }

    public function test_delete_confirmation_and_required_indicators_are_rendered(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->createActivity(
            $customerId,
            $templateId,
            $lessonId,
            'UI Activity'
        );

        $this->actingAs($admin)
            ->get(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit"
            )
            ->assertOk()
            ->assertSeeText('+ Thêm Activity')
            ->assertSeeText('Bạn có chắc chắn muốn xóa Activity này không?')
            ->assertSeeText('Có, xóa')
            ->assertSeeText('Không');

        $response = $this->actingAs($admin)
            ->get(
                $this->activityCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId,
                    $lessonId
                ).'/create'
            )
            ->assertOk();

        foreach ([
            'title',
            'sort_order',
            'activity_type',
            'is_required',
            'completion_rule',
            'is_preview',
            'unlock_rule',
            'status',
        ] as $field) {
            $this->assertSame(
                1,
                $this->requiredIndicatorCount($response->getContent(), $field)
            );
        }
    }

    public function test_guest_and_student_cannot_access_activity_management(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        $student = $this->createUser($customerId, 'student');
        $url = $this->activityCollectionUrl(
            'admin',
            $templateId,
            $sectionId,
            $lessonId
        ).'/create';

        $this->get($url)->assertRedirect('https://tenant-a.localhost/login');
        $this->actingAs($student)->get($url)->assertForbidden();
    }

    public function test_course_template_activity_module_has_no_eloquent_models(): void
    {
        $this->assertFileDoesNotExist(
            app_path('Models/CoreCourseTemplateActivity.php')
        );
        $this->assertFileDoesNotExist(
            app_path('Models/CourseTemplateActivity.php')
        );
    }

    private function createHierarchy(
        string $tenantSlug = 'tenant-a',
        string $suffix = 'default'
    ): array {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => $tenantSlug.'-'.$suffix,
            'slug' => $tenantSlug.'-'.$suffix,
            'subdomain' => $tenantSlug,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $templateId = $this->createTemplate(
            $customerId,
            'Template '.$suffix,
            'template-'.$suffix
        );
        $sectionId = $this->createSection(
            $customerId,
            $templateId,
            'Section '.$suffix
        );
        $lessonId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Lesson '.$suffix
        );

        return [$customerId, $templateId, $sectionId, $lessonId];
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
        string $slug
    ): int {
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
            'created_by' => null,
            'last_version_published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
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
            'code' => null,
            'title' => $title,
            'short_title' => null,
            'description' => null,
            'thumbnail_file_id' => null,
            'sort_order' => 1,
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

    private function createLesson(
        int $customerId,
        int $templateId,
        int $sectionId,
        string $title
    ): int {
        return DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_section_id' => $sectionId,
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)).'-'.uniqid(),
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
    }

    private function createActivity(
        int $customerId,
        int $templateId,
        int $lessonId,
        string $title,
        int $sortOrder = 0,
        ?int $unlockAfterActivityId = null
    ): int {
        return DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_lesson_id' => $lessonId,
            'title' => $title,
            'description' => null,
            'sort_order' => $sortOrder,
            'activity_type' => 'text',
            'activity_ref_type' => null,
            'activity_ref_id' => null,
            'external_url' => null,
            'embed_code' => null,
            'duration_seconds' => 0,
            'is_required' => true,
            'completion_rule' => 'view',
            'completion_threshold' => null,
            'is_preview' => false,
            'unlock_rule' => $unlockAfterActivityId === null
                ? 'none'
                : 'previous_activity_completed',
            'unlock_after_activity_id' => $unlockAfterActivityId,
            'unlock_at' => null,
            'status' => 'draft',
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validActivityData(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Introduction Activity',
            'description' => null,
            'sort_order' => 0,
            'activity_type' => 'text',
            'activity_ref_type' => null,
            'activity_ref_id' => null,
            'external_url' => null,
            'embed_code' => null,
            'duration_seconds' => 0,
            'is_required' => 1,
            'completion_rule' => 'view',
            'completion_threshold' => null,
            'is_preview' => 0,
            'unlock_rule' => 'none',
            'unlock_after_activity_id' => null,
            'unlock_at' => null,
            'status' => 'draft',
        ], $overrides);
    }

    private function activityCollectionUrl(
        string $area,
        int $templateId,
        int $sectionId,
        int $lessonId
    ): string {
        return "https://tenant-a.localhost/{$area}/course-templates/"
            ."{$templateId}/sections/{$sectionId}/lessons/"
            ."{$lessonId}/activities";
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
