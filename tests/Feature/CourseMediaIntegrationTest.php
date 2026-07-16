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
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CourseMediaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://tenant-a.localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
            'media.disk' => 'media_local',
            'media.bucket' => 'lf-test-media',
            'media.region' => 'ap-southeast-1',
            'media.signed_url_ttl_minutes' => 5,
        ]);

        Carbon::setTestNow('2026-07-05 09:00:00');
        URL::forceRootUrl('https://tenant-a.localhost');
        URL::forceScheme('https');
        Storage::fake('media_local');
        TenantContext::set(null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        TenantContext::set(null);

        parent::tearDown();
    }

    public function test_course_template_create_rolls_back_when_media_synchronization_throws(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $imageId = $this->createMediaFile(
            $customerId,
            $admin->id,
            'image',
            'create-rollback.png',
            'image/png'
        );
        $mediaService = \Mockery::mock(MediaService::class)->makePartial();
        $mediaService->shouldReceive('attachUsage')
            ->once()
            ->andThrow(new \RuntimeException('Injected media synchronization failure.'));
        $this->app->instance(MediaService::class, $mediaService);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Create Must Roll Back',
                    'intro_image_file' => null,
                    'intro_image_media_file_id' => $imageId,
                ])
            );
            $this->fail('Expected media synchronization to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected media synchronization failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('core_course_templates', [
            'customer_id' => $customerId,
            'title' => 'Create Must Roll Back',
        ]);
        $this->assertDatabaseMissing('media_file_usages', [
            'customer_id' => $customerId,
            'media_file_id' => $imageId,
            'owner_type' => 'course_template',
            'usage_type' => 'intro_image',
        ]);
        $this->assertFalse(session()->has('course_template_created_title'));
        $this->assertFalse(session()->has('course_template_created_guidance'));
    }

    public function test_course_template_update_rolls_back_fields_and_usages_when_later_media_sync_throws(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData(['title' => 'Original Atomic Template'])
        )->assertRedirect();
        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Original Atomic Template')
            ->sole();
        $originalImageId = (int) $template->intro_image_media_file_id;
        $replacementImageId = $this->createMediaFile($customerId, $admin->id, 'image', 'replacement.png', 'image/png');
        $replacementVideoId = $this->createMediaFile($customerId, $admin->id, 'video', 'replacement.mp4', 'video/mp4');

        $mediaService = \Mockery::mock(MediaService::class)->makePartial();
        $mediaService->shouldReceive('attachUsage')->byDefault()->passthru();
        $mediaService->shouldReceive('attachUsage')
            ->with($replacementVideoId, 'course_template', (int) $template->id, 'intro_video')
            ->once()
            ->andThrow(new \RuntimeException('Injected later media synchronization failure.'));
        $this->app->instance(MediaService::class, $mediaService);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->put(
                "https://tenant-a.localhost/admin/course-templates/{$template->id}",
                $this->validTemplateData([
                    'title' => 'Mutated Title Must Roll Back',
                    'intro_image_file' => null,
                    'intro_image_media_file_id' => $replacementImageId,
                    'intro_video_source' => 'upload',
                    'intro_video_media_file_id' => $replacementVideoId,
                ])
            );
            $this->fail('Expected later media synchronization to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected later media synchronization failure.', $exception->getMessage());
        }

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $template->id,
            'title' => 'Original Atomic Template',
            'working_revision' => 1,
            'intro_image_media_file_id' => $originalImageId,
            'intro_video_media_file_id' => null,
        ]);
        $this->assertDatabaseHas('media_file_usages', [
            'media_file_id' => $originalImageId,
            'owner_type' => 'course_template',
            'owner_id' => $template->id,
            'usage_type' => 'intro_image',
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('media_file_usages', [
            'media_file_id' => $replacementImageId,
            'owner_type' => 'course_template',
            'owner_id' => $template->id,
            'usage_type' => 'intro_image',
        ]);
    }

    public function test_admin_can_upload_and_view_course_product_cover_image(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-products',
                $this->validProductData([
                    'title' => 'Media Product',
                    'slug' => 'media-product',
                    'cover_image_file' => UploadedFile::fake()->image(
                        'product-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-products/1/edit');

        $productId = (int) DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->where('slug', 'media-product')
            ->value('id');

        $mediaFile = $this->assertActiveUsage(
            $customerId,
            'course_product',
            $productId,
            'cover_image'
        );

        $this->assertSame('media_local', $mediaFile->storage_disk);
        $this->assertStringStartsWith(
            "tenants/{$customerId}/course/products/{$productId}/cover/",
            $mediaFile->storage_key
        );
        $this->assertNull($mediaFile->public_url);
        Storage::disk('media_local')->assertExists($mediaFile->storage_key);
        $this->assertSignedDeliveryUrl($customerId, $mediaFile);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()
            ->assertSeeText('Cover image upload')
            ->assertSeeText('Định dạng: JPG, PNG, GIF, WEBP, SVG')
            ->assertSeeText('Tối đa:')
            ->assertSee('expiration=', false);
    }

    public function test_admin_can_upload_course_template_introduction_image(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Media Template',
                    'intro_image_file' => UploadedFile::fake()->image(
                        'template-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Media Template')
            ->value('id');

        $mediaFile = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'intro_image'
        );
        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'intro_video_source' => null,
            'intro_image_media_file_id' => $mediaFile->id,
            'intro_video_media_file_id' => null,
        ]);

        $this->assertStringStartsWith(
            "tenants/{$customerId}/course/templates/{$templateId}/intro_image/",
            $mediaFile->storage_key
        );
        $this->assertNull($mediaFile->public_url);
        $this->assertSignedDeliveryUrl($customerId, $mediaFile);

        $response = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()
            ->assertSee('name="intro_image_file"', false)
            ->assertSee("/admin/course-templates/{$templateId}/media/image/", false)
            ->assertSee('name="remove_intro_image"', false)
            ->assertSee(__('lf.LF_media_file_common_preview_action'), false)
            ->assertDontSee('course-template-preview-type', false)
            ->assertDontSee('<strong>Media Template</strong>', false)
            ->assertDontSee('/storage/tenants/', false);

    }

    public function test_admin_can_upload_course_template_intro_video(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Video Template',
                    'slug' => 'video-template',
                    'intro_video_source' => 'upload',
                    'intro_image_file' => null,
                    'intro_video_file' => UploadedFile::fake()->create(
                        'intro-video.mp4',
                        32,
                        'video/mp4'
                    ),
                ])
            )
            ->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Video Template')
            ->value('id');

        $mediaFile = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'intro_video'
        );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'intro_video_source' => 'upload',
            'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => $mediaFile->id,
        ]);
        $this->assertStringStartsWith(
            "tenants/{$customerId}/course/templates/{$templateId}/intro_video/",
            $mediaFile->storage_key
        );
        Storage::disk('media_local')->assertExists($mediaFile->storage_key);

        $response = $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()
            ->assertSee('name="intro_video_file"', false)
            ->assertSee("course-templates\\/{$templateId}\\/media\\/video\\/", false)
            ->assertSee('name="remove_intro_video"', false)
            ->assertSee('preload="metadata"', false)
            ->assertSee('this.resetTemplatePreview()', false)
            ->assertSee('this.$refs.templatePreviewVideoSource?.setAttribute(\'src\', url)', false)
            ->assertSee('this.$refs.templatePreviewVideoPlayer?.load()', false)
            ->assertSee('this.previewOpen = true', false)
            ->assertSee('player.play()', false)
            ->assertSee('player.pause()', false)
            ->assertSee('removeAttribute(\'src\')', false)
            ->assertSee('this.videoSrc = \'\'', false)
            ->assertSee('player.load()', false)
            ->assertDontSee('<video controls preload="metadata">', false)
            ->assertDontSee('course-template-preview-type', false)
            ->assertDontSee('<strong>Video Template</strong>', false)
            ->assertDontSee('/storage/tenants/', false);
    }

    public function test_admin_can_open_course_template_page_after_cover_media_rename(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Show Route Video Template',
                    'slug' => 'show-route-video-template',
                    'intro_video_source' => 'upload',
                    'intro_image_file' => null,
                    'intro_video_file' => UploadedFile::fake()->create(
                        'show-route-intro.mp4',
                        32,
                        'video/mp4'
                    ),
                ])
            )
            ->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Show Route Video Template')
            ->value('id');

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}")
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit"
            );

        $this->actingAs($admin)
            ->followingRedirects()
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}")
            ->assertOk()
            ->assertSee('name="intro_video_file"', false)
            ->assertSee("course-templates\\/{$templateId}\\/media\\/video\\/", false);
    }

    public function test_admin_can_update_course_template_from_image_to_intro_video(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Switch Cover Template',
                    'slug' => 'switch-cover-template',
                    'intro_image_file' => UploadedFile::fake()->image(
                        'initial-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Switch Cover Template')
            ->value('id');
        $coverImage = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'intro_image'
        );

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}",
                $this->validTemplateData([
                    '_method' => 'PUT',
                    'title' => 'Switch Cover Template',
                    'slug' => 'switch-cover-template',
                    'intro_video_source' => 'upload',
                    'intro_image_file' => null,
                    'intro_video_file' => UploadedFile::fake()->create(
                        'updated-intro.mp4',
                        32,
                        'video/mp4'
                    ),
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit"
            );

        $introVideo = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'intro_video'
        );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'intro_video_source' => 'upload',
            'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => $introVideo->id,
        ]);
        $this->assertDatabaseHas('media_file_usages', [
            'customer_id' => $customerId,
            'media_file_id' => $coverImage->id,
            'owner_type' => 'course_template',
            'owner_id' => $templateId,
            'usage_type' => 'intro_image',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('media_files', [
            'id' => $coverImage->id,
            'customer_id' => $customerId,
            'status' => 'ready',
        ]);
        $this->assertSame(
            2,
            $this->activeTemplatePreviewUsageCount($customerId, $templateId)
        );
        Storage::disk('media_local')->assertExists($coverImage->storage_key);
    }

    public function test_admin_can_update_course_template_from_intro_video_to_image(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Switch Video Template',
                    'slug' => 'switch-video-template',
                    'intro_video_source' => 'upload',
                    'intro_image_file' => null,
                    'intro_video_file' => UploadedFile::fake()->create(
                        'initial-intro.mp4',
                        32,
                        'video/mp4'
                    ),
                ])
            )
            ->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Switch Video Template')
            ->value('id');
        $introVideo = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'intro_video'
        );
        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}",
                $this->validTemplateData([
                    '_method' => 'PUT',
                    'title' => 'Switch Video Template',
                    'slug' => 'switch-video-template',
                    'intro_video_source' => null,
                    'intro_image_file' => UploadedFile::fake()->image(
                        'replacement-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit"
            );

        $coverImage = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'intro_image'
        );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'intro_video_source' => null,
            'intro_image_media_file_id' => $coverImage->id,
            'intro_video_media_file_id' => null,
        ]);
        $this->assertDatabaseHas('media_file_usages', [
            'customer_id' => $customerId,
            'media_file_id' => $introVideo->id,
            'owner_type' => 'course_template',
            'owner_id' => $templateId,
            'usage_type' => 'intro_video',
            'status' => 'detached',
        ]);
        $this->assertDatabaseHas('media_files', [
            'id' => $introVideo->id,
            'customer_id' => $customerId,
            'status' => 'ready',
        ]);
        $this->assertSame(
            1,
            $this->activeTemplatePreviewUsageCount($customerId, $templateId)
        );
    }

    public function test_admin_can_remove_course_template_cover_image_usage_without_deleting_media_file(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Remove Cover Template',
                    'slug' => 'remove-cover-template',
                    'intro_image_file' => UploadedFile::fake()->image(
                        'remove-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Remove Cover Template')
            ->value('id');
        $coverImage = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'intro_image'
        );

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}",
                $this->validTemplateData([
                    '_method' => 'PUT',
                    'title' => 'Remove Cover Template',
                    'slug' => 'remove-cover-template',
                    'intro_video_source' => null,
                    'intro_image_media_file_id' => $coverImage->id,
                    'intro_image_file' => null,
                    'intro_video_file' => null,
                    'remove_intro_image' => 1,
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit"
            );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => null,
        ]);
        $this->assertDatabaseHas('media_file_usages', [
            'customer_id' => $customerId,
            'media_file_id' => $coverImage->id,
            'owner_type' => 'course_template',
            'owner_id' => $templateId,
            'usage_type' => 'intro_image',
            'status' => 'detached',
        ]);
        $this->assertSame(
            0,
            $this->activeTemplatePreviewUsageCount($customerId, $templateId)
        );
        Storage::disk('media_local')->assertExists($coverImage->storage_key);
    }

    public function test_admin_can_remove_course_template_intro_video_usage_without_deleting_media_file(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Remove Video Template',
                    'slug' => 'remove-video-template',
                    'intro_video_source' => 'upload',
                    'intro_image_file' => null,
                    'intro_video_file' => UploadedFile::fake()->create(
                        'remove-intro.mp4',
                        32,
                        'video/mp4'
                    ),
                ])
            )
            ->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Remove Video Template')
            ->value('id');
        $introVideo = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'intro_video'
        );
        $this->addPublishableContent($customerId, $templateId, $admin);

        $this->actingAs($admin)
            ->post("https://tenant-a.localhost/admin/course-templates/{$templateId}/publish")
            ->assertRedirect();
        $version = DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->sole();

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}",
                $this->validTemplateData([
                    '_method' => 'PUT',
                    'title' => 'Remove Video Template',
                    'slug' => 'remove-video-template',
                    'intro_video_source' => 'upload',
                    'intro_image_file' => null,
                    'intro_video_media_file_id' => $introVideo->id,
                    'intro_video_file' => null,
                    'remove_intro_video' => 1,
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit"
            );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => null,
        ]);
        $this->assertDatabaseHas('media_file_usages', [
            'customer_id' => $customerId,
            'media_file_id' => $introVideo->id,
            'owner_type' => 'course_template',
            'owner_id' => $templateId,
            'usage_type' => 'intro_video',
            'status' => 'detached',
        ]);
        $this->assertDatabaseHas('core_course_template_versions', [
            'id' => $version->id,
            'intro_video_source_snapshot' => 'upload',
            'intro_video_media_file_id_snapshot' => $introVideo->id,
        ]);
        $this->assertDatabaseHas('media_file_usages', [
            'customer_id' => $customerId,
            'media_file_id' => $introVideo->id,
            'owner_type' => 'course_template_version',
            'owner_id' => $version->id,
            'usage_type' => 'intro_video',
            'status' => 'active',
        ]);
        $this->assertSame(
            0,
            $this->activeTemplatePreviewUsageCount($customerId, $templateId)
        );
        Storage::disk('media_local')->assertExists($introVideo->storage_key);
    }

    public function test_admin_can_remove_course_template_document_independently_without_deleting_media_file(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData([
                'title' => 'Remove Document Template',
                'intro_image_file' => UploadedFile::fake()->image('intro.png'),
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => 'https://youtu.be/dQw4w9WgXcQ',
                'intro_document_file' => UploadedFile::fake()->create(
                    'remove.pdf',
                    16,
                    'application/pdf'
                ),
            ])
        )->assertRedirect();

        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Remove Document Template')
            ->sole();
        $document = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $template->id,
            'intro_document'
        );

        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-templates/{$template->id}",
            $this->validTemplateData([
                'title' => 'Remove Document Template',
                'intro_image_media_file_id' => $template->intro_image_media_file_id,
                'intro_image_file' => null,
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => $template->intro_video_embed_url,
                'intro_document_media_file_id' => $document->id,
                'intro_document_file' => null,
                'remove_intro_document' => 1,
            ])
        )->assertRedirect();

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $template->id,
            'intro_image_media_file_id' => $template->intro_image_media_file_id,
            'intro_video_source' => 'embed',
            'intro_video_embed_url' => $template->intro_video_embed_url,
            'intro_document_media_file_id' => null,
        ]);
        $this->assertDatabaseHas('media_file_usages', [
            'media_file_id' => $document->id,
            'owner_type' => 'course_template',
            'owner_id' => $template->id,
            'usage_type' => 'intro_document',
            'status' => 'detached',
        ]);
        Storage::disk('media_local')->assertExists($document->storage_key);
    }

    public function test_admin_can_remove_embedded_youtube_video_without_changing_other_introduction_media(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData([
                'title' => 'Remove YouTube Template',
                'intro_image_file' => UploadedFile::fake()->image('intro.png'),
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => 'https://youtu.be/dQw4w9WgXcQ',
                'intro_document_file' => UploadedFile::fake()->create(
                    'intro.pdf',
                    16,
                    'application/pdf'
                ),
            ])
        )->assertRedirect();

        $template = DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Remove YouTube Template')
            ->sole();
        $imageId = $template->intro_image_media_file_id;
        $documentId = $template->intro_document_media_file_id;

        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-templates/{$template->id}",
            $this->validTemplateData([
                'title' => 'Remove YouTube Template',
                'intro_image_media_file_id' => $imageId,
                'intro_image_file' => null,
                'intro_video_source' => 'embed',
                'intro_video_media_file_id' => 999999,
                'intro_video_embed_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'intro_video_provider' => 'vimeo',
                'intro_document_media_file_id' => $documentId,
                'intro_document_file' => null,
                'remove_intro_video' => '1',
            ])
        )->assertRedirect(
            "https://tenant-a.localhost/admin/course-templates/{$template->id}/edit"
        );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $template->id,
            'intro_image_media_file_id' => $imageId,
            'intro_video_source' => null,
            'intro_video_media_file_id' => null,
            'intro_video_embed_url' => null,
            'intro_video_provider' => null,
            'intro_document_media_file_id' => $documentId,
        ]);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$template->id}/edit")
            ->assertOk()
            ->assertDontSee('name="remove_intro_video"', false)
            ->assertDontSee('https://www.youtube.com/embed/dQw4w9WgXcQ', false)
            ->assertSee('name="remove_intro_image"', false)
            ->assertSee('name="remove_intro_document"', false);
    }

    public function test_admin_can_remove_embedded_vimeo_video_and_preserve_it_when_removal_is_unchecked(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData([
                'title' => 'Remove Vimeo Template',
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => 'https://vimeo.com/76979871',
            ])
        )->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Remove Vimeo Template')
            ->value('id');

        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}",
            $this->validTemplateData([
                'title' => 'Remove Vimeo Template',
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => 'https://vimeo.com/76979871',
            ])
        )->assertRedirect();

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'intro_video_source' => 'embed',
            'intro_video_embed_url' => 'https://vimeo.com/76979871',
            'intro_video_provider' => 'vimeo',
        ]);

        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}",
            $this->validTemplateData([
                'title' => 'Remove Vimeo Template',
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => 'https://vimeo.com/76979871',
                'remove_intro_video' => true,
            ])
        )->assertRedirect();

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'intro_video_source' => null,
            'intro_video_media_file_id' => null,
            'intro_video_embed_url' => null,
            'intro_video_provider' => null,
        ]);
    }

    public function test_valid_video_replacement_wins_over_removal_and_invalid_replacement_preserves_existing_video(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData([
                'title' => 'Video Replacement Precedence',
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => 'https://youtu.be/dQw4w9WgXcQ',
            ])
        )->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Video Replacement Precedence')
            ->value('id');

        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}",
            $this->validTemplateData([
                'title' => 'Video Replacement Precedence',
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => 'https://vimeo.com/76979871',
                'remove_intro_video' => 1,
            ])
        )->assertRedirect();

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'intro_video_source' => 'embed',
            'intro_video_embed_url' => 'https://vimeo.com/76979871',
            'intro_video_provider' => 'vimeo',
        ]);

        $this->actingAs($admin)
            ->from("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->put(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}",
                $this->validTemplateData([
                    'title' => 'Video Replacement Precedence',
                    'intro_video_source' => 'embed',
                    'intro_video_embed_url' => 'https://example.com/video',
                    'remove_intro_video' => 1,
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit"
            )
            ->assertSessionHasErrors('intro_video_embed_url');

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'intro_video_source' => 'embed',
            'intro_video_embed_url' => 'https://vimeo.com/76979871',
            'intro_video_provider' => 'vimeo',
        ]);
    }

    public function test_course_template_removal_labels_are_field_specific_in_vietnamese_and_english(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData([
                'title' => 'Localized Removal Labels',
                'intro_image_file' => UploadedFile::fake()->image('intro.png'),
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => 'https://youtu.be/dQw4w9WgXcQ',
                'intro_document_file' => UploadedFile::fake()->create(
                    'intro.pdf',
                    16,
                    'application/pdf'
                ),
            ])
        )->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Localized Removal Labels')
            ->value('id');
        $url = "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit";

        $this->withSession(['locale' => 'vi'])->actingAs($admin)->get($url)
            ->assertSeeText('Gỡ ảnh hiện tại')
            ->assertSeeText('Gỡ video hiện tại')
            ->assertSeeText('Gỡ tài liệu hiện tại');

        $this->withSession(['locale' => 'en'])->actingAs($admin)->get($url)
            ->assertSeeText('Remove current image')
            ->assertSeeText('Remove current video')
            ->assertSeeText('Remove current document');
    }

    public function test_course_template_accepts_image_and_uploaded_video_together(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-templates/create')
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Invalid Dual Preview Template',
                    'slug' => 'invalid-dual-preview-template',
                    'intro_video_source' => 'upload',
                    'intro_image_file' => UploadedFile::fake()->image(
                        'dual-preview-cover.png',
                        120,
                        80
                    ),
                    'intro_video_file' => UploadedFile::fake()->create(
                        'dual-preview-intro.mp4',
                        32,
                        'video/mp4'
                    ),
                ])
            )
            ->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')->where('customer_id', $customerId)->where('title', 'Invalid Dual Preview Template')->value('id');
        $this->assertDatabaseHas('core_course_templates', [
            'customer_id' => $customerId,
            'id' => $templateId,
            'intro_video_source' => 'upload',
        ]);
        $this->assertActiveUsage($customerId, 'course_template', $templateId, 'intro_image');
        $this->assertActiveUsage($customerId, 'course_template', $templateId, 'intro_video');
    }

    public function test_template_media_fields_use_the_shared_authoring_layout_for_admin_and_teacher(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');

        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData([
                'title' => 'Standard Media Layout',
                'intro_image_file' => UploadedFile::fake()->image('layout.png'),
                'intro_video_source' => 'upload',
                'intro_video_file' => UploadedFile::fake()->create('layout.mp4', 32, 'video/mp4'),
                'intro_document_file' => UploadedFile::fake()->create('layout.pdf', 32, 'application/pdf'),
            ])
        )->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')
            ->where('title', 'Standard Media Layout')->value('id');
        $this->createTemplateAssignment($customerId, $templateId, $teacher->id);

        foreach ([[$admin, 'admin'], [$teacher, 'teacher']] as [$user, $area]) {
            $response = $this->actingAs($user)->get(
                "https://tenant-a.localhost/{$area}/course-templates/{$templateId}/edit"
            )->assertOk()
                ->assertDontSeeText(__('lf.LF_course_template_activity_media_current_file'));
            $html = $response->getContent();

            $this->assertSame(3, $this->htmlElementCount(
                $html,
                '//div[contains(concat(" ", normalize-space(@class), " "), " course-template-information-media ")]'
                .'//div[@data-authoring-media-current-row]'
            ));

            foreach ([
                'intro_image_file' => 'remove_intro_image',
                'intro_video_file' => 'remove_intro_video',
                'intro_document_file' => 'remove_intro_document',
            ] as $uploadName => $removeName) {
                $field = '//div[contains(concat(" ", normalize-space(@class), " "), " course-template-information-media ")]'
                    .'[.//input[@name="'.$uploadName.'"]]';
                $this->assertSame(1, $this->htmlElementCount(
                    $html,
                    $field.'/input[@name="'.$uploadName.'"]'
                    .'[preceding-sibling::div[@data-authoring-media-current-row]]'
                ));
                $this->assertSame(1, $this->htmlElementCount(
                    $html,
                    $field.'/input[@name="'.$uploadName.'"]'
                    .'/following-sibling::*[1]'
                    .'[contains(concat(" ", normalize-space(@class), " "), " authoring-media-help ")]'
                ));
                $this->assertSame(1, $this->htmlElementCount(
                    $html,
                    $field.'//div[@data-authoring-media-current-row]'
                    .'//input[@name="'.$removeName.'"]'
                ));
            }
        }
    }

    public function test_course_template_rejects_cross_tenant_preview_media_id(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $otherVideoId = $this->createMediaFile(
            $otherCustomerId,
            $otherAdmin->id,
            'intro_video',
            'tenant-b-intro.mp4',
            'video/mp4'
        );

        $this->actingAs($admin)
            ->from('https://tenant-a.localhost/admin/course-templates/create')
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Blocked Tenant Preview Template',
                    'slug' => 'blocked-tenant-preview-template',
                    'intro_video_source' => 'upload',
                    'intro_image_file' => null,
                    'intro_video_media_file_id' => $otherVideoId,
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates/create')
            ->assertSessionHasErrors('intro_video_media_file_id');

        $this->assertDatabaseMissing('media_file_usages', [
            'customer_id' => $customerId,
            'media_file_id' => $otherVideoId,
            'owner_type' => 'course_template',
            'usage_type' => 'intro_video',
        ]);
    }

    public function test_browser_style_course_template_intro_video_update_does_not_500(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Browser Video Update Template',
                    'slug' => 'browser-video-update-template',
                    'intro_image_file' => UploadedFile::fake()->image(
                        'browser-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Browser Video Update Template')
            ->value('id');

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()
            ->assertSee('name="intro_video_source"', false)
            ->assertSee('name="intro_video_file"', false)
            ->assertSee(':disabled="selectedVideoSource !== \'upload\'"', false)
            ->assertSee(':disabled="selectedVideoSource !== \'embed\'"', false);

        $response = $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}",
            $this->validTemplateData([
                '_method' => 'PUT',
                'title' => 'Browser Video Update Template',
                'slug' => 'browser-video-update-template',
                'intro_video_source' => 'upload',
                'intro_image_file' => null,
                'intro_video_file' => UploadedFile::fake()->create(
                    'browser-intro.mp4',
                    32,
                    'video/mp4'
                ),
            ])
        );

        $response->assertRedirect(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit"
        );
        $this->assertNotSame(500, $response->getStatusCode());

        $introVideo = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'intro_video'
        );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'intro_video_source' => 'upload',
            'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => $introVideo->id,
        ]);
    }

    public function test_admin_can_update_course_template_intro_video_while_staying_video(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Replace Video Template',
                    'slug' => 'replace-video-template',
                    'intro_video_source' => 'upload',
                    'intro_image_file' => null,
                    'intro_video_file' => UploadedFile::fake()->create(
                        'initial-intro.mp4',
                        32,
                        'video/mp4'
                    ),
                ])
            )
            ->assertRedirect();

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Replace Video Template')
            ->value('id');
        $initialVideo = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'intro_video'
        );

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}",
                $this->validTemplateData([
                    '_method' => 'PUT',
                    'title' => 'Replace Video Template',
                    'slug' => 'replace-video-template',
                    'intro_video_source' => 'upload',
                    'intro_image_file' => null,
                    'intro_video_media_file_id' => $initialVideo->id,
                    'intro_video_file' => UploadedFile::fake()->create(
                        'replacement-intro.mp4',
                        48,
                        'video/mp4'
                    ),
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit"
            );

        $replacementVideo = DB::table('media_file_usages')
            ->join('media_files', function ($join): void {
                $join->on(
                    'media_files.id',
                    '=',
                    'media_file_usages.media_file_id'
                )->on(
                    'media_files.customer_id',
                    '=',
                    'media_file_usages.customer_id'
                );
            })
            ->where('media_file_usages.customer_id', $customerId)
            ->where('media_file_usages.owner_type', 'course_template')
            ->where('media_file_usages.owner_id', $templateId)
            ->where('media_file_usages.usage_type', 'intro_video')
            ->where('media_file_usages.status', 'active')
            ->select('media_files.*')
            ->sole();

        $this->assertNotSame($initialVideo->id, $replacementVideo->id);
        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'intro_video_source' => 'upload',
            'intro_image_media_file_id' => null,
            'intro_video_media_file_id' => $replacementVideo->id,
        ]);
        $this->assertDatabaseHas('media_file_usages', [
            'customer_id' => $customerId,
            'media_file_id' => $initialVideo->id,
            'owner_type' => 'course_template',
            'owner_id' => $templateId,
            'usage_type' => 'intro_video',
            'status' => 'detached',
        ]);
    }

    public function test_admin_can_upload_and_view_cohort_document_and_attachment(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-cohorts',
                $this->validCohortData([
                    'name' => 'Media Cohort',
                    'cohort_document_file' => UploadedFile::fake()->create(
                        'cohort-document.pdf',
                        32,
                        'application/pdf'
                    ),
                    'cohort_attachment_file' => UploadedFile::fake()->create(
                        'cohort-attachment.pdf',
                        32,
                        'application/pdf'
                    ),
                ])
            )
            ->assertRedirect();

        $cohortId = (int) DB::table('core_course_cohorts')
            ->where('customer_id', $customerId)
            ->where('name', 'Media Cohort')
            ->value('id');

        foreach (['document', 'attachment'] as $usageType) {
            $mediaFile = $this->assertActiveUsage(
                $customerId,
                'course_cohort',
                $cohortId,
                $usageType
            );

            $this->assertStringStartsWith(
                "tenants/{$customerId}/course/cohorts/{$cohortId}/",
                $mediaFile->storage_key
            );
            $this->assertSignedDeliveryUrl($customerId, $mediaFile);
        }

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-cohorts/{$cohortId}")
            ->assertOk()
            ->assertSeeText('Cohort media')
            ->assertSeeText('document')
            ->assertSeeText('attachment')
            ->assertSee('expiration=', false);
    }

    public function test_course_template_preview_allows_admin_and_assigned_teacher_for_exact_active_slots(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $teacher = $this->createUser($customerId, 'teacher');
        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData([
                'title' => 'Authorized Preview Template',
                'intro_video_source' => 'upload',
                'intro_video_file' => UploadedFile::fake()->create('intro.mp4', 16, 'video/mp4'),
                'intro_document_file' => UploadedFile::fake()->create('intro.pdf', 16, 'application/pdf'),
            ])
        )->assertRedirect();
        $template = DB::table('core_course_templates')->where('title', 'Authorized Preview Template')->sole();
        $this->createTemplateAssignment($customerId, $template->id, $teacher->id);

        foreach ([
            'image' => $template->intro_image_media_file_id,
            'video' => $template->intro_video_media_file_id,
            'document' => $template->intro_document_media_file_id,
        ] as $slot => $mediaId) {
            $adminUrl = route('admin.course-templates.media.preview', [$template->id, $slot, $mediaId]);
            $teacherUrl = route('teacher.course-templates.media.preview', [$template->id, $slot, $mediaId]);

            $this->actingAs($admin)->get($adminUrl)->assertOk();
            $this->actingAs($teacher)->get($teacherUrl)->assertOk();
        }
    }

    public function test_course_template_preview_rejects_unassigned_teacher_cross_tenant_and_wrong_owner(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $unassignedTeacher = $this->createUser($customerId, 'teacher');
        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData(['title' => 'Protected Preview Template'])
        )->assertRedirect();
        $template = DB::table('core_course_templates')->where('title', 'Protected Preview Template')->sole();
        $mediaId = (int) $template->intro_image_media_file_id;

        $this->actingAs($unassignedTeacher)
            ->get(route('teacher.course-templates.media.preview', [$template->id, 'image', $mediaId]))
            ->assertNotFound();

        $otherTemplateId = $this->createTemplate($customerId, 'Other Preview Template', 'unused', $admin->id);
        $this->actingAs($admin)
            ->get(route('admin.course-templates.media.preview', [$otherTemplateId, 'image', $mediaId]))
            ->assertNotFound();

        $otherCustomerId = $this->createTenant('tenant-b');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $this->actingAs($otherAdmin)
            ->get("https://tenant-b.localhost/admin/course-templates/{$template->id}/media/image/{$mediaId}")
            ->assertNotFound();
    }

    public function test_course_template_preview_rejects_wrong_purpose_detached_archived_and_unrelated_usage(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData(['title' => 'Purpose Isolated Preview'])
        )->assertRedirect();
        $template = DB::table('core_course_templates')->where('title', 'Purpose Isolated Preview')->sole();
        $mediaId = (int) $template->intro_image_media_file_id;
        $url = route('admin.course-templates.media.preview', [$template->id, 'image', $mediaId]);

        DB::table('media_file_usages')->where('owner_type', 'course_template')->where('owner_id', $template->id)->where('media_file_id', $mediaId)->update(['usage_type' => 'intro_video']);
        $this->actingAs($admin)->get($url)->assertNotFound();

        DB::table('media_file_usages')->where('owner_type', 'course_template')->where('owner_id', $template->id)->where('media_file_id', $mediaId)->update(['usage_type' => 'intro_image', 'status' => 'detached']);
        DB::table('media_file_usages')->insert([
            'customer_id' => $customerId,
            'media_file_id' => $mediaId,
            'owner_type' => 'course_product',
            'owner_id' => 9999,
            'usage_type' => 'cover_image',
            'status' => 'active',
            'metadata' => null,
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('media_file_usages')->where('owner_type', 'course_template')->where('owner_id', $template->id)->where('media_file_id', $mediaId)->update(['status' => 'detached']);
        $this->actingAs($admin)->get($url)->assertNotFound();

        DB::table('media_file_usages')->where('owner_type', 'course_template')->where('owner_id', $template->id)->where('media_file_id', $mediaId)->update(['status' => 'archived']);
        $this->actingAs($admin)->get($url)->assertNotFound();
    }

    public function test_course_template_preview_rejects_non_ready_media_and_slot_type_mismatches(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData([
                'title' => 'Typed Preview Template',
                'intro_video_source' => 'upload',
                'intro_video_file' => UploadedFile::fake()->create('typed.mp4', 16, 'video/mp4'),
                'intro_document_file' => UploadedFile::fake()->create('typed.pdf', 16, 'application/pdf'),
            ])
        )->assertRedirect();
        $template = DB::table('core_course_templates')->where('title', 'Typed Preview Template')->sole();

        $this->actingAs($admin)->get(route('admin.course-templates.media.preview', [$template->id, 'video', $template->intro_image_media_file_id]))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.course-templates.media.preview', [$template->id, 'image', $template->intro_video_media_file_id]))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.course-templates.media.preview', [$template->id, 'image', $template->intro_document_media_file_id]))->assertNotFound();

        DB::table('media_files')->where('id', $template->intro_image_media_file_id)->update(['status' => 'deleted']);
        $this->actingAs($admin)->get(route('admin.course-templates.media.preview', [$template->id, 'image', $template->intro_image_media_file_id]))->assertNotFound();
    }

    public function test_version_detail_displays_template_media_snapshots_independently_from_current_draft(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData([
                'title' => 'Historical Media Detail',
                'intro_image_file' => UploadedFile::fake()->image('historical-image.png'),
                'intro_video_source' => 'upload',
                'intro_video_file' => UploadedFile::fake()->create('historical-video.mp4', 16, 'video/mp4'),
                'intro_document_file' => UploadedFile::fake()->create('historical-document.pdf', 16, 'application/pdf'),
            ])
        )->assertRedirect();
        $template = DB::table('core_course_templates')->where('title', 'Historical Media Detail')->sole();
        $this->addPublishableContent($customerId, (int) $template->id, $admin);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-templates/{$template->id}/publish")->assertRedirect();
        $version = DB::table('core_course_template_versions')->where('template_id', $template->id)->sole();

        $this->actingAs($admin)->put(
            "https://tenant-a.localhost/admin/course-templates/{$template->id}",
            $this->validTemplateData([
                'title' => 'Changed Current Draft',
                'intro_image_file' => UploadedFile::fake()->image('current-image.png'),
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => 'https://vimeo.com/76979871',
                'remove_intro_document' => 1,
            ])
        )->assertRedirect();

        $response = $this->actingAs($admin)->get(
            "https://tenant-a.localhost/admin/course-templates/{$template->id}/versions/{$version->id}"
        )->assertOk();
        $response->assertSeeText('Media giới thiệu của phiên bản')
            ->assertSeeText('Historical Media Detail')
            ->assertDontSeeText('Changed Current Draft')
            ->assertDontSee('vimeo.com/76979871', false)
            ->assertSee('data-version-media-slot="image"', false)
            ->assertSee('data-version-media-slot="video"', false)
            ->assertSee('data-version-media-slot="document"', false)
            ->assertSee('openVersionPreview', false)
            ->assertSee('x-ref="versionPreviewVideo"', false)
            ->assertSee('course-version-document-preview', false)
            ->assertSee('data-preview-type="document"', false)
            ->assertSee('allow="autoplay; fullscreen; picture-in-picture"', false)
            ->assertDontSeeText(__('lf.LF_version_detail_document_fallback'))
            ->assertSee("/versions/{$version->id}/media/image/", false)
            ->assertSee("/versions/{$version->id}/media/video/", false)
            ->assertSee("/versions/{$version->id}/media/document/", false);

        foreach ([
            'image' => $version->intro_image_media_file_id_snapshot,
            'video' => $version->intro_video_media_file_id_snapshot,
            'document' => $version->intro_document_media_file_id_snapshot,
        ] as $slot => $mediaId) {
            $this->actingAs($admin)->get(route('admin.course-templates.versions.media.preview', [
                $template->id, $version->id, $slot, $mediaId,
            ]))->assertOk();
        }

        $documentUrl = route('admin.course-templates.versions.media.preview', [
            $template->id,
            $version->id,
            'document',
            $version->intro_document_media_file_id_snapshot,
        ]);
        foreach ([
            ['historical.docx', 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['historical.xlsx', 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['historical.pptx', 'pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            ['historical.bin', 'bin', 'application/octet-stream'],
        ] as [$displayName, $extension, $mime]) {
            DB::table('media_files')
                ->where('id', $version->intro_document_media_file_id_snapshot)
                ->update([
                    'display_name' => $displayName,
                    'extension' => $extension,
                    'mime_type' => $mime,
                ]);

            $officeResponse = $this->actingAs($admin)->get(
                "https://tenant-a.localhost/admin/course-templates/{$template->id}/versions/{$version->id}"
            )->assertOk()
                ->assertSee('target="_blank"', false)
                ->assertSee('rel="noopener noreferrer"', false)
                ->assertSee($documentUrl, false);
            $this->assertSame(0, $this->htmlElementCount(
                $officeResponse->getContent(),
                '//div[@data-version-media-slot="document"]//button'
            ));
        }
    }

    public function test_version_detail_uses_historical_embed_and_safely_handles_invalid_media_relationship(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $this->actingAs($admin)->post(
            'https://tenant-a.localhost/admin/course-templates',
            $this->validTemplateData([
                'title' => 'Historical Embed Detail',
                'intro_image_file' => null,
                'intro_video_source' => 'embed',
                'intro_video_embed_url' => 'https://youtu.be/dQw4w9WgXcQ',
            ])
        )->assertRedirect();
        $template = DB::table('core_course_templates')->where('title', 'Historical Embed Detail')->sole();
        $this->addPublishableContent($customerId, (int) $template->id, $admin);
        $this->actingAs($admin)->post("https://tenant-a.localhost/admin/course-templates/{$template->id}/publish")->assertRedirect();
        $version = DB::table('core_course_template_versions')->where('template_id', $template->id)->sole();

        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-templates/{$template->id}/versions/{$version->id}")
            ->assertOk()
            ->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', false)
            ->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1', false)
            ->assertSee("openVersionPreview", false)
            ->assertSeeText('Không có');

        DB::table('core_course_template_versions')->where('id', $version->id)->update([
            'intro_video_embed_url_snapshot' => 'https://vimeo.com/76979871',
            'intro_video_provider_snapshot' => 'vimeo',
        ]);
        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-templates/{$template->id}/versions/{$version->id}")
            ->assertOk()
            ->assertSee('https://player.vimeo.com/video/76979871?autoplay=1', false);

        DB::table('core_course_template_versions')->where('id', $version->id)->update([
            'intro_image_media_file_id_snapshot' => $this->createMediaFile($customerId, $admin->id, 'image', 'unavailable.png', 'image/png'),
        ]);
        $invalidMediaId = (int) DB::table('core_course_template_versions')->where('id', $version->id)->value('intro_image_media_file_id_snapshot');

        $this->actingAs($admin)->get("https://tenant-a.localhost/admin/course-templates/{$template->id}/versions/{$version->id}")
            ->assertOk()
            ->assertSeeText('Media snapshot không khả dụng');
        $this->actingAs($admin)->get(route('admin.course-templates.versions.media.preview', [
            $template->id, $version->id, 'image', $invalidMediaId,
        ]))->assertNotFound();
        $this->assertDatabaseHas('core_course_template_versions', [
            'id' => $version->id,
            'intro_image_media_file_id_snapshot' => $invalidMediaId,
        ]);
    }

    public function test_cross_tenant_media_is_not_visible_on_course_owner_forms(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $productId = $this->createProduct($customerId, 'Tenant A Product', 'tenant-a-product');

        $this->actingAs($otherAdmin)
            ->post(
                'https://tenant-b.localhost/admin/course-products',
                $this->validProductData([
                    'title' => 'Tenant B Media Product',
                    'slug' => 'tenant-b-media-product',
                    'cover_image_file' => UploadedFile::fake()->image(
                        'tenant-b-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertRedirect();

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-products/{$productId}/edit")
            ->assertOk()
            ->assertDontSeeText('Tenant B Media Product')
            ->assertDontSee('expiration=', false);
    }

    public function test_cross_tenant_owner_attach_is_blocked_by_course_owner_lookup(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $admin = $this->createUser($customerId, 'customer_admin');
        $otherProductId = $this->createProduct(
            $otherCustomerId,
            'Tenant B Product',
            'tenant-b-product'
        );

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-products/{$otherProductId}",
                $this->validProductData([
                    'title' => 'Blocked Attach',
                    'slug' => 'blocked-attach',
                    'cover_image_file' => UploadedFile::fake()->image(
                        'blocked-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertNotFound();

        $this->assertDatabaseMissing('media_file_usages', [
            'customer_id' => $customerId,
            'owner_type' => 'course_product',
            'owner_id' => $otherProductId,
            'usage_type' => 'cover_image',
        ]);
    }

    public function test_teacher_can_upload_template_cover_for_own_and_assigned_template(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $assignedTemplateId = $this->createTemplate(
            $customerId,
            'Assigned Template',
            'assigned-template'
        );

        $this->createTemplateAssignment($customerId, $assignedTemplateId, $teacher->id);

        $this->actingAs($teacher)
            ->post(
                'https://tenant-a.localhost/teacher/course-templates',
                $this->validTemplateData([
                    'title' => 'Teacher Owned Template',
                    'slug' => 'teacher-owned-template',
                    'intro_image_file' => UploadedFile::fake()->image(
                        'owned-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertRedirect();

        $ownedTemplateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('title', 'Teacher Owned Template')
            ->value('id');

        $this->assertActiveUsage(
            $customerId,
            'course_template',
            $ownedTemplateId,
            'intro_image'
        );

        $this->actingAs($teacher)
            ->put(
                "https://tenant-a.localhost/teacher/course-templates/{$assignedTemplateId}",
                $this->validTemplateData([
                    'title' => 'Assigned Template Updated',
                    'slug' => 'assigned-template',
                    'intro_image_file' => UploadedFile::fake()->image(
                        'assigned-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/teacher/course-templates/{$assignedTemplateId}/edit"
            );

        $this->assertActiveUsage(
            $customerId,
            'course_template',
            $assignedTemplateId,
            'intro_image'
        );
    }

    public function test_teacher_cannot_upload_template_cover_for_unassigned_same_tenant_template(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate(
            $customerId,
            'Unassigned Template',
            'unassigned-template'
        );

        $this->actingAs($teacher)
            ->put(
                "https://tenant-a.localhost/teacher/course-templates/{$templateId}",
                $this->validTemplateData([
                    'title' => 'Blocked Teacher Upload',
                    'slug' => 'unassigned-template',
                    'intro_image_file' => UploadedFile::fake()->image(
                        'blocked-template-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertNotFound();

        $this->assertDatabaseMissing('media_file_usages', [
            'customer_id' => $customerId,
            'owner_type' => 'course_template',
            'owner_id' => $templateId,
            'usage_type' => 'intro_image',
        ]);
    }

    public function test_teacher_can_upload_activity_media_for_assigned_template(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate(
            $customerId,
            'Assigned Activity Template',
            'assigned-activity-template'
        );
        $lessonId = $this->createLesson(
            $customerId,
            $templateId,
            'Activity Parent Lesson',
            'activity-parent-lesson'
        );

        $this->createTemplateAssignment($customerId, $templateId, $teacher->id);

        $this->actingAs($teacher)
            ->post(
                "https://tenant-a.localhost/teacher/course-templates/{$templateId}/lessons/{$lessonId}/activities",
                $this->validActivityData([
                    'title' => 'Teacher Media Activity',
                    'activity_type' => 'video',
                    'activity_video_file' => UploadedFile::fake()->create(
                        'activity-video.mp4',
                        32,
                        'video/mp4'
                    ),
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/teacher/course-templates/{$templateId}/edit?tab=structure#course-template-lesson-{$lessonId}-activities"
            );

        $activityId = (int) DB::table('core_course_template_activities')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('template_lesson_id', $lessonId)
            ->where('title', 'Teacher Media Activity')
            ->value('id');

        foreach (['video'] as $usageType) {
            $mediaFile = $this->assertActiveUsage(
                $customerId,
                'course_activity',
                $activityId,
                $usageType
            );

            $this->assertStringStartsWith(
                "tenants/{$customerId}/course/activities/{$activityId}/",
                $mediaFile->storage_key
            );
        }

        $this->actingAs($teacher)
            ->get(
                "https://tenant-a.localhost/teacher/course-templates/{$templateId}/lessons/{$lessonId}/activities/{$activityId}/edit"
            )
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_media_replacement_video'))
            ->assertDontSeeText('activity-video.mp4')
            ->assertSee('data-current-media-state="available"', false);

        $outline = $this->actingAs($teacher)
            ->get(
                "https://tenant-a.localhost/teacher/course-templates/{$templateId}/edit"
            )
            ->assertOk();
        $this->assertSame(
            1,
            $this->htmlElementCount(
                $outline->getContent(),
                '//div[contains(concat(" ", normalize-space(@class), " "), " course-template-activity-item ")'
                .' and .//span[normalize-space()="Teacher Media Activity"]]'
                .'//div[contains(concat(" ", normalize-space(@class), " "), " admin-table-actions ")]'
                .'//a[normalize-space()="Xem" and @target="_blank" and contains(@href, "expiration=")]'
            )
        );
        $this->assertSame(
            0,
            $this->htmlElementCount(
                $outline->getContent(),
                '//div[contains(concat(" ", normalize-space(@class), " "), " course-template-activity-item ")]'
                .'//a[normalize-space()="Teacher Media Activity" or contains(@class, "course-template-activity-title")]'
            )
        );
    }

    public function test_activity_edit_displays_exact_current_video_audio_and_document_separately_from_upload_inputs(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Current Media Template', 'current-media-template', $admin->id);
        $lessonId = $this->createLesson($customerId, $templateId, 'Current Media Lesson', 'current-media-lesson');

        $cases = [
            'video' => ['lesson-video.mp4', 'video/mp4', 'activity_video_file'],
            'audio' => ['lesson-audio.mp3', 'audio/mpeg', 'activity_audio_file'],
            'document' => ['lesson-notes.pdf', 'application/pdf', 'activity_document_file'],
        ];

        foreach ($cases as $type => [$filename, $mime, $field]) {
            $this->actingAs($admin)->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities",
                $this->validActivityData([
                    'title' => ucfirst($type).' Current Media',
                    'activity_type' => $type,
                    $field => UploadedFile::fake()->create($filename, 32, $mime),
                ])
            )->assertRedirect();

            $activityId = (int) DB::table('core_course_template_activities')
                ->where('title', ucfirst($type).' Current Media')
                ->value('id');
            $mediaId = (int) DB::table('media_file_usages')
                ->where('owner_type', 'course_activity')
                ->where('owner_id', $activityId)
                ->where('usage_type', $type)
                ->where('status', 'active')
                ->value('media_file_id');
            $previewUrl = "https://tenant-a.localhost/admin/course-templates/{$templateId}/activities/{$activityId}/media/{$type}/{$mediaId}";

            $response = $this->actingAs($admin)->get(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities/{$activityId}/edit"
            )->assertOk()
                ->assertDontSeeText(__('lf.LF_course_template_activity_media_current_file'))
                ->assertDontSeeText($filename)
                ->assertSee('data-current-media-state="available"', false)
                ->assertSee('name="'.$field.'"', false)
                ->assertDontSee('name="'.$field.'" value=', false)
                ->assertSeeText('Định dạng:')
                ->assertSeeText('Tối đa:');

            $this->assertSame(1, $this->htmlElementCount(
                $response->getContent(),
                '//div[@data-current-media-state="available"]'
                .'//div[@data-authoring-media-current-row]'
                .'[.//*[contains(concat(" ", normalize-space(@class), " "), " media-thumbnail ")]'
                .' and .//*[self::a or self::button][normalize-space()="Xem"]]'
            ));
            $this->assertSame(0, $this->htmlElementCount(
                $response->getContent(),
                '//div[@data-current-media-state="available"]'
                .'//*[contains(concat(" ", normalize-space(@class), " "), " course-activity-current-media-required ")]'
            ));
            $this->assertSame(1, $this->htmlElementCount(
                $response->getContent(),
                '//input[@name="'.$field.'"]'
                .'[preceding-sibling::div[contains(concat(" ", normalize-space(@class), " "), " course-activity-current-media ")]]'
                .'/following-sibling::*[1]'
                .'[contains(concat(" ", normalize-space(@class), " "), " authoring-media-help ")]'
            ));
            $this->assertSame(0, $this->htmlElementCount(
                $response->getContent(),
                '//div[@data-current-media-state="available"]//input[starts-with(@name, "remove_")]'
            ));

            if ($type === 'document') {
                $response
                    ->assertSee($previewUrl, false)
                    ->assertSee('data-media-thumbnail-kind="pdf"', false);
            } else {
                $this->assertStringContainsString(
                    str_replace('/', '\\/', $previewUrl),
                    $response->getContent()
                );
            }

            $this->actingAs($admin)->get($previewUrl)
                ->assertOk()
                ->assertHeader('content-type', $mime);
        }
    }

    public function test_activity_edit_empty_and_invalid_relationships_never_fabricate_a_preview_url(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Empty Media Template', 'empty-media-template', $admin->id);
        $lessonId = $this->createLesson($customerId, $templateId, 'Empty Media Lesson', 'empty-media-lesson');
        $activityId = DB::table('core_course_template_activities')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId, 'template_lesson_id' => $lessonId,
            'title' => 'Activity 14 tài liệu a', 'description' => null, 'sort_order' => 1,
            'activity_type' => 'document', 'external_video_url' => null, 'live_class_url' => null,
            'assessment_quiz_id' => null, 'duration_seconds' => 0, 'estimated_duration_seconds' => null,
            'is_required' => true, 'completion_rule' => 'view', 'completion_threshold' => null,
            'is_preview' => false, 'unlock_rule' => 'none', 'unlock_after_activity_id' => null,
            'unlock_at' => null, 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $editUrl = "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities/{$activityId}/edit";

        $this->actingAs($admin)->get($editUrl)
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_media_empty'))
            ->assertSeeText(__('lf.LF_course_template_activity_media_required_before_publish'))
            ->assertSee('data-current-media-state="empty"', false)
            ->assertDontSee("/activities/{$activityId}/media/document/", false);

        DB::table('core_course_template_activities')->where('id', $activityId)->update([
            'title' => 'Activity 15 video khoá học',
            'activity_type' => 'video',
        ]);
        $this->actingAs($admin)->get($editUrl)
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_media_empty'))
            ->assertDontSee("/activities/{$activityId}/media/video/", false);
        DB::table('core_course_template_activities')->where('id', $activityId)->update([
            'title' => 'Activity 14 tài liệu a',
            'activity_type' => 'document',
        ]);

        $mediaId = $this->createMediaFile($customerId, $admin->id, 'document', 'historical.pdf', 'application/pdf');
        $usageId = DB::table('media_file_usages')->insertGetId([
            'customer_id' => $customerId, 'media_file_id' => $mediaId,
            'owner_type' => 'course_activity', 'owner_id' => $activityId,
            'usage_type' => 'document', 'status' => 'detached', 'metadata' => null,
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([
            ['media_file_usages', $usageId, ['status' => 'detached']],
            ['media_file_usages', $usageId, ['status' => 'archived']],
            ['media_files', $mediaId, ['status' => 'archived']],
            ['media_files', $mediaId, ['status' => 'deleted']],
            ['media_files', $mediaId, ['file_type' => 'video', 'mime_type' => 'video/mp4']],
        ] as [$table, $id, $mutation]) {
            DB::table('media_file_usages')->where('id', $usageId)->update(['status' => 'active']);
            DB::table('media_files')->where('id', $mediaId)->update([
                'status' => 'ready', 'file_type' => 'document', 'mime_type' => 'application/pdf',
            ]);
            DB::table($table)->where('id', $id)->update($mutation);

            $response = $this->actingAs($admin)->get($editUrl)
                ->assertOk()
                ->assertSeeText(__('lf.LF_course_template_activity_media_unavailable'))
                ->assertSeeText(__('lf.LF_course_template_activity_media_required_before_publish'))
                ->assertSee('data-current-media-state="unavailable"', false)
                ->assertDontSee("/activities/{$activityId}/media/document/{$mediaId}", false);
            $this->assertSame(0, $this->htmlElementCount(
                $response->getContent(),
                '//div[@data-current-media-state="unavailable"]'
                .'//*[self::a or self::button][normalize-space()="Xem"]'
            ));
        }
    }

    public function test_activity_media_preview_fails_closed_for_wrong_relationship_and_teacher_scope(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $assigned = $this->createUser($customerId, 'teacher');
        $unassigned = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate($customerId, 'Preview Scope Template', 'preview-scope-template', $admin->id);
        $lessonId = $this->createLesson($customerId, $templateId, 'Preview Scope Lesson', 'preview-scope-lesson');
        $this->createTemplateAssignment($customerId, $templateId, $assigned->id);

        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities",
            $this->validActivityData([
                'title' => 'Scoped Video', 'activity_type' => 'video',
                'activity_video_file' => UploadedFile::fake()->create('scoped.mp4', 32, 'video/mp4'),
            ])
        )->assertRedirect();
        $activityId = (int) DB::table('core_course_template_activities')->where('title', 'Scoped Video')->value('id');
        $usage = DB::table('media_file_usages')->where('owner_type', 'course_activity')->where('owner_id', $activityId)->first();
        $url = "https://tenant-a.localhost/teacher/course-templates/{$templateId}/activities/{$activityId}/media/video/{$usage->media_file_id}";

        $this->actingAs($assigned)->get($url)->assertOk();
        $this->actingAs($unassigned)->get($url)->assertNotFound();

        foreach ([
            ['owner_id' => $activityId + 999],
            ['owner_type' => 'course_template'],
            ['usage_type' => 'audio'],
            ['status' => 'detached'],
        ] as $mutation) {
            DB::table('media_file_usages')->where('id', $usage->id)->update([
                'owner_id' => $activityId, 'owner_type' => 'course_activity',
                'usage_type' => 'video', 'status' => 'active',
            ]);
            DB::table('media_file_usages')->where('id', $usage->id)->update($mutation);
            $this->actingAs($admin)->get(str_replace('/teacher/', '/admin/', $url))->assertNotFound();
        }

        $otherCustomerId = $this->createTenant('tenant-b');
        $otherAdmin = $this->createUser($otherCustomerId, 'customer_admin');
        $otherMediaId = $this->createMediaFile($otherCustomerId, $otherAdmin->id, 'video', 'other.mp4', 'video/mp4');
        DB::table('media_file_usages')->where('id', $usage->id)->update([
            'owner_id' => $activityId, 'owner_type' => 'course_activity',
            'usage_type' => 'video', 'status' => 'active', 'media_file_id' => $otherMediaId,
        ]);
        $this->actingAs($admin)->get(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/activities/{$activityId}/media/video/{$otherMediaId}"
        )->assertNotFound();
    }

    public function test_activity_media_replacement_failure_and_type_change_preserve_expected_current_state(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Replacement Display Template', 'replacement-display-template', $admin->id);
        $lessonId = $this->createLesson($customerId, $templateId, 'Replacement Display Lesson', 'replacement-display-lesson');
        $collection = "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities";

        $this->actingAs($admin)->post($collection, $this->validActivityData([
            'title' => 'Replace Video', 'activity_type' => 'video',
            'activity_video_file' => UploadedFile::fake()->create('before.mp4', 32, 'video/mp4'),
        ]))->assertRedirect();
        $activityId = (int) DB::table('core_course_template_activities')->where('title', 'Replace Video')->value('id');
        $editUrl = "{$collection}/{$activityId}/edit";

        $this->actingAs($admin)->put("{$collection}/{$activityId}", $this->validActivityData([
            'title' => 'Replace Video', 'activity_type' => 'video',
            'activity_video_file' => UploadedFile::fake()->create('after.mp4', 48, 'video/mp4'),
        ]))->assertRedirect($editUrl);
        $replacementMediaId = (int) DB::table('media_file_usages')
            ->where('owner_type', 'course_activity')->where('owner_id', $activityId)
            ->where('usage_type', 'video')->where('status', 'active')->value('media_file_id');
        $response = $this->actingAs($admin)->get($editUrl)
            ->assertOk()
            ->assertDontSeeText('after.mp4')->assertDontSeeText('before.mp4');
        $this->assertStringContainsString(
            "\\/activities\\/{$activityId}\\/media\\/video\\/{$replacementMediaId}",
            $response->getContent()
        );

        $this->actingAs($admin)->from($editUrl)->put("{$collection}/{$activityId}", $this->validActivityData([
            'title' => 'Replace Video', 'activity_type' => 'video',
            'activity_video_file' => UploadedFile::fake()->create('invalid.txt', 32, 'text/plain'),
        ]))->assertRedirect($editUrl)->assertSessionHasErrors();
        $response = $this->actingAs($admin)->get($editUrl)
            ->assertOk()
            ->assertDontSeeText('after.mp4')->assertDontSeeText('invalid.txt');
        $this->assertStringContainsString(
            "\\/activities\\/{$activityId}\\/media\\/video\\/{$replacementMediaId}",
            $response->getContent()
        );

        $this->actingAs($admin)->put("{$collection}/{$activityId}", $this->validActivityData([
            'title' => 'Replace Video', 'activity_type' => 'audio',
        ]))->assertRedirect($editUrl);
        $this->actingAs($admin)->get($editUrl)
            ->assertOk()
            ->assertSeeText(__('lf.LF_course_template_activity_media_empty'))
            ->assertDontSeeText('after.mp4')
            ->assertDontSee("/activities/{$activityId}/media/video/", false);
        $this->assertDatabaseHas('media_file_usages', [
            'customer_id' => $customerId, 'owner_type' => 'course_activity',
            'owner_id' => $activityId, 'usage_type' => 'video', 'status' => 'detached',
        ]);
    }

    public function test_activity_create_rolls_back_when_media_usage_attachment_fails(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Activity Rollback Template', 'activity-rollback-template', $admin->id);
        $lessonId = $this->createLesson($customerId, $templateId, 'Rollback Lesson', 'rollback-lesson');
        $mediaService = \Mockery::mock(MediaService::class)->makePartial();
        $mediaService->shouldReceive('attachUsage')->once()->andThrow(new \RuntimeException('Injected activity media failure.'));
        $this->app->instance(MediaService::class, $mediaService);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities",
                $this->validActivityData([
                    'title' => 'Activity Must Roll Back',
                    'activity_type' => 'video',
                    'activity_video_file' => UploadedFile::fake()->create('rollback.mp4', 32, 'video/mp4'),
                ])
            );
            $this->fail('Expected activity media synchronization to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected activity media failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('core_course_template_activities', [
            'customer_id' => $customerId,
            'title' => 'Activity Must Roll Back',
        ]);
        $this->assertDatabaseMissing('media_files', [
            'customer_id' => $customerId,
            'original_name' => 'rollback.mp4',
        ]);
    }

    public function test_activity_update_rolls_back_fields_and_existing_usage_when_replacement_fails(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate($customerId, 'Activity Update Rollback', 'activity-update-rollback', $admin->id);
        $lessonId = $this->createLesson($customerId, $templateId, 'Update Rollback Lesson', 'update-rollback-lesson');
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities",
            $this->validActivityData([
                'title' => 'Original Activity',
                'activity_type' => 'video',
                'activity_video_file' => UploadedFile::fake()->create('original.mp4', 32, 'video/mp4'),
            ])
        )->assertRedirect();
        $activityId = (int) DB::table('core_course_template_activities')->where('title', 'Original Activity')->value('id');
        $originalUsage = DB::table('media_file_usages')->where('owner_type', 'course_activity')->where('owner_id', $activityId)->first();

        $mediaService = \Mockery::mock(MediaService::class)->makePartial();
        $mediaService->shouldReceive('attachUsage')->once()->andThrow(new \RuntimeException('Injected replacement failure.'));
        $this->app->instance(MediaService::class, $mediaService);
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->put(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities/{$activityId}",
                $this->validActivityData([
                    'title' => 'Changed Activity',
                    'activity_type' => 'video',
                    'activity_video_file' => UploadedFile::fake()->create('replacement.mp4', 32, 'video/mp4'),
                ])
            );
            $this->fail('Expected replacement synchronization to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected replacement failure.', $exception->getMessage());
        }

        $this->assertDatabaseHas('core_course_template_activities', ['id' => $activityId, 'title' => 'Original Activity']);
        $this->assertDatabaseHas('media_file_usages', ['id' => $originalUsage->id, 'media_file_id' => $originalUsage->media_file_id, 'status' => 'active']);
        $this->assertDatabaseMissing('media_files', ['customer_id' => $customerId, 'original_name' => 'replacement.mp4']);
    }

    public function test_teacher_cannot_access_product_media_upload_or_lifecycle_routes(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate(
            $customerId,
            'Teacher Route Template',
            'teacher-route-template',
            $teacher->id
        );

        $this->actingAs($teacher)
            ->get('https://tenant-a.localhost/teacher/course-products/create')
            ->assertNotFound();

        $this->actingAs($teacher)
            ->post(
                'https://tenant-a.localhost/teacher/course-products',
                $this->validProductData([
                    'cover_image_file' => UploadedFile::fake()->image(
                        'teacher-product-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertNotFound();

        $this->actingAs($teacher)
            ->post("https://tenant-a.localhost/teacher/course-templates/{$templateId}/publish")
            ->assertNotFound();

        $this->actingAs($teacher)
            ->get("https://tenant-a.localhost/teacher/course-templates/{$templateId}/versions/1")
            ->assertNotFound();
    }

    private function assertActiveUsage(
        int $customerId,
        string $ownerType,
        int $ownerId,
        string $usageType
    ): object {
        $usage = DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('usage_type', $usageType)
            ->where('status', 'active')
            ->first();

        $this->assertNotNull($usage);

        $mediaFile = DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('id', $usage->media_file_id)
            ->first();

        $this->assertNotNull($mediaFile);

        return $mediaFile;
    }

    private function assertSignedDeliveryUrl(int $customerId, object $mediaFile): void
    {
        TenantContext::set((object) ['id' => $customerId]);

        $signedUrl = app(MediaService::class)->generateSignedUrl(
            (int) $mediaFile->id
        );

        $this->assertStringContainsString('expiration=', $signedUrl);
        $this->assertStringNotContainsString('public_url', $signedUrl);
    }

    private function activeTemplatePreviewUsageCount(
        int $customerId,
        int $templateId
    ): int {
        return DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('owner_type', 'course_template')
            ->where('owner_id', $templateId)
            ->whereIn('usage_type', ['intro_image', 'intro_video', 'intro_document'])
            ->where('status', 'active')
            ->count();
    }

    private function htmlElementCount(string $html, string $xpath): int
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return (new \DOMXPath($document))->query($xpath)->length;
    }

    private function createMediaFile(
        int $customerId,
        int $uploadedBy,
        string $fileType,
        string $originalName,
        string $mimeType
    ): int {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        return DB::table('media_files')->insertGetId([
            'customer_id' => $customerId,
            'category_id' => null,
            'uploaded_by' => $uploadedBy,
            'file_type' => $fileType,
            'mime_type' => $mimeType,
            'original_name' => $originalName,
            'display_name' => $originalName,
            'extension' => $extension,
            'storage_disk' => 'media_local',
            'storage_bucket' => 'lf-test-media',
            'storage_region' => 'ap-southeast-1',
            'storage_key' => "tenants/{$customerId}/course/templates/preview/{$originalName}",
            'storage_class' => null,
            'cdn_url' => null,
            'public_url' => null,
            'checksum' => 'sha256:'.hash('sha256', $customerId.$originalName),
            'file_size_bytes' => 32,
            'duration_seconds' => null,
            'width' => null,
            'height' => null,
            'page_count' => null,
            'language' => null,
            'visibility' => 'private',
            'status' => 'ready',
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
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

    private function createProduct(
        int $customerId,
        string $title,
        string $slug
    ): int {
        return DB::table('core_course_products')->insertGetId([
            'customer_id' => $customerId,
            'product_code' => strtoupper($slug),
            'product_type' => 'single_course',
            'title' => $title,
            'slug' => $slug,
            'short_description' => null,
            'description' => null,
            'thumbnail_type' => 'image',
            'thumbnail_image' => null,
            'thumbnail_video_source' => null,
            'thumbnail_video_url' => null,
            'thumbnail_video_media_id' => null,
            'price' => 0,
            'sale_price' => null,
            'sale_starts_at' => null,
            'sale_ends_at' => null,
            'currency' => 'VND',
            'enrollment_type' => 'paid',
            'max_students' => null,
            'enrollment_count' => 0,
            'access_duration_days' => null,
            'review_duration_days' => null,
            'is_certificate_enabled' => false,
            'is_refundable' => false,
            'refund_days' => null,
            'tags' => null,
            'badge_type' => null,
            'show_enrollment_count' => true,
            'display_enrollment_count' => null,
            'is_featured' => false,
            'sort_order' => 0,
            'visibility' => 'public',
            'available_from' => null,
            'available_until' => null,
            'registration_starts_at' => null,
            'registration_ends_at' => null,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'status' => 'draft',
            'created_by' => null,
            'published_at' => null,
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
            'estimated_lesson_count' => null,
            'lesson_count' => 0,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'working_revision' => 1,
            'status' => 'draft',
            'created_by' => $createdBy,
            'last_version_published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTemplateAssignment(
        int $customerId,
        int $templateId,
        int $teacherId
    ): int {
        return DB::table('core_course_template_teachers')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'teacher_id' => $teacherId,
            'role' => 'teacher',
            'sort_order' => 0,
            'status' => 'active',
            'assigned_by' => null,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLesson(
        int $customerId,
        int $templateId,
        string $title,
        string $slug
    ): int {
        return DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $customerId,
            'template_id' => $templateId,
            'template_section_id' => null,
            'title' => $title,
            'short_description' => null,
            'description' => null,
            'sort_order' => 0,
            'is_preview' => false,
            'unlock_rule' => 'none',
            'unlock_after_lesson_id' => null,
            'unlock_at' => null,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addPublishableContent(int $customerId, int $templateId, User $admin): void
    {
        $lessonId = $this->createLesson(
            $customerId,
            $templateId,
            'Publishing Readiness Lesson',
            'publishing-readiness-lesson'
        );
        $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities",
            $this->validActivityData([
                'title' => 'Publishing Readiness Document',
                'activity_type' => 'document',
                'activity_document_file' => UploadedFile::fake()->create(
                    'publishing-readiness.pdf',
                    16,
                    'application/pdf'
                ),
            ])
        )->assertRedirect();
    }

    private function validProductData(array $overrides = []): array
    {
        return array_merge([
            'product_type' => 'single_course',
            'title' => 'Programming Basics',
            'short_description' => null,
            'description' => null,
            'thumbnail_type' => 'image',
            'thumbnail_image' => null,
            'thumbnail_video_source' => null,
            'thumbnail_video_url' => null,
            'thumbnail_video_media_id' => null,
            'price' => 0,
            'sale_price' => null,
            'sale_starts_at' => null,
            'sale_ends_at' => null,
            'currency' => 'VND',
            'enrollment_type' => 'paid',
            'max_students' => null,
            'access_duration_days' => null,
            'review_duration_days' => null,
            'is_refundable' => 0,
            'refund_days' => null,
            'tags' => null,
            'badge_type' => null,
            'show_enrollment_count' => 1,
            'display_enrollment_count' => null,
            'is_featured' => 0,
            'sort_order' => 0,
            'visibility' => 'public',
            'available_from' => null,
            'available_until' => null,
            'registration_starts_at' => null,
            'registration_ends_at' => null,
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'status' => 'draft',
        ], $overrides);
    }

    private function validTemplateData(array $overrides = []): array
    {
        $customerId = (int) DB::table('saas_customers')->orderByDesc('id')->value('id');
        $categoryId = DB::table('core_course_categories')->where('customer_id', $customerId)->value('id');
        if (! $categoryId) {
            $categoryId = DB::table('core_course_categories')->insertGetId([
                'customer_id' => $customerId, 'parent_id' => null, 'name' => 'General',
                'slug' => 'general-'.$customerId, 'description' => null,
                'thumbnail_image' => null, 'banner_image' => null, 'sort_order' => 0,
                'is_featured' => false, 'meta_title' => null, 'meta_description' => null,
                'meta_keywords' => null, 'status' => 'active', 'created_by' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

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

    private function validLessonData(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Lesson Introduction',
            'short_description' => null,
            'description' => null,
            'sort_order' => 0,
            'is_preview' => 0,
            'unlock_rule' => 'none',
            'unlock_after_lesson_id' => null,
            'unlock_at' => null,
        ], $overrides);
    }

    private function validActivityData(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Activity Introduction',
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

    private function validCohortData(array $overrides = []): array
    {
        return array_merge([
            'product_id' => null,
            'version_id' => null,
            'teacher_id' => null,
            'name' => 'TOPIK Beginner Morning',
            'description' => null,
            'status' => 'active',
            'capacity' => null,
            'start_date' => null,
            'end_date' => null,
            'metadata' => null,
        ], $overrides);
    }
}
