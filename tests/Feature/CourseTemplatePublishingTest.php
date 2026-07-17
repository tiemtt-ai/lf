<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

        Carbon::setTestNow('2026-07-04 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_course_template_lifecycle_routes_are_registered_for_admin_only(): void
    {
        $this->assertTrue(Route::has('admin.course-templates.publish'));
        $this->assertTrue(Route::has('admin.course-templates.archive'));
        $this->assertTrue(Route::has('admin.course-templates.versions.show'));
        $this->assertTrue(Route::has('admin.course-templates.versions.duplicate-to-draft'));

        $this->assertFalse(Route::has('teacher.course-templates.publish'));
        $this->assertFalse(Route::has('teacher.course-templates.archive'));
        $this->assertFalse(Route::has('teacher.course-templates.versions.show'));
        $this->assertFalse(Route::has('teacher.course-templates.versions.duplicate-to-draft'));
    }

    public function test_version_content_tab_labels_and_empty_states_are_localized(): void
    {
        app()->setLocale('en');
        $this->assertSame('Direct Lessons', __('lf.LF_course_template_structure_tab_direct'));
        $this->assertSame('By Sections', __('lf.LF_course_template_structure_tab_sections'));
        $this->assertSame('No direct lessons.', __('lf.LF_version_detail_no_direct_lessons'));
        $this->assertSame('No sections.', __('lf.LF_version_detail_no_sections'));

        app()->setLocale('vi');
        $this->assertSame('Bài học trực tiếp', __('lf.LF_course_template_structure_tab_direct'));
        $this->assertSame('Theo phần học', __('lf.LF_course_template_structure_tab_sections'));
        $this->assertSame('Chưa có bài học trực tiếp.', __('lf.LF_version_detail_no_direct_lessons'));
        $this->assertSame('Chưa có phần học.', __('lf.LF_version_detail_no_sections'));
    }

    public function test_only_active_working_template_status_can_publish(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin', 'Status Admin');

        foreach (['draft', 'active', 'inactive', 'archived'] as $status) {
            $templateId = $this->createTemplate(
                $customerId,
                $admin->id,
                ucfirst($status).' Status Course'
            );
            DB::table('core_course_templates')
                ->where('id', $templateId)
                ->update(['status' => $status]);
            $lessonId = $this->createLesson(
                $customerId,
                $templateId,
                null,
                ucfirst($status).' Lesson',
                0,
                $admin->id
            );
            $this->createActivity(
                $customerId,
                $templateId,
                $lessonId,
                ucfirst($status).' Activity',
                0,
                $admin->id
            );

            $editUrl = "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=publish";
            $publishUrl = "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish";
            $readiness = $this->actingAs($admin)->get($editUrl)->assertOk();

            if ($status === 'active') {
                $readiness
                    ->assertDontSee('data-readiness-code="template_status"', false)
                    ->assertDontSee('course-template-publish-button" disabled', false);
                $this->actingAs($admin)
                    ->from($editUrl)
                    ->post($publishUrl)
                    ->assertRedirect($editUrl)
                    ->assertSessionDoesntHaveErrors();
                $this->assertDatabaseHas('core_course_template_versions', [
                    'template_id' => $templateId,
                    'status' => 'published',
                ]);
            } else {
                $statusLabel = __('lf.LF_course_template_common_'.$status);
                $blocker = "Chỉ Course Template ở trạng thái Hoạt động mới có thể xuất bản. Trạng thái hiện tại là {$statusLabel}. Hãy đổi trạng thái trong tab Thông tin trước khi xuất bản.";
                $readiness
                    ->assertSee('data-readiness-code="template_status"', false)
                    ->assertSeeText($blocker);
                $this->actingAs($admin)
                    ->from($editUrl)
                    ->post($publishUrl)
                    ->assertRedirect($editUrl)
                    ->assertSessionHasErrors([
                        'publish' => $blocker,
                    ]);
                $this->assertDatabaseMissing('core_course_template_versions', [
                    'template_id' => $templateId,
                ]);
            }

            $this->assertDatabaseHas('core_course_templates', [
                'id' => $templateId,
                'status' => $status,
            ]);
        }
    }

    public function test_template_status_publish_blocker_is_localized(): void
    {
        app()->setLocale('en');
        $this->assertSame(
            'Only an Active Course Template can be published. The current status is Draft. Change it in the Information tab before publishing.',
            __('lf.LF_course_template_publish_integrity_template_status', ['status' => 'Draft'])
        );

        app()->setLocale('vi');
        $this->assertSame(
            'Chỉ Course Template ở trạng thái Hoạt động mới có thể xuất bản. Trạng thái hiện tại là Bản nháp. Hãy đổi trạng thái trong tab Thông tin trước khi xuất bản.',
            __('lf.LF_course_template_publish_integrity_template_status', ['status' => 'Bản nháp'])
        );
    }

    public function test_publish_rechecks_locked_status_after_readiness_render_and_ignores_forged_status(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin', 'Locked Status Admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Locked Status Course');
        $lessonId = $this->createLesson($customerId, $templateId, null, 'Locked Status Lesson', 0, $admin->id);
        $this->createActivity($customerId, $templateId, $lessonId, 'Locked Status Activity', 0, $admin->id);
        $editUrl = "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=publish";

        $this->actingAs($admin)->get($editUrl)
            ->assertOk()
            ->assertSeeText('Sẵn sàng xuất bản');

        DB::table('core_course_templates')->where('id', $templateId)->update([
            'status' => 'inactive',
        ]);

        $this->actingAs($admin)
            ->from($editUrl)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish",
                ['status' => 'active']
            )
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors('publish');

        $this->assertDatabaseMissing('core_course_template_versions', [
            'template_id' => $templateId,
        ]);
        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'status' => 'inactive',
        ]);
    }

    public function test_admin_archives_only_inactive_template_without_changing_published_version(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin', 'Archive Admin');
        $teacher = $this->createUser($customerId, 'teacher', 'Archive Teacher');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin', 'Other Archive Admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Archive Course');
        $lessonId = $this->createLesson($customerId, $templateId, null, 'Archive Lesson', 0, $admin->id);
        $this->createActivity($customerId, $templateId, $lessonId, 'Archive Activity', 0, $admin->id);
        $archiveUrl = "https://tenant-a.localhost/admin/course-templates/{$templateId}/archive";

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
        )->assertRedirect();
        $versionBefore = DB::table('core_course_template_versions')
            ->where('template_id', $templateId)
            ->sole();

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->post($archiveUrl)
            ->assertSessionHasErrors('status');
        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'status' => 'active',
        ]);

        DB::table('core_course_templates')->where('id', $templateId)->update([
            'status' => 'inactive',
        ]);
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()
            ->assertSeeText('Lưu trữ Template')
            ->assertSee('window.confirm', false)
            ->assertSee(':aria-busy="submitting"', false);

        $this->actingAs($teacher)->post($archiveUrl)->assertForbidden();

        $this->actingAs($admin)
            ->post($archiveUrl)
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates');
        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'status' => 'archived',
        ]);
        $this->assertEquals(
            $versionBefore,
            DB::table('core_course_template_versions')->where('id', $versionBefore->id)->sole()
        );
        $audit = DB::table('saas_audit_logs')
            ->where('customer_id', $customerId)
            ->where('action', 'course_template_archive')
            ->sole();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(
            ['template_id' => $templateId, 'status' => 'inactive'],
            json_decode($audit->before, true)
        );
        $this->assertSame(
            ['template_id' => $templateId, 'status' => 'archived'],
            json_decode($audit->after, true)
        );

        $archivedEdit = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()
            ->assertSeeText('Ngừng sử dụng')
            ->assertDontSee('name="status"', false)
            ->assertDontSeeText('Lưu thay đổi');
        $this->assertStringNotContainsString('/archive', $archivedEdit->getContent());

        $otherTemplateId = $this->createTemplate(
            $otherCustomerId,
            $otherAdmin->id,
            'Other Tenant Archive Course'
        );
        DB::table('core_course_templates')->where('id', $otherTemplateId)->update([
            'status' => 'inactive',
        ]);
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$otherTemplateId}/archive"
        )->assertNotFound();
        $this->assertDatabaseHas('core_course_templates', [
            'id' => $otherTemplateId,
            'customer_id' => $otherCustomerId,
            'status' => 'inactive',
        ]);
    }

    public function test_version_content_reuses_authoring_visual_classes_without_parallel_tab_styles(): void
    {
        $authoringStructure = file_get_contents(resource_path(
            'views/course-template-sections/partials/list.blade.php'
        ));
        $authoringSections = file_get_contents(resource_path(
            'views/course-template-sections/partials/section-node.blade.php'
        ));
        $authoringLessons = file_get_contents(resource_path(
            'views/course-template-lessons/partials/list.blade.php'
        ));
        $version = file_get_contents(resource_path(
            'views/course-template-versions/show.blade.php'
        ));
        $versionSections = file_get_contents(resource_path(
            'views/course-template-versions/partials/section-node.blade.php'
        ));
        $versionLessons = file_get_contents(resource_path(
            'views/course-template-versions/partials/lesson.blade.php'
        ));
        foreach ([
            'course-template-section-card',
            'course-template-section-header',
            'course-template-structure-tabs',
            'course-template-structure-panel',
            'course-template-section-action-bar',
            'course-template-outline-sections',
        ] as $class) {
            $this->assertStringContainsString($class, $authoringStructure);
            $this->assertStringContainsString($class, $version);
        }

        foreach ([
            'course-template-outline-section',
            'course-template-outline-section-header',
            'course-template-outline-section-title',
            'course-template-outline-children',
        ] as $class) {
            $this->assertStringContainsString($class, $authoringSections);
            $this->assertStringContainsString($class, $versionSections);
        }

        foreach ([
            'course-template-lesson-panel',
            'course-template-lesson-list',
            'course-template-lesson-item',
            'course-template-lesson-summary',
            'course-template-activity-panel',
            'course-template-activity-list',
            'course-template-activity-item',
            'course-template-activity-identity',
        ] as $class) {
            $this->assertStringContainsString($class, $authoringLessons);
            $this->assertStringContainsString($class, $version.$versionLessons);
        }

        foreach ([
            'course-version-role-badge',
            'course-version-lesson-title-row',
            'course-version-lesson-meta',
            'course-version-activity-meta',
            'LF_version_detail_required_short',
            'LF_course_template_version_detail_preview',
            'unlock_rule_snapshot',
        ] as $versionOnlyPresentation) {
            $this->assertStringNotContainsString(
                $versionOnlyPresentation,
                $versionLessons
            );
        }
        $this->assertStringContainsString('short_description_snapshot', $versionLessons);
        $this->assertStringContainsString('description_snapshot', $versionLessons);
        $this->assertStringContainsString('course-template-activity-icon', $versionLessons);
        $this->assertStringContainsString('title_snapshot', $versionLessons);

        $css = file_get_contents(resource_path('css/admin/admin-pages.css'));
        $this->assertStringNotContainsString('.course-version-structure-tabs', $css);
        $this->assertStringNotContainsString('.course-version-structure-tab', $css);
        $this->assertStringNotContainsString('.course-version-outline-section-header', $css);
        $this->assertStringNotContainsString('.course-version-lesson-item', $css);
        $this->assertFileDoesNotExist(resource_path(
            'views/components/course-structure-tabs.blade.php'
        ));
        $authoringTabs = (string) str($authoringStructure)->between(
            '<div class="course-template-structure-tabs"',
            '<div id="course-template-direct-panel"'
        );
        $versionTabs = (string) str($version)->between(
            '<div class="course-template-structure-tabs"',
            '<div id="course-version-direct-panel"'
        );
        $normalizeTabs = static fn (string $html): string => str_replace(
            ['course-template', 'course-version', 'activeStructureTab', 'activeContentTab', 'selectStructureTab', 'selectContentTab'],
            ['course-context', 'course-context', 'activeTab', 'activeTab', 'selectTab', 'selectTab'],
            preg_replace('/\s+/', ' ', trim($html))
        );
        $this->assertSame(
            $normalizeTabs($authoringTabs),
            $normalizeTabs($versionTabs)
        );
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
        $childSectionId = $this->createSection(
            $customerId,
            $templateId,
            'Batchim',
            1,
            $sectionId
        );
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
        $nestedSectionLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $childSectionId,
            'Batchim Practice',
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
        $nestedSectionActivityId = $this->createActivity(
            $customerId,
            $templateId,
            $nestedSectionLessonId,
            'Batchim Drill',
            1,
            $admin->id
        );
        DB::table('core_course_template_lessons')
            ->where('id', $directLessonId)
            ->update(['lesson_type' => 'review']);
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
        $this->assertSame(3, $version->lesson_count_snapshot);

        $versionSection = DB::table(
            'core_course_template_version_sections'
        )
            ->where('customer_id', $customerId)
            ->where('template_version_id', $version->id)
            ->where('source_template_section_id', $sectionId)
            ->first();

        $this->assertNotNull($versionSection);
        $this->assertSame(1, $versionSection->display_order);
        $this->assertSame(1, (int) $versionSection->allows_lessons);
        $this->assertSame('Hangul', $versionSection->title_snapshot);

        $childVersionSection = DB::table(
            'core_course_template_version_sections'
        )
            ->where('customer_id', $customerId)
            ->where('template_version_id', $version->id)
            ->where('source_template_section_id', $childSectionId)
            ->first();

        $this->assertNotNull($childVersionSection);
        $this->assertSame(
            $versionSection->id,
            $childVersionSection->parent_version_section_id
        );
        $this->assertSame('Batchim', $childVersionSection->title_snapshot);

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
        $nestedSectionVersionLesson = DB::table(
            'core_course_template_version_lessons'
        )
            ->where('customer_id', $customerId)
            ->where('template_version_id', $version->id)
            ->where('source_template_lesson_id', $nestedSectionLessonId)
            ->first();

        $this->assertNotNull($directVersionLesson);
        $this->assertNull($directVersionLesson->version_section_id);
        $this->assertSame('Course Orientation', $directVersionLesson->title_snapshot);
        $this->assertSame('review', $directVersionLesson->lesson_type);
        $this->assertNotNull($sectionVersionLesson);
        $this->assertSame(
            $versionSection->id,
            $sectionVersionLesson->version_section_id
        );
        $this->assertSame('Hangul Fundamentals', $sectionVersionLesson->title_snapshot);
        $this->assertNotNull($nestedSectionVersionLesson);
        $this->assertSame(
            $childVersionSection->id,
            $nestedSectionVersionLesson->version_section_id
        );
        $this->assertSame('Batchim Practice', $nestedSectionVersionLesson->title_snapshot);

        $directVersionActivity = DB::table(
            'core_course_template_version_activities'
        )
            ->where('customer_id', $customerId)
            ->where('template_version_id', $version->id)
            ->where('source_template_activity_id', $directActivityId)
            ->first();
        $draftMediaId = DB::table('media_file_usages')
            ->where('owner_type', 'course_activity')
            ->where('owner_id', $directActivityId)
            ->where('usage_type', 'document')
            ->value('media_file_id');
        $this->assertSame((int) $draftMediaId, (int) $directVersionActivity->media_file_id);
        $this->assertDatabaseHas('media_file_usages', [
            'customer_id' => $customerId,
            'media_file_id' => $draftMediaId,
            'owner_type' => 'course_version_activity',
            'owner_id' => $directVersionActivity->id,
            'usage_type' => 'document',
            'status' => 'active',
        ]);
        $sectionVersionActivity = DB::table(
            'core_course_template_version_activities'
        )
            ->where('customer_id', $customerId)
            ->where('template_version_id', $version->id)
            ->where('source_template_activity_id', $sectionActivityId)
            ->first();
        $nestedSectionVersionActivity = DB::table(
            'core_course_template_version_activities'
        )
            ->where('customer_id', $customerId)
            ->where('template_version_id', $version->id)
            ->where('source_template_activity_id', $nestedSectionActivityId)
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
        $this->assertNotNull($nestedSectionVersionActivity);
        $this->assertSame(
            $nestedSectionVersionLesson->id,
            $nestedSectionVersionActivity->version_lesson_id
        );
        $this->assertSame('Batchim Drill', $nestedSectionVersionActivity->title_snapshot);

        $draftBefore['template']['last_version_published_at'] = $version->published_at;
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
        $this->addValidContent($customerId, $templateId, $admin->id, 'Versioned');

        $url = "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish";

        $this->actingAs($admin)->post($url)->assertRedirect();
        $this->actingAs($admin)->post($url)->assertRedirect();

        $versions = DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->orderBy('version_number')
            ->get();

        $this->assertSame([1, 2], $versions->pluck('version_number')->all());
        $this->assertSame(
            ['VER-20260704-001', 'VER-20260704-002'],
            $versions->pluck('version_code')->all()
        );
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

        $historyResponse = $this->actingAs($admin)
            ->get(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=history"
            )
            ->assertOk()
            ->assertSeeText('Phiên bản 2')
            ->assertSeeText('Phiên bản 1')
            ->assertSeeText('Version Admin')
            ->assertSeeText('Đã xuất bản')
            ->assertSeeText('Hiện tại');

        $document = new \DOMDocument;
        @$document->loadHTML($historyResponse->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query(
            '//table[contains(@class, "course-template-history-table")]'
            .'//tbody/tr/td[6][normalize-space()="Đã xuất bản"]'
        )->length);

        app()->setLocale('en');
        $englishHistory = $this->actingAs($admin)
            ->get(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=history"
            )
            ->assertOk();
        $englishDocument = new \DOMDocument;
        @$englishDocument->loadHTML($englishHistory->getContent());
        $englishXpath = new \DOMXPath($englishDocument);
        $this->assertSame(2, $englishXpath->query(
            '//table[contains(@class, "course-template-history-table")]'
            .'//tbody/tr/td[6][normalize-space()="Published"]'
        )->length);
    }

    public function test_publish_integrity_rejects_invalid_graph_and_preserves_atomic_state(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin', 'Integrity Admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Integrity Course');
        $lessonId = $this->createLesson($customerId, $templateId, null, 'Integrity Lesson', 0, $admin->id);
        $this->createActivity($customerId, $templateId, $lessonId, 'Integrity Activity', 0, $admin->id);
        $url = "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish";

        $this->actingAs($admin)->post($url)->assertRedirect();
        $publishedAt = DB::table('core_course_templates')->where('id', $templateId)->value('last_version_published_at');
        $this->assertNotNull($publishedAt);
        $this->assertSame(
            $publishedAt,
            DB::table('core_course_template_versions')->where('template_id', $templateId)->value('published_at')
        );

        DB::table('core_course_template_lessons')->where('id', $lessonId)->update(['lesson_type' => 'unsupported']);
        $this->actingAs($admin)->from(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=publish"
        )->post($url)->assertSessionHasErrors('publish');

        $this->assertSame(1, DB::table('core_course_template_versions')->where('template_id', $templateId)->count());
        $this->assertSame($publishedAt, DB::table('core_course_templates')->where('id', $templateId)->value('last_version_published_at'));
        $this->assertSame(1, DB::table('core_course_template_versions')->where('template_id', $templateId)->where('is_current', true)->count());
    }

    public function test_uploaded_activity_media_types_create_immutable_version_usages(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin', 'Media Snapshot Admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Media Snapshot Course');

        foreach (['video', 'audio', 'document'] as $order => $type) {
            $lessonId = $this->createLesson($customerId, $templateId, null, ucfirst($type).' Lesson', $order, $admin->id);
            $this->createActivity($customerId, $templateId, $lessonId, ucfirst($type).' Activity', 0, $admin->id, $type);
        }

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
        )->assertRedirect();

        $versionActivities = DB::table('core_course_template_version_activities')
            ->where('customer_id', $customerId)->orderBy('id')->get();
        $this->assertCount(3, $versionActivities);
        foreach ($versionActivities as $activity) {
            $this->assertNotNull($activity->media_file_id);
            $this->assertDatabaseHas('media_file_usages', [
                'customer_id' => $customerId,
                'media_file_id' => $activity->media_file_id,
                'owner_type' => 'course_version_activity',
                'owner_id' => $activity->id,
                'usage_type' => $activity->activity_type,
                'status' => 'active',
            ]);
            $this->assertSame(1, DB::table('media_files')->where('id', $activity->media_file_id)->count());
        }
    }

    public function test_version_detail_media_authorization_denies_invalid_usage_and_media_states_without_draft_fallback(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin', 'Media Auth Admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Media Auth Course');
        $lessonId = $this->createLesson($customerId, $templateId, null, 'Media Auth Lesson', 0, $admin->id);
        $draftActivityId = $this->createActivity($customerId, $templateId, $lessonId, 'Unique Media Activity', 0, $admin->id);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-templates/{$templateId}/publish");
        $version = DB::table('core_course_template_versions')->where('template_id', $templateId)->first();
        $activity = DB::table('core_course_template_version_activities')->where('template_version_id', $version->id)->first();
        $usage = DB::table('media_file_usages')->where('owner_type', 'course_version_activity')->where('owner_id', $activity->id)->first();
        $url = "https://tenant-a.localhost/admin/course-templates/{$templateId}/versions/{$version->id}";

        $this->actingAs($admin)->get($url)->assertOk()->assertSee('media/files/', false)->assertDontSee('tests/activity-', false);

        foreach ([
            'wrong_purpose' => fn () => DB::table('media_file_usages')->where('id', $usage->id)->update(['usage_type' => 'audio']),
            'inactive_usage' => fn () => DB::table('media_file_usages')->where('id', $usage->id)->update(['usage_type' => 'document', 'status' => 'detached']),
            'archived_media' => fn () => tap(DB::table('media_file_usages')->where('id', $usage->id)->update(['status' => 'active']), fn () => DB::table('media_files')->where('id', $activity->media_file_id)->update(['status' => 'archived'])),
            'missing_usage' => fn () => tap(DB::table('media_files')->where('id', $activity->media_file_id)->update(['status' => 'ready']), fn () => DB::table('media_file_usages')->where('id', $usage->id)->delete()),
        ] as $case => $corrupt) {
            $corrupt();
            $response = $this->actingAs($admin)->get($url)->assertOk()
                ->assertDontSeeText('Media snapshot không khả dụng')
                ->assertDontSee('media/files/', false)
                ->assertDontSee('tests/activity-', false);
            $this->assertStringNotContainsString('course_activity', $response->getContent(), $case);
        }
        app()->setLocale('en');
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertDontSeeText('Media snapshot unavailable')
            ->assertDontSee('media/files/', false);
        $this->assertDatabaseHas('media_file_usages', ['owner_type' => 'course_activity', 'owner_id' => $draftActivityId, 'status' => 'active']);
    }

    public function test_publish_media_errors_identify_activity_and_template_fields_safely(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin', 'Media Error Admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Media Error Course');
        $lessonId = $this->createLesson($customerId, $templateId, null, 'Media Error Lesson', 0, $admin->id);
        $activityId = $this->createActivity($customerId, $templateId, $lessonId, 'Missing Activity Media', 0, $admin->id);
        DB::table('media_file_usages')->where('owner_type', 'course_activity')->where('owner_id', $activityId)->delete();

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
        )->assertSessionHasErrors([
            'publish' => 'Media của hoạt động "Missing Activity Media" (document) trong bài học "Media Error Lesson" đang thiếu, không khả dụng hoặc liên kết không đúng. Trong tab Nội dung, hãy sửa hoạt động này và tải lên hoặc chọn lại đúng tệp.',
        ]);

        $this->assertSame(
            'Ảnh giới thiệu của Template không khả dụng hoặc liên kết Media không hợp lệ. Vui lòng kiểm tra tab Thông tin.',
            __('lf.LF_course_template_publish_integrity_template_intro_image')
        );

    }

    public function test_publish_tab_uses_structured_readiness_for_ready_and_blocked_drafts(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin', 'Readiness Admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Readiness Course');
        $lessonId = $this->createLesson($customerId, $templateId, null, 'Readiness Lesson', 0, $admin->id);
        $activityId = $this->createActivity($customerId, $templateId, $lessonId, 'Readiness Document', 0, $admin->id);

        $service = app(\App\Services\CourseTemplatePublishReadinessService::class);
        $ready = $service->evaluate($customerId, $service->load($customerId, $templateId));
        $this->assertTrue($ready->isReady());
        $this->assertCount(0, $ready->blockers());
        $this->assertCount(0, $ready->warnings());
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=publish")
            ->assertOk()
            ->assertSeeText('Sẵn sàng xuất bản')
            ->assertDontSee('course-template-publish-button" disabled', false);

        DB::table('core_course_templates')->where('id', $templateId)->update(['publisher_name' => null, 'working_revision' => 4]);
        DB::table('media_file_usages')->where('owner_type', 'course_activity')->where('owner_id', $activityId)->delete();
        $blocked = $service->evaluate($customerId, $service->load($customerId, $templateId));
        $this->assertFalse($blocked->isReady());
        $this->assertSame(['template', 'activity_media'], $blocked->blockers()->pluck('code')->all());
        $this->assertSame(['information', 'content'], $blocked->blockers()->pluck('targetTab')->all());

        $response = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=publish")
            ->assertOk()
            ->assertSeeText('2 vấn đề đang chặn xuất bản')
            ->assertSeeText('Readiness Lesson')
            ->assertSeeText('Readiness Document')
            ->assertSee('?tab=information', false)
            ->assertSee('?tab=structure', false)
            ->assertSee("course-template-lesson-{$lessonId}-activities", false);
        $content = $response->getContent();
        $this->assertStringContainsString('class="course-template-publish-summary"', $content);
        $this->assertStringContainsString('class="course-template-readiness-header"', $content);
        $this->assertStringContainsString('class="course-template-readiness-help"', $content);
        $this->assertStringContainsString('class="course-template-readiness-message"', $content);
        $this->assertStringContainsString('class="admin-form-actions course-template-publish-actions"', $content);
        $document = new \DOMDocument;
        @$document->loadHTML($content);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//button[contains(@class, "course-template-publish-button") and @disabled]')->length);
        $this->assertSame(1, substr_count($response->getContent(), 'data-readiness-code="activity_media"'));
        $informationTarget = $xpath->query('//li[@data-readiness-code="template"]//a')->item(0);
        $contentTarget = $xpath->query('//li[@data-readiness-code="activity_media"]//a')->item(0);
        $this->assertNotNull($informationTarget);
        $this->assertNotNull($contentTarget);
        $this->assertStringContainsString('?tab=information', $informationTarget->getAttribute('href'));
        $this->assertStringContainsString('?tab=structure', $contentTarget->getAttribute('href'));
        $this->assertStringContainsString(
            "#course-template-lesson-{$lessonId}-activities",
            $contentTarget->getAttribute('href')
        );
        $this->assertStringNotContainsString('?tab=content', $contentTarget->getAttribute('href'));

        $css = file_get_contents(resource_path('css/admin/admin-pages.css'));
        $this->assertStringContainsString('repeat(auto-fit, minmax(min(100%, 240px), 1fr))', $css);
        $this->assertStringContainsString('.course-template-readiness-list li {', $css);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) auto;', $css);
        $this->assertStringContainsString('@media (max-width: 575.98px)', $css);
    }

    public function test_direct_publish_post_cannot_bypass_readiness_and_correction_is_recalculated(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin', 'Recalculation Admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Recalculation Course');
        $lessonId = $this->createLesson($customerId, $templateId, null, 'Recalculation Lesson', 0, $admin->id);
        $activityId = $this->createActivity($customerId, $templateId, $lessonId, 'Recalculation Video', 0, $admin->id, 'video');
        $usage = DB::table('media_file_usages')->where('owner_type', 'course_activity')->where('owner_id', $activityId)->first();
        DB::table('media_file_usages')->where('id', $usage->id)->update(['status' => 'detached']);

        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-templates/{$templateId}/publish")
            ->assertSessionHasErrors('publish');
        $this->assertDatabaseMissing('core_course_template_versions', ['template_id' => $templateId]);

        DB::table('media_file_usages')->where('id', $usage->id)->update(['status' => 'active']);
        DB::table('core_course_templates')->where('id', $templateId)->update(['working_revision' => 4]);
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=publish")
            ->assertOk()
            ->assertSeeText('Hoạt động · Bản chỉnh sửa 4')
            ->assertSeeText('Sẵn sàng xuất bản');
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-templates/{$templateId}/publish")
            ->assertRedirect("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=publish");
        $this->assertDatabaseHas('core_course_template_versions', ['template_id' => $templateId, 'status' => 'published']);
    }

    public function test_readiness_reports_empty_content_and_template_media_video_state_without_foreign_details(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin', 'Coverage Admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Coverage Course');

        $empty = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=publish")
            ->assertOk()
            ->assertSeeText('Khóa học chưa có bài học')
            ->assertSee('?tab=structure', false);
        $this->assertStringNotContainsString('storage_key', $empty->getContent());
        $emptyDocument = new \DOMDocument;
        @$emptyDocument->loadHTML($empty->getContent());
        $emptyTarget = (new \DOMXPath($emptyDocument))
            ->query('//li[@data-readiness-code="empty_content"]//a')
            ->item(0);
        $this->assertNotNull($emptyTarget);
        $this->assertStringContainsString('?tab=structure', $emptyTarget->getAttribute('href'));
        $this->assertStringNotContainsString('?tab=content', $emptyTarget->getAttribute('href'));

        DB::table('core_course_templates')->where('id', $templateId)->update([
            'intro_video_source' => 'embed',
            'intro_video_embed_url' => 'https://example.test/video',
            'intro_video_provider' => 'unsupported',
        ]);
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=publish")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_invalid_video_state'))
            ->assertSee('?tab=information', false);
    }

    public function test_readiness_keeps_every_activity_media_relationship_failure_as_a_blocker(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin', 'Relationship Admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Relationship Course');
        $lessonId = $this->createLesson($customerId, $templateId, null, 'Relationship Lesson', 0, $admin->id);
        $activityId = $this->createActivity($customerId, $templateId, $lessonId, 'Relationship Document', 0, $admin->id);
        $usage = DB::table('media_file_usages')->where('owner_type', 'course_activity')->where('owner_id', $activityId)->first();
        $media = DB::table('media_files')->where('id', $usage->media_file_id)->first();
        $service = app(\App\Services\CourseTemplatePublishReadinessService::class);

        $cases = [
            'detached usage' => fn () => DB::table('media_file_usages')->where('id', $usage->id)->update(['status' => 'detached']),
            'archived media' => fn () => DB::table('media_files')->where('id', $media->id)->update(['status' => 'archived']),
            'wrong usage' => fn () => DB::table('media_file_usages')->where('id', $usage->id)->update(['usage_type' => 'video']),
            'wrong owner' => fn () => DB::table('media_file_usages')->where('id', $usage->id)->update(['owner_id' => $activityId + 999]),
            'wrong mime' => fn () => DB::table('media_files')->where('id', $media->id)->update(['mime_type' => 'video/mp4']),
            'cross tenant media' => fn () => DB::table('media_files')->where('id', $media->id)->update(['customer_id' => $otherCustomerId]),
        ];

        foreach ($cases as $case => $corrupt) {
            DB::table('media_file_usages')->where('id', $usage->id)->update([
                'customer_id' => $customerId,
                'owner_type' => 'course_activity',
                'owner_id' => $activityId,
                'usage_type' => 'document',
                'status' => 'active',
            ]);
            DB::table('media_files')->where('id', $media->id)->update([
                'customer_id' => $customerId,
                'file_type' => 'document',
                'mime_type' => 'application/pdf',
                'status' => 'ready',
            ]);
            $corrupt();

            $readiness = $service->evaluate($customerId, $service->load($customerId, $templateId));
            $this->assertFalse($readiness->isReady(), $case);
            $this->assertSame(['activity_media'], $readiness->blockers()->pluck('code')->all(), $case);
        }
    }

    public function test_readiness_reports_each_invalid_template_media_slot_from_the_shared_result(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin', 'Slot Admin');
        $templateId = $this->createTemplate($customerId, $admin->id, 'Slot Course');
        $documentLesson = $this->createLesson($customerId, $templateId, null, 'Document Lesson', 0, $admin->id);
        $documentActivity = $this->createActivity($customerId, $templateId, $documentLesson, 'Slot Document', 0, $admin->id);
        $videoLesson = $this->createLesson($customerId, $templateId, null, 'Video Lesson', 1, $admin->id);
        $videoActivity = $this->createActivity($customerId, $templateId, $videoLesson, 'Slot Video', 0, $admin->id, 'video');
        $documentMediaId = DB::table('media_file_usages')->where('owner_type', 'course_activity')->where('owner_id', $documentActivity)->value('media_file_id');
        $videoMediaId = DB::table('media_file_usages')->where('owner_type', 'course_activity')->where('owner_id', $videoActivity)->value('media_file_id');
        DB::table('core_course_templates')->where('id', $templateId)->update([
            'intro_image_media_file_id' => $documentMediaId,
            'intro_video_source' => 'upload',
            'intro_video_media_file_id' => $videoMediaId,
            'intro_video_embed_url' => null,
            'intro_video_provider' => null,
            'intro_document_media_file_id' => $documentMediaId,
        ]);

        $service = app(\App\Services\CourseTemplatePublishReadinessService::class);
        $readiness = $service->evaluate($customerId, $service->load($customerId, $templateId));
        $this->assertSame(
            ['template_intro_image', 'template_intro_video', 'template_intro_document'],
            $readiness->blockers()->pluck('code')->all()
        );
        $this->assertSame(['information', 'information', 'information'], $readiness->blockers()->pluck('targetTab')->all());
    }

    public function test_publish_and_duplicate_preserve_documented_duplicate_content_order_values(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser(
            $customerId,
            'customer_admin',
            'Duplicate Order Admin'
        );
        $templateId = $this->createTemplate(
            $customerId,
            $admin->id,
            'Duplicate Order Course'
        );
        $firstSectionId = $this->createSection(
            $customerId,
            $templateId,
            'First Section',
            1
        );
        $secondSectionId = $this->createSection(
            $customerId,
            $templateId,
            'Second Section',
            1
        );
        $nestedSectionId = $this->createSection(
            $customerId,
            $templateId,
            'Nested Section',
            1,
            $firstSectionId
        );
        $firstDirectId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'First Direct Lesson',
            3,
            $admin->id
        );
        $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Second Direct Lesson',
            3,
            $admin->id
        );
        $firstLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $firstSectionId,
            'First Lesson',
            1,
            $admin->id
        );
        $this->createLesson(
            $customerId,
            $templateId,
            $firstSectionId,
            'Second Lesson',
            1,
            $admin->id
        );
        $nestedLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $nestedSectionId,
            'Nested Lesson',
            4,
            $admin->id
        );
        $this->createActivity(
            $customerId,
            $templateId,
            $firstLessonId,
            'First Activity',
            1,
            $admin->id
        );
        $this->createActivity(
            $customerId,
            $templateId,
            $nestedLessonId,
            'Nested Activity',
            2,
            $admin->id
        );
        $this->createActivity(
            $customerId,
            $templateId,
            $firstLessonId,
            'Second Activity',
            1,
            $admin->id
        );

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
            )
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('success', 'Đã xuất bản phiên bản 1.');

        $this->assertDatabaseHas('core_course_template_versions', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'status' => 'published',
        ]);

        $version = DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->first();
        $versionState = $this->versionState(
            $customerId,
            $templateId,
            $version->id
        );
        $this->clearDraftContent($customerId, $templateId);
        $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Stale Draft Lesson',
            99,
            $admin->id
        );

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/versions/{$version->id}/duplicate-to-draft"
            )
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas(
                'success',
                'Đã sao chép phiên bản đã xuất bản vào bản nháp.'
            );

        $directLessons = DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->whereNull('template_section_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $this->assertSame(
            ['First Direct Lesson', 'Second Direct Lesson'],
            $directLessons->pluck('title')->all()
        );
        $this->assertSame([3, 3], $directLessons->pluck('sort_order')->all());

        $restoredFirstSection = DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('title', 'First Section')
            ->first();
        $restoredSecondSection = DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('title', 'Second Section')
            ->first();
        $restoredNestedSection = DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('title', 'Nested Section')
            ->first();
        $this->assertSame(1, $restoredFirstSection->display_order);
        $this->assertSame(1, $restoredSecondSection->display_order);
        $this->assertSame(
            $restoredFirstSection->id,
            $restoredNestedSection->parent_section_id
        );

        $restoredSectionLessons = DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('template_section_id', $restoredFirstSection->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $this->assertSame([1, 1], $restoredSectionLessons->pluck('sort_order')->all());
        $restoredFirstLesson = $restoredSectionLessons->firstWhere(
            'title',
            'First Lesson'
        );
        $restoredActivities = DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('template_lesson_id', $restoredFirstLesson->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $this->assertSame([1, 1], $restoredActivities->pluck('sort_order')->all());
        $this->assertSame(
            $versionState,
            $this->versionState($customerId, $templateId, $version->id)
        );
        $this->assertDatabaseMissing('core_course_template_lessons', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'title' => 'Stale Draft Lesson',
        ]);
    }

    public function test_template_version_code_sequence_is_tenant_scoped(): void
    {
        $customerA = $this->createTenant();
        $customerB = $this->createTenant('tenant-b');
        $adminA = $this->createUser($customerA, 'customer_admin');
        $adminB = $this->createUser($customerB, 'customer_admin');
        $templateA1 = $this->createTemplate($customerA, $adminA->id, 'Tenant A First');
        $templateA2 = $this->createTemplate($customerA, $adminA->id, 'Tenant A Second');
        $templateB = $this->createTemplate($customerB, $adminB->id, 'Tenant B First');
        $this->addValidContent($customerA, $templateA1, $adminA->id, 'Tenant A First');
        $this->addValidContent($customerA, $templateA2, $adminA->id, 'Tenant A Second');
        $this->addValidContent($customerB, $templateB, $adminB->id, 'Tenant B First');

        $this->actingAs($adminA)
            ->post("https://tenant-a.localhost/admin/course-templates/{$templateA1}/publish")
            ->assertRedirect();
        $this->actingAs($adminA)
            ->post("https://tenant-a.localhost/admin/course-templates/{$templateA2}/publish")
            ->assertRedirect();
        $this->actingAs($adminB)
            ->post("https://tenant-b.localhost/admin/course-templates/{$templateB}/publish")
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_template_versions', [
            'customer_id' => $customerA,
            'template_id' => $templateA1,
            'version_code' => 'VER-20260704-001',
        ]);
        $this->assertDatabaseHas('core_course_template_versions', [
            'customer_id' => $customerA,
            'template_id' => $templateA2,
            'version_code' => 'VER-20260704-002',
        ]);
        $this->assertDatabaseHas('core_course_template_versions', [
            'customer_id' => $customerB,
            'template_id' => $templateB,
            'version_code' => 'VER-20260704-001',
        ]);
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
        $childSectionId = $this->createSection(
            $customerId,
            $templateId,
            'Nested Hangul',
            1,
            $sectionId
        );
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
        $blankDescriptionLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Blank Description Lesson',
            2,
            $admin->id
        );
        DB::table('core_course_template_lessons')
            ->where('id', $directLessonId)
            ->update([
                'short_description' => 'Immutable <script>alert("lesson")</script> summary.',
                'description' => 'Detailed description must not replace the short description.',
            ]);
        DB::table('core_course_template_lessons')
            ->where('id', $sectionLessonId)
            ->update([
                'short_description' => null,
                'description' => 'Immutable section lesson description.',
            ]);
        DB::table('core_course_template_lessons')
            ->where('id', $blankDescriptionLessonId)
            ->update([
                'short_description' => '   ',
                'description' => "\n\t",
            ]);
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

        DB::table('core_course_template_lessons')
            ->whereIn('id', [$directLessonId, $sectionLessonId])
            ->update([
                'short_description' => 'Changed draft description.',
                'description' => 'Changed draft detailed description.',
            ]);

        $versionId = (int) DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->value('id');

        $response = $this->actingAs($admin)
            ->get(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/versions/{$versionId}"
            )
            ->assertOk()
            ->assertSeeText('Chi tiết phiên bản đã xuất bản')
            ->assertSeeText('Phiên bản 1')
            ->assertSeeText('Phiên bản xuất bản hiện tại')
            ->assertSeeText('Bản chỉnh sửa nguồn')
            ->assertSeeText('Đây là phiên bản lịch sử bất biến')
            ->assertSeeText('Readonly Snapshot')
            ->assertSeeText('Bài học trực tiếp')
            ->assertSeeText('Direct Snapshot Lesson')
            ->assertSeeText('Immutable <script>alert("lesson")</script> summary.')
            ->assertSeeText('Immutable section lesson description.')
            ->assertSeeText('Blank Description Lesson')
            ->assertDontSeeText('Changed draft description.')
            ->assertDontSeeText('Detailed description must not replace the short description.')
            ->assertDontSee('<script>alert("lesson")</script>', false)
            ->assertSeeText('Direct Snapshot Activity')
            ->assertSeeText('Hangul')
            ->assertSeeText('Section Snapshot Lesson')
            ->assertSeeText('Section Snapshot Activity')
            ->assertSeeText('Quay lại lịch sử khóa học')
            ->assertSeeText('Sao chép vào bản nháp')
            ->assertSee('class="course-template-version-detail"', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('id="course-version-direct-tab"', false)
            ->assertSee('id="course-version-sections-tab"', false)
            ->assertSee('role="tabpanel"', false)
            ->assertSee('x-on:keydown.right.prevent', false)
            ->assertSee('x-on:keydown.left.prevent', false)
            ->assertSee('class="course-template-outline-section"', false)
            ->assertSee('class="course-template-lesson-item"', false)
            ->assertSee('class="course-template-activity-item"', false)
            ->assertSee('openVersionPreview', false)
            ->assertDontSeeText('Giáo viên được phân công')
            ->assertDontSeeText('Lưu thay đổi')
            ->assertDontSeeText('Xóa');

        $html = $response->getContent();
        $directPanel = str($html)->between(
            'id="course-version-direct-panel"',
            'id="course-version-sections-panel"'
        );
        $sectionsPanel = str($html)->after('id="course-version-sections-panel"');

        $this->assertStringContainsString('Direct Snapshot Lesson', $directPanel);
        $this->assertStringNotContainsString('Section Snapshot Lesson', $directPanel);
        $this->assertStringContainsString('Section Snapshot Lesson', $sectionsPanel);

        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $contentCard = $xpath->query('//*[@id="course-version-content"]')->item(0);
        $this->assertNotNull($contentCard);
        $this->assertSame(0, $xpath->query('.//form', $contentCard)->length);
        $this->assertSame(0, $xpath->query('.//input|.//select|.//textarea', $contentCard)->length);
        $this->assertSame(0, $xpath->query('.//*[contains(@class, "course-version-role-badge")]', $contentCard)->length);
        $this->assertSame(0, $xpath->query('.//*[contains(@class, "course-version-lesson-meta")]', $contentCard)->length);
        $this->assertSame(0, $xpath->query('.//*[contains(@class, "course-version-activity-meta")]', $contentCard)->length);

        $lessonSelector = './/article[contains(concat(" ", normalize-space(@class), " "), " course-template-lesson-item ")]';
        $directLesson = $xpath->query($lessonSelector.'[.//strong[normalize-space()="Direct Snapshot Lesson"]]', $contentCard)->item(0);
        $sectionLesson = $xpath->query($lessonSelector.'[.//strong[normalize-space()="Section Snapshot Lesson"]]', $contentCard)->item(0);
        $blankLesson = $xpath->query($lessonSelector.'[.//strong[normalize-space()="Blank Description Lesson"]]', $contentCard)->item(0);
        foreach ([$directLesson, $sectionLesson, $blankLesson] as $lessonNode) {
            $this->assertNotNull($lessonNode);
        }
        $descriptionSelector = './/*[contains(concat(" ", normalize-space(@class), " "), " course-template-lesson-summary ")]/div/strong/following-sibling::*[1][self::span[contains(concat(" ", normalize-space(@class), " "), " lf-secondary-text ") and contains(concat(" ", normalize-space(@class), " "), " lf-line-clamp-2 ")]]';
        $this->assertSame(1, $xpath->query($descriptionSelector, $directLesson)->length);
        $this->assertSame(1, $xpath->query($descriptionSelector, $sectionLesson)->length);
        $this->assertSame(0, $xpath->query($descriptionSelector, $blankLesson)->length);

        $pageCss = file_get_contents(
            base_path('resources/css/admin/admin-pages.css')
        );
        $this->assertStringContainsString(
            '.course-template-version-detail {' . PHP_EOL
                . '    width: 100%;' . PHP_EOL
                . '    min-width: 0;',
            $pageCss
        );
        $this->assertStringNotContainsString(
            '.course-template-version-detail {' . PHP_EOL
                . '    width: 100%;' . PHP_EOL
                . '    max-width: 960px;',
            $pageCss
        );

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
            ]);

        $sectionId = $this->createSection($customerId, $templateId);
        $childSectionId = $this->createSection(
            $customerId,
            $templateId,
            'Nested Hangul',
            1,
            $sectionId
        );
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
            $childSectionId,
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
        DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $version->id)
            ->update(['lesson_type' => 'final_exam']);

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
        $this->assertNull($template->intro_video_source);
        $this->assertNull($template->intro_image_media_file_id);
        $this->assertNull($template->intro_video_media_file_id);
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
        $this->assertDatabaseHas('core_course_template_lessons', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'lesson_type' => 'final_exam',
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
        $childSection = DB::table('core_course_template_sections')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('title', 'Nested Hangul')
            ->first();
        $this->assertNotNull($childSection);
        $this->assertSame($section->id, $childSection->parent_section_id);
        $this->assertSame(1, (int) $section->allows_lessons);
        $this->assertSame(1, (int) $childSection->allows_lessons);
        $this->assertDatabaseHas('core_course_template_lessons', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_section_id' => $childSection->id,
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
                'working_revision' => 9,
            ]);
        DB::table('core_course_template_versions')
            ->where('id', $versionId)
            ->update(['intro_video_source_snapshot' => 'embed']);
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

    public function test_duplicate_rejects_invalid_snapshot_order_and_rolls_back(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            $admin->id,
            'Invalid Snapshot Order'
        );
        $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Published Lesson',
            1,
            $admin->id
        );
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/publish"
        );
        $version = DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->first();
        DB::table('core_course_template_version_lessons')
            ->where('customer_id', $customerId)
            ->where('template_version_id', $version->id)
            ->update(['sort_order' => -1]);
        DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->update(['title' => 'Current Draft Must Remain']);
        $draftBefore = $this->draftState($customerId, $templateId);

        $this->actingAs($admin)
            ->from(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=history"
            )
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/versions/{$version->id}/duplicate-to-draft"
            )
            ->assertSessionHasErrors('duplicate');

        $this->assertSame(
            $draftBefore,
            $this->draftState($customerId, $templateId)
        );
        $this->assertSame(
            -1,
            DB::table('core_course_template_version_lessons')
                ->where('customer_id', $customerId)
                ->where('template_version_id', $version->id)
                ->value('sort_order')
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
            'short_description' => 'Published snapshot course',
            'description' => 'Detailed snapshot description.',
            'publisher_name' => 'LearnForge',
            'intro_video_source' => null,
            'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => null,
            'difficulty_level' => 'beginner',
            'estimated_minutes_per_lesson' => 90,
            'estimated_lesson_count' => 20,
            'lesson_count' => 2,
            'meta_title' => 'Snapshot Course',
            'meta_description' => 'Snapshot course metadata.',
            'meta_keywords' => 'snapshot,course',
            'working_revision' => 3,
            'status' => 'active',
            'created_by' => $createdBy,
            'last_version_published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createSection(
        int $customerId,
        int $templateId,
        string $title = 'Hangul',
        int $displayOrder = 1,
        ?int $parentSectionId = null
    ): int {
        return DB::table('core_course_template_sections')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'parent_section_id' => $parentSectionId,
            'allows_lessons' => true,
            'title' => $title,
            'description' => $title.' section.',
            'display_order' => $displayOrder,
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
            'short_description' => 'Lesson summary.',
            'description' => 'Lesson description.',
            'sort_order' => $sortOrder,
            'is_preview' => $sectionId === null,
            'duration_seconds' => 0,
            'activity_count' => 1,
            'unlock_rule' => 'none',
            'unlock_after_lesson_id' => null,
            'unlock_at' => null,
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
        int $createdBy,
        string $activityType = 'document'
    ): int {
        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_lesson_id' => $lessonId,
            'title' => $title,
            'description' => 'Activity description.',
            'sort_order' => $sortOrder,
            'activity_type' => $activityType,
            'external_video_url' => null,
            'live_class_url' => null,
            'assessment_quiz_id' => null,
            'duration_seconds' => 600,
            'is_required' => true,
            'completion_rule' => 'view',
            'completion_threshold' => null,
            'is_preview' => false,
            'unlock_rule' => 'none',
            'unlock_after_activity_id' => null,
            'unlock_at' => null,
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mime = ['video' => 'video/mp4', 'audio' => 'audio/mpeg', 'document' => 'application/pdf'][$activityType];
        $extension = ['video' => 'mp4', 'audio' => 'mp3', 'document' => 'pdf'][$activityType];
        $mediaId = DB::table('media_files')->insertGetId([
            'customer_id' => $customerId, 'category_id' => null, 'uploaded_by' => $createdBy,
            'file_type' => $activityType, 'mime_type' => $mime,
            'original_name' => "activity-{$activityId}.{$extension}", 'display_name' => $title,
            'extension' => $extension, 'storage_disk' => 'media_local', 'storage_bucket' => 'local-media',
            'storage_region' => null, 'storage_key' => "tests/activity-{$customerId}-{$activityId}.{$extension}",
            'storage_class' => null, 'cdn_url' => null, 'public_url' => null, 'checksum' => null,
            'file_size_bytes' => 1, 'duration_seconds' => null, 'width' => null, 'height' => null,
            'page_count' => 1, 'language' => null, 'visibility' => 'private', 'status' => 'ready',
            'metadata' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_file_usages')->insert([
            'customer_id' => $customerId, 'media_file_id' => $mediaId,
            'owner_type' => 'course_activity', 'owner_id' => $activityId,
            'usage_type' => $activityType, 'status' => 'active', 'metadata' => null,
            'created_by' => $createdBy, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $activityId;
    }

    private function addValidContent(int $customerId, int $templateId, int $createdBy, string $prefix): void
    {
        $lessonId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            $prefix.' Lesson',
            0,
            $createdBy
        );
        $this->createActivity(
            $customerId,
            $templateId,
            $lessonId,
            $prefix.' Activity',
            0,
            $createdBy
        );
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
