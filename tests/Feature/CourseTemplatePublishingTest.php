<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CourseTemplatePublishingTest extends TestCase
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

    public function test_course_template_lifecycle_routes_are_registered_for_admin_only(): void
    {
        $this->assertTrue(Route::has('admin.course-templates.publish'));
        $this->assertTrue(Route::has('admin.course-templates.versions.show'));
        $this->assertTrue(Route::has('admin.course-templates.versions.duplicate-to-draft'));

        $this->assertFalse(Route::has('teacher.course-templates.publish'));
        $this->assertFalse(Route::has('teacher.course-templates.versions.show'));
        $this->assertFalse(Route::has('teacher.course-templates.versions.duplicate-to-draft'));
    }

    public function test_admin_publish_creates_a_complete_immutable_snapshot(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser(
            $customerId,
            'customer_admin',
            'Snapshot Admin'
        );
        $templateId = $this->createTemplate(
            $customerId,
            $admin->id,
            'Snapshot Course'
        );
        $sectionId = $this->createSection($customerId, $templateId);
        $directLessonId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Course Orientation',
            1,
            $admin->id
        );
        $sectionLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Hangul Fundamentals',
            1,
            $admin->id
        );
        $directActivityId = $this->createActivity(
            $customerId,
            $templateId,
            $directLessonId,
            'Welcome Video',
            1,
            $admin->id
        );
        $sectionActivityId = $this->createActivity(
            $customerId,
            $templateId,
            $sectionLessonId,
            'Hangul Practice',
            1,
            $admin->id
        );
        $draftBefore = $this->draftState($customerId, $templateId);

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=publish"
            )
            ->assertSessionHas(
                'success',
                'Đã xuất bản phiên bản 1.'
            );

        $version = DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->first();

        $this->assertNotNull($version);
        $this->assertSame(1, $version->version_number);
        $this->assertSame('published', $version->status);
        $this->assertSame(1, (int) $version->is_current);
        $this->assertSame($admin->id, $version->published_by);
        $this->assertSame('Snapshot Course', $version->title_snapshot);
        $this->assertSame(2, $version->lesson_count_snapshot);

        $versionSection = DB::table(
            'core_course_template_version_sections'
        )
            ->where('customer_id', $customerId)
            ->where('template_version_id', $version->id)
            ->where('source_template_section_id', $sectionId)
            ->first();

        $this->assertNotNull($versionSection);
        $this->assertSame(1, $versionSection->sort_order);
        $this->assertSame('Hangul', $versionSection->title_snapshot);

        $directVersionLesson = DB::table(
            'core_course_template_version_lessons'
        )
            ->where('customer_id', $customerId)
            ->where('template_version_id', $version->id)
            ->where('source_template_lesson_id', $directLessonId)
            ->first();
        $sectionVersionLesson = DB::table(
            'core_course_template_version_lessons'
        )
            ->where('customer_id', $customerId)
            ->where('template_version_id', $version->id)
            ->where('source_template_lesson_id', $sectionLessonId)
            ->first();

        $this->assertNotNull($directVersionLesson);
        $this->assertNull($directVersionLesson->version_section_id);
        $this->assertSame('Course Orientation', $directVersionLesson->title_snapshot);
        $this->assertNotNull($sectionVersionLesson);
        $this->assertSame(
            $versionSection->id,
            $sectionVersionLesson->version_section_id
        );
        $this->assertSame('Hangul Fundamentals', $sectionVersionLesson->title_snapshot);

        $directVersionActivity = DB::table(
            'core_course_template_version_activities'
        )
            ->where('customer_id', $customerId)
            ->where('template_version_id', $version->id)
            ->where('source_template_activity_id', $directActivityId)
            ->first();
        $sectionVersionActivity = DB::table(
            'core_course_template_version_activities'
        )
            ->where('customer_id', $customerId)
            ->where('template_version_id', $version->id)
            ->where('source_template_activity_id', $sectionActivityId)
            ->first();

        $this->assertNotNull($directVersionActivity);
        $this->assertSame(
            $directVersionLesson->id,
            $directVersionActivity->version_lesson_id
        );
        $this->assertSame('Welcome Video', $directVersionActivity->title_snapshot);
        $this->assertNotNull($sectionVersionActivity);
        $this->assertSame(
            $sectionVersionLesson->id,
            $sectionVersionActivity->version_lesson_id
        );
        $this->assertSame('Hangul Practice', $sectionVersionActivity->title_snapshot);

        $this->assertSame(
            $draftBefore,
            $this->draftState($customerId, $templateId)
        );
    }

    public function test_each_publish_increments_version_and_moves_current_marker(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser(
            $customerId,
            'customer_admin',
            'Version Admin'
        );
        $templateId = $this->createTemplate(
            $customerId,
            $admin->id,
            'Versioned Course'
        );

        $url = "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish";

        $this->actingAs($admin)->post($url)->assertRedirect();
        $this->actingAs($admin)->post($url)->assertRedirect();

        $versions = DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->orderBy('version_number')
            ->get();

        $this->assertSame([1, 2], $versions->pluck('version_number')->all());
        $this->assertSame(0, (int) $versions[0]->is_current);
        $this->assertSame(1, (int) $versions[1]->is_current);
        $this->assertSame(
            1,
            DB::table('core_course_template_versions')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('is_current', true)
                ->count()
        );

        $this->actingAs($admin)
            ->get(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=history"
            )
            ->assertOk()
            ->assertSeeText('Phiên bản 2')
            ->assertSeeText('Phiên bản 1')
            ->assertSeeText('Version Admin')
            ->assertSeeText('Đã xuất bản')
            ->assertSeeText('Hiện tại');
    }

    public function test_non_admin_users_cannot_publish(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $templateId = $this->createTemplate(
            $customerId,
            $admin->id,
            'Protected Course'
        );

        $this->actingAs($teacher)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
            )
            ->assertForbidden();

        $this->actingAs($student)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
            )
            ->assertForbidden();

        auth()->logout();

        $this->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
        )->assertRedirect();

        $this->assertDatabaseCount('core_course_template_versions', 0);
    }

    public function test_publish_is_tenant_isolated(): void
    {
        $customerA = $this->createTenant();
        $customerB = $this->createTenant('tenant-b');
        $adminA = $this->createUser($customerA, 'customer_admin');
        $adminB = $this->createUser($customerB, 'customer_admin');
        $templateB = $this->createTemplate(
            $customerB,
            $adminB->id,
            'Tenant B Course'
        );

        $this->actingAs($adminA)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateB}/publish"
            )
            ->assertNotFound();

        $this->assertDatabaseCount('core_course_template_versions', 0);
    }

    public function test_admin_can_view_readonly_version_detail_with_snapshot_content(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser(
            $customerId,
            'customer_admin',
            'Detail Admin'
        );
        $templateId = $this->createTemplate(
            $customerId,
            $admin->id,
            'Readonly Snapshot'
        );
        $sectionId = $this->createSection($customerId, $templateId);
        $directLessonId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Direct Snapshot Lesson',
            1,
            $admin->id
        );
        $sectionLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Section Snapshot Lesson',
            1,
            $admin->id
        );
        $this->createActivity(
            $customerId,
            $templateId,
            $directLessonId,
            'Direct Snapshot Activity',
            1,
            $admin->id
        );
        $this->createActivity(
            $customerId,
            $templateId,
            $sectionLessonId,
            'Section Snapshot Activity',
            1,
            $admin->id
        );

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
        );

        $versionId = (int) DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->value('id');

        $this->actingAs($admin)
            ->get(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/versions/{$versionId}"
            )
            ->assertOk()
            ->assertSeeText('Chi tiết phiên bản đã xuất bản')
            ->assertSeeText('Phiên bản 1')
            ->assertSeeText('Hiện tại')
            ->assertSeeText('Readonly Snapshot')
            ->assertSeeText('Bài học trực tiếp')
            ->assertSeeText('Direct Snapshot Lesson')
            ->assertSeeText('Direct Snapshot Activity')
            ->assertSeeText('Hangul')
            ->assertSeeText('Section Snapshot Lesson')
            ->assertSeeText('Section Snapshot Activity')
            ->assertSeeText('Quay lại lịch sử khóa học')
            ->assertSeeText('Sao chép vào bản nháp')
            ->assertDontSeeText('Lưu thay đổi')
            ->assertDontSeeText('Xóa');

        $this->actingAs($admin)
            ->get(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=history"
            )
            ->assertOk()
            ->assertSee(
                "/admin/course-templates/{$templateId}/versions/{$versionId}",
                false
            );
    }

    public function test_non_admin_users_cannot_view_version_detail(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $templateId = $this->createTemplate(
            $customerId,
            $admin->id,
            'Protected Detail'
        );

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
        );
        $versionId = (int) DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->value('id');
        $url = "https://tenant-a.localhost/admin/course-templates/{$templateId}/versions/{$versionId}";

        $this->actingAs($teacher)->get($url)->assertForbidden();
        $this->actingAs($student)->get($url)->assertForbidden();

        auth()->logout();
        $this->get($url)->assertRedirect();
    }

    public function test_version_detail_is_tenant_isolated(): void
    {
        $customerA = $this->createTenant();
        $customerB = $this->createTenant('tenant-b');
        $adminA = $this->createUser($customerA, 'customer_admin');
        $adminB = $this->createUser($customerB, 'customer_admin');
        $templateB = $this->createTemplate(
            $customerB,
            $adminB->id,
            'Tenant B Detail'
        );

        $this->actingAs($adminB)->post(
            "https://tenant-b.localhost/admin/course-templates/{$templateB}/publish"
        );
        $versionB = (int) DB::table('core_course_template_versions')
            ->where('customer_id', $customerB)
            ->where('template_id', $templateB)
            ->value('id');

        $this->actingAs($adminA)
            ->get(
                "https://tenant-a.localhost/admin/course-templates/{$templateB}/versions/{$versionB}"
            )
            ->assertNotFound();
    }

    public function test_missing_version_detail_returns_not_found(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            $admin->id,
            'Missing Version'
        );

        $this->actingAs($admin)
            ->get(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/versions/999999"
            )
            ->assertNotFound();
    }

    public function test_admin_can_duplicate_version_to_the_single_existing_draft(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser(
            $customerId,
            'customer_admin',
            'Duplicate Admin'
        );
        $templateId = $this->createTemplate(
            $customerId,
            $admin->id,
            'Restorable Course'
        );
        $categoryId = $this->createCategory(
            $customerId,
            $admin->id,
            'Historic Category'
        );

        DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->update([
                'category_id' => $categoryId,
                'thumbnail_video_media_id' => 999999,
            ]);

        $sectionId = $this->createSection($customerId, $templateId);
        $directFirstId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Direct First',
            1,
            $admin->id
        );
        $directLaterId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Direct Later',
            2,
            $admin->id
        );
        $sectionLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Section Lesson',
            1,
            $admin->id
        );
        $activityFirstId = $this->createActivity(
            $customerId,
            $templateId,
            $directFirstId,
            'Activity First',
            1,
            $admin->id
        );
        $activityLaterId = $this->createActivity(
            $customerId,
            $templateId,
            $directFirstId,
            'Activity Later',
            2,
            $admin->id
        );
        $this->createActivity(
            $customerId,
            $templateId,
            $sectionLessonId,
            'Section Activity',
            1,
            $admin->id
        );

        DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('id', $directLaterId)
            ->update([
                'unlock_rule' => 'previous_lesson_completed',
                'unlock_after_lesson_id' => $directFirstId,
            ]);
        DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('id', $activityLaterId)
            ->update([
                'unlock_rule' => 'previous_activity_completed',
                'unlock_after_activity_id' => $activityFirstId,
            ]);

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
        )->assertRedirect();

        $version = DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->first();
        $this->assertNotNull($version);

        $versionState = $this->versionState(
            $customerId,
            $templateId,
            $version->id
        );
        $lastPublishedAt = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->value('last_version_published_at');

        DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->update([
                'category_id' => null,
                'title' => 'Changed Working Draft',
                'status' => 'active',
                'working_revision' => 11,
            ]);
        DB::table('core_course_categories')
            ->where('customer_id', $customerId)
            ->where('id', $categoryId)
            ->delete();

        $this->clearDraftContent($customerId, $templateId);
        $staleSectionId = $this->createSection($customerId, $templateId);
        DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('id', $staleSectionId)
            ->update([
                'code' => 'STALE',
                'title' => 'Stale Section',
            ]);
        $staleLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $staleSectionId,
            'Stale Lesson',
            1,
            $admin->id
        );
        $this->createActivity(
            $customerId,
            $templateId,
            $staleLessonId,
            'Stale Activity',
            1,
            $admin->id
        );

        $duplicateUrl = "https://tenant-a.localhost/admin/course-templates/{$templateId}/versions/{$version->id}/duplicate-to-draft";

        $this->withSession(['locale' => 'en'])
            ->actingAs($admin)
            ->get(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=history"
            )
            ->assertOk()
            ->assertSeeText('Duplicate to Draft')
            ->assertSee(
                'This will replace the current draft content with this published version. Published versions will not be changed. Continue?',
                false
            );

        $this->actingAs($admin)
            ->post($duplicateUrl)
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure"
            )
            ->assertSessionHas(
                'success',
                'Published version duplicated to draft successfully.'
            );

        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->first();

        $this->assertNotNull($template);
        $this->assertSame('Restorable Course', $template->title);
        $this->assertSame('draft', $template->status);
        $this->assertSame(12, $template->working_revision);
        $this->assertNull($template->category_id);
        $this->assertNull($template->thumbnail_video_media_id);
        $this->assertEquals(
            $lastPublishedAt,
            $template->last_version_published_at
        );

        $this->assertDatabaseMissing('core_course_template_sections', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'title' => 'Stale Section',
        ]);
        $this->assertDatabaseMissing('core_course_template_lessons', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'title' => 'Stale Lesson',
        ]);
        $this->assertDatabaseMissing('core_course_template_activities', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'title' => 'Stale Activity',
        ]);

        $directLessons = DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->whereNull('template_section_id')
            ->orderBy('sort_order')
            ->get();
        $this->assertSame(
            ['Direct First', 'Direct Later'],
            $directLessons->pluck('title')->all()
        );
        $this->assertSame(
            $directLessons[0]->id,
            $directLessons[1]->unlock_after_lesson_id
        );

        $section = DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('title', 'Hangul')
            ->first();
        $this->assertNotNull($section);
        $this->assertDatabaseHas('core_course_template_lessons', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_section_id' => $section->id,
            'title' => 'Section Lesson',
            'sort_order' => 1,
        ]);

        $restoredDirectFirst = $directLessons->first();
        $directActivities = DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('template_lesson_id', $restoredDirectFirst->id)
            ->orderBy('sort_order')
            ->get();
        $this->assertSame(
            ['Activity First', 'Activity Later'],
            $directActivities->pluck('title')->all()
        );
        $this->assertSame(
            $directActivities[0]->id,
            $directActivities[1]->unlock_after_activity_id
        );
        $this->assertDatabaseHas('core_course_template_activities', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'title' => 'Section Activity',
            'sort_order' => 1,
        ]);

        $this->assertSame(
            $versionState,
            $this->versionState(
                $customerId,
                $templateId,
                $version->id
            )
        );
        $this->assertSame(
            1,
            DB::table('core_course_template_versions')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->count()
        );
        $this->assertSame(
            $version->id,
            DB::table('core_course_template_versions')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('is_current', true)
                ->value('id')
        );
        $this->assertSame(
            1,
            DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $templateId)
                ->count()
        );

        $audit = DB::table('saas_audit_logs')
            ->where('customer_id', $customerId)
            ->where(
                'action',
                'course_template_version_duplicated_to_draft'
            )
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(
            $version->id,
            json_decode($audit->after, true)[
                'source_template_version_id'
            ]
        );
    }

    public function test_non_admin_users_cannot_duplicate_version_to_draft(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $templateId = $this->createTemplate(
            $customerId,
            $admin->id,
            'Protected Duplicate'
        );

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
        );
        $versionId = (int) DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->value('id');
        $url = "https://tenant-a.localhost/admin/course-templates/{$templateId}/versions/{$versionId}/duplicate-to-draft";
        $draftBefore = $this->draftState($customerId, $templateId);

        $this->actingAs($teacher)->post($url)->assertForbidden();
        $this->actingAs($student)->post($url)->assertForbidden();

        auth()->logout();
        $this->post($url)->assertRedirect();

        $this->assertSame(
            $draftBefore,
            $this->draftState($customerId, $templateId)
        );
        $this->assertDatabaseMissing('saas_audit_logs', [
            'customer_id' => $customerId,
            'action' => 'course_template_version_duplicated_to_draft',
        ]);
    }

    public function test_duplicate_version_to_draft_is_tenant_isolated(): void
    {
        $customerA = $this->createTenant();
        $customerB = $this->createTenant('tenant-b');
        $adminA = $this->createUser($customerA, 'customer_admin');
        $adminB = $this->createUser($customerB, 'customer_admin');
        $templateB = $this->createTemplate(
            $customerB,
            $adminB->id,
            'Tenant B Duplicate'
        );

        $this->actingAs($adminB)->post(
            "https://tenant-b.localhost/admin/course-templates/{$templateB}/publish"
        );
        $versionB = (int) DB::table('core_course_template_versions')
            ->where('customer_id', $customerB)
            ->where('template_id', $templateB)
            ->value('id');
        $draftBefore = $this->draftState($customerB, $templateB);

        $this->actingAs($adminA)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateB}/versions/{$versionB}/duplicate-to-draft"
            )
            ->assertNotFound();

        $this->assertSame(
            $draftBefore,
            $this->draftState($customerB, $templateB)
        );
        $this->assertDatabaseMissing('saas_audit_logs', [
            'customer_id' => $customerA,
            'action' => 'course_template_version_duplicated_to_draft',
        ]);
    }

    public function test_duplicate_validation_failure_keeps_current_draft_intact(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            $admin->id,
            'Slug Source'
        );
        $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Original Lesson',
            1,
            $admin->id
        );

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
        );
        $versionId = (int) DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->value('id');

        DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->update([
                'title' => 'Current Working Draft',
                'slug' => 'current-working-draft',
                'working_revision' => 9,
            ]);
        $this->createTemplate(
            $customerId,
            $admin->id,
            'Slug Source'
        );
        $draftBefore = $this->draftState($customerId, $templateId);

        $this->actingAs($admin)
            ->from(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=history"
            )
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/versions/{$versionId}/duplicate-to-draft"
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=history"
            )
            ->assertSessionHasErrors('duplicate');

        $this->assertSame(
            $draftBefore,
            $this->draftState($customerId, $templateId)
        );
        $this->assertDatabaseMissing('saas_audit_logs', [
            'customer_id' => $customerId,
            'action' => 'course_template_version_duplicated_to_draft',
        ]);
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

    private function createCategory(
        int $customerId,
        int $createdBy,
        string $name
    ): int {
        return DB::table('core_course_categories')->insertGetId([
            'customer_id' => $customerId,
            'parent_id' => null,
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'description' => null,
            'thumbnail_image' => null,
            'banner_image' => null,
            'sort_order' => 1,
            'is_featured' => false,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'status' => 'active',
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(
        int $customerId,
        string $role,
        ?string $name = null
    ): User {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => $name ?? ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function createTemplate(
        int $customerId,
        int $createdBy,
        string $title
    ): int {
        $now = now();

        return DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId,
            'category_id' => null,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'short_description' => 'Published snapshot course',
            'description' => 'Detailed snapshot description.',
            'publisher_name' => 'LearnForge',
            'thumbnail_type' => 'image',
            'thumbnail_image' => '/course.jpg',
            'thumbnail_video_source' => null,
            'thumbnail_video_url' => null,
            'thumbnail_video_media_id' => null,
            'difficulty_level' => 'beginner',
            'language' => 'vi',
            'estimated_duration_minutes' => 90,
            'max_lessons' => 20,
            'lesson_count' => 2,
            'meta_title' => 'Snapshot Course',
            'meta_description' => 'Snapshot course metadata.',
            'meta_keywords' => 'snapshot,course',
            'working_revision' => 3,
            'status' => 'draft',
            'created_by' => $createdBy,
            'last_version_published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createSection(int $customerId, int $templateId): int
    {
        return DB::table('core_course_template_sections')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'parent_section_id' => null,
            'code' => 'M01',
            'title' => 'Hangul',
            'short_title' => 'Hangul',
            'description' => 'Hangul section.',
            'thumbnail_file_id' => null,
            'sort_order' => 1,
            'is_required' => true,
            'unlock_rule' => 'immediate',
            'estimated_duration_minutes' => 60,
            'total_lessons' => 1,
            'status' => 'active',
            'metadata' => json_encode(['color' => '#0EA5E9']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLesson(
        int $customerId,
        int $templateId,
        ?int $sectionId,
        string $title,
        int $sortOrder,
        int $createdBy
    ): int {
        return DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_section_id' => $sectionId,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'short_description' => 'Lesson summary.',
            'description' => 'Lesson description.',
            'sort_order' => $sortOrder,
            'is_preview' => $sectionId === null,
            'learning_objective' => 'Complete the lesson.',
            'duration_seconds' => 600,
            'activity_count' => 1,
            'unlock_rule' => 'none',
            'unlock_after_lesson_id' => null,
            'unlock_at' => null,
            'status' => 'active',
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createActivity(
        int $customerId,
        int $templateId,
        int $lessonId,
        string $title,
        int $sortOrder,
        int $createdBy
    ): int {
        return DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_lesson_id' => $lessonId,
            'title' => $title,
            'description' => 'Activity description.',
            'sort_order' => $sortOrder,
            'activity_type' => 'text',
            'activity_ref_type' => null,
            'activity_ref_id' => null,
            'external_url' => null,
            'embed_code' => '<p>Published content</p>',
            'duration_seconds' => 600,
            'is_required' => true,
            'completion_rule' => 'view',
            'completion_threshold' => null,
            'is_preview' => false,
            'unlock_rule' => 'none',
            'unlock_after_activity_id' => null,
            'unlock_at' => null,
            'status' => 'active',
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function draftState(int $customerId, int $templateId): array
    {
        return [
            'template' => (array) DB::table('core_course_templates')
                ->where('customer_id', $customerId)
                ->where('id', $templateId)
                ->first(),
            'sections' => DB::table('core_course_template_sections')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'lessons' => DB::table('core_course_template_lessons')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'activities' => DB::table('core_course_template_activities')
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ];
    }

    private function versionState(
        int $customerId,
        int $templateId,
        int $versionId
    ): array {
        return [
            'version' => (array) DB::table(
                'core_course_template_versions'
            )
                ->where('customer_id', $customerId)
                ->where('template_id', $templateId)
                ->where('id', $versionId)
                ->first(),
            'sections' => DB::table(
                'core_course_template_version_sections'
            )
                ->where('customer_id', $customerId)
                ->where('template_version_id', $versionId)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'lessons' => DB::table(
                'core_course_template_version_lessons'
            )
                ->where('customer_id', $customerId)
                ->where('template_version_id', $versionId)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'activities' => DB::table(
                'core_course_template_version_activities'
            )
                ->where('customer_id', $customerId)
                ->where('template_version_id', $versionId)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ];
    }

    private function clearDraftContent(
        int $customerId,
        int $templateId
    ): void {
        DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->update(['unlock_after_activity_id' => null]);
        DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->delete();

        DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->update(['unlock_after_lesson_id' => null]);
        DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->delete();

        DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->delete();
    }
}
