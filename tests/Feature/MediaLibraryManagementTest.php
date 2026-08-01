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

class MediaLibraryManagementTest extends TestCase
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
            'media.region' => 'ap-southeast-1',
        ]);

        Carbon::setTestNow('2026-07-08 10:00:00');
        Storage::fake('media_local');
        TenantContext::set(null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::set(null);

        parent::tearDown();
    }

    public function test_media_library_displays_category_uploads(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-categories', $this->validCategoryData([
                'name' => 'TOPIK Beginner',
                'slug' => 'topik-beginner',
                'thumbnail_image_file' => UploadedFile::fake()->image('topik-thumbnail.png', 120, 120),
                'banner_image_file' => UploadedFile::fake()->image('topik-banner.png', 1200, 320),
            ]))
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media')
            ->assertOk()
            ->assertSeeText(__('lf.LF_media_file_common_title'))
            ->assertSeeText(__('lf.LF_navigation_menu_admin_media'))
            ->assertSee('media-library-filter-grid', false)
            ->assertSee('media-library-tab-count', false)
            ->assertSee('media-library-index-table', false)
            ->assertSee('media-library-index-actions', false)
            ->assertSee('media-library-sequence-number', false)
            ->assertSee('media-library-preview-button', false)
            ->assertDontSee('name="type"', false)
            ->assertSeeTextInOrder([
                __('lf.LF_media_file_common_keyword'),
                __('lf.LF_media_file_common_owner_type'),
                __('lf.LF_media_file_usage_status'),
            ])
            ->assertDontSee('name="usage_type"', false)
            ->assertSeeText('TOPIK Beginner')
            ->assertSeeText(__('lf.LF_media_usage_label_course_category'));

        $componentsCss = file_get_contents(resource_path('css/admin/admin-components.css'));
        $pagesCss = file_get_contents(resource_path('css/admin/admin-pages.css'));
        $this->assertStringContainsString(
            '.lf-admin-page .media-library-index-table .admin-table-sequence',
            $componentsCss
        );
        $this->assertStringContainsString('max-width: 72px;', $componentsCss);
        $this->assertStringContainsString(
            '.media-library-index-table tbody tr:hover > td',
            $pagesCss
        );
        $this->assertStringContainsString(
            '.media-library-index-table tbody tr:hover .media-library-preview-button .media-library-preview-overlay',
            $pagesCss
        );
        $this->assertStringContainsString(
            '.media-library-tabs .admin-tab.is-active .media-library-tab-count',
            $pagesCss
        );

        $this->assertSame(2, DB::table('media_files')->where('customer_id', $customerId)->count());
        $this->assertSame(2, DB::table('media_file_usages')->where('customer_id', $customerId)->count());
    }

    public function test_media_library_uses_standard_empty_state(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media')
            ->assertOk()
            ->assertSee('media-library-empty-state', false)
            ->assertSeeText(__('lf.LF_media_file_common_empty'))
            ->assertSeeText(__('lf.LF_media_file_empty_help'));
    }

    public function test_media_library_tabs_filter_file_types(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin);
        TenantContext::set((object) ['id' => $customerId]);
        $this->uploadManagedMedia($admin, 'Image Asset', 'image', 'image.png', 'course_category', 101, 'thumbnail');
        $this->uploadManagedMedia($admin, 'Video Asset', 'video', 'video.mp4', 'course_activity', 201, 'video');
        $this->uploadManagedMedia($admin, 'Document Asset', 'document', 'document.pdf', 'course_activity', 301, 'document');
        $this->uploadManagedMedia($admin, 'Audio Asset', 'audio', 'audio.mp3', 'course_activity', 401, 'audio');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?tab=images')
            ->assertOk()
            ->assertSeeText('Image Asset')
            ->assertDontSeeText('Video Asset')
            ->assertDontSeeText('Document Asset')
            ->assertDontSeeText('Audio Asset');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?tab=videos')
            ->assertOk()
            ->assertSeeText('Video Asset')
            ->assertSee('media-library-preview-button', false)
            ->assertSeeText('Video')
            ->assertSee('expiration=', false)
            ->assertSee('media\\/files\\/', false)
            ->assertSee('openMediaPreview(', false)
            ->assertSee('preload="metadata"', false)
            ->assertDontSee('<video controls preload="metadata">', false)
            ->assertDontSee('/storage/tenants/', false)
            ->assertDontSeeText('Image Asset');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?tab=audio')
            ->assertOk()
            ->assertSeeText('Audio Asset')
            ->assertSee('data-media-thumbnail-kind="audio"', false)
            ->assertSee('media-library-preview-overlay', false)
            ->assertSee('openMediaPreview(', false)
            ->assertSee('x-ref="audioPreviewPlayer"', false)
            ->assertSeeText('Hoạt động khóa học')
            ->assertDontSeeText('#401')
            ->assertDontSee('media-library-used-by', false)
            ->assertSee('media-library-usage-summary', false)
            ->assertDontSeeText('Document Asset');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?tab=documents')
            ->assertOk()
            ->assertSeeText('Document Asset')
            ->assertSee('data-media-thumbnail-kind="pdf"', false)
            ->assertSee('media-library-preview-overlay', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertDontSeeText('Audio Asset');
    }

    public function test_video_media_can_be_previewed_through_signed_private_url(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin);
        TenantContext::set((object) ['id' => $customerId]);

        $mediaFile = $this->uploadUnattachedMedia(
            $admin,
            'Preview Video',
            'video',
            'preview-video.mp4'
        );

        $response = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?tab=videos')
            ->assertOk()
            ->assertSeeText('Preview Video')
            ->assertSee('media-library-preview-button', false)
            ->assertSeeText('Video')
            ->assertSee('expiration=', false)
            ->assertSee('media\\/files\\/'.$mediaFile->id.'\\/signed', false)
            ->assertSee('openMediaPreview(', false)
            ->assertSee('media-library-preview-title', false)
            ->assertSee('preload="metadata"', false)
            ->assertDontSee('/storage/tenants/', false)
            ->assertDontSee('public_url', false);

        $this->assertSame(0, $this->tableMediaElementCount($response->getContent(), 'video'));
        $this->assertSame(0, $this->tableMediaElementCount($response->getContent(), 'img'));
        $this->assertSame(1, $this->mediaLibraryPreviewButtonCount($response->getContent(), 2));
        $this->assertSame(0, $this->mediaLibraryPreviewButtonCount($response->getContent(), 3));
        $this->assertMediaLibraryPreviewButtonCss();
        $this->assertSame(1, substr_count($response->getContent(), '<video'));
        $this->assertStringContainsString('this.resetMediaPreview()', $response->getContent());
        $this->assertStringContainsString('this.$refs.videoPreviewSource?.setAttribute(\'src\', url)', $response->getContent());
        $this->assertStringContainsString('this.$refs.videoPreviewPlayer?.load()', $response->getContent());
        $this->assertStringContainsString('this.previewOpen = true', $response->getContent());
        $this->assertStringContainsString('playVideoPreview()', $response->getContent());
        $this->assertStringContainsString('player.play()', $response->getContent());
        $this->assertStringContainsString('player.muted = true', $response->getContent());
        $this->assertStringContainsString('player.muted = false', $response->getContent());
        $this->assertStringContainsString('player.pause()', $response->getContent());
        $this->assertStringContainsString('removeAttribute(\'src\')', $response->getContent());
        $this->assertStringContainsString('this.videoSrc = \'\'', $response->getContent());
        $this->assertStringContainsString('player.load()', $response->getContent());

        $signedUrl = app(MediaService::class)->generateSignedUrl((int) $mediaFile->id);

        $this->assertStringContainsString('expiration=', $signedUrl);
        $this->assertStringContainsString('/media/files/'.$mediaFile->id.'/signed', $signedUrl);
        $this->assertStringNotContainsString('/storage/tenants/', $signedUrl);
        $this->assertStringContainsString((string) $mediaFile->id, $signedUrl);

        $this->get($signedUrl)
            ->assertOk()
            ->assertHeader('content-type', 'video/mp4')
            ->assertHeader('accept-ranges', 'bytes');

        $unsignedResponse = $this->get(
            "https://tenant-a.localhost/media/files/{$mediaFile->id}/signed"
        );
        $this->assertContains($unsignedResponse->getStatusCode(), [403, 404]);

        $wrongTenantResponse = $this->get(
            str_replace('tenant-a.', 'tenant-b.', $signedUrl)
        );
        $this->assertContains($wrongTenantResponse->getStatusCode(), [403, 404]);
    }

    public function test_image_preview_still_uses_signed_private_media_route(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin);
        TenantContext::set((object) ['id' => $customerId]);

        $mediaFile = $this->uploadUnattachedMedia(
            $admin,
            'Preview Image',
            'image',
            'preview-image.png'
        );

        $response = $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?tab=images')
            ->assertOk()
            ->assertSeeText('Preview Image')
            ->assertSee('media-library-preview-button', false)
            ->assertSee('media\\/files\\/'.$mediaFile->id.'\\/signed', false)
            ->assertSee('openMediaPreview(', false)
            ->assertSee('loading="lazy"', false)
            ->assertSee('decoding="async"', false)
            ->assertSee('media-thumbnail-compact', false)
            ->assertSee('data-media-thumbnail-kind="image"', false)
            ->assertSee('media-library-modal-image', false)
            ->assertSee('media-library-preview-title', false)
            ->assertDontSee('target="_blank"', false)
            ->assertDontSee('/storage/tenants/', false);

        $this->assertSame(1, $this->tableMediaElementCount($response->getContent(), 'img'));
        $this->assertSame(0, $this->tableMediaElementCount($response->getContent(), 'video'));
        $this->assertSame(1, $this->mediaLibraryPreviewButtonCount($response->getContent(), 2));
        $this->assertSame(0, $this->mediaLibraryPreviewButtonCount($response->getContent(), 3));
        $this->assertMediaLibraryPreviewButtonCss();
        $this->assertStringContainsString('preview.mediaType === \'image\'', $response->getContent());

        $signedUrl = app(MediaService::class)->generateSignedUrl((int) $mediaFile->id);

        $this->assertStringContainsString('/media/files/'.$mediaFile->id.'/signed', $signedUrl);
        $this->assertStringNotContainsString('/storage/tenants/', $signedUrl);

        $this->get($signedUrl)
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    private function tableMediaElementCount(string $html, string $tagName): int
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return (new \DOMXPath($document))
            ->query('//tbody//'.$tagName)
            ->length;
    }

    private function mediaLibraryPreviewButtonCount(string $html, int $columnIndex): int
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return (new \DOMXPath($document))
            ->query(
                '//tbody/tr/td['.$columnIndex.']'
                .'//*[contains(concat(" ", normalize-space(@class), " "), " media-library-preview-button ")]'
            )
            ->length;
    }

    private function assertMediaLibraryPreviewButtonCss(): void
    {
        $css = file_get_contents(base_path('resources/css/admin/admin-pages.css'));

        $this->assertStringContainsString('.media-library-preview-button {', $css);
        $this->assertStringContainsString('.media-library-preview-button:focus-visible {', $css);
    }

    public function test_media_library_filters_by_owner_and_keyword(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCourseCategory($customerId, 'TOPIK Beginner', 'topik-beginner');

        $this->actingAs($admin);
        TenantContext::set((object) ['id' => $customerId]);
        $this->uploadManagedMedia($admin, 'Category Thumbnail', 'image', 'category.png', 'course_category', $categoryId, 'thumbnail');
        $this->uploadManagedMedia($admin, 'Activity Document', 'document', 'activity.pdf', 'course_activity', 301, 'document');
        $this->uploadManagedMedia($admin, 'Published Template Asset', 'audio', 'published-template.mp3', 'course_template_version', 901, 'audio');
        $this->uploadManagedMedia($admin, 'Published Activity Asset', 'video', 'published-activity.mp4', 'course_version_activity', 902, 'video');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?owner_type=course_category')
            ->assertOk()
            ->assertSeeText('Category Thumbnail')
            ->assertSeeText('Danh mục sản phẩm')
            ->assertSeeText('1 nơi sử dụng')
            ->assertDontSeeText('Lesson Document');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?owner_type=course_template')
            ->assertOk()
            ->assertSeeText('Published Template Asset')
            ->assertDontSeeText('Published Activity Asset')
            ->assertDontSee('value="course_template_version"', false)
            ->assertDontSee('value="course_version_activity"', false);

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?owner_type=course_activity')
            ->assertOk()
            ->assertSeeText('Activity Document')
            ->assertSeeText('Published Activity Asset')
            ->assertDontSeeText('Published Template Asset');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?keyword=Activity')
            ->assertOk()
            ->assertSeeText('Activity Document')
            ->assertDontSeeText('Category Thumbnail');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?keyword=category.png')
            ->assertOk()
            ->assertSeeText('Category Thumbnail')
            ->assertDontSeeText('Activity Document');
    }

    public function test_existing_category_uploads_remain_functional_after_media_library_enhancement(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post('https://tenant-a.localhost/admin/course-categories', $this->validCategoryData([
                'name' => 'Media Korean',
                'slug' => 'media-korean',
                'thumbnail_image_file' => UploadedFile::fake()->image('category-thumbnail.png', 120, 120),
            ]))
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $categoryId = (int) DB::table('core_course_categories')
            ->where('customer_id', $customerId)
            ->where('slug', 'media-korean')
            ->value('id');

        $this->assertDatabaseHas('media_file_usages', [
            'customer_id' => $customerId,
            'owner_type' => 'course_category',
            'owner_id' => $categoryId,
            'usage_type' => 'thumbnail',
            'status' => 'active',
        ]);
    }

    public function test_media_library_permissions_remain_admin_only(): void
    {
        $customerId = $this->createTenant();
        $student = $this->createUser($customerId, 'student');
        $teacher = $this->createUser($customerId, 'teacher');

        $this->get('https://tenant-a.localhost/admin/media')
            ->assertRedirect('https://tenant-a.localhost/login');

        $this->actingAs($student)
            ->get('https://tenant-a.localhost/admin/media')
            ->assertForbidden();

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/admin/media')
            ->assertForbidden();
    }

    public function test_cannot_delete_used_media_file(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin);
        TenantContext::set((object) ['id' => $customerId]);

        $mediaFile = $this->uploadManagedMedia(
            $admin,
            'Used Image',
            'image',
            'used-image.png',
            'course_category',
            101,
            'thumbnail'
        );

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media')
            ->assertOk()
            ->assertSeeText('Used Image')
            ->assertDontSee("admin/media/{$mediaFile->id}", false);

        $this->actingAs($admin)
            ->delete("https://tenant-a.localhost/admin/media/{$mediaFile->id}")
            ->assertSessionHasErrors('media_file_id');

        $this->assertDatabaseHas('media_files', [
            'id' => $mediaFile->id,
            'customer_id' => $customerId,
            'status' => 'ready',
        ]);
        Storage::disk('media_local')->assertExists($mediaFile->storage_key);
    }

    public function test_media_library_filters_by_usage_status(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin);
        TenantContext::set((object) ['id' => $customerId]);

        $this->uploadManagedMedia(
            $admin,
            'Used Filter Image',
            'image',
            'used-filter.png',
            'course_category',
            101,
            'thumbnail'
        );
        $this->uploadUnattachedMedia(
            $admin,
            'Unused Filter Document',
            'document',
            'unused-filter.pdf'
        );

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?usage_status=in_use')
            ->assertOk()
            ->assertSeeText('Used Filter Image')
            ->assertDontSeeText('Unused Filter Document');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?usage_status=unused')
            ->assertOk()
            ->assertSeeText('Unused Filter Document')
            ->assertDontSeeText('Used Filter Image')
            ->assertSee('media-library-selection-checkbox', false)
            ->assertSeeText(__('lf.LF_media_file_bulk_delete'));
    }

    public function test_can_bulk_delete_unused_media_files(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin);
        TenantContext::set((object) ['id' => $customerId]);

        $first = $this->uploadUnattachedMedia($admin, 'Bulk First', 'image', 'bulk-first.png');
        $second = $this->uploadUnattachedMedia($admin, 'Bulk Second', 'document', 'bulk-second.pdf');

        $this->actingAs($admin)
            ->delete('https://tenant-a.localhost/admin/media/bulk', [
                'media_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect('https://tenant-a.localhost/admin/media?usage_status=unused')
            ->assertSessionHas(
                'success',
                trans_choice('lf.LF_media_file_bulk_deleted', 2, ['count' => 2])
            );

        foreach ([$first, $second] as $mediaFile) {
            $this->assertDatabaseHas('media_files', [
                'id' => $mediaFile->id,
                'customer_id' => $customerId,
                'status' => 'deleted',
            ]);
            Storage::disk('media_local')->assertMissing($mediaFile->storage_key);
        }
    }

    public function test_bulk_delete_is_atomic_when_selection_contains_used_media(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin);
        TenantContext::set((object) ['id' => $customerId]);

        $unused = $this->uploadUnattachedMedia($admin, 'Bulk Unused', 'document', 'bulk-unused.pdf');
        $used = $this->uploadManagedMedia(
            $admin,
            'Bulk Used',
            'image',
            'bulk-used.png',
            'course_category',
            102,
            'thumbnail'
        );

        $this->actingAs($admin)
            ->delete('https://tenant-a.localhost/admin/media/bulk', [
                'media_ids' => [$unused->id, $used->id],
            ])
            ->assertSessionHasErrors('media_ids');

        foreach ([$unused, $used] as $mediaFile) {
            $this->assertDatabaseHas('media_files', [
                'id' => $mediaFile->id,
                'customer_id' => $customerId,
                'status' => 'ready',
            ]);
            Storage::disk('media_local')->assertExists($mediaFile->storage_key);
        }
    }

    public function test_bulk_delete_rejects_media_from_another_tenant_without_partial_delete(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');

        $this->actingAs($admin);
        TenantContext::set((object) ['id' => $customerId]);
        $ownMedia = $this->uploadUnattachedMedia($admin, 'Own Bulk Media', 'image', 'own-bulk.png');

        $this->actingAs($otherAdmin);
        TenantContext::set((object) ['id' => $otherCustomerId]);
        $otherMedia = $this->uploadUnattachedMedia($otherAdmin, 'Other Bulk Media', 'image', 'other-bulk.png');

        $this->actingAs($admin)
            ->delete('https://tenant-a.localhost/admin/media/bulk', [
                'media_ids' => [$ownMedia->id, $otherMedia->id],
            ])
            ->assertSessionHasErrors('media_ids');

        foreach ([$ownMedia, $otherMedia] as $mediaFile) {
            $this->assertDatabaseHas('media_files', [
                'id' => $mediaFile->id,
                'status' => 'ready',
            ]);
            Storage::disk('media_local')->assertExists($mediaFile->storage_key);
        }
    }

    public function test_can_delete_unused_media_file(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin);
        TenantContext::set((object) ['id' => $customerId]);

        $mediaFile = $this->uploadUnattachedMedia(
            $admin,
            'Unused Image',
            'image',
            'unused-image.png'
        );

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media')
            ->assertOk()
            ->assertSeeText('Unused Image')
            ->assertSee("admin/media/{$mediaFile->id}", false)
            ->assertSeeText(__('lf.LF_media_file_common_delete_confirm'))
            ->assertSeeText(__('lf.LF_media_file_delete_confirm_warning'))
            ->assertDontSee('onclick="return confirm', false);

        $this->actingAs($admin)
            ->delete("https://tenant-a.localhost/admin/media/{$mediaFile->id}")
            ->assertRedirect('https://tenant-a.localhost/admin/media')
            ->assertSessionHas('success', __('lf.LF_media_file_common_deleted'));

        $this->assertDatabaseHas('media_files', [
            'id' => $mediaFile->id,
            'customer_id' => $customerId,
            'status' => 'deleted',
        ]);
        Storage::disk('media_local')->assertMissing($mediaFile->storage_key);

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media')
            ->assertOk()
            ->assertDontSeeText('Unused Image');
    }

    public function test_delete_unused_media_with_missing_storage_object_does_not_crash(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin);
        TenantContext::set((object) ['id' => $customerId]);

        $mediaFile = $this->uploadUnattachedMedia(
            $admin,
            'Missing Object Video',
            'video',
            'missing-object.mp4'
        );

        Storage::disk('media_local')->delete($mediaFile->storage_key);
        Storage::disk('media_local')->assertMissing($mediaFile->storage_key);

        $this->actingAs($admin)
            ->delete("https://tenant-a.localhost/admin/media/{$mediaFile->id}")
            ->assertRedirect('https://tenant-a.localhost/admin/media')
            ->assertSessionHas('success', __('lf.LF_media_file_common_deleted'));

        $this->assertDatabaseHas('media_files', [
            'id' => $mediaFile->id,
            'customer_id' => $customerId,
            'status' => 'deleted',
        ]);
    }

    public function test_cannot_delete_media_from_another_tenant(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');

        $this->actingAs($otherAdmin);
        TenantContext::set((object) ['id' => $otherCustomerId]);
        $otherMediaFile = $this->uploadUnattachedMedia(
            $otherAdmin,
            'Tenant B Image',
            'image',
            'tenant-b-image.png'
        );

        $this->actingAs($admin)
            ->delete("https://tenant-a.localhost/admin/media/{$otherMediaFile->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('media_files', [
            'id' => $otherMediaFile->id,
            'customer_id' => $otherCustomerId,
            'status' => 'ready',
        ]);
        Storage::disk('media_local')->assertExists($otherMediaFile->storage_key);
    }

    private function uploadManagedMedia(
        User $user,
        string $displayName,
        string $fileType,
        string $filename,
        string $ownerType,
        int $ownerId,
        string $usageType
    ): object {
        $service = app(MediaService::class);
        $mediaFile = $service->uploadMedia(
            $this->fakeUpload($fileType, $filename),
            [
                'file_type' => $fileType,
                'module' => 'course',
                'entity_type' => str($ownerType)->replace('_', '-')->toString(),
                'entity_id' => $ownerId,
                'purpose' => $usageType,
                'display_name' => $displayName,
            ],
            $user->id
        );

        $service->attachUsage((int) $mediaFile->id, $ownerType, $ownerId, $usageType);

        return $mediaFile;
    }

    private function uploadUnattachedMedia(
        User $user,
        string $displayName,
        string $fileType,
        string $filename
    ): object {
        return app(MediaService::class)->uploadMedia(
            $this->fakeUpload($fileType, $filename),
            [
                'file_type' => $fileType,
                'module' => 'media',
                'entity_type' => 'library',
                'entity_id' => 1,
                'purpose' => 'upload',
                'display_name' => $displayName,
            ],
            $user->id
        );
    }

    private function fakeUpload(string $fileType, string $filename): UploadedFile
    {
        return match ($fileType) {
            'image' => UploadedFile::fake()->image($filename, 120, 80),
            'video' => UploadedFile::fake()->create($filename, 32, 'video/mp4'),
            'audio' => UploadedFile::fake()->create($filename, 32, 'audio/mpeg'),
            default => UploadedFile::fake()->create($filename, 32, 'application/pdf'),
        };
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

    private function createCourseCategory(int $customerId, string $name, string $slug): int
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

    private function validCategoryData(array $overrides = []): array
    {
        return array_merge([
            'parent_id' => null,
            'name' => 'Programming',
            'slug' => 'programming',
            'description' => null,
            'thumbnail_image' => null,
            'banner_image' => null,
            'sort_order' => 0,
            'is_featured' => 0,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'status' => 'active',
        ], $overrides);
    }
}
