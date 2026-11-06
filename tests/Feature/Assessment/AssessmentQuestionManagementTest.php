<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Câu hỏi of the Kiểm tra module (ADR-0025 D3–D5): six types entered by form, publish rules per type,
 * teachers own their questions, admins see the whole tenant, a question in a quiz is never deleted.
 */
class AssessmentQuestionManagementTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'https://tenant-a.localhost/admin/assessments/questions';

    private const TEACHER = 'https://tenant-a.localhost/teacher/assessments/questions';

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
        ]);

        $this->customerId = $this->createTenant('tenant-a');
        $this->admin = $this->createUser($this->customerId, 'customer_admin');
        $this->teacher = $this->createUser($this->customerId, 'teacher');
    }

    public function test_single_choice_is_stored_with_content_and_ordered_options(): void
    {
        $category = $this->category('TOPIK I');

        $this->actingAs($this->teacher)->postJson(self::TEACHER, $this->payload([
            'category_id' => $category,
            'skill_type' => 'reading',
            'difficulty_level' => 'easy',
            'default_points' => '2.5',
            'status' => 'published',
            'prompt' => '  Thủ đô của Hàn Quốc?  ',
            'explanation' => 'Seoul là thủ đô.',
            'options' => [
                ['text' => 'Busan', 'correct' => 0],
                ['text' => 'Seoul', 'correct' => 1],
                ['text' => '', 'correct' => 0],
                ['text' => 'Incheon', 'correct' => 0],
            ],
        ]), self::AJAX)->assertCreated()->assertJson(['message' => __('lf.LF_assessment_question_created')]);

        $question = DB::table('core_assessment_questions')->first();
        $this->assertSame(
            ['single_choice', $category, 'reading', 'easy', '2.50', 'published', $this->teacher->id, $this->customerId],
            [$question->question_type, (int) $question->category_id, $question->skill_type, $question->difficulty_level,
                number_format((float) $question->default_points, 2, '.', ''), $question->status, (int) $question->created_by, (int) $question->customer_id],
        );
        $content = DB::table('core_assessment_question_contents')->where('question_id', $question->id)->first();
        $this->assertSame(['vi', 'Thủ đô của Hàn Quốc?', 'Seoul là thủ đô.', null], [$content->locale, $content->prompt, $content->explanation, $content->reference_answer]);
        $this->assertSame(
            [['Busan', 0, 1], ['Seoul', 1, 2], ['Incheon', 0, 3]],
            DB::table('core_assessment_question_options')->where('question_id', $question->id)->orderBy('sort_order')->get()
                ->map(fn ($option) => [$option->option_text, (int) $option->is_correct, (int) $option->sort_order])->all(),
        );
    }

    public function test_publish_rules_follow_the_question_type(): void
    {
        $cases = [
            ['single_choice', ['options' => [['text' => 'A', 'correct' => 0], ['text' => 'B', 'correct' => 0]]]],
            ['single_choice', ['options' => [['text' => 'A', 'correct' => 1]]]],
            ['multiple_choice', ['options' => [['text' => 'A', 'correct' => 0], ['text' => 'B', 'correct' => 0]]]],
            ['true_false', ['true_false_answer' => null]],
            ['short_answer', ['accepted_answers' => ['', '  ']]],
        ];

        foreach ($cases as [$type, $extra]) {
            $this->actingAs($this->admin)
                ->postJson(self::ADMIN, $this->payload(['question_type' => $type, 'status' => 'published'] + $extra), self::AJAX)
                ->assertUnprocessable()->assertJsonValidationErrors('options');

            // The same incomplete question saves as a draft.
            $this->actingAs($this->admin)
                ->postJson(self::ADMIN, $this->payload(['question_type' => $type, 'status' => 'draft'] + $extra), self::AJAX)
                ->assertCreated();
        }

        $this->assertSame(0, DB::table('core_assessment_questions')->where('status', 'published')->count());
        $this->assertSame(count($cases), DB::table('core_assessment_questions')->where('status', 'draft')->count());
    }

    public function test_single_choice_never_stores_two_correct_answers_even_as_draft(): void
    {
        $this->actingAs($this->admin)->postJson(self::ADMIN, $this->payload([
            'status' => 'draft',
            'options' => [['text' => 'A', 'correct' => 1], ['text' => 'B', 'correct' => 1]],
        ]), self::AJAX)->assertUnprocessable()->assertJsonValidationErrors('options');
    }

    public function test_true_false_short_answer_and_essay_store_the_right_answer_shape(): void
    {
        $this->actingAs($this->admin)->postJson(self::ADMIN, $this->payload([
            'question_type' => 'true_false', 'status' => 'published', 'true_false_answer' => 'false',
            'options' => [['text' => 'leftover from another type', 'correct' => 1]],
        ]), self::AJAX)->assertCreated();
        $this->actingAs($this->admin)->postJson(self::ADMIN, $this->payload([
            'question_type' => 'short_answer', 'status' => 'published', 'accepted_answers' => ['서울', ' Seoul ', 'seoul', ''],
        ]), self::AJAX)->assertCreated();
        $this->actingAs($this->admin)->postJson(self::ADMIN, $this->payload([
            'question_type' => 'essay', 'status' => 'published', 'reference_answer' => 'Bài mẫu',
            'options' => [['text' => 'ignored', 'correct' => 1]],
        ]), self::AJAX)->assertCreated();

        [$trueFalse, $short, $essay] = DB::table('core_assessment_questions')->orderBy('id')->pluck('id')->all();
        $options = fn (int $id) => DB::table('core_assessment_question_options')->where('question_id', $id)->orderBy('sort_order')
            ->get()->map(fn ($option) => [$option->option_text, (int) $option->is_correct])->all();

        $this->assertSame([[__('lf.LF_assessment_question_true'), 0], [__('lf.LF_assessment_question_false'), 1]], $options($trueFalse));
        $this->assertSame([['서울', 1], ['Seoul', 1]], $options($short));
        $this->assertSame([], $options($essay));
        $this->assertSame('Bài mẫu', DB::table('core_assessment_question_contents')->where('question_id', $essay)->value('reference_answer'));
    }

    public function test_input_values_must_be_the_exact_lowercase_codes(): void
    {
        foreach ([['question_type' => 'Essay'], ['status' => 'Published'], ['skill_type' => 'Reading'], ['difficulty_level' => 'HARD'], ['default_points' => '-1'], ['default_points' => '1.234'], ['prompt' => '']] as $bad) {
            $this->actingAs($this->admin)->postJson(self::ADMIN, $this->payload($bad), self::AJAX)
                ->assertUnprocessable()->assertJsonValidationErrors(array_key_first($bad));
        }

        $this->assertSame(0, DB::table('core_assessment_questions')->count());
    }

    public function test_category_must_be_active_and_of_this_tenant_but_an_archived_one_can_be_kept(): void
    {
        $archived = $this->category('Cũ', 'archived');
        $foreign = $this->category('Khác', 'active', $this->createTenant('tenant-b'));

        foreach ([$archived, $foreign] as $categoryId) {
            $this->actingAs($this->admin)->postJson(self::ADMIN, $this->payload(['category_id' => $categoryId]), self::AJAX)
                ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        }

        $id = $this->storedQuestion($this->admin, ['category_id' => null]);
        DB::table('core_assessment_questions')->where('id', $id)->update(['category_id' => $archived]);
        $this->actingAs($this->admin)->putJson(self::ADMIN.'/'.$id, $this->payload(['category_id' => $archived, 'prompt' => 'Đã sửa']), self::AJAX)
            ->assertOk();
        $this->assertSame('Đã sửa', DB::table('core_assessment_question_contents')->where('question_id', $id)->value('prompt'));
    }

    public function test_update_changes_type_rewrites_options_and_keeps_the_author(): void
    {
        $id = $this->storedQuestion($this->teacher);

        $this->actingAs($this->admin)->putJson(self::ADMIN.'/'.$id, $this->payload([
            'question_type' => 'short_answer', 'accepted_answers' => ['một'],
        ]), self::AJAX)->assertOk()->assertJson(['message' => __('lf.LF_assessment_question_updated')]);

        $question = DB::table('core_assessment_questions')->find($id);
        $this->assertSame(['short_answer', $this->teacher->id, $this->admin->id], [$question->question_type, (int) $question->created_by, (int) $question->updated_by]);
        $this->assertSame(['một'], DB::table('core_assessment_question_options')->where('question_id', $id)->pluck('option_text')->all());
        $this->assertSame(1, DB::table('core_assessment_question_contents')->where('question_id', $id)->count());
    }

    public function test_teachers_see_and_edit_only_their_own_questions_and_admins_see_all(): void
    {
        $other = $this->createUser($this->customerId, 'teacher');
        $mine = $this->storedQuestion($this->teacher, ['prompt' => 'Câu của tôi']);
        $theirs = $this->storedQuestion($other, ['prompt' => 'Câu của người khác']);

        $this->actingAs($this->teacher)->get(self::TEACHER)->assertOk()
            ->assertSeeText('Câu của tôi')->assertDontSeeText('Câu của người khác')
            ->assertDontSeeText(__('lf.LF_assessment_question_author'));
        $this->actingAs($this->teacher)->get(self::TEACHER.'/'.$theirs.'/edit', self::AJAX)->assertNotFound();
        $this->actingAs($this->teacher)->putJson(self::TEACHER.'/'.$theirs, $this->payload(), self::AJAX)->assertNotFound();
        $this->actingAs($this->teacher)->deleteJson(self::TEACHER, ['ids' => [$theirs]], self::AJAX)->assertNotFound();
        $this->actingAs($this->teacher)->get(self::TEACHER.'/'.$mine.'/edit', self::AJAX)->assertOk()->assertSee('Câu của tôi', false);

        $this->actingAs($this->admin)->get(self::ADMIN)->assertOk()
            ->assertSeeText('Câu của tôi')->assertSeeText('Câu của người khác')
            ->assertSeeText(__('lf.LF_assessment_question_author'));

        $this->assertSame('Câu của người khác', DB::table('core_assessment_question_contents')->where('question_id', $theirs)->value('prompt'));
    }

    public function test_index_filters_by_keyword_type_category_and_status(): void
    {
        $category = $this->category('Nghe');
        $this->storedQuestion($this->admin, ['prompt' => 'Câu nghe số một', 'category_id' => $category]);
        $this->storedQuestion($this->admin, ['prompt' => 'Câu viết', 'question_type' => 'essay', 'status' => 'draft']);

        $this->actingAs($this->admin)->get(self::ADMIN.'?keyword=nghe')->assertOk()->assertSeeText('Câu nghe số một')->assertDontSeeText('Câu viết');
        $this->actingAs($this->admin)->get(self::ADMIN.'?question_type=essay')->assertOk()->assertSeeText('Câu viết')->assertDontSeeText('Câu nghe số một');
        $this->actingAs($this->admin)->get(self::ADMIN.'?category_id='.$category)->assertOk()->assertSeeText('Câu nghe số một')->assertDontSeeText('Câu viết');
        $this->actingAs($this->admin)->get(self::ADMIN.'?status=draft')->assertOk()->assertSeeText('Câu viết')->assertDontSeeText('Câu nghe số một');
    }

    public function test_edit_dialog_restores_each_answer_shape(): void
    {
        $trueFalse = $this->storedQuestion($this->admin, ['question_type' => 'true_false', 'true_false_answer' => 'false']);
        $short = $this->storedQuestion($this->admin, ['question_type' => 'short_answer', 'accepted_answers' => ['xin chào']]);

        $this->actingAs($this->admin)->get(self::ADMIN.'/'.$trueFalse.'/edit', self::AJAX)->assertOk()
            ->assertSee('name="true_false_answer" value="false" checked', false);
        $this->actingAs($this->admin)->get(self::ADMIN.'/'.$short.'/edit', self::AJAX)->assertOk()
            ->assertSee('answers: '.Js::from(collect(['xin chào'])), false);
        $this->actingAs($this->admin)->get(self::ADMIN.'/create')->assertNotFound();
        // A new question starts with two empty choices to type into.
        $this->actingAs($this->admin)->get(self::ADMIN.'/create', self::AJAX)->assertOk()
            ->assertSee('options: '.Js::from(collect([['text' => '', 'correct' => false], ['text' => '', 'correct' => false]])), false);
    }

    public function test_delete_refuses_questions_used_by_a_quiz_and_removes_children_of_free_ones(): void
    {
        $used = $this->storedQuestion($this->admin);
        $free = $this->storedQuestion($this->admin);
        $this->putInQuiz($used);

        $this->actingAs($this->admin)->deleteJson(self::ADMIN, ['ids' => [$free, $used]], self::AJAX)->assertStatus(409);
        $this->assertSame(2, DB::table('core_assessment_questions')->count());

        $this->actingAs($this->admin)->deleteJson(self::ADMIN, ['ids' => [$free]], self::AJAX)->assertOk();
        $this->assertFalse(DB::table('core_assessment_questions')->where('id', $free)->exists());
        $this->assertSame(0, DB::table('core_assessment_question_contents')->where('question_id', $free)->count());
        $this->assertSame(0, DB::table('core_assessment_question_options')->where('question_id', $free)->count());
    }

    public function test_another_tenant_cannot_reach_the_questions(): void
    {
        $id = $this->storedQuestion($this->admin);
        $otherAdmin = $this->createUser($this->createTenant('tenant-b'), 'customer_admin');

        $this->actingAs($otherAdmin)->get('https://tenant-b.localhost/admin/assessments/questions/'.$id.'/edit', self::AJAX)->assertNotFound();
        $this->actingAs($otherAdmin)->putJson('https://tenant-b.localhost/admin/assessments/questions/'.$id, $this->payload(), self::AJAX)->assertNotFound();
        $this->actingAs($otherAdmin)->deleteJson('https://tenant-b.localhost/admin/assessments/questions', ['ids' => [$id]], self::AJAX)->assertNotFound();
        $this->actingAs($otherAdmin)->get('https://tenant-b.localhost/admin/assessments/questions')->assertOk()->assertDontSeeText('Câu hỏi mẫu');
    }

    public function test_students_cannot_use_the_question_pages(): void
    {
        $student = $this->createUser($this->customerId, 'student');

        $this->actingAs($student)->get(self::ADMIN)->assertForbidden();
        $this->actingAs($student)->postJson(self::TEACHER, $this->payload(), self::AJAX)->assertForbidden();
        $this->assertSame(0, DB::table('core_assessment_questions')->count());
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'question_type' => 'single_choice',
            'category_id' => null,
            'default_points' => '1',
            'status' => 'published',
            'prompt' => 'Câu hỏi mẫu',
            'options' => [['text' => 'Một', 'correct' => 1], ['text' => 'Hai', 'correct' => 0]],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function storedQuestion(User $user, array $overrides = []): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $this->actingAs($user)->postJson($base, $this->payload($overrides), self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_questions')->max('id');
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

    private function category(string $name, string $status = 'active', ?int $customerId = null): int
    {
        return DB::table('core_assessment_categories')->insertGetId([
            'customer_id' => $customerId ?? $this->customerId,
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
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
