<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Services\MediaShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Prompt media of a question (ADR-0025 D4): one image and one audio uploaded in the question form; each keeps a
 * core_assessment_question_media row and an active media_file_usages row (owner assessment_question). A Media that
 * already exists may be picked from the library instead (ADR-0021: a teacher only their own or one shared with them).
 */
class AssessmentQuestionMediaTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://tenant-a.localhost/admin/assessments/questions';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private int $customerId;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
            'media.disk' => 'media_local',
        ]);
        Storage::fake('media_local');

        $this->customerId = DB::table('saas_customers')->insertGetId([
            'name' => 'tenant-a', 'slug' => 'tenant-a', 'subdomain' => 'tenant-a', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::forceCreate([
            'customer_id' => $this->customerId, 'name' => 'Admin', 'email' => 'admin-'.uniqid().'@example.test',
            'password' => Hash::make('password123'), 'role' => 'customer_admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    public function test_image_and_audio_are_attached_with_usages(): void
    {
        $id = $this->create([
            'prompt_image_file' => UploadedFile::fake()->image('hinh.png', 40, 30),
            'prompt_audio_file' => UploadedFile::fake()->create('nghe.mp3', 32, 'audio/mpeg'),
        ]);

        $rows = DB::table('core_assessment_question_media')->where('question_id', $id)->orderBy('media_role')->get();
        $this->assertSame(['prompt_audio', 'prompt_image'], $rows->pluck('media_role')->all());

        foreach ($rows as $row) {
            $usage = DB::table('media_file_usages')->where('media_file_id', $row->media_file_id)->first();
            $this->assertSame(['assessment_question', $id, 'active', $this->customerId],
                [$usage->owner_type, (int) $usage->owner_id, $usage->status, (int) $usage->customer_id]);
        }
        $this->assertSame(['audio', 'image'], DB::table('media_files')->orderBy('file_type')->pluck('file_type')->all());

        $this->actingAs($this->admin)->get(self::BASE)->assertOk()
            ->assertSeeText(trans_choice('lf.LF_assessment_question_media_count', 2, ['count' => 2]));
        $this->actingAs($this->admin)->get(self::BASE.'/'.$id.'/edit', self::AJAX)->assertOk()
            ->assertSee('name="remove_prompt_image"', false);

        // The Media library names the question by its prompt and links to the question list.
        $this->actingAs($this->admin)->get('https://tenant-a.localhost/admin/media')->assertOk()
            ->assertSeeText('Nghe và chọn đáp án đúng')
            ->assertSee('https://tenant-a.localhost/admin/assessments/questions', false);
    }

    public function test_a_new_file_replaces_the_old_one_and_remove_only_detaches(): void
    {
        $id = $this->create(['prompt_image_file' => UploadedFile::fake()->image('cu.png', 20, 20)]);
        $oldMedia = (int) DB::table('core_assessment_question_media')->where('question_id', $id)->value('media_file_id');

        $this->update($id, ['prompt_image_file' => UploadedFile::fake()->image('moi.png', 60, 40)]);
        $newMedia = (int) DB::table('core_assessment_question_media')->where('question_id', $id)->value('media_file_id');
        $this->assertNotSame($oldMedia, $newMedia);
        $this->assertSame('detached', DB::table('media_file_usages')->where('media_file_id', $oldMedia)->value('status'));
        $this->assertSame('active', DB::table('media_file_usages')->where('media_file_id', $newMedia)->value('status'));

        // Saving without touching the slot keeps the image.
        $this->update($id, []);
        $this->assertSame(1, DB::table('core_assessment_question_media')->where('question_id', $id)->count());

        $this->update($id, ['remove_prompt_image' => 1]);
        $this->assertSame(0, DB::table('core_assessment_question_media')->where('question_id', $id)->count());
        $this->assertSame('detached', DB::table('media_file_usages')->where('media_file_id', $newMedia)->value('status'));
        // The file stays in the Media library; only the question lets go of it.
        $this->assertTrue(DB::table('media_files')->where('id', $newMedia)->exists());
    }

    public function test_wrong_file_types_are_refused(): void
    {
        $this->actingAs($this->admin)->post(self::BASE, $this->payload([
            'prompt_image_file' => UploadedFile::fake()->create('ghi-chu.pdf', 10, 'application/pdf'),
            'prompt_audio_file' => UploadedFile::fake()->create('anh.png', 10, 'image/png'),
        ]), self::AJAX)->assertUnprocessable()->assertJsonValidationErrors(['prompt_image_file', 'prompt_audio_file']);

        $this->assertSame(0, DB::table('core_assessment_questions')->count());
        $this->assertSame(0, DB::table('media_files')->count());
    }

    public function test_deleting_a_question_releases_its_media_usages(): void
    {
        $id = $this->create(['prompt_audio_file' => UploadedFile::fake()->create('nghe.mp3', 32, 'audio/mpeg')]);
        $mediaId = (int) DB::table('core_assessment_question_media')->where('question_id', $id)->value('media_file_id');

        $this->actingAs($this->admin)->deleteJson(self::BASE, ['ids' => [$id]], self::AJAX)->assertOk();

        $this->assertSame(0, DB::table('core_assessment_question_media')->count());
        $this->assertSame('detached', DB::table('media_file_usages')->where('media_file_id', $mediaId)->value('status'));
    }

    public function test_a_media_from_the_library_is_attached_without_a_new_file(): void
    {
        $image = $this->libraryMedia($this->admin, 'image', 'thu-vien.png', 'image/png');
        $audio = $this->libraryMedia($this->admin, 'audio', 'thu-vien.mp3', 'audio/mpeg');

        // The picker lists images too, with a preview to choose by sight.
        $items = $this->actingAs($this->admin)->getJson('https://tenant-a.localhost/admin/media/picker?type=image')->assertOk()->json('items');
        $this->assertSame([$image], array_column($items, 'id'));
        $this->assertNotSame('', $items[0]['preview']);

        $id = $this->create(['prompt_image_library_id' => $image, 'prompt_audio_library_id' => $audio]);

        $this->assertSame([$audio, $image], DB::table('core_assessment_question_media')->where('question_id', $id)
            ->orderBy('media_role')->pluck('media_file_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(2, DB::table('media_files')->count());
        $this->assertSame(2, DB::table('media_file_usages')->where('owner_type', 'assessment_question')->where('owner_id', $id)->where('status', 'active')->count());

        // Picking another replaces it; the old one is only detached, never deleted (it belongs to the library).
        $other = $this->libraryMedia($this->admin, 'image', 'khac.png', 'image/png');
        $this->update($id, ['prompt_image_library_id' => $other]);
        $this->assertSame($other, (int) DB::table('core_assessment_question_media')->where('question_id', $id)->where('media_role', 'prompt_image')->value('media_file_id'));
        $this->assertSame('detached', DB::table('media_file_usages')->where('media_file_id', $image)->value('status'));
        $this->assertSame('ready', DB::table('media_files')->where('id', $image)->value('status'));

        // An upload in the same submit wins over the pick.
        $this->update($id, ['prompt_image_library_id' => $image, 'prompt_image_file' => UploadedFile::fake()->image('moi.png', 20, 20)]);
        $this->assertNotContains((int) DB::table('core_assessment_question_media')->where('question_id', $id)->where('media_role', 'prompt_image')->value('media_file_id'), [$image, $other]);
    }

    public function test_a_library_pick_of_the_wrong_kind_saves_nothing(): void
    {
        $audio = $this->libraryMedia($this->admin, 'audio', 'nghe.mp3', 'audio/mpeg');
        $processing = $this->libraryMedia($this->admin, 'image', 'dang-xu-ly.png', 'image/png', 'processing');
        $otherCustomer = DB::table('saas_customers')->insertGetId([
            'name' => 'tenant-b', 'slug' => 'tenant-b', 'subdomain' => 'tenant-b', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherAdmin = User::forceCreate([
            'customer_id' => $otherCustomer, 'name' => 'Other', 'email' => 'other-'.uniqid().'@example.test',
            'password' => Hash::make('password123'), 'role' => 'customer_admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $foreign = $this->libraryMedia($otherAdmin, 'image', 'ngoai.png', 'image/png', 'ready', $otherCustomer);

        foreach ([$audio, $processing, $foreign] as $mediaId) {
            $this->actingAs($this->admin)->post(self::BASE, $this->payload(['prompt_image_library_id' => $mediaId]), self::AJAX)
                ->assertUnprocessable()->assertJsonValidationErrors(['prompt_image_file']);
        }

        $this->assertSame(0, DB::table('core_assessment_questions')->count());
        $this->assertSame(0, DB::table('media_file_usages')->count());
    }

    public function test_a_teacher_picks_only_their_own_or_shared_media(): void
    {
        $teacher = User::forceCreate([
            'customer_id' => $this->customerId, 'name' => 'Teacher', 'email' => 'teacher-'.uniqid().'@example.test',
            'password' => Hash::make('password123'), 'role' => 'teacher', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $mine = $this->libraryMedia($teacher, 'image', 'cua-toi.png', 'image/png');
        $adminOnly = $this->libraryMedia($this->admin, 'image', 'rieng.png', 'image/png');
        $url = 'https://tenant-a.localhost/teacher/assessments/questions';

        $this->actingAs($teacher)->post($url, $this->payload(['prompt_image_library_id' => $adminOnly]), self::AJAX)
            ->assertUnprocessable()->assertJsonValidationErrors(['prompt_image_file']);
        $this->assertSame(0, DB::table('core_assessment_questions')->count());

        $this->actingAs($teacher)->post($url, $this->payload(['prompt_image_library_id' => $mine]), self::AJAX)->assertCreated();

        app(MediaShareService::class)->apply($this->admin, $this->customerId, $adminOnly, MediaShareService::SCOPE_ALL_TEACHERS);
        $this->actingAs($teacher)->post($url, $this->payload(['prompt_image_library_id' => $adminOnly]), self::AJAX)->assertCreated();

        $this->assertSame([$mine, $adminOnly], DB::table('core_assessment_question_media')->orderBy('id')->pluck('media_file_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_the_form_offers_the_library(): void
    {
        $this->actingAs($this->admin)->get(self::BASE.'/create', self::AJAX)->assertOk()
            ->assertSee('name="prompt_image_library_id"', false)
            ->assertSee('name="prompt_audio_library_id"', false)
            ->assertSee('data-ui-media-library', false)
            ->assertSee('ui-library-view', false);
    }

    private function libraryMedia(User $owner, string $type, string $name, string $mime, string $status = 'ready', ?int $customerId = null): int
    {
        $customerId ??= $this->customerId;

        return DB::table('media_files')->insertGetId([
            'customer_id' => $customerId, 'uploaded_by' => $owner->id,
            'file_type' => $type, 'mime_type' => $mime, 'original_name' => $name, 'display_name' => $name,
            'extension' => pathinfo($name, PATHINFO_EXTENSION), 'storage_disk' => 'media_local', 'storage_bucket' => 'lf-test-media', 'storage_region' => 'ap-southeast-1',
            'storage_key' => "tenants/{$customerId}/library/{$name}", 'checksum' => 'sha256:'.hash('sha256', $customerId.$name),
            'file_size_bytes' => 32, 'visibility' => 'private', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function create(array $extra): int
    {
        $this->actingAs($this->admin)->post(self::BASE, $this->payload($extra), self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_questions')->max('id');
    }

    /** @param  array<string, mixed>  $extra */
    private function update(int $id, array $extra): void
    {
        $this->actingAs($this->admin)->post(self::BASE.'/'.$id, $this->payload($extra + ['_method' => 'PUT']), self::AJAX)->assertOk();
    }

    /** @param  array<string, mixed>  $extra */
    private function payload(array $extra): array
    {
        return $extra + [
            'question_type' => 'single_choice',
            'default_points' => '1',
            'status' => 'draft',
            'prompt' => 'Nghe và chọn đáp án đúng',
            'options' => [['text' => 'Một', 'correct' => 1], ['text' => 'Hai', 'correct' => 0]],
        ];
    }
}
