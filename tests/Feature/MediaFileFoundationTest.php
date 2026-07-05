<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class MediaFileFoundationTest extends TestCase
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

        URL::forceRootUrl('https://tenant-a.localhost');
        URL::forceScheme('https');
        Storage::fake('media_local');
        TenantContext::set(null);
    }

    public function test_media_files_table_matches_sprint_two_a_scope(): void
    {
        $this->assertTrue(Schema::hasTable('media_files'));

        foreach ([
            'id',
            'customer_id',
            'category_id',
            'uploaded_by',
            'file_type',
            'mime_type',
            'original_name',
            'display_name',
            'extension',
            'storage_disk',
            'storage_bucket',
            'storage_region',
            'storage_key',
            'storage_class',
            'cdn_url',
            'public_url',
            'checksum',
            'file_size_bytes',
            'duration_seconds',
            'width',
            'height',
            'page_count',
            'language',
            'visibility',
            'status',
            'metadata',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('media_files', $column));
        }

        $this->assertFalse(Schema::hasColumn('media_files', 'path'));
    }

    public function test_media_file_usages_table_matches_sprint_two_b_scope(): void
    {
        $this->assertTrue(Schema::hasTable('media_file_usages'));

        foreach ([
            'id',
            'customer_id',
            'media_file_id',
            'owner_type',
            'owner_id',
            'usage_type',
            'status',
            'metadata',
            'created_by',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('media_file_usages', $column));
        }

        $this->assertFalse(Schema::hasColumn('media_file_usages', 'sort_order'));
        $this->assertFalse(Schema::hasColumn('media_file_usages', 'is_primary'));
    }

    public function test_media_disks_keep_local_and_s3_configuration_separate(): void
    {
        $mediaLocal = config('filesystems.disks.media_local');
        $mediaS3 = config('filesystems.disks.media_s3');

        $this->assertSame('local', $mediaLocal['driver']);
        $this->assertSame(storage_path('app/media'), $mediaLocal['root']);
        $this->assertSame('private', $mediaLocal['visibility']);
        $this->assertSame('s3', $mediaS3['driver']);
        $this->assertSame('private', $mediaS3['visibility']);
        $this->assertArrayNotHasKey('root', $mediaS3);
        $this->assertArrayNotHasKey('serve', $mediaS3);
    }

    public function test_upload_media_creates_tenant_aware_private_storage_record(): void
    {
        $customerId = $this->createTenant();
        $user = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId);
        TenantContext::set((object) ['id' => $customerId]);

        $mediaFile = app(MediaService::class)->uploadMedia(
            UploadedFile::fake()->image('original-course-cover.png', 80, 60),
            [
                'category_id' => $categoryId,
                'file_type' => 'image',
                'module' => 'course',
                'entity_type' => 'activities',
                'entity_id' => 9001,
                'purpose' => 'cover',
                'display_name' => 'Course Cover',
                'metadata' => ['source' => 'test'],
            ],
            $user->id
        );

        $this->assertSame($customerId, (int) $mediaFile->customer_id);
        $this->assertSame($categoryId, (int) $mediaFile->category_id);
        $this->assertSame($user->id, (int) $mediaFile->uploaded_by);
        $this->assertSame('image', $mediaFile->file_type);
        $this->assertSame('image/png', $mediaFile->mime_type);
        $this->assertSame('original-course-cover.png', $mediaFile->original_name);
        $this->assertSame('Course Cover', $mediaFile->display_name);
        $this->assertSame('png', $mediaFile->extension);
        $this->assertSame('media_local', $mediaFile->storage_disk);
        $this->assertSame('lf-test-media', $mediaFile->storage_bucket);
        $this->assertSame('ap-southeast-1', $mediaFile->storage_region);
        $this->assertMatchesRegularExpression(
            '#^tenants/'.$customerId.'/course/activities/9001/cover/[0-9A-HJKMNP-TV-Z]{26}\.png$#',
            $mediaFile->storage_key
        );
        $this->assertStringNotContainsString(
            'original-course-cover',
            $mediaFile->storage_key
        );
        $this->assertSame('private', $mediaFile->visibility);
        $this->assertSame('ready', $mediaFile->status);
        $this->assertNull($mediaFile->public_url);
        $this->assertNull($mediaFile->cdn_url);
        $this->assertStringStartsWith('sha256:', $mediaFile->checksum);

        Storage::disk('media_local')->assertExists($mediaFile->storage_key);
    }

    public function test_upload_validation_is_tenant_scoped_for_category_and_uploader(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $user = $this->createUser($customerId, 'customer_admin');
        $otherUser = $this->createUser($otherCustomerId, 'customer_admin');
        $otherCategoryId = $this->createCategory($otherCustomerId);
        TenantContext::set((object) ['id' => $customerId]);

        try {
            app(MediaService::class)->uploadMedia(
                UploadedFile::fake()->image('cover.png'),
                [
                    'category_id' => $otherCategoryId,
                    'file_type' => 'image',
                    'module' => 'course',
                    'entity_type' => 'activities',
                    'entity_id' => 9001,
                    'purpose' => 'cover',
                ],
                $user->id
            );

            Assert::fail('Cross-tenant media category was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category_id', $exception->errors());
        }

        try {
            app(MediaService::class)->uploadMedia(
                UploadedFile::fake()->image('cover.png'),
                [
                    'file_type' => 'image',
                    'module' => 'course',
                    'entity_type' => 'activities',
                    'entity_id' => 9001,
                    'purpose' => 'cover',
                ],
                $otherUser->id
            );

            Assert::fail('Cross-tenant uploader was accepted.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_upload_rejects_mismatched_file_type_mime_and_extension(): void
    {
        $customerId = $this->createTenant();
        $user = $this->createUser($customerId, 'customer_admin');
        TenantContext::set((object) ['id' => $customerId]);

        $this->expectException(ValidationException::class);

        app(MediaService::class)->uploadMedia(
            UploadedFile::fake()->create(
                'lesson.pdf',
                12,
                'application/pdf'
            ),
            [
                'file_type' => 'image',
                'module' => 'course',
                'entity_type' => 'activities',
                'entity_id' => 9001,
                'purpose' => 'cover',
            ],
            $user->id
        );
    }

    public function test_signed_url_generation_rechecks_tenant_and_ready_status(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $user = $this->createUser($customerId, 'customer_admin');
        TenantContext::set((object) ['id' => $customerId]);
        $service = app(MediaService::class);

        $mediaFile = $service->uploadMedia(
            UploadedFile::fake()->image('cover.png'),
            [
                'file_type' => 'image',
                'module' => 'course',
                'entity_type' => 'activities',
                'entity_id' => 9001,
                'purpose' => 'cover',
            ],
            $user->id
        );

        $url = $service->generateSignedUrl($mediaFile->id, now()->addMinutes(5));

        $this->assertIsString($url);
        $this->assertNotEmpty($url);
        $this->assertDatabaseHas('media_files', [
            'id' => $mediaFile->id,
            'customer_id' => $customerId,
            'public_url' => null,
            'cdn_url' => null,
        ]);

        DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('id', $mediaFile->id)
            ->update(['status' => 'failed']);

        try {
            $service->generateSignedUrl($mediaFile->id, now()->addMinutes(5));

            Assert::fail('Signed URL was generated for non-ready media.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        DB::table('media_files')
            ->where('customer_id', $customerId)
            ->where('id', $mediaFile->id)
            ->update(['status' => 'ready']);
        TenantContext::set((object) ['id' => $otherCustomerId]);

        try {
            $service->generateSignedUrl($mediaFile->id, now()->addMinutes(5));

            Assert::fail('Signed URL was generated across tenants.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_signed_delivery_route_is_tenant_scoped_and_private(): void
    {
        $customerId = $this->createTenant();
        $user = $this->createUser($customerId, 'customer_admin');
        TenantContext::set((object) ['id' => $customerId]);

        $mediaFile = app(MediaService::class)->uploadMedia(
            UploadedFile::fake()->image('cover.png'),
            [
                'file_type' => 'image',
                'module' => 'course',
                'entity_type' => 'activities',
                'entity_id' => 9001,
                'purpose' => 'cover',
            ],
            $user->id
        );

        $url = URL::temporarySignedRoute(
            'media.files.signed',
            now()->addMinutes(5),
            ['mediaFile' => $mediaFile->id]
        );

        $this->get($url)
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->get(str_replace('tenant-a.', 'tenant-b.', $url))
            ->assertNotFound();
    }

    public function test_tenant_can_attach_media_file_usage(): void
    {
        $customerId = $this->createTenant();
        $user = $this->createUser($customerId, 'customer_admin');
        TenantContext::set((object) ['id' => $customerId]);
        $this->actingAs($user);

        $mediaFile = $this->uploadTestMedia($user);
        $usage = app(MediaService::class)->attachUsage(
            $mediaFile->id,
            'course_activity',
            9001,
            'video',
            ['slot' => 'primary']
        );

        $this->assertSame($customerId, (int) $usage->customer_id);
        $this->assertSame($mediaFile->id, (int) $usage->media_file_id);
        $this->assertSame('course_activity', $usage->owner_type);
        $this->assertSame(9001, (int) $usage->owner_id);
        $this->assertSame('video', $usage->usage_type);
        $this->assertSame('active', $usage->status);
        $this->assertSame($user->id, (int) $usage->created_by);
        $this->assertSame('{"slot":"primary"}', $usage->metadata);
    }

    public function test_attach_usage_is_idempotent_and_reactivates_existing_usage(): void
    {
        $customerId = $this->createTenant();
        $user = $this->createUser($customerId, 'customer_admin');
        TenantContext::set((object) ['id' => $customerId]);
        $this->actingAs($user);

        $mediaFile = $this->uploadTestMedia($user);
        $service = app(MediaService::class);

        $first = $service->attachUsage(
            $mediaFile->id,
            'course_activity',
            9001,
            'video'
        );
        $second = $service->attachUsage(
            $mediaFile->id,
            'course_activity',
            9001,
            'video',
            ['reactivated' => true]
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('media_file_usages')->count());
        $this->assertSame('active', $second->status);

        $detached = $service->detachUsage(
            $mediaFile->id,
            'course_activity',
            9001,
            'video'
        );
        $this->assertSame('detached', $detached->status);

        $reactivated = $service->attachUsage(
            $mediaFile->id,
            'course_activity',
            9001,
            'video'
        );

        $this->assertSame($first->id, $reactivated->id);
        $this->assertSame('active', $reactivated->status);
        $this->assertSame(1, DB::table('media_file_usages')->count());
    }

    public function test_tenant_cannot_attach_another_tenants_media_file(): void
    {
        $customerId = $this->createTenant();
        $otherCustomerId = $this->createTenant('tenant-b');
        $user = $this->createUser($customerId, 'customer_admin');
        $otherUser = $this->createUser($otherCustomerId, 'customer_admin');
        TenantContext::set((object) ['id' => $customerId]);
        $mediaFile = $this->uploadTestMedia($user);

        TenantContext::set((object) ['id' => $otherCustomerId]);
        $this->actingAs($otherUser);

        $this->expectException(NotFoundHttpException::class);

        app(MediaService::class)->attachUsage(
            $mediaFile->id,
            'course_activity',
            9001,
            'video'
        );
    }

    public function test_detach_usage_marks_usage_as_detached_without_deleting(): void
    {
        $customerId = $this->createTenant();
        $user = $this->createUser($customerId, 'customer_admin');
        TenantContext::set((object) ['id' => $customerId]);

        $mediaFile = $this->uploadTestMedia($user);
        $service = app(MediaService::class);
        $usage = $service->attachUsage(
            $mediaFile->id,
            'course_activity',
            9001,
            'video'
        );

        $detached = $service->detachUsage(
            $mediaFile->id,
            'course_activity',
            9001,
            'video'
        );

        $this->assertSame($usage->id, $detached->id);
        $this->assertSame('detached', $detached->status);
        $this->assertSame(1, DB::table('media_file_usages')->count());
    }

    public function test_is_in_use_returns_true_only_for_active_usage(): void
    {
        $customerId = $this->createTenant();
        $user = $this->createUser($customerId, 'customer_admin');
        TenantContext::set((object) ['id' => $customerId]);

        $mediaFile = $this->uploadTestMedia($user);
        $service = app(MediaService::class);

        $this->assertFalse($service->isInUse($mediaFile->id));

        $service->attachUsage(
            $mediaFile->id,
            'course_activity',
            9001,
            'video'
        );
        $this->assertTrue($service->isInUse($mediaFile->id));

        $service->detachUsage(
            $mediaFile->id,
            'course_activity',
            9001,
            'video'
        );
        $this->assertFalse($service->isInUse($mediaFile->id));

        DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('media_file_id', $mediaFile->id)
            ->update(['status' => 'archived']);
        $this->assertFalse($service->isInUse($mediaFile->id));
    }

    public function test_delete_media_is_blocked_when_active_usage_exists(): void
    {
        $customerId = $this->createTenant();
        $user = $this->createUser($customerId, 'customer_admin');
        TenantContext::set((object) ['id' => $customerId]);

        $mediaFile = $this->uploadTestMedia($user);
        $service = app(MediaService::class);
        $service->attachUsage(
            $mediaFile->id,
            'course_activity',
            9001,
            'video'
        );

        try {
            $service->deleteMedia($mediaFile->id);

            Assert::fail('Media delete was allowed with active usage.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('media_file_id', $exception->errors());
        }

        $this->assertDatabaseHas('media_files', [
            'id' => $mediaFile->id,
            'customer_id' => $customerId,
            'status' => 'ready',
        ]);
        Storage::disk('media_local')->assertExists($mediaFile->storage_key);
    }

    public function test_owner_media_can_be_listed_by_owner_and_usage_type(): void
    {
        $customerId = $this->createTenant();
        $user = $this->createUser($customerId, 'customer_admin');
        TenantContext::set((object) ['id' => $customerId]);

        $video = $this->uploadTestMedia($user);
        $thumbnail = $this->uploadTestMedia($user, [
            'purpose' => 'thumbnail',
        ]);
        $otherOwner = $this->uploadTestMedia($user, [
            'entity_id' => 9002,
        ]);
        $service = app(MediaService::class);

        $service->attachUsage($video->id, 'course_activity', 9001, 'video');
        $service->attachUsage(
            $thumbnail->id,
            'course_activity',
            9001,
            'thumbnail'
        );
        $service->attachUsage($otherOwner->id, 'course_activity', 9002, 'video');

        $videos = $service->getOwnerMedia('course_activity', 9001, 'video');
        $allOwnerMedia = $service->getOwnerMedia('course_activity', 9001);

        $this->assertCount(1, $videos);
        $this->assertSame($video->id, (int) $videos->first()->id);
        $this->assertSame('video', $videos->first()->usage_type);
        $this->assertCount(2, $allOwnerMedia);
        $this->assertEquals(
            [$video->id, $thumbnail->id],
            $allOwnerMedia->pluck('id')->map(fn ($id): int => (int) $id)->all()
        );
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

    private function createCategory(int $customerId): int
    {
        return DB::table('media_categories')->insertGetId([
            'customer_id' => $customerId,
            'parent_id' => null,
            'name' => 'Images',
            'slug' => 'images',
            'description' => null,
            'icon' => null,
            'color' => null,
            'sort_order' => 1,
            'status' => 'active',
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function uploadTestMedia(User $user, array $overrides = []): object
    {
        return app(MediaService::class)->uploadMedia(
            UploadedFile::fake()->image(
                $overrides['filename'] ?? 'cover.png',
                80,
                60
            ),
            array_merge([
                'file_type' => 'image',
                'module' => 'course',
                'entity_type' => 'activities',
                'entity_id' => 9001,
                'purpose' => 'cover',
            ], $overrides),
            $user->id
        );
    }
}
