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
            ->assertSeeText('TOPIK Beginner')
            ->assertSeeText('Course Category')
            ->assertSeeText('Thumbnail')
            ->assertSeeText('Banner Image');

        $this->assertSame(2, DB::table('media_files')->where('customer_id', $customerId)->count());
        $this->assertSame(2, DB::table('media_file_usages')->where('customer_id', $customerId)->count());
    }

    public function test_media_library_tabs_filter_file_types(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $this->actingAs($admin);
        TenantContext::set((object) ['id' => $customerId]);
        $this->uploadManagedMedia($admin, 'Image Asset', 'image', 'image.png', 'course_category', 101, 'thumbnail');
        $this->uploadManagedMedia($admin, 'Video Asset', 'video', 'video.mp4', 'course_activity', 201, 'video');
        $this->uploadManagedMedia($admin, 'Document Asset', 'document', 'document.pdf', 'course_lesson', 301, 'document');
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
            ->assertSeeText(__('lf.LF_media_file_common_preview_action'))
            ->assertSeeText('Video')
            ->assertSee('expiration=', false)
            ->assertSee('media\\/files\\/', false)
            ->assertSee('preload="none"', false)
            ->assertDontSee('<video controls preload="metadata">', false)
            ->assertDontSee('/storage/tenants/', false)
            ->assertDontSeeText('Image Asset');
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
            ->assertSeeText(__('lf.LF_media_file_common_preview_action'))
            ->assertSeeText('Video')
            ->assertSee('expiration=', false)
            ->assertSee('media\\/files\\/'.$mediaFile->id.'\\/signed', false)
            ->assertSee('openVideoPreview(', false)
            ->assertSee('preload="none"', false)
            ->assertDontSee('<video controls preload="metadata">', false)
            ->assertDontSee('/storage/tenants/', false)
            ->assertDontSee('public_url', false);

        $this->assertSame(0, $this->tableVideoElementCount($response->getContent()));
        $this->assertSame(1, substr_count($response->getContent(), '<video'));
        $this->assertStringContainsString('removeAttribute(\'src\')', $response->getContent());
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
            ->assertSee('/media/files/'.$mediaFile->id.'/signed', false)
            ->assertDontSee('/storage/tenants/', false);

        $signedUrl = app(MediaService::class)->generateSignedUrl((int) $mediaFile->id);

        $this->assertStringContainsString('/media/files/'.$mediaFile->id.'/signed', $signedUrl);
        $this->assertStringNotContainsString('/storage/tenants/', $signedUrl);

        $this->get($signedUrl)
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    private function tableVideoElementCount(string $html): int
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return (new \DOMXPath($document))
            ->query('//tbody//video')
            ->length;
    }

    public function test_media_library_filters_by_owner_and_usage_type(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCourseCategory($customerId, 'TOPIK Beginner', 'topik-beginner');

        $this->actingAs($admin);
        TenantContext::set((object) ['id' => $customerId]);
        $this->uploadManagedMedia($admin, 'Category Thumbnail', 'image', 'category.png', 'course_category', $categoryId, 'thumbnail');
        $this->uploadManagedMedia($admin, 'Lesson Document', 'document', 'lesson.pdf', 'course_lesson', 301, 'document');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?owner_type=course_category')
            ->assertOk()
            ->assertSeeText('Category Thumbnail')
            ->assertSeeText('TOPIK Beginner')
            ->assertDontSeeText('Lesson Document');

        $this->actingAs($admin)
            ->get('https://tenant-a.localhost/admin/media?usage_type=document')
            ->assertOk()
            ->assertSeeText('Lesson Document')
            ->assertDontSeeText('Category Thumbnail');
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
            ->assertSee("admin/media/{$mediaFile->id}", false);

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
