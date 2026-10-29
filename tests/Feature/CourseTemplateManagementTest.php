<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CourseTemplateStatus;
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
                ->assertSee('lf-admin-ui', false)
                ->assertSee('data-ui-live-filter', false)
                ->assertSee('data-ui-kit-select', false)
                ->assertSee('mk-table-scroll', false)
                ->assertSee('mk-table-foot', false)
                ->assertSee('ux-row-edit', false)
                ->assertSee('mk-sequence', false)
                ->assertSee('data-ui-toast-region', false)
                ->assertDontSee('course-template-index-table', false)
                ->assertSee('ui-status ui-status--', false)
                ->assertSeeText(__('lf.table_no'))
                ->assertDontSeeText('Private Tenant Template');
        }
    }

    public function test_template_index_paginates_ten_records_with_a_continuous_sequence_column(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        foreach (range(1, 11) as $number) {
            $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $this->createTemplate(
                $customerId,
                "Paginated Template {$suffix}",
                "paginated-template-{$suffix}"
            );
        }

        $firstPage = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-templates')
            ->assertOk()
            ->assertSeeText('Paginated Template 11')
            ->assertSeeText('Paginated Template 10')
            ->assertDontSeeText('Paginated Template 01')
            ->assertSeeTextInOrder(['Paginated Template 11', 'Paginated Template 10'])
            ->assertSee('mk-sequence', false);

        $secondPage = $this->get('https://tenant-a.localhost/admin/course-templates?page=2')
            ->assertOk()
            ->assertSeeText('Paginated Template 01')
            ->assertDontSeeText('Paginated Template 11')
            ->assertSee('mk-sequence', false);

        $firstPageDocument = new \DOMDocument;
        @$firstPageDocument->loadHTML($firstPage->getContent());
        $firstPageSequence = (new \DOMXPath($firstPageDocument))
            ->query('//tbody/tr/td[contains(@class, "mk-sequence")]');
        $this->assertCount(10, $firstPageSequence);
        $this->assertSame('10', trim($firstPageSequence->item(9)->textContent));

        $secondPageDocument = new \DOMDocument;
        @$secondPageDocument->loadHTML($secondPage->getContent());
        $secondPageSequence = (new \DOMXPath($secondPageDocument))
            ->query('//tbody/tr/td[contains(@class, "mk-sequence")]');
        $this->assertCount(1, $secondPageSequence);
        $this->assertSame('11', trim($secondPageSequence->item(0)->textContent));
    }

    public function test_workspace_links_back_to_the_last_list_with_its_filters_for_admin_and_teacher(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate($customerId, 'Back Link Template', 'back-link-template', $teacher->id);

        foreach ([[$admin, 'admin'], [$teacher, 'teacher']] as [$user, $area]) {
            $edit = "https://tenant-a.localhost/{$area}/course-templates/{$templateId}/edit";
            $index = "https://tenant-a.localhost/{$area}/course-templates";

            // No list visited yet in this session: the plain list.
            $this->actingAs($user)->get($edit)->assertOk()
                ->assertSee('<div class="page-back">', false)
                ->assertSee('href="'.$index.'"', false)
                ->assertSeeText(__('lf.LF_course_template_common_title'));

            // After filtering the list, the link returns to that exact view.
            $this->actingAs($user)->get($index.'?keyword=Back&status=active&page=1')->assertOk();
            $this->actingAs($user)->get($edit)->assertOk()
                ->assertSee('href="'.$index.'?keyword=Back&amp;page=1&amp;status=active"', false);
        }
    }

    public function test_back_link_never_points_to_another_host(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Host Template', 'host-template');

        $this->actingAs($admin)
            ->withSession(['list_return.course-templates.admin.course-templates' => 'https://evil.example/admin/course-templates'])
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()
            ->assertDontSee('evil.example', false)
            ->assertSee('href="https://tenant-a.localhost/admin/course-templates"', false);
    }

    public function test_template_index_uses_standard_empty_states_for_default_and_filtered_lists(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-templates')
            ->assertOk()
            ->assertSee('mk-empty', false)
            ->assertSeeText(__('lf.LF_course_template_common_empty'))
            ->assertSeeText(__('lf.LF_course_template_empty_help'))
            ->assertSeeText(__('lf.LF_course_template_common_create_short'));

        $this->createTemplate($customerId, 'Existing Template', 'existing-template');

        $this->get('https://tenant-a.localhost/admin/course-templates?keyword=missing')
            ->assertOk()
            ->assertSee('mk-empty', false)
            ->assertSeeText(__('lf.LF_course_template_filter_empty'))
            ->assertSeeText(__('lf.LF_course_template_filter_empty_help'))
            ->assertSeeText(__('lf.LF_course_template_common_clear_filters'))
            ->assertDontSeeText(__('lf.LF_course_template_common_empty'));
    }

    public function test_template_edit_uses_the_five_authoring_tabs(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'General', 'general');
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
            ->assertSeeText('Bản chỉnh sửa hiện tại')
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
            ->assertSeeText('Chọn cách sắp xếp bài học trong Template khóa học.')
            ->assertSeeText('Bài học độc lập')
            ->assertSeeText('Chia theo phần')
            ->assertSeeText('Bài học nằm trực tiếp trong khóa học.')
            ->assertSeeText('Nhóm bài học theo chương hoặc chủ đề.')
            ->assertSeeText('Thêm bài học')
            ->assertSeeText('Thêm phần học')
            ->assertSeeText('Thêm bài học để bắt đầu xây dựng nội dung khóa học.')
            ->assertSeeText('Thêm phần học để tổ chức bài học theo từng nhóm nội dung.')
            ->assertDontSeeText(__('lf.LF_course_template_mode_mixed_note'))
            ->assertSee('role="tablist"', false)
            ->assertSee('x-on:click="selectStructureTab(\'direct\')"', false)
            ->assertSee('x-on:click="selectStructureTab(\'sections\')"', false)
            ->assertSee('x-show="activeStructureTab === \'direct\'"', false)
            ->assertSee('x-show="activeStructureTab === \'sections\'"', false)
            ->assertDontSee('course-template-mode-card', false);
        $this->assertStringContainsString('course-template-direct-lesson-panel', $emptyStructure->getContent());
        $this->assertStringContainsString('course-template-content-toolbar', $emptyStructure->getContent());
        $this->assertStringContainsString('course-template-content-empty', $emptyStructure->getContent());
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

        $createFragment = $this->actingAs($admin)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get('https://tenant-a.localhost/admin/course-templates/create')
            ->assertOk()
            ->getContent();
        $editFragment = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/form")
            ->assertOk()
            ->getContent();
        foreach ([$createFragment, $editFragment] as $fragment) {
            foreach (['category_id', 'title', 'publisher_name', 'status'] as $field) {
                $this->assertSame(1, $this->dialogRequiredIndicatorCount($fragment, $field), "Dialog: {$field} is required.");
            }
            foreach (['short_description', 'description', 'difficulty_level', 'estimated_minutes_per_lesson', 'estimated_lesson_count'] as $field) {
                $this->assertSame(0, $this->dialogRequiredIndicatorCount($fragment, $field), "Dialog: {$field} is optional.");
            }
            $this->assertStringNotContainsString('name="slug"', $fragment);
            $this->assertStringNotContainsString('name="sort_order"', $fragment);
            $this->assertManualSeoControlsNotRendered($fragment, 'course-template-seo-title', 'LF_course_template');
        }

        // The workspace page no longer carries the form: its Information tab is a summary that opens the dialog.
        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()
            ->assertDontSee('id="course-template-update-form"', false)
            ->assertSee('data-ui-dialog-form-hash-open', false);
        $this->assertFileDoesNotExist(base_path('resources/views/course-templates/partials/form.blade.php'));
    }

    public function test_create_select_defaults_and_edit_saved_values_are_preserved(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'General', 'general');

        $create = $this->actingAs($admin)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get('https://tenant-a.localhost/admin/course-templates/create')
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_select_category'))
            ->assertSeeText(__('lf.LF_course_template_select_difficulty'));

        $this->assertTrue($this->selectOptionIsSelected($create->getContent(), 'status', 'draft'));
        foreach (CourseTemplateStatus::EDITABLE_VALUES as $status) {
            $create->assertSee('value="'.$status.'"', false);
        }
        $create->assertDontSee('value="archived"', false);
        $this->assertTrue($this->selectOptionIsSelected($create->getContent(), 'category_id', ''));
        $this->assertTrue($this->selectOptionIsSelected($create->getContent(), 'difficulty_level', ''));
        $this->assertFalse($this->selectOptionIsSelected($create->getContent(), 'category_id', (string) $categoryId));
        $create->assertSee('data-ui-kit-select', false)
            ->assertSee('name="intro_video_source"', false)
            ->assertDontSee('<option value="embed"', false);

        $templateId = $this->createTemplate($customerId, 'Saved Selects', 'unused', $admin->id);
        DB::table('core_course_templates')->where('id', $templateId)->update([
            'category_id' => $categoryId,
            'difficulty_level' => 'advanced',
            'status' => 'active',
        ]);

        $editFragment = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/form")
            ->assertOk()
            ->getContent();
        $this->assertTrue($this->selectOptionIsSelected($editFragment, 'category_id', (string) $categoryId));
        $this->assertTrue($this->selectOptionIsSelected($editFragment, 'difficulty_level', 'advanced'));
        $this->assertTrue($this->selectOptionIsSelected($editFragment, 'status', 'active'));
    }

    public function test_template_order_defaults_updates_and_category_moves_are_scoped(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $sourceCategoryId = $this->createCategory($customerId, 'Source', 'source');
        $destinationCategoryId = $this->createCategory($customerId, 'Destination', 'destination');

        $firstLocation = $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData(['category_id' => $sourceCategoryId, 'title' => 'First ordered Template'])
        )->assertRedirect()->headers->get('Location');
        $firstId = (int) basename(dirname((string) parse_url($firstLocation, PHP_URL_PATH)));

        $secondLocation = $this->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData([
                'category_id' => $sourceCategoryId,
                'title' => 'Second ordered Template',
                'sort_order' => 0,
            ])
        )->assertRedirect()->headers->get('Location');
        $secondId = (int) basename(dirname((string) parse_url($secondLocation, PHP_URL_PATH)));

        $this->assertSame(1, (int) DB::table('core_course_templates')->where('id', $firstId)->value('sort_order'));
        $this->assertSame(2, (int) DB::table('core_course_templates')->where('id', $secondId)->value('sort_order'));

        $destinationId = $this->createTemplate($customerId, 'Destination existing', 'unused', $admin->id);
        DB::table('core_course_templates')->where('id', $destinationId)->update([
            'category_id' => $destinationCategoryId,
            'sort_order' => 7,
        ]);

        $this->put(
            "https://tenant-a.localhost/admin/course-templates/{$secondId}",
            $this->validTemplateData(['category_id' => $destinationCategoryId, 'title' => 'Second ordered Template'])
        )->assertRedirect();

        $moved = DB::table('core_course_templates')->where('id', $secondId)->first();
        $this->assertSame($destinationCategoryId, (int) $moved->category_id);
        $this->assertSame(8, (int) $moved->sort_order);

        $this->put(
            "https://tenant-a.localhost/admin/course-templates/{$secondId}",
            $this->validTemplateData([
                'category_id' => $destinationCategoryId,
                'title' => 'Second ordered Template',
                'sort_order' => 3,
            ])
        )->assertRedirect();

        $this->assertSame(8, (int) DB::table('core_course_templates')->where('id', $secondId)->value('sort_order'));
    }

    public function test_template_order_validation_and_list_show_newest_records_first(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'Ordering', 'ordering');

        $location = $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData(['category_id' => $categoryId, 'sort_order' => -1])
        )->assertRedirect()->headers->get('Location');
        $forgedId = (int) basename(dirname((string) parse_url($location, PHP_URL_PATH)));
        $this->assertSame(1, (int) DB::table('core_course_templates')->where('id', $forgedId)->value('sort_order'));

        $firstId = $this->createTemplate($customerId, 'Tie A', 'unused-a', $admin->id);
        $secondId = $this->createTemplate($customerId, 'Tie B', 'unused-b', $admin->id);
        DB::table('core_course_templates')->whereIn('id', [$firstId, $secondId])->update([
            'category_id' => $categoryId,
            'sort_order' => 4,
        ]);

        $this->get('https://tenant-a.localhost/admin/course-templates')
            ->assertOk()
            ->assertSeeInOrder(['Tie B', 'Tie A']);
    }

    public function test_create_form_does_not_expose_template_order(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Highest existing', 'unused', $admin->id);
        DB::table('core_course_templates')->where('id', $templateId)->update(['sort_order' => 8]);

        $this->actingAs($admin)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get('https://tenant-a.localhost/admin/course-templates/create')
            ->assertOk()
            ->assertDontSee('selectedSortOrder', false)
            ->assertDontSee('name="sort_order"', false);
    }

    public function test_create_and_edit_use_contextual_primary_action_labels(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Action Labels', 'action-labels', $admin->id);

        $create = $this->actingAs($admin)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get('https://tenant-a.localhost/admin/course-templates/create')
            ->assertOk()->assertSeeText('Xác nhận')->getContent();
        $edit = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/form")
            ->assertOk()->assertSeeText('Xác nhận')->getContent();
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*>.*?Xác nhận\s*<\/button>/s', $create);
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*>.*?Xác nhận\s*<\/button>/s', $edit);
        $this->assertStringContainsString('class="ui-modal__footer"', $create);
        $this->assertStringContainsString('class="ui-modal__footer"', $edit);

        $workspace = $this->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()->assertSeeText('Sửa thông tin')->getContent();
        $this->assertStringContainsString('data-ui-dialog-form-open', $workspace);

        $englishCreate = $this->withSession(['locale' => 'en'])->actingAs($admin)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get('https://tenant-a.localhost/admin/course-templates/create')
            ->assertOk()->assertSeeText('Confirm')->getContent();
        $englishEdit = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/form")
            ->assertOk()->assertSeeText('Confirm')->getContent();
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*>.*?Confirm\s*<\/button>/s', $englishCreate);
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*>.*?Confirm\s*<\/button>/s', $englishEdit);
    }

    public function test_only_four_canonical_template_statuses_are_accepted(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'Statuses', 'statuses');

        $this->assertSame(
            ['draft', 'active', 'inactive', 'archived'],
            CourseTemplateStatus::VALUES
        );
        $this->assertSame('draft', CourseTemplateStatus::DEFAULT);

        $this->assertSame(
            ['draft', 'active', 'inactive'],
            CourseTemplateStatus::EDITABLE_VALUES
        );

        foreach (CourseTemplateStatus::EDITABLE_VALUES as $status) {
            $this->actingAs($admin)->post(
                'https://tenant-a.localhost/admin/course-templates',
                [
                    'category_id' => $categoryId,
                    'title' => ucfirst($status).' Template',
                    'publisher_name' => 'LearnForge',
                    'status' => $status,
                ]
            )->assertRedirect()->assertSessionDoesntHaveErrors();

            $this->assertDatabaseHas('core_course_templates', [
                'customer_id' => $customerId,
                'title' => ucfirst($status).' Template',
                'status' => $status,
            ]);
        }

        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            [
                'category_id' => $categoryId,
                'title' => 'Direct Archived Template',
                'publisher_name' => 'LearnForge',
                'status' => 'archived',
            ]
        )->assertSessionHasErrors('status');
        $this->assertDatabaseMissing('core_course_templates', [
            'title' => 'Direct Archived Template',
        ]);

        $activeTemplateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('status', 'active')
            ->value('id');
        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-templates/{$activeTemplateId}",
            $this->validTemplateData([
                'category_id' => $categoryId,
                'title' => 'Forged Archived Update',
                'status' => 'archived',
            ])
        )->assertSessionHasErrors('status');
        $this->assertDatabaseHas('core_course_templates', [
            'id' => $activeTemplateId,
            'status' => 'active',
        ]);

        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            [
                'category_id' => $categoryId,
                'title' => 'Forged Status Template',
                'publisher_name' => 'LearnForge',
                'status' => 'published',
            ]
        )->assertSessionHasErrors('status');
        $this->assertDatabaseMissing('core_course_templates', [
            'title' => 'Forged Status Template',
        ]);
    }

    public function test_admin_can_create_template_with_only_required_fields(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'General', 'general');

        $response = $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-templates', [
                'category_id' => $categoryId,
                'title' => 'Minimal Template',
                'publisher_name' => 'LearnForge',
                'status' => 'draft',
            ]);

        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Minimal Template')
            ->sole();

        $response
            ->assertRedirect("https://tenant-a.localhost/admin/course-templates/{$template->id}/edit")
            ->assertSessionHas(
                'course_template_created_title',
                'Đã tạo Template khóa học thành công.'
            )
            ->assertSessionHas(
                'course_template_created_guidance',
                'Bạn có thể tiếp tục cập nhật thông tin, xây dựng nội dung khóa học, phân công giáo viên và xuất bản phiên bản khi sẵn sàng.'
            );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $template->id,
            'customer_id' => $customerId,
            'title' => 'Minimal Template',
            'publisher_name' => 'LearnForge',
            'category_id' => $categoryId,
            'intro_video_source' => null,
            'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => null,
            'short_description' => null,
            'description' => null,
            'difficulty_level' => null,
            'estimated_minutes_per_lesson' => null,
            'estimated_lesson_count' => null,
            'status' => 'draft',
        ]);

        $firstEdit = $this->followRedirects($response)
            ->assertOk()
            ->assertSeeText('Đã tạo Template khóa học thành công.')
            ->assertSeeText('Bạn có thể tiếp tục cập nhật thông tin, xây dựng nội dung khóa học, phân công giáo viên và xuất bản phiên bản khi sẵn sàng.')
            ->assertSee('Minimal Template');
        $this->assertStringContainsString('role="status"', $firstEdit->getContent());
        $this->assertStringContainsString('class="admin-alert-title"', $firstEdit->getContent());
        $this->assertStringContainsString('class="admin-alert-guidance"', $firstEdit->getContent());

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$template->id}/edit")
            ->assertOk()
            ->assertDontSeeText('Đã tạo Template khóa học thành công.')
            ->assertDontSeeText('Bạn có thể tiếp tục cập nhật thông tin, xây dựng nội dung khóa học, phân công giáo viên và xuất bản phiên bản khi sẵn sàng.');

        $this->withSession(['locale' => 'en'])->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-templates', [
                'category_id' => $categoryId,
                'title' => 'English Template',
                'publisher_name' => 'LearnForge',
                'status' => 'draft',
            ])
            ->assertSessionHas(
                'course_template_created_title',
                'Course Template created successfully.'
            )
            ->assertSessionHas(
                'course_template_created_guidance',
                'You can continue updating its information, building the course content, assigning teachers, and publishing a version when ready.'
            );
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
                    'short_description' => 'TOPIK foundation',
                    'description' => 'Detailed TOPIK foundation course.',
                    'publisher_name' => 'Visang',
                    'intro_video_source' => 'upload',
                    'intro_image_file' => null,
                    'intro_video_file' => UploadedFile::fake()->create(
                        'intro-video.mp4',
                        32,
                        'video/mp4'
                    ),
                    'difficulty_level' => 'beginner',
                    'estimated_minutes_per_lesson' => 2400,
                    'estimated_lesson_count' => 40,
                    'meta_title' => 'TOPIK Beginner',
                    'meta_description' => 'Learn TOPIK from the beginning.',
                    'meta_keywords' => 'topik,korean',
                    'status' => 'draft',
                ])
            )
            ->assertRedirect();

        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'TOPIK Beginner 1')
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
        $categoryId = $this->createCategory($customerId, 'General', 'general');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Optional Preview Course',
                    'category_id' => $categoryId,
                    'intro_image_file' => null,
                    'intro_video_file' => null,
                ])
            )
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_templates', [
            'customer_id' => $customerId,
            'title' => 'Optional Preview Course',
            'intro_video_source' => null,
            'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => null,
        ]);
        $this->assertDatabaseMissing('media_file_usages', [
            'customer_id' => $customerId,
            'owner_type' => 'course_template',
            'usage_type' => 'intro_image',
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
            'meta_title' => 'Legacy SEO Title',
            'meta_description' => 'Legacy SEO description',
            'meta_keywords' => 'legacy,seo',
        ]);
    }

    public function test_teacher_can_create_an_independent_draft_template(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $categoryId = $this->createCategory($customerId, 'General', 'general');

        $this->actingAs($teacher)
            ->post(
                'https://tenant-a.localhost/teacher/course-templates',
                $this->validTemplateData([
                    'category_id' => $categoryId,
                    'title' => 'Teacher Course',
                ])
            )
            ->assertRedirect();

        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Teacher Course')
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

        $validationResponse = $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-templates/create')
            ->post('https://tenant-a.localhost/admin/course-templates', [
                'category_id' => $otherCategoryId,
                'title' => '',
                'publisher_name' => '',
                'intro_video_source' => 'document',
                'estimated_minutes_per_lesson' => -1,
                'status' => 'disabled',
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates/create')
            ->assertSessionHasErrors([
                'category_id',
                'title',
                'publisher_name',
                'intro_video_source',
                'estimated_minutes_per_lesson',
                'status',
            ]);
        $validationResponse
            ->assertSessionMissing('course_template_created_title')
            ->assertSessionMissing('course_template_created_guidance');

        $this->assertDatabaseCount('core_course_templates', 0);
    }

    public function test_estimated_counts_must_be_whole_numbers_within_the_column_range(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-templates/create')
            ->post('https://tenant-a.localhost/admin/course-templates', $this->validTemplateData([
                'estimated_minutes_per_lesson' => 10001,
                'estimated_lesson_count' => 1.5,
            ]))
            ->assertSessionHasErrors(['estimated_minutes_per_lesson', 'estimated_lesson_count']);

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-templates/create')
            ->post('https://tenant-a.localhost/admin/course-templates', $this->validTemplateData([
                'estimated_minutes_per_lesson' => 4294967296,
                'estimated_lesson_count' => 1001,
            ]))
            ->assertSessionHasErrors(['estimated_minutes_per_lesson', 'estimated_lesson_count']);

        $this->assertDatabaseCount('core_course_templates', 0);
    }

    public function test_templates_do_not_require_slug_or_title_uniqueness(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $otherCategoryId = $this->createCategory($otherCustomerId, 'General', 'general');
        $this->createTemplate($customerId, 'TOPIK', 'topik');
        $ownCategoryId = DB::table('core_course_categories')->where('customer_id', $customerId)->value('id');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'category_id' => $ownCategoryId,
                    'title' => 'TOPIK',
                ])
            )
            ->assertRedirect();

        $this->actingAs($otherAdmin)
            ->post(
                'https://tenant-b.localhost/admin/course-templates',
                $this->validTemplateData([
                    'category_id' => $otherCategoryId,
                    'title' => 'TOPIK',
                ])
            )
            ->assertRedirect();

        $this->assertDatabaseHas('core_course_templates', [
            'customer_id' => $otherCustomerId,
            'title' => 'TOPIK',
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
                ])
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->delete(
                'https://tenant-a.localhost/admin/course-templates',
                ['ids' => [$otherTemplateId]]
            )
            ->assertNotFound();

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-templates/{$ownTemplateId}",
                $this->validTemplateData([
                    'title' => 'Inactive Own Course',
                    'status' => 'inactive',
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$ownTemplateId}/edit"
            );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $ownTemplateId,
            'customer_id' => $customerId,
            'title' => 'Inactive Own Course',
            'status' => 'inactive',
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
                'https://tenant-a.localhost/admin/course-templates',
                ['ids' => [$templateId]]
            )
            ->assertRedirect();

        $this->assertDatabaseMissing('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
        ]);
    }

    public function test_unused_template_is_deleted_through_json_and_blocked_ones_report_why(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $free = $this->createTemplate($customerId, 'Free template', 'free-template');
        $withSection = $this->createTemplate($customerId, 'Has section', 'has-section');
        $withTeacher = $this->createTemplate($customerId, 'Has teacher', 'has-teacher');
        $withProduct = $this->createTemplate($customerId, 'Used by product', 'used-by-product');

        DB::table('core_course_template_sections')->insert([
            'customer_id' => $customerId, 'template_id' => $withSection, 'parent_section_id' => null,
            'allows_lessons' => true, 'title' => 'S', 'description' => 'S', 'display_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_template_teachers')->insert([
            'customer_id' => $customerId, 'template_id' => $withTeacher, 'teacher_id' => $teacher->id,
            'role' => 'lead', 'sort_order' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = DB::table('core_course_products')->insertGetId([
            'customer_id' => $customerId, 'product_code' => 'p1', 'product_type' => 'course', 'title' => 'p1', 'slug' => 'p1',
            'thumbnail_type' => 'image', 'price' => 0, 'currency' => 'VND', 'enrollment_type' => 'open',
            'visibility' => 'public', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_product_items')->insert([
            'customer_id' => $customerId, 'product_id' => $productId, 'template_id' => $withProduct, 'version_id' => null,
            'sort_order' => 0, 'is_required' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[$withSection, 'sections'], [$withTeacher, 'teachers'], [$withProduct, 'products']] as [$id, $reason]) {
            $this->actingAs($admin)
                ->deleteJson('https://tenant-a.localhost/admin/course-templates', ['ids' => [$id]])
                ->assertStatus(409)
                ->assertJsonPath('message', fn ($message) => str_contains($message, __('lf.LF_course_template_delete_blocked_'.$reason, ['count' => 1])));
            $this->assertDatabaseHas('core_course_templates', ['id' => $id]);
        }

        $this->actingAs($admin)
            ->deleteJson('https://tenant-a.localhost/admin/course-templates', ['ids' => [$free]])
            ->assertOk()
            ->assertJson(['message' => trans_choice('lf.LF_course_template_bulk_deleted', 1, ['count' => 1])]);
        $this->assertDatabaseMissing('core_course_templates', ['id' => $free]);

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-templates')
            ->delete('https://tenant-a.localhost/admin/course-templates', ['ids' => [$withSection]])
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates')
            ->assertSessionHasErrors(['template']);
    }

    public function test_template_delete_respects_tenant_and_teacher_scope(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $stranger = $this->createUser($customerId, 'teacher');
        $others = $this->createTemplate($otherCustomerId, 'Other tenant', 'other-tenant');
        $own = $this->createTemplate($customerId, 'Own template', 'own-template', $teacher->id);

        $this->actingAs($admin)->deleteJson('https://tenant-a.localhost/admin/course-templates', ['ids' => [$others]])->assertNotFound();
        $this->assertDatabaseHas('core_course_templates', ['id' => $others]);
        // A teacher with no access to the template does not even learn that it exists.
        $this->actingAs($stranger)->deleteJson('https://tenant-a.localhost/teacher/course-templates', ['ids' => [$own]])->assertNotFound();
        $this->assertDatabaseHas('core_course_templates', ['id' => $own]);
        $this->actingAs($teacher)->deleteJson('https://tenant-a.localhost/teacher/course-templates', ['ids' => [$own]])->assertOk();
        $this->assertDatabaseMissing('core_course_templates', ['id' => $own]);
    }

    public function test_template_bulk_delete_is_all_or_nothing_and_respects_tenant_and_teacher_scope(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $one = $this->createTemplate($customerId, 'Bulk one', 'bulk-one');
        $two = $this->createTemplate($customerId, 'Bulk two', 'bulk-two');
        $used = $this->createTemplate($customerId, 'Bulk used', 'bulk-used');
        $foreign = $this->createTemplate($otherCustomerId, 'Foreign bulk', 'foreign-bulk');
        DB::table('core_course_template_sections')->insert([
            'customer_id' => $customerId, 'template_id' => $used, 'parent_section_id' => null,
            'allows_lessons' => true, 'title' => 'S', 'description' => 'S', 'display_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = 'https://tenant-a.localhost/admin/course-templates';

        $this->actingAs($admin)->deleteJson($url, ['ids' => [$one, $used]])
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'Bulk used'));
        $this->assertSame(2, DB::table('core_course_templates')->whereIn('id', [$one, $used])->count(), 'a blocked one refuses the whole request');

        $this->actingAs($admin)->deleteJson($url, ['ids' => [$one, $foreign]])->assertNotFound();
        $this->assertDatabaseHas('core_course_templates', ['id' => $foreign]);
        $this->assertDatabaseHas('core_course_templates', ['id' => $one]);

        $this->actingAs($teacher)->deleteJson('https://tenant-a.localhost/teacher/course-templates', ['ids' => [$two]])->assertNotFound();
        $this->assertDatabaseHas('core_course_templates', ['id' => $two]);
        $own = $this->createTemplate($customerId, 'Teacher own', 'teacher-own', $teacher->id);
        $this->actingAs($teacher)->deleteJson('https://tenant-a.localhost/teacher/course-templates', ['ids' => [$own]])->assertOk();
        $this->assertDatabaseMissing('core_course_templates', ['id' => $own]);

        $this->actingAs($admin)->deleteJson($url, ['ids' => [$one, $two]])
            ->assertOk()->assertJsonPath('message', trans_choice('lf.LF_course_template_bulk_deleted', 2, ['count' => 2]));
        $this->assertDatabaseMissing('core_course_templates', ['id' => $one]);
        $this->assertDatabaseMissing('core_course_templates', ['id' => $two]);
        $this->assertDatabaseHas('core_course_templates', ['id' => $used]);

        $this->actingAs($admin)->deleteJson($url, ['ids' => []])->assertStatus(422);
    }

    public function test_an_assigned_teacher_may_work_on_a_template_but_only_its_creator_or_an_admin_deletes_it(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $creator = $this->createUser($customerId, 'teacher');
        $assigned = $this->createUser($customerId, 'teacher');
        $template = $this->createTemplate($customerId, 'Shared work', 'shared-work', $creator->id);
        // An assignment that is no longer active never granted deletion either: access comes from the active one only.
        DB::table('core_course_template_teachers')->insert([
            'customer_id' => $customerId, 'template_id' => $template, 'teacher_id' => $assigned->id,
            'role' => 'lead', 'sort_order' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $base = 'https://tenant-a.localhost/teacher/course-templates';

        // The assigned teacher sees the Template, but the list locks it with the reason, and the server refuses (403, not the 409 of "still assigned").
        $html = $this->actingAs($assigned)->get($base)->assertOk()->assertSeeText('Shared work')
            ->assertSee(__('lf.LF_course_template_delete_blocked_teachers', ['count' => 1]), false)->getContent();
        $this->assertSame(0, substr_count($html, 'data-ui-bulk-item'), 'no selectable row for the assigned teacher');
        $this->actingAs($assigned)->deleteJson($base, ['ids' => [$template]])->assertForbidden();
        $this->assertDatabaseHas('core_course_templates', ['id' => $template]);

        // Once nothing hangs on it, the creator deletes it; for the assigned teacher the reason is now "not the creator".
        DB::table('core_course_template_teachers')->where('template_id', $template)->delete();
        $this->actingAs($creator)->deleteJson($base, ['ids' => [$template]])->assertOk();
        $this->assertDatabaseMissing('core_course_templates', ['id' => $template]);

        $other = $this->createTemplate($customerId, 'Admin removes', 'admin-removes', $creator->id);
        $this->actingAs($admin)->deleteJson('https://tenant-a.localhost/admin/course-templates', ['ids' => [$other]])->assertOk();
    }

    public function test_template_index_enables_delete_only_for_unused_templates_and_explains_why_not(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $free = $this->createTemplate($customerId, 'Free listed', 'free-listed');
        $used = $this->createTemplate($customerId, 'Used listed', 'used-listed');
        DB::table('core_course_template_sections')->insert([
            'customer_id' => $customerId, 'template_id' => $used, 'parent_section_id' => null,
            'allows_lessons' => true, 'title' => 'S', 'description' => 'S', 'display_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $content = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-templates')
            ->assertOk()
            ->assertSee('resources/js/admin-ui-kit-row-actions.js', false)
            ->assertSee('data-csrf=', false)
            ->assertSee(__('lf.LF_course_template_delete_blocked_sections', ['count' => 1]), false)
            ->getContent();

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$content);
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//button[@data-ui-row-delete]')->length, 'no delete icon on the rows any more');
        $this->assertSame(1, $xpath->query('//input[@data-ui-bulk-item and not(@disabled) and @value="'.$free.'"]')->length);
        $this->assertSame(1, $xpath->query('//span[@class="ui-bulk-lock"]/input[@disabled]')->length, 'a used Template has a locked checkbox with the reason');
        $this->assertSame(1, $xpath->query('//input[@data-ui-bulk-select-all]')->length);
        $this->assertSame(1, $xpath->query('//div[contains(@class, "mk-table-top__actions")]/button[1][@data-ui-dialog-form-open]')->length, 'the create button is the first one in the toolbar');
        $this->assertSame(0, $xpath->query('//div[contains(@class, "page-heading__actions")]')->length, 'the heading carries no list buttons');
        $this->assertSame(
            1,
            $xpath->query('//button[@data-ui-bulk-delete and @disabled and @data-url="https://tenant-a.localhost/admin/course-templates"]')->length,
            'the delete button is always there, disabled until a row is checked'
        );
        $this->assertSame(
            __('lf.LF_course_template_bulk_delete'),
            $xpath->query('//button[@data-ui-bulk-delete]')->item(0)?->getAttribute('data-confirm-label'),
            'the confirm button names the action (Xóa), not a generic "Xác nhận"'
        );
    }

    public function test_template_dialog_fragments_have_the_popup_structure_and_media_slots(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Dialog template', 'dialog-template', $admin->id);
        $archivedId = $this->createTemplate($customerId, 'Archived template', 'archived-template', $admin->id);
        DB::table('core_course_templates')->where('id', $archivedId)->update(['status' => 'archived']);

        $hasClass = fn (string $class) => 'contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")';
        $fragments = [
            'create' => $this->actingAs($admin)->withHeader('X-Requested-With', 'XMLHttpRequest')
                ->get('https://tenant-a.localhost/admin/course-templates/create')->assertOk()->getContent(),
            'edit' => $this->withHeader('X-Requested-With', 'XMLHttpRequest')
                ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/form")->assertOk()->getContent(),
        ];

        foreach ($fragments as $mode => $html) {
            $count = fn (string $xpath) => $this->templateHtmlCount($html, $xpath);
            $this->assertSame(1, $count('//form['.$hasClass('ui-modal__form').' and @novalidate]'), $mode);
            $this->assertSame(1, $count('//div['.$hasClass('ui-modal__body').']//div['.$hasClass('ui-modal__grid').']'), $mode);
            $this->assertSame(1, $count('//dialog'), $mode.': only the media preview dialog, no nested discard dialog');
            $this->assertSame(2, $count('//button[@data-ui-dialog-form-cancel]'), $mode);
            $this->assertSame(3, $count('//div[@data-ui-media-slot]'), $mode);
            $this->assertSame(
                ['image', 'video'],
                [
                    $this->templateHtmlAttribute($html, '//div[@data-ui-media-slot][.//input[@id="intro_image_file"]]', 'data-kind'),
                    $this->templateHtmlAttribute($html, '//div[@data-ui-media-slot][.//input[@id="intro_video_file"]]', 'data-kind'),
                ],
                $mode
            );
            $this->assertSame(1, $count('//input[@id="intro_document_file"]'), $mode);
            foreach (['title', 'category_id', 'publisher_name', 'status', 'difficulty_level', 'short_description', 'description'] as $field) {
                $this->assertSame(1, $count('//*[@id="template-form-'.$field.'"]'), "{$mode} {$field}");
            }
            $this->assertSame(0, $count('//*[@id="status" or @id="title" or @id="keyword"]'), 'ids must not collide with the list filters');
            $this->assertSame(3, $count('//input[@type="hidden" and (@name="intro_image_media_file_id" or @name="intro_video_media_file_id" or @name="intro_document_media_file_id")]'), $mode);
            $this->assertSame(3, $count('//select[@data-ui-kit-select and (@name="category_id" or @name="difficulty_level" or @name="status")]'), $mode);
            $this->assertSame(0, $count('//select[@name="intro_video_source"]'), 'embedded video is hidden for now');
            $this->assertSame(1, $count('//input[@type="hidden" and @name="intro_video_source"]'), $mode);
            $this->assertSame(0, $count('//input[@name="intro_video_embed_url" and not(@type="hidden")]'), $mode);
            $this->assertStringContainsString('x-on:ui-media-preview', $html);
        }
        $this->assertSame(1, $this->templateHtmlCount($fragments['edit'], '//input[@name="_method" and @value="PUT"]'));
        $this->assertSame(0, $this->templateHtmlCount($fragments['create'], '//input[@name="_method"]'));
        $this->assertStringContainsString("/admin/course-templates/{$templateId}", $fragments['edit']);

        $archived = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get("https://tenant-a.localhost/admin/course-templates/{$archivedId}/form")->assertOk()->getContent();
        $this->assertSame(1, $this->templateHtmlCount($archived, '//fieldset[@disabled]'));
        $this->assertSame(1, $this->templateHtmlCount($archived, '//button[@type="submit" and @disabled]'));
        $this->assertSame(0, $this->templateHtmlCount($fragments['edit'], '//fieldset[@disabled]'));
    }

    public function test_embed_tile_is_empty_for_new_templates_and_shows_the_saved_embed(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $youtube = $this->createTemplate($customerId, 'YouTube template', 'youtube-template', $admin->id);
        $vimeo = $this->createTemplate($customerId, 'Vimeo template', 'vimeo-template', $admin->id);
        $plain = $this->createTemplate($customerId, 'No video template', 'no-video-template', $admin->id);
        DB::table('core_course_templates')->where('id', $youtube)->update([
            'intro_video_source' => 'embed', 'intro_video_embed_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'intro_video_provider' => 'youtube',
        ]);
        DB::table('core_course_templates')->where('id', $vimeo)->update([
            'intro_video_source' => 'embed', 'intro_video_embed_url' => 'https://vimeo.com/76979871', 'intro_video_provider' => 'vimeo',
        ]);
        $get = fn (string $url) => $this->actingAs($admin)->withHeader('X-Requested-With', 'XMLHttpRequest')->get($url)->assertOk()->getContent();
        $base = 'https://tenant-a.localhost/admin/course-templates';
        $embed = '//div[@data-ui-embed-slot]';

        $create = $get($base.'/create');
        $this->assertSame(1, $this->templateHtmlCount($create, $embed.'[@data-state="empty"][@data-ui-error-for="intro_video_embed_url"]'));
        $this->assertSame(1, $this->templateHtmlCount($create, $embed.'//input[@type="hidden" and @name="intro_video_embed_url" and @value=""]'));
        $this->assertSame(1, $this->templateHtmlCount($create, $embed.'//button[@data-ui-embed-add and @aria-haspopup="true"]'));
        $this->assertSame(1, $this->templateHtmlCount($create, $embed.'//div[@data-ui-embed-editor and @hidden]//input[@type="url"]'));
        $this->assertSame(1, $this->templateHtmlCount($create, '//p[normalize-space(.)="'.__('lf.LF_course_template_video_embed').'"]'));
        $this->assertSame(1, $this->templateHtmlCount($create, '//input[@type="hidden" and @name="intro_video_source"]'));

        $withYoutube = $get($base."/{$youtube}/form");
        $this->assertSame(1, $this->templateHtmlCount($withYoutube, $embed.'[@data-state="filled"]'));
        $this->assertSame(1, $this->templateHtmlCount($withYoutube, $embed.'//input[@name="intro_video_embed_url" and @value="https://www.youtube.com/watch?v=dQw4w9WgXcQ"]'));
        $this->assertSame(1, $this->templateHtmlCount($withYoutube, $embed.'//img[@data-ui-embed-image and @src="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg"]'));
        $this->assertStringContainsString('data-saved-preview-url="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', $withYoutube);

        $withVimeo = $get($base."/{$vimeo}/form");
        $this->assertSame(1, $this->templateHtmlCount($withVimeo, $embed.'[@data-state="filled"]'));
        $this->assertSame(0, $this->templateHtmlCount($withVimeo, $embed.'//img[@data-ui-embed-image and @src]'), 'Vimeo has no thumbnail: the tile shows the video icon');

        $this->assertSame(1, $this->templateHtmlCount($get($base."/{$plain}/form"), $embed.'[@data-state="empty"]'));
    }

    public function test_choosing_an_embed_or_an_upload_switches_the_saved_video_source_on_the_server(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->createCategory($customerId, 'General', 'general');
        $templateId = $this->createTemplate($customerId, 'Switch', 'switch', $admin->id);
        $url = "https://tenant-a.localhost/admin/course-templates/{$templateId}";

        $this->actingAs($admin)
            ->putJson($url, $this->validTemplateData([
                'intro_image_file' => null,
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ]))
            ->assertOk();
        $this->assertDatabaseHas('core_course_templates', ['id' => $templateId, 'intro_video_source' => 'embed', 'intro_video_provider' => 'youtube', 'intro_video_media_file_id' => null]);

        $this->actingAs($admin)
            ->putJson($url, $this->validTemplateData([
                'intro_image_file' => null,
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => 'https://example.com/not-a-trusted-provider',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['intro_video_embed_url']);

        $this->actingAs($admin)
            ->putJson($url, $this->validTemplateData([
                'intro_image_file' => null,
                'intro_video_source' => 'upload',
                'intro_video_file' => UploadedFile::fake()->create('clip.mp4', 32, 'video/mp4'),
            ]))
            ->assertOk();
        $this->assertDatabaseHas('core_course_templates', ['id' => $templateId, 'intro_video_source' => 'upload', 'intro_video_embed_url' => null]);

        $this->actingAs($admin)
            ->putJson($url, $this->validTemplateData([
                'intro_image_file' => null,
                'intro_video_source' => null,
            ]))
            ->assertOk();
        $this->assertDatabaseHas('core_course_templates', ['id' => $templateId, 'intro_video_source' => null, 'intro_video_media_file_id' => null, 'intro_video_embed_url' => null]);
    }

    public function test_template_dialog_fragments_are_ajax_only_and_scoped(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $stranger = $this->createUser($customerId, 'teacher');
        $mine = $this->createTemplate($customerId, 'Mine', 'mine', $admin->id);
        $theirs = $this->createTemplate($otherCustomerId, 'Theirs', 'theirs');
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $this->actingAs($admin)->get('https://tenant-a.localhost/admin/course-templates/create')->assertNotFound();
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-templates/{$mine}/form")->assertNotFound();
        $this->actingAs($admin)->withHeaders($ajax)->get("https://tenant-a.localhost/admin/course-templates/{$theirs}/form")->assertNotFound();
        $this->actingAs($stranger)->withHeaders($ajax)->get("https://tenant-a.localhost/teacher/course-templates/{$mine}/form")->assertNotFound();
    }

    public function test_json_create_returns_the_workspace_redirect_and_update_reports_validation_per_field(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->createCategory($customerId, 'General', 'general');

        $this->actingAs($admin)
            ->postJson('https://tenant-a.localhost/admin/course-templates', $this->validTemplateData(['title' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
        $this->assertSame(0, DB::table('core_course_templates')->where('customer_id', $customerId)->count());

        $response = $this->actingAs($admin)
            ->postJson('https://tenant-a.localhost/admin/course-templates', $this->validTemplateData(['title' => 'Created in dialog']))
            ->assertCreated();
        $templateId = (int) DB::table('core_course_templates')->where('title', 'Created in dialog')->value('id');
        $response->assertJson(['redirect' => "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit"]);
        $response->assertSessionHas('course_template_created_title');
        $response->assertSessionHas('course_template_created_guidance');

        $this->actingAs($admin)
            ->putJson("https://tenant-a.localhost/admin/course-templates/{$templateId}", $this->validTemplateData(['title' => 'Renamed in dialog']))
            ->assertOk()
            ->assertJson(['message' => __('lf.LF_course_template_common_updated')]);
        $this->assertSame('Renamed in dialog', DB::table('core_course_templates')->where('id', $templateId)->value('title'));

        $this->actingAs($admin)
            ->putJson("https://tenant-a.localhost/admin/course-templates/{$templateId}", $this->validTemplateData(['title' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_template_index_opens_create_edit_information_and_manage_from_the_list(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Listed template', 'listed-template', $admin->id);

        $content = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-templates')
            ->assertOk()
            ->assertSee('data-ui-dialog-form-scope', false)
            ->assertSee('data-columns="2"', false)
            ->assertSee('resources/js/admin-ui-kit-dialog-form.js', false)
            ->getContent();

        $this->assertSame(1, $this->templateHtmlCount($content, '//dialog[@data-ui-dialog-form]'));
        $this->assertSame(
            1,
            $this->templateHtmlCount($content, '//button[@data-ui-dialog-form-open and @data-url="https://tenant-a.localhost/admin/course-templates/create"]')
        );
        $this->assertSame(
            1,
            $this->templateHtmlCount($content, '//button[@data-ui-dialog-form-open and @data-url="https://tenant-a.localhost/admin/course-templates/'.$templateId.'/form"]')
        );
        $this->assertSame(
            1,
            $this->templateHtmlCount($content, '//a[@href="https://tenant-a.localhost/admin/course-templates/'.$templateId.'/edit"]')
        );
        $this->assertSame(0, $this->templateHtmlCount($content, '//a[contains(@href, "/course-templates/create")]'));
    }

    public function test_workspace_information_tab_is_a_summary_that_opens_the_same_popup(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'Korean', 'korean');
        $templateId = $this->createTemplate($customerId, 'Summary template', 'summary-template', $admin->id);
        DB::table('core_course_templates')->where('id', $templateId)->update([
            'category_id' => $categoryId,
            'publisher_name' => 'Visang',
            'difficulty_level' => 'advanced',
            'estimated_minutes_per_lesson' => 25,
            'short_description' => 'Short summary text',
            'status' => 'inactive',
        ]);

        $html = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()
            ->assertSee('resources/js/admin-ui-kit-dialog-form.js', false)
            ->assertSee('data-field-id-prefix="template-form-"', false)
            ->getContent();

        $this->assertSame(0, $this->templateHtmlCount($html, '//form[@id="course-template-update-form"]'));
        $this->assertSame(
            1,
            $this->templateHtmlCount($html, '//button[@data-ui-dialog-form-open and @data-ui-dialog-form-hash-open and @data-url="https://tenant-a.localhost/admin/course-templates/'.$templateId.'/form?context=workspace"]')
        );
        $this->assertSame(12, $this->templateHtmlCount($html, '//div[@data-summary-field]'));
        foreach ([
            'title' => 'Summary template',
            'category_id' => 'Korean',
            'publisher_name' => 'Visang',
            'estimated_minutes_per_lesson' => '25',
            'short_description' => 'Short summary text',
        ] as $field => $value) {
            $this->assertSame(
                1,
                $this->templateHtmlCount($html, '//div[@data-summary-field="'.$field.'"]/dd[contains(normalize-space(.), "'.$value.'")]'),
                $field
            );
        }
        $this->assertSame(1, $this->templateHtmlCount($html, '//div[@data-summary-field="status"]//span[contains(@class, "ui-status--inactive")]'));
        // The archive action for an inactive template stays on the page (not part of the dialog).
        $this->assertSame(1, $this->templateHtmlCount($html, '//form[contains(@action, "/archive")]'));
    }

    public function test_saving_from_the_workspace_dialog_returns_to_the_information_tab_with_a_message(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->createCategory($customerId, 'General', 'general');
        $templateId = $this->createTemplate($customerId, 'Before', 'before', $admin->id);
        $url = "https://tenant-a.localhost/admin/course-templates/{$templateId}";

        $response = $this->actingAs($admin)
            ->putJson($url.'?context=workspace', $this->validTemplateData(['title' => 'After workspace save']))
            ->assertOk()
            ->assertJson([
                'message' => __('lf.LF_course_template_common_updated'),
                'redirect' => $url.'/edit?tab=information',
            ]);
        $response->assertSessionHas('success', __('lf.LF_course_template_common_updated'));
        $this->assertSame('After workspace save', DB::table('core_course_templates')->where('id', $templateId)->value('title'));

        $form = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get($url.'/form?context=workspace')
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('action="'.$url.'?context=workspace"', $form);
        $plain = $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get($url.'/form')->getContent();
        $this->assertStringNotContainsString('context=workspace', $plain);
    }

    public function test_readiness_links_still_target_information_fields_that_the_dialog_can_focus(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Needs info', 'needs-info', $admin->id);
        DB::table('core_course_templates')->where('id', $templateId)->update(['category_id' => null, 'difficulty_level' => null]);

        $html = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=publish")
            ->assertOk()
            ->getContent();
        $fragments = [];
        preg_match_all('/\?tab=information#([a-z_]+)/', $html, $fragments);
        $this->assertNotEmpty($fragments[1], 'readiness issues link to Information fields');

        $dialog = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/form")
            ->getContent();
        foreach (array_unique($fragments[1]) as $field) {
            $this->assertGreaterThanOrEqual(
                1,
                $this->templateHtmlCount($dialog, '//*[@id="template-form-'.$field.'" or @id="'.$field.'"]'),
                "The dialog has a field the link #{$field} can focus."
            );
        }
    }

    public function test_template_media_uploads_use_the_video_and_other_media_limits(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->createCategory($customerId, 'General', 'general');
        $templateId = $this->createTemplate($customerId, 'Limits', 'limits', $admin->id);

        // Lower the policy so small fake files can prove which limit each field uses (video 300 KB, other 100 KB).
        config(['media.max_upload_kilobytes' => 100, 'media.video_max_upload_kilobytes' => 300]);

        $this->actingAs($admin)
            ->putJson("https://tenant-a.localhost/admin/course-templates/{$templateId}", $this->validTemplateData([
                'intro_video_source' => 'upload',
                'intro_image_file' => UploadedFile::fake()->image('big.png')->size(200),
                'intro_video_file' => UploadedFile::fake()->create('ok.mp4', 200, 'video/mp4'),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['intro_image_file'])
            ->assertJsonMissingValidationErrors(['intro_video_file']);

        $this->actingAs($admin)
            ->putJson("https://tenant-a.localhost/admin/course-templates/{$templateId}", $this->validTemplateData([
                'intro_video_source' => 'upload',
                'intro_image_file' => null,
                'intro_document_file' => UploadedFile::fake()->create('doc.pdf', 200, 'application/pdf'),
                'intro_video_file' => UploadedFile::fake()->create('toobig.mp4', 400, 'video/mp4'),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['intro_document_file', 'intro_video_file']);

        $hints = $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/form")
            ->assertOk()
            ->getContent();
        $this->assertSame(2, substr_count($hints, 'Tối đa: 100 KB'));
        $this->assertSame(1, substr_count($hints, 'Tối đa: 300 KB'));
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

    public function test_information_shows_the_saved_embed_in_the_summary_and_the_form(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'YouTube Course', 'youtube-course', $admin->id);
        DB::table('core_course_templates')->where('id', $templateId)->update([
            'intro_video_source' => 'embed',
            'intro_video_embed_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'intro_video_provider' => 'youtube',
        ]);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()
            ->assertSee('data-summary-field="intro_video_source"', false)
            // Same tile as the form: the provider thumbnail, named by its alt text.
            ->assertSee('src="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg" alt="Youtube"', false)
            // The summary is read-only: an empty resource is a dash, adding happens in the edit form.
            ->assertDontSee('data-ui-dialog-form-focus="intro_document_file"', false);

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/form")
            ->assertOk()
            // Hidden for now, but a saved embed is carried through untouched until a video file is chosen.
            ->assertSee('<input type="hidden" name="intro_video_embed_url" value="https://www.youtube.com/watch?v=dQw4w9WgXcQ"', false)
            ->assertSee('savedVideoSource', false)
            ->assertDontSee('<option value="embed"', false);
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
        $categoryId = DB::table('core_course_categories')
            ->where('customer_id', $customerId)
            ->value('id') ?? $this->createCategory(
                $customerId,
                'General',
                'general-'.$customerId
            );

        return DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId,
            'category_id' => $categoryId,
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
            'created_by' => $createdBy,
            'last_version_published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function validTemplateData(array $overrides = []): array
    {
        $categoryId = DB::table('core_course_categories')->value('id');

        return array_merge([
            'category_id' => $categoryId,
            'title' => 'Programming Basics',
            'short_description' => null,
            'description' => null,
            'publisher_name' => 'LearnForge',
            'intro_video_source' => null,
            'intro_image_file' => UploadedFile::fake()->image(
                'template-cover.png',
                120,
                80
            ),
            'difficulty_level' => null,
            'estimated_minutes_per_lesson' => null,
            'estimated_lesson_count' => null,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'status' => 'draft',
        ], $overrides);
    }

    private function templateHtmlCount(string $html, string $xpath): int
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return (new \DOMXPath($document))->query($xpath)->length;
    }

    private function templateHtmlAttribute(string $html, string $xpath, string $attribute): ?string
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $node = (new \DOMXPath($document))->query($xpath)->item(0);

        return $node?->getAttribute($attribute);
    }

    private function dialogRequiredIndicatorCount(string $html, string $field): int
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return (new \DOMXPath($document))->query(sprintf(
            '//label[@for="template-form-%s"]//span[contains(concat(" ", normalize-space(@class), " "), " ui-field__required ")]',
            $field
        ))->length;
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

    private function selectOptionIsSelected(string $html, string $select, string $value): bool
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);

        return $xpath->query(sprintf(
            '//select[@name="%s"]/option[@value="%s" and @selected]',
            $select,
            $value
        ))->length === 1;
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
