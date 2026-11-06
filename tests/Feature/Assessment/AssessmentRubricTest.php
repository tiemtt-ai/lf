<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use App\Services\Assessment\AssessmentAttemptService;
use App\Services\Assessment\AssessmentRubricService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tiêu chí chấm (ADR-0026): rubrics owned like questions (teachers also use the admins'), chosen on essay / speaking
 * questions, frozen into the version on publish, graded per criterion and scaled to the question's points, shown to
 * the student with the feedback.
 */
class AssessmentRubricTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'https://tenant-a.localhost/admin/assessments';

    private const TEACHER = 'https://tenant-a.localhost/teacher/assessments';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private int $customerId;

    private User $admin;

    private User $teacher;

    private User $otherTeacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://localhost', 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);

        $this->customerId = $this->createTenant('tenant-a');
        $this->admin = $this->createUser($this->customerId, 'customer_admin');
        $this->teacher = $this->createUser($this->customerId, 'teacher');
        $this->otherTeacher = $this->createUser($this->customerId, 'teacher');
        $this->student = $this->createUser($this->customerId, 'student');
    }

    public function test_rubric_form_saves_criteria_and_total_and_refuses_bad_rows(): void
    {
        $this->actingAs($this->teacher)->get(self::TEACHER.'/rubrics/create', self::AJAX)->assertOk()
            ->assertSee('items[', false);

        $id = $this->rubric($this->teacher, self::TEACHER, 'Viết câu 54', [
            ['title' => 'Nội dung', 'max_points' => '12', 'description' => 'Đủ ý'],
            ['title' => '', 'max_points' => '', 'description' => ''],
            ['title' => 'Bố cục', 'max_points' => '12'],
            ['title' => 'Ngôn ngữ', 'max_points' => '26'],
        ]);

        $rubric = DB::table('core_assessment_rubrics')->find($id);
        $this->assertSame(['50.00', $this->teacher->id, 'active'], [number_format((float) $rubric->total_points, 2, '.', ''), (int) $rubric->created_by, $rubric->status]);
        $this->assertSame(['Nội dung', 'Bố cục', 'Ngôn ngữ'], DB::table('core_assessment_rubric_items')->where('rubric_id', $id)->orderBy('sort_order')->pluck('title')->all());
        $this->assertSame([1, 2, 3], DB::table('core_assessment_rubric_items')->where('rubric_id', $id)->orderBy('sort_order')->pluck('sort_order')->map(fn ($v) => (int) $v)->all());

        $post = fn (array $items) => $this->actingAs($this->teacher)->postJson(self::TEACHER.'/rubrics', [
            'title' => 'Lỗi', 'status' => 'active', 'items' => $items,
        ], self::AJAX);
        $post([])->assertUnprocessable()->assertJsonValidationErrors('items');
        $post([['title' => 'Chỉ có tên', 'max_points' => '']])->assertUnprocessable()->assertJsonValidationErrors('items');
        $post([['title' => 'Điểm 0', 'max_points' => '0']])->assertUnprocessable()->assertJsonValidationErrors('items.0.max_points');

        // Editing rewrites the criteria and the total.
        $this->actingAs($this->teacher)->putJson(self::TEACHER.'/rubrics/'.$id, [
            'title' => 'Viết câu 54', 'status' => 'active', 'items' => [['title' => 'Chung', 'max_points' => '10']],
        ], self::AJAX)->assertOk();
        $this->assertEquals(10, (float) DB::table('core_assessment_rubrics')->where('id', $id)->value('total_points'));
        $this->assertSame(1, DB::table('core_assessment_rubric_items')->where('rubric_id', $id)->count());
    }

    public function test_teachers_use_their_own_and_admin_rubrics_and_edit_only_their_own(): void
    {
        $adminRubric = $this->rubric($this->admin, self::ADMIN, 'Chuẩn trung tâm');
        $mine = $this->rubric($this->teacher, self::TEACHER, 'Của tôi');
        $other = $this->rubric($this->otherTeacher, self::TEACHER, 'Của người khác');

        $this->actingAs($this->teacher)->get(self::TEACHER.'/rubrics')->assertOk()
            ->assertSee('Chuẩn trung tâm')->assertSee('Của tôi')->assertDontSee('Của người khác')
            ->assertSee(self::TEACHER.'/rubrics/'.$mine.'/edit', false)
            ->assertDontSee(self::TEACHER.'/rubrics/'.$adminRubric.'/edit', false);
        $this->actingAs($this->admin)->get(self::ADMIN.'/rubrics')->assertOk()->assertSee('Của người khác');

        $this->actingAs($this->teacher)->get(self::TEACHER.'/rubrics/'.$adminRubric.'/edit', self::AJAX)->assertNotFound();
        $this->actingAs($this->teacher)->putJson(self::TEACHER.'/rubrics/'.$adminRubric, [
            'title' => 'Đổi', 'status' => 'active', 'items' => [['title' => 'A', 'max_points' => '1']],
        ], self::AJAX)->assertNotFound();
        $this->actingAs($this->teacher)->post(self::TEACHER.'/rubrics/'.$other.'/toggle-status')->assertNotFound();
        $this->actingAs($this->teacher)->deleteJson(self::TEACHER.'/rubrics', ['ids' => [$adminRubric]], self::AJAX)->assertNotFound();

        // The question form offers exactly the usable active rubrics.
        $this->actingAs($this->teacher)->get(self::TEACHER.'/questions/create', self::AJAX)->assertOk()
            ->assertSee('name="rubric_id"', false)->assertSee('Chuẩn trung tâm')->assertSee('Của tôi')->assertDontSee('Của người khác');

        // Picking: own and admin rubrics yes, another teacher's no, an archived one no.
        $this->question($this->teacher, ['rubric_id' => $adminRubric])->assertCreated();
        $this->question($this->teacher, ['rubric_id' => $other])->assertUnprocessable()->assertJsonValidationErrors('rubric_id');
        $this->actingAs($this->teacher)->post(self::TEACHER.'/rubrics/'.$mine.'/toggle-status')->assertRedirect();
        $this->assertSame('archived', DB::table('core_assessment_rubrics')->where('id', $mine)->value('status'));
        $this->question($this->teacher, ['rubric_id' => $mine])->assertUnprocessable()->assertJsonValidationErrors('rubric_id');

        $this->actingAs($this->student)->get(self::TEACHER.'/rubrics')->assertForbidden();
    }

    public function test_question_keeps_its_rubric_only_for_essay_and_speaking_and_blocks_deleting_it(): void
    {
        $rubric = $this->rubric($this->teacher, self::TEACHER, 'Nói');

        $this->question($this->teacher, ['question_type' => 'single_choice', 'rubric_id' => $rubric,
            'options' => [['text' => 'A', 'correct' => 1], ['text' => 'B', 'correct' => 0]]])->assertCreated();
        $this->assertNull(DB::table('core_assessment_questions')->max('rubric_id'));

        $this->question($this->teacher, ['question_type' => 'speaking', 'rubric_id' => $rubric])->assertCreated();
        $questionId = (int) DB::table('core_assessment_questions')->max('id');
        $this->assertSame($rubric, (int) DB::table('core_assessment_questions')->where('id', $questionId)->value('rubric_id'));

        // Archived: the question keeps it when saved untouched.
        $this->actingAs($this->teacher)->post(self::TEACHER.'/rubrics/'.$rubric.'/toggle-status');
        $this->actingAs($this->teacher)->putJson(self::TEACHER.'/questions/'.$questionId, $this->questionPayload(['question_type' => 'speaking', 'rubric_id' => $rubric]), self::AJAX)->assertOk();
        $this->assertSame($rubric, (int) DB::table('core_assessment_questions')->where('id', $questionId)->value('rubric_id'));

        $this->actingAs($this->teacher)->deleteJson(self::TEACHER.'/rubrics', ['ids' => [$rubric]], self::AJAX)->assertStatus(409);
        $this->assertSame(1, DB::table('core_assessment_rubrics')->count());

        // Becoming an objective question drops the rubric; then it can be deleted.
        $this->actingAs($this->teacher)->putJson(self::TEACHER.'/questions/'.$questionId, $this->questionPayload(['question_type' => 'short_answer', 'rubric_id' => $rubric, 'accepted_answers' => ['x']]), self::AJAX)->assertOk();
        $this->assertNull(DB::table('core_assessment_questions')->where('id', $questionId)->value('rubric_id'));
        $this->actingAs($this->teacher)->deleteJson(self::TEACHER.'/rubrics', ['ids' => [$rubric]], self::AJAX)->assertOk();
        $this->assertSame(0, DB::table('core_assessment_rubric_items')->count());
    }

    public function test_publish_freezes_the_rubric_and_grading_scales_criteria_to_the_question_points(): void
    {
        $rubric = $this->rubric($this->admin, self::ADMIN, 'Viết câu 54', [
            ['title' => 'Nội dung', 'max_points' => '12', 'description' => 'Đủ ý'],
            ['title' => 'Bố cục', 'max_points' => '12'],
            ['title' => 'Ngôn ngữ', 'max_points' => '26'],
        ]);
        $this->question($this->admin, ['question_type' => 'essay', 'rubric_id' => $rubric, 'default_points' => '10'], self::ADMIN)->assertCreated();
        $essay = (int) DB::table('core_assessment_questions')->max('id');
        $quiz = $this->publishQuiz($this->admin, [$essay]);

        $vq = DB::table('core_assessment_quiz_version_questions')->where('question_id', $essay)->first();
        $snapshot = json_decode((string) $vq->rubric_snapshot, true);
        $this->assertSame(['Viết câu 54', 50.0], [$snapshot['title'], (float) $snapshot['total_points']]);
        $this->assertSame([[1, 'Nội dung', 12.0], [2, 'Bố cục', 12.0], [3, 'Ngôn ngữ', 26.0]],
            array_map(fn ($item) => [$item['key'], $item['title'], (float) $item['max_points']], $snapshot['items']));

        // Editing the rubric later changes nothing in the published version.
        $this->actingAs($this->admin)->putJson(self::ADMIN.'/rubrics/'.$rubric, [
            'title' => 'Đã đổi', 'status' => 'active', 'items' => [['title' => 'Một', 'max_points' => '5']],
        ], self::AJAX)->assertOk();
        $this->assertSame($vq->rubric_snapshot, DB::table('core_assessment_quiz_version_questions')->where('id', $vq->id)->value('rubric_snapshot'));

        $this->actingAs($this->admin)->postJson(self::ADMIN.'/registrations', ['quiz_id' => $quiz, 'student_ids' => [$this->student->id]], self::AJAX)->assertCreated();
        TenantContext::set(DB::table('saas_customers')->find($this->customerId));
        $attempts = app(AssessmentAttemptService::class);
        $attempt = $attempts->start($this->customerId, (int) DB::table('core_assessment_quiz_registrations')->max('id'));
        $attempts->saveAnswer($this->customerId, $attempt->id, $vq->id, ['text' => 'Bài viết']);
        $attempts->submit($this->customerId, $attempt->id);
        $answer = DB::table('core_assessment_answers')->where('attempt_id', $attempt->id)->first();
        $grade = self::ADMIN.'/results/'.$attempt->id.'/answers/'.$answer->id.'/grade';

        $this->actingAs($this->admin)->get(self::ADMIN.'/results/'.$attempt->id)->assertOk()
            ->assertSee('name="criteria[1]"', false)->assertSee('name="criteria[3]"', false)->assertSee('Đủ ý');

        // Every criterion is required and stays within its maximum.
        $this->actingAs($this->admin)->post($grade, ['criteria' => [1 => '10', 2 => '10']])->assertSessionHasErrors('criteria.3');
        $this->actingAs($this->admin)->post($grade, ['criteria' => [1 => '13', 2 => '10', 3 => '20']])->assertSessionHasErrors('criteria.1');
        $this->assertSame(0, DB::table('core_assessment_gradings')->count());

        // 40 / 50 on a 10-point question → 8; a plain score sent alongside is ignored.
        $this->actingAs($this->admin)->post($grade, ['score' => '10', 'criteria' => [1 => '10', 2 => '10', 3 => '20'], 'feedback' => 'Khá'])
            ->assertSessionHasNoErrors();
        $grading = DB::table('core_assessment_gradings')->where('is_current', true)->first();
        $this->assertEquals(8, (float) $grading->score);
        $this->assertSame([['key' => 1, 'score' => 10], ['key' => 2, 'score' => 10], ['key' => 3, 'score' => 20]],
            array_map(fn ($row) => ['key' => $row['key'], 'score' => (int) $row['score']], json_decode((string) $grading->rubric_result, true)));
        $this->assertEquals(8, (float) DB::table('core_assessment_attempts')->where('id', $attempt->id)->value('score'));

        // The student sees each criterion with the feedback once graded.
        $this->actingAs($this->student)->get('https://tenant-a.localhost/assessments/attempts/'.$attempt->id.'/result')->assertOk()
            ->assertSee('Viết câu 54')->assertSee('Ngôn ngữ')->assertSee('20 / 26')->assertSee('Khá');
    }

    public function test_score_rounds_and_never_exceeds_the_question_points(): void
    {
        $snapshot = ['total_points' => 3, 'items' => [['key' => 1, 'title' => 'A', 'max_points' => 1], ['key' => 2, 'title' => 'B', 'max_points' => 2]]];

        $this->assertSame(6.67, AssessmentRubricService::score($snapshot, [1 => 1, 2 => 1], 10)['score']);
        $this->assertSame(10.0, AssessmentRubricService::score($snapshot, [1 => 1, 2 => 2], 10)['score']);
        $this->expectException(ValidationException::class);
        AssessmentRubricService::score($snapshot, [1 => '0.555', 2 => 1], 10);
    }

    /** @param  list<array<string, string>>|null  $items */
    private function rubric(User $user, string $base, string $title, ?array $items = null): int
    {
        $this->actingAs($user)->postJson($base.'/rubrics', [
            'title' => $title, 'status' => 'active', 'skill_type' => 'writing',
            'items' => $items ?? [['title' => 'Nội dung', 'max_points' => '5'], ['title' => 'Ngôn ngữ', 'max_points' => '5']],
        ], self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_rubrics')->max('id');
    }

    private function questionPayload(array $overrides): array
    {
        return $overrides + ['question_type' => 'essay', 'prompt' => 'Viết đoạn văn', 'status' => 'published', 'default_points' => '4'];
    }

    private function question(User $user, array $overrides, string $base = self::TEACHER)
    {
        return $this->actingAs($user)->postJson($base.'/questions', $this->questionPayload($overrides), self::AJAX);
    }

    /** @param  list<int>  $questionIds */
    private function publishQuiz(User $user, array $questionIds): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $this->actingAs($user)->postJson($base.'/quizzes', [
            'title' => 'Đề viết', 'assessment_type' => 'exam', 'max_attempts' => '1', 'show_result_mode' => 'after_grading',
        ], self::AJAX)->assertCreated();
        $quiz = (int) DB::table('core_assessment_quizzes')->max('id');
        $section = (int) DB::table('core_assessment_quiz_sections')->where('quiz_id', $quiz)->value('id');
        $this->actingAs($user)->postJson($base.'/quizzes/'.$quiz.'/questions', ['quiz_section_id' => $section, 'question_ids' => $questionIds], self::AJAX)->assertCreated();
        $this->actingAs($user)->post($base.'/quizzes/'.$quiz.'/publish')->assertSessionHasNoErrors();

        return $quiz;
    }

    private function createTenant(string $slug): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
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
