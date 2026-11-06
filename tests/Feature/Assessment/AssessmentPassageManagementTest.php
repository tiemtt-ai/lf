<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ngữ liệu of the Kiểm tra module (ADR-0025 D3–D4): a passage with shared text and media (step 1) and the passage page
 * that adds, edits, orders and deletes its child questions (step 2). Teachers own their passages and every child.
 */
class AssessmentPassageManagementTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'https://tenant-a.localhost/admin/assessments/passages';

    private const TEACHER = 'https://tenant-a.localhost/teacher/assessments/passages';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private int $customerId;

    private User $admin;

    private User $teacher;

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

        $this->customerId = $this->createTenant('tenant-a');
        $this->admin = $this->createUser($this->customerId, 'customer_admin');
        $this->teacher = $this->createUser($this->customerId, 'teacher');
    }

    public function test_a_passage_is_stored_and_opens_its_own_page(): void
    {
        $category = $this->category('Reading');

        $response = $this->actingAs($this->teacher)->postJson(self::TEACHER, $this->payload([
            'title' => '  A day at the zoo  ', 'content' => "Line one\nLine two", 'category_id' => $category,
            'skill_type' => 'reading', 'difficulty_level' => 'easy',
        ]), self::AJAX)->assertCreated();

        $passage = DB::table('core_assessment_passages')->first();
        $response->assertJson(['redirect' => self::TEACHER.'/'.$passage->id]);
        $this->assertSame(
            ['A day at the zoo', "Line one\nLine two", $category, 'reading', 'easy', 'draft', $this->teacher->id, $this->customerId],
            [$passage->title, $passage->content, (int) $passage->category_id, $passage->skill_type, $passage->difficulty_level,
                $passage->status, (int) $passage->created_by, (int) $passage->customer_id]
        );

        $this->actingAs($this->teacher)->get(self::TEACHER)->assertOk()->assertSeeText('A day at the zoo');
        $this->actingAs($this->teacher)->get(self::TEACHER.'/'.$passage->id)->assertOk()
            ->assertSeeText('A day at the zoo')->assertSeeText('Reading')
            ->assertSeeText(__('lf.LF_assessment_passage_questions_empty'));
        $this->actingAs($this->teacher)->get(self::TEACHER.'/'.$passage->id.'/edit', self::AJAX)->assertOk()
            ->assertSee('value="A day at the zoo"', false);

        $this->actingAs($this->teacher)->putJson(self::TEACHER.'/'.$passage->id, $this->payload(['title' => 'Đổi tên', 'status' => 'published']), self::AJAX)
            ->assertOk()->assertJson(['message' => __('lf.LF_assessment_passage_updated')]);
        $this->assertSame(['Đổi tên', 'published'], [
            DB::table('core_assessment_passages')->value('title'), DB::table('core_assessment_passages')->value('status'),
        ]);
    }

    public function test_input_is_validated(): void
    {
        $this->actingAs($this->admin)->postJson(self::ADMIN, ['title' => '', 'status' => 'Draft', 'skill_type' => 'cooking'], self::AJAX)
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'status', 'skill_type']);
        $this->actingAs($this->admin)->postJson(self::ADMIN, $this->payload(['category_id' => $this->category('Cũ', 'archived')]), self::AJAX)
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
        $this->assertSame(0, DB::table('core_assessment_passages')->count());
    }

    public function test_index_filters_by_keyword_skill_and_status(): void
    {
        $this->passage($this->admin, ['title' => 'Bài đọc số một', 'skill_type' => 'reading']);
        $this->passage($this->admin, ['title' => 'Bài nghe số hai', 'skill_type' => 'listening', 'status' => 'published']);

        $this->actingAs($this->admin)->get(self::ADMIN.'?keyword='.urlencode('đọc'))->assertOk()
            ->assertSeeText('Bài đọc số một')->assertDontSeeText('Bài nghe số hai');
        $this->actingAs($this->admin)->get(self::ADMIN.'?skill_type=listening')->assertOk()
            ->assertSeeText('Bài nghe số hai')->assertDontSeeText('Bài đọc số một');
        $this->actingAs($this->admin)->get(self::ADMIN.'?status=draft')->assertOk()
            ->assertSeeText('Bài đọc số một')->assertDontSeeText('Bài nghe số hai');
    }

    public function test_child_questions_are_added_in_order_and_shown_on_the_passage_page(): void
    {
        $passage = $this->passage($this->teacher, ['title' => 'Bài đọc']);

        $this->actingAs($this->teacher)->get('https://tenant-a.localhost/teacher/assessments/questions/create?passage_id='.$passage, self::AJAX)
            ->assertOk()->assertSee('name="passage_id" value="'.$passage.'"', false)->assertSeeText('Bài đọc');

        $first = $this->child($this->teacher, $passage, 'Câu thứ nhất');
        $second = $this->child($this->teacher, $passage, 'Câu thứ hai');

        $this->assertSame([[$first, 1], [$second, 2]], $this->order($passage));
        $this->actingAs($this->teacher)->get(self::TEACHER.'/'.$passage)->assertOk()
            ->assertSeeTextInOrder(['Câu thứ nhất', 'Câu thứ hai']);
        $this->actingAs($this->teacher)->get(self::TEACHER)->assertOk()->assertSee('data-label="'.__('lf.LF_assessment_passage_question_count').'">2<', false);
        // The question list says which passage a child belongs to.
        $this->actingAs($this->teacher)->get('https://tenant-a.localhost/teacher/assessments/questions')->assertOk()
            ->assertSeeText(__('lf.LF_assessment_question_in_passage', ['title' => 'Bài đọc']));
    }

    public function test_children_move_up_and_down_and_are_renumbered(): void
    {
        $passage = $this->passage($this->admin);
        [$a, $b, $c] = [$this->child($this->admin, $passage, 'A'), $this->child($this->admin, $passage, 'B'), $this->child($this->admin, $passage, 'C')];
        $move = fn (int $question, string $direction) => $this->actingAs($this->admin)
            ->from(self::ADMIN.'/'.$passage)->post(self::ADMIN.'/'.$passage.'/questions/'.$question.'/move', ['direction' => $direction]);

        $move($c, 'up')->assertRedirect(self::ADMIN.'/'.$passage);
        $this->assertSame([[$a, 1], [$c, 2], [$b, 3]], $this->order($passage));

        $move($a, 'down');
        $this->assertSame([[$c, 1], [$a, 2], [$b, 3]], $this->order($passage));

        // The edges do nothing; a deleted child leaves a gap that the next move closes.
        $move($c, 'up');
        $this->assertSame([[$c, 1], [$a, 2], [$b, 3]], $this->order($passage));
        $this->actingAs($this->admin)->deleteJson('https://tenant-a.localhost/admin/assessments/questions', ['ids' => [$a]], self::AJAX)->assertOk();
        $move($b, 'up');
        $this->assertSame([[$b, 1], [$c, 2]], $this->order($passage));

        $move($b, 'sideways')->assertSessionHasErrors('direction');
        $other = $this->passage($this->admin);
        $this->actingAs($this->admin)->post(self::ADMIN.'/'.$other.'/questions/'.$b.'/move', ['direction' => 'down'])->assertNotFound();
    }

    public function test_an_archived_passage_refuses_new_children(): void
    {
        $passage = $this->passage($this->admin, ['status' => 'archived']);

        $this->actingAs($this->admin)->postJson('https://tenant-a.localhost/admin/assessments/questions', $this->questionPayload($passage, 'Câu mới'), self::AJAX)
            ->assertUnprocessable();
        $this->assertSame(0, DB::table('core_assessment_questions')->count());
        $this->actingAs($this->admin)->get(self::ADMIN.'/'.$passage)->assertOk()
            ->assertDontSee('questions/create?passage_id='.$passage, false);
    }

    public function test_teachers_reach_only_their_own_passages_and_their_children(): void
    {
        $other = $this->createUser($this->customerId, 'teacher');
        $mine = $this->passage($this->teacher, ['title' => 'Ngữ liệu của tôi']);
        $theirs = $this->passage($other, ['title' => 'Ngữ liệu người khác']);
        $theirChild = $this->child($other, $theirs, 'Câu người khác');

        $this->actingAs($this->teacher)->get(self::TEACHER)->assertOk()
            ->assertSeeText('Ngữ liệu của tôi')->assertDontSeeText('Ngữ liệu người khác');
        $this->actingAs($this->teacher)->get(self::TEACHER.'/'.$theirs)->assertNotFound();
        $this->actingAs($this->teacher)->get(self::TEACHER.'/'.$theirs.'/edit', self::AJAX)->assertNotFound();
        $this->actingAs($this->teacher)->putJson(self::TEACHER.'/'.$theirs, $this->payload(), self::AJAX)->assertNotFound();
        $this->actingAs($this->teacher)->deleteJson(self::TEACHER, ['ids' => [$theirs]], self::AJAX)->assertNotFound();
        $this->actingAs($this->teacher)->post(self::TEACHER.'/'.$theirs.'/questions/'.$theirChild.'/move', ['direction' => 'up'])->assertNotFound();
        // A teacher cannot hang a question on someone else's passage.
        $this->actingAs($this->teacher)->postJson('https://tenant-a.localhost/teacher/assessments/questions', $this->questionPayload($theirs, 'Chen vào'), self::AJAX)
            ->assertNotFound();

        // The admin added a child to the teacher's passage: the teacher owns the passage, so they can edit it.
        $adminChild = $this->child($this->admin, $mine, 'Câu admin thêm');
        $this->actingAs($this->teacher)->get('https://tenant-a.localhost/teacher/assessments/questions/'.$adminChild.'/edit', self::AJAX)
            ->assertOk()->assertSee('Câu admin thêm', false);
        $this->actingAs($this->teacher)->get(self::TEACHER.'/'.$mine)->assertOk()->assertSeeText('Câu admin thêm');

        $this->actingAs($this->admin)->get(self::ADMIN)->assertOk()
            ->assertSeeText('Ngữ liệu của tôi')->assertSeeText('Ngữ liệu người khác');
    }

    public function test_delete_is_blocked_by_a_quiz_and_otherwise_removes_children_and_media(): void
    {
        $used = $this->passage($this->admin, ['title' => 'Đang dùng']);
        $this->putInQuiz($this->child($this->admin, $used, 'Câu trong đề'));

        $free = $this->passage($this->admin, [], ['prompt_image_file' => UploadedFile::fake()->image('anh.png', 30, 20)]);
        $child = $this->child($this->admin, $free, 'Câu có âm thanh', ['prompt_audio_file' => UploadedFile::fake()->create('nghe.mp3', 32, 'audio/mpeg')]);
        $mediaIds = DB::table('core_assessment_question_media')->pluck('media_file_id')->all();
        $this->assertCount(2, $mediaIds);

        $this->actingAs($this->admin)->get(self::ADMIN)->assertOk()
            ->assertSee(e(trans_choice('lf.LF_assessment_passage_delete_blocked_quizzes', 1, ['count' => 1])), false);
        $this->actingAs($this->admin)->deleteJson(self::ADMIN, ['ids' => [$free, $used]], self::AJAX)->assertStatus(409);
        $this->assertSame(2, DB::table('core_assessment_passages')->count());

        $this->actingAs($this->admin)->deleteJson(self::ADMIN, ['ids' => [$free]], self::AJAX)->assertOk();
        $this->assertFalse(DB::table('core_assessment_passages')->where('id', $free)->exists());
        $this->assertFalse(DB::table('core_assessment_questions')->where('id', $child)->exists());
        $this->assertSame(0, DB::table('core_assessment_question_media')->count());
        $this->assertSame(['detached', 'detached'], DB::table('media_file_usages')->whereIn('media_file_id', $mediaIds)->pluck('status')->all());
    }

    public function test_passage_media_is_attached_replaced_and_named_in_the_media_library(): void
    {
        $id = $this->passage($this->admin, ['title' => 'Bài nghe có video'], [
            'prompt_audio_file' => UploadedFile::fake()->create('nghe.mp3', 32, 'audio/mpeg'),
            'prompt_video_file' => UploadedFile::fake()->create('clip.mp4', 64, 'video/mp4'),
        ]);

        $rows = DB::table('core_assessment_question_media')->where('passage_id', $id)->orderBy('media_role')->get();
        $this->assertSame(['prompt_audio', 'prompt_video'], $rows->pluck('media_role')->all());
        $this->assertNull($rows->first()->question_id);
        $this->assertSame(['assessment_passage', $id], [
            DB::table('media_file_usages')->value('owner_type'), (int) DB::table('media_file_usages')->value('owner_id'),
        ]);

        $this->actingAs($this->admin)->get(self::ADMIN.'/'.$id.'/edit', self::AJAX)->assertOk()
            ->assertSee('name="remove_prompt_video"', false);
        $this->actingAs($this->admin)->get('https://tenant-a.localhost/admin/media')->assertOk()
            ->assertSeeText('Bài nghe có video')->assertSeeText(__('lf.LF_media_usage_label_assessment_passage'))
            // The usage popup receives its link through @js, which escapes every slash.
            ->assertSee(str_replace('/', '\\\\\\/', self::ADMIN.'/'.$id.'\u0022'), false);

        $this->actingAs($this->admin)->post(self::ADMIN.'/'.$id, $this->payload(['title' => 'Bài nghe có video', '_method' => 'PUT', 'remove_prompt_video' => 1]), self::AJAX)->assertOk();
        $this->assertSame(['prompt_audio'], DB::table('core_assessment_question_media')->where('passage_id', $id)->pluck('media_role')->all());

        $this->actingAs($this->admin)->postJson(self::ADMIN, $this->payload([
            'prompt_video_file' => UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'),
        ]), self::AJAX)->assertUnprocessable()->assertJsonValidationErrors(['prompt_video_file']);
    }

    public function test_passage_media_can_be_picked_from_the_library(): void
    {
        $video = DB::table('media_files')->insertGetId([
            'customer_id' => $this->customerId, 'uploaded_by' => $this->admin->id, 'file_type' => 'video', 'mime_type' => 'video/mp4',
            'original_name' => 'bai-giang.mp4', 'display_name' => 'Bài giảng', 'extension' => 'mp4', 'storage_disk' => 'media_local',
            'storage_bucket' => 'lf-test-media', 'storage_region' => 'ap-southeast-1', 'storage_key' => 'tenants/'.$this->customerId.'/library/bai-giang.mp4',
            'checksum' => 'sha256:'.hash('sha256', 'bai-giang'), 'file_size_bytes' => 64, 'visibility' => 'private', 'status' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(self::ADMIN.'/create', self::AJAX)->assertOk()
            ->assertSee('name="prompt_video_library_id"', false);

        // A video picked for the audio role is refused and nothing is saved.
        $this->actingAs($this->admin)->post(self::ADMIN, $this->payload(['prompt_audio_library_id' => $video]), self::AJAX)
            ->assertUnprocessable()->assertJsonValidationErrors(['prompt_audio_file']);
        $this->assertSame(0, DB::table('core_assessment_passages')->count());

        $id = $this->passage($this->admin, ['prompt_video_library_id' => $video]);
        $this->assertSame($video, (int) DB::table('core_assessment_question_media')->where('passage_id', $id)->where('media_role', 'prompt_video')->value('media_file_id'));
        $this->assertSame('active', DB::table('media_file_usages')->where('media_file_id', $video)->where('owner_type', 'assessment_passage')->value('status'));
        $this->assertSame(1, DB::table('media_files')->count());
    }

    public function test_another_tenant_and_students_cannot_reach_passages(): void
    {
        $id = $this->passage($this->admin, ['title' => 'Bí mật']);
        $otherAdmin = $this->createUser($this->createTenant('tenant-b'), 'customer_admin');
        $base = 'https://tenant-b.localhost/admin/assessments/passages';

        $this->actingAs($otherAdmin)->get($base.'/'.$id)->assertNotFound();
        $this->actingAs($otherAdmin)->putJson($base.'/'.$id, $this->payload(), self::AJAX)->assertNotFound();
        $this->actingAs($otherAdmin)->deleteJson($base, ['ids' => [$id]], self::AJAX)->assertNotFound();
        $this->actingAs($otherAdmin)->get($base)->assertOk()->assertDontSeeText('Bí mật');

        $student = $this->createUser($this->customerId, 'student');
        $this->actingAs($student)->get(self::ADMIN)->assertForbidden();
        $this->actingAs($student)->postJson(self::TEACHER, $this->payload(), self::AJAX)->assertForbidden();
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return $overrides + ['title' => 'Ngữ liệu mẫu', 'status' => 'draft'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $files
     */
    private function passage(User $user, array $overrides = [], array $files = []): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $this->actingAs($user)->post($base, $files + $this->payload($overrides), self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_passages')->max('id');
    }

    /** @param  array<string, mixed>  $files */
    private function child(User $user, int $passageId, string $prompt, array $files = []): int
    {
        $base = $user->role === 'customer_admin' ? 'https://tenant-a.localhost/admin' : 'https://tenant-a.localhost/teacher';
        $this->actingAs($user)->post($base.'/assessments/questions', $files + $this->questionPayload($passageId, $prompt), self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_questions')->max('id');
    }

    /** @return array<string, mixed> */
    private function questionPayload(int $passageId, string $prompt): array
    {
        return [
            'passage_id' => $passageId,
            'question_type' => 'single_choice',
            'default_points' => '1',
            'status' => 'draft',
            'prompt' => $prompt,
            'options' => [['text' => 'Một', 'correct' => 1], ['text' => 'Hai', 'correct' => 0]],
        ];
    }

    /** @return list<array{int, int}> */
    private function order(int $passageId): array
    {
        return DB::table('core_assessment_questions')->where('passage_id', $passageId)->orderBy('passage_sort_order')
            ->get(['id', 'passage_sort_order'])->map(fn ($row) => [(int) $row->id, (int) $row->passage_sort_order])->all();
    }

    private function putInQuiz(int $questionId): void
    {
        $now = now();
        $quizId = DB::table('core_assessment_quizzes')->insertGetId([
            'customer_id' => $this->customerId, 'created_by' => $this->admin->id, 'title' => 'Đề 1',
            'assessment_type' => 'exam', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $sectionId = DB::table('core_assessment_quiz_sections')->insertGetId([
            'customer_id' => $this->customerId, 'quiz_id' => $quizId, 'title' => 'Phần 1', 'sort_order' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('core_assessment_quiz_questions')->insert([
            'customer_id' => $this->customerId, 'quiz_id' => $quizId, 'quiz_section_id' => $sectionId, 'question_id' => $questionId,
            'sort_order' => 1, 'points' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function category(string $name, string $status = 'active'): int
    {
        return DB::table('core_assessment_categories')->insertGetId([
            'customer_id' => $this->customerId, 'name' => $name, 'slug' => strtolower($name).'-'.uniqid(),
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createTenant(string $slug): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createUser(int $customerId, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => ucfirst(str_replace('_', ' ', $role)).' '.uniqid(),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
