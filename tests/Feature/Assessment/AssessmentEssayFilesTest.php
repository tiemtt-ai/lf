<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bài Viết nộp bằng tệp (ADR-0027 D5): the "Cho nộp tệp" setting of essay questions, frozen at publish; the student
 * uploads up to three images / PDF / DOCX while the attempt is open and may remove them; an essay with files only is
 * not blank; graders and the student see the files on their pages.
 */
class AssessmentEssayFilesTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://tenant-a.localhost';

    private const ADMIN = 'https://tenant-a.localhost/admin/assessments';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private int $customerId;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://localhost', 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https', 'media.disk' => 'media_local']);
        Storage::fake('media_local');

        $this->customerId = $this->createTenant('tenant-a');
        $this->admin = $this->createUser($this->customerId, 'customer_admin');
        $this->student = $this->createUser($this->customerId, 'student', 'Học viên Lan');
    }

    public function test_the_setting_belongs_to_essays_and_is_frozen_at_publish(): void
    {
        $essay = $this->question(['question_type' => 'essay', 'prompt' => 'Viết đoạn văn', 'allow_file_answer' => '1']);
        $this->assertTrue((bool) DB::table('core_assessment_questions')->where('id', $essay)->value('allow_file_answer'));
        $this->actingAs($this->admin)->get(self::ADMIN.'/questions/'.$essay.'/edit', self::AJAX)->assertOk()
            ->assertSee('name="allow_file_answer"', false)->assertSeeText(__('lf.LF_assessment_question_allow_file_answer'));

        // Another type never keeps it.
        $speaking = $this->question(['question_type' => 'speaking', 'prompt' => 'Nói', 'allow_file_answer' => '1']);
        $this->assertFalse((bool) DB::table('core_assessment_questions')->where('id', $speaking)->value('allow_file_answer'));
        $this->actingAs($this->admin)->putJson(self::ADMIN.'/questions/'.$essay, [
            'question_type' => 'short_answer', 'prompt' => 'Thủ đô', 'accepted_answers' => ['Seoul'], 'status' => 'published',
            'default_points' => '1', 'allow_file_answer' => '1',
        ], self::AJAX)->assertOk();
        $this->assertFalse((bool) DB::table('core_assessment_questions')->where('id', $essay)->value('allow_file_answer'));

        // The published version keeps what it was published with.
        [, $vq, $q] = $this->quiz();
        $this->assertTrue(json_decode((string) $vq[$q['files']]->question_snapshot, true)['allow_file_answer']);
        $this->assertFalse(json_decode((string) $vq[$q['plain']]->question_snapshot, true)['allow_file_answer']);
        DB::table('core_assessment_questions')->where('id', $q['files'])->update(['allow_file_answer' => false]);
        $this->assertTrue(json_decode((string) DB::table('core_assessment_quiz_version_questions')->where('id', $vq[$q['files']]->id)
            ->value('question_snapshot'), true)['allow_file_answer']);
    }

    public function test_students_upload_up_to_three_files_and_remove_them_while_the_attempt_is_open(): void
    {
        [$quiz, $vq, $q] = $this->quiz();
        $attempt = $this->start($quiz);
        $files = self::SITE.'/assessments/attempts/'.$attempt.'/files/'.$vq[$q['files']]->id;

        $this->actingAs($this->student)->get(self::SITE.'/assessments/attempts/'.$attempt)->assertOk()
            ->assertSee('data-files-url', false)->assertSeeText(__('lf.LF_assessment_learner_files_title'));
        // Only the essay that allows files takes them.
        $this->actingAs($this->student)->post(self::SITE.'/assessments/attempts/'.$attempt.'/files/'.$vq[$q['plain']]->id,
            ['file' => UploadedFile::fake()->image('bai.jpg')], self::AJAX)->assertNotFound();

        $this->actingAs($this->student)->post($files, ['file' => UploadedFile::fake()->image('trang-1.jpg')], self::AJAX)
            ->assertOk()->assertJsonCount(1, 'files')->assertJsonPath('files.0.name', 'trang-1.jpg')->assertJsonPath('files.0.kind', 'image');
        $this->actingAs($this->student)->post($files, ['file' => UploadedFile::fake()->create('bai.pdf', 30, 'application/pdf')], self::AJAX)
            ->assertOk()->assertJsonPath('files.1.kind', 'pdf');
        $this->actingAs($this->student)->post($files, ['file' => UploadedFile::fake()->create('bai.docx', 30,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document')], self::AJAX)
            ->assertOk()->assertJsonCount(3, 'files')->assertJsonPath('files.2.kind', 'docx');
        $this->actingAs($this->student)->post($files, ['file' => UploadedFile::fake()->image('trang-2.png')], self::AJAX)
            ->assertUnprocessable()->assertJsonPath('errors.file.0', __('lf.LF_assessment_learner_files_full', ['max' => 3]));

        $rows = DB::table('core_assessment_answer_files')->orderBy('id')->get();
        $this->assertSame(['essay_attachment'], $rows->pluck('file_role')->unique()->values()->all());
        $this->assertSame(3, DB::table('media_file_usages')->where('owner_type', 'assessment_answer')->where('usage_type', 'attachment')
            ->where('status', 'active')->count());

        // The page asks for the list again while a file waits for its scan: no link until it is ready.
        $scanning = (int) $rows->first()->media_file_id;
        DB::table('media_files')->where('id', $scanning)->update(['status' => 'processing']);
        $this->actingAs($this->student)->getJson($files, self::AJAX)->assertOk()->assertJsonCount(3, 'files')
            ->assertJsonPath('files.0.id', $scanning)->assertJsonPath('files.0.url', null);
        DB::table('media_files')->where('id', $scanning)->update(['status' => 'ready']);
        $this->assertNotNull($this->actingAs($this->student)->getJson($files, self::AJAX)->assertOk()->json('files.0.url'));
        $this->actingAs($this->student)->getJson(self::SITE.'/assessments/attempts/'.$attempt.'/files/'.$vq[$q['plain']]->id, self::AJAX)
            ->assertNotFound();

        // Removing frees a place and deletes the file nothing else uses.
        $first = (int) $rows->first()->media_file_id;
        $this->actingAs($this->student)->deleteJson($files.'/'.$first, [], self::AJAX)->assertOk()->assertJsonCount(2, 'files');
        $this->assertSame('deleted', DB::table('media_files')->where('id', $first)->value('status'));
        $this->actingAs($this->student)->post($files, ['file' => UploadedFile::fake()->image('trang-2.png')], self::AJAX)->assertOk()->assertJsonCount(3, 'files');

        // Wrong kinds and other students are refused.
        $this->actingAs($this->student)->deleteJson($files.'/'.$rows->last()->media_file_id, [], self::AJAX)->assertOk();
        $this->actingAs($this->student)->post($files, ['file' => UploadedFile::fake()->create('bang.xlsx', 10,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')], self::AJAX)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->actingAs($this->student)->post($files, ['file' => UploadedFile::fake()->create('am-thanh.mp3', 10, 'audio/mpeg')], self::AJAX)
            ->assertUnprocessable();
        $other = $this->createUser($this->customerId, 'student');
        $this->actingAs($other)->post($files, ['file' => UploadedFile::fake()->image('x.jpg')], self::AJAX)->assertNotFound();
        $this->actingAs($other)->deleteJson($files.'/'.$rows->get(1)->media_file_id, [], self::AJAX)->assertNotFound();
        $this->actingAs($other)->getJson($files, self::AJAX)->assertNotFound();

        // Files only: the essay is answered, not blank, and waits for a grader.
        $this->actingAs($this->student)->post(self::SITE.'/assessments/attempts/'.$attempt.'/submit', ['answers' => [
            $vq[$q['files']]->id => ['present' => '1', 'text' => ''],
        ]])->assertRedirect();
        $answer = DB::table('core_assessment_answers')->where('quiz_version_question_id', $vq[$q['files']]->id)->first();
        $this->assertSame('pending', $answer->grading_status);
        $this->assertSame(['submitted', 'pending'], [
            DB::table('core_assessment_attempts')->value('status'), DB::table('core_assessment_attempts')->value('grading_status'),
        ]);
        // The plain essay left blank is still graded 0 by the system.
        $this->assertSame('graded', DB::table('core_assessment_answers')->where('quiz_version_question_id', $vq[$q['plain']]->id)->value('grading_status'));

        // Closed: nothing more goes in or out.
        $this->actingAs($this->student)->post($files, ['file' => UploadedFile::fake()->image('muon.jpg')], self::AJAX)->assertStatus(409);
        $this->actingAs($this->student)->deleteJson($files.'/'.$rows->get(1)->media_file_id, [], self::AJAX)->assertStatus(409);
        $this->actingAs($this->student)->getJson($files, self::AJAX)->assertStatus(409);
        $this->assertSame(2, DB::table('core_assessment_answer_files')->where('answer_id', $answer->id)->count());

        // Grader and student both see the files.
        $this->actingAs($this->admin)->get(self::ADMIN.'/results/'.$attempt)->assertOk()
            ->assertSeeText(__('lf.LF_assessment_result_files'))->assertSeeText('bai.pdf')->assertSeeText('trang-2.png')
            // Essays only: no choices to colour, so no colour key.
            ->assertDontSeeText(__('lf.LF_assessment_result_answers_help'));
        $this->actingAs($this->student)->get(self::SITE.'/assessments/attempts/'.$attempt.'/result')->assertOk()
            ->assertSeeText('bai.pdf')->assertSeeText('trang-2.png')->assertDontSeeText('trang-1.jpg')
            ->assertDontSeeText(__('lf.LF_assessment_learner_review_help'));
    }

    public function test_import_reads_the_column_in_either_language(): void
    {
        $header = 'Dạng câu,Đề bài,A,B,C,D,E,F,Đáp án,Điểm,Kỹ năng,Độ khó,Giải thích,Đáp án mẫu,Cho nộp tệp';
        $csv = fn (string ...$rows) => UploadedFile::fake()->createWithContent('cau-hoi.csv', implode("\n", [$header, ...$rows]));
        $import = fn (UploadedFile $file) => $this->actingAs($this->admin)
            ->post(self::ADMIN.'/questions/import', ['file' => $file, 'status' => 'draft'], self::AJAX);

        $import($csv('Tự luận,Chụp bài,,,,,,,,1,,,,,Có', 'Essay,Typed,,,,,,,,1,,,,,no', 'Speaking,Đọc to,,,,,,,,1,,,,,Có'))->assertCreated();
        $this->assertSame([1, 0, 0], DB::table('core_assessment_questions')->orderBy('id')->pluck('allow_file_answer')
            ->map(fn ($value) => (int) $value)->all());

        $import($csv('Tự luận,Chụp bài,,,,,,,,1,,,,,Có lẽ'))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(3, DB::table('core_assessment_questions')->count());
    }

    // ---- fixtures ----

    private function question(array $payload): int
    {
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/questions', $payload + ['status' => 'published', 'default_points' => '2'], self::AJAX)
            ->assertCreated();

        return (int) DB::table('core_assessment_questions')->max('id');
    }

    /** @return array{0: int, 1: Collection<int, object>, 2: array{files: int, plain: int}} */
    private function quiz(): array
    {
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/quizzes', [
            'title' => 'Đề viết', 'assessment_type' => 'exam', 'max_attempts' => '1', 'pass_score_percentage' => '60', 'show_result_mode' => 'immediately',
        ], self::AJAX)->assertCreated();
        $quiz = (int) DB::table('core_assessment_quizzes')->max('id');
        $q = [
            'files' => $this->question(['question_type' => 'essay', 'prompt' => 'Viết tay rồi chụp', 'allow_file_answer' => '1']),
            'plain' => $this->question(['question_type' => 'essay', 'prompt' => 'Gõ bài viết']),
        ];
        $section = (int) DB::table('core_assessment_quiz_sections')->where('quiz_id', $quiz)->value('id');
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/quizzes/'.$quiz.'/questions', ['quiz_section_id' => $section, 'question_ids' => array_values($q)], self::AJAX)->assertCreated();
        $this->actingAs($this->admin)->post(self::ADMIN.'/quizzes/'.$quiz.'/publish')->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(self::ADMIN.'/quizzes/'.$quiz.'/registration', ['open' => '1'])->assertSessionHasNoErrors();

        return [$quiz, DB::table('core_assessment_quiz_version_questions')->get()->keyBy('question_id'), $q];
    }

    private function start(int $quiz): int
    {
        $this->actingAs($this->student)->post(self::SITE.'/assessments/'.$quiz.'/register')->assertSessionHasNoErrors();
        $registration = (int) DB::table('core_assessment_quiz_registrations')->where('quiz_id', $quiz)->value('id');
        $this->actingAs($this->student)->post(self::SITE.'/assessments/registrations/'.$registration.'/start');

        return (int) DB::table('core_assessment_attempts')->value('id');
    }

    private function createTenant(string $slug): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createUser(int $customerId, string $role, ?string $name = null): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => $name ?? ucfirst(str_replace('_', ' ', $role)).' '.uniqid(),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
