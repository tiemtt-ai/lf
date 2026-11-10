<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
        DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->update(['created_by' => $teacher->id]);
        $this->assignCreator($customerId, $templateId, $teacher->id);
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
            $response = $this->actingAs($user)
                ->get(
                    "https://tenant-a.localhost/{$area}/course-templates/"
                    ."{$templateId}/edit"
                )
                ->assertOk()
                ->assertSeeText('Bài học (1)')
                ->assertSeeText('Thêm bài học')
                ->assertSeeText('Học bất kỳ lúc nào')
                ->assertDontSeeText('Trước buổi học')
                ->assertSeeText('Thêm hoạt động')
                ->assertSeeText('Alphabet Text')
                ->assertDontSeeText('Private Tenant Activity');

            $xpath = $this->xpath($response->getContent());
            $sectionLesson = $xpath->query(
                '//article[contains(concat(" ", normalize-space(@class), " "), " course-template-outline-section ")]'
                .'//article[contains(concat(" ", normalize-space(@class), " "), " course-template-lesson-item ")]'
            )->item(0);

            $this->assertNotNull($sectionLesson);
            $this->assertSame(
                ['Sửa', 'Xóa'],
                array_map(
                    static fn (\DOMNode $node): string => trim($node->textContent),
                    iterator_to_array($xpath->query(
                        './div[contains(concat(" ", normalize-space(@class), " "), " course-template-lesson-summary ")]'
                        .'//div[contains(concat(" ", normalize-space(@class), " "), " admin-table-actions ")]/*',
                        $sectionLesson
                    ))
                )
            );

            $activityActions = $xpath->query(
                './/div[contains(concat(" ", normalize-space(@class), " "), " course-template-activity-item ")]'
                .'//div[contains(concat(" ", normalize-space(@class), " "), " admin-table-actions ")]/*',
                $sectionLesson
            );
            // AI proposals live only in the Outcomes & competencies tab: no row carries an entry.
            $this->assertSame(
                ['Xem', 'Sửa', 'Xóa'],
                array_map(
                    static fn (\DOMNode $node): string => trim($node->textContent),
                    iterator_to_array($activityActions)
                )
            );

            foreach ($xpath->query(
                './/*[self::a or self::button][contains(concat(" ", normalize-space(@class), " "), " admin-text-action ")]',
                $sectionLesson->parentNode
            ) as $action) {
                $this->assertStringContainsString(
                    'admin-text-action',
                    $action->getAttribute('class')
                );
            }
        }
    }

    public function test_structure_tab_renders_the_complete_course_outline(): void
    {
        [$customerId, $templateId, , $sectionedLessonId] =
            $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $directLessonId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Direct Outline Lesson'
        );
        DB::table('core_course_template_lessons')
            ->where('id', $directLessonId)
            ->update([
                'short_description' => 'Mô tả ngắn của bài học được hiển thị ngay dưới tiêu đề.',
            ]);
        $directActivityId = $this->createActivity(
            $customerId,
            $templateId,
            $directLessonId,
            'Direct Outline Activity'
        );
        $this->createActivity(
            $customerId,
            $templateId,
            $sectionedLessonId,
            'Section Outline Activity'
        );

        $response = $this->actingAs($admin)
            ->get(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit?tab=structure"
            )
            ->assertOk()
            ->assertSeeText('Cấu trúc khóa học')
            ->assertSeeText('Bài học độc lập')
            ->assertSeeText('Direct Outline Lesson')
            ->assertSeeText('Direct Outline Activity')
            ->assertSeeText('Section default')
            ->assertSeeText('Lesson default')
            ->assertSeeText('Section Outline Activity')
            ->assertSeeText('Thêm phần học')
            ->assertSeeText('Thêm bài học')
            ->assertSeeText('Thêm hoạt động')
            ->assertSeeText('Sửa')
            ->assertSeeText('Xóa')
            ->assertSeeText(__('lf.LF_course_template_mode_mixed_note'))
            ->assertSeeText(__('lf.LF_course_template_mode_mixed_help'))
            ->assertSee('id="course-template-direct-panel"', false)
            ->assertSee('id="course-template-sections-panel"', false);

        $xpath = $this->xpath($response->getContent());
        $this->assertSame(
            1,
            $xpath->query(
                '//div[contains(concat(" ", normalize-space(@class), " "), " course-template-structure-note ")][@role="status"]'
            )->length
        );
        $directLesson = $xpath->query(
            '//article[contains(concat(" ", normalize-space(@class), " "), " course-template-lesson-item ")]'
            .'[.//strong[normalize-space()="Direct Outline Lesson"]]'
        )->item(0);
        $this->assertNotNull($directLesson);
        $lessonDescription = $xpath->query(
            './/span[contains(concat(" ", normalize-space(@class), " "), " lf-secondary-text ")'
            .' and contains(concat(" ", normalize-space(@class), " "), " lf-line-clamp-2 ")]',
            $directLesson
        )->item(0);
        $this->assertNotNull($lessonDescription);
        $this->assertSame(
            'Mô tả ngắn của bài học được hiển thị ngay dưới tiêu đề.',
            trim($lessonDescription->textContent)
        );
        $activityTitle = $xpath->query(
            './/span[contains(concat(" ", normalize-space(@class), " "), " course-template-activity-title-text ")]'
            .'[normalize-space()="Direct Outline Activity"]',
            $directLesson
        )->item(0);
        $this->assertNotNull($activityTitle);
        $activityRow = $xpath->query(
            'ancestor::div[contains(concat(" ", normalize-space(@class), " "), " course-template-activity-item ")]',
            $activityTitle
        )->item(0);
        $this->assertNotNull($activityRow);
        $viewAction = $xpath->query(
            './/div[contains(concat(" ", normalize-space(@class), " "), " admin-table-actions ")]//button[normalize-space()="Xem" and @data-ui-dialog-form-open]',
            $activityRow
        )->item(0);
        $this->assertNotNull($viewAction);
        $actionLabels = [];
        foreach ($xpath->query(
            './/div[contains(concat(" ", normalize-space(@class), " "), " admin-table-actions ")]/*',
            $activityRow
        ) as $action) {
            $actionLabels[] = trim($action->textContent);
        }
        // The admin also has the D11 entry to the AI proposals of an Activity that carries Media.
        $this->assertSame(['Xem', 'Sửa', 'Xóa'], $actionLabels);
        $this->assertSame(
            $this->directActivityCollectionUrl(
                'admin',
                $templateId,
                $directLessonId
            )."/{$directActivityId}",
            $viewAction->getAttribute('data-url')
        );
        $this->assertSame(
            0,
            $xpath->query(
                './/span[normalize-space()="Bản nháp" or normalize-space()="Hoạt động" or normalize-space()="Không hoạt động" or normalize-space()="Ngừng sử dụng"]',
                $directLesson
            )->length
        );
        $this->assertSame(0, $xpath->query('.//a[normalize-space()="Direct Outline Activity"]', $activityRow)->length);
        $this->assertSame(
            1,
            $xpath->query(
                './/span[contains(concat(" ", normalize-space(@class), " "), " course-template-activity-icon ")]',
                $activityRow
            )->length
        );
        $this->assertSame(
            1,
            $xpath->query(
                './/div[contains(concat(" ", normalize-space(@class), " "), " admin-table-actions ")]//button[normalize-space()="Sửa" and @data-ui-dialog-form-open]',
                $activityRow
            )->length
        );
        $this->assertSame(
            1,
            $xpath->query(
                './/div[contains(concat(" ", normalize-space(@class), " "), " admin-table-actions ")]//button[normalize-space()="Xóa"]',
                $activityRow
            )->length
        );

        $activityBefore = DB::table('core_course_template_activities')
            ->where('id', $directActivityId)
            ->first();
        // The read-only view is a popup fragment (XHR only): details, Close and Edit, no field.
        $viewUrl = $viewAction->getAttribute('data-url');
        $this->actingAs($admin)->get($viewUrl)->assertNotFound();
        $showResponse = $this->actingAs($admin)
            ->withHeaders($this->ajaxHeaders())
            ->get($viewUrl)
            ->assertOk()
            ->assertSeeText('Direct Outline Activity')
            ->assertSeeText('Đóng')
            ->assertSeeText('Sửa');
        $showXpath = $this->xpath($showResponse->getContent());
        $this->assertSame(
            0,
            $showXpath->query('//input[not(@type="hidden")]|//select|//textarea')->length
        );
        $this->assertSame(
            1,
            $showXpath->query('//button[@data-ui-dialog-form-open and contains(@data-url, "/activities/'.$directActivityId.'/edit")]')->length
        );
        $this->assertEquals(
            $activityBefore,
            DB::table('core_course_template_activities')
                ->where('id', $directActivityId)
                ->first()
        );
    }

    public function test_external_activity_view_action_opens_external_url_in_new_tab(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $activityId = $this->createActivity(
            $customerId,
            $templateId,
            $lessonId,
            'External Activity'
        );
        DB::table('core_course_template_activities')
            ->where('id', $activityId)
            ->update([
                'activity_type' => 'embedded_video',
                'external_video_url' => 'https://www.youtube.com/watch?v=abc123',
            ]);

        $response = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk();
        $xpath = $this->xpath($response->getContent());
        $row = $xpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " course-template-activity-item ")]'
            .'[.//span[normalize-space()="External Activity"]]'
        )->item(0);
        $title = $xpath->query('.//span[normalize-space()="External Activity"]', $row)->item(0);
        $viewAction = $xpath->query('.//a[normalize-space()="Xem"]', $row)->item(0);

        $this->assertNotNull($row);
        $this->assertNotNull($title);
        $this->assertNotNull($viewAction);
        $this->assertFalse($title->hasAttribute('href'));
        $this->assertSame('https://www.youtube.com/watch?v=abc123', $viewAction->getAttribute('href'));
        $this->assertSame('_blank', $viewAction->getAttribute('target'));
        $this->assertStringNotContainsString('/edit', $viewAction->getAttribute('href'));
    }

    public function test_activity_title_has_no_link_specific_styles(): void
    {
        $css = file_get_contents(resource_path('css/admin/admin-pages.css'));

        $this->assertStringNotContainsString('.course-template-activity-title {', $css);
        $this->assertStringNotContainsString('.course-template-activity-title:hover', $css);
        $this->assertStringNotContainsString('.course-template-activity-title:focus-visible', $css);
    }

    public function test_a_quiz_activity_picks_a_published_quiz_the_actor_may_use(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $colleague = $this->createUser($customerId, 'teacher');
        $this->assignCreator($customerId, $templateId, $teacher->id);
        $quiz = fn (string $title, string $status, User $owner, int $tenant = 0): int => DB::table('core_assessment_quizzes')->insertGetId([
            'customer_id' => $tenant ?: $customerId, 'created_by' => $owner->id, 'title' => $title, 'assessment_type' => 'exam',
            'duration_minutes' => 45, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $own = $quiz('Own published', 'published', $teacher);
        $ownDraft = $quiz('Own draft', 'draft', $teacher);
        $ownArchived = $quiz('Own archived', 'archived', $teacher);
        $theirs = $quiz('Colleague published', 'published', $colleague);
        [$otherCustomer] = $this->createHierarchy('tenant-b', 'other');
        $foreign = $quiz('Foreign published', 'published', $this->createUser($otherCustomer, 'customer_admin'), $otherCustomer);

        // The picker lists only published Quizzes of the tenant; a teacher sees their own, an admin all.
        $picker = fn (string $area) => "https://tenant-a.localhost/{$area}/course-templates/{$templateId}/activities/quiz-picker";
        $ids = fn ($response) => collect($response->assertOk()->json('items'))->pluck('id')->all();
        $this->assertSame([$own], $ids($this->actingAs($teacher)->getJson($picker('teacher'))));
        $this->assertEqualsCanonicalizing([$own, $theirs], $ids($this->actingAs($admin)->getJson($picker('admin'))));
        $this->assertSame([$theirs], $ids($this->actingAs($admin)->getJson($picker('admin').'?keyword=Colleague')));
        $this->assertSame([], $ids($this->actingAs($admin)->getJson($picker('admin').'?assessment_type=exercise')));
        $this->actingAs($colleague)->getJson($picker('teacher'))->assertNotFound();

        // Saving accepts only a pickable Quiz.
        $url = $this->activityCollectionUrl('teacher', $templateId, $sectionId, $lessonId);
        $payload = fn (int $quizId) => $this->validActivityData(['title' => 'Quiz', 'activity_type' => 'quiz', 'assessment_quiz_id' => $quizId, 'completion_rule' => 'pass', 'completion_threshold' => 70]);
        foreach ([$ownDraft, $ownArchived, $theirs, $foreign, 999999] as $refused) {
            $this->actingAs($teacher)->post($url, $payload($refused))
                ->assertSessionHasErrors(['assessment_quiz_id' => __('lf.LF_course_template_activity_quiz_unavailable')]);
        }
        $this->actingAs($teacher)->post($url, $payload($own))->assertSessionHasNoErrors();
        $activityId = (int) DB::table('core_course_template_activities')->where('title', 'Quiz')->value('id');
        $this->assertSame($own, (int) DB::table('core_course_template_activities')->where('id', $activityId)->value('assessment_quiz_id'));

        // A stored Quiz that later stops being pickable stays when the Activity is saved unchanged.
        DB::table('core_assessment_quizzes')->where('id', $own)->update(['status' => 'archived']);
        $this->actingAs($teacher)->put("{$url}/{$activityId}", $payload($own))->assertSessionHasNoErrors();
        $this->actingAs($teacher)->put("{$url}/{$activityId}", $payload($theirs))->assertSessionHasErrors('assessment_quiz_id');

        // The form shows the chosen Quiz by name and opens the picker; nothing is typed.
        $html = $this->actingAs($teacher)->get("{$url}/{$activityId}/edit", ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
        $this->assertStringContainsString('data-quiz-picker', $html);
        $this->assertStringContainsString('Own published', $html);
        $this->assertStringNotContainsString('type="number" min="1" name="assessment_quiz_id"', $html);
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
                'activity_type' => 'document',
                'is_required' => 1,
                'completion_rule' => 'view',
                'is_preview' => 1,
                'estimated_duration_minutes' => 15,
            ]))
            ->assertRedirect(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit"
                ."?tab=structure#course-template-lesson-{$lessonId}-activities"
            );

        $activity = DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('title', 'Reading Text')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, (int) $activity->created_by);
        $this->assertSame(0, (int) $activity->duration_seconds);
        $this->assertSame(900, (int) $activity->estimated_duration_seconds);
        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $lessonId,
            'duration_seconds' => 900,
        ]);

        $this->actingAs($admin)
            ->put(
                "{$collectionUrl}/{$activity->id}",
                $this->validActivityData([
                    'title' => 'Updated Reading Text',
                    'sort_order' => 4,
                    'activity_type' => 'document',
                    'estimated_duration_minutes' => 5,
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
            'duration_seconds' => 0,
            'estimated_duration_seconds' => 300,
        ]);
        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $lessonId,
            'duration_seconds' => 300,
        ]);

        $this->actingAs($admin)
            ->delete("{$collectionUrl}/{$activity->id}")
            ->assertRedirect(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit"
                ."?tab=structure#course-template-lesson-{$lessonId}-activities"
            );

        $this->assertDatabaseMissing('core_course_template_activities', [
            'id' => $activity->id,
        ]);
        $this->assertDatabaseHas('core_course_template_lessons', [
            'id' => $lessonId,
            'duration_seconds' => 0,
        ]);
    }

    public function test_every_structure_change_moves_the_working_revision_that_publish_checks(): void
    {
        [$customerId, $templateId, $lessonId] = $this->createDirectHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $base = "https://tenant-a.localhost/admin/course-templates/{$templateId}";
        $revision = fn (): int => (int) DB::table('core_course_templates')->where('id', $templateId)->value('working_revision');
        $expected = $revision();
        $moved = function (string $step) use (&$expected, $revision): void {
            $this->assertSame(++$expected, $revision(), $step);
        };
        $unmoved = function (string $step) use (&$expected, $revision): void {
            $this->assertSame($expected, $revision(), $step);
        };
        $section = ['parent_section_id' => null, 'allows_lessons' => 1, 'title' => 'Part', 'description' => null, 'display_order' => 1];
        $lesson = ['title' => 'Extra', 'short_description' => null, 'description' => null, 'sort_order' => 2, 'is_preview' => 0,
            'lesson_type' => 'regular', 'unlock_rule' => 'none', 'unlock_after_lesson_id' => null, 'unlock_at' => null];
        $this->actingAs($admin);

        // The page the admin reviews before publishing shows this revision.
        $opened = $revision();

        $this->post("{$base}/lessons/{$lessonId}/activities", $this->validActivityData(['title' => 'One']))->assertRedirect();
        $moved('activity created');
        $this->post("{$base}/lessons/{$lessonId}/activities", $this->validActivityData(['title' => 'Two']))->assertRedirect();
        $moved('second activity created');
        [$one, $two] = DB::table('core_course_template_activities')->where('template_id', $templateId)->orderBy('id')->pluck('id')->all();
        $this->put("{$base}/lessons/{$lessonId}/activities/{$one}", $this->validActivityData(['title' => 'One, edited']))->assertSessionHasNoErrors();
        $moved('activity updated');
        $this->patchJson("{$base}/lessons/{$lessonId}/activities/reorder", ['activity_ids' => [$two, $one]])->assertOk();
        $moved('activities reordered');
        $this->patchJson("{$base}/lessons/{$lessonId}/activities/reorder", ['activity_ids' => [$two]])->assertStatus(409);
        $unmoved('a refused reorder changes nothing');
        $this->delete("{$base}/lessons/{$lessonId}/activities/{$two}")->assertRedirect();
        $moved('activity deleted');

        $this->post("{$base}/lessons", $lesson)->assertRedirect();
        $moved('lesson created');
        $extra = (int) DB::table('core_course_template_lessons')->where('template_id', $templateId)->where('title', 'Extra')->value('id');
        $this->put("{$base}/lessons/{$extra}", ['title' => 'Extra, edited'] + $lesson)->assertSessionHasNoErrors();
        $moved('lesson updated');
        $this->patchJson("{$base}/lessons/reorder", ['lesson_ids' => [$extra, $lessonId]])->assertOk();
        $moved('lessons reordered');
        $this->delete("{$base}/lessons/{$lessonId}")->assertSessionHasErrors();
        $unmoved('a lesson that still has an activity is not deleted');
        $this->delete("{$base}/lessons/{$extra}")->assertSessionHasNoErrors();
        $moved('lesson deleted');

        $this->post("{$base}/sections", $section)->assertRedirect();
        $moved('section created');
        $this->post("{$base}/sections", ['title' => 'Part two', 'display_order' => 2] + $section)->assertRedirect();
        $moved('second section created');
        [$first, $second] = DB::table('core_course_template_sections')->where('template_id', $templateId)->orderBy('id')->pluck('id')->all();
        $this->put("{$base}/sections/{$first}", ['title' => 'Part, edited'] + $section)->assertSessionHasNoErrors();
        $moved('section updated');
        $this->patchJson("{$base}/sections/reorder", ['section_ids' => [$second, $first]])->assertOk();
        $moved('sections reordered');
        $this->delete("{$base}/sections/{$second}")->assertSessionHasNoErrors();
        $moved('section deleted');

        // The page opened before these edits can no longer publish them unseen.
        $this->post("{$base}/publish", ['working_revision' => $opened])
            ->assertSessionHasErrors(['publish' => __('lf.LF_course_template_publish_stale')]);
        $this->assertDatabaseMissing('core_course_template_versions', ['template_id' => $templateId]);
    }

    public function test_a_date_based_unlock_typed_in_local_time_is_stored_in_utc_and_shown_back_locally(): void
    {
        [$customerId, $templateId] = $this->createDirectHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $base = "https://tenant-a.localhost/admin/course-templates/{$templateId}";
        $this->actingAs($admin);

        $this->post("{$base}/lessons", ['title' => 'Opens on a date', 'short_description' => null, 'description' => null,
            'sort_order' => 2, 'is_preview' => 0, 'lesson_type' => 'regular', 'unlock_rule' => 'date_based',
            'unlock_after_lesson_id' => null, 'unlock_at' => '2026-12-01T08:00'])->assertSessionHasNoErrors();
        $lesson = DB::table('core_course_template_lessons')->where('template_id', $templateId)->where('title', 'Opens on a date')->first();

        // 08:00 in Asia/Ho_Chi_Minh is 01:00 UTC, the instant the runtime compares (LF-Core-Course, date_based).
        $this->assertSame('2026-12-01 01:00:00', Carbon::parse($lesson->unlock_at)->format('Y-m-d H:i:s'));
        $this->get("{$base}/lessons/{$lesson->id}/edit", ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee('name="unlock_at" value="2026-12-01T08:00"', false);
    }

    public function test_an_archived_template_takes_no_structure_or_teacher_change(): void
    {
        [$customerId, $templateId, $lessonId] = $this->createDirectHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $assigned = $this->createUser($customerId, 'teacher');
        $base = "https://tenant-a.localhost/admin/course-templates/{$templateId}";
        $this->actingAs($admin);
        $this->post("{$base}/lessons/{$lessonId}/activities", $this->validActivityData(['title' => 'Kept']))->assertRedirect();
        $activityId = (int) DB::table('core_course_template_activities')->where('template_id', $templateId)->value('id');
        $this->post("{$base}/teachers", ['teacher_id' => $assigned->id, 'role' => 'primary'])->assertSessionHasNoErrors();
        $assignmentId = (int) DB::table('core_course_template_teachers')->where('template_id', $templateId)->value('id');
        DB::table('core_course_templates')->where('id', $templateId)->update(['status' => 'archived']);
        $before = DB::table('core_course_templates')->where('id', $templateId)->value('working_revision');
        $readOnly = ['template' => __('lf.LF_course_template_status_archived_readonly')];
        $section = ['parent_section_id' => null, 'allows_lessons' => 1, 'title' => 'Part', 'description' => null, 'display_order' => 1];
        $lesson = ['title' => 'Extra', 'short_description' => null, 'description' => null, 'sort_order' => 2, 'is_preview' => 0,
            'lesson_type' => 'regular', 'unlock_rule' => 'none', 'unlock_after_lesson_id' => null, 'unlock_at' => null];

        $this->post("{$base}/lessons/{$lessonId}/activities", $this->validActivityData(['title' => 'New']))->assertSessionHasErrors($readOnly);
        $this->put("{$base}/lessons/{$lessonId}/activities/{$activityId}", $this->validActivityData(['title' => 'Edited']))->assertSessionHasErrors($readOnly);
        $this->patchJson("{$base}/lessons/{$lessonId}/activities/reorder", ['activity_ids' => [$activityId]])->assertStatus(422);
        $this->delete("{$base}/lessons/{$lessonId}/activities/{$activityId}")->assertSessionHasErrors($readOnly);
        $this->post("{$base}/lessons", $lesson)->assertSessionHasErrors($readOnly);
        $this->put("{$base}/lessons/{$lessonId}", ['title' => 'Edited'] + $lesson)->assertSessionHasErrors($readOnly);
        $this->post("{$base}/sections", $section)->assertSessionHasErrors($readOnly);
        $this->post("{$base}/teachers", ['teacher_id' => $teacher->id, 'role' => 'assistant'])->assertSessionHasErrors($readOnly);
        $this->put("{$base}/teachers/{$assignmentId}", ['role' => 'assistant'])->assertSessionHasErrors($readOnly);

        $this->assertSame(['Kept'], DB::table('core_course_template_activities')->where('template_id', $templateId)->pluck('title')->all());
        $this->assertSame(1, DB::table('core_course_template_lessons')->where('template_id', $templateId)->count());
        $this->assertSame(0, DB::table('core_course_template_sections')->where('template_id', $templateId)->count());
        $this->assertSame(['primary'], DB::table('core_course_template_teachers')->where('template_id', $templateId)->pluck('role')->all());
        $this->assertSame($before, DB::table('core_course_templates')->where('id', $templateId)->value('working_revision'));

        // Taking a teacher's access away stays possible.
        $this->delete("{$base}/teachers/{$assignmentId}")->assertSessionHasNoErrors();
        $this->assertSame('inactive', DB::table('core_course_template_teachers')->where('id', $assignmentId)->value('status'));
    }

    public function test_an_archived_template_starts_no_transcription_or_board_preview(): void
    {
        config(['media.disk' => 'media_local', 'media.bucket' => 'lf-test-media']);
        Storage::fake('media_local');
        [$customerId, $templateId, $lessonId] = $this->createDirectHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $base = "https://tenant-a.localhost/admin/course-templates/{$templateId}";
        $this->actingAs($admin);
        $this->post("{$base}/lessons/{$lessonId}/activities", $this->validActivityData(['title' => 'Audio']))->assertRedirect();
        $this->post("{$base}/lessons/{$lessonId}/activities", $this->validActivityData(['title' => 'Document']))->assertRedirect();
        [$audioId, $documentId] = DB::table('core_course_template_activities')->where('template_id', $templateId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        DB::table('core_course_template_activities')->where('id', $audioId)->update(['activity_type' => 'audio']);
        DB::table('core_course_template_activities')->where('id', $documentId)->update(['activity_type' => 'document']);
        TenantContext::set((object) ['id' => $customerId]);
        $media = app(MediaService::class);
        $pdf = $media->uploadMedia(UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\nnotes\n%%EOF"),
            ['file_type' => 'document', 'module' => 'course', 'entity_type' => 'activities', 'entity_id' => $documentId, 'purpose' => 'document'], $admin->id);
        $media->attachUsage((int) $pdf->id, 'course_activity', $documentId, 'document');
        DB::table('core_course_templates')->where('id', $templateId)->update(['status' => 'archived']);
        $readOnly = ['template' => __('lf.LF_course_template_status_archived_readonly')];

        $this->post("{$base}/lessons/{$lessonId}/activities/{$audioId}/initialize-transcription", ['processing_locales' => ['vi']])
            ->assertSessionHasErrors($readOnly);
        $this->post("{$base}/lessons/{$lessonId}/activities/{$documentId}/board-preview")->assertSessionHasErrors($readOnly);
        $this->assertSame(0, DB::table('media_processing_jobs')->whereIn('job_type', ['speech_to_text', 'transcode'])->count());
    }

    public function test_admin_and_teacher_can_create_activities_for_direct_lessons(): void
    {
        [$customerId, $templateId, $lessonId] =
            $this->createDirectHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->update(['created_by' => $teacher->id]);
        $this->assignCreator($customerId, $templateId, $teacher->id);

        foreach ([
            [$admin, 'admin', 'Admin Direct Activity'],
            [$teacher, 'teacher', 'Teacher Direct Activity'],
        ] as [$user, $area, $title]) {
            $collectionUrl = $this->directActivityCollectionUrl(
                $area,
                $templateId,
                $lessonId
            );

            $this->actingAs($user)
                ->withHeaders($this->ajaxHeaders())
                ->get($collectionUrl.'/create')
                ->assertOk()
                ->assertSeeText('Direct Lesson');
            $this->actingAs($user)->withoutHeader('X-Requested-With')->get($collectionUrl.'/create')->assertNotFound();

            $this->actingAs($user)
                ->post(
                    $collectionUrl,
                    $this->validActivityData(['title' => $title])
                )
                ->assertRedirect(
                    "https://tenant-a.localhost/{$area}/course-templates/"
                    ."{$templateId}/edit"
                    ."?tab=structure#course-template-lesson-{$lessonId}-activities"
                );

            $this->assertDatabaseHas('core_course_template_activities', [
                'customer_id' => $customerId,
                'template_id' => $templateId,
                'template_lesson_id' => $lessonId,
                'title' => $title,
                'created_by' => $user->id,
            ]);
        }

        $this->assertDatabaseCount('core_course_template_sections', 0);
    }

    public function test_activity_order_is_automatic_and_scoped_by_lesson(): void
    {
        [$customerId, $templateId, $sectionId, $firstLessonId] =
            $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $secondLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Second Scoped Lesson'
        );
        $this->createActivity(
            $customerId,
            $templateId,
            $firstLessonId,
            'Activity Gap',
            8
        );

        foreach ([
            [$firstLessonId, 'Automatic First Activity', 9],
            [$secondLessonId, 'Automatic Second Activity', 1],
        ] as [$lessonId, $title, $expectedOrder]) {
            $data = $this->validActivityData(['title' => $title]);
            unset($data['sort_order']);
            $this->actingAs($admin)->post(
                $this->activityCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId,
                    $lessonId
                ),
                $data
            )->assertSessionDoesntHaveErrors();

            $this->assertDatabaseHas('core_course_template_activities', [
                'template_id' => $templateId,
                'template_lesson_id' => $lessonId,
                'title' => $title,
                'sort_order' => $expectedOrder,
            ]);
        }
    }

    public function test_flat_and_sectioned_activity_routes_enforce_lesson_location(): void
    {
        [$customerId, $templateId, $sectionId, $sectionedLessonId] =
            $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $directLessonId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Direct Route Lesson'
        );

        $this->actingAs($admin)
            ->get(
                $this->directActivityCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionedLessonId
                ).'/create'
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(
                $this->activityCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId,
                    $directLessonId
                ).'/create'
            )
            ->assertNotFound();
    }

    public function test_teacher_can_create_update_and_delete_an_activity(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        $teacher = $this->createUser($customerId, 'teacher');
        DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('id', $templateId)
            ->update(['created_by' => $teacher->id]);
        $this->assignCreator($customerId, $templateId, $teacher->id);
        $collectionUrl = $this->activityCollectionUrl(
            'teacher',
            $templateId,
            $sectionId,
            $lessonId
        );

        $this->actingAs($teacher)
            ->post($collectionUrl, $this->validActivityData([
                'title' => 'Practice Link',
                'activity_type' => 'embedded_video',
                'external_video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
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
                    'activity_type' => 'embedded_video',
                    'external_video_url' => 'https://youtu.be/9bZkp7q19f0',
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
                'is_required',
                'completion_rule',
                'is_preview',
                'unlock_rule',
            ]);

        $this->actingAs($admin)
            ->post($url, $this->validActivityData([
                'activity_type' => 'embedded_video',
                'external_video_url' => null,
            ]))
            ->assertSessionHasErrors('external_video_url');

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

        $this->actingAs($admin)
            ->post($url, $this->validActivityData([
                'unlock_rule' => 'date_based',
                'unlock_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ]))
            ->assertSessionHasErrors('unlock_rule');

        $this->assertDatabaseCount('core_course_template_activities', 0);
    }

    public function test_activity_description_and_sort_order_are_bounded_to_their_columns(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $url = $this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId);

        $this->actingAs($admin)
            ->post($url, $this->validActivityData([
                'description' => str_repeat('ạ', 20001),
                'sort_order' => 1000001,
            ]))
            ->assertSessionHasErrors(['description', 'sort_order']);
        $this->actingAs($admin)
            ->post($url, $this->validActivityData([
                'description' => str_repeat('ạ', 20000),
                'sort_order' => 1000000,
            ]))
            ->assertSessionDoesntHaveErrors(['description', 'sort_order']);
    }

    public function test_final_activity_content_fields_are_stored(): void
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
                'completion_rule' => 'watch_percent',
                'completion_threshold' => 80,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_template_activities', [
            'customer_id' => $customerId,
            'template_lesson_id' => $lessonId,
            'activity_type' => 'video',
            'duration_seconds' => 0,
            'completion_rule' => 'watch_percent',
            'completion_threshold' => 80,
        ]);
    }

    public function test_live_class_accepts_attendance_or_manual_completion_rules(): void
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
                'title' => 'Live attendance',
                'activity_type' => 'live_class',
                'completion_rule' => 'join',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_template_activities', [
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_lesson_id' => $lessonId,
            'activity_type' => 'live_class',
            'completion_rule' => 'join',
        ]);
    }

    public function test_learning_availability_supports_anytime_or_multiple_session_phases(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $url = $this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId);

        $this->actingAs($admin)
            ->post($url, $this->validActivityData([
                'title' => 'Before and during',
                'learning_phases_present' => 1,
                'learning_phases' => ['before_session', 'during_session'],
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_template_activities', [
            'customer_id' => $customerId,
            'title' => 'Before and during',
            'available_anytime' => 0,
            'available_before_session' => 1,
            'available_during_session' => 1,
            'available_after_session' => 0,
        ]);

        $this->actingAs($admin)
            ->post($url, $this->validActivityData([
                'title' => 'Invalid availability',
                'learning_phases_present' => 1,
                'learning_phases' => ['anytime', 'after_session'],
            ]))
            ->assertSessionHasErrors('learning_phases');

        $this->actingAs($admin)
            ->post($url, $this->validActivityData([
                'title' => 'Missing availability',
                'learning_phases_present' => 1,
                'learning_phases' => [],
            ]))
            ->assertSessionHasErrors('learning_phases');
    }

    public function test_a_live_class_takes_any_session_phase_but_never_anytime(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $url = $this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId);
        $live = fn (array $overrides) => $this->validActivityData([
            'activity_type' => 'live_class',
            'completion_rule' => 'join',
        ] + $overrides);
        // A lesson holds one live session, so each case starts from an empty lesson.
        $clear = fn () => DB::table('core_course_template_activities')->where('template_lesson_id', $lessonId)->delete();

        // Any session phase is kept as chosen (e.g. after the session, to watch the replay).
        $this->actingAs($admin)
            ->post($url, $live(['title' => 'Live after', 'learning_phases_present' => 1, 'learning_phases' => ['during_session', 'after_session']]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_template_activities', [
            'customer_id' => $customerId, 'title' => 'Live after',
            'available_anytime' => 0, 'available_before_session' => 0, 'available_during_session' => 1, 'available_after_session' => 1,
        ]);
        $clear();

        // Anytime is refused, alone or with a session phase.
        foreach ([['anytime'], ['anytime', 'during_session']] as $index => $phases) {
            $this->actingAs($admin)
                ->post($url, $live(['title' => 'Live anytime '.$index, 'learning_phases_present' => 1, 'learning_phases' => $phases]))
                ->assertSessionHasErrors('learning_phases');
            $this->assertDatabaseMissing('core_course_template_activities', ['title' => 'Live anytime '.$index]);
        }

        // Without a declaration a live class defaults to during the session (other types keep defaulting to anytime).
        $request = $live(['title' => 'Live default']);
        unset($request['learning_phases'], $request['learning_phases_present']);
        $this->actingAs($admin)->post($url, $request)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_template_activities', [
            'title' => 'Live default', 'available_anytime' => 0, 'available_during_session' => 1,
        ]);
    }

    public function test_a_lesson_holds_one_live_session_and_the_default_link_is_no_longer_entered(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $url = $this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId);
        $live = fn (array $overrides) => $this->validActivityData([
            'activity_type' => 'live_class', 'completion_rule' => 'join',
        ] + $overrides);

        // A submitted link is ignored: the meeting link is chosen when the class is scheduled.
        $this->actingAs($admin)
            ->post($url, $live(['title' => 'Live one', 'live_class_url' => 'https://meet.google.com/abc-defg-hij']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_template_activities', [
            'title' => 'Live one', 'activity_type' => 'live_class', 'live_class_url' => null,
        ]);

        // A second live session in the same lesson is refused.
        $this->actingAs($admin)->post($url, $live(['title' => 'Live two']))
            ->assertSessionHasErrors(['activity_type' => __('lf.LF_course_template_activity_live_class_one_per_lesson')]);
        $this->assertDatabaseMissing('core_course_template_activities', ['title' => 'Live two']);
        // Turning another Activity of the lesson into a live session is refused too.
        $documentId = $this->createActivity($customerId, $templateId, $lessonId, 'Reading', 1);
        $this->actingAs($admin)->put("{$url}/{$documentId}", $live(['title' => 'Reading']))
            ->assertSessionHasErrors(['activity_type' => __('lf.LF_course_template_activity_live_class_one_per_lesson')]);
        $this->assertDatabaseHas('core_course_template_activities', ['id' => $documentId, 'activity_type' => 'document']);

        // Another lesson takes its own live session.
        $otherLessonId = $this->createLesson($customerId, $templateId, $sectionId, 'Other lesson');
        $this->actingAs($admin)
            ->post($this->activityCollectionUrl('admin', $templateId, $sectionId, $otherLessonId), $live(['title' => 'Live other']))
            ->assertSessionHasNoErrors();

        // In a class course only the lesson without a live session is labelled self-study.
        $this->createLesson($customerId, $templateId, $sectionId, 'Reading lesson');
        $html = $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure")
            ->assertOk()->getContent();
        $label = preg_quote(__('lf.LF_course_template_lesson_self_study'), '/');
        $this->assertSame(1, preg_match_all('/>\s*'.$label.'\s*<\/span>/u', $html));

        // A link stored before the change is kept when the live session is edited, and dropped when its type changes.
        $liveId = (int) DB::table('core_course_template_activities')->where('title', 'Live one')->value('id');
        DB::table('core_course_template_activities')->where('id', $liveId)->update(['live_class_url' => 'https://meet.google.com/old-link']);
        $this->actingAs($admin)
            ->put("{$url}/{$liveId}", $live(['title' => 'Live renamed', 'live_class_url' => 'https://meet.google.com/new-link']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_template_activities', [
            'id' => $liveId, 'title' => 'Live renamed', 'live_class_url' => 'https://meet.google.com/old-link',
        ]);
        $this->actingAs($admin)->put("{$url}/{$liveId}", $this->validActivityData(['title' => 'Now a document']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_template_activities', [
            'id' => $liveId, 'activity_type' => 'document', 'live_class_url' => null,
        ]);
    }

    public function test_learning_availability_is_bounded_by_the_template_course_type(): void
    {
        // ADR-0022: self-paced is only anytime, scheduled only session phases, blended both; enforced on the server.
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $url = $this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId);
        $template = DB::table('core_course_templates')->where('id', $templateId);
        $phases = fn (array $phases) => ['learning_phases_present' => 1, 'learning_phases' => $phases];

        $template->update(['course_type' => 'self_paced']);
        $this->actingAs($admin)->post($url, $this->validActivityData(['title' => 'Self any'] + $phases(['anytime'])))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post($url, $this->validActivityData(['title' => 'Self session'] + $phases(['during_session'])))
            ->assertSessionHasErrors(['learning_phases' => __('lf.LF_course_type_phases_not_allowed_self_paced')]);
        $this->actingAs($admin)->post($url, ['live_class_url' => null] + $this->validActivityData([
            'title' => 'Self live', 'activity_type' => 'live_class', 'completion_rule' => 'join',
        ] + $phases(['during_session'])))->assertSessionHasErrors(['activity_type' => __('lf.LF_course_type_live_class_not_allowed')]);
        // Without a declaration a self-paced Activity is anytime.
        $this->actingAs($admin)->post($url, $this->validActivityData(['title' => 'Self default']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('core_course_template_activities', ['title' => 'Self default', 'available_anytime' => 1, 'available_during_session' => 0]);

        // ADR-0022 Amendment 1.1: a legacy scheduled Template reads as class-based and takes anytime too.
        $template->update(['course_type' => 'scheduled']);
        $this->actingAs($admin)->post($url, $this->validActivityData(['title' => 'Sched any'] + $phases(['anytime'])))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post($url, $this->validActivityData(['title' => 'Sched before'] + $phases(['before_session', 'after_session'])))->assertSessionHasNoErrors();

        $template->update(['course_type' => 'blended']);
        $this->actingAs($admin)->post($url, $this->validActivityData(['title' => 'Blend any'] + $phases(['anytime'])))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post($url, $this->validActivityData(['title' => 'Blend session'] + $phases(['during_session'])))->assertSessionHasNoErrors();

        foreach (['Self session', 'Self live'] as $rejected) {
            $this->assertDatabaseMissing('core_course_template_activities', ['title' => $rejected]);
        }
    }

    public function test_a_blended_course_keeps_the_mode_cards_and_explains_the_session_times(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        DB::table('core_course_templates')->where('id', $templateId)->update(['course_type' => 'blended']);

        $this->actingAs($admin)
            ->get($this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId).'/create', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('name="learning_mode"', false)
            ->assertSee('value="anytime"', false)
            ->assertSee('lf-info-tip--lines', false)
            ->assertSeeText(__('lf.LF_course_template_activity_learning_mode_anytime_help'))
            ->assertSeeText(__('lf.LF_course_template_activity_phase_choose'))
            ->assertDontSee('ui-option-cards__detail--solo', false);
    }

    public function test_a_legacy_scheduled_course_gets_the_class_based_form(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        DB::table('core_course_templates')->where('id', $templateId)->update(['course_type' => 'scheduled']);

        $this->actingAs($admin)
            ->get($this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId).'/create', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('name="learning_mode"', false)
            ->assertSee('value="anytime"', false)
            ->assertDontSee('ui-option-cards__detail--solo', false);
    }

    public function test_learning_availability_checkboxes_render_on_activity_form_and_detail(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $url = $this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId);

        $this->actingAs($admin)->withHeaders($this->ajaxHeaders())->get("{$url}/create")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_learning_availability'))
            ->assertSee('name="learning_phases[]"', false)
            ->assertSee('value="anytime"', false)
            ->assertSee('value="before_session"', false)
            ->assertSee('value="during_session"', false)
            ->assertSee('value="after_session"', false);

        $this->actingAs($admin)
            ->post($url, $this->validActivityData([
                'title' => 'After class resource',
                'learning_phases_present' => 1,
                'learning_phases' => ['after_session'],
            ]))
            ->assertRedirect();

        $activityId = (int) DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('title', 'After class resource')
            ->value('id');

        $this->actingAs($admin)->withHeaders($this->ajaxHeaders())->get("{$url}/{$activityId}")
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_learning_availability_after_session'));
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

        $this->actingAs($admin)
            ->get(
                $this->activityCollectionUrl(
                    'admin',
                    $otherTemplateId,
                    $otherSectionId,
                    $otherLessonId
                )."/{$otherActivityId}"
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
            ->assertSeeText('Thêm hoạt động')
            ->assertSee('data-ui-row-delete', false)
            ->assertSee('data-confirm-title="Xoá hoạt động?"', false)
            ->assertSee('data-confirm-message="Hoạt động «UI Activity» sẽ bị xoá vĩnh viễn cùng tệp đính kèm và không thể hoàn tác."', false)
            ->assertDontSeeText('Bạn có chắc chắn muốn xóa Activity này không?');

        $response = $this->actingAs($admin)
            ->withHeaders($this->ajaxHeaders())
            ->get(
                $this->activityCollectionUrl(
                    'admin',
                    $templateId,
                    $sectionId,
                    $lessonId
                ).'/create'
            )
            ->assertOk();

        $response
            ->assertSee('completionRuleLabelsByType', false)
            ->assertSee('syncCompletionOptions', false);
        $this->assertSame(
            'Nghe nội dung',
            __('lf.LF_course_template_activity_common_completion_audio_view')
        );
        $this->assertSame(
            'Phần trăm đã nghe',
            __('lf.LF_course_template_activity_common_completion_audio_watch_percent')
        );

        foreach ([
            'title',
            'completion_rule',
            'is_preview',
            'unlock_rule',
        ] as $field) {
            $this->assertSame(
                1,
                $this->requiredIndicatorCount($response->getContent(), $field)
            );
        }

        $this->assertSame(
            0,
            $this->requiredIndicatorCount(
                $response->getContent(),
                'sort_order'
            )
        );
    }

    public function test_admin_and_teacher_reorder_the_activities_of_one_lesson_on_both_routes(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        DB::table('core_course_templates')->where('id', $templateId)->update(['created_by' => $teacher->id]);
        $this->assignCreator($customerId, $templateId, $teacher->id);
        $a = $this->createActivity($customerId, $templateId, $lessonId, 'A', 1);
        $b = $this->createActivity($customerId, $templateId, $lessonId, 'B', 2);
        $c = $this->createActivity($customerId, $templateId, $lessonId, 'C', 3);
        $otherLessonId = $this->createLesson($customerId, $templateId, $sectionId, 'Other');
        $other = $this->createActivity($customerId, $templateId, $otherLessonId, 'Other', 7);

        $this->actingAs($admin)
            ->patchJson($this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId).'/reorder', ['activity_ids' => [$c, $a, $b]])
            ->assertOk()
            ->assertJsonPath('message', __('lf.LF_course_template_activity_reorder_saved'));
        $this->assertActivityOrders([$c => 1, $a => 2, $b => 3, $other => 7]);

        $this->actingAs($teacher)
            ->patchJson($this->activityCollectionUrl('teacher', $templateId, $sectionId, $lessonId).'/reorder', ['activity_ids' => [$b, $c, $a]])
            ->assertOk();
        $this->assertActivityOrders([$b => 1, $c => 2, $a => 3, $other => 7]);
    }

    public function test_activities_of_a_direct_lesson_reorder_on_the_direct_route(): void
    {
        [$customerId, $templateId, $lessonId] = $this->createDirectHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $x = $this->createActivity($customerId, $templateId, $lessonId, 'X', 1);
        $y = $this->createActivity($customerId, $templateId, $lessonId, 'Y', 2);

        $this->actingAs($admin)
            ->patchJson($this->directActivityCollectionUrl('admin', $templateId, $lessonId).'/reorder', ['activity_ids' => [$y, $x]])
            ->assertOk();
        $this->assertActivityOrders([$y => 1, $x => 2]);
    }

    public function test_activity_reorder_ignores_prerequisites_because_the_order_is_display_only(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $a = $this->createActivity($customerId, $templateId, $lessonId, 'A', 1);
        $b = $this->createActivity($customerId, $templateId, $lessonId, 'B', 2, $a);

        $this->actingAs($admin)
            ->patchJson($this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId).'/reorder', ['activity_ids' => [$b, $a]])
            ->assertOk();
        $this->assertActivityOrders([$b => 1, $a => 2]);
        $this->assertDatabaseHas('core_course_template_activities', ['id' => $b, 'unlock_after_activity_id' => $a]);
    }

    public function test_activity_reorder_refuses_a_list_that_is_not_exactly_the_lesson(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        [$otherCustomerId, $otherTemplateId, , $otherTenantLessonId] = $this->createHierarchy('tenant-b', 'other');
        $admin = $this->createUser($customerId, 'customer_admin');
        $a = $this->createActivity($customerId, $templateId, $lessonId, 'A', 1);
        $b = $this->createActivity($customerId, $templateId, $lessonId, 'B', 2);
        $siblingLessonId = $this->createLesson($customerId, $templateId, $sectionId, 'Sibling');
        $sibling = $this->createActivity($customerId, $templateId, $siblingLessonId, 'Sibling', 1);
        $foreign = $this->createActivity($otherCustomerId, $otherTemplateId, $otherTenantLessonId, 'Foreign', 1);
        $url = $this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId).'/reorder';

        foreach ([[$b], [$b, $a, $sibling], [$b, $a, $foreign], [$b, $sibling]] as $wrong) {
            $this->actingAs($admin)->patchJson($url, ['activity_ids' => $wrong])
                ->assertStatus(409)
                ->assertJsonPath('message', __('lf.LF_course_template_activity_reorder_stale'));
        }
        $this->actingAs($admin)->patchJson($url, ['activity_ids' => [$a, $a]])->assertUnprocessable();
        $this->actingAs($admin)->patchJson($url, ['activity_ids' => []])->assertUnprocessable();
        $this->actingAs($admin)->patch($url, ['activity_ids' => [$b, $a]])->assertNotFound();

        $this->assertActivityOrders([$a => 1, $b => 2, $sibling => 1, $foreign => 1]);
    }

    public function test_activity_reorder_respects_tenant_role_assignment_and_hierarchy(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        [$otherCustomerId, $otherTemplateId, $otherSectionId, $otherLessonId] = $this->createHierarchy('tenant-b', 'other');
        $admin = $this->createUser($customerId, 'customer_admin');
        $unassignedTeacher = $this->createUser($customerId, 'teacher');
        $student = $this->createUser($customerId, 'student');
        $a = $this->createActivity($customerId, $templateId, $lessonId, 'A', 1);
        $b = $this->createActivity($customerId, $templateId, $lessonId, 'B', 2);
        $x = $this->createActivity($otherCustomerId, $otherTemplateId, $otherLessonId, 'X', 1);
        $y = $this->createActivity($otherCustomerId, $otherTemplateId, $otherLessonId, 'Y', 2);
        $secondSectionId = $this->createSection($customerId, $templateId, 'Second');
        $payload = ['activity_ids' => [$b, $a]];
        $url = fn (string $area): string => $this->activityCollectionUrl($area, $templateId, $sectionId, $lessonId).'/reorder';

        $this->patchJson($url('admin'), $payload)->assertUnauthorized();
        $this->actingAs($student)->patchJson($url('admin'), $payload)->assertForbidden();
        $this->actingAs($student)->patchJson($url('teacher'), $payload)->assertForbidden();
        $this->actingAs($unassignedTeacher)->patchJson($url('teacher'), $payload)->assertNotFound();
        $this->actingAs($unassignedTeacher)->patchJson($url('admin'), $payload)->assertForbidden();
        $this->actingAs($admin)
            ->patchJson($this->activityCollectionUrl('admin', $otherTemplateId, $otherSectionId, $otherLessonId).'/reorder', ['activity_ids' => [$y, $x]])
            ->assertNotFound();
        // A Lesson reached through a Section it is not in, or through the direct route while it has a Section.
        $this->actingAs($admin)
            ->patchJson($this->activityCollectionUrl('admin', $templateId, $secondSectionId, $lessonId).'/reorder', $payload)
            ->assertNotFound();
        $this->actingAs($admin)
            ->patchJson($this->directActivityCollectionUrl('admin', $templateId, $lessonId).'/reorder', $payload)
            ->assertNotFound();

        $this->assertActivityOrders([$a => 1, $b => 2, $x => 1, $y => 2]);
    }

    public function test_activity_form_has_no_order_field_a_new_one_goes_last_and_an_edit_keeps_the_place(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $a = $this->createActivity($customerId, $templateId, $lessonId, 'A', 4);
        $this->createActivity($customerId, $templateId, $lessonId, 'B', 9);
        $collection = $this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId);

        foreach (["{$collection}/create", "{$collection}/{$a}/edit"] as $form) {
            $this->actingAs($admin)->withHeaders($this->ajaxHeaders())->get($form)
                ->assertOk()
                ->assertDontSee('name="sort_order"', false);
        }

        $data = array_diff_key($this->validActivityData(['estimated_duration_minutes' => 5]), ['sort_order' => true]);
        $this->actingAs($admin)->post($collection, array_merge($data, ['title' => 'New']))->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('core_course_template_activities', ['title' => 'New', 'sort_order' => 10]);

        $this->actingAs($admin)->put("{$collection}/{$a}", array_merge($data, ['title' => 'A renamed']))->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('core_course_template_activities', ['id' => $a, 'title' => 'A renamed', 'sort_order' => 4]);
    }

    public function test_the_activity_handle_appears_only_on_a_lesson_with_two_activities(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->createActivity($customerId, $templateId, $lessonId, 'First', 1);
        $edit = "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure";
        $reorderUrl = $this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId).'/reorder';

        $this->actingAs($admin)->get($edit)->assertOk()->assertDontSee($reorderUrl, false);

        $this->createActivity($customerId, $templateId, $lessonId, 'Second', 2);
        $this->actingAs($admin)->get($edit)->assertOk()
            ->assertSee($reorderUrl, false)
            ->assertSee(__('lf.LF_course_template_activity_reorder_handle', ['title' => 'Second']));
    }

    public function test_template_edit_shows_the_correct_add_activity_action_for_each_lesson(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        $secondLessonId = $this->createLesson(
            $customerId,
            $templateId,
            $sectionId,
            'Lesson Two'
        );
        $admin = $this->createUser($customerId, 'customer_admin');

        $response = $this->actingAs($admin)
            ->get(
                'https://tenant-a.localhost/admin/course-templates/'
                ."{$templateId}/edit"
            )
            ->assertOk()
            ->assertDontSeeText('Trước buổi học')
            ->assertSeeText('Thêm hoạt động')
            ->assertSeeText('Chưa có hoạt động.')
            ->assertDontSeeText('Chưa có hoạt động học tập nào.');

        $this->assertLessonHasActivityCreateAction(
            $response->getContent(),
            'Lesson default',
            $this->activityCollectionUrl(
                'admin',
                $templateId,
                $sectionId,
                $lessonId
            ).'/create'
        );
        $this->assertLessonHasActivityCreateAction(
            $response->getContent(),
            'Lesson Two',
            $this->activityCollectionUrl(
                'admin',
                $templateId,
                $sectionId,
                $secondLessonId
            ).'/create'
        );
    }

    public function test_create_and_edit_forms_show_the_documented_activity_type_selector(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] =
            $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $activityId = $this->createActivity(
            $customerId,
            $templateId,
            $lessonId,
            'Quiz Activity'
        );
        DB::table('core_course_template_activities')
            ->where('id', $activityId)
            ->update(['activity_type' => 'quiz']);
        $collectionUrl = $this->activityCollectionUrl(
            'admin',
            $templateId,
            $sectionId,
            $lessonId
        );

        $createResponse = $this->actingAs($admin)
            ->withHeaders($this->ajaxHeaders())
            ->get("{$collectionUrl}/create")
            ->assertOk()
            ->assertSeeText('Loại hoạt động')
            ->assertSee('placeholder="Nhập tên hoạt động"', false)
            ->assertSee('placeholder="Nhập mô tả hoạt động"', false)
            ->assertSee('placeholder="Ví dụ: 15"', false)
            ->assertSee('Chọn Activity điều kiện');

        $this->assertActivityTypeSelector($createResponse->getContent());
        $this->assertSame(
            3,
            $this->xpath($createResponse->getContent())->query('//*[@data-ui-file-slot]')->length
        );

        $editResponse = $this->actingAs($admin)
            ->get("{$collectionUrl}/{$activityId}/edit")
            ->assertOk()
            ->assertSeeText('Loại hoạt động');

        $this->assertActivityTypeSelector(
            $editResponse->getContent(),
            'quiz'
        );
        $this->assertSame(
            3,
            $this->xpath($editResponse->getContent())->query('//*[@data-ui-file-slot]')->length
        );

        app()->setLocale('en');
        $this->actingAs($admin)
            ->get("{$collectionUrl}/create")
            ->assertOk()
            ->assertSee('placeholder="Enter activity name"', false)
            ->assertSee('placeholder="Enter activity description"', false)
            ->assertSee('Select a prerequisite activity');
    }

    public function test_popup_save_answers_json_with_the_toast_redirect_and_keeps_the_background_notice(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        DB::table('core_course_templates')->where('id', $templateId)->update(['created_by' => $teacher->id]);
        $this->assignCreator($customerId, $templateId, $teacher->id);

        foreach ([[$admin, 'admin'], [$teacher, 'teacher']] as [$user, $area]) {
            $url = $this->activityCollectionUrl($area, $templateId, $sectionId, $lessonId);
            $structure = "https://tenant-a.localhost/{$area}/course-templates/{$templateId}/edit"
                ."?tab=structure#course-template-lesson-{$lessonId}-activities";

            $this->actingAs($user)
                ->postJson($url, $this->validActivityData(['title' => "{$area} popup activity"]))
                ->assertCreated()
                ->assertJson(['message' => __('lf.LF_course_template_activity_common_created'), 'redirect' => $structure])
                ->assertSessionHas('success', __('lf.LF_course_template_activity_common_created'));
            $activityId = (int) DB::table('core_course_template_activities')->where('title', "{$area} popup activity")->value('id');

            $this->actingAs($user)
                ->putJson("{$url}/{$activityId}", $this->validActivityData(['title' => "{$area} popup renamed"]))
                ->assertOk()
                ->assertJson(['message' => __('lf.LF_course_template_activity_common_updated'), 'redirect' => $structure]);
            $this->assertDatabaseHas('core_course_template_activities', ['id' => $activityId, 'title' => "{$area} popup renamed"]);

            $this->actingAs($user)
                ->postJson($url, $this->validActivityData(['title' => '', 'completion_rule' => 'bogus']))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['title', 'completion_rule']);
        }

        // Queued background work is announced next to the success toast, in JSON mode too.
        $this->actingAs($admin)
            ->postJson($this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId), $this->validActivityData([
                'title' => 'Audio with transcription',
                'activity_type' => 'audio',
                'completion_rule' => 'view',
                'speech_to_text' => 1,
                'processing_locales' => ['vi'],
                'activity_audio_file' => UploadedFile::fake()->create('lesson.mp3', 32, 'audio/mpeg'),
            ]))
            ->assertCreated()
            ->assertSessionHas('info', __('lf.LF_course_template_activity_stt_queued_notice'));
    }

    public function test_delete_answers_json_and_an_activity_that_another_unlocks_after_is_blocked_with_a_disabled_button(): void
    {
        [$customerId, $templateId, $sectionId, $lessonId] = $this->createHierarchy();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        DB::table('core_course_templates')->where('id', $templateId)->update(['created_by' => $teacher->id]);
        $this->assignCreator($customerId, $templateId, $teacher->id);
        $firstId = $this->createActivity($customerId, $templateId, $lessonId, 'Unlock First');
        $secondId = $this->createActivity($customerId, $templateId, $lessonId, 'Unlock Second');
        $freeId = $this->createActivity($customerId, $templateId, $lessonId, 'Free Activity');
        DB::table('core_course_template_activities')->where('id', $secondId)->update([
            'unlock_rule' => 'previous_activity_completed',
            'unlock_after_activity_id' => $firstId,
        ]);
        $url = $this->activityCollectionUrl('admin', $templateId, $sectionId, $lessonId);

        $this->actingAs($admin)->deleteJson("{$url}/{$firstId}")
            ->assertStatus(409)
            ->assertJson(['message' => __('lf.LF_course_template_activity_delete_blocked_unlock')]);
        $this->assertDatabaseHas('core_course_template_activities', ['id' => $firstId]);

        $xpath = $this->xpath($this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")->getContent());
        foreach ([$firstId => true, $secondId => false, $freeId => false] as $id => $blocked) {
            $button = $xpath->query('//button[@data-ui-row-delete and contains(@data-url, "/activities/'.$id.'")]')->item(0);
            $this->assertNotNull($button);
            $this->assertSame($blocked, $button->hasAttribute('disabled'));
        }

        $structure = "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure#course-template-lesson-{$lessonId}-activities";
        $this->actingAs($admin)->deleteJson("{$url}/{$secondId}")
            ->assertOk()
            ->assertJson(['message' => __('lf.LF_course_template_activity_common_deleted'), 'redirect' => $structure])
            ->assertSessionHas('success', __('lf.LF_course_template_activity_common_deleted'));
        $this->assertDatabaseMissing('core_course_template_activities', ['id' => $secondId]);

        // Once the dependant is gone the first one can be deleted too (teacher, own template).
        $teacherUrl = $this->activityCollectionUrl('teacher', $templateId, $sectionId, $lessonId);
        $this->actingAs($teacher)->deleteJson("{$teacherUrl}/{$firstId}")->assertOk();
        $this->assertDatabaseMissing('core_course_template_activities', ['id' => $firstId]);
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

    public function test_activity_upload_validation_uses_filtered_request_input(): void
    {
        $source = file_get_contents(
            app_path('Http/Controllers/CourseTemplateActivityController.php')
        );

        $this->assertStringNotContainsString('$request->all()', $source);
        $this->assertStringContainsString(
            '$request->request->all()',
            $source
        );
    }

    /** @param array<int, int> $orders sort_order by activity id */
    private function assertActivityOrders(array $orders): void
    {
        foreach ($orders as $activityId => $sortOrder) {
            $this->assertSame(
                $sortOrder,
                (int) DB::table('core_course_template_activities')->where('id', $activityId)->value('sort_order'),
                "sort_order of activity {$activityId}"
            );
        }
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

    private function createDirectHierarchy(): array
    {
        $customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'tenant-a-direct',
            'slug' => 'tenant-a-direct',
            'subdomain' => 'tenant-a',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $templateId = $this->createTemplate(
            $customerId,
            'Direct Template',
            'direct-template'
        );
        $lessonId = $this->createLesson(
            $customerId,
            $templateId,
            null,
            'Direct Lesson'
        );

        return [$customerId, $templateId, $lessonId];
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

    /** The creator of a Template is its primary assignment when a teacher (Owner decision 2026-11-10). */
    private function assignCreator(int $customerId, int $templateId, ?int $createdBy): int
    {
        if ($createdBy !== null && DB::table('users')->where('id', $createdBy)->value('role') === 'teacher') {
            DB::table('core_course_template_teachers')->insert([
                'customer_id' => $customerId, 'template_id' => $templateId, 'teacher_id' => $createdBy,
                'role' => 'primary', 'sort_order' => 0, 'status' => 'active',
                'assigned_by' => $createdBy, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $templateId;
    }

    private function createTemplate(
        int $customerId,
        string $title,
        string $slug
    ): int {
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
            'estimated_lesson_count' => null,
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
        string $title
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
            'unlock_rule' => 'none',
            'unlock_after_lesson_id' => null,
            'unlock_at' => null,
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
            'activity_type' => 'document',
            'external_video_url' => null,
            'live_class_url' => null,
            'assessment_quiz_id' => null,
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
            'activity_type' => 'document',
            'external_video_url' => null,
            'live_class_url' => null,
            'assessment_quiz_id' => null,
            'is_required' => 1,
            'completion_rule' => 'view',
            'completion_threshold' => null,
            'is_preview' => 0,
            'unlock_rule' => 'none',
            'unlock_after_activity_id' => null,
            'unlock_at' => null,
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

    private function directActivityCollectionUrl(
        string $area,
        int $templateId,
        int $lessonId
    ): string {
        return "https://tenant-a.localhost/{$area}/course-templates/"
            ."{$templateId}/lessons/{$lessonId}/activities";
    }

    /** The activity form is a popup fragment: it answers only XHR. */
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
            '//label[@for="activity-form-%s"]//span[contains(concat(" ", normalize-space(@class), " "), " ui-field__required ")]',
            $field
        );

        return $xpath->query($query)->length;
    }

    private function assertActivityTypeSelector(
        string $html,
        ?string $selectedType = null
    ): void {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $radios = $xpath->query('//input[@type="radio" and @name="activity_type"]');

        $optionValues = [];
        foreach ($radios as $radio) {
            $optionValues[] = $radio->getAttribute('value');
        }

        $this->assertSame([
            'video',
            'embedded_video',
            'audio',
            'document',
            'quiz',
            'live_class',
        ], $optionValues);
        $this->assertSame(
            1,
            $xpath->query('//input[@type="radio" and @name="activity_type" and @required]')->length
        );

        $checkedType = $selectedType ?? 'video';
        $checked = $xpath->query('//input[@type="radio" and @name="activity_type" and @checked]');
        $this->assertSame(1, $checked->length);
        $this->assertSame($checkedType, $checked->item(0)->getAttribute('value'));
    }

    private function assertLessonHasActivityCreateAction(
        string $html,
        string $lessonTitle,
        string $expectedUrl
    ): void {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $query = sprintf(
            '//article[contains(concat(" ", normalize-space(@class), " "),'
            .' " course-template-lesson-item ")][.//strong[normalize-space()="%s"]]'
            .'//section[contains(concat(" ", normalize-space(@class), " "),'
            .' " course-template-activity-panel ")]'
            .'//button[@data-ui-dialog-form-open and starts-with(@data-url, "%s?phase=") and normalize-space()="Thêm hoạt động"]',
            $lessonTitle,
            $expectedUrl
        );

        $this->assertSame(1, $xpath->query($query)->length);
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
