<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MediaService;
use App\Services\MediaShareService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Picking a Media that already exists in the library when authoring an activity (ADR-0021).
 */
class MediaLibraryPickerTest extends TestCase
{
    use RefreshDatabase;

    private int $uploads = 0;

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

    public function test_the_picker_lists_ready_media_of_one_type_by_what_the_user_may_see(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        $own = $this->upload($teachers[0], 'Own doc');
        $shared = $this->upload($teachers[1], 'Shared doc');
        $this->upload($teachers[1], 'Private doc of B');
        $this->upload($admin, 'Admin doc');
        $this->upload($teachers[0], 'Own audio', 'audio');
        app(MediaShareService::class)->apply($teachers[1], $customerId, $shared, 'teachers', [$teachers[0]->id]);

        $names = fn (User $user, string $query) => collect($this->actingAs($user)->getJson($query)->assertOk()->json('items'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Own doc', 'Shared doc'], $names($teachers[0], 'https://tenant-a.localhost/teacher/media/picker?type=document'));
        $this->assertSame(['Admin doc', 'Own doc', 'Private doc of B', 'Shared doc'], $names($admin, 'https://tenant-a.localhost/admin/media/picker?type=document'));
        $this->assertSame(['Own audio'], $names($teachers[0], 'https://tenant-a.localhost/teacher/media/picker?type=audio'));
        $this->assertSame(['Shared doc'], $names($teachers[0], 'https://tenant-a.localhost/teacher/media/picker?type=document&keyword=Shared'));
        $this->assertSame([], $names($teachers[2], 'https://tenant-a.localhost/teacher/media/picker?type=document'));

        $this->actingAs($teachers[0])->getJson('https://tenant-a.localhost/teacher/media/picker?type=image')->assertStatus(422);
        $this->assertNotEmpty($own);
    }

    public function test_an_activity_can_use_a_library_media_and_letting_go_keeps_someone_elses_media(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        [$templateId, $lessonId] = $this->hierarchy($customerId, $teachers[0]->id);
        $shared = $this->upload($teachers[1], 'Doc of B');
        app(MediaShareService::class)->apply($teachers[1], $customerId, $shared, 'teachers', [$teachers[0]->id]);
        $url = "https://tenant-a.localhost/teacher/course-templates/{$templateId}/lessons/{$lessonId}/activities";

        $response = $this->actingAs($teachers[0])->postJson($url, $this->activity(['existing_media_id' => $shared]))->assertCreated();
        $activityId = (int) DB::table('core_course_template_activities')->where('title', 'Library activity')->value('id');
        $this->assertDatabaseHas('media_file_usages', [
            'media_file_id' => $shared, 'owner_type' => 'course_activity', 'owner_id' => $activityId, 'usage_type' => 'document', 'status' => 'active', 'created_by' => $teachers[0]->id,
        ]);
        $this->assertSame('ready', DB::table('media_files')->where('id', $shared)->value('status'));
        $this->assertNotNull($response->json('redirect'));

        // Removing it from the activity detaches the usage but leaves B's Media in the library.
        $this->actingAs($teachers[0])->putJson("{$url}/{$activityId}", $this->activity(['title' => 'Library activity', 'remove_activity_media' => 1]))->assertOk();
        $this->assertDatabaseHas('media_file_usages', ['media_file_id' => $shared, 'owner_id' => $activityId, 'status' => 'detached']);
        $this->assertSame('ready', DB::table('media_files')->where('id', $shared)->value('status'), 'a Media someone else uploaded is not deleted');
    }

    public function test_the_activity_form_state_is_whole_and_knows_about_library_media(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        [$templateId, $lessonId] = $this->hierarchy($customerId, $teachers[0]->id);
        $html = $this->actingAs($teachers[0])->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get("https://tenant-a.localhost/teacher/course-templates/{$templateId}/lessons/{$lessonId}/activities/create")->assertOk()->getContent();

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $state = (string) (new \DOMXPath($document))->query('//*[@x-data and contains(@x-data, "activityType")]/@x-data')->item(0)?->nodeValue;
        // A raw double quote in a script comment would cut the attribute short and leave the form without its state.
        $this->assertStringContainsString('libraryMedia', $state);
        $this->assertStringContainsString('formattedMediaDuration', $state);
        $this->assertStringEndsWith('}', trim($state));
        $this->assertSame(substr_count($state, '{'), substr_count($state, '}'));
        // Choosing a library Media hides the processing choices that only make sense for a new upload.
        $this->assertSame(3, substr_count($html, 'x-on:library-media.window') + 2, 'one listener for the three pickers');
        $this->assertGreaterThanOrEqual(5, substr_count($html, '! libraryMedia'));
    }

    public function test_a_teacher_cannot_use_media_that_is_not_theirs_or_shared_or_of_the_wrong_type(): void
    {
        [$customerId, $admin, $teachers] = $this->world();
        [$templateId, $lessonId] = $this->hierarchy($customerId, $teachers[0]->id);
        $url = "https://tenant-a.localhost/teacher/course-templates/{$templateId}/lessons/{$lessonId}/activities";
        $private = $this->upload($teachers[1], 'Private of B');
        $audio = $this->upload($teachers[0], 'Own audio', 'audio');
        $foreignTenant = DB::table('saas_customers')->insertGetId(['name' => 'tenant-b', 'slug' => 'tenant-b', 'subdomain' => 'tenant-b', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        TenantContext::set((object) ['id' => $foreignTenant]);
        $foreignOwner = $this->createUser($foreignTenant, 'customer_admin');
        $foreign = $this->upload($foreignOwner, 'Foreign doc');
        TenantContext::set((object) ['id' => $customerId]);

        $this->actingAs($teachers[0])->postJson($url, $this->activity(['existing_media_id' => $private]))->assertForbidden();
        $this->actingAs($teachers[0])->postJson($url, $this->activity(['existing_media_id' => $audio]))->assertStatus(422)->assertJsonValidationErrors('existing_media_id');
        $this->actingAs($teachers[0])->postJson($url, $this->activity(['existing_media_id' => $foreign]))->assertStatus(422);
        $this->assertSame(0, DB::table('core_course_template_activities')->where('title', 'Library activity')->count(), 'nothing was created');
        $this->assertSame(0, DB::table('media_file_usages')->where('owner_type', 'course_activity')->count());

        // An admin may use any Media of the tenant.
        $adminUrl = "https://tenant-a.localhost/admin/course-templates/{$templateId}/lessons/{$lessonId}/activities";
        $this->actingAs($admin)->postJson($adminUrl, $this->activity(['existing_media_id' => $private]))->assertCreated();
    }

    /**
     * @return array{0: int, 1: User, 2: array<int, User>}
     */
    private function world(): array
    {
        $customerId = DB::table('saas_customers')->insertGetId(['name' => 'tenant-a', 'slug' => 'tenant-a', 'subdomain' => 'tenant-a', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        TenantContext::set((object) ['id' => $customerId]);
        $admin = $this->createUser($customerId, 'customer_admin');
        $teachers = [$this->createUser($customerId, 'teacher'), $this->createUser($customerId, 'teacher'), $this->createUser($customerId, 'teacher')];

        return [$customerId, $admin, $teachers];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function hierarchy(int $customerId, int $teacherId): array
    {
        $templateId = DB::table('core_course_templates')->insertGetId([
            'customer_id' => $customerId, 'category_id' => null, 'title' => 'Template', 'short_description' => null, 'description' => null,
            'publisher_name' => null, 'intro_video_source' => null, 'intro_image_media_file_id' => null, 'intro_video_media_file_id' => null,
            'difficulty_level' => null, 'estimated_minutes_per_lesson' => 0, 'estimated_lesson_count' => null, 'lesson_count' => 0,
            'meta_title' => null, 'meta_description' => null, 'meta_keywords' => null, 'working_revision' => 1, 'status' => 'draft',
            'created_by' => $teacherId, 'last_version_published_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lessonId = DB::table('core_course_template_lessons')->insertGetId([
            'customer_id' => $customerId, 'template_id' => $templateId, 'template_section_id' => null, 'title' => 'Lesson',
            'short_description' => null, 'description' => null, 'sort_order' => 0, 'is_preview' => false, 'duration_seconds' => 0,
            'activity_count' => 0, 'unlock_rule' => 'none', 'unlock_after_lesson_id' => null, 'unlock_at' => null,
            'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$templateId, $lessonId];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function activity(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Library activity', 'description' => null, 'sort_order' => 0, 'activity_type' => 'document',
            'external_video_url' => null, 'live_class_url' => null, 'assessment_quiz_id' => null, 'is_required' => 1,
            'completion_rule' => 'view', 'completion_threshold' => null, 'is_preview' => 0, 'unlock_rule' => 'none',
            'unlock_after_activity_id' => null, 'unlock_at' => null,
        ], $overrides);
    }

    private function upload(User $user, string $name, string $type = 'document'): int
    {
        // Unattached uploads with the same entity replace each other and equal content is deduplicated: make each one unique.
        $this->uploads++;
        $extension = $type === 'audio' ? 'mp3' : 'pdf';
        $file = $type === 'audio'
            ? UploadedFile::fake()->createWithContent(str($name)->slug().'.mp3', 'ID3'.$name)
            : UploadedFile::fake()->createWithContent(str($name)->slug().'.pdf', "%PDF-1.4\n".$name."\n%%EOF");

        return (int) app(MediaService::class)->uploadMedia(
            $file,
            ['file_type' => $type, 'module' => 'media', 'entity_type' => 'library', 'entity_id' => $this->uploads, 'purpose' => 'upload', 'display_name' => $name],
            $user->id
        )->id;
    }

    private function createUser(int $customerId, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
