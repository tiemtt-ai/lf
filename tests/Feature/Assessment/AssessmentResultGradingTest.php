<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Services\Assessment\AssessmentAttemptService;
use App\Support\SimpleXlsx;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Attempts and Kết quả & Chấm bài (ADR-0025 D8, D10–D12): attempts counted per registration, automatic grading from the
 * version snapshot on submit, the results list with "Chờ chấm", manual grading of essay / speaking with history.
 */
class AssessmentResultGradingTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'https://tenant-a.localhost/admin/assessments';

    private const TEACHER = 'https://tenant-a.localhost/teacher/assessments';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private int $customerId;

    private User $admin;

    private User $teacher;

    private User $student;

    private AssessmentAttemptService $attempts;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://localhost', 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);

        $this->customerId = $this->createTenant('tenant-a');
        $this->admin = $this->createUser($this->customerId, 'customer_admin');
        $this->teacher = $this->createUser($this->customerId, 'teacher');
        $this->student = $this->createUser($this->customerId, 'student', 'Học viên Lan');
        $this->attempts = app(AssessmentAttemptService::class);
    }

    public function test_submit_grades_objective_answers_from_the_snapshot_and_waits_for_manual_ones(): void
    {
        [$quiz, $q] = $this->quizWithAllTypes($this->admin, 2);
        $registration = $this->grant($quiz, $this->admin);
        $attempt = $this->startAttempt($registration);
        $vq = $this->versionQuestions($attempt);

        // Right: single, short answer (case and spaces ignored); wrong: multiple (subset); true/false left empty.
        $this->attempts->saveAnswer($this->customerId, $attempt->id, $vq[$q['single']]->id, ['option_keys' => [2, 99]]);
        $this->attempts->saveAnswer($this->customerId, $attempt->id, $vq[$q['multiple']]->id, ['option_keys' => [1]]);
        $this->attempts->saveAnswer($this->customerId, $attempt->id, $vq[$q['short']]->id, ['text' => '  SEOUL  ']);
        $this->attempts->saveAnswer($this->customerId, $attempt->id, $vq[$q['essay']]->id, ['text' => 'Bài viết của em']);
        $submitted = $this->attempts->submit($this->customerId, $attempt->id);

        $this->assertSame(['submitted', 'pending', 'student'], [$submitted->status, $submitted->grading_status, $submitted->submit_reason]);
        $answers = DB::table('core_assessment_answers')->where('attempt_id', $attempt->id)->get()->keyBy('question_id');
        $this->assertCount(6, $answers);
        $state = fn (int $question) => [(int) $answers[$question]->is_correct, (float) $answers[$question]->score, $answers[$question]->grading_status];
        $this->assertSame([1, 2.0, 'not_required'], $state($q['single']));
        $this->assertSame('[2]', $answers[$q['single']]->selected_option_keys);
        $this->assertSame([0, 0.0, 'not_required'], $state($q['multiple']));
        $this->assertSame([0, 0.0, 'not_required'], $state($q['true_false']));
        $this->assertSame([1, 1.0, 'not_required'], $state($q['short']));
        $this->assertSame(['pending', null], [$answers[$q['essay']]->grading_status, $answers[$q['essay']]->score]);
        // No recording: the system grades the speaking answer 0 at once (ADR-0025 D11); the essay still waits.
        $this->assertSame(['graded', 'audio', 0.0], [$answers[$q['speaking']]->grading_status, $answers[$q['speaking']]->answer_type, (float) $answers[$q['speaking']]->score]);
        $this->assertSame([['system_auto', null, 0.0, 1]], DB::table('core_assessment_gradings')->where('attempt_id', $attempt->id)->get()
            ->map(fn ($g) => [$g->grading_type, $g->grader_user_id, (float) $g->score, (int) $g->is_current])->all());
        $this->assertSame(['3.00', '30.00', null], [
            number_format((float) $submitted->score, 2, '.', ''), number_format((float) $submitted->score_percentage, 2, '.', ''), $submitted->passed,
        ]);

        // Second attempt allowed (max 2), then the registration is exhausted.
        $second = $this->startAttempt($registration);
        $this->assertSame(2, (int) $second->attempt_no);
        $this->attempts->submit($this->customerId, $second->id);
        $this->assertSame(['exhausted', 2], [
            DB::table('core_assessment_quiz_registrations')->where('id', $registration)->value('status'),
            (int) DB::table('core_assessment_quiz_registrations')->where('id', $registration)->value('attempts_used'),
        ]);
        $this->expectException(ValidationException::class);
        $this->attempts->saveAnswer($this->customerId, $second->id, $vq[$q['single']]->id, ['option_keys' => [1]]);
    }

    public function test_results_list_shows_pending_attempts_and_grading_completes_the_attempt(): void
    {
        [$quiz, $q] = $this->quizWithAllTypes($this->admin);
        $attempt = $this->startAttempt($this->grant($quiz, $this->admin));
        $vq = $this->versionQuestions($attempt);
        $this->attempts->saveAnswer($this->customerId, $attempt->id, $vq[$q['single']]->id, ['option_keys' => [2]]);
        $this->attempts->saveAnswer($this->customerId, $attempt->id, $vq[$q['essay']]->id, ['text' => 'Bài viết của em']);
        $this->attempts->submit($this->customerId, $attempt->id);
        $answer = fn (int $question) => DB::table('core_assessment_answers')->where('attempt_id', $attempt->id)->where('question_id', $question)->first();

        $this->actingAs($this->admin)->get(self::ADMIN.'/results?tab=pending')->assertOk()
            ->assertSee('data-assessment-section="results"', false)
            ->assertSeeText('Học viên Lan')->assertSeeText(__('lf.LF_assessment_result_grading_pending'));
        $page = self::ADMIN.'/results/'.$attempt->id;
        $this->actingAs($this->admin)->get($page)->assertOk()
            ->assertSeeText('Bài viết của em')->assertSeeText('Chọn đáp án đúng')
            ->assertSeeText(__('lf.LF_assessment_result_accepted', ['answers' => 'Seoul / 서울']))
            ->assertSee('results/'.$attempt->id.'/answers/'.$answer($q['essay'])->id.'/grade', false);

        // Over the max, and an auto-graded answer, are refused.
        $this->actingAs($this->admin)->from($page)->post($page.'/answers/'.$answer($q['essay'])->id.'/grade', ['score' => '99'])->assertSessionHasErrors('score');
        $this->actingAs($this->admin)->from($page)->post($page.'/answers/'.$answer($q['single'])->id.'/grade', ['score' => '1'])->assertSessionHasErrors('score');

        $this->actingAs($this->admin)->post($page.'/answers/'.$answer($q['essay'])->id.'/grade', ['score' => '3.5', 'feedback' => 'Tốt'])
            ->assertRedirect($page.'#answer-'.$answer($q['essay'])->id);
        // The blank speaking answer was graded 0 by the system, so grading the essay completes the attempt.
        $this->assertSame('graded', DB::table('core_assessment_attempts')->value('status'));
        $this->actingAs($this->admin)->get($page)->assertOk()->assertSeeText(__('lf.LF_assessment_result_graded_blank'));
        // ...and a grader may still regrade it.
        $this->actingAs($this->admin)->post($page.'/answers/'.$answer($q['speaking'])->id.'/grade', ['score' => '1'])->assertSessionHasNoErrors();

        $row = DB::table('core_assessment_attempts')->find($attempt->id);
        // single 2 + essay 3.5 + speaking 1 = 6.5 of 10; pass mark 60%.
        $this->assertSame(['graded', 'completed', '6.50', '65.00', 1], [$row->status, $row->grading_status,
            number_format((float) $row->score, 2, '.', ''), number_format((float) $row->score_percentage, 2, '.', ''), (int) $row->passed]);
        $this->assertNotNull($row->graded_at);

        // Regrading keeps history and recalculates.
        $this->actingAs($this->admin)->post($page.'/answers/'.$answer($q['essay'])->id.'/grade', ['score' => '1']);
        $this->assertSame([[0, '3.50', 'Tốt'], [1, '1.00', null]], DB::table('core_assessment_gradings')->where('answer_id', $answer($q['essay'])->id)
            ->orderBy('id')->get()->map(fn ($g) => [(int) $g->is_current, number_format((float) $g->score, 2, '.', ''), $g->feedback])->all());
        $row = DB::table('core_assessment_attempts')->find($attempt->id);
        $this->assertSame(['4.00', 0], [number_format((float) $row->score, 2, '.', ''), (int) $row->passed]);
        $this->actingAs($this->admin)->get(self::ADMIN.'/results?tab=pending')->assertOk()->assertSeeText(__('lf.LF_assessment_result_pending_empty'));
        $this->actingAs($this->admin)->get($page)->assertOk()->assertSeeText(__('lf.LF_assessment_result_history', ['count' => 2]));
    }

    public function test_teachers_grade_attempts_of_their_quizzes_or_registrations_they_granted(): void
    {
        $other = $this->createUser($this->customerId, 'teacher');
        [$theirQuiz, $q] = $this->quizWithAllTypes($other);
        $foreignAttempt = $this->submittedAttempt($this->grant($theirQuiz, $other));
        [$myQuiz] = $this->quizWithAllTypes($this->teacher);
        $mine = $this->submittedAttempt($this->grant($myQuiz, $this->teacher));
        $essay = fn (object $attempt) => (int) DB::table('core_assessment_answers')->where('attempt_id', $attempt->id)
            ->where('grading_status', 'pending')->orderBy('id')->value('id');

        $this->actingAs($this->teacher)->get(self::TEACHER.'/results')->assertOk()->assertSee('results/'.$mine->id, false)
            ->assertDontSee('results/'.$foreignAttempt->id.'"', false);
        $this->actingAs($this->teacher)->get(self::TEACHER.'/results/'.$foreignAttempt->id)->assertNotFound();
        $this->actingAs($this->teacher)->post(self::TEACHER.'/results/'.$foreignAttempt->id.'/answers/'.$essay($foreignAttempt).'/grade', ['score' => '1'])->assertNotFound();
        $this->actingAs($this->teacher)->post(self::TEACHER.'/results/'.$mine->id.'/answers/'.$essay($mine).'/grade', ['score' => '1'])->assertSessionHasNoErrors();

        // An admin grants the other teacher's quiz to a student; that grant does not open it to this teacher, but the
        // teacher who granted a registration may grade it.
        $student2 = $this->createUser($this->customerId, 'student');
        DB::table('core_assessment_quiz_registrations')->insert([
            'customer_id' => $this->customerId, 'quiz_id' => $theirQuiz, 'student_id' => $student2->id, 'source' => 'granted',
            'granted_by' => $this->teacher->id, 'status' => 'active', 'attempts_used' => 0, 'registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $granted = $this->submittedAttempt((int) DB::table('core_assessment_quiz_registrations')->max('id'));
        $this->actingAs($this->teacher)->get(self::TEACHER.'/results/'.$granted->id)->assertOk();

        $this->actingAs($this->admin)->get(self::ADMIN.'/results')->assertOk()
            ->assertSee('results/'.$mine->id, false)->assertSee('results/'.$foreignAttempt->id, false);
    }

    public function test_another_tenant_and_students_cannot_reach_results(): void
    {
        [$quiz] = $this->quizWithAllTypes($this->admin);
        $attempt = $this->submittedAttempt($this->grant($quiz, $this->admin));
        $otherAdmin = $this->createUser($this->createTenant('tenant-b'), 'customer_admin');

        $this->actingAs($otherAdmin)->get('https://tenant-b.localhost/admin/assessments/results/'.$attempt->id)->assertNotFound();
        $this->actingAs($otherAdmin)->get('https://tenant-b.localhost/admin/assessments/results')->assertOk()->assertDontSeeText('Học viên Lan');
        $this->actingAs($this->student)->get(self::ADMIN.'/results')->assertForbidden();
        $this->actingAs($this->student)->get(self::TEACHER.'/results/'.$attempt->id)->assertForbidden();
    }

    public function test_export_follows_the_list_scope_tab_and_filters(): void
    {
        $other = $this->createUser($this->customerId, 'teacher');
        [$theirQuiz] = $this->quizWithAllTypes($other);
        $this->submittedAttempt($this->grant($theirQuiz, $other));
        [$myQuiz] = $this->quizWithAllTypes($this->teacher);
        $mine = $this->submittedAttempt($this->grant($myQuiz, $this->teacher));
        $myTitle = DB::table('core_assessment_quizzes')->where('id', $myQuiz)->value('title');
        $read = function (User $user, string $url): array {
            $response = $this->actingAs($user)->get($url)->assertOk();
            $this->assertStringContainsString('attachment; filename="ket-qua-kiem-tra-', $response->headers->get('Content-Disposition'));
            $path = tempnam(sys_get_temp_dir(), 'exp');
            file_put_contents($path, $response->getContent());

            return SimpleXlsx::read($path);
        };

        $this->actingAs($this->teacher)->get(self::TEACHER.'/results?tab=pending')->assertOk()
            ->assertSee('href="'.self::TEACHER.'/results/export?tab=pending"', false);

        // A teacher gets only the attempts the list shows them.
        $teacherRows = $read($this->teacher, self::TEACHER.'/results/export');
        $this->assertSame(__('lf.LF_export_col_attempt_no'), $teacherRows[0][5]);
        $this->assertCount(2, $teacherRows);
        $this->assertSame(['1', 'Học viên Lan', $this->student->email, $myTitle, '1', '1'], array_slice($teacherRows[1], 0, 6));
        $this->assertSame([__('lf.LF_assessment_result_score'), __('lf.LF_export_col_max_score')], [$teacherRows[0][8], $teacherRows[0][9]]);
        $this->assertSame([number_format((float) $mine->max_score, 0), __('lf.LF_assessment_result_grading_pending')], [$teacherRows[1][9], $teacherRows[1][12]]);

        $this->assertCount(3, $read($this->admin, self::ADMIN.'/results/export'));
        $this->assertCount(3, $read($this->admin, self::ADMIN.'/results/export?tab=pending'));
        $this->assertSame([$myTitle], array_column(array_slice($read($this->admin, self::ADMIN.'/results/export?quiz_id='.$myQuiz), 1), 3));
        $this->assertCount(1, $read($this->admin, self::ADMIN.'/results/export?status=in_progress'));

        $otherAdmin = $this->createUser($this->createTenant('tenant-b'), 'customer_admin');
        $this->assertCount(1, $read($otherAdmin, 'https://tenant-b.localhost/admin/assessments/results/export'));
        $this->actingAs($this->student)->get(self::TEACHER.'/results/export')->assertForbidden();
    }

    public function test_results_page_marks_pending_grading_and_the_verdict(): void
    {
        [$quiz] = $this->quizWithAllTypes($this->admin, 2);
        $registration = $this->grant($quiz, $this->admin);
        $this->submittedAttempt($registration);
        $newer = $this->submittedAttempt($registration);
        DB::table('core_assessment_attempts')->where('id', $newer->id)->update(['grading_status' => 'completed', 'status' => 'graded', 'passed' => false]);

        $page = $this->actingAs($this->admin)->get(self::ADMIN.'/results')->assertOk();
        $page->assertDontSee('ui-banner', false)
            ->assertSee('aria-label="'.__('lf.LF_assessment_result_grade').': Học viên Lan"', false)
            ->assertSee('aria-label="'.__('lf.LF_assessment_result_view').': Học viên Lan"', false)
            ->assertSee('ui-status--danger">'.__('lf.LF_assessment_result_failed'), false)
            ->assertSeeText(__('lf.LF_assessment_result_provisional_short'));
        $this->assertMatchesRegularExpression('/ui-result-tabbar.*ui-result-tabs.*results\/export.*mk-section/s', $page->getContent());
    }

    // ---- fixtures ----

    /** @return array{0: int, 1: array<string, int>} a published quiz with one question of each type (10 points) */
    private function quizWithAllTypes(User $user, int $attempts = 1): array
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $this->actingAs($user)->postJson($base.'/quizzes', [
            'title' => 'Đề đủ dạng '.uniqid(), 'assessment_type' => 'exam', 'max_attempts' => (string) $attempts,
            'pass_score_percentage' => '60', 'show_result_mode' => 'after_grading',
        ], self::AJAX)->assertCreated();
        $quiz = (int) DB::table('core_assessment_quizzes')->max('id');
        $make = function (array $payload) use ($user, $base): int {
            $this->actingAs($user)->postJson($base.'/questions', $payload + ['status' => 'published', 'default_points' => '1'], self::AJAX)->assertCreated();

            return (int) DB::table('core_assessment_questions')->max('id');
        };
        $q = [
            'single' => $make(['question_type' => 'single_choice', 'default_points' => '2', 'prompt' => 'Chọn đáp án đúng',
                'options' => [['text' => 'Sai', 'correct' => 0], ['text' => 'Đúng', 'correct' => 1]]]),
            'multiple' => $make(['question_type' => 'multiple_choice', 'prompt' => 'Chọn các đáp án đúng',
                'options' => [['text' => 'A', 'correct' => 1], ['text' => 'B', 'correct' => 1], ['text' => 'C', 'correct' => 0]]]),
            'true_false' => $make(['question_type' => 'true_false', 'prompt' => 'Seoul là thủ đô', 'true_false_answer' => 'true']),
            'short' => $make(['question_type' => 'short_answer', 'prompt' => 'Thủ đô Hàn Quốc', 'accepted_answers' => ['Seoul', '서울']]),
            'essay' => $make(['question_type' => 'essay', 'default_points' => '4', 'prompt' => 'Viết đoạn văn']),
            'speaking' => $make(['question_type' => 'speaking', 'prompt' => 'Giới thiệu bản thân']),
        ];
        $section = (int) DB::table('core_assessment_quiz_sections')->where('quiz_id', $quiz)->value('id');
        $this->actingAs($user)->postJson($base.'/quizzes/'.$quiz.'/questions', ['quiz_section_id' => $section, 'question_ids' => array_values($q)], self::AJAX)->assertCreated();
        $this->actingAs($user)->post($base.'/quizzes/'.$quiz.'/publish')->assertSessionHasNoErrors();

        return [$quiz, $q];
    }

    private function grant(int $quizId, User $user): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $this->actingAs($user)->postJson($base.'/registrations', ['quiz_id' => $quizId, 'student_ids' => [$this->student->id]], self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_quiz_registrations')->max('id');
    }

    private function startAttempt(int $registrationId): object
    {
        TenantContext::set(DB::table('saas_customers')->find($this->customerId));

        return $this->attempts->start($this->customerId, $registrationId);
    }

    /** Submitted with only the essay written, so the attempt waits for grading (a blank one is graded 0 at once). */
    private function submittedAttempt(int $registrationId): object
    {
        $attempt = $this->startAttempt($registrationId);
        $essay = DB::table('core_assessment_quiz_version_questions')->where('quiz_version_id', $attempt->quiz_version_id)
            ->where('question_type', 'essay')->value('id');
        $this->attempts->saveAnswer($this->customerId, $attempt->id, (int) $essay, ['text' => 'Bài viết']);

        return $this->attempts->submit($this->customerId, $attempt->id);
    }

    /** @return Collection<int, object> version questions keyed by source question id */
    private function versionQuestions(object $attempt)
    {
        return DB::table('core_assessment_quiz_version_questions')->where('quiz_version_id', $attempt->quiz_version_id)->get()->keyBy('question_id');
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
