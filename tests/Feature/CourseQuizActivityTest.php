<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Quiz Activity of a course (ADR-0032): the attempt comes from the Enrollment on the quiz version frozen at publish,
 * attempts are counted per Enrollment + Activity, and the Activity completes by its own rule.
 */
class CourseQuizActivityTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://tenant-a.localhost';

    private const ADMIN = 'https://tenant-a.localhost/admin/assessments';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private int $customer;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://localhost', 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);
        $this->customer = DB::table('saas_customers')->insertGetId([
            'name' => 'tenant-a', 'slug' => 'tenant-a', 'subdomain' => 'tenant-a', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = $this->user('customer_admin', 'Quản trị');
        $this->student = $this->user('student', 'Học viên Lan');
    }

    public function test_attempts_use_the_frozen_version_and_count_per_enrollment_until_the_activity_passes(): void
    {
        [$quiz, $version] = $this->quiz(['title' => 'Kiểm tra bài 1', 'max_attempts' => '2']);
        [$product, $courseVersion, $lesson] = $this->course('Sơ cấp tự học');
        $activity = $this->quizActivity($courseVersion, $lesson, $quiz, $version, ['completion_rule' => 'pass', 'completion_threshold' => 50]);
        $enrollment = $this->enroll($this->student, $product, $courseVersion);
        $lessonUrl = self::HOST.'/my-courses/'.$enrollment.'/lessons/'.$lesson;
        $start = self::HOST.'/my-courses/'.$enrollment.'/activities/'.$activity.'/attempts';

        $this->actingAs($this->student)->get($lessonUrl)->assertOk()
            ->assertSee('data-learn-quiz="'.$activity.'"', false)
            ->assertSeeText(__('lf.LF_my_learning_quiz_attempts', ['used' => 0, 'max' => 2]))
            ->assertSeeText(__('lf.LF_my_learning_quiz_start'))
            ->assertSeeText(__('lf.LF_my_learning_rule_passed', ['percent' => 50]));

        // The quiz is republished: the course keeps the version it was published with.
        $newer = $this->republish($quiz, $version);
        $first = $this->startAttempt($start);
        $this->assertSame(
            [$version, $enrollment, $product, $courseVersion, $activity, null, 1],
            [(int) $first->quiz_version_id, (int) $first->enrollment_id, (int) $first->product_id, (int) $first->template_version_id,
                (int) $first->version_activity_id, $first->quiz_registration_id, (int) $first->attempt_no]
        );
        $this->assertNotSame($newer, (int) $first->quiz_version_id);
        // The take page leads back to the lesson and does not light the "Bài đánh giá" menu.
        $this->actingAs($this->student)->get(self::HOST.'/assessments/attempts/'.$first->id)->assertOk()->assertSeeText('Chọn đáp án đúng')
            ->assertSee('class="lf-exam-back" href="'.$lessonUrl.'"', false)->assertDontSee('class="is-active" href="'.self::HOST.'/assessments"', false);
        // Starting again resumes the open attempt.
        $this->assertSame($first->id, $this->startAttempt($start)->id);
        $this->assertSame(1, DB::table('core_assessment_attempts')->count());
        $this->assertSame(['in_progress', 1], [$this->progress($enrollment, $activity)->status, (int) $this->progress($enrollment, $activity)->attempt_count]);

        // A wrong answer is graded 0%: below the Activity's 50%.
        $this->submit($first->id, ['1']);
        $row = $this->progress($enrollment, $activity);
        $this->assertSame(['in_progress', 'passed', 0, '0.00'], [$row->status, $row->completion_rule, (int) $row->passed, number_format((float) $row->score, 2, '.', '')]);
        $course = DB::table('core_course_progress')->where('enrollment_id', $enrollment)->sole();
        $this->assertSame([0, 1], [(int) $course->assessment_completed, (int) $course->assessment_total]);
        $this->actingAs($this->student)->get(self::HOST.'/assessments/attempts/'.$first->id.'/result')->assertOk()
            ->assertSee($lessonUrl, false)->assertSee($start, false)->assertDontSee('class="is-active" href="'.self::HOST.'/assessments"', false);

        $second = $this->startAttempt($start);
        $this->assertSame(2, (int) $second->attempt_no);
        $this->submit($second->id, ['2']);
        $row = $this->progress($enrollment, $activity);
        $this->assertSame(['completed', 1, 2, '2.00'], [$row->status, (int) $row->passed, (int) $row->attempt_count, number_format((float) $row->score, 2, '.', '')]);
        $course = DB::table('core_course_progress')->where('enrollment_id', $enrollment)->sole();
        $this->assertSame(['completed', 1, 1, 1, 1], [$course->status, (int) $course->completed_activities, (int) $course->total_activities,
            (int) $course->assessment_completed, (int) $course->assessment_total]);

        // No attempt is left: the lesson says so and starting is refused.
        $this->actingAs($this->student)->get($lessonUrl)->assertOk()
            ->assertSeeText(__('lf.LF_my_learning_quiz_no_attempts'))->assertSeeText('2 / 2 (100%)');
        $this->actingAs($this->student)->from($lessonUrl)->post($start)->assertRedirect($lessonUrl)->assertSessionHasErrors('attempt');
        $this->assertSame(2, DB::table('core_assessment_attempts')->count());
        $this->actingAs($this->student)->get(self::HOST.'/assessments/attempts/'.$second->id.'/result')->assertOk()->assertDontSee($start, false);

        // "Bài thi của tôi" lists registrations only (and lights the menu); "Kết quả học tập" shows the course quiz with its course.
        $this->actingAs($this->student)->get(self::HOST.'/assessments/my')->assertOk()->assertDontSeeText('Kiểm tra bài 1')
            ->assertSee('class="is-active" href="'.self::HOST.'/assessments"', false);
        $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=results&view=exams')->assertOk()
            ->assertSee('data-results-row="course-'.$enrollment.'-'.$activity.'"', false)
            ->assertSeeText('Sơ cấp tự học')->assertSeeText(__('lf.LF_my_learning_results_in_course'));
    }

    public function test_only_the_learner_with_access_may_start_and_a_cancelled_enrollment_takes_no_answer(): void
    {
        [$quiz, $version] = $this->quiz(['title' => 'Kiểm tra bài 1', 'max_attempts' => '3']);
        [$product, $courseVersion, $lesson] = $this->course('Sơ cấp tự học');
        $activity = $this->quizActivity($courseVersion, $lesson, $quiz, $version, ['completion_rule' => 'submit']);
        $enrollment = $this->enroll($this->student, $product, $courseVersion);
        $start = self::HOST.'/my-courses/'.$enrollment.'/activities/'.$activity.'/attempts';

        $this->actingAs($this->user('student'))->post($start)->assertForbidden();
        $this->actingAs($this->admin)->post($start)->assertForbidden();
        // A non-quiz Activity has no attempt.
        $video = DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $this->customer, 'template_version_id' => $courseVersion, 'version_lesson_id' => $lesson,
            'source_template_activity_id' => 99001, 'title_snapshot' => 'Video', 'activity_type' => 'video', 'sort_order' => 2,
            'is_required' => true, 'completion_rule' => 'view', 'unlock_rule_snapshot' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->student)->post(self::HOST.'/my-courses/'.$enrollment.'/activities/'.$video.'/attempts')->assertNotFound();
        // Outside the access window the Activity is locked.
        DB::table('core_course_enrollments')->where('id', $enrollment)->update(['access_starts_at' => now()->addDay(), 'access_ends_at' => now()->addDays(30)]);
        $this->actingAs($this->student)->post($start)->assertForbidden();
        DB::table('core_course_enrollments')->where('id', $enrollment)->update(['access_starts_at' => null, 'access_ends_at' => null]);

        // `submit` completes on submit.
        $attempt = $this->startAttempt($start);
        $this->submit($attempt->id, ['1']);
        $this->assertSame(['completed', 'submitted'], [$this->progress($enrollment, $activity)->status, $this->progress($enrollment, $activity)->completion_rule]);

        $open = $this->startAttempt($start);
        DB::table('core_course_enrollments')->where('id', $enrollment)->update(['status' => 'cancelled']);
        $question = $this->singleQuestion($open);
        $this->actingAs($this->student)->postJson(self::HOST.'/assessments/attempts/'.$open->id.'/answers',
            ['question' => $question, 'option_keys' => ['2']], self::AJAX)->assertStatus(409);
        $this->actingAs($this->student)->post(self::HOST.'/assessments/attempts/'.$open->id.'/submit');
        $this->assertSame('in_progress', DB::table('core_assessment_attempts')->where('id', $open->id)->value('status'));
        $this->actingAs($this->student)->post($start)->assertForbidden();
    }

    public function test_pass_waits_for_grading_and_graders_see_the_course(): void
    {
        [$quiz, $version] = $this->quiz(['title' => 'Bài viết', 'max_attempts' => '1', 'show_result_mode' => 'after_grading'], essay: true);
        [$product, $courseVersion, $lesson] = $this->course('Viết sơ cấp');
        $activity = $this->quizActivity($courseVersion, $lesson, $quiz, $version, ['completion_rule' => 'pass', 'completion_threshold' => 60]);
        $enrollment = $this->enroll($this->student, $product, $courseVersion);
        $attempt = $this->startAttempt(self::HOST.'/my-courses/'.$enrollment.'/activities/'.$activity.'/attempts');
        $essay = (int) DB::table('core_assessment_quiz_version_questions')->where('quiz_version_id', $version)->where('question_type', 'essay')->value('id');
        $this->actingAs($this->student)->post(self::HOST.'/assessments/attempts/'.$attempt->id.'/submit', ['answers' => [
            $this->singleQuestion($attempt) => ['present' => '1', 'option_keys' => ['2']],
            $essay => ['present' => '1', 'text' => 'Bài viết của em'],
        ]]);

        // Waiting for grading: no score shown, not completed.
        $this->assertSame(['in_progress', null], [$this->progress($enrollment, $activity)->status, $this->progress($enrollment, $activity)->passed]);
        $this->actingAs($this->student)->get(self::HOST.'/my-courses/'.$enrollment.'/lessons/'.$lesson)->assertOk()
            ->assertSeeText(__('lf.LF_my_learning_results_waiting'))->assertDontSeeText('2 / 6');

        $this->actingAs($this->admin)->get(self::ADMIN.'/results?tab=pending')->assertOk()
            ->assertSeeText(__('lf.LF_assessment_result_course_source', ['course' => 'Viết sơ cấp']));
        $answer = (int) DB::table('core_assessment_answers')->where('attempt_id', $attempt->id)->where('quiz_version_question_id', $essay)->value('id');
        $this->actingAs($this->admin)->post(self::ADMIN.'/results/'.$attempt->id.'/answers/'.$answer.'/grade', ['score' => '3'])->assertSessionHasNoErrors();

        // 2 + 3 of 6 = 83%: passes 60%.
        $row = $this->progress($enrollment, $activity);
        $this->assertSame(['completed', 1, '5.00'], [$row->status, (int) $row->passed, number_format((float) $row->score, 2, '.', '')]);
        // A lower regrade keeps the completion.
        $this->actingAs($this->admin)->post(self::ADMIN.'/results/'.$attempt->id.'/answers/'.$answer.'/grade', ['score' => '0'])->assertSessionHasNoErrors();
        $row = $this->progress($enrollment, $activity);
        $this->assertSame(['completed', 0, '2.00'], [$row->status, (int) $row->passed, number_format((float) $row->score, 2, '.', '')]);
    }

    private function startAttempt(string $url): object
    {
        $response = $this->actingAs($this->student)->post($url);
        $response->assertRedirect();
        $this->assertMatchesRegularExpression('#/assessments/attempts/(\d+)$#', $response->headers->get('Location'));
        preg_match('#/assessments/attempts/(\d+)$#', $response->headers->get('Location'), $match);

        return DB::table('core_assessment_attempts')->find((int) $match[1]);
    }

    /** Submits the attempt answering the single-choice question (option 2 is right, 2 points). */
    private function submit(int $attemptId, array $keys): void
    {
        $attempt = DB::table('core_assessment_attempts')->find($attemptId);
        $this->actingAs($this->student)->post(self::HOST.'/assessments/attempts/'.$attemptId.'/submit', ['answers' => [
            $this->singleQuestion($attempt) => ['present' => '1', 'option_keys' => $keys],
        ]])->assertRedirect();
    }

    private function singleQuestion(object $attempt): int
    {
        return (int) DB::table('core_assessment_quiz_version_questions')->where('quiz_version_id', $attempt->quiz_version_id)
            ->where('question_type', 'single_choice')->value('id');
    }

    private function progress(int $enrollment, int $activity): object
    {
        return DB::table('core_course_activity_progress')->where('enrollment_id', $enrollment)->where('version_activity_id', $activity)->sole();
    }

    /** @return array{0: int, 1: int} quiz, its published version */
    private function quiz(array $settings, bool $essay = false): array
    {
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/quizzes', $settings + [
            'assessment_type' => 'exam', 'pass_score_percentage' => '60', 'show_result_mode' => 'immediately',
        ], self::AJAX)->assertCreated();
        $quiz = (int) DB::table('core_assessment_quizzes')->max('id');
        $make = function (array $payload): int {
            $this->actingAs($this->admin)->postJson(self::ADMIN.'/questions', $payload + ['status' => 'published', 'default_points' => '1'], self::AJAX)->assertCreated();

            return (int) DB::table('core_assessment_questions')->max('id');
        };
        $questions = [$make(['question_type' => 'single_choice', 'default_points' => '2', 'prompt' => 'Chọn đáp án đúng',
            'options' => [['text' => 'Sai', 'correct' => 0], ['text' => 'Đúng', 'correct' => 1]]])];
        if ($essay) {
            $questions[] = $make(['question_type' => 'essay', 'default_points' => '4', 'prompt' => 'Viết đoạn văn']);
        }
        $section = (int) DB::table('core_assessment_quiz_sections')->where('quiz_id', $quiz)->value('id');
        $this->actingAs($this->admin)->postJson(self::ADMIN.'/quizzes/'.$quiz.'/questions', ['quiz_section_id' => $section, 'question_ids' => $questions], self::AJAX)->assertCreated();
        $this->actingAs($this->admin)->post(self::ADMIN.'/quizzes/'.$quiz.'/publish')->assertSessionHasNoErrors();

        return [$quiz, (int) DB::table('core_assessment_quizzes')->where('id', $quiz)->value('current_version_id')];
    }

    /** A newer published version of the quiz, made current. */
    private function republish(int $quiz, int $version): int
    {
        $row = (array) DB::table('core_assessment_quiz_versions')->find($version);
        unset($row['id']);
        $newer = DB::table('core_assessment_quiz_versions')->insertGetId(['version_no' => 2, 'max_attempts' => 9] + $row);
        DB::table('core_assessment_quizzes')->where('id', $quiz)->update(['current_version_id' => $newer]);

        return $newer;
    }

    /** @return array{0: int, 1: int, 2: int} product, template version, version lesson */
    private function course(string $title): array
    {
        $product = DB::table('core_course_products')->insertGetId([
            'customer_id' => $this->customer, 'product_code' => 'P-'.uniqid(), 'product_type' => 'single_course',
            'title' => $title, 'slug' => 'p-'.uniqid(), 'thumbnail_type' => 'image', 'price' => 0, 'currency' => 'VND',
            'enrollment_type' => 'paid', 'enrollment_count' => 0, 'is_certificate_enabled' => false, 'is_refundable' => false,
            'show_enrollment_count' => true, 'is_featured' => false, 'sort_order' => 0, 'visibility' => 'public',
            'status' => 'active', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $template = DB::table('core_course_templates')->insertGetId([
            'course_type' => 'self_paced', 'customer_id' => $this->customer, 'title' => $title,
            'estimated_minutes_per_lesson' => 0, 'lesson_count' => 0, 'working_revision' => 1,
            'status' => 'active', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = DB::table('core_course_template_versions')->insertGetId([
            'course_type_snapshot' => 'self_paced', 'customer_id' => $this->customer, 'template_id' => $template,
            'version_number' => 1, 'version_code' => 'V-'.$template, 'is_current' => true,
            'title_snapshot' => $title, 'estimated_minutes_per_lesson_snapshot' => 0, 'lesson_count_snapshot' => 1,
            'source_working_revision' => 1, 'status' => 'published', 'published_at' => now(), 'published_by' => $this->admin->id,
            'source_template_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lesson = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $this->customer, 'template_version_id' => $version, 'source_template_lesson_id' => 90001,
            'title_snapshot' => 'Bài 1', 'sort_order' => 1, 'unlock_rule_snapshot' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$product, $version, $lesson];
    }

    private function quizActivity(int $version, int $lesson, int $quiz, int $quizVersion, array $extra): int
    {
        return DB::table('core_course_template_version_activities')->insertGetId($extra + [
            'customer_id' => $this->customer, 'template_version_id' => $version, 'version_lesson_id' => $lesson,
            'source_template_activity_id' => 98001, 'title_snapshot' => 'Kiểm tra cuối bài', 'activity_type' => 'quiz',
            'assessment_quiz_id_snapshot' => $quiz, 'assessment_quiz_version_id_snapshot' => $quizVersion,
            'sort_order' => 1, 'is_required' => true, 'unlock_rule_snapshot' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function enroll(User $student, int $product, int $version): int
    {
        return DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $this->customer, 'product_id' => $product, 'version_id' => $version,
            'student_id' => $student->id, 'source' => 'admin', 'enrolled_at' => now(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(string $role, ?string $name = null): User
    {
        return User::forceCreate([
            'customer_id' => $this->customer, 'name' => $name ?? ucfirst($role).' '.uniqid(), 'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }
}
