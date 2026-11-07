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
 * Kiểm tra on the tenant website (ADR-0025 D2, D7, D8, D10, D12): the public list of open quizzes, self-registration,
 * "Đề thi của tôi", taking an attempt (autosave, timer, recordings, submit) and what the result page reveals.
 */
class AssessmentLearnerTest extends TestCase
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

    public function test_guests_see_only_open_quizzes_and_no_questions(): void
    {
        [$open] = $this->quiz(['title' => 'Đề mở đăng ký']);
        $this->quiz(['title' => 'Đề không mở'], open: false);
        $otherTenant = $this->createTenant('tenant-b');
        DB::table('core_assessment_quizzes')->insert([
            'customer_id' => $otherTenant, 'created_by' => $this->createUser($otherTenant, 'customer_admin')->id, 'title' => 'Đề của tenant khác', 'assessment_type' => 'exam', 'max_attempts' => 1,
            'show_result_mode' => 'immediately', 'status' => 'draft', 'registration_open' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->app['auth']->forgetGuards();
        $this->get(self::SITE.'/assessments')->assertOk()
            ->assertSeeText('Đề mở đăng ký')->assertDontSeeText('Đề không mở')->assertDontSeeText('Đề của tenant khác')
            ->assertSeeText(__('lf.LF_assessment_learner_fact_questions', ['count' => 6]))
            ->assertSeeText(__('lf.LF_assessment_learner_login_to_register'))
            ->assertDontSeeText('Chọn đáp án đúng')->assertDontSee('/register', false);
        $this->get(self::SITE.'/assessments?keyword=kh%C3%B4ng')->assertOk()->assertDontSeeText('Đề mở đăng ký');

        // Student pages need a student login.
        $this->get(self::SITE.'/assessments/my')->assertRedirect();
        $this->post(self::SITE.'/assessments/'.$open.'/register')->assertRedirect();
        $this->actingAs($this->admin)->get(self::SITE.'/assessments')->assertOk()->assertSeeText(__('lf.LF_assessment_learner_students_only'));
        $this->actingAs($this->admin)->post(self::SITE.'/assessments/'.$open.'/register')->assertForbidden();
    }

    public function test_students_register_once_and_find_the_quiz_in_their_list(): void
    {
        [$open] = $this->quiz(['title' => 'Đề mở đăng ký']);
        [$closed] = $this->quiz(['title' => 'Đề đóng'], open: false);

        $this->actingAs($this->student)->post(self::SITE.'/assessments/'.$open.'/register')
            ->assertRedirect(self::SITE.'/assessments/my')->assertSessionHas('success');
        $this->actingAs($this->student)->post(self::SITE.'/assessments/'.$open.'/register')->assertRedirect(self::SITE.'/assessments/my');
        $this->assertSame(1, DB::table('core_assessment_quiz_registrations')->where('quiz_id', $open)->count());
        $this->assertSame(['self_registered', null], [
            DB::table('core_assessment_quiz_registrations')->value('source'), DB::table('core_assessment_quiz_registrations')->value('granted_by'),
        ]);

        $this->actingAs($this->student)->from(self::SITE.'/assessments')->post(self::SITE.'/assessments/'.$closed.'/register')
            ->assertRedirect(self::SITE.'/assessments')->assertSessionHasErrors('quiz');

        $this->actingAs($this->student)->get(self::SITE.'/assessments')->assertOk()
            ->assertSeeText(__('lf.LF_assessment_learner_registered_badge'))->assertSeeText(__('lf.LF_assessment_learner_tab_mine'));
        $this->actingAs($this->student)->get(self::SITE.'/assessments/my')->assertOk()
            ->assertSeeText('Đề mở đăng ký')->assertSeeText(__('lf.LF_assessment_learner_start'))
            ->assertSeeText(__('lf.LF_assessment_learner_attempts_used', ['used' => 0, 'max' => 1]));
    }

    public function test_taking_an_attempt_hides_answers_saves_progress_and_submits(): void
    {
        [$quiz, $q] = $this->quiz(['title' => 'Đề làm thử', 'show_result_mode' => 'immediately']);
        $registration = $this->register($quiz);

        $start = $this->actingAs($this->student)->post(self::SITE.'/assessments/registrations/'.$registration.'/start');
        $attempt = (int) DB::table('core_assessment_attempts')->value('id');
        $start->assertRedirect(self::SITE.'/assessments/attempts/'.$attempt);
        $vq = DB::table('core_assessment_quiz_version_questions')->get()->keyBy('question_id');

        $page = $this->actingAs($this->student)->get(self::SITE.'/assessments/attempts/'.$attempt)->assertOk()
            ->assertSee('data-exam-attempt', false)->assertSeeText('Chọn đáp án đúng')->assertSeeText('Đúng')
            ->assertDontSeeText('Giải thích bí mật')->assertDontSeeText('Seoul / 서울')->assertDontSeeText('Đáp án mẫu bí mật');
        $page->assertSee('name="answers['.$vq[$q['single']]->id.'][option_keys][]"', false);

        // Autosave; answers of another student's attempt are out of reach.
        $save = self::SITE.'/assessments/attempts/'.$attempt.'/answers';
        $this->actingAs($this->student)->postJson($save, ['question' => $vq[$q['single']]->id, 'option_keys' => [2]], self::AJAX)
            ->assertOk()->assertJsonStructure(['saved_at']);
        $other = $this->createUser($this->customerId, 'student');
        $this->actingAs($other)->postJson($save, ['question' => $vq[$q['single']]->id, 'option_keys' => [1]], self::AJAX)->assertNotFound();
        $this->actingAs($other)->get(self::SITE.'/assessments/attempts/'.$attempt)->assertNotFound();

        // A recording for the speaking question.
        $this->actingAs($this->student)->post(self::SITE.'/assessments/attempts/'.$attempt.'/recordings/'.$vq[$q['speaking']]->id, [
            'recording' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ], self::AJAX)->assertOk();
        $file = DB::table('core_assessment_answer_files')->first();
        $this->assertSame('speaking_recording', $file->file_role);
        $this->assertTrue(DB::table('media_file_usages')->where('owner_type', 'assessment_answer')->where('owner_id', $file->answer_id)
            ->where('usage_type', 'recording')->exists());
        // Browsers record audio-only WebM, which file detection reports as video/webm; it replaces the first recording.
        $this->actingAs($this->student)->post(self::SITE.'/assessments/attempts/'.$attempt.'/recordings/'.$vq[$q['speaking']]->id, [
            'recording' => UploadedFile::fake()->create('recording.webm', 20, 'video/webm'),
        ], self::AJAX)->assertOk();
        $replaced = DB::table('core_assessment_answer_files')->sole();
        $this->assertNotSame((int) $file->media_file_id, (int) $replaced->media_file_id);
        $this->assertSame(['audio', 'deleted'], [
            DB::table('media_files')->where('id', $replaced->media_file_id)->value('file_type'),
            DB::table('media_files')->where('id', $file->media_file_id)->value('status'),
        ]);
        // In the Media library the recording names its student, quiz and question, and opens the attempt for grading.
        $this->actingAs($this->admin)->get(self::SITE.'/admin/media')->assertOk()
            // The usage popup receives a JSON string inside @js, so its unicode escapes are escaped once more.
            ->assertSee(str_replace('\\', '\\\\', trim(json_encode(__('lf.LF_media_usage_owner_assessment_answer', [
                'student' => 'Học viên Lan', 'quiz' => 'Đề làm thử', 'number' => $vq[$q['speaking']]->sort_order,
            ])), '"')), false)
            ->assertSee(str_replace('/', '\\\\\\/', self::ADMIN.'/results/'.$attempt.'\u0022'), false);
        $this->actingAs($this->student)->post(self::SITE.'/assessments/attempts/'.$attempt.'/recordings/'.$vq[$q['single']]->id, [
            'recording' => UploadedFile::fake()->create('recording.webm', 20, 'audio/webm'),
        ], self::AJAX)->assertNotFound();

        // Submit carries the form: the short answer typed but never autosaved still counts.
        $this->actingAs($this->student)->post(self::SITE.'/assessments/attempts/'.$attempt.'/submit', ['answers' => [
            $vq[$q['short']]->id => ['present' => '1', 'text' => ' seoul '],
            $vq[$q['multiple']]->id => ['present' => '1', 'option_keys' => ['1', '2']],
            $vq[$q['essay']]->id => ['present' => '1', 'text' => 'Bài viết'],
        ]])->assertRedirect(self::SITE.'/assessments/attempts/'.$attempt.'/result');
        $row = DB::table('core_assessment_attempts')->find($attempt);
        $this->assertSame(['submitted', 'pending', 'student'], [$row->status, $row->grading_status, $row->submit_reason]);
        // single 2 + multiple 1 + short 1 = 4 of 10 so far.
        $this->assertSame('4.00', number_format((float) $row->score, 2, '.', ''));

        // Immediately: score and answers, explanation, but never the reference answer.
        $this->actingAs($this->student)->get(self::SITE.'/assessments/attempts/'.$attempt.'/result')->assertOk()
            ->assertSeeText('4 / 10')->assertSeeText(__('lf.LF_assessment_learner_score_provisional'))
            ->assertSeeText('Giải thích bí mật')->assertSeeText('Seoul / 서울')->assertDontSeeText('Đáp án mẫu bí mật')
            ->assertSeeText(__('lf.LF_assessment_learner_review_help'));
        $this->actingAs($this->student)->get(self::SITE.'/assessments/attempts/'.$attempt)
            ->assertRedirect(self::SITE.'/assessments/attempts/'.$attempt.'/result');
        $this->actingAs($this->student)->postJson($save, ['question' => $vq[$q['single']]->id, 'option_keys' => [1]], self::AJAX)->assertStatus(409);
        $this->actingAs($this->student)->get(self::SITE.'/assessments/my')->assertOk()
            ->assertSeeText(__('lf.LF_assessment_registration_status_exhausted'))->assertSeeText(__('lf.LF_assessment_learner_register_again'));
    }

    public function test_result_modes_decide_what_the_student_sees(): void
    {
        [$quiz] = $this->quiz(['title' => 'Đề sau khi chấm', 'show_result_mode' => 'after_grading']);
        $attempt = $this->finishedAttempt($this->register($quiz));
        $result = self::SITE.'/assessments/attempts/'.$attempt.'/result';
        $this->actingAs($this->student)->get($result)->assertOk()
            ->assertSeeText(__('lf.LF_assessment_learner_result_after_grading'))->assertDontSeeText('Giải thích bí mật');

        foreach (DB::table('core_assessment_answers')->where('grading_status', 'pending')->pluck('id') as $answerId) {
            $this->actingAs($this->admin)->post(self::ADMIN.'/results/'.$attempt.'/answers/'.$answerId.'/grade', ['score' => '1', 'feedback' => 'Cố gắng thêm']);
        }
        $this->actingAs($this->student)->get($result)->assertOk()
            // single 2 + essay 1; the speaking answer was left blank, so the system gave it 0.
            ->assertSeeText('3 / 10')->assertSeeText('Giải thích bí mật')->assertSeeText('Cố gắng thêm')->assertSeeText(__('lf.LF_assessment_result_failed'));

        [$hidden] = $this->quiz(['title' => 'Đề ẩn điểm', 'show_result_mode' => 'hidden']);
        $hiddenAttempt = $this->finishedAttempt($this->register($hidden));
        $this->actingAs($this->student)->get(self::SITE.'/assessments/attempts/'.$hiddenAttempt.'/result')->assertOk()
            ->assertSeeText(__('lf.LF_assessment_learner_result_hidden'))->assertDontSeeText('Giải thích bí mật');
    }

    public function test_an_overdue_attempt_is_closed_as_timed_out(): void
    {
        [$quiz, $q] = $this->quiz(['title' => 'Đề 10 phút', 'duration_minutes' => '10', 'max_attempts' => '2']);
        $registration = $this->register($quiz);
        $this->actingAs($this->student)->post(self::SITE.'/assessments/registrations/'.$registration.'/start');
        $attempt = (int) DB::table('core_assessment_attempts')->value('id');
        $vq = DB::table('core_assessment_quiz_version_questions')->get()->keyBy('question_id');
        $this->actingAs($this->student)->get(self::SITE.'/assessments/attempts/'.$attempt)->assertOk()->assertSee('data-remaining="600"', false);

        $this->travel(11)->minutes();
        $this->actingAs($this->student)->postJson(self::SITE.'/assessments/attempts/'.$attempt.'/answers',
            ['question' => $vq[$q['single']]->id, 'option_keys' => [2]], self::AJAX)->assertStatus(409);
        // Essay and speaking were left blank, so the system graded them 0 and nothing waits for a teacher.
        $this->assertSame(['graded', 'timeout'], [
            DB::table('core_assessment_attempts')->value('status'), DB::table('core_assessment_attempts')->value('submit_reason'),
        ]);
        $this->actingAs($this->student)->get(self::SITE.'/assessments/my')->assertOk()->assertSeeText(__('lf.LF_assessment_learner_retry'));
    }

    // ---- fixtures ----

    /** @return array{0: int, 1: array<string, int>} a published quiz with one question of each type (10 points) */
    private function quiz(array $settings, bool $open = true): array
    {
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/quizzes', $settings + [
            'assessment_type' => 'exam', 'max_attempts' => '1', 'pass_score_percentage' => '60', 'show_result_mode' => 'immediately',
        ], self::AJAX)->assertCreated();
        $quiz = (int) DB::table('core_assessment_quizzes')->max('id');
        $make = function (array $payload): int {
            $this->actingAs($this->admin)->postJson(self::ADMIN.'/questions', $payload + ['status' => 'published', 'default_points' => '1'], self::AJAX)->assertCreated();

            return (int) DB::table('core_assessment_questions')->max('id');
        };
        $q = [
            'single' => $make(['question_type' => 'single_choice', 'default_points' => '2', 'prompt' => 'Chọn đáp án đúng',
                'explanation' => 'Giải thích bí mật', 'options' => [['text' => 'Sai', 'correct' => 0], ['text' => 'Đúng', 'correct' => 1]]]),
            'multiple' => $make(['question_type' => 'multiple_choice', 'prompt' => 'Chọn các đáp án đúng',
                'options' => [['text' => 'A', 'correct' => 1], ['text' => 'B', 'correct' => 1], ['text' => 'C', 'correct' => 0]]]),
            'true_false' => $make(['question_type' => 'true_false', 'prompt' => 'Seoul là thủ đô', 'true_false_answer' => 'true']),
            'short' => $make(['question_type' => 'short_answer', 'prompt' => 'Thủ đô Hàn Quốc', 'accepted_answers' => ['Seoul', '서울']]),
            'essay' => $make(['question_type' => 'essay', 'default_points' => '4', 'prompt' => 'Viết đoạn văn', 'reference_answer' => 'Đáp án mẫu bí mật']),
            'speaking' => $make(['question_type' => 'speaking', 'prompt' => 'Giới thiệu bản thân']),
        ];
        $section = (int) DB::table('core_assessment_quiz_sections')->where('quiz_id', $quiz)->value('id');
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/quizzes/'.$quiz.'/questions', ['quiz_section_id' => $section, 'question_ids' => array_values($q)], self::AJAX)->assertCreated();
        $this->actingAs($this->admin)->post(self::ADMIN.'/quizzes/'.$quiz.'/publish')->assertSessionHasNoErrors();
        if ($open) {
            $this->actingAs($this->admin)->post(self::ADMIN.'/quizzes/'.$quiz.'/registration', ['open' => '1'])->assertSessionHasNoErrors();
        }

        return [$quiz, $q];
    }

    private function register(int $quizId): int
    {
        $this->actingAs($this->student)->post(self::SITE.'/assessments/'.$quizId.'/register')->assertSessionHasNoErrors();

        return (int) DB::table('core_assessment_quiz_registrations')->where('quiz_id', $quizId)->where('status', 'active')->value('id');
    }

    /** Starts and submits an attempt with the single-choice question answered right (2 points). */
    private function finishedAttempt(int $registrationId): int
    {
        $this->actingAs($this->student)->post(self::SITE.'/assessments/registrations/'.$registrationId.'/start');
        $attempt = (int) DB::table('core_assessment_attempts')->where('quiz_registration_id', $registrationId)->value('id');
        $single = DB::table('core_assessment_quiz_version_questions as vq')
            ->join('core_assessment_attempts as a', 'a.quiz_version_id', '=', 'vq.quiz_version_id')
            ->where('a.id', $attempt)->where('vq.question_type', 'single_choice')->value('vq.id');
        $essay = DB::table('core_assessment_quiz_version_questions as vq')
            ->join('core_assessment_attempts as a', 'a.quiz_version_id', '=', 'vq.quiz_version_id')
            ->where('a.id', $attempt)->where('vq.question_type', 'essay')->value('vq.id');
        $this->actingAs($this->student)->post(self::SITE.'/assessments/attempts/'.$attempt.'/submit', ['answers' => [
            $single => ['present' => '1', 'option_keys' => ['2']],
            $essay => ['present' => '1', 'text' => 'Bài viết'],
        ]]);

        return $attempt;
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
