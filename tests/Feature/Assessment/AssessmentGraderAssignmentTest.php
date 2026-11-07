<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Services\Assessment\AssessmentAttemptService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phân công chấm (ADR-0027, Amendment 1.1 and 1.2): graders are named on quizzes from the quiz list (one or many at once,
 * add or replace) or the quiz page; category graders and Giao chấm per attempt are gone, the old category rows are copied
 * to quizzes once. Teachers share the attempts by Nhận chấm, Trả lại and by saving a first score; while a teacher holds
 * an attempt the other graders only view it.
 */
class AssessmentGraderAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'https://tenant-a.localhost/admin/assessments';

    private const TEACHER = 'https://tenant-a.localhost/teacher/assessments';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private int $customerId;

    private User $admin;

    private User $student;

    private AssessmentAttemptService $attempts;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://localhost', 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);

        $this->customerId = $this->createTenant('tenant-a');
        $this->admin = $this->createUser($this->customerId, 'customer_admin');
        $this->student = $this->createUser($this->customerId, 'student', 'Học viên Lan');
        $this->attempts = app(AssessmentAttemptService::class);
    }

    public function test_graders_are_named_on_many_quizzes_at_once_from_the_quiz_list(): void
    {
        $lan = $this->createUser($this->customerId, 'teacher', 'Cô Lan');
        $minh = $this->createUser($this->customerId, 'teacher', 'Thầy Minh');
        $first = $this->quiz($this->admin, $this->category('TOPIK'));
        $second = $this->quiz($this->admin);
        $attempt = $this->submittedAttempt($this->grant($first));

        // The list: every quiz can be chosen (a published one for Giao chấm only), and each shows how many graders it has.
        $list = $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes')->assertOk()
            ->assertSee('data-url-base="'.self::ADMIN.'/quizzes/graders"', false)
            ->assertSeeText(__('lf.LF_assessment_grader_none_quiz'));
        $this->assertMatchesRegularExpression('/data-ui-bulk-item\s+data-ui-bulk-no-delete\s+value="'.$first.'"/', $list->getContent());
        $this->assertSame(2, substr_count($list->getContent(), __('lf.LF_assessment_grader_none_tip')));

        // Several quizzes: nobody ticked, "Thêm vào" by default.
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/graders?ids[]='.$first.'&ids[]='.$second, self::AJAX)->assertOk()
            ->assertSee('id="assessment-quiz-graders-dialog-title"', false)->assertSee("mode: 'add'", false)
            ->assertSeeText('Cô Lan')->assertDontSee('name="user_ids[]" value="'.$lan->id.'" checked', false);
        $this->save($this->admin, [$first, $second], [$lan->id], 'add')->assertOk()
            ->assertJson(['message' => trans_choice('lf.LF_assessment_grader_quizzes_saved', 2, ['count' => 2])]);
        $this->save($this->admin, [$first], [$minh->id], 'add')->assertOk();
        $this->assertSame([$lan->id, $minh->id], $this->graders($first));
        $this->assertSame([$lan->id], $this->graders($second));
        $this->actingAs($lan)->get(self::TEACHER.'/results/'.$attempt->id)->assertOk();
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes')->assertOk()
            ->assertSeeText(trans_choice('lf.LF_assessment_grader_count', 2, ['count' => 2]))
            ->assertSeeText(trans_choice('lf.LF_assessment_grader_count', 1, ['count' => 1]));

        // The dialog says who already grades the chosen quizzes.
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/graders?ids[]='.$first.'&ids[]='.$second, self::AJAX)->assertOk()
            ->assertSeeText(__('lf.LF_assessment_grader_covers_all_quizzes'))
            ->assertSeeText(__('lf.LF_assessment_grader_covers_some', ['count' => 1, 'total' => 2]));

        // "Thay thế" leaves exactly the chosen teachers; nobody removes them all. "Thêm vào" needs someone.
        $this->save($this->admin, [$first, $second], [$minh->id], 'replace')->assertOk();
        $this->assertSame([$minh->id], $this->graders($first));
        $this->assertSame([$minh->id], $this->graders($second));
        $this->actingAs($lan)->get(self::TEACHER.'/results/'.$attempt->id)->assertNotFound();
        $this->save($this->admin, [$second], [], 'replace')->assertOk();
        $this->assertSame([], $this->graders($second));
        $this->save($this->admin, [$second], [], 'add')->assertStatus(422)->assertJsonValidationErrors('user_ids');

        // Only active teachers of the tenant can be named; other tenants cannot touch these quizzes.
        $other = $this->createTenant('tenant-b');
        foreach ([$this->student->id, $this->createUser($other, 'teacher')->id, $this->admin->id] as $wrong) {
            $this->save($this->admin, [$first], [$lan->id, $wrong], 'add')->assertStatus(422)->assertJsonValidationErrors('user_ids');
        }
        $stranger = $this->createUser($other, 'customer_admin');
        $this->actingAs($stranger)->get('https://tenant-b.localhost/admin/assessments/quizzes/graders?ids[]='.$first, self::AJAX)->assertNotFound();
        $this->actingAs($stranger)->putJson('https://tenant-b.localhost/admin/assessments/quizzes/graders', ['ids' => [$first], 'user_ids' => [], 'mode' => 'replace'], self::AJAX)
            ->assertOk()->assertJson(['message' => trans_choice('lf.LF_assessment_grader_quizzes_saved', 0, ['count' => 0]).' '
                .trans_choice('lf.LF_assessment_grader_quizzes_skipped', 1, ['count' => 1])]);
        $this->assertSame([$minh->id], $this->graders($first));
        // Only as a popup.
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/graders?ids[]='.$first)->assertNotFound();
    }

    public function test_only_quizzes_with_something_to_grade_by_hand_take_graders_and_the_list_filters_by_question_type(): void
    {
        $lan = $this->createUser($this->customerId, 'teacher', 'Cô Lan');
        $written = $this->quiz($this->admin);
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/quizzes', [
            'title' => 'Đề trắc nghiệm', 'assessment_type' => 'exam', 'max_attempts' => '1', 'show_result_mode' => 'after_grading',
        ], self::AJAX)->assertCreated();
        $choice = (int) DB::table('core_assessment_quizzes')->max('id');
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/questions', ['question_type' => 'single_choice', 'default_points' => '1', 'prompt' => 'Chọn một',
            'status' => 'published', 'options' => [['text' => 'A', 'correct' => 1], ['text' => 'B', 'correct' => 0]]], self::AJAX)->assertCreated();
        $section = (int) DB::table('core_assessment_quiz_sections')->where('quiz_id', $choice)->value('id');
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/quizzes/'.$choice.'/questions', ['quiz_section_id' => $section,
            'question_ids' => [(int) DB::table('core_assessment_questions')->max('id')]], self::AJAX)->assertCreated();

        // The auto-graded quiz says it needs no grader; its checkbox serves Xoá only.
        $page = $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes')->assertOk()
            ->assertSeeText(__('lf.LF_assessment_grader_not_needed'))->getContent();
        $this->assertMatchesRegularExpression('/data-ui-bulk-item\s+data-ui-bulk-no-open\s+value="'.$choice.'"/', $page);
        $this->assertMatchesRegularExpression('/data-ui-bulk-item(\s+data-ui-bulk-no-delete)?\s+value="'.$written.'"/', $page);

        // The list explains why Xoá is off on a published pick; the auto-graded quiz has no Người chấm tab.
        $this->assertStringContainsString('data-text-blocked="'.e(__('lf.LF_assessment_quiz_bulk_delete_blocked')).'"', $page);
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/'.$choice.'?tab=graders')->assertOk()
            ->assertDontSee('id="assessment-quiz-tab-graders"', false)->assertDontSeeText(__('lf.LF_assessment_quiz_tab_graders'))
            ->assertSee('id="assessment-quiz-tab-info"', false);
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/'.$written.'?tab=graders')->assertOk()
            ->assertSee('id="assessment-quiz-tab-graders"', false)->assertSeeText(__('lf.LF_assessment_quiz_tab_graders'));

        // Choosing both names graders on the written quiz only; the other alone opens nothing.
        $this->save($this->admin, [$written, $choice], [$lan->id], 'add')->assertOk()
            ->assertJson(['message' => trans_choice('lf.LF_assessment_grader_quizzes_saved', 1, ['count' => 1]).' '
                .trans_choice('lf.LF_assessment_grader_quizzes_skipped', 1, ['count' => 1])]);
        $this->assertSame([$lan->id], $this->graders($written));
        $this->assertSame([], $this->graders($choice));
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/graders?ids[]='.$choice, self::AJAX)->assertNotFound();

        // Filter by question type in the draft, or "graded by hand".
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes?question_type=manual')->assertOk()
            ->assertSee('value="'.$written.'"', false)->assertDontSee('value="'.$choice.'"', false);
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes?question_type=single_choice')->assertOk()
            ->assertSee('value="'.$choice.'"', false)->assertDontSee('value="'.$written.'"', false);
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes?question_type=speaking')->assertOk()
            ->assertSee('value="'.$written.'"', false)->assertDontSee('value="'.$choice.'"', false);
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes?question_type=nonsense')->assertOk()
            ->assertSee('value="'.$written.'"', false)->assertSee('value="'.$choice.'"', false);
    }

    public function test_quiz_creators_name_graders_only_on_their_own_quizzes(): void
    {
        $creator = $this->createUser($this->customerId, 'teacher', 'Cô Hoa');
        $helper = $this->createUser($this->customerId, 'teacher', 'Thầy Nam');
        $mine = $this->quiz($creator);
        $adminQuiz = $this->quiz($this->admin);

        $this->actingAs($creator)->putJson(self::TEACHER.'/quizzes/graders', ['ids' => [$mine, $adminQuiz], 'user_ids' => [$helper->id], 'mode' => 'add'], self::AJAX)
            ->assertOk()->assertJson(['message' => trans_choice('lf.LF_assessment_grader_quizzes_saved', 1, ['count' => 1]).' '
                .trans_choice('lf.LF_assessment_grader_quizzes_skipped', 1, ['count' => 1])]);
        $this->assertSame([$helper->id], $this->graders($mine));
        $this->assertSame([], $this->graders($adminQuiz));
        $this->actingAs($creator)->get(self::TEACHER.'/quizzes/graders?ids[]='.$adminQuiz, self::AJAX)->assertNotFound();
        $this->actingAs($helper)->get(self::TEACHER.'/quizzes/graders?ids[]='.$mine, self::AJAX)->assertNotFound();

        // The quiz page opens the same dialog for one quiz: its teachers ticked, "Thay thế", back to the Người chấm tab.
        $this->actingAs($creator)->get(self::TEACHER.'/quizzes/'.$mine.'?tab=graders')->assertOk()
            ->assertSee(e(self::TEACHER.'/quizzes/graders?ids%5B0%5D='.$mine.'&from_quiz=1'), false)
            ->assertSeeTextInOrder([__('lf.LF_assessment_grader_teachers'), 'Thầy Nam'])
            ->assertSee('data-tooltip="'.e(__('lf.LF_assessment_quiz_grader_role_granter_tip')).'"', false);
        $this->actingAs($creator)->get(self::TEACHER.'/quizzes/graders?ids[]='.$mine.'&from_quiz=1', self::AJAX)->assertOk()
            ->assertSee('name="user_ids[]" value="'.$helper->id.'" checked', false)->assertSee("mode: 'replace'", false)
            ->assertSee('name="return_to" value="'.$mine.'"', false);
        $this->actingAs($creator)->putJson(self::TEACHER.'/quizzes/graders', ['ids' => [$mine], 'user_ids' => [], 'mode' => 'replace', 'return_to' => $mine], self::AJAX)
            ->assertOk()->assertJsonPath('redirect', self::TEACHER.'/quizzes/'.$mine.'?tab=graders');
        $this->assertSame([], $this->graders($mine));
    }

    public function test_category_graders_are_copied_to_the_quizzes_once_and_no_longer_count(): void
    {
        $lan = $this->createUser($this->customerId, 'teacher', 'Cô Lan');
        $topik = $this->category('TOPIK');
        $topikOne = $this->category('TOPIK I', $topik);
        $inChild = $this->quiz($this->admin, $topikOne);
        $inParent = $this->quiz($this->admin, $topik);
        $elsewhere = $this->quiz($this->admin, $this->category('Tiếng Anh'));
        $attempt = $this->submittedAttempt($this->grant($inChild));
        // A category row written before Amendment 1.2.
        DB::table('core_assessment_grader_scopes')->insert(['customer_id' => $this->customerId, 'category_id' => $topik, 'user_id' => $lan->id,
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);

        // The category row alone gives no right any more.
        $this->actingAs($lan)->get(self::TEACHER.'/results/'.$attempt->id)->assertNotFound();

        $this->artisan('assessment:copy-category-graders', ['--dry-run' => true])->expectsOutputToContain('sẽ thêm 2')->assertSuccessful();
        $this->assertSame([], $this->graders($inChild));
        $this->artisan('assessment:copy-category-graders')->expectsOutputToContain('đã thêm 2')->assertSuccessful();
        $this->assertSame([$lan->id], $this->graders($inChild));
        $this->assertSame([$lan->id], $this->graders($inParent));
        $this->assertSame([], $this->graders($elsewhere));
        $this->actingAs($lan)->get(self::TEACHER.'/results/'.$attempt->id)->assertOk();
        // Running it again adds nothing.
        $this->artisan('assessment:copy-category-graders')->expectsOutputToContain('đã thêm 0')->assertSuccessful();

        // Categories and results no longer name graders.
        $this->actingAs($this->admin)->get(self::ADMIN.'/categories')->assertOk()->assertDontSee('/graders', false);
        $this->actingAs($this->admin)->get(self::ADMIN.'/categories/'.$topik.'/graders', self::AJAX)->assertNotFound();
        $this->actingAs($this->admin)->get(self::ADMIN.'/results/assign?ids[]='.$attempt->id, self::AJAX)->assertNotFound();
        $this->actingAs($this->admin)->get(self::ADMIN.'/results')->assertOk()->assertDontSee('data-ui-bulk-open', false);
        $this->actingAs($this->admin)->get(self::ADMIN.'/results/'.$attempt->id)->assertOk()->assertDontSee('/results/assign', false);
    }

    public function test_teachers_claim_and_release_attempts(): void
    {
        $lan = $this->createUser($this->customerId, 'teacher', 'Cô Lan');
        $minh = $this->createUser($this->customerId, 'teacher', 'Thầy Minh');
        $quiz = $this->quiz($this->admin);
        $attempt = $this->submittedAttempt($this->grant($quiz));
        $this->save($this->admin, [$quiz], [$lan->id, $minh->id], 'add')->assertOk();

        $this->actingAs($lan)->get(self::TEACHER.'/results?tab=pending')->assertOk()
            ->assertSee('results/'.$attempt->id.'/claim', false)->assertSeeText(__('lf.LF_assessment_grader_unassigned'));
        $this->actingAs($lan)->post(self::TEACHER.'/results/'.$attempt->id.'/claim')->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('lf.LF_assessment_grader_claimed'));
        $this->assertSame(['claimed', $lan->id, $lan->id], [
            DB::table('core_assessment_grading_assignments')->value('assignment_type'),
            (int) DB::table('core_assessment_grading_assignments')->value('assigned_to_user_id'),
            (int) DB::table('core_assessment_grading_assignments')->value('assigned_by_user_id'),
        ]);

        // The other grader sees it held and only views it; their default Chờ chấm view leaves it out.
        $this->actingAs($minh)->post(self::TEACHER.'/results/'.$attempt->id.'/claim')->assertSessionHasErrors('assignment');
        $this->grade($minh, $attempt)->assertSessionHasErrors('score');
        $this->actingAs($minh)->get(self::TEACHER.'/results/'.$attempt->id)->assertOk()
            ->assertSeeText(__('lf.LF_assessment_grader_view_only', ['name' => 'Cô Lan']))
            ->assertDontSee('/answers/'.$this->essayAnswer($attempt).'/grade', false);
        $this->actingAs($minh)->get(self::TEACHER.'/results?tab=pending')->assertOk()->assertDontSeeText('Học viên Lan');
        $this->actingAs($minh)->get(self::TEACHER.'/results?tab=pending&grader=all')->assertOk()->assertSeeText('Học viên Lan')->assertSeeText('Cô Lan');
        $this->actingAs($this->admin)->get(self::ADMIN.'/results?grader='.$lan->id)->assertOk()->assertSeeText('Học viên Lan');
        $this->actingAs($this->admin)->get(self::ADMIN.'/results?grader=unassigned')->assertOk()->assertDontSeeText('Học viên Lan');

        // Only the holder, or whoever manages the quiz, releases it; then the other teacher takes it.
        $this->actingAs($minh)->post(self::TEACHER.'/results/'.$attempt->id.'/release')->assertForbidden();
        $this->actingAs($lan)->get(self::TEACHER.'/results/'.$attempt->id)->assertOk()->assertSee('results/'.$attempt->id.'/release', false);
        $this->actingAs($lan)->post(self::TEACHER.'/results/'.$attempt->id.'/release')->assertSessionHas('success', __('lf.LF_assessment_grader_released'));
        $this->actingAs($minh)->post(self::TEACHER.'/results/'.$attempt->id.'/claim')->assertSessionHasNoErrors();
        // The admin frees it from a teacher who is away, so someone else can take it.
        $this->actingAs($this->admin)->get(self::ADMIN.'/results/'.$attempt->id)->assertOk()->assertSee('results/'.$attempt->id.'/release', false);
        $this->actingAs($this->admin)->post(self::ADMIN.'/results/'.$attempt->id.'/release')->assertSessionHasNoErrors();
        $this->assertNull(DB::table('core_assessment_grading_assignments')->where('status', 'active')->value('id'));
        $this->actingAs($lan)->post(self::TEACHER.'/results/'.$attempt->id.'/claim')->assertSessionHasNoErrors();
        $this->grade($lan, $attempt)->assertSessionHasNoErrors();

        // Admins do not claim; a fully graded attempt cannot be claimed, and its grader keeps it after leaving the quiz.
        $this->actingAs($this->admin)->post(self::ADMIN.'/results/'.$attempt->id.'/claim')->assertForbidden();
        $this->actingAs($minh)->post(self::TEACHER.'/results/'.$attempt->id.'/claim')->assertSessionHasErrors('assignment');
        $this->save($this->admin, [$quiz], [], 'replace')->assertOk();
        $this->actingAs($lan)->get(self::TEACHER.'/results/'.$attempt->id)->assertOk();
        $this->actingAs($minh)->get(self::TEACHER.'/results/'.$attempt->id)->assertNotFound();
    }

    /** ADR-0027 Amendment 1.1: the first score a teacher saves on a pending attempt nobody holds takes it for them. */
    public function test_saving_a_score_takes_an_unheld_attempt_so_two_teachers_never_grade_it_at_once(): void
    {
        $lan = $this->createUser($this->customerId, 'teacher', 'Cô Lan');
        $minh = $this->createUser($this->customerId, 'teacher', 'Thầy Minh');
        $quiz = $this->quiz($this->admin);
        $attempt = $this->submittedAttempt($this->grant($quiz));
        $this->save($this->admin, [$quiz], [$lan->id, $minh->id], 'add')->assertOk();
        // The speaking answer was recorded too, so two answers wait for grading.
        DB::table('core_assessment_answers')->where('id', $this->speakingAnswer($attempt))->update(['grading_status' => 'pending']);
        DB::table('core_assessment_gradings')->where('answer_id', $this->speakingAnswer($attempt))->delete();
        $speaking = fn (User $user, string $score) => $this->actingAs($user)->from(self::TEACHER.'/results/'.$attempt->id)
            ->post(self::TEACHER.'/results/'.$attempt->id.'/answers/'.$this->speakingAnswer($attempt).'/grade', ['score' => $score]);
        $rows = fn () => DB::table('core_assessment_grading_assignments')->where('attempt_id', $attempt->id)->orderBy('id')->get()
            ->map(fn ($row) => [$row->assignment_type, $row->status, (int) $row->assigned_to_user_id])->all();

        // Both teachers see it unheld; Lan saves the essay score without pressing Nhận chấm and now holds it.
        $this->actingAs($minh)->get(self::TEACHER.'/results/'.$attempt->id)->assertOk()
            ->assertSee('/answers/'.$this->essayAnswer($attempt).'/grade', false);
        $this->grade($lan, $attempt, '3')->assertSessionHasNoErrors();
        $this->assertSame([['claimed', 'active', $lan->id]], $rows());
        $this->assertSame('pending', DB::table('core_assessment_attempts')->where('id', $attempt->id)->value('grading_status'));

        // Minh, who opened the same attempt, can no longer grade or claim it.
        $this->grade($minh, $attempt, '1')->assertSessionHasErrors('score');
        $speaking($minh, '1')->assertSessionHasErrors('score');
        $this->actingAs($minh)->post(self::TEACHER.'/results/'.$attempt->id.'/claim')->assertSessionHasErrors('assignment');
        $this->assertSame('3.00', number_format((float) DB::table('core_assessment_answers')->where('id', $this->essayAnswer($attempt))->value('score'), 2));

        // The admin still grades, without taking it from Lan.
        $this->grade($this->admin, $attempt, '2')->assertSessionHasNoErrors();
        $this->assertSame([['claimed', 'active', $lan->id]], $rows());

        // Lan finishes: the hold completes, and a later regrade by another grader takes nothing.
        $speaking($lan, '1')->assertSessionHasNoErrors();
        $this->assertSame([['claimed', 'completed', $lan->id]], $rows());
        $this->assertSame('graded', DB::table('core_assessment_attempts')->where('id', $attempt->id)->value('status'));
        $this->grade($minh, $attempt, '4')->assertSessionHasNoErrors();
        $this->assertSame([['claimed', 'completed', $lan->id]], $rows());
    }

    public function test_the_quiz_creator_grading_takes_nothing(): void
    {
        $hoa = $this->createUser($this->customerId, 'teacher', 'Cô Hoa');
        $attempt = $this->submittedAttempt($this->grant($this->quiz($hoa), $hoa));

        $this->grade($hoa, $attempt)->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('core_assessment_grading_assignments')->where('attempt_id', $attempt->id)->count());
    }

    /** @param  list<int>  $quizIds  @param  list<int>  $userIds */
    private function save(User $user, array $quizIds, array $userIds, string $mode)
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;

        return $this->actingAs($user)->putJson($base.'/quizzes/graders', ['ids' => $quizIds, 'user_ids' => $userIds, 'mode' => $mode], self::AJAX);
    }

    /** @return list<int> */
    private function graders(int $quizId): array
    {
        return DB::table('core_assessment_grader_scopes')->where('quiz_id', $quizId)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    private function grade(User $user, object $attempt, string $score = '3')
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;

        return $this->actingAs($user)->from($base.'/results/'.$attempt->id)
            ->post($base.'/results/'.$attempt->id.'/answers/'.$this->essayAnswer($attempt).'/grade', ['score' => $score]);
    }

    private function essayAnswer(object $attempt): int
    {
        return (int) DB::table('core_assessment_answers')->where('attempt_id', $attempt->id)->where('answer_type', 'text')->whereNull('selected_option_keys')
            ->whereIn('quiz_version_question_id', DB::table('core_assessment_quiz_version_questions')->where('question_type', 'essay')->pluck('id'))->value('id');
    }

    private function speakingAnswer(object $attempt): int
    {
        return (int) DB::table('core_assessment_answers')->where('attempt_id', $attempt->id)
            ->whereIn('quiz_version_question_id', DB::table('core_assessment_quiz_version_questions')->where('question_type', 'speaking')->pluck('id'))->value('id');
    }

    private function category(string $name, ?int $parentId = null): int
    {
        return DB::table('core_assessment_categories')->insertGetId([
            'customer_id' => $this->customerId, 'parent_id' => $parentId, 'name' => $name, 'slug' => str()->slug($name).'-'.uniqid(),
            'sort_order' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A published quiz with one essay (4 points) and one speaking question (1 point), in the given category. */
    private function quiz(User $user, ?int $categoryId = null): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $this->actingAs($user)->postJson($base.'/quizzes', [
            'title' => 'Đề viết '.uniqid(), 'assessment_type' => 'exam', 'max_attempts' => '1', 'show_result_mode' => 'after_grading',
        ], self::AJAX)->assertCreated();
        $quiz = (int) DB::table('core_assessment_quizzes')->max('id');
        DB::table('core_assessment_quizzes')->where('id', $quiz)->update(['category_id' => $categoryId]);
        $ids = [];
        foreach ([['essay', '4', 'Viết đoạn văn'], ['speaking', '1', 'Giới thiệu bản thân']] as [$type, $points, $prompt]) {
            $this->actingAs($user)->postJson($base.'/questions', ['question_type' => $type, 'default_points' => $points, 'prompt' => $prompt, 'status' => 'published'], self::AJAX)->assertCreated();
            $ids[] = (int) DB::table('core_assessment_questions')->max('id');
        }
        $section = (int) DB::table('core_assessment_quiz_sections')->where('quiz_id', $quiz)->value('id');
        $this->actingAs($user)->postJson($base.'/quizzes/'.$quiz.'/questions', ['quiz_section_id' => $section, 'question_ids' => $ids], self::AJAX)->assertCreated();
        $this->actingAs($user)->post($base.'/quizzes/'.$quiz.'/publish')->assertSessionHasNoErrors();

        return $quiz;
    }

    private function grant(int $quizId, ?User $user = null): int
    {
        $user ??= $this->admin;
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $student = $this->createUser($this->customerId, 'student', 'Học viên Lan');
        $this->actingAs($user)->postJson($base.'/registrations', ['quiz_id' => $quizId, 'student_ids' => [$student->id]], self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_quiz_registrations')->max('id');
    }

    private function startAttempt(int $registrationId): object
    {
        TenantContext::set(DB::table('saas_customers')->find($this->customerId));

        return $this->attempts->start($this->customerId, $registrationId);
    }

    /** Submitted with the essay written, so it waits for grading; the blank speaking answer is graded 0 at once. */
    private function submittedAttempt(int $registrationId): object
    {
        $attempt = $this->startAttempt($registrationId);
        $essay = DB::table('core_assessment_quiz_version_questions')->where('quiz_version_id', $attempt->quiz_version_id)
            ->where('question_type', 'essay')->value('id');
        $this->attempts->saveAnswer($this->customerId, $attempt->id, (int) $essay, ['text' => 'Bài viết']);

        return $this->attempts->submit($this->customerId, $attempt->id);
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
