<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourseProductCategoryAttachedMediaUiTest extends TestCase
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

        Storage::fake('media_local');
    }

    public function test_attached_media_actions_align_with_thumbnail(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'Media Korean', 'media-korean');

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-categories/{$categoryId}",
                $this->validCategoryData([
                    'name' => 'Media Korean',
                    'slug' => 'media-korean',
                    'thumbnail_image_file' => UploadedFile::fake()->image(
                        'category-thumbnail.png',
                        120,
                        120
                    ),
                    'banner_image_file' => UploadedFile::fake()->image(
                        'category-banner.jpg',
                        1200,
                        320
                    ),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $response = $this->actingAs($admin)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')->get("https://tenant-a.localhost/admin/course-categories/{$categoryId}/edit")
            ->assertOk();
        $content = $response->getContent();

        $this->assertSame(
            1,
            $this->htmlElementCount(
                $content,
                '//form[contains(concat(" ", normalize-space(@class), " "), " ui-modal__form ")]'
                .'/div[contains(concat(" ", normalize-space(@class), " "), " ui-modal__body ")]'
                .'//div[contains(concat(" ", normalize-space(@class), " "), " ui-modal__grid ")]'
            )
        );
        $slot = '//div[@data-ui-media-slot and @data-saved="true"]';
        $this->assertSame(2, $this->htmlElementCount($content, $slot));
        $this->assertSame(2, $this->htmlElementCount($content, $slot.'//img[@data-ui-media-image and @src]'));
        $this->assertSame(
            2,
            $this->htmlElementCount($content, $slot.'//button[@type="button" and @data-ui-media-view and @aria-label]')
        );
        $this->assertSame(
            2,
            $this->htmlElementCount($content, $slot.'//button[@type="button" and @data-ui-media-remove and @aria-label]')
        );
        $this->assertSame(
            2,
            $this->htmlElementCount($content, $slot.'//input[@type="file" and @data-ui-media-input]')
        );
        $this->assertSame(
            2,
            $this->htmlElementCount($content, $slot.'//input[@type="hidden" and @data-ui-media-remove-flag and @value="0"]')
        );
        $this->assertSame(
            1,
            $this->htmlElementCount(
                $content,
                '//div[@x-data][@*[name()="x-on:ui-media-preview" and contains(., "openCategoryImagePreview")]]'
            )
        );
        $this->assertSame(0, $this->htmlElementCount($content, '//div[@data-authoring-media-current-row]'));
        $this->assertStringNotContainsString('data-ui-media-name', $content);
        $this->assertSame(
            0,
            $this->htmlElementCount(
                $content,
                '//label[contains(concat(" ", normalize-space(@class), " "), " ui-upload ")]'
            )
        );
        $this->assertStringContainsString('name="remove_thumbnail_image_media"', $content);
        $this->assertStringContainsString('name="remove_banner_image_media"', $content);
        $this->assertStringContainsString('name="thumbnail_image_file"', $content);
        $this->assertStringContainsString('name="banner_image_file"', $content);
        $this->assertStringContainsString('course-category-preview-title', $content);
        $this->assertStringContainsString(
            'Định dạng: JPG, PNG, GIF, WEBP, SVG',
            $content
        );
        $this->assertStringContainsString('Tối đa:', $content);
        $this->assertStringContainsString('Ảnh đại diện', $content);
        $this->assertStringContainsString('Ảnh banner', $content);
        $this->assertStringContainsString('class="ui-modal"', $content);
        $this->assertGreaterThanOrEqual(
            1,
            $this->htmlElementCount(
                $content,
                '//*[@id="course-category-preview-title"]/ancestor::*[@x-data]'
            )
        );
        $this->assertStringNotContainsString('admin-media-action-divider', $content);
        $this->assertStringNotContainsString('target="_blank"', $content);

        $componentCss = file_get_contents(resource_path('css/admin/admin-ui-kit.css'));
        $this->assertStringContainsString('.lf-admin-ui .ui-media-tile', $componentCss);
        $this->assertStringContainsString('.lf-admin-ui .ui-media-tile__actions', $componentCss);
    }

    public function test_create_form_shows_empty_media_slots_without_remove_controls(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $content = $this->actingAs($admin)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')->get('https://tenant-a.localhost/admin/course-categories/create')
            ->assertOk()
            ->getContent();

        $slot = '//div[@data-ui-media-slot and @data-saved="false"]';
        $this->assertSame(2, $this->htmlElementCount($content, $slot));
        $this->assertSame(
            2,
            $this->htmlElementCount(
                $content,
                $slot.'//label[@data-ui-media-empty and not(@hidden)]//span[text()="Tải lên"]'
            )
        );
        $this->assertSame(
            2,
            $this->htmlElementCount($content, $slot.'//div[@data-ui-media-filled and @hidden]')
        );
        $this->assertSame(
            2,
            $this->htmlElementCount($content, $slot.'//input[@type="file" and @accept="image/*"]')
        );
    }

    public function test_upload_hint_lives_inside_the_slot_and_describes_the_file_input(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');

        $content = $this->actingAs($admin)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get('https://tenant-a.localhost/admin/course-categories/create')
            ->assertOk()
            ->getContent();

        foreach (['thumbnail_image_file', 'banner_image_file'] as $id) {
            $this->assertSame(
                1,
                $this->htmlElementCount(
                    $content,
                    '//div[@data-ui-media-slot]//div[@id="'.$id.'-hint" and contains(., "Định dạng: JPG")]'
                ),
                $id
            );
            $this->assertSame(
                1,
                $this->htmlElementCount(
                    $content,
                    '//input[@id="'.$id.'" and @aria-describedby="'.$id.'-hint"]'
                ),
                $id
            );
        }
        $this->assertSame(
            0,
            $this->htmlElementCount(
                $content,
                '//div[contains(concat(" ", normalize-space(@class), " "), " ui-grid ")]/following-sibling::*[contains(., "Định dạng")]'
            )
        );
        $css = file_get_contents(resource_path('css/admin/admin-ui-kit.css'));
        $this->assertStringContainsString('.lf-admin-ui .ui-media-slot__hint', $css);
        $this->assertStringContainsString('(hover: hover)', $css);
    }

    public function test_remove_current_image_still_detaches_usage_without_deleting_media_file(): void
    {
        $customerId = $this->createTenant();
        $admin = $this->createUser($customerId, 'customer_admin');
        $categoryId = $this->createCategory($customerId, 'Media Korean', 'media-korean');

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-categories/{$categoryId}",
                $this->validCategoryData([
                    'name' => 'Media Korean',
                    'slug' => 'media-korean',
                    'thumbnail_image_file' => UploadedFile::fake()->image(
                        'category-thumbnail.png',
                        120,
                        120
                    ),
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $usage = DB::table('media_file_usages')
            ->where('customer_id', $customerId)
            ->where('owner_type', 'course_category')
            ->where('owner_id', $categoryId)
            ->where('usage_type', 'thumbnail')
            ->where('status', 'active')
            ->first();
        $this->assertNotNull($usage);

        $mediaFile = DB::table('media_files')->where('id', $usage->media_file_id)->first();
        $this->assertNotNull($mediaFile);

        $this->actingAs($admin)
            ->put(
                "https://tenant-a.localhost/admin/course-categories/{$categoryId}",
                $this->validCategoryData([
                    'name' => 'Media Korean',
                    'slug' => 'media-korean',
                    'remove_thumbnail_image_media' => 1,
                ])
            )
            ->assertRedirect('https://tenant-a.localhost/admin/course-categories');

        $this->assertDatabaseHas('media_file_usages', [
            'id' => $usage->id,
            'status' => 'detached',
        ]);
        $this->assertDatabaseHas('media_files', [
            'id' => $mediaFile->id,
            'status' => $mediaFile->status,
        ]);
        Storage::disk('media_local')->assertExists($mediaFile->storage_key);
    }

    private function createTenant(): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => 'tenant-a',
            'slug' => 'tenant-a',
            'subdomain' => 'tenant-a',
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

    private function validCategoryData(array $overrides = []): array
    {
        return array_merge([
            'parent_id' => null,
            'name' => 'Programming',
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

    private function htmlElementCount(string $html, string $query): int
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return (new \DOMXPath($document))->query($query)->length;
    }
}
