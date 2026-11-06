<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Đề / Bài tập of the Kiểm tra module, step 1 (ADR-0025 D6, D9): the quiz draft settings. A new quiz gets one default
 * section; teachers own their quizzes; a quiz with a published version is never deleted.
 */
class AssessmentQuizManagementTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'https://tenant-a.localhost/admin/assessments/quizzes';

    private const TEACHER = 'https://tenant-a.localhost/teacher/assessments/quizzes';

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

    public function test_a_quiz_is_stored_as_a_draft_with_one_default_section(): void
    {
        $category = $this->category('TOPIK');

        $this->actingAs($this->teacher)->postJson(self::TEACHER, $this->payload([
            'title' => '  TOPIK I - Đề 01 ', 'description' => 'Giới thiệu', 'category_id' => $category, 'assessment_type' => 'exam',
            'duration_minutes' => '60', 'max_attempts' => '2', 'pass_score_percentage' => '70.5', 'show_result_mode' => 'score_only',
        ]), self::AJAX)->assertCreated()->assertJson(['message' => __('lf.LF_assessment_quiz_created')]);

        $quiz = DB::table('core_assessment_quizzes')->first();
        $this->assertSame(
            ['TOPIK I - Đề 01', 'Giới thiệu', $category, 'exam', 60, 2, '70.50', 'score_only', 'draft', null, 0, $this->teacher->id, $this->customerId],
            [$quiz->title, $quiz->description, (int) $quiz->category_id, $quiz->assessment_type, (int) $quiz->duration_minutes,
                (int) $quiz->max_attempts, number_format((float) $quiz->pass_score_percentage, 2, '.', ''), $quiz->show_result_mode,
                $quiz->status, $quiz->current_version_id, (int) $quiz->registration_open, (int) $quiz->created_by, (int) $quiz->customer_id]
        );
        $this->assertNotNull($quiz->content_updated_at);

        $sections = DB::table('core_assessment_quiz_sections')->where('quiz_id', $quiz->id)->get();
        $this->assertSame([[__('lf.LF_assessment_quiz_default_section'), 1, $this->customerId]],
            $sections->map(fn ($s) => [$s->title, (int) $s->sort_order, (int) $s->customer_id])->all());

        $this->actingAs($this->teacher)->get(self::TEACHER)->assertOk()
            ->assertSee('data-assessment-section="quizzes"', false)
            ->assertSeeText('TOPIK I - Đề 01')->assertSeeText(__('lf.LF_assessment_quiz_minutes', ['count' => 60]))
            ->assertSeeText(trans_choice('lf.LF_assessment_quiz_attempts_count', 2, ['count' => 2]));
        $this->actingAs($this->teacher)->get(self::TEACHER.'/'.$quiz->id.'/edit', self::AJAX)->assertOk()
            ->assertSee('value="TOPIK I - Đề 01"', false)->assertSee('value="70.5"', false)->assertSee('value="60"', false);
    }

    public function test_update_keeps_the_author_and_empty_optional_fields_mean_no_limit(): void
    {
        $id = $this->quiz($this->teacher, ['duration_minutes' => '45', 'pass_score_percentage' => '50']);

        $this->actingAs($this->admin)->putJson(self::ADMIN.'/'.$id, $this->payload([
            'title' => 'Đổi tên', 'duration_minutes' => '', 'pass_score_percentage' => '',
        ]), self::AJAX)->assertOk()->assertJson(['message' => __('lf.LF_assessment_quiz_updated')]);

        $quiz = DB::table('core_assessment_quizzes')->find($id);
        $this->assertSame(['Đổi tên', null, null, $this->teacher->id, $this->admin->id, 'draft'],
            [$quiz->title, $quiz->duration_minutes, $quiz->pass_score_percentage, (int) $quiz->created_by, (int) $quiz->updated_by, $quiz->status]);
        $this->assertSame(1, DB::table('core_assessment_quiz_sections')->where('quiz_id', $id)->count());
    }

    public function test_input_is_validated(): void
    {
        $this->actingAs($this->admin)->postJson(self::ADMIN, [
            'title' => '', 'assessment_type' => 'Exam', 'duration_minutes' => '0', 'max_attempts' => '0',
            'pass_score_percentage' => '100.5', 'show_result_mode' => 'later',
        ], self::AJAX)->assertUnprocessable()->assertJsonValidationErrors([
            'title', 'assessment_type', 'duration_minutes', 'max_attempts', 'pass_score_percentage', 'show_result_mode',
        ]);
        $this->actingAs($this->admin)->postJson(self::ADMIN, $this->payload(['pass_score_percentage' => '50.123']), self::AJAX)
            ->assertUnprocessable()->assertJsonValidationErrors(['pass_score_percentage']);
        $this->actingAs($this->admin)->postJson(self::ADMIN, $this->payload(['category_id' => $this->category('Cũ', 'archived')]), self::AJAX)
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
        // Status, version and registration follow publishing; the form cannot set them.
        $this->actingAs($this->admin)->postJson(self::ADMIN, $this->payload(['status' => 'published', 'registration_open' => 1]), self::AJAX)->assertCreated();
        $this->assertSame(['draft', 0], [DB::table('core_assessment_quizzes')->value('status'), (int) DB::table('core_assessment_quizzes')->value('registration_open')]);
        $this->assertSame(1, DB::table('core_assessment_quizzes')->count());
    }

    public function test_index_filters_by_keyword_type_and_status(): void
    {
        $this->quiz($this->admin, ['title' => 'Bài tập từ vựng', 'assessment_type' => 'exercise']);
        $this->quiz($this->admin, ['title' => 'Đề thi cuối kỳ', 'assessment_type' => 'exam']);

        $this->actingAs($this->admin)->get(self::ADMIN.'?keyword='.urlencode('vựng'))->assertOk()
            ->assertSeeText('Bài tập từ vựng')->assertDontSeeText('Đề thi cuối kỳ');
        $this->actingAs($this->admin)->get(self::ADMIN.'?assessment_type=exam')->assertOk()
            ->assertSeeText('Đề thi cuối kỳ')->assertDontSeeText('Bài tập từ vựng');
        $this->actingAs($this->admin)->get(self::ADMIN.'?status=archived')->assertOk()
            ->assertSeeText(__('lf.LF_assessment_quiz_filter_empty'));
    }

    public function test_teachers_reach_only_their_own_quizzes_and_admins_see_all(): void
    {
        $other = $this->createUser($this->customerId, 'teacher');
        $this->quiz($this->teacher, ['title' => 'Đề của tôi']);
        $theirs = $this->quiz($other, ['title' => 'Đề người khác']);

        $this->actingAs($this->teacher)->get(self::TEACHER)->assertOk()
            ->assertSeeText('Đề của tôi')->assertDontSeeText('Đề người khác');
        $this->actingAs($this->teacher)->get(self::TEACHER.'/'.$theirs.'/edit', self::AJAX)->assertNotFound();
        $this->actingAs($this->teacher)->putJson(self::TEACHER.'/'.$theirs, $this->payload(), self::AJAX)->assertNotFound();
        $this->actingAs($this->teacher)->deleteJson(self::TEACHER, ['ids' => [$theirs]], self::AJAX)->assertNotFound();
        $this->assertTrue(DB::table('core_assessment_quizzes')->where('id', $theirs)->exists());

        $this->actingAs($this->admin)->get(self::ADMIN)->assertOk()->assertSeeText('Đề của tôi')->assertSeeText('Đề người khác');
    }

    public function test_delete_removes_a_draft_with_its_sections_but_keeps_the_questions(): void
    {
        $id = $this->quiz($this->admin);
        $question = $this->question();
        $section = (int) DB::table('core_assessment_quiz_sections')->where('quiz_id', $id)->value('id');
        DB::table('core_assessment_quiz_questions')->insert([
            'customer_id' => $this->customerId, 'quiz_id' => $id, 'quiz_section_id' => $section, 'question_id' => $question,
            'sort_order' => 1, 'points' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->admin)->get(self::ADMIN)->assertOk()
            ->assertSee('data-label="'.__('lf.LF_assessment_quiz_question_count').'">1<', false);

        $this->actingAs($this->admin)->deleteJson(self::ADMIN, ['ids' => [$id]], self::AJAX)->assertOk()
            ->assertJson(['message' => trans_choice('lf.LF_assessment_quiz_bulk_deleted', 1, ['count' => 1])]);

        $this->assertSame(0, DB::table('core_assessment_quizzes')->count());
        $this->assertSame(0, DB::table('core_assessment_quiz_sections')->count());
        $this->assertSame(0, DB::table('core_assessment_quiz_questions')->count());
        $this->assertTrue(DB::table('core_assessment_questions')->where('id', $question)->exists());
    }

    public function test_a_published_quiz_cannot_be_deleted(): void
    {
        $draft = $this->quiz($this->admin, ['title' => 'Nháp']);
        $published = $this->quiz($this->admin, ['title' => 'Đã xuất bản']);
        $this->publish($published);

        $this->actingAs($this->admin)->get(self::ADMIN)->assertOk()
            ->assertSee(e(trans_choice('lf.LF_assessment_quiz_delete_blocked_versions', 1, ['count' => 1])), false)
            ->assertSeeText(__('lf.LF_assessment_quiz_status_published'));
        $this->actingAs($this->admin)->deleteJson(self::ADMIN, ['ids' => [$draft, $published]], self::AJAX)->assertStatus(409);
        $this->assertSame(2, DB::table('core_assessment_quizzes')->count());

        $this->actingAs($this->admin)->get(self::ADMIN.'/'.$published.'/edit', self::AJAX)->assertOk()
            ->assertSeeText(__('lf.LF_assessment_quiz_edit_published_note'));
    }

    public function test_another_tenant_and_students_cannot_reach_quizzes(): void
    {
        $id = $this->quiz($this->admin, ['title' => 'Bí mật']);
        $otherAdmin = $this->createUser($this->createTenant('tenant-b'), 'customer_admin');
        $base = 'https://tenant-b.localhost/admin/assessments/quizzes';

        $this->actingAs($otherAdmin)->get($base.'/'.$id.'/edit', self::AJAX)->assertNotFound();
        $this->actingAs($otherAdmin)->putJson($base.'/'.$id, $this->payload(), self::AJAX)->assertNotFound();
        $this->actingAs($otherAdmin)->deleteJson($base, ['ids' => [$id]], self::AJAX)->assertNotFound();
        $this->actingAs($otherAdmin)->get($base)->assertOk()->assertDontSeeText('Bí mật');

        $student = $this->createUser($this->customerId, 'student');
        $this->actingAs($student)->get(self::ADMIN)->assertForbidden();
        $this->actingAs($student)->postJson(self::TEACHER, $this->payload(), self::AJAX)->assertForbidden();
        $this->assertSame(1, DB::table('core_assessment_quizzes')->count());
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Đề mẫu',
            'assessment_type' => 'exercise',
            'max_attempts' => '1',
            'show_result_mode' => 'after_grading',
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function quiz(User $user, array $overrides = []): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $this->actingAs($user)->postJson($base, $this->payload($overrides), self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_quizzes')->max('id');
    }

    private function question(): int
    {
        $this->actingAs($this->admin)->postJson('https://tenant-a.localhost/admin/assessments/questions', [
            'question_type' => 'essay', 'default_points' => '1', 'status' => 'published', 'prompt' => 'Viết đoạn văn',
        ], self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_questions')->max('id');
    }

    /** Stands in for the publish step (built later): one frozen version made current. */
    private function publish(int $quizId): void
    {
        $now = now();
        $versionId = DB::table('core_assessment_quiz_versions')->insertGetId([
            'customer_id' => $this->customerId, 'quiz_id' => $quizId, 'version_no' => 1, 'title' => 'Đã xuất bản',
            'assessment_type' => 'exercise', 'max_attempts' => 1, 'show_result_mode' => 'after_grading',
            'total_points' => 0, 'question_count' => 0, 'sections_snapshot' => '[]',
            'published_by' => $this->admin->id, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('core_assessment_quizzes')->where('id', $quizId)->update(['current_version_id' => $versionId, 'status' => 'published']);
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
