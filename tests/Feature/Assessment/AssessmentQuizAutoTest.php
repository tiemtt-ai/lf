<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ghép tự động (ADR-0025 D6a): a random preview per skill × level cell from the questions the picker would list,
 * within a category (and its children), saving nothing; the preview is confirmed through the "Thêm câu hỏi" route.
 */
class AssessmentQuizAutoTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'https://tenant-a.localhost/admin/assessments';

    private const TEACHER = 'https://tenant-a.localhost/teacher/assessments';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private int $customerId;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://localhost', 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);

        $this->customerId = $this->createTenant('tenant-a');
        $this->admin = $this->createUser($this->customerId, 'customer_admin');
        $this->teacher = $this->createUser($this->customerId, 'teacher');
    }

    public function test_the_popup_shows_the_skill_and_level_table_next_to_add_questions(): void
    {
        $quiz = $this->quiz($this->admin);
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/'.$quiz)->assertOk()
            ->assertSee(self::ADMIN.'/quizzes/'.$quiz.'/questions/auto', false)->assertSeeText(__('lf.LF_assessment_quiz_auto'));
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/'.$quiz.'/questions/auto', self::AJAX)->assertOk()
            ->assertSee('name="counts[reading][easy]"', false)->assertSee('name="counts[other][hard]"', false)
            ->assertSee('name="with_children"', false)->assertSee(self::ADMIN.'/quizzes/'.$quiz.'/questions/auto/preview', false);
        // Only as a popup.
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/'.$quiz.'/questions/auto')->assertNotFound();
    }

    public function test_preview_picks_per_cell_within_the_category_and_its_children_and_says_what_is_short(): void
    {
        $parent = $this->category('Tiếng Hàn');
        $child = $this->category('TOPIK I', $parent);
        $other = $this->category('Tiếng Anh');
        $readEasy = [
            $this->question($this->admin, 'Đọc dễ 1', 'reading', 'easy', $parent),
            $this->question($this->admin, 'Đọc dễ 2', 'reading', 'easy', $child),
            $this->question($this->admin, 'Đọc dễ 3', 'reading', 'easy', $child),
        ];
        $listenHard = $this->question($this->admin, 'Nghe khó', 'listening', 'hard', $child);
        $this->question($this->admin, 'Đọc dễ khác danh mục', 'reading', 'easy', $other);
        $this->question($this->admin, 'Đọc dễ nháp', 'reading', 'easy', $parent, 'draft');
        $this->question($this->admin, 'Không ghi kỹ năng', null, 'easy', $parent);
        $passage = $this->passage($this->admin, 'Bài đọc');
        $this->question($this->admin, 'Câu con', 'reading', 'easy', $parent, 'published', $passage);
        $quiz = $this->quiz($this->admin);
        $inQuiz = $readEasy[0];
        $this->add($quiz, [$inQuiz])->assertCreated();

        // Category with children: the two other easy reading questions; the one in the quiz, the draft, another
        // category, a question without skill and a passage child are never picked.
        $response = $this->preview($quiz, ['reading' => ['easy' => 5], 'listening' => ['hard' => 1]], $parent)->assertOk();
        $ids = array_column($response->json('questions'), 'id');
        $this->assertEqualsCanonicalizing([$readEasy[1], $readEasy[2], $listenHard], $ids);
        $response->assertJsonPath('wanted', 6)->assertJsonCount(1, 'short')->assertJsonPath('short.0', __('lf.LF_assessment_quiz_auto_short', [
            'cell' => __('lf.LF_assessment_question_skill_reading').' · '.__('lf.LF_assessment_question_level_easy'), 'wanted' => 5, 'found' => 2,
        ]));
        // Listed by cell: reading before listening.
        $this->assertSame($listenHard, end($ids));

        // Without children only the parent itself, which has nothing left.
        $this->assertSame([], $this->preview($quiz, ['reading' => ['easy' => 5]], $parent, false)->assertOk()->json('questions'));
        // Limits are random but never above the wanted number.
        $this->assertCount(1, $this->preview($quiz, ['reading' => ['easy' => 1]], $parent)->json('questions'));

        // Nothing is saved by a preview.
        $this->assertSame([$inQuiz], DB::table('core_assessment_quiz_questions')->where('quiz_id', $quiz)->pluck('question_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_confirming_adds_the_kept_questions_in_preview_order_with_default_points(): void
    {
        $quiz = $this->quiz($this->admin);
        $a = $this->question($this->admin, 'Viết 1', 'writing', 'medium', null, 'published', null, '4');
        $b = $this->question($this->admin, 'Đọc 1', 'reading', 'easy', null, 'published', null, '2');

        $this->add($quiz, [$a, $b])->assertCreated();
        $this->assertSame([[$a, '4.00'], [$b, '2.00']], DB::table('core_assessment_quiz_questions')->where('quiz_id', $quiz)
            ->orderBy('sort_order')->get()->map(fn ($row) => [(int) $row->question_id, number_format((float) $row->points, 2, '.', '')])->all());
    }

    public function test_the_table_must_ask_for_something_and_not_too_much(): void
    {
        $quiz = $this->quiz($this->admin);
        $this->preview($quiz, [])->assertUnprocessable()->assertJsonPath('errors.counts.0', __('lf.LF_assessment_quiz_auto_error_none'));
        $this->preview($quiz, ['reading' => ['easy' => 150], 'writing' => ['hard' => 51]])->assertUnprocessable()
            ->assertJsonPath('errors.counts.0', __('lf.LF_assessment_quiz_auto_error_max', ['max' => 200]));
        $this->preview($quiz, ['reading' => ['easy' => -1]])->assertUnprocessable()->assertJsonValidationErrors(['counts.reading.easy']);
        $this->preview($quiz, ['reading' => ['easy' => 1]], 999999)->assertNotFound();
    }

    public function test_teachers_pick_only_their_own_questions_and_other_tenants_nothing(): void
    {
        $mine = $this->question($this->teacher, 'Của giáo viên', 'speaking', 'easy');
        $this->question($this->admin, 'Của admin', 'speaking', 'easy');
        $quiz = $this->quiz($this->teacher);

        $ids = array_column($this->preview($quiz, ['speaking' => ['easy' => 5]], null, true, $this->teacher)->assertOk()->json('questions'), 'id');
        $this->assertSame([$mine], $ids);

        // A quiz of someone else is out of reach.
        $adminQuiz = $this->quiz($this->admin);
        $this->preview($adminQuiz, ['speaking' => ['easy' => 1]], null, true, $this->teacher)->assertNotFound();

        $otherTenant = $this->createTenant('tenant-b');
        $stranger = $this->createUser($otherTenant, 'customer_admin');
        $this->actingAs($stranger)->postJson('https://tenant-b.localhost/admin/assessments/quizzes/'.$quiz.'/questions/auto/preview',
            ['counts' => ['speaking' => ['easy' => 1]]], self::AJAX)->assertNotFound();
    }

    /** @param  array<string, array<string, int>>  $counts */
    private function preview(int $quizId, array $counts, ?int $categoryId = null, bool $withChildren = true, ?User $user = null): TestResponse
    {
        $user ??= $this->admin;
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;

        return $this->actingAs($user)->postJson($base.'/quizzes/'.$quizId.'/questions/auto/preview', array_filter([
            'category_id' => $categoryId,
            'with_children' => $withChildren ? '1' : '0',
            'counts' => $counts,
        ], fn ($value) => $value !== null), self::AJAX);
    }

    /** @param  list<int>  $questionIds */
    private function add(int $quizId, array $questionIds): TestResponse
    {
        $section = (int) DB::table('core_assessment_quiz_sections')->where('quiz_id', $quizId)->value('id');

        return $this->actingAs($this->admin)->postJson(self::ADMIN.'/quizzes/'.$quizId.'/questions', [
            'quiz_section_id' => $section, 'question_ids' => $questionIds,
        ], self::AJAX);
    }

    private function quiz(User $user): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $this->actingAs($user)->postJson($base.'/quizzes', [
            'title' => 'Đề ghép', 'assessment_type' => 'exam', 'max_attempts' => '1', 'show_result_mode' => 'after_grading',
        ], self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_quizzes')->max('id');
    }

    private function category(string $name, ?int $parentId = null): int
    {
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/categories', array_filter([
            'name' => $name, 'parent_id' => $parentId, 'status' => 'active',
        ], fn ($value) => $value !== null), self::AJAX)->assertSuccessful();

        return (int) DB::table('core_assessment_categories')->max('id');
    }

    private function question(User $user, string $prompt, ?string $skill, ?string $level, ?int $categoryId = null,
        string $status = 'published', ?int $passageId = null, string $points = '1'): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $this->actingAs($user)->post($base.'/questions', array_filter([
            'question_type' => 'single_choice',
            'default_points' => $points,
            'status' => $status,
            'prompt' => $prompt,
            'skill_type' => $skill,
            'difficulty_level' => $level,
            'category_id' => $categoryId,
            'passage_id' => $passageId,
            'options' => [['text' => 'Một', 'correct' => 1], ['text' => 'Hai', 'correct' => 0]],
        ], fn ($value) => $value !== null), self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_questions')->max('id');
    }

    private function passage(User $user, string $title): int
    {
        $this->actingAs($user)->post(self::ADMIN.'/passages', ['title' => $title, 'status' => 'published'], self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_passages')->max('id');
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
