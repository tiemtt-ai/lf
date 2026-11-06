<?php

namespace Tests\Feature\Assessment;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ghi danh, admin and teacher side (ADR-0025 D7–D9): granting a published quiz to students (one active registration
 * per student and quiz), the list and its filters, cancelling without deleting.
 */
class AssessmentRegistrationManagementTest extends TestCase
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

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
        ]);

        $this->customerId = $this->createTenant('tenant-a');
        $this->admin = $this->createUser($this->customerId, 'customer_admin');
        $this->teacher = $this->createUser($this->customerId, 'teacher');
    }

    public function test_granting_creates_one_active_registration_per_student_and_skips_holders(): void
    {
        $quiz = $this->publishedQuiz($this->admin, 'TOPIK 01', 3);
        $a = $this->createUser($this->customerId, 'student', 'An');
        $b = $this->createUser($this->customerId, 'student', 'Bình');

        $this->actingAs($this->admin)->get(self::ADMIN.'/registrations/create?quiz_id='.$quiz, self::AJAX)->assertOk()
            ->assertSeeText('TOPIK 01')->assertSeeText('An')->assertSeeText('Bình')
            ->assertSee('<option value="'.$quiz.'" selected', false);

        $this->grant($quiz, [$a->id], 'Lớp sáng')->assertCreated()
            ->assertJson(['message' => trans_choice('lf.LF_assessment_registration_granted', 1, ['count' => 1])]);
        $this->grant($quiz, [$a->id, $b->id])->assertCreated()
            ->assertJson(['message' => trans_choice('lf.LF_assessment_registration_granted', 1, ['count' => 1]).' '
                .trans_choice('lf.LF_assessment_registration_skipped', 1, ['count' => 1])]);

        $rows = DB::table('core_assessment_quiz_registrations')->orderBy('id')->get();
        $this->assertSame([[$a->id, 'granted', $this->admin->id, 'active', 0, 'Lớp sáng'], [$b->id, 'granted', $this->admin->id, 'active', 0, null]],
            $rows->map(fn ($r) => [(int) $r->student_id, $r->source, (int) $r->granted_by, $r->status, (int) $r->attempts_used, $r->note])->all());

        $this->actingAs($this->admin)->get(self::ADMIN.'/registrations')->assertOk()
            ->assertSee('data-assessment-section="registrations"', false)
            ->assertSeeText('An')->assertSeeText('TOPIK 01')->assertSeeText('0 / 3')
            ->assertSeeText(__('lf.LF_assessment_registration_status_active'));
        $this->actingAs($this->admin)->get(self::ADMIN.'/quizzes/'.$quiz)->assertOk()
            ->assertSeeText(trans_choice('lf.LF_assessment_registration_active_count', 2, ['count' => 2]))
            ->assertSee('registrations/create?quiz_id='.$quiz, false);
    }

    public function test_only_published_quizzes_and_active_students_of_the_tenant_can_be_granted(): void
    {
        $draft = $this->quiz($this->admin, 'Nháp');
        $published = $this->publishedQuiz($this->admin, 'Đã xuất bản');
        $student = $this->createUser($this->customerId, 'student');
        $inactive = $this->createUser($this->customerId, 'student', 'Ngừng', 'inactive');
        $teacherUser = $this->createUser($this->customerId, 'teacher');
        $foreign = $this->createUser($this->createTenant('tenant-b'), 'student');

        $this->grant($draft, [$student->id])->assertUnprocessable()->assertJsonValidationErrors(['quiz_id']);
        foreach ([$inactive, $teacherUser, $foreign] as $wrong) {
            $this->grant($published, [$student->id, $wrong->id])->assertUnprocessable()->assertJsonValidationErrors(['student_ids']);
        }
        $this->grant($published, [])->assertUnprocessable()->assertJsonValidationErrors(['student_ids']);

        DB::table('core_assessment_quizzes')->where('id', $published)->update(['status' => 'archived']);
        $this->grant($published, [$student->id])->assertUnprocessable()->assertJsonValidationErrors(['quiz_id']);
        $this->assertSame(0, DB::table('core_assessment_quiz_registrations')->count());

        $this->actingAs($this->admin)->get(self::ADMIN.'/registrations/create', self::AJAX)->assertOk()
            ->assertDontSeeText('Nháp')->assertDontSeeText('Ngừng');
    }

    public function test_cancel_keeps_the_row_and_allows_a_new_grant(): void
    {
        $quiz = $this->publishedQuiz($this->admin);
        $student = $this->createUser($this->customerId, 'student');
        $this->grant($quiz, [$student->id])->assertCreated();
        $id = (int) DB::table('core_assessment_quiz_registrations')->value('id');

        $this->actingAs($this->admin)->deleteJson(self::ADMIN.'/registrations', ['ids' => [$id]], self::AJAX)->assertOk()
            ->assertJson(['message' => trans_choice('lf.LF_assessment_registration_cancelled', 1, ['count' => 1])]);
        $row = DB::table('core_assessment_quiz_registrations')->find($id);
        $this->assertSame(['cancelled', $this->admin->id], [$row->status, (int) $row->cancelled_by]);
        $this->assertNotNull($row->cancelled_at);

        // Cancelling again is refused; a new grant makes a fresh active registration.
        $this->actingAs($this->admin)->deleteJson(self::ADMIN.'/registrations', ['ids' => [$id]], self::AJAX)->assertStatus(409);
        $this->grant($quiz, [$student->id])->assertCreated();
        $this->assertSame(['cancelled', 'active'], DB::table('core_assessment_quiz_registrations')->orderBy('id')->pluck('status')->all());
    }

    public function test_index_filters_by_student_quiz_source_and_status(): void
    {
        $q1 = $this->publishedQuiz($this->admin, 'Đề Một');
        $q2 = $this->publishedQuiz($this->admin, 'Đề Hai');
        $an = $this->createUser($this->customerId, 'student', 'Nguyễn An');
        $binh = $this->createUser($this->customerId, 'student', 'Trần Bình');
        $this->grant($q1, [$an->id])->assertCreated();
        $this->grant($q2, [$binh->id])->assertCreated();
        DB::table('core_assessment_quiz_registrations')->where('student_id', $binh->id)->update([
            'source' => 'self_registered', 'granted_by' => null,
        ]);

        $this->actingAs($this->admin)->get(self::ADMIN.'/registrations?keyword='.urlencode('Bình'))->assertOk()
            ->assertSeeText('Trần Bình')->assertDontSeeText('Nguyễn An');
        $this->actingAs($this->admin)->get(self::ADMIN.'/registrations?quiz_id='.$q1)->assertOk()
            ->assertSeeText('Nguyễn An')->assertDontSeeText('Trần Bình');
        $this->actingAs($this->admin)->get(self::ADMIN.'/registrations?source=self_registered')->assertOk()
            ->assertSeeText('Trần Bình')->assertDontSeeText('Nguyễn An');
        $this->actingAs($this->admin)->get(self::ADMIN.'/registrations?status=cancelled')->assertOk()
            ->assertSeeText(__('lf.LF_assessment_registration_filter_empty'));
    }

    public function test_teachers_grant_and_see_only_registrations_of_their_own_quizzes(): void
    {
        $other = $this->createUser($this->customerId, 'teacher');
        $mine = $this->publishedQuiz($this->teacher, 'Đề của tôi');
        $theirs = $this->publishedQuiz($other, 'Đề người khác');
        $an = $this->createUser($this->customerId, 'student', 'Học viên An');
        $binh = $this->createUser($this->customerId, 'student', 'Học viên Bình');

        $this->grant($mine, [$an->id], null, $this->teacher)->assertCreated();
        $this->grant($theirs, [$an->id], null, $this->teacher)->assertNotFound();
        $this->grant($theirs, [$binh->id], null, $other)->assertCreated();
        $theirRegistration = (int) DB::table('core_assessment_quiz_registrations')->where('quiz_id', $theirs)->value('id');

        $this->actingAs($this->teacher)->get(self::TEACHER.'/registrations')->assertOk()
            ->assertSeeText('Học viên An')->assertDontSeeText('Học viên Bình');
        $this->actingAs($this->teacher)->get(self::TEACHER.'/registrations/create', self::AJAX)->assertOk()
            ->assertSeeText('Đề của tôi')->assertDontSeeText('Đề người khác');
        $this->actingAs($this->teacher)->deleteJson(self::TEACHER.'/registrations', ['ids' => [$theirRegistration]], self::AJAX)->assertNotFound();
        $this->assertSame('active', DB::table('core_assessment_quiz_registrations')->where('id', $theirRegistration)->value('status'));

        $this->actingAs($this->admin)->get(self::ADMIN.'/registrations')->assertOk()
            ->assertSeeText('Học viên An')->assertSeeText('Học viên Bình');
    }

    public function test_another_tenant_and_students_cannot_reach_registrations(): void
    {
        $quiz = $this->publishedQuiz($this->admin);
        $student = $this->createUser($this->customerId, 'student', 'Học viên A');
        $this->grant($quiz, [$student->id])->assertCreated();
        $id = (int) DB::table('core_assessment_quiz_registrations')->value('id');
        $otherAdmin = $this->createUser($this->createTenant('tenant-b'), 'customer_admin');
        $base = 'https://tenant-b.localhost/admin/assessments/registrations';

        $this->actingAs($otherAdmin)->get($base)->assertOk()->assertDontSeeText('Học viên A');
        $this->actingAs($otherAdmin)->deleteJson($base, ['ids' => [$id]], self::AJAX)->assertNotFound();
        $this->actingAs($otherAdmin)->postJson($base, ['quiz_id' => $quiz, 'student_ids' => [$student->id]], self::AJAX)->assertNotFound();

        $this->actingAs($student)->get(self::ADMIN.'/registrations')->assertForbidden();
        $this->actingAs($student)->postJson(self::TEACHER.'/registrations', ['quiz_id' => $quiz, 'student_ids' => [$student->id]], self::AJAX)->assertForbidden();
        $this->assertSame(1, DB::table('core_assessment_quiz_registrations')->count());
    }

    // ---- fixtures ----

    /**
     * @param  list<int>  $studentIds
     */
    private function grant(int $quizId, array $studentIds, ?string $note = null, ?User $user = null): TestResponse
    {
        $user ??= $this->admin;
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;

        return $this->actingAs($user)->postJson($base.'/registrations', array_filter([
            'quiz_id' => $quizId, 'student_ids' => $studentIds, 'note' => $note,
        ], fn ($value) => $value !== null), self::AJAX);
    }

    private function quiz(User $user, string $title = 'Đề mẫu', int $attempts = 1): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $this->actingAs($user)->postJson($base.'/quizzes', [
            'title' => $title, 'assessment_type' => 'exam', 'max_attempts' => (string) $attempts, 'show_result_mode' => 'after_grading',
        ], self::AJAX)->assertCreated();

        return (int) DB::table('core_assessment_quizzes')->max('id');
    }

    private function publishedQuiz(User $user, string $title = 'Đề mẫu', int $attempts = 1): int
    {
        $base = $user->role === 'customer_admin' ? self::ADMIN : self::TEACHER;
        $quiz = $this->quiz($user, $title, $attempts);
        $this->actingAs($user)->postJson($base.'/questions', [
            'question_type' => 'essay', 'default_points' => '1', 'status' => 'published', 'prompt' => 'Viết đoạn văn',
        ], self::AJAX)->assertCreated();
        $question = (int) DB::table('core_assessment_questions')->max('id');
        $section = (int) DB::table('core_assessment_quiz_sections')->where('quiz_id', $quiz)->value('id');
        $this->actingAs($user)->postJson($base.'/quizzes/'.$quiz.'/questions', ['quiz_section_id' => $section, 'question_ids' => [$question]], self::AJAX)
            ->assertCreated();
        $this->actingAs($user)->post($base.'/quizzes/'.$quiz.'/publish')->assertSessionHasNoErrors();

        return $quiz;
    }

    private function createTenant(string $slug): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createUser(int $customerId, string $role, ?string $name = null, string $status = 'active'): User
    {
        return User::forceCreate([
            'customer_id' => $customerId,
            'name' => $name ?? ucfirst(str_replace('_', ' ', $role)).' '.uniqid(),
            'email' => $role.'-'.$customerId.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'),
            'role' => $role,
            'status' => $status,
            'email_verified_at' => now(),
        ]);
    }
}
