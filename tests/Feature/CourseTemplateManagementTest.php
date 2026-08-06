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
                ->assertSee('course-template-index-toolbar', false)
                ->assertSee('course-template-index-count', false)
                ->assertSee('course-template-filter-grid', false)
                ->assertSee('course-template-index-table', false)
                ->assertSee('course-template-status-badge', false)
                ->assertSee('admin-table-sequence', false)
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
            ->assertSee('admin-table-sequence', false);

        $secondPage = $this->get('https://tenant-a.localhost/admin/course-templates?page=2')
            ->assertOk()
            ->assertSeeText('Paginated Template 01')
            ->assertDontSeeText('Paginated Template 11')
            ->assertSee('admin-table-sequence', false);

        $firstPageDocument = new \DOMDocument;
        @$firstPageDocument->loadHTML($firstPage->getContent());
        $firstPageSequence = (new \DOMXPath($firstPageDocument))
            ->query('//tbody/tr/td[contains(@class, "admin-table-sequence")]');
        $this->assertCount(10, $firstPageSequence);
        $this->assertSame('10', trim($firstPageSequence->item(9)->textContent));

        $secondPageDocument = new \DOMDocument;
        @$secondPageDocument->loadHTML($secondPage->getContent());
        $secondPageSequence = (new \DOMXPath($secondPageDocument))
            ->query('//tbody/tr/td[contains(@class, "admin-table-sequence")]');
        $this->assertCount(1, $secondPageSequence);
        $this->assertSame('11', trim($secondPageSequence->item(0)->textContent));
    }

    public function test_template_index_uses_standard_empty_states_for_default_and_filtered_lists(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-templates')
            ->assertOk()
            ->assertSee('course-template-empty-state', false)
            ->assertSeeText(__('lf.LF_course_template_common_empty'))
            ->assertSeeText(__('lf.LF_course_template_empty_help'))
            ->assertSeeText(__('lf.LF_course_template_common_create'));

        $this->createTemplate($customerId, 'Existing Template', 'existing-template');

        $this->get('https://tenant-a.localhost/admin/course-templates?keyword=missing')
            ->assertOk()
            ->assertSee('course-template-empty-state', false)
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
            ->assertSeeText('+ Thêm bài học')
            ->assertSeeText('+ Thêm phần học')
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

        $this->assertSame(1, substr_count($formPartial, 'class="admin-form-flow"'));
        $this->assertSame(5, substr_count($formPartial, 'class="admin-form-standard-section"'));
        $this->assertStringContainsString('aria-labelledby="course-template-basic"', $formPartial);
        $this->assertStringContainsString('aria-labelledby="course-template-description"', $formPartial);
        $this->assertStringContainsString('aria-labelledby="course-template-learning"', $formPartial);
        $this->assertStringContainsString('aria-labelledby="course-template-introduction"', $formPartial);
        $this->assertStringContainsString('aria-labelledby="course-template-display"', $formPartial);
        $this->assertMatchesRegularExpression(
            '/aria-labelledby="course-template-display"[\s\S]*?<div class="lf-form-group admin-form-field--full">[\s\S]*?(?:id="status"|LF_course_template_common_status)/',
            $formPartial
        );
        $this->assertSame(4, substr_count($formPartial, 'admin-form-field--full'));
        $this->assertStringNotContainsString('admin-form-subsection', $formPartial);
        $this->assertSame(3, substr_count($formPartial, '<x-authoring-media-row'));
        $this->assertSame(3, substr_count($formPartial, '<x-authoring-media-upload'));
        $this->assertSame(1, substr_count($formPartial, ':presentation="$introImageThumbnail"'));
        $this->assertSame(1, substr_count($formPartial, ':presentation="$introVideoThumbnail"'));
        $this->assertSame(1, substr_count($formPartial, ':presentation="$introDocumentThumbnail"'));
        $this->assertStringNotContainsString('course-template-preview-info', $formPartial);
        $this->assertStringNotContainsString('course-template-preview-name', $formPartial);
        $this->assertStringNotContainsString('course-template-preview-actions', $formPartial);
        $this->assertStringContainsString("preview.mediaType === 'embed'", $formPartial);
        $this->assertStringNotContainsString('syncDefaultSortOrder', $formPartial);
        $this->assertStringNotContainsString('name="sort_order"', $formPartial);

        foreach ($responses as $response) {
            foreach ([
                'category_id',
                'title',
                'publisher_name',
                'status',
            ] as $field) {
                $this->assertSame(
                    1,
                    $this->requiredIndicatorCount($response->getContent(), $field),
                    "Expected {$field} to have one required indicator."
                );
            }

            foreach ([
                'short_description',
                'description',
                'intro_image_file',
                'intro_video_file',
                'intro_document_file',
                'intro_image_media_file_id',
                'intro_video_media_file_id',
                'difficulty_level',
                'estimated_minutes_per_lesson',
                'estimated_lesson_count',
                'sort_order',
            ] as $field) {
                $this->assertSame(
                    0,
                    $this->requiredIndicatorCount($response->getContent(), $field),
                    "Expected {$field} to remain optional."
                );
            }

            $response->assertSeeText(__('lf.LF_course_template_group_basic'))
                ->assertSeeText(__('lf.LF_course_template_group_description'))
                ->assertSeeText(__('lf.LF_course_template_group_learning'))
                ->assertSeeText(__('lf.LF_course_template_group_introduction'));
            $this->assertStringContainsString('admin-form-field-grid--three', $response->getContent());
            $this->assertStringNotContainsString('name="slug"', $response->getContent());
            $this->assertManualSeoControlsNotRendered(
                $response->getContent(),
                'course-template-seo-title',
                'LF_course_template'
            );
        }
    }

    public function test_create_select_defaults_and_edit_saved_values_are_preserved(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'General', 'general');

        $create = $this->actingAs($admin)
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
        $create->assertSee("'lf-select-placeholder': selectedCategoryId === null || selectedCategoryId === ''", false)
            ->assertSee("'lf-select-placeholder': selectedDifficulty === null || selectedDifficulty === ''", false)
            ->assertSee("'lf-select-placeholder': selectedVideoSource === null || selectedVideoSource === ''", false)
            ->assertSee("'has-value': selectedVideoSource", false)
            ->assertSee('class="lf-form-control introduction-video-source"', false);

        $templateId = $this->createTemplate($customerId, 'Saved Selects', 'unused', $admin->id);
        DB::table('core_course_templates')->where('id', $templateId)->update([
            'category_id' => $categoryId,
            'difficulty_level' => 'advanced',
            'status' => 'active',
        ]);

        $edit = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk();
        $this->assertTrue($this->selectOptionIsSelected($edit->getContent(), 'category_id', (string) $categoryId));
        $this->assertTrue($this->selectOptionIsSelected($edit->getContent(), 'difficulty_level', 'advanced'));
        $this->assertTrue($this->selectOptionIsSelected($edit->getContent(), 'status', 'active'));
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
            ->get('https://tenant-a.localhost/admin/course-templates/create')
            ->assertOk()->assertSeeText('Tạo Template')->getContent();
        $edit = $this->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()->assertSeeText('Lưu thay đổi')->getContent();
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*>\s*Tạo Template\s*<\/button>/', $create);
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*>\s*Lưu thay đổi\s*<\/button>/', $edit);
        $this->assertStringContainsString('class="admin-form-footer"', $create);
        $this->assertStringContainsString('class="admin-form-footer"', $edit);
        $this->assertStringContainsString('class="admin-form-footer-danger"', $edit);
        $this->assertStringContainsString('class="admin-form-footer-primary"', $edit);
        $this->assertStringContainsString('form="course-template-update-form"', $edit);

        $englishCreate = $this->withSession(['locale' => 'en'])->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/course-templates/create')
            ->assertOk()->assertSeeText('Create Template')->getContent();
        $englishEdit = $this->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()->assertSeeText('Save Changes')->getContent();
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*>\s*Create Template\s*<\/button>/', $englishCreate);
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*>\s*Save Changes\s*<\/button>/', $englishEdit);
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
            ->assertSee('value="Minimal Template"', false);
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
                "https://tenant-a.localhost/admin/course-templates/{$otherTemplateId}"
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
                "https://tenant-a.localhost/admin/course-templates/{$templateId}"
            )
            ->assertRedirect();

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

    public function test_edit_renders_real_trusted_youtube_thumbnail(): void
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
            ->assertSee('data-media-thumbnail-state="provider_video_thumbnail"', false)
            ->assertSee('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', false)
            ->assertSee('<img class="media-thumbnail-image"', false)
            ->assertDontSee('<iframe class="media-thumbnail', false);
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
