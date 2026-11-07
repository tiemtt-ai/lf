<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Services\Assessment\AssessmentAttemptService;
use App\Services\Assessment\AssessmentStatisticsService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Thống kê tab of Kết quả & Chấm bài: per-quiz totals and per-question correct rates, gathered only from attempts the
 * user may see (same scope as the results list).
 */
class AssessmentStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'https://tenant-a.localhost/admin/assessments';

    private const TEACHER = 'https://tenant-a.localhost/teacher/assessments';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private int $customerId;

    private User $admin;

    private User $teacher;

    private AssessmentAttemptService $attempts;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://localhost', 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);

        $this->customerId = $this->createTenant('tenant-a');
        $this->admin = $this->createUser($this->customerId, 'customer_admin');
        $this->teacher = $this->createUser($this->customerId, 'teacher');
        $this->attempts = app(AssessmentAttemptService::class);
    }

    public function test_totals_and_per_question_rates_of_submitted_attempts(): void
    {
        [$quiz, $q] = $this->quizWithAllTypes($this->admin);
        $students = [$this->createUser($this->customerId, 'student'), $this->createUser($this->customerId, 'student'), $this->createUser($this->customerId, 'student')];
        $made = [];
        foreach ($students as $i => $student) {
            $attempt = $this->startAttempt($this->grant($quiz, $this->admin, $student));
            $vq = $this->versionQuestions($attempt);
            // Students 1 and 3 get the single-choice question right, student 2 does not.
            $this->attempts->saveAnswer($this->customerId, $attempt->id, $vq[$q['single']]->id, ['option_keys' => [$i === 1 ? 1 : 2]]);
            $this->attempts->saveAnswer($this->customerId, $attempt->id, $vq[$q['essay']]->id, ['text' => 'Bài viết']);
            $made[] = $this->attempts->submit($this->customerId, $attempt->id);
        }
        // An attempt still in progress is not counted.
        $this->startAttempt($this->grant($quiz, $this->admin, $this->createUser($this->customerId, 'student')));

        // Student 1 is fully graded: single 2 + essay 4 + speaking 1 = 7 / 10 (70%, pass mark 60%).
        $answer = fn (object $attempt, int $question) => DB::table('core_assessment_answers')->where('attempt_id', $attempt->id)->where('question_id', $question)->value('id');
        $page = self::ADMIN.'/results/'.$made[0]->id;
        $this->actingAs($this->admin)->post($page.'/answers/'.$answer($made[0], $q['essay']).'/grade', ['score' => '4'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post($page.'/answers/'.$answer($made[0], $q['speaking']).'/grade', ['score' => '1'])->assertSessionHasNoErrors();

        $html = $this->actingAs($this->admin)->get(self::ADMIN.'/results/statistics?quiz_id='.$quiz)->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSeeText(__('lf.LF_assessment_statistics_tab'))
            ->getContent();

        $stats = app(AssessmentStatisticsService::class)->forQuiz($this->customerId, $this->admin, $quiz);
        // Provisional scores (0% and 20%, essay / speaking not graded yet) stay out: only student 1's 70% counts.
        $this->assertSame(['submitted' => 3, 'pending' => 2, 'graded' => 1, 'average' => 70.0, 'highest' => 70.0, 'lowest' => 70.0, 'pass_rate' => 100.0], $stats['summary']);
        $rows = $stats['questions']->keyBy('question_id');
        $this->assertSame(['single_choice', 'multiple_choice', 'true_false', 'short_answer', 'essay', 'speaking'], $stats['questions']->pluck('type')->all());
        // Three answers are too few for Dễ / Vừa / Khó.
        $this->assertSame([3, 66.7, null], [$rows[$q['single']]['answered'], $rows[$q['single']]['rate'], $rows[$q['single']]['level']]);
        $this->assertSame([0.0, null], [$rows[$q['multiple']]['rate'], $rows[$q['multiple']]['level']]);
        // Essay: only the graded answer counts (4 / 4), two still pending.
        $this->assertSame([1, 3, 100.0, null, 2], [$rows[$q['essay']]['counted'], $rows[$q['essay']]['answered'], $rows[$q['essay']]['rate'], $rows[$q['essay']]['level'], $rows[$q['essay']]['pending']]);

        $this->assertStringContainsString('66.7%', $html);
        $this->assertStringContainsString('70%', $html);
        $this->assertStringContainsString(trans_choice('lf.LF_assessment_statistics_based_on', 1, ['count' => 1]), $html);
        $this->assertStringContainsString('1 / 3', $html);
        $this->assertStringContainsString(__('lf.LF_assessment_statistics_level_too_few'), $html);
        $this->assertStringContainsString(__('lf.LF_assessment_statistics_rate_heading'), $html);
        $this->assertStringContainsString('Chọn đáp án đúng', $html);
    }

    public function test_levels_need_enough_answers_and_scores_wait_for_grading(): void
    {
        [$quiz, $q] = $this->quizWithAllTypes($this->admin);
        $url = self::ADMIN.'/results/statistics?quiz_id='.$quiz;
        $student = fn () => $this->createUser($this->customerId, 'student');

        // One submission still waiting for grading: no score figures yet.
        $this->submittedAttempt($this->grant($quiz, $this->admin, $student()));
        $summary = app(AssessmentStatisticsService::class)->forQuiz($this->customerId, $this->admin, $quiz)['summary'];
        $this->assertSame([1, 1, 0, null, null], [$summary['submitted'], $summary['pending'], $summary['graded'], $summary['average'], $summary['pass_rate']]);
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSeeText(__('lf.LF_assessment_statistics_none_graded'));

        // Five answers: single choice all right (Dễ, green), multiple choice all wrong (Khó, red), true / false 3 of 5 (Vừa, neutral).
        for ($i = 0; $i < 4; $i++) {
            $attempt = $this->startAttempt($this->grant($quiz, $this->admin, $student()));
            $vq = DB::table('core_assessment_quiz_version_questions')->where('quiz_version_id', $attempt->quiz_version_id)->get()->keyBy('question_id');
            $this->attempts->saveAnswer($this->customerId, $attempt->id, $vq[$q['single']]->id, ['option_keys' => [2]]);
            $this->attempts->saveAnswer($this->customerId, $attempt->id, $vq[$q['true_false']]->id, ['option_keys' => [$i < 3 ? 1 : 2]]);
            $this->attempts->submit($this->customerId, $attempt->id);
        }
        DB::table('core_assessment_answers')->where('question_id', $q['single'])->update(['is_correct' => 1]);

        $rows = app(AssessmentStatisticsService::class)->forQuiz($this->customerId, $this->admin, $quiz)['questions']->keyBy('question_id');
        $this->assertSame(['easy', 'hard', 'medium'], [$rows[$q['single']]['level'], $rows[$q['multiple']]['level'], $rows[$q['true_false']]['level']]);
        $this->actingAs($this->admin)->get($url)->assertOk()
            ->assertSee('class="ui-status ui-status--active">'.__('lf.LF_assessment_statistics_level_easy'), false)
            ->assertSee('class="ui-status ui-status--danger">'.__('lf.LF_assessment_statistics_level_hard'), false)
            ->assertSee('class="ui-status">'.__('lf.LF_assessment_statistics_level_medium'), false);
    }

    public function test_teachers_see_only_their_quizzes_and_other_tenants_nothing(): void
    {
        [$adminQuiz] = $this->quizWithAllTypes($this->admin);
        $this->submittedAttempt($this->grant($adminQuiz, $this->admin, $this->createUser($this->customerId, 'student')));

        // Not the teacher's quiz: nothing to show, and asking for it by id falls back to their own list.
        $this->actingAs($this->teacher)->get(self::TEACHER.'/results/statistics?quiz_id='.$adminQuiz)->assertOk()
            ->assertSeeText(__('lf.LF_assessment_statistics_empty'))
            ->assertDontSeeText('Chọn đáp án đúng');

        [$mine] = $this->quizWithAllTypes($this->teacher);
        $this->submittedAttempt($this->grant($mine, $this->teacher, $this->createUser($this->customerId, 'student')));
        $this->actingAs($this->teacher)->get(self::TEACHER.'/results/statistics?quiz_id='.$adminQuiz)->assertOk()
            ->assertSee('<option value="'.$mine.'" selected', false)
            ->assertDontSee('<option value="'.$adminQuiz.'"', false);

        $otherAdmin = $this->createUser($this->createTenant('tenant-b'), 'customer_admin');
        $this->actingAs($otherAdmin)->get('https://tenant-b.localhost/admin/assessments/results/statistics?quiz_id='.$adminQuiz)->assertOk()
            ->assertSeeText(__('lf.LF_assessment_statistics_empty'));
        $this->actingAs($this->createUser($this->customerId, 'student'))->get(self::ADMIN.'/results/statistics')->assertForbidden();
    }

    public function test_results_list_links_to_the_statistics_tab(): void
    {
        $this->actingAs($this->admin)->get(self::ADMIN.'/results')->assertOk()
            ->assertSee('href="'.self::ADMIN.'/results/statistics"', false);
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

    private function grant(int $quizId, User $user, User $student): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $this->actingAs($user)->postJson($base.'/registrations', ['quiz_id' => $quizId, 'student_ids' => [$student->id]], self::AJAX)->assertCreated();

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
