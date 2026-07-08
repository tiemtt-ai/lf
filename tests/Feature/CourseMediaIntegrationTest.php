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
            ->assertRedirect('https://tenant-a.localhost/admin/course-products');

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
            ->assertSee('expiration=', false);
    }

    public function test_admin_can_upload_course_template_cover_image(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin)
            ->post(
                'https://tenant-a.localhost/admin/course-templates',
                $this->validTemplateData([
                    'title' => 'Media Template',
                    'slug' => 'media-template',
                    'cover_image_file' => UploadedFile::fake()->image(
                        'template-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates');

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('slug', 'media-template')
            ->value('id');

        $mediaFile = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'cover_image'
        );
        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'cover_type' => 'image',
            'cover_image_media_file_id' => $mediaFile->id,
            'intro_video_media_file_id' => null,
        ]);

        $this->assertStringStartsWith(
            "tenants/{$customerId}/course/templates/{$templateId}/cover/",
            $mediaFile->storage_key
        );
        $this->assertNull($mediaFile->public_url);
        $this->assertSignedDeliveryUrl($customerId, $mediaFile);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()
            ->assertSee('name="cover_image_file"', false)
            ->assertSee('expiration=', false);
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
                    'cover_type' => 'video',
                    'cover_image_file' => null,
                    'intro_video_file' => UploadedFile::fake()->create(
                        'intro-video.mp4',
                        32,
                        'video/mp4'
                    ),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates');

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('slug', 'video-template')
            ->value('id');

        $mediaFile = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'video'
        );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'cover_type' => 'video',
            'cover_image_media_file_id' => null,
            'intro_video_media_file_id' => $mediaFile->id,
        ]);
        $this->assertStringStartsWith(
            "tenants/{$customerId}/course/templates/{$templateId}/intro-video/",
            $mediaFile->storage_key
        );
        Storage::disk('media_local')->assertExists($mediaFile->storage_key);

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()
            ->assertSee('name="intro_video_file"', false)
            ->assertSee('expiration=', false);
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
                    'cover_type' => 'video',
                    'cover_image_file' => null,
                    'intro_video_file' => UploadedFile::fake()->create(
                        'show-route-intro.mp4',
                        32,
                        'video/mp4'
                    ),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates');

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('slug', 'show-route-video-template')
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
            ->assertSee('expiration=', false);
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
                    'cover_image_file' => UploadedFile::fake()->image(
                        'initial-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates');

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('slug', 'switch-cover-template')
            ->value('id');
        $coverImage = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'cover_image'
        );

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}",
                $this->validTemplateData([
                    '_method' => 'PUT',
                    'title' => 'Switch Cover Template',
                    'slug' => 'switch-cover-template',
                    'cover_type' => 'video',
                    'cover_image_file' => null,
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
            'video'
        );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'cover_type' => 'video',
            'cover_image_media_file_id' => null,
            'intro_video_media_file_id' => $introVideo->id,
        ]);
        $this->assertDatabaseHas('media_file_usages', [
            'customer_id' => $customerId,
            'media_file_id' => $coverImage->id,
            'owner_type' => 'course_template',
            'owner_id' => $templateId,
            'usage_type' => 'cover_image',
            'status' => 'detached',
        ]);
        $this->assertDatabaseHas('media_files', [
            'id' => $coverImage->id,
            'customer_id' => $customerId,
            'status' => 'ready',
        ]);
        $this->assertSame(
            1,
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
                    'cover_type' => 'video',
                    'cover_image_file' => null,
                    'intro_video_file' => UploadedFile::fake()->create(
                        'initial-intro.mp4',
                        32,
                        'video/mp4'
                    ),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates');

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('slug', 'switch-video-template')
            ->value('id');
        $introVideo = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'video'
        );

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}",
                $this->validTemplateData([
                    '_method' => 'PUT',
                    'title' => 'Switch Video Template',
                    'slug' => 'switch-video-template',
                    'cover_type' => 'image',
                    'cover_image_file' => UploadedFile::fake()->image(
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
            'cover_image'
        );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'cover_type' => 'image',
            'cover_image_media_file_id' => $coverImage->id,
            'intro_video_media_file_id' => null,
        ]);
        $this->assertDatabaseHas('media_file_usages', [
            'customer_id' => $customerId,
            'media_file_id' => $introVideo->id,
            'owner_type' => 'course_template',
            'owner_id' => $templateId,
            'usage_type' => 'video',
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

    public function test_course_template_rejects_image_and_video_in_same_request(): void
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
                    'cover_type' => 'image',
                    'cover_image_file' => UploadedFile::fake()->image(
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
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates/create')
            ->assertSessionHasErrors('cover_type');

        $this->assertDatabaseMissing('core_course_templates', [
            'customer_id' => $customerId,
            'slug' => 'invalid-dual-preview-template',
        ]);
        $this->assertDatabaseMissing('media_file_usages', [
            'customer_id' => $customerId,
            'owner_type' => 'course_template',
        ]);
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
            'video',
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
                    'cover_type' => 'video',
                    'cover_image_file' => null,
                    'intro_video_media_file_id' => $otherVideoId,
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates/create')
            ->assertSessionHasErrors('intro_video_media_file_id');

        $this->assertDatabaseMissing('media_file_usages', [
            'customer_id' => $customerId,
            'media_file_id' => $otherVideoId,
            'owner_type' => 'course_template',
            'usage_type' => 'video',
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
                    'cover_image_file' => UploadedFile::fake()->image(
                        'browser-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates');

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('slug', 'browser-video-update-template')
            ->value('id');

        $this->actingAs($admin)
            ->get("https://tenant-a.localhost/admin/course-templates/{$templateId}/edit")
            ->assertOk()
            ->assertSee('name="cover_type"', false)
            ->assertSee('name="intro_video_file"', false)
            ->assertSee(':disabled="selectedCoverType !== \'image\'"', false)
            ->assertSee(':disabled="selectedCoverType !== \'video\'"', false);

        $response = $this->actingAs($admin)->post(
            "https://tenant-a.localhost/admin/course-templates/{$templateId}",
            $this->validTemplateData([
                '_method' => 'PUT',
                'title' => 'Browser Video Update Template',
                'slug' => 'browser-video-update-template',
                'cover_type' => 'video',
                'cover_image_file' => null,
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
            'video'
        );

        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'cover_type' => 'video',
            'cover_image_media_file_id' => null,
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
                    'cover_type' => 'video',
                    'cover_image_file' => null,
                    'intro_video_file' => UploadedFile::fake()->create(
                        'initial-intro.mp4',
                        32,
                        'video/mp4'
                    ),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-templates');

        $templateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('slug', 'replace-video-template')
            ->value('id');
        $initialVideo = $this->assertActiveUsage(
            $customerId,
            'course_template',
            $templateId,
            'video'
        );

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}",
                $this->validTemplateData([
                    '_method' => 'PUT',
                    'title' => 'Replace Video Template',
                    'slug' => 'replace-video-template',
                    'cover_type' => 'video',
                    'cover_image_file' => null,
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
            ->where('media_file_usages.usage_type', 'video')
            ->where('media_file_usages.status', 'active')
            ->select('media_files.*')
            ->sole();

        $this->assertNotSame($initialVideo->id, $replacementVideo->id);
        $this->assertDatabaseHas('core_course_templates', [
            'id' => $templateId,
            'customer_id' => $customerId,
            'cover_type' => 'video',
            'cover_image_media_file_id' => null,
            'intro_video_media_file_id' => $replacementVideo->id,
        ]);
        $this->assertDatabaseHas('media_file_usages', [
            'customer_id' => $customerId,
            'media_file_id' => $initialVideo->id,
            'owner_type' => 'course_template',
            'owner_id' => $templateId,
            'usage_type' => 'video',
            'status' => 'detached',
        ]);
    }

    public function test_admin_can_upload_and_view_lesson_video_audio_and_document(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $templateId = $this->createTemplate(
            $customerId,
            'Lesson Media Template',
            'lesson-media-template'
        );

        $this->actingAs($admin)
            ->post(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons",
                $this->validLessonData([
                    'title' => 'Media Lesson',
                    'slug' => 'media-lesson',
                    'media_video_file' => UploadedFile::fake()->create(
                        'lesson-video.mp4',
                        32,
                        'video/mp4'
                    ),
                    'media_audio_file' => UploadedFile::fake()->create(
                        'lesson-audio.mp3',
                        32,
                        'audio/mpeg'
                    ),
                    'media_document_file' => UploadedFile::fake()->create(
                        'lesson-document.pdf',
                        32,
                        'application/pdf'
                    ),
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/edit?tab=structure#course-template-direct-lessons"
            );

        $lessonId = (int) DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('slug', 'media-lesson')
            ->value('id');

        foreach (['video', 'audio', 'document'] as $usageType) {
            $mediaFile = $this->assertActiveUsage(
                $customerId,
                'course_lesson',
                $lessonId,
                $usageType
            );

            $this->assertStringStartsWith(
                "tenants/{$customerId}/course/lessons/{$lessonId}/{$usageType}/",
                $mediaFile->storage_key
            );
            $this->assertSignedDeliveryUrl($customerId, $mediaFile);
        }

        $this->actingAs($admin)
            ->get(
                "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/edit"
            )
            ->assertOk()
            ->assertSeeText('Lesson media')
            ->assertSeeText('video')
            ->assertSeeText('audio')
            ->assertSeeText('document')
            ->assertSee('expiration=', false);
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
                    'cover_image_file' => UploadedFile::fake()->image(
                        'owned-cover.png',
                        120,
                        80
                    ),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/teacher/course-templates');

        $ownedTemplateId = (int) DB::table('core_course_templates')
            ->where('customer_id', $customerId)
            ->where('slug', 'teacher-owned-template')
            ->value('id');

        $this->assertActiveUsage(
            $customerId,
            'course_template',
            $ownedTemplateId,
            'cover_image'
        );

        $this->actingAs($teacher)
            ->put(
                "https://tenant-a.localhost/teacher/course-templates/{$assignedTemplateId}",
                $this->validTemplateData([
                    'title' => 'Assigned Template Updated',
                    'slug' => 'assigned-template',
                    'cover_image_file' => UploadedFile::fake()->image(
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
            'cover_image'
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
                    'cover_image_file' => UploadedFile::fake()->image(
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
            'usage_type' => 'cover_image',
        ]);
    }

    public function test_teacher_can_upload_lesson_media_for_assigned_template(): void
    {
        $customerId = $this->createTenant();
        $teacher = $this->createUser($customerId, 'teacher');
        $templateId = $this->createTemplate(
            $customerId,
            'Assigned Lesson Template',
            'assigned-lesson-template'
        );

        $this->createTemplateAssignment($customerId, $templateId, $teacher->id);

        $this->actingAs($teacher)
            ->post(
                "https://tenant-a.localhost/teacher/course-templates/{$templateId}/lessons",
                $this->validLessonData([
                    'title' => 'Teacher Media Lesson',
                    'slug' => 'teacher-media-lesson',
                    'media_video_file' => UploadedFile::fake()->create(
                        'teacher-video.mp4',
                        32,
                        'video/mp4'
                    ),
                    'media_audio_file' => UploadedFile::fake()->create(
                        'teacher-audio.mp3',
                        32,
                        'audio/mpeg'
                    ),
                    'media_document_file' => UploadedFile::fake()->create(
                        'teacher-document.pdf',
                        32,
                        'application/pdf'
                    ),
                ])
            )
            ->assertRedirect(
                "https://tenant-a.localhost/teacher/course-templates/{$templateId}/edit?tab=structure#course-template-direct-lessons"
            );

        $lessonId = (int) DB::table('core_course_template_lessons')
            ->where('customer_id', $customerId)
            ->where('template_id', $templateId)
            ->where('slug', 'teacher-media-lesson')
            ->value('id');

        foreach (['video', 'audio', 'document'] as $usageType) {
            $mediaFile = $this->assertActiveUsage(
                $customerId,
                'course_lesson',
                $lessonId,
                $usageType
            );

            $this->assertStringStartsWith(
                "tenants/{$customerId}/course/lessons/{$lessonId}/{$usageType}/",
                $mediaFile->storage_key
            );
        }

        $this->actingAs($teacher)
            ->get(
                "https://tenant-a.localhost/teacher/course-templates/{$templateId}/lessons/{$lessonId}/edit"
            )
            ->assertOk()
            ->assertSeeText('Lesson media')
            ->assertSee('expiration=', false);
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
                    'duration_seconds' => null,
                    'activity_video_file' => UploadedFile::fake()->create(
                        'activity-video.mp4',
                        32,
                        'video/mp4'
                    ),
                    'activity_audio_file' => UploadedFile::fake()->create(
                        'activity-audio.mp3',
                        32,
                        'audio/mpeg'
                    ),
                    'activity_document_file' => UploadedFile::fake()->create(
                        'activity-document.pdf',
                        32,
                        'application/pdf'
                    ),
                    'activity_attachment_file' => UploadedFile::fake()->create(
                        'activity-attachment.pdf',
                        32,
                        'application/pdf'
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

        foreach (['video', 'audio', 'document', 'attachment'] as $usageType) {
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
            ->assertSeeText('Activity media')
            ->assertSee('expiration=', false);
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
            ->whereIn('usage_type', ['cover_image', 'video'])
            ->where('status', 'active')
            ->count();
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
            'slug' => $slug,
            'short_description' => null,
            'description' => null,
            'publisher_name' => null,
            'cover_type' => 'image',
            'cover_image_media_file_id' => null,
            'intro_video_media_file_id' => null,
            'difficulty_level' => null,
            'estimated_duration_minutes' => 0,
            'max_lessons' => null,
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
            'slug' => $slug,
            'short_description' => null,
            'description' => null,
            'sort_order' => 0,
            'is_preview' => false,
            'learning_objective' => null,
            'unlock_rule' => 'none',
            'unlock_after_lesson_id' => null,
            'unlock_at' => null,
            'status' => 'draft',
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validProductData(array $overrides = []): array
    {
        return array_merge([
            'product_type' => 'single_course',
            'title' => 'Programming Basics',
            'slug' => 'programming-basics',
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
        return array_merge([
            'category_id' => null,
            'title' => 'Programming Basics',
            'slug' => 'programming-basics',
            'short_description' => null,
            'description' => null,
            'publisher_name' => null,
            'cover_type' => 'image',
            'cover_image_file' => UploadedFile::fake()->image(
                'template-cover.png',
                120,
                80
            ),
            'difficulty_level' => null,
            'estimated_duration_minutes' => 0,
            'max_lessons' => null,
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
            'slug' => null,
            'short_description' => null,
            'description' => null,
            'sort_order' => 0,
            'is_preview' => 0,
            'learning_objective' => null,
            'unlock_rule' => 'none',
            'unlock_after_lesson_id' => null,
            'unlock_at' => null,
            'status' => 'draft',
        ], $overrides);
    }

    private function validActivityData(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Activity Introduction',
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
