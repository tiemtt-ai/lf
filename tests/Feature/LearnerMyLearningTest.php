<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Khóa học của tôi" (LF-Navigation § My Courses): self-paced courses, classes and their detail pages,
 * read from the learner's own Enrollments and Cohort seats only.
 */
class LearnerMyLearningTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://tenant-a.localhost';

    private int $customer;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://localhost', 'app.base_domain' => 'localhost', 'app.tenant_scheme' => 'https']);
        Carbon::setTestNow('2026-07-10 09:00:00');
        $this->customer = $this->tenant('tenant-a');
        $this->student = $this->user($this->customer, 'student');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_self_paced_courses_are_grouped_by_learning_cycle_and_only_the_learners_own(): void
    {
        $teacher = $this->user($this->customer, 'teacher', 'Cô Thúy');
        [$product, $version, $template] = $this->course($this->customer, 'Sơ cấp tự học', 'self_paced');
        DB::table('core_course_template_teachers')->insert([
            'customer_id' => $this->customer, 'template_id' => $template, 'teacher_id' => $teacher->id, 'role' => 'primary',
            'sort_order' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->lesson($version, 'Bài 1', 1);
        $this->lesson($version, 'Bài 2', 2);
        [$classProduct, $classVersion] = $this->course($this->customer, 'Khóa theo lớp', 'blended');

        $current = $this->enroll($this->student, $product, $version, ['access_starts_at' => '2026-07-01 00:00:00', 'access_ends_at' => '2026-07-21 00:00:00']);
        $review = $this->enroll($this->student, $product, $version, ['access_starts_at' => '2026-06-01 00:00:00', 'access_ends_at' => '2026-07-01 00:00:00',
            'review_starts_at' => '2026-07-01 00:00:00', 'review_ends_at' => '2026-07-31 00:00:00']);
        $completed = $this->enroll($this->student, $product, $version, ['status' => 'completed', 'completed_at' => '2026-07-05 00:00:00']);
        $ended = $this->enroll($this->student, $product, $version, ['access_starts_at' => '2026-05-01 00:00:00', 'access_ends_at' => '2026-06-01 00:00:00']);
        $expired = $this->enroll($this->student, $product, $version, ['status' => 'expired']);
        $cancelled = $this->enroll($this->student, $product, $version, ['status' => 'cancelled']);
        $classEnrollment = $this->enroll($this->student, $classProduct, $classVersion);
        $someoneElse = $this->enroll($this->user($this->customer, 'student'), $product, $version);

        $page = fn (string $view): string => $this->actingAs($this->student)->get(self::HOST.'/my-courses?view='.$view)->assertOk()->getContent();
        $this->assertSame([$review, $current], $this->ids($page('current'), 'data-learn-course'));
        $this->assertSame([$completed], $this->ids($page('completed'), 'data-learn-course'));
        $this->assertEqualsCanonicalizing([$ended, $expired], $this->ids($page('expired'), 'data-learn-course'));

        $html = $page('current');
        $this->assertStringContainsString('Sơ cấp tự học', $html);
        $this->assertStringContainsString('Cô Thúy', $html);
        $this->assertStringContainsString(__('lf.LF_my_learning_days_left', ['days' => 11]), $html);
        $this->assertStringContainsString(__('lf.LF_my_learning_phase_review'), $html);
        foreach ([$cancelled, $classEnrollment, $someoneElse] as $hidden) {
            $this->assertStringNotContainsString('data-learn-course="'.$hidden.'"', $page('current').$page('completed').$page('expired'));
        }
    }

    public function test_the_filter_narrows_the_chosen_group_and_missing_features_stay_locked(): void
    {
        [$product, $version] = $this->course($this->customer, 'Sơ cấp tự học', 'self_paced');
        [$otherProduct, $otherVersion] = $this->course($this->customer, 'Luyện nghe TOPIK', 'self_paced');
        $window = ['access_starts_at' => '2026-07-01 00:00:00', 'access_ends_at' => '2026-07-21 00:00:00'];
        $first = $this->enroll($this->student, $product, $version, $window);
        $second = $this->enroll($this->student, $otherProduct, $otherVersion, $window);

        $html = $this->actingAs($this->student)->get(self::HOST.'/my-courses?q=topik')->assertOk()->getContent();
        $this->assertSame([$second], $this->ids($html, 'data-learn-course'));
        $this->assertStringContainsString(__('lf.LF_my_learning_found', ['count' => 1]), $html);
        // The group count stays the learner's whole group.
        $this->assertMatchesRegularExpression('#data-learn-view="current">\s*'.preg_quote(__('lf.LF_my_learning_view_courses_current')).'\s*<span class="student-products__tab-count">2</span>#', $html);
        $this->assertSame([$second, $first], $this->ids($this->actingAs($this->student)->get(self::HOST.'/my-courses')->getContent(), 'data-learn-course'));

        // Favourites, learning results, 1:1 advice and AI Speak are shown but only say "coming soon".
        $this->assertStringContainsString('data-coming-soon-message="'.__('lf.LF_student_site_coming_soon').'"', $html);
        $locked = fn (string $label): int => preg_match_all('#data-coming-soon[^>]*>(?:(?!</button>).)*'.preg_quote($label, '#').'#s', $html);
        $this->assertSame(2, $locked(__('lf.LF_student_site_my_results')));
        $this->assertSame(1, $locked(__('lf.LF_my_learning_view_favorite')));
        $this->assertGreaterThanOrEqual(1, preg_match_all('#class="student-products__like" data-coming-soon#', $html));
        $this->assertSame(1, $locked('aispeak.svg'));
    }

    public function test_course_detail_shows_each_lesson_with_its_runtime_access_decision(): void
    {
        [$product, $version] = $this->course($this->customer, 'Sơ cấp tự học', 'self_paced');
        $first = $this->lesson($version, 'Giới thiệu', 1);
        $second = $this->lesson($version, 'Từ vựng', 2, ['unlock_rule_snapshot' => 'previous_lesson_completed', 'unlock_after_version_lesson_id' => $first]);
        $third = $this->lesson($version, 'Ngữ pháp', 3, ['unlock_rule_snapshot' => 'date_based', 'unlock_at_snapshot' => '2026-07-15 02:00:00']);
        $enrollment = $this->enroll($this->student, $product, $version, ['access_starts_at' => '2026-07-01 00:00:00', 'access_ends_at' => '2026-07-21 00:00:00']);

        $html = $this->actingAs($this->student)->get(self::HOST.'/my-courses/'.$enrollment)->assertOk()->getContent();
        $this->assertSame('open', $this->lessonState($html, $first));
        $this->assertSame('locked', $this->lessonState($html, $second));
        $this->assertSame('locked', $this->lessonState($html, $third));
        $this->assertStringContainsString(__('lf.LF_my_learning_lock_prerequisite'), $html);
        $this->assertStringContainsString(e(__('lf.LF_my_learning_lock_time', ['date' => '15/07/2026 09:00'])), $html);
        $this->assertStringContainsString('1/3', $html);

        // Outside the access window every Lesson is closed for that reason.
        Carbon::setTestNow('2026-07-22 09:00:00');
        $html = $this->actingAs($this->student)->get(self::HOST.'/my-courses/'.$enrollment)->assertOk()->getContent();
        $this->assertSame('locked', $this->lessonState($html, $first));
        $this->assertStringContainsString(__('lf.LF_my_learning_lock_window'), $html);

        // Someone else's Enrollment, a cancelled one, and another tenant's learner learn nothing.
        $other = $this->user($this->customer, 'student');
        $this->actingAs($other)->get(self::HOST.'/my-courses/'.$enrollment)->assertNotFound();
        DB::table('core_course_enrollments')->where('id', $enrollment)->update(['status' => 'cancelled']);
        $this->actingAs($this->student)->get(self::HOST.'/my-courses/'.$enrollment)->assertNotFound();
        DB::table('core_course_enrollments')->where('id', $enrollment)->update(['status' => 'active']);
        $tenantB = $this->tenant('tenant-b');
        $this->actingAs($this->user($tenantB, 'student'))->get('https://tenant-b.localhost/my-courses/'.$enrollment)->assertNotFound();
    }

    public function test_classes_show_attendance_against_sessions_held_and_the_next_session(): void
    {
        $teacher = $this->user($this->customer, 'teacher', 'Thầy Minh');
        [$product, $version] = $this->course($this->customer, 'Sơ cấp 1A', 'blended');
        $cohort = $this->cohort($product, $version, 'Lớp A', 'active');
        DB::table('core_course_cohort_teachers')->insert([
            'customer_id' => $this->customer, 'cohort_id' => $cohort, 'teacher_id' => $teacher->id, 'role' => 'primary',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $enrollment = $this->enroll($this->student, $product, $version);
        $this->seat($cohort, $enrollment, $this->student, $product);

        $attended = $this->liveSession($cohort, $version, 'Buổi 1', '2026-07-03 19:00:00', '2026-07-03 21:00:00', 'completed');
        $missed = $this->liveSession($cohort, $version, 'Buổi 2', '2026-07-06 19:00:00', '2026-07-06 21:00:00', 'completed');
        $cancelled = $this->liveSession($cohort, $version, 'Buổi hủy', '2026-07-08 19:00:00', '2026-07-08 21:00:00', 'cancelled');
        $next = $this->liveSession($cohort, $version, 'Buổi 3', '2026-07-13 19:00:00', '2026-07-13 21:00:00');
        DB::table('core_liveclass_attendances')->insert([
            'customer_id' => $this->customer, 'session_id' => $attended, 'enrollment_id' => $enrollment, 'user_id' => $this->student->id,
            'status' => 'present', 'attendance_mode' => 'onsite', 'joined_at' => '2026-07-03 19:01:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Draft classes, classes the learner left, and other learners' classes stay out.
        $draft = $this->cohort($product, $version, 'Lớp nháp', 'draft');
        $this->seat($draft, $this->enroll($this->student, $product, $version), $this->student, $product);
        $left = $this->cohort($product, $version, 'Lớp đã chuyển', 'active');
        $this->seat($left, $this->enroll($this->student, $product, $version), $this->student, $product, ['status' => 'transferred', 'left_at' => '2026-07-02 00:00:00']);
        $foreign = $this->cohort($product, $version, 'Lớp khác', 'active');
        $otherStudent = $this->user($this->customer, 'student');
        $this->seat($foreign, $this->enroll($otherStudent, $product, $version), $otherStudent, $product);
        $finished = $this->cohort($product, $version, 'Lớp đã học', 'completed');
        $this->seat($finished, $this->enroll($this->student, $product, $version), $this->student, $product);

        $list = $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=classes')->assertOk()->getContent();
        $this->assertSame([$cohort], $this->ids($list, 'data-learn-class'));
        $this->assertStringContainsString('Thầy Minh', $list);
        $this->assertStringContainsString(__('lf.LF_my_learning_class_attendance').': 50%', $list);
        $this->assertStringContainsString(__('lf.LF_my_learning_class_sessions', ['held' => 2, 'total' => 3]), $list);
        $done = $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=classes&view=completed')->assertOk()->getContent();
        $this->assertSame([$finished], $this->ids($done, 'data-learn-class'));
        // Class-based courses are not listed among self-paced courses.
        $this->assertSame([], $this->ids($this->actingAs($this->student)->get(self::HOST.'/my-courses')->getContent(), 'data-learn-course'));

        $detail = $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$cohort)->assertOk()->getContent();
        $this->assertSame([$attended, $missed, $cancelled, $next], $this->ids($detail, 'data-learn-session'));
        $this->assertMatchesRegularExpression('#data-learn-session="'.$attended.'".*?data-learn-attendance="present"#s', $detail);
        $this->assertMatchesRegularExpression('#data-learn-session="'.$missed.'".*?data-learn-attendance="none"#s', $detail);
        $this->assertStringContainsString('Buổi 3', substr($detail, strpos($detail, 'data-learn-next'), 600));

        $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$foreign)->assertNotFound();
        $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$left)->assertNotFound();
        $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$draft)->assertNotFound();
    }

    public function test_a_class_course_without_a_class_shows_as_awaiting_placement(): void
    {
        [$product, $version] = $this->course($this->customer, 'Lớp tối giao tiếp', 'scheduled');
        $waiting = $this->enroll($this->student, $product, $version);
        [$draftProduct, $draftVersion] = $this->course($this->customer, 'Lớp sáng chờ mở', 'blended');
        $inDraft = $this->enroll($this->student, $draftProduct, $draftVersion);
        $this->seat($this->cohort($draftProduct, $draftVersion, 'Lớp nháp', 'draft'), $inDraft, $this->student, $draftProduct);
        [$seatedProduct, $seatedVersion] = $this->course($this->customer, 'Lớp đã xếp', 'scheduled');
        $seated = $this->enroll($this->student, $seatedProduct, $seatedVersion);
        $this->seat($this->cohort($seatedProduct, $seatedVersion, 'Lớp A1', 'active'), $seated, $this->student, $seatedProduct);
        [$cancelledProduct, $cancelledVersion] = $this->course($this->customer, 'Lớp đã huỷ', 'scheduled');
        $cancelled = $this->enroll($this->student, $cancelledProduct, $cancelledVersion, ['status' => 'cancelled']);
        $other = $this->user($this->customer, 'student');
        $foreign = $this->enroll($other, $product, $version);

        $html = $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=classes')->assertOk()
            ->assertSee('Lớp tối giao tiếp')->assertSee('Đang chờ xếp lớp')->getContent();
        $this->assertSame([$inDraft, $waiting], array_values(array_unique($this->ids($html, 'data-learn-awaiting'))));
        $this->assertNotContains($cancelled, $this->ids($html, 'data-learn-awaiting'));
        $this->assertNotContains($foreign, $this->ids($html, 'data-learn-awaiting'));
        $this->assertNotContains($seated, $this->ids($html, 'data-learn-awaiting'));
        $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=classes&q=t%E1%BB%91i')->assertOk()
            ->assertSee('Lớp tối giao tiếp')->assertDontSee('Lớp sáng chờ mở');
        $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=courses')->assertOk()->assertDontSee('Lớp tối giao tiếp');
    }

    public function test_the_page_is_for_students_only(): void
    {
        $this->get(self::HOST.'/my-courses')->assertRedirect();
        $this->actingAs($this->user($this->customer, 'teacher'))->get(self::HOST.'/my-courses')->assertForbidden();
        $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=unknown&view=nope')->assertOk()
            ->assertSee('data-learn-tab="courses"', false)->assertSeeText(__('lf.LF_my_learning_empty_courses'));
    }

    public function test_the_lesson_page_lists_its_activities_and_refuses_locked_or_foreign_lessons(): void
    {
        [$product, $version] = $this->course($this->customer, 'Sơ cấp tự học', 'self_paced');
        $first = $this->lesson($version, 'Giới thiệu', 1);
        $second = $this->lesson($version, 'Từ vựng', 2, ['unlock_rule_snapshot' => 'previous_lesson_completed', 'unlock_after_version_lesson_id' => $first]);
        $video = $this->activity($version, $first, 'Video mở đầu', 'video', 2, ['completion_rule' => 'watch_percent', 'completion_threshold' => 80, 'duration_seconds' => 600]);
        $document = $this->activity($version, $first, 'Tài liệu', 'document', 1);
        $enrollment = $this->enroll($this->student, $product, $version);
        [$otherProduct, $otherVersion] = $this->course($this->customer, 'Khóa khác', 'self_paced');
        $foreignLesson = $this->lesson($otherVersion, 'Bài khác', 1);

        $html = $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $first))->assertOk()
            ->assertSee('Video mở đầu')->assertSee(__('lf.LF_my_learning_rule_watch_percentage', ['percent' => 80]))->getContent();
        $this->assertSame([$document, $video], $this->ids($html, 'data-learn-activity'));
        $this->assertNotNull(DB::table('core_course_lesson_progress')->where('enrollment_id', $enrollment)->where('version_lesson_id', $first)->value('first_accessed_at'));
        // The next Lesson is still locked: no link to it, and opening it is refused with its reason.
        $this->assertStringNotContainsString($this->lessonUrl($enrollment, $second), $html);
        $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $second))->assertForbidden();
        $this->assertSame(0, DB::table('core_course_lesson_progress')->where('version_lesson_id', $second)->count());

        $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $foreignLesson))->assertNotFound();
        $this->actingAs($this->user($this->customer, 'student'))->get($this->lessonUrl($enrollment, $first))->assertNotFound();
        $this->actingAs($this->user($this->tenant('tenant-b'), 'student'))
            ->get(str_replace(self::HOST, 'https://tenant-b.localhost', $this->lessonUrl($enrollment, $first)))->assertNotFound();
        $this->assertSame(0, DB::table('core_course_activity_progress')->count(), 'reading a lesson records no Activity');
    }

    public function test_progress_events_follow_each_activity_completion_rule(): void
    {
        [$product, $version] = $this->course($this->customer, 'Sơ cấp tự học', 'self_paced');
        $lesson = $this->lesson($version, 'Bài 1', 1);
        $document = $this->activity($version, $lesson, 'Tài liệu', 'document', 1);
        $manual = $this->activity($version, $lesson, 'Tự luyện', 'document', 2, ['completion_rule' => 'manual']);
        $video = $this->activity($version, $lesson, 'Video', 'video', 3, ['completion_rule' => 'watch_percent', 'completion_threshold' => 80, 'duration_seconds' => 600]);
        $quiz = $this->activity($version, $lesson, 'Kiểm tra', 'quiz', 4, ['completion_rule' => 'quiz_pass']);
        $enrollment = $this->enroll($this->student, $product, $version, ['access_starts_at' => '2026-07-01 00:00:00', 'access_ends_at' => '2026-07-21 00:00:00']);
        $send = fn (int $activity, array $data) => $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $activity), $data);

        $send($document, ['event' => 'opened'])->assertOk()->assertJsonPath('progress.status', 'completed')->assertJsonPath('progress.completed', true);
        $send($manual, ['event' => 'opened'])->assertOk()->assertJsonPath('progress.status', 'in_progress');
        $send($manual, ['event' => 'completed'])->assertOk()->assertJsonPath('progress.status', 'completed');

        // A watched position only advances as fast as real playback allows (1.5× plus 30 s).
        $send($video, ['event' => 'position'])->assertUnprocessable();
        $send($video, ['event' => 'position', 'position_seconds' => 300])->assertOk()->assertJsonPath('progress.percentage', 5);
        $send($video, ['event' => 'completed'])->assertOk()->assertJsonPath('progress.status', 'in_progress');
        Carbon::setTestNow(Carbon::now()->addSeconds(20));
        $send($video, ['event' => 'position', 'position_seconds' => 300])->assertOk()->assertJsonPath('progress.percentage', 15);
        $this->assertSame(300, (int) $this->activityRow($enrollment, $video)->last_position_seconds);
        Carbon::setTestNow(Carbon::now()->addSeconds(400));
        $send($video, ['event' => 'position', 'position_seconds' => 590])->assertOk()->assertJsonPath('progress.status', 'completed')->assertJsonPath('progress.percentage', 100);
        // Seeking back after completion keeps it completed.
        $send($video, ['event' => 'position', 'position_seconds' => 10])->assertOk()->assertJsonPath('progress.status', 'completed');
        $row = $this->activityRow($enrollment, $video);
        $this->assertSame(['watch_percentage', 110], [$row->completion_rule, (int) $row->total_learning_seconds]);

        // A quiz is not recorded here; the Lesson and the Course count only the other Activities.
        $send($quiz, ['event' => 'opened'])->assertForbidden();
        $lessonRow = DB::table('core_course_lesson_progress')->where('enrollment_id', $enrollment)->sole();
        $this->assertSame(['completed', 3, 3], [$lessonRow->status, (int) $lessonRow->completed_activities, (int) $lessonRow->total_activities]);
        $course = DB::table('core_course_progress')->where('enrollment_id', $enrollment)->sole();
        $this->assertSame(['completed', 100, $video, $lesson], [$course->status, (int) $course->progress_percentage, (int) $course->last_version_activity_id, (int) $course->last_version_lesson_id]);
        // A course finished by its progress moves to "Đã hoàn thành" (ADR-0030 Q2).
        $this->assertSame([$enrollment], $this->ids($this->actingAs($this->student)->get(self::HOST.'/my-courses?view=completed')->getContent(), 'data-learn-course'));

        // Someone else's Enrollment and a closed window record nothing.
        $this->actingAs($this->user($this->customer, 'student'))->postJson($this->progressUrl($enrollment, $manual), ['event' => 'completed'])->assertForbidden();
        Carbon::setTestNow('2026-07-22 09:00:00');
        $send($document, ['event' => 'opened'])->assertForbidden();
        $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $lesson))->assertForbidden();
    }

    public function test_completing_activities_unlocks_the_next_activity_and_lesson(): void
    {
        [$product, $version] = $this->course($this->customer, 'Sơ cấp tự học', 'self_paced');
        $first = $this->lesson($version, 'Bài 1', 1);
        $second = $this->lesson($version, 'Bài 2', 2, ['unlock_rule_snapshot' => 'previous_lesson_completed', 'unlock_after_version_lesson_id' => $first]);
        $read = $this->activity($version, $first, 'Đọc', 'document', 1, ['completion_rule' => 'manual']);
        $practice = $this->activity($version, $first, 'Luyện', 'document', 2, ['completion_rule' => 'manual',
            'unlock_rule_snapshot' => 'previous_activity_completed', 'unlock_after_version_activity_id' => $read]);
        $extra = $this->activity($version, $first, 'Đọc thêm', 'document', 3, ['is_required' => false]);
        $enrollment = $this->enroll($this->student, $product, $version);
        $send = fn (int $activity, string $event) => $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $activity), ['event' => $event]);

        $html = $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $first))->getContent();
        $this->assertMatchesRegularExpression('#is-locked[^>]*data-learn-activity="'.$practice.'"#', $html);
        $this->assertStringContainsString(__('lf.LF_my_learning_activity_lock_prerequisite'), $html);
        $send($practice, 'completed')->assertForbidden();

        $send($read, 'completed')->assertOk();
        $send($practice, 'completed')->assertOk()->assertJsonPath('progress.status', 'completed');
        // An optional Activity does not hold the Lesson back.
        $this->assertSame('not_started', $this->activityRow($enrollment, $extra)?->status ?? 'not_started');
        $this->assertSame('completed', DB::table('core_course_lesson_progress')->where('version_lesson_id', $first)->value('status'));
        $course = $this->actingAs($this->student)->get(self::HOST.'/my-courses/'.$enrollment)->getContent();
        $this->assertSame('open', $this->lessonState($course, $second));
        $this->assertStringContainsString($this->lessonUrl($enrollment, $second), $course);
        // "Học tiếp" moves on from the finished Lesson to the next open one.
        $this->assertMatchesRegularExpression('#href="'.preg_quote($this->lessonUrl($enrollment, $second), '#').'" data-learn-resume#', $course);

        // A Lesson without Activities completes when it is opened, and with it the Course.
        $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $second))->assertOk()->assertSee(__('lf.LF_my_learning_activities_empty'));
        $this->assertSame('completed', DB::table('core_course_progress')->where('enrollment_id', $enrollment)->value('status'));
    }

    public function test_media_of_an_open_activity_is_served_by_a_short_lived_url_only(): void
    {
        [$product, $version] = $this->course($this->customer, 'Sơ cấp tự học', 'self_paced');
        $lesson = $this->lesson($version, 'Bài 1', 1);
        $media = $this->mediaFile('bai-1.pdf', 'document', 'application/pdf');
        $document = $this->activity($version, $lesson, 'Tài liệu', 'document', 1, ['media_file_id' => $media, 'completion_rule' => 'manual']);
        $locked = $this->activity($version, $lesson, 'Tài liệu 2', 'document', 2, ['media_file_id' => $media,
            'unlock_rule_snapshot' => 'previous_activity_completed', 'unlock_after_version_activity_id' => $document]);
        $detached = $this->activity($version, $lesson, 'Tài liệu cũ', 'document', 3, ['media_file_id' => $media]);
        foreach ([$document => 'active', $locked => 'active', $detached => 'detached'] as $owner => $status) {
            DB::table('media_file_usages')->insert([
                'customer_id' => $this->customer, 'media_file_id' => $media, 'owner_type' => 'course_version_activity', 'owner_id' => $owner,
                'usage_type' => 'document', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $enrollment = $this->enroll($this->student, $product, $version);
        $url = fn (int $activity): string => self::HOST.'/my-courses/'.$enrollment.'/activities/'.$activity.'/media';

        $signed = $this->actingAs($this->student)->getJson($url($document))->assertOk()->json('url');
        $this->assertStringStartsWith(self::HOST.'/media/files/'.$media.'/signed?', $signed);
        $this->assertStringContainsString('signature=', $signed);
        $this->actingAs($this->student)->getJson($url($locked))->assertForbidden();
        $this->actingAs($this->student)->getJson($url($detached))->assertNotFound();
        $this->actingAs($this->user($this->customer, 'student'))->getJson($url($document))->assertForbidden();
        $this->assertSame(0, DB::table('core_course_activity_progress')->count(), 'reading the URL records nothing');

        // An open Activity inside a locked Lesson: the lesson page refuses it, and so does its Media.
        $lockedLesson = $this->lesson($version, 'Bài 2', 2, ['unlock_rule_snapshot' => 'previous_lesson_completed', 'unlock_after_version_lesson_id' => $lesson]);
        $insideLocked = $this->activity($version, $lockedLesson, 'Tài liệu bài 2', 'document', 1, ['media_file_id' => $media]);
        DB::table('media_file_usages')->insert([
            'customer_id' => $this->customer, 'media_file_id' => $media, 'owner_type' => 'course_version_activity', 'owner_id' => $insideLocked,
            'usage_type' => 'document', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $lockedLesson))->assertForbidden();
        $this->actingAs($this->student)->getJson($url($insideLocked))->assertForbidden();
    }

    public function test_a_class_course_follows_the_class_order_and_needs_the_class(): void
    {
        [$product, $version] = $this->course($this->customer, 'Sơ cấp 1A', 'blended');
        $first = $this->lesson($version, 'Bài A', 1);
        $second = $this->lesson($version, 'Bài B', 2);
        $one = $this->activity($version, $first, 'Hoạt động 1', 'document', 1);
        $two = $this->activity($version, $first, 'Hoạt động 2', 'document', 2);
        $cohort = $this->cohort($product, $version, 'Lớp A', 'active');
        foreach ([$second => 1, $first => 2] as $lesson => $order) {
            DB::table('core_course_cohort_lesson_orders')->insert(['customer_id' => $this->customer, 'cohort_id' => $cohort,
                'version_lesson_id' => $lesson, 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([$two => 1, $one => 2] as $activity => $order) {
            DB::table('core_course_cohort_activity_orders')->insert(['customer_id' => $this->customer, 'cohort_id' => $cohort,
                'version_activity_id' => $activity, 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now()]);
        }
        $enrollment = $this->enroll($this->student, $product, $version);

        // Without a class seat nothing opens.
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $one), ['event' => 'opened'])->assertForbidden();

        $this->seat($cohort, $enrollment, $this->student, $product);
        $course = $this->actingAs($this->student)->get(self::HOST.'/my-courses/'.$enrollment)->assertOk()->getContent();
        $this->assertSame([$second, $first], $this->ids($course, 'data-learn-lesson'));
        $lesson = $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $first))->assertOk()->getContent();
        $this->assertSame([$two, $one], $this->ids($lesson, 'data-learn-activity'));
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $one), ['event' => 'opened'])->assertOk();
    }

    // ---- fixtures ----

    private function lessonUrl(int $enrollment, int $lesson): string
    {
        return self::HOST.'/my-courses/'.$enrollment.'/lessons/'.$lesson;
    }

    private function progressUrl(int $enrollment, int $activity): string
    {
        return self::HOST.'/my-courses/'.$enrollment.'/activities/'.$activity.'/progress';
    }

    private function activityRow(int $enrollment, int $activity): ?object
    {
        return DB::table('core_course_activity_progress')->where('enrollment_id', $enrollment)->where('version_activity_id', $activity)->first();
    }

    private function activity(int $version, int $lesson, string $title, string $type, int $order, array $extra = []): int
    {
        static $source = 95000;

        return DB::table('core_course_template_version_activities')->insertGetId($extra + [
            'customer_id' => $this->customer, 'template_version_id' => $version, 'version_lesson_id' => $lesson,
            'source_template_activity_id' => ++$source, 'title_snapshot' => $title, 'activity_type' => $type,
            'sort_order' => $order, 'is_required' => true, 'completion_rule' => 'view', 'unlock_rule_snapshot' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mediaFile(string $name, string $type, string $mime): int
    {
        return DB::table('media_files')->insertGetId([
            'customer_id' => $this->customer, 'uploaded_by' => $this->student->id, 'file_type' => $type, 'mime_type' => $mime,
            'original_name' => $name, 'display_name' => $name, 'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'storage_disk' => 'media_local', 'storage_bucket' => 'lf-test-media', 'storage_key' => 'tenants/'.$this->customer.'/'.$name,
            'checksum' => 'sha256:'.hash('sha256', $name), 'file_size_bytes' => 32, 'visibility' => 'private', 'status' => 'ready',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<int> */
    private function ids(string $html, string $attribute): array
    {
        preg_match_all('#'.$attribute.'="(\d+)"#', $html, $found);

        return array_map('intval', $found[1]);
    }

    private function lessonState(string $html, int $lesson): ?string
    {
        return preg_match('#data-learn-lesson="'.$lesson.'" data-learn-lesson-state="(\w+)"#', $html, $match) ? $match[1] : null;
    }

    /** @return array{0: int, 1: int, 2: int} product, version, template */
    private function course(int $customer, string $title, string $type): array
    {
        $admin = $this->user($customer, 'customer_admin');
        $product = DB::table('core_course_products')->insertGetId([
            'customer_id' => $customer, 'product_code' => 'P-'.uniqid(), 'product_type' => 'single_course',
            'title' => $title, 'slug' => 'p-'.uniqid(), 'thumbnail_type' => 'image', 'price' => 0, 'currency' => 'VND',
            'enrollment_type' => 'paid', 'enrollment_count' => 0, 'is_certificate_enabled' => false, 'is_refundable' => false,
            'show_enrollment_count' => true, 'is_featured' => false, 'sort_order' => 0, 'visibility' => 'public',
            'status' => 'active', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $template = DB::table('core_course_templates')->insertGetId([
            'course_type' => $type, 'customer_id' => $customer, 'title' => $title,
            'estimated_minutes_per_lesson' => 0, 'lesson_count' => 0, 'working_revision' => 1,
            'status' => 'active', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = DB::table('core_course_template_versions')->insertGetId([
            'course_type_snapshot' => $type, 'customer_id' => $customer, 'template_id' => $template,
            'version_number' => 1, 'version_code' => 'V-'.$template, 'is_current' => true,
            'title_snapshot' => $title, 'estimated_minutes_per_lesson_snapshot' => 0, 'lesson_count_snapshot' => 0,
            'source_working_revision' => 1, 'status' => 'published', 'published_at' => now(), 'published_by' => $admin->id,
            'source_template_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$product, $version, $template];
    }

    private function lesson(int $version, string $title, int $order, array $extra = []): int
    {
        static $source = 90000;

        return DB::table('core_course_template_version_lessons')->insertGetId($extra + [
            'customer_id' => $this->customer, 'template_version_id' => $version, 'source_template_lesson_id' => ++$source,
            'title_snapshot' => $title, 'sort_order' => $order, 'unlock_rule_snapshot' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function enroll(User $student, int $product, int $version, array $extra = []): int
    {
        return DB::table('core_course_enrollments')->insertGetId($extra + [
            'customer_id' => $student->customer_id, 'product_id' => $product, 'version_id' => $version,
            'student_id' => $student->id, 'source' => 'admin', 'enrolled_at' => now(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cohort(int $product, int $version, string $name, string $status): int
    {
        return DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $this->customer, 'product_id' => $product, 'version_id' => $version, 'name' => $name,
            'code' => 'COH-'.uniqid(), 'status' => $status, 'start_date' => '2026-07-01', 'end_date' => '2026-08-31',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seat(int $cohort, int $enrollment, User $student, int $product, array $extra = []): void
    {
        DB::table('core_course_cohort_students')->insert($extra + [
            'customer_id' => $this->customer, 'cohort_id' => $cohort, 'enrollment_id' => $enrollment,
            'product_id' => $product, 'student_id' => $student->id, 'joined_at' => '2026-07-01 00:00:00',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function liveSession(int $cohort, int $version, string $title, string $start, string $end, string $status = 'scheduled'): int
    {
        static $number = 0;

        return DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $this->customer, 'cohort_id' => $cohort, 'template_version_id' => $version,
            'session_type' => 'operational', 'session_no' => ++$number, 'title' => $title, 'delivery_mode' => 'offline',
            'scheduled_start_at' => $start, 'scheduled_end_at' => $end, 'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function tenant(string $slug): int
    {
        return DB::table('saas_customers')->insertGetId([
            'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(int $customer, string $role, ?string $name = null): User
    {
        return User::forceCreate([
            'customer_id' => $customer, 'name' => $name ?? ucfirst($role).' '.uniqid(), 'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }
}
