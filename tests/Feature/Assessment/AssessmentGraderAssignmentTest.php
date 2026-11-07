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
 * Phân công chấm (ADR-0027 D1–D4): graders named on a category (inherited by sub-categories) or a quiz, Giao chấm by an
 * admin or the quiz creator, Nhận chấm / Trả lại by teachers, and the rule that while a teacher holds an attempt the
 * other graders only view it.
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

    public function test_category_graders_are_inherited_by_sub_categories_and_only_admins_name_them(): void
    {
        $lan = $this->createUser($this->customerId, 'teacher', 'Cô Lan');
        $minh = $this->createUser($this->customerId, 'teacher', 'Thầy Minh');
        $topik = $this->category('TOPIK');
        $topikOne = $this->category('TOPIK I', $topik);
        $quiz = $this->quiz($this->admin, $topikOne);
        $attempt = $this->submittedAttempt($this->grant($quiz));

        // Not named anywhere yet: the attempt of the admin's quiz is out of reach.
        $this->actingAs($lan)->get(self::TEACHER.'/results/'.$attempt->id)->assertNotFound();

        $this->actingAs($this->admin)->get(self::ADMIN.'/categories/'.$topik.'/graders', self::AJAX)->assertOk()
            ->assertSee('id="assessment-grader-dialog-title"', false)->assertSeeText('Cô Lan')->assertDontSeeText('Học viên Lan');
        $this->actingAs($this->admin)->putJson(self::ADMIN.'/categories/'.$topik.'/graders', ['user_ids' => [$lan->id]], self::AJAX)->assertOk();
        $this->assertSame([[$topik, null, $lan->id, $this->admin->id]], DB::table('core_assessment_grader_scopes')->get()
            ->map(fn ($row) => [(int) $row->category_id, $row->quiz_id, (int) $row->user_id, (int) $row->created_by])->all());

        // Named on the parent: grades the sub-category's quiz.
        $this->actingAs($lan)->get(self::TEACHER.'/results')->assertOk()->assertSeeText('Học viên Lan');
        $this->actingAs($lan)->get(self::TEACHER.'/results/'.$attempt->id)->assertOk();
        $this->grade($lan, $attempt)->assertSessionHasNoErrors();
        $this->actingAs($minh)->get(self::TEACHER.'/results/'.$attempt->id)->assertNotFound();

        // The list shows who is named directly and what the child inherits.
        $this->actingAs($this->admin)->get(self::ADMIN.'/categories')->assertOk()
            ->assertSeeText('Cô Lan')->assertSeeText(__('lf.LF_assessment_grader_inherited_from', ['category' => 'TOPIK', 'count' => 1]));

        // Only active teachers of the tenant can be named; teachers have no category routes.
        $other = $this->createUser($this->createTenant('tenant-b'), 'teacher');
        foreach ([$this->student->id, $other->id, $this->admin->id] as $wrong) {
            $this->actingAs($this->admin)->putJson(self::ADMIN.'/categories/'.$topik.'/graders', ['user_ids' => [$lan->id, $wrong]], self::AJAX)
                ->assertStatus(422)->assertJsonValidationErrors('user_ids');
        }
        $this->actingAs($lan)->get(self::TEACHER.'/categories/'.$topik.'/graders', self::AJAX)->assertNotFound();

        // Clearing the list removes the right.
        $this->actingAs($this->admin)->putJson(self::ADMIN.'/categories/'.$topik.'/graders', ['user_ids' => []], self::AJAX)->assertOk();
        $this->actingAs($lan)->get(self::TEACHER.'/results/'.$attempt->id)->assertNotFound();
    }

    public function test_quiz_graders_are_named_by_the_admin_or_the_quiz_creator(): void
    {
        $creator = $this->createUser($this->customerId, 'teacher', 'Cô Hoa');
        $helper = $this->createUser($this->customerId, 'teacher', 'Thầy Nam');
        $outsider = $this->createUser($this->customerId, 'teacher', 'Thầy Khoa');
        $category = $this->category('Sơ cấp');
        $quiz = $this->quiz($creator, $category);
        $attempt = $this->submittedAttempt($this->grant($quiz, $creator));
        $this->actingAs($this->admin)->putJson(self::ADMIN.'/categories/'.$category.'/graders', ['user_ids' => [$outsider->id]], self::AJAX)->assertOk();

        $this->actingAs($creator)->get(self::TEACHER.'/quizzes/'.$quiz.'/graders', self::AJAX)->assertOk()
            ->assertSeeText(__('lf.LF_assessment_grader_inherited'))->assertSeeText('Thầy Khoa');
        $this->actingAs($creator)->putJson(self::TEACHER.'/quizzes/'.$quiz.'/graders', ['user_ids' => [$helper->id]], self::AJAX)->assertOk();
        $this->actingAs($helper)->get(self::TEACHER.'/results/'.$attempt->id)->assertOk();
        $this->grade($helper, $attempt)->assertSessionHasNoErrors();

        // Another teacher cannot open or change the quiz's graders.
        $this->actingAs($helper)->get(self::TEACHER.'/quizzes/'.$quiz.'/graders', self::AJAX)->assertNotFound();
        $this->actingAs($helper)->putJson(self::TEACHER.'/quizzes/'.$quiz.'/graders', ['user_ids' => [$helper->id, $outsider->id]], self::AJAX)->assertNotFound();

        $this->actingAs($creator)->get(self::TEACHER.'/quizzes/'.$quiz)->assertOk()
            ->assertSeeText('Thầy Nam')->assertSeeText(__('lf.LF_assessment_grader_from_category', ['category' => 'Sơ cấp', 'names' => 'Thầy Khoa']));
        $this->actingAs($this->admin)->putJson(self::ADMIN.'/quizzes/'.$quiz.'/graders', ['user_ids' => []], self::AJAX)->assertOk();
        $this->actingAs($helper)->get(self::TEACHER.'/results/'.$attempt->id)->assertNotFound();
    }

    public function test_an_assigned_teacher_holds_the_attempt_and_the_other_graders_only_view_it(): void
    {
        $lan = $this->createUser($this->customerId, 'teacher', 'Cô Lan');
        $minh = $this->createUser($this->customerId, 'teacher', 'Thầy Minh');
        $guest = $this->createUser($this->customerId, 'teacher', 'Cô Mai');
        $category = $this->category('TOPIK');
        $quiz = $this->quiz($this->admin, $category);
        $attempt = $this->submittedAttempt($this->grant($quiz));
        $this->actingAs($this->admin)->putJson(self::ADMIN.'/categories/'.$category.'/graders', ['user_ids' => [$lan->id, $minh->id]], self::AJAX)->assertOk();

        // The dialog ranks the category's teachers first; anyone active can be chosen.
        $dialog = $this->actingAs($this->admin)->get(self::ADMIN.'/results/assign?ids[]='.$attempt->id, self::AJAX)->assertOk()
            ->assertSee('id="assessment-assign-dialog-title"', false)->assertSeeText(__('lf.LF_assessment_grader_covers_all'));
        $this->assertLessThan(strpos($dialog->getContent(), 'Cô Mai'), strpos($dialog->getContent(), 'Cô Lan'));

        // Giao chấm to a teacher outside the category: they now see and grade it.
        $this->actingAs($guest)->get(self::TEACHER.'/results/'.$attempt->id)->assertNotFound();
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/results/assign', ['ids' => [$attempt->id], 'teacher_id' => $guest->id], self::AJAX)
            ->assertOk()->assertJson(['message' => trans_choice('lf.LF_assessment_grader_assigned', 1, ['count' => 1])]);
        $this->actingAs($guest)->get(self::TEACHER.'/results?tab=pending')->assertOk()->assertSeeText('Học viên Lan')->assertSeeText(__('lf.LF_assessment_grader_you'));

        // The category's teachers see it held and cannot grade it, nor claim it.
        $this->actingAs($lan)->get(self::TEACHER.'/results/'.$attempt->id)->assertOk()
            ->assertSeeText(__('lf.LF_assessment_grader_view_only', ['name' => 'Cô Mai']))
            ->assertDontSee('/answers/'.$this->essayAnswer($attempt).'/grade', false);
        $this->grade($lan, $attempt)->assertSessionHasErrors('score');
        $this->actingAs($lan)->post(self::TEACHER.'/results/'.$attempt->id.'/claim')->assertSessionHasErrors('assignment');
        // ...and their default Chờ chấm view (mine + unassigned) leaves it out; "all" shows it.
        $this->actingAs($lan)->get(self::TEACHER.'/results?tab=pending')->assertOk()->assertDontSeeText('Học viên Lan');
        $this->actingAs($lan)->get(self::TEACHER.'/results?tab=pending&grader=all')->assertOk()->assertSeeText('Học viên Lan')->assertSeeText('Cô Mai');

        // Reassigning releases the earlier holder; the admin always grades.
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/results/assign', ['ids' => [$attempt->id], 'teacher_id' => $minh->id], self::AJAX)->assertOk();
        $this->assertSame([['released', $guest->id], ['active', $minh->id]], DB::table('core_assessment_grading_assignments')->orderBy('id')->get()
            ->map(fn ($row) => [$row->status, (int) $row->assigned_to_user_id])->all());
        $this->actingAs($guest)->get(self::TEACHER.'/results/'.$attempt->id)->assertNotFound();
        $this->actingAs($this->admin)->get(self::ADMIN.'/results?grader='.$minh->id)->assertOk()->assertSeeText('Học viên Lan');
        $this->actingAs($this->admin)->get(self::ADMIN.'/results?grader=unassigned')->assertOk()->assertDontSeeText('Học viên Lan');

        // The last pending answer graded: the assignment completes, the holder still sees and regrades it.
        $this->grade($this->admin, $attempt, '2')->assertSessionHasNoErrors();
        $this->assertSame('graded', DB::table('core_assessment_attempts')->where('id', $attempt->id)->value('status'));
        $this->assertSame('completed', DB::table('core_assessment_grading_assignments')->where('assigned_to_user_id', $minh->id)->value('status'));
        $this->assertNotNull(DB::table('core_assessment_grading_assignments')->where('assigned_to_user_id', $minh->id)->value('ended_at'));
        $this->grade($minh, $attempt, '3')->assertSessionHasNoErrors();
        $this->grade($lan, $attempt, '1')->assertSessionHasNoErrors();
    }

    public function test_teachers_claim_and_release_attempts(): void
    {
        $lan = $this->createUser($this->customerId, 'teacher', 'Cô Lan');
        $minh = $this->createUser($this->customerId, 'teacher', 'Thầy Minh');
        $category = $this->category('TOPIK');
        $quiz = $this->quiz($this->admin, $category);
        $attempt = $this->submittedAttempt($this->grant($quiz));
        $this->actingAs($this->admin)->putJson(self::ADMIN.'/categories/'.$category.'/graders', ['user_ids' => [$lan->id, $minh->id]], self::AJAX)->assertOk();

        $this->actingAs($lan)->get(self::TEACHER.'/results?tab=pending')->assertOk()
            ->assertSee('results/'.$attempt->id.'/claim', false)->assertSeeText(__('lf.LF_assessment_grader_unassigned'));
        $this->actingAs($lan)->post(self::TEACHER.'/results/'.$attempt->id.'/claim')->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('lf.LF_assessment_grader_claimed'));
        $this->assertSame(['claimed', $lan->id, $lan->id], [
            DB::table('core_assessment_grading_assignments')->value('assignment_type'),
            (int) DB::table('core_assessment_grading_assignments')->value('assigned_to_user_id'),
            (int) DB::table('core_assessment_grading_assignments')->value('assigned_by_user_id'),
        ]);
        $this->actingAs($minh)->post(self::TEACHER.'/results/'.$attempt->id.'/claim')->assertSessionHasErrors('assignment');
        $this->grade($minh, $attempt)->assertSessionHasErrors('score');

        // Only the holder (or whoever may hand it out) releases it; then the other teacher takes it.
        $this->actingAs($minh)->post(self::TEACHER.'/results/'.$attempt->id.'/release')->assertForbidden();
        $this->actingAs($lan)->get(self::TEACHER.'/results/'.$attempt->id)->assertOk()->assertSee('results/'.$attempt->id.'/release', false);
        $this->actingAs($lan)->post(self::TEACHER.'/results/'.$attempt->id.'/release')->assertSessionHas('success', __('lf.LF_assessment_grader_released'));
        $this->actingAs($minh)->post(self::TEACHER.'/results/'.$attempt->id.'/claim')->assertSessionHasNoErrors();
        $this->grade($minh, $attempt)->assertSessionHasNoErrors();

        // Admins hand attempts out instead of claiming them; a fully graded attempt cannot be claimed.
        $this->actingAs($this->admin)->post(self::ADMIN.'/results/'.$attempt->id.'/claim')->assertForbidden();
        $this->actingAs($this->admin)->post(self::ADMIN.'/results/'.$attempt->id.'/answers/'.$this->speakingAnswer($attempt).'/grade', ['score' => '0']);
        $this->actingAs($this->admin)->post(self::ADMIN.'/results/'.$attempt->id.'/release')->assertSessionHasNoErrors();
    }

    public function test_quiz_creators_assign_only_their_own_attempts_and_in_progress_ones_are_skipped(): void
    {
        $creator = $this->createUser($this->customerId, 'teacher', 'Cô Hoa');
        $helper = $this->createUser($this->customerId, 'teacher', 'Thầy Nam');
        $mine = $this->submittedAttempt($this->grant($this->quiz($creator), $creator));
        $adminQuiz = $this->quiz($this->admin);
        $theirs = $this->submittedAttempt($this->grant($adminQuiz));
        $open = $this->startAttempt($this->grant($this->quiz($creator), $creator));

        // The admin's attempt is out of the creator's reach, the open one cannot be handed out.
        $this->actingAs($creator)->postJson(self::TEACHER.'/results/assign', ['ids' => [$mine->id, $theirs->id, $open->id], 'teacher_id' => $helper->id], self::AJAX)
            ->assertOk()->assertJson(['message' => trans_choice('lf.LF_assessment_grader_assigned', 1, ['count' => 1]).' '
                .trans_choice('lf.LF_assessment_grader_skipped', 2, ['count' => 2])]);
        $this->assertSame([(int) $mine->id], DB::table('core_assessment_grading_assignments')->pluck('attempt_id')->map(fn ($id) => (int) $id)->all());
        $this->actingAs($helper)->get(self::TEACHER.'/results/'.$mine->id)->assertOk();

        // Nothing assignable: the dialog does not open; a student or another tenant's teacher is refused.
        $this->actingAs($creator)->get(self::TEACHER.'/results/assign?ids[]='.$theirs->id, self::AJAX)->assertNotFound();
        $other = $this->createUser($this->createTenant('tenant-b'), 'teacher');
        foreach ([$this->student->id, $other->id] as $wrong) {
            $this->actingAs($this->admin)->postJson(self::ADMIN.'/results/assign', ['ids' => [$theirs->id], 'teacher_id' => $wrong], self::AJAX)
                ->assertStatus(422)->assertJsonValidationErrors('teacher_id');
        }

        // The list offers the checkbox only on rows the creator may hand out.
        $page = $this->actingAs($creator)->get(self::TEACHER.'/results')->assertOk()->getContent();
        $this->assertStringContainsString('data-ui-bulk-item value="'.$mine->id.'"', $page);
        $this->assertStringNotContainsString('data-ui-bulk-item value="'.$open->id.'"', $page);
        // A teacher who created no quiz gets no assign column.
        $this->actingAs($helper)->get(self::TEACHER.'/results')->assertOk()->assertDontSee('data-ui-bulk-open', false);
    }

    public function test_assigning_from_the_attempt_page_returns_to_it(): void
    {
        $lan = $this->createUser($this->customerId, 'teacher', 'Cô Lan');
        $attempt = $this->submittedAttempt($this->grant($this->quiz($this->admin)));
        $page = self::ADMIN.'/results/'.$attempt->id;

        $this->actingAs($this->admin)->get($page)->assertOk()->assertSeeText(__('lf.LF_assessment_grader_unassigned'))
            ->assertSee('results/assign?ids%5B0%5D='.$attempt->id.'&amp;from_attempt=1', false);
        $this->actingAs($this->admin)->get(self::ADMIN.'/results/assign?ids[]='.$attempt->id.'&from_attempt=1', self::AJAX)->assertOk()
            ->assertSee('name="return_to" value="'.$attempt->id.'"', false);
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/results/assign', ['ids' => [$attempt->id], 'teacher_id' => $lan->id, 'return_to' => $attempt->id], self::AJAX)
            ->assertOk()->assertJson(['redirect' => $page]);
        $this->actingAs($this->admin)->get($page)->assertOk()->assertSeeText('Cô Lan')
            ->assertSeeText(__('lf.LF_assessment_grader_reassign'))->assertSee('results/'.$attempt->id.'/release', false);
    }

    // ---- fixtures ----

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
