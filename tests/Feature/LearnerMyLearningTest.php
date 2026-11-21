<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LearnerMyLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Js;
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

        // 1:1 advice and AI Speak are shown but only say "coming soon". "Quan tâm" of courses works (ADR-0030 D4); the
        // classes and tests tabs keep it locked. "Kết quả học tập" opens its page (ADR-0031).
        $this->assertStringContainsString('data-coming-soon-message="'.__('lf.LF_student_site_coming_soon').'"', $html);
        $locked = fn (string $label, string $page): int => preg_match_all('#\sdata-coming-soon[\s>][^>]*>?(?:(?!</button>).)*'.preg_quote($label, '#').'#s', $page);
        $this->assertSame(0, $locked(__('lf.LF_student_site_my_results'), $html));
        $this->assertSame(0, $locked(__('lf.LF_my_learning_view_favorite'), $html));
        $this->assertStringContainsString('data-learn-view="favorite"', $html);
        $this->assertSame(0, preg_match_all('#class="student-products__like" data-coming-soon#', $html));
        // One heart per card; the filter kept one card.
        $this->assertSame(1, preg_match_all('#data-favorite="\d+" aria-pressed="false"#', $html));
        $this->assertSame(1, $locked('aispeak.svg', $html));
        $exams = $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=exams')->assertOk()->getContent();
        $this->assertSame(1, $locked(__('lf.LF_my_learning_view_favorite'), $exams));
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
        // The MK "learning course" layout: three tabs, the lesson search, and what each Lesson holds.
        $this->activity($version, $first, 'Video mở đầu', 'video', 1);
        $this->activity($version, $first, 'Nghe mẫu', 'audio', 2);
        $html = $this->actingAs($this->student)->get(self::HOST.'/my-courses/'.$enrollment)->assertOk()->getContent();
        $this->assertSame(['lessons', 'intro', 'teachers'], array_map('strval', $this->tabs($html)));
        $this->assertStringContainsString('data-learn-search', $html);
        // "Vào học" opens the Lesson in the popup; the link itself still leads to the lesson page.
        $this->assertSame(1, substr_count($html, 'data-learn-open '));
        $this->assertStringContainsString('href="'.$this->lessonUrl($enrollment, $first).'"'."\n".str_repeat(' ', 43).'data-learn-open', $html);
        $this->assertStringContainsString('data-learn-modal', $html);
        $this->assertStringContainsString(__('lf.LF_my_learning_detail_watched_video'), $html);
        $this->assertStringContainsString(__('lf.LF_my_learning_detail_state_new'), $html);
        $this->assertSame(2, substr_count($html, 'lf-cd-action is-disabled'));

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
        // The assistant is assigned first; the primary teacher is still named first.
        $assistant = $this->user($this->customer, 'teacher', 'Cô Lan');
        foreach ([[$assistant, 'assistant'], [$teacher, 'primary_teacher']] as [$person, $role]) {
            DB::table('core_course_cohort_teachers')->insert([
                'customer_id' => $this->customer, 'cohort_id' => $cohort, 'teacher_id' => $person->id, 'role' => $role,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $enrollment = $this->enroll($this->student, $product, $version);
        $this->seat($cohort, $enrollment, $this->student, $product);

        $attended = $this->liveSession($cohort, $version, 'Buổi 1', '2026-07-03 19:00:00', '2026-07-03 21:00:00', 'completed');
        $missed = $this->liveSession($cohort, $version, 'Buổi 2', '2026-07-06 19:00:00', '2026-07-06 21:00:00', 'completed');
        $cancelled = $this->liveSession($cohort, $version, 'Buổi hủy', '2026-07-08 19:00:00', '2026-07-08 21:00:00', 'cancelled');
        $next = $this->liveSession($cohort, $version, 'Buổi 3', '2026-07-13 19:00:00', '2026-07-13 21:00:00');
        // Only a completed Session is held (Owner 2026-11-18): one left `scheduled` after its time and one still
        // running count in the total, not as learned, and a mark in the running one is not attendance yet.
        $skipped = $this->liveSession($cohort, $version, 'Buổi lỡ', '2026-07-01 19:00:00', '2026-07-01 21:00:00');
        $running = $this->liveSession($cohort, $version, 'Buổi đang dạy', '2026-07-09 19:00:00', '2026-07-09 21:00:00', 'live');
        foreach ([[$attended, '2026-07-03 19:01:00'], [$running, '2026-07-09 19:01:00']] as [$session, $joinedAt]) {
            DB::table('core_liveclass_attendances')->insert([
                'customer_id' => $this->customer, 'session_id' => $session, 'enrollment_id' => $enrollment, 'user_id' => $this->student->id,
                'status' => 'present', 'attendance_mode' => 'offline', 'joined_at' => $joinedAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

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
        $this->assertStringContainsString('Thầy Minh, Cô Lan', $list);
        $this->assertStringContainsString(__('lf.LF_my_learning_class_attendance').': 50%', $list);
        $this->assertStringContainsString(__('lf.LF_my_learning_class_sessions', ['held' => 2, 'total' => 5]), $list);
        $done = $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=classes&view=completed')->assertOk()->getContent();
        $this->assertSame([$finished], $this->ids($done, 'data-learn-class'));
        // Class-based courses are not listed among self-paced courses.
        $this->assertSame([], $this->ids($this->actingAs($this->student)->get(self::HOST.'/my-courses')->getContent(), 'data-learn-course'));

        $detail = $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$cohort)->assertOk()->getContent();
        $this->assertSame([$skipped, $attended, $missed, $cancelled, $running, $next], $this->ids($detail, 'data-learn-session'));
        // A held Session older than automatic absence looks back no longer waits for a result.
        Carbon::setTestNow('2026-07-20 09:00:00');
        $old = $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$cohort)->assertOk()->getContent();
        $this->assertStringNotContainsString('data-learn-attendance-pending', strstr(substr($old, strpos($old, 'data-learn-session="'.$missed.'"')), 'id="lesson-content-', true));
        Carbon::setTestNow('2026-07-10 09:00:00');
        $this->assertMatchesRegularExpression('#data-learn-session="'.$attended.'".*?data-learn-attendance="present"#s', $detail);
        $this->assertMatchesRegularExpression('#data-learn-session="'.$missed.'".*?data-learn-attendance="none"#s', $detail);
        // MK 3.1: the entry time and mode sit under the result; a held Session past its end with no result waits
        // for one, while a Session nobody started waits for nothing.
        // The Session's header ends where its contents start.
        $row = fn (int $id): string => strstr(substr($detail, strpos($detail, 'data-learn-session="'.$id.'"')), 'id="lesson-content-', true);
        $this->assertMatchesRegularExpression('#data-learn-attendance-entry>\s*'.preg_quote(__('lf.LF_my_learning_board_entered', ['time' => '19:01'])).' · '.__('lf.LF_my_learning_board_entered_offline').'#u', $row($attended));
        $this->assertStringContainsString('data-learn-attendance-pending', $row($missed));
        $this->assertStringContainsString(__('lf.LF_my_learning_attendance_pending'), $row($missed));
        $this->assertStringNotContainsString('data-learn-attendance-entry', $row($missed));
        $this->assertStringNotContainsString('data-learn-attendance-pending', $row($skipped));
        $this->assertStringNotContainsString('data-learn-attendance-pending', $row($attended));

        // MK 3.1: the block of sessions to act on now refreshes itself from a fragment, narrowed to the page's class.
        Carbon::setTestNow('2026-07-09 19:30:00');
        $fragment = self::HOST.'/my-courses/live-sessions/now';
        // The class page (MK Live layout) puts the actions in the Session's own "Trong buổi học" row and reloads
        // itself when that fragment of its class changes.
        $page = $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$cohort)->assertOk()
            ->assertSee('data-live-refresh-url="'.$fragment.'?cohort='.$cohort.'"', false)->getContent();
        $this->assertSame([$running], $this->ids($page, 'data-live-session'));
        $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=classes')->assertOk()
            ->assertSee('data-live-sessions-block data-url="'.$fragment.'"', false);
        $now = $this->actingAs($this->student)->get($fragment)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([$running], $this->ids($now->getContent(), 'data-live-session'));
        $this->assertSame([$running], $this->ids($this->actingAs($this->student)->get($fragment.'?cohort='.$cohort)->getContent(), 'data-live-session'));
        $this->assertSame('', $this->actingAs($this->student)->get($fragment.'?cohort='.$foreign)->assertOk()->getContent());
        $this->assertSame('', $this->actingAs($otherStudent)->get($fragment.'?cohort='.$cohort)->assertOk()->getContent());
        Carbon::setTestNow('2026-07-10 09:00:00');
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
        $quiz = $this->activity($version, $lesson, 'Kiểm tra', 'quiz', 4, ['completion_rule' => 'submit', 'is_required' => 0]);
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

        // A quiz moves only through its attempts (ADR-0032 D3); an optional one does not hold the Lesson back.
        $send($quiz, ['event' => 'opened'])->assertForbidden();
        $send($quiz, ['event' => 'completed'])->assertForbidden();
        $lessonRow = DB::table('core_course_lesson_progress')->where('enrollment_id', $enrollment)->sole();
        $this->assertSame(['completed', 3, 4], [$lessonRow->status, (int) $lessonRow->completed_activities, (int) $lessonRow->total_activities]);
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

    public function test_an_external_link_opens_in_a_new_tab_and_completes_when_opened(): void
    {
        [$product, $version] = $this->course($this->customer, 'Luyện Wings', 'self_paced');
        $lesson = $this->lesson($version, 'Bài 1', 1);
        $first = $this->activity($version, $lesson, 'Wings bài 1', 'external_link', 1, ['external_link_url_snapshot' => 'https://wings.example.test/u/1']);
        $second = $this->activity($version, $lesson, 'Wings bài 2', 'external_link', 2, ['external_link_url_snapshot' => 'https://wings.example.test/u/2',
            'unlock_rule_snapshot' => 'previous_activity_completed', 'unlock_after_version_activity_id' => $first]);
        $broken = $this->activity($version, $lesson, 'Link hỏng', 'external_link', 3, ['external_link_url_snapshot' => 'http://wings.example.test/u/3', 'is_required' => 0]);
        $enrollment = $this->enroll($this->student, $product, $version);

        $html = $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $lesson))->assertOk()
            ->assertSeeText(__('lf.LF_my_learning_activity_open_link'))->getContent();
        // ADR-0035 §2: a new tab without opener or referrer; the click records the opening.
        $this->assertStringContainsString('href="https://wings.example.test/u/1" target="_blank" rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('x-on:click="openLink()" data-learn-open-link', $html);
        // A locked Activity's link, and an invalid one, never reach the page.
        $this->assertStringNotContainsString('wings.example.test/u/2', $html);
        $this->assertStringNotContainsString('wings.example.test/u/3', $html);
        $this->assertSame(1, substr_count($html, 'data-learn-open-link'));

        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $first), ['event' => 'opened'])
            ->assertOk()->assertJsonPath('progress.status', 'completed');
        $this->assertSame('viewed', $this->activityRow($enrollment, $first)->completion_rule);
        $this->assertStringContainsString('href="https://wings.example.test/u/2"',
            $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $lesson))->assertOk()->getContent());
        $this->assertNull($this->activityRow($enrollment, $broken));
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
        $learning = app(LearnerMyLearningService::class);
        $course = $learning->course($this->customer, $this->student->id, $enrollment);
        $this->assertSame([$second, $first], $course->sections->flatMap(fn ($section) => $section->lessons)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([$two, $one], $learning->lesson($this->customer, $this->student->id, $enrollment, $first)->activities->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $one), ['event' => 'opened'])->assertOk();

        // No hidden pages: a class course and its lessons are learned on the class page, the one the menu leads to.
        $classUrl = self::HOST.'/my-courses/classes/'.$cohort;
        $this->actingAs($this->student)->get(self::HOST.'/my-courses/'.$enrollment)->assertRedirect($classUrl);
        $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $first))->assertRedirect($classUrl);
        // Without a class to open yet, both wait in the classes tab.
        DB::table('core_course_cohorts')->where('id', $cohort)->update(['status' => 'draft']);
        $this->actingAs($this->student)->get(self::HOST.'/my-courses/'.$enrollment)->assertRedirect(self::HOST.'/my-courses?tab=classes');
        $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $first))->assertRedirect(self::HOST.'/my-courses?tab=classes');
    }

    public function test_the_class_page_shows_when_each_scheduled_activity_opens(): void
    {
        // ADR-0022 Amendment 1.3, Đợt 2: content not open yet says when it opens, or that the Lesson has no Session.
        [$product, $version] = $this->course($this->customer, 'Sơ cấp 2A', 'blended');
        $first = $this->lesson($version, 'Bài A', 1);
        $second = $this->lesson($version, 'Bài B', 2);
        $phase = fn (string $phase): array => ['available_anytime' => false, 'available_'.$phase.'_session' => true];
        $live = $this->activity($version, $first, 'Lên lớp', 'live_class', 1, $phase('during') + ['completion_rule' => 'join']);
        $before = $this->activity($version, $first, 'Đọc trước', 'document', 2, $phase('before'));
        $after = $this->activity($version, $first, 'Bài tập về nhà', 'document', 3, $phase('after'));
        $unscheduled = $this->activity($version, $second, 'Đọc bài B', 'document', 1, $phase('before'));
        $cohort = $this->cohort($product, $version, 'Lớp 2A', 'active');
        $enrollment = $this->enroll($this->student, $product, $version);
        $this->seat($cohort, $enrollment, $this->student, $product);
        $day = now('Asia/Ho_Chi_Minh')->addDays(2)->toDateString();
        $session = DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $this->customer, 'cohort_id' => $cohort, 'template_version_id' => $version,
            'session_type' => 'curriculum', 'version_lesson_id' => $first, 'version_activity_id' => $live,
            'session_no' => 1, 'title' => 'Buổi 1', 'delivery_mode' => 'offline',
            'scheduled_start_at' => $day.' 19:30:00', 'scheduled_end_at' => $day.' 21:00:00', 'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $date = Carbon::parse($day)->format('d/m');

        // The lesson's address leads to its Session on the class page, which says when each part opens.
        $classUrl = self::HOST.'/my-courses/classes/'.$cohort;
        $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $first))->assertRedirect($classUrl.'#live-lesson-'.$session);
        $page = $this->actingAs($this->student)->get($classUrl)->assertOk();
        $page->assertSee(__('lf.LF_my_learning_board_opens_at', ['time' => '00:00', 'date' => $date]));
        $page->assertSee(__('lf.LF_my_learning_board_opens_at', ['time' => '21:00', 'date' => $date]));
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $before), ['event' => 'opened'])->assertForbidden();
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $after), ['event' => 'opened'])->assertForbidden();

        // A Lesson without a Session is not on the class page and opens nothing.
        $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $second))->assertRedirect($classUrl);
        $page->assertDontSee('Đọc bài B');
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $unscheduled), ['event' => 'opened'])->assertForbidden();

        Carbon::setTestNow(Carbon::parse($day.' 00:00:00', 'Asia/Ho_Chi_Minh'));
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $before), ['event' => 'opened'])->assertOk();
        Carbon::setTestNow();
    }

    public function test_the_class_page_lists_a_self_study_session_without_a_delivery_mode(): void
    {
        // ADR-0022 Amendment 1.3, Đợt 4, Bước 2: a self-study lesson has a date on the class schedule.
        [$product, $version] = $this->course($this->customer, 'Sơ cấp 3A', 'blended');
        $lesson = $this->lesson($version, 'Ôn tập', 1, ['lesson_type' => 'self_study']);
        $cohort = $this->cohort($product, $version, 'Lớp 3A', 'active');
        $enrollment = $this->enroll($this->student, $product, $version);
        $this->seat($cohort, $enrollment, $this->student, $product);
        $session = DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $this->customer, 'cohort_id' => $cohort, 'template_version_id' => $version,
            'session_type' => 'self_study', 'version_lesson_id' => $lesson, 'version_activity_id' => null,
            'session_no' => 1, 'title' => 'Ôn tập', 'delivery_mode' => null,
            'scheduled_start_at' => '2026-07-20 19:00:00', 'scheduled_end_at' => '2026-07-20 21:00:00', 'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$cohort)->assertOk()
            ->assertSee('data-learn-session="'.$session.'"', false)
            ->assertSeeText(__('lf.LF_course_cohort_session_type_self_study'))
            ->assertSee('data-learn-session-shown="scheduled"', false);
        // It has no "Bắt đầu" and stays scheduled; inside its planned time the learner sees it as running.
        Carbon::setTestNow(Carbon::parse('2026-07-20 20:00:00', 'Asia/Ho_Chi_Minh'));
        $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$cohort)->assertOk()
            ->assertSee('data-learn-session-status="scheduled" data-learn-session-shown="live"', false)
            ->assertSeeText(__('lf.LF_my_learning_session_status_live'));
        Carbon::setTestNow('2026-07-10 09:00:00');
        $this->actingAs($this->student)->get(self::HOST.'/my-courses')->assertOk();
        $this->actingAs($this->student)->get(self::HOST.'/my-courses/'.$enrollment)->assertRedirect(self::HOST.'/my-courses/classes/'.$cohort);
    }

    public function test_the_class_video_popup_shows_its_resume_question_and_watched_share_like_mk(): void
    {
        // MK Live "Video tải lên": the popup asks "Tiếp tục xem / Xem từ đầu" and its footer shows the last opening and
        // "Tiến độ xem: hh:mm:ss ~ %". The card's share is the same floored figure.
        [$product, $version] = $this->course($this->customer, 'Sơ cấp 5A', 'blended');
        $lesson = $this->lesson($version, 'Luyện nghe', 1);
        $live = $this->activity($version, $lesson, 'Lên lớp', 'live_class', 1, ['completion_rule' => 'join']);
        $video = $this->activity($version, $lesson, 'Video chữa bài', 'video', 2, ['completion_rule' => 'watch_percent', 'completion_threshold' => 80, 'duration_seconds' => 600]);
        $manual = $this->activity($version, $lesson, 'Tự luyện', 'document', 3, ['completion_rule' => 'manual']);
        $cohort = $this->cohort($product, $version, 'Lớp 5A', 'active');
        $enrollment = $this->enroll($this->student, $product, $version);
        $this->seat($cohort, $enrollment, $this->student, $product);
        DB::table('core_liveclass_sessions')->insert([
            'customer_id' => $this->customer, 'cohort_id' => $cohort, 'template_version_id' => $version,
            'session_type' => 'curriculum', 'version_lesson_id' => $lesson, 'version_activity_id' => $live,
            'session_no' => 1, 'title' => 'Luyện nghe', 'delivery_mode' => 'offline',
            'scheduled_start_at' => '2026-07-08 18:30:00', 'scheduled_end_at' => '2026-07-08 20:00:00', 'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $page = fn (): string => $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$cohort)->assertOk()->getContent();
        $item = function (string $html) use ($video): string {
            $start = strpos($html, 'data-learn-activity="'.$video.'"');
            $this->assertNotFalse($start);

            return substr($html, $start, strpos($html, '</dialog>', $start) - $start);
        };
        // Not opened yet: no last opening, nothing watched, the resume question hidden until asked.
        $fresh = $item($page());
        $this->assertStringContainsString('Tiến độ xem', $fresh);
        $this->assertStringContainsString('data-upload-resume', $fresh);
        $this->assertStringContainsString('data-upload-continue', $fresh);
        $this->assertStringContainsString('class="learning-upload-restart"', $fresh);
        $this->assertMatchesRegularExpression('#<time x-show="viewed" x-text="viewed"\s+x-cloak\s*></time>#', $fresh);

        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $video), ['event' => 'opened'])->assertOk();
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $video), ['event' => 'position', 'position_seconds' => 20])->assertOk();
        Carbon::setTestNow('2026-07-10 09:02:00');
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $video), ['event' => 'position', 'position_seconds' => 150])->assertOk();

        $watched = $item($page());
        // The card follows playback live (MK "Đã nghe 25%"), then shows the completed label.
        $this->assertStringContainsString('x-show="cardPercent > 0" x-text="cardPercent + \'%\'" >25%</span>', $watched);
        $this->assertStringContainsString('x-text="completed ? '.Js::from(__('lf.LF_my_learning_board_state_watched')).' : \'\'"', $watched);
        $this->assertStringContainsString('>2026-07-10 09:02</time>', $watched);
        $this->assertStringContainsString('00:02:30', $watched);

        // "Đánh dấu hoàn thành" is on the class page too: the lesson page is not a class learner's.
        $manualItem = function () use ($page, $manual): string {
            $html = $page();
            $start = strpos($html, 'data-learn-activity="'.$manual.'"');
            $this->assertNotFalse($start);

            return substr($html, $start, strpos($html, 'learning-board-item ', $start + 1) ?: strlen($html) - $start);
        };
        $this->assertMatchesRegularExpression('#x-show="! completed"\s+x-on:click="markDone\(\)"[^>]*data-learn-mark-done#', $manualItem());
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $manual), ['event' => 'completed'])->assertOk()
            ->assertJsonPath('progress.status', 'completed');
        $this->assertMatchesRegularExpression('#x-show="! completed"\s+x-cloak\s+x-on:click="markDone\(\)"#', $manualItem());
    }

    public function test_the_lesson_list_shows_each_lessons_played_share_and_quiz_like_mk(): void
    {
        // MK "learning course": a row's state and meter follow its video or audio; the course figure beside them is the
        // mean over the Lessons with media, an unplayed one counting 0. Progress belongs to this Enrollment only.
        [$product, $version] = $this->course($this->customer, 'Sơ cấp tự học', 'self_paced');
        $watched = $this->lesson($version, 'Bảng chữ cái', 1);
        $fresh = $this->lesson($version, 'Phát âm', 2);
        $reading = $this->lesson($version, 'Đọc thêm', 3);
        $video = $this->activity($version, $watched, 'Video', 'video', 1, ['completion_rule' => 'watch_percent', 'completion_threshold' => 80, 'duration_seconds' => 600]);
        $quiz = $this->activity($version, $watched, 'Kiểm tra bài 1', 'quiz', 2, ['completion_rule' => 'submit']);
        $this->activity($version, $fresh, 'Nghe', 'audio', 1, ['completion_rule' => 'watch_percent', 'completion_threshold' => 80, 'duration_seconds' => 60]);
        $this->activity($version, $reading, 'Tài liệu', 'document', 1);
        $enrollment = $this->enroll($this->student, $product, $version, ['access_starts_at' => '2026-07-01 00:00:00', 'access_ends_at' => '2026-07-21 00:00:00']);
        // The same course bought again (another Enrollment) keeps its own progress.
        $other = $this->enroll($this->student, $product, $version, ['access_starts_at' => '2026-07-01 00:00:00', 'access_ends_at' => '2026-07-21 00:00:00']);

        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $video), ['event' => 'opened'])->assertOk();
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $video), ['event' => 'position', 'position_seconds' => 20])->assertOk();
        Carbon::setTestNow('2026-07-10 09:02:00');
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $video), ['event' => 'position', 'position_seconds' => 150])->assertOk();

        $html = $this->actingAs($this->student)->get(self::HOST.'/my-courses/'.$enrollment)->assertOk()->getContent();
        $row = function (int $lesson) use ($html): string {
            $start = strpos($html, 'data-learn-lesson="'.$lesson.'"');
            $this->assertNotFalse($start);

            return substr($html, $start, strpos($html, '</li>', $start) - $start);
        };
        $this->assertStringContainsString('data-learn-lesson-badge="progress"', $row($watched));
        $this->assertStringContainsString(__('lf.LF_my_learning_detail_state_watching'), $row($watched));
        $this->assertStringContainsString(__('lf.LF_my_learning_detail_watched_video'), $row($watched));
        $this->assertStringContainsString('value="25"', $row($watched));
        $this->assertStringContainsString('data-learn-open-seconds="150" data-learn-open-percent="25"', $row($watched));
        $this->assertStringContainsString('data-learn-open-viewed="2026-07-10 09:02"', $row($watched));
        // The Quiz: "Kiểm tra: chưa làm" and its own button to the lesson page, at the Quiz.
        $this->assertStringContainsString(__('lf.LF_my_learning_detail_quiz_none'), $row($watched));
        $this->assertStringContainsString('href="'.$this->lessonUrl($enrollment, $watched).'#activity-'.$quiz.'" data-learn-quiz-link', $row($watched));
        $this->assertStringContainsString('data-learn-lesson-badge="new"', $row($fresh));
        $this->assertStringContainsString(__('lf.LF_my_learning_detail_watched_audio'), $row($fresh));
        $this->assertStringNotContainsString('data-learn-quiz-link', $row($fresh));
        // Without video or audio the row follows the Lesson's own progress.
        $this->assertStringContainsString(__('lf.LF_my_learning_detail_learned'), $row($reading));
        // (25 + 0) / 2 Lessons with media; the Lessons completed stay a separate count.
        // The popup asks "Tiếp tục xem / Xem từ đầu" for a video stopped part-way (MK).
        $this->assertStringContainsString('data-learn-modal-resume hidden', $html);
        $this->assertStringContainsString(__('lf.LF_my_learning_detail_resume_question'), $html);
        $this->assertStringContainsString('data-learn-modal-resume-continue>'.__('lf.LF_my_learning_detail_continue').'</button>', $html);
        $this->assertMatchesRegularExpression('#data-learn-ratio="watch">\s*<span>'.preg_quote(__('lf.LF_my_learning_detail_watch_ratio')).'</span>\s*<progress value="12"#', $html);
        $this->assertStringContainsString('0/'.__('lf.LF_my_learning_detail_lessons_unit', ['count' => 3]), $html);

        // The lesson page gives the popup what its footer shows; the Quiz has its anchor.
        $lesson = $this->actingAs($this->student)->get($this->lessonUrl($enrollment, $watched))->assertOk()->getContent();
        $this->assertStringContainsString('id="activity-'.$video.'"', $lesson);
        $this->assertStringContainsString('data-learn-activity-duration="600" data-learn-activity-percent="25" data-learn-activity-seconds="150" data-learn-activity-resume="150" data-learn-activity-viewed="2026-07-10 09:02"', $lesson);
        $this->assertStringContainsString('id="activity-'.$quiz.'"', $lesson);

        $again = $this->actingAs($this->student)->get(self::HOST.'/my-courses/'.$other)->assertOk()->getContent();
        $this->assertStringContainsString('data-learn-open-seconds="0" data-learn-open-percent="0"', $again);
        $this->assertStringNotContainsString('data-learn-lesson-badge="progress"', $again);
    }

    public function test_a_lesson_over_several_sessions_shows_before_on_the_first_and_after_on_the_last(): void
    {
        // ADR-0022 Amendment 1.3, Đợt 2: "Trước" opens with the Lesson's first Session, "Sau" with its last.
        // A cancelled Session stays on the schedule without a board and is not one of the Lesson's Sessions.
        [$product, $version] = $this->course($this->customer, 'Sơ cấp 4A', 'blended');
        $lesson = $this->lesson($version, 'Thực hành', 1);
        $phase = fn (string $phase): array => ['available_anytime' => false, 'available_'.$phase.'_session' => true];
        $live = $this->activity($version, $lesson, 'Lên lớp thực hành', 'live_class', 1, $phase('during') + ['completion_rule' => 'join']);
        $this->activity($version, $lesson, 'Nghe mẫu trước giờ', 'document', 2, $phase('before'));
        $this->activity($version, $lesson, 'Nộp bài sau giờ', 'document', 3, $phase('after'));
        $cohort = $this->cohort($product, $version, 'Lớp 4A', 'active');
        $enrollment = $this->enroll($this->student, $product, $version);
        $this->seat($cohort, $enrollment, $this->student, $product);
        $session = fn (int $no, string $day, string $status): int => DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $this->customer, 'cohort_id' => $cohort, 'template_version_id' => $version,
            'session_type' => 'curriculum', 'version_lesson_id' => $lesson, 'version_activity_id' => $live,
            'session_no' => $no, 'title' => 'Thực hành '.$no, 'delivery_mode' => 'offline',
            'scheduled_start_at' => $day.' 18:30:00', 'scheduled_end_at' => $day.' 20:00:00', 'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => $status, 'cancellation_reason' => $status === 'cancelled' ? 'Giáo viên ốm' : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $first = $session(1, '2026-07-20', 'scheduled');
        $cancelled = $session(2, '2026-07-22', 'cancelled');
        $last = $session(3, '2026-07-24', 'scheduled');

        $page = $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$cohort)->assertOk()->getContent();
        $block = function (int $id) use ($page): string {
            $start = strpos($page, 'id="live-lesson-'.$id.'"');
            $this->assertNotFalse($start);
            $next = strpos($page, 'id="live-lesson-', $start + 1);

            return substr($page, $start, $next === false ? null : $next - $start);
        };
        $this->assertStringContainsString('Nghe mẫu trước giờ', $block($first));
        $this->assertStringNotContainsString('Nộp bài sau giờ', $block($first));
        $this->assertStringContainsString('Lên lớp thực hành', $block($first));
        $this->assertStringContainsString('Nộp bài sau giờ', $block($last));
        $this->assertStringNotContainsString('Nghe mẫu trước giờ', $block($last));
        $this->assertStringContainsString('Lên lớp thực hành', $block($last));
        $this->assertStringContainsString('Giáo viên ốm', $block($cancelled));
        $this->assertStringNotContainsString('lesson-content-'.$cancelled, $block($cancelled));

        // "Quay lại lớp học" lands where the content is shown: "Trước" on the first Session, "Sau" on the last,
        // the rest on the latest Session already started (else the first). Cancelled Sessions are skipped.
        $learning = app(LearnerMyLearningService::class);
        $back = fn (?string $phase): ?string => $learning->classReturnUrl($this->customer, $this->student->id, $enrollment, $lesson, $phase);
        $classUrl = self::HOST.'/my-courses/classes/'.$cohort;
        $this->assertSame($classUrl.'#live-lesson-'.$first, $back('before'));
        $this->assertSame($classUrl.'#live-lesson-'.$last, $back('after'));
        $this->assertSame($classUrl.'#live-lesson-'.$first, $back(null));
        Carbon::setTestNow(Carbon::parse('2026-07-25 09:00:00', 'Asia/Ho_Chi_Minh'));
        $this->assertSame($classUrl.'#live-lesson-'.$last, $back(null));
        $this->assertSame($classUrl.'#live-lesson-'.$first, $back('before'));
    }

    public function test_a_class_that_has_ended_is_review_only(): void
    {
        // ADR-0022 Amendment 1.4 (MK 3.6): the content still opens, nothing adds progress.
        [$product, $version] = $this->course($this->customer, 'Sơ cấp 4A', 'blended');
        $lesson = $this->lesson($version, 'Ôn tập', 1, ['lesson_type' => 'self_study']);
        $done = $this->activity($version, $lesson, 'Đã đọc', 'document', 1);
        $later = $this->activity($version, $lesson, 'Chưa đọc', 'document', 2);
        $cohort = $this->cohort($product, $version, 'Lớp 4A', 'active');
        $enrollment = $this->enroll($this->student, $product, $version);
        $this->seat($cohort, $enrollment, $this->student, $product);
        DB::table('core_liveclass_sessions')->insert([
            'customer_id' => $this->customer, 'cohort_id' => $cohort, 'template_version_id' => $version,
            'session_type' => 'self_study', 'version_lesson_id' => $lesson, 'version_activity_id' => null,
            'session_no' => 1, 'title' => 'Ôn tập', 'delivery_mode' => null,
            'scheduled_start_at' => '2026-07-01 19:00:00', 'scheduled_end_at' => '2026-07-01 21:00:00', 'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $status = fn (int $activity): ?string => DB::table('core_course_activity_progress')->where('enrollment_id', $enrollment)
            ->where('version_activity_id', $activity)->value('status');

        // While the class runs, opening a document completes it.
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $done), ['event' => 'opened'])->assertOk();
        $this->assertSame('completed', $status($done));
        $before = (float) DB::table('core_course_progress')->where('enrollment_id', $enrollment)->value('progress_percentage');

        DB::table('core_course_cohorts')->where('id', $cohort)->update(['status' => 'completed']);
        $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$cohort)->assertOk()
            ->assertSee('data-learn-review-only', false)->assertSeeText(__('lf.LF_my_learning_review_only'));
        // The content is still served, and opening it records nothing.
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $later), ['event' => 'opened'])->assertOk()
            ->assertJsonPath('progress.status', 'not_started');
        $this->assertSame('not_started', $status($later));
        $this->assertSame($before, (float) DB::table('core_course_progress')->where('enrollment_id', $enrollment)->value('progress_percentage'));
        $this->assertSame('completed', $status($done));

        // A class still running shows no such notice.
        DB::table('core_course_cohorts')->where('id', $cohort)->update(['status' => 'active']);
        $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$cohort)->assertOk()->assertDontSee('data-learn-review-only', false);
    }

    public function test_a_class_whose_enrollment_ran_out_says_so_and_counts_down_to_nothing(): void
    {
        // Display only: the class card and page say the Enrollment ended; upcoming Sessions offer no countdown.
        [$product, $version] = $this->course($this->customer, 'Sơ cấp 5A', 'blended');
        $cohort = $this->cohort($product, $version, 'Lớp 5A', 'active');
        $enrollment = $this->enroll($this->student, $product, $version, [
            'access_starts_at' => '2026-07-01 00:00:00', 'access_ends_at' => '2026-07-20 00:00:00',
        ]);
        $this->seat($cohort, $enrollment, $this->student, $product);
        $this->liveSession($cohort, $version, 'Buổi 1', '2026-07-03 19:00:00', '2026-07-03 21:00:00', 'completed');
        $this->liveSession($cohort, $version, 'Buổi 2', '2026-07-13 19:00:00', '2026-07-13 21:00:00');
        $page = fn (): string => $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$cohort)->assertOk()->getContent();
        $list = fn (): string => $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=classes')->assertOk()->getContent();

        // Inside the window: the countdown and the way in are there, no notice.
        $this->assertStringContainsString('data-learn-countdown="', $page());
        $this->assertStringNotContainsString('data-learn-access-hold', $page());
        $this->assertStringContainsString('btn-live-class', $list());

        // The job has expired it: the card is labelled, the page explains, nothing counts down.
        DB::table('core_course_enrollments')->where('id', $enrollment)->update(['status' => 'expired', 'access_ends_at' => '2026-07-08 00:00:00', 'expired_at' => '2026-07-08 00:05:00']);
        $card = $list();
        $this->assertSame([$cohort], $this->ids($card, 'data-learn-class'));
        $this->assertStringContainsString('data-learn-access-hold="ended"', $card);
        $this->assertStringContainsString(__('lf.LF_my_learning_class_access_ended'), $card);
        $this->assertStringNotContainsString('btn-live-class', $card);
        $detail = $page();
        $this->assertStringContainsString(e(__('lf.LF_my_learning_class_access_ended_notice', ['date' => '08/07/2026'])), $detail);
        $this->assertStringNotContainsString('data-learn-countdown="', $detail);
        $this->assertStringContainsString(__('lf.LF_my_learning_board_entry_access_ended'), $detail);

        // A window that is over before the job ran reads the same.
        DB::table('core_course_enrollments')->where('id', $enrollment)->update(['status' => 'active', 'expired_at' => null]);
        $this->assertStringContainsString('data-learn-access-hold="ended"', $page());
        $this->assertStringNotContainsString('data-learn-countdown="', $page());
    }

    public function test_a_suspended_class_enrollment_says_so_and_waits_for_no_result(): void
    {
        // Display only: while the centre has paused the Enrollment, the class says so, nothing counts down, and a
        // Session held meanwhile does not wait for a result nobody will record (only active Enrollments are recorded).
        [$product, $version] = $this->course($this->customer, 'Sơ cấp 6A', 'blended');
        $cohort = $this->cohort($product, $version, 'Lớp 6A', 'active');
        $enrollment = $this->enroll($this->student, $product, $version);
        $this->seat($cohort, $enrollment, $this->student, $product);
        $held = $this->liveSession($cohort, $version, 'Buổi 1', '2026-07-08 19:00:00', '2026-07-08 21:00:00', 'completed');
        $this->liveSession($cohort, $version, 'Buổi 2', '2026-07-13 19:00:00', '2026-07-13 21:00:00');
        $page = fn (): string => $this->actingAs($this->student)->get(self::HOST.'/my-courses/classes/'.$cohort)->assertOk()->getContent();
        $heldBlock = fn (string $html): string => (string) strstr(substr($html, strpos($html, 'data-learn-session="'.$held.'"')), 'id="lesson-content-', true);

        $this->assertStringContainsString('data-learn-attendance-pending', $heldBlock($page()));

        DB::table('core_course_enrollments')->where('id', $enrollment)->update(['status' => 'suspended']);
        $card = $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=classes')->assertOk()->getContent();
        $this->assertStringContainsString('data-learn-access-hold="suspended"', $card);
        $this->assertStringContainsString(__('lf.LF_my_learning_class_access_suspended'), $card);
        $this->assertStringNotContainsString('btn-live-class', $card);
        $detail = $page();
        $this->assertStringContainsString(e(__('lf.LF_my_learning_class_access_suspended_notice')), $detail);
        $this->assertStringContainsString(e(__('lf.LF_my_learning_board_entry_access_suspended')), $detail);
        $this->assertStringNotContainsString('data-learn-countdown="', $detail);
        $this->assertStringNotContainsString('data-learn-attendance-pending', $heldBlock($detail));
    }

    // ---- fixtures ----

    public function test_learning_results_summarise_the_learners_courses_and_classes(): void
    {
        // A self-paced course: one of two lessons done, 1 h 5 min of learning.
        [$product, $version] = $this->course($this->customer, 'Sơ cấp tự học', 'self_paced');
        $first = $this->lesson($version, 'Bài 1', 1);
        $this->lesson($version, 'Bài 2', 2);
        $document = $this->activity($version, $first, 'Tài liệu', 'document', 1);
        $enrollment = $this->enroll($this->student, $product, $version, ['access_starts_at' => '2026-07-01 00:00:00', 'access_ends_at' => '2026-07-21 00:00:00']);
        $this->actingAs($this->student)->postJson($this->progressUrl($enrollment, $document), ['event' => 'opened'])->assertOk();
        DB::table('core_course_progress')->where('enrollment_id', $enrollment)->update(['total_learning_seconds' => 3900]);

        // A class: two sessions held, present at one.
        [$classProduct, $classVersion] = $this->course($this->customer, 'Giao tiếp', 'blended');
        $cohort = $this->cohort($classProduct, $classVersion, 'Lớp A', 'active');
        $classEnrollment = $this->enroll($this->student, $classProduct, $classVersion);
        $this->seat($cohort, $classEnrollment, $this->student, $classProduct);
        $present = $this->liveSession($cohort, $classVersion, 'Buổi 1', '2026-07-03 19:00:00', '2026-07-03 21:00:00', 'completed');
        $this->liveSession($cohort, $classVersion, 'Buổi 2', '2026-07-06 19:00:00', '2026-07-06 21:00:00', 'completed');
        $this->liveSession($cohort, $classVersion, 'Buổi 3', '2026-07-13 19:00:00', '2026-07-13 21:00:00');
        DB::table('core_liveclass_attendances')->insert([
            'customer_id' => $this->customer, 'session_id' => $present, 'enrollment_id' => $classEnrollment, 'user_id' => $this->student->id,
            'status' => 'present', 'attendance_mode' => 'onsite', 'joined_at' => '2026-07-03 19:01:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Someone else's course stays out.
        $other = $this->user($this->customer, 'student');
        $foreign = $this->enroll($other, $product, $version);

        $page = $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=results')->assertOk()
            ->assertSee('data-learn-tab="results"', false)->assertSee('data-results-summary="courses"', false)->getContent();
        $this->assertSame([$enrollment], $this->ids($page, 'data-results-row'));
        $this->assertStringNotContainsString('data-results-row="'.$foreign.'"', $page);
        $this->assertMatchesRegularExpression('#'.preg_quote(__('lf.LF_my_learning_results_lessons_done')).'</dt>\s*<dd><strong>1/2</strong> <span>50%</span>#', $page);
        $this->assertStringContainsString(__('lf.LF_my_learning_results_hours', ['hours' => 1, 'minutes' => 5]), $page);
        $this->assertMatchesRegularExpression('#data-results-view="classes">\s*'.preg_quote(__('lf.LF_my_learning_results_view_classes')).'\s*<span class="student-products__tab-count">1</span>#', $page);

        $classes = $this->actingAs($this->student)->get(self::HOST.'/my-courses?tab=results&view=classes')->assertOk()->getContent();
        $this->assertSame([$cohort], $this->ids($classes, 'data-results-row'));
        $this->assertMatchesRegularExpression('#'.preg_quote(__('lf.LF_my_learning_results_attendance')).'</dt>\s*<dd><strong>50%</strong>#', $classes);
        $this->assertStringContainsString(__('lf.LF_my_learning_results_absent_count', ['count' => 1]), $classes);
        $this->assertMatchesRegularExpression('#data-results-row="'.$cohort.'">.*?<td>2/3</td>\s*<td>1</td>\s*<td>1</td>#s', $classes);

        // The account menu and the hub tile lead here; nothing about results is locked any more.
        $this->assertStringContainsString('href="'.self::HOST.'/my-courses?tab=results"', $page);
        $this->assertDoesNotMatchRegularExpression('#\sdata-coming-soon[\s>][^>]*>?(?:(?!</button>).)*'.preg_quote(__('lf.LF_student_site_my_results'), '#').'#s', $page);
        $this->actingAs($this->user($this->customer, 'teacher'))->get(self::HOST.'/my-courses?tab=results')->assertForbidden();
    }

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
        if ($type === 'quiz' && ! isset($extra['assessment_quiz_version_id_snapshot'])) {
            $extra += $this->quizSnapshot();
        }

        return DB::table('core_course_template_version_activities')->insertGetId($extra + [
            'customer_id' => $this->customer, 'template_version_id' => $version, 'version_lesson_id' => $lesson,
            'source_template_activity_id' => ++$source, 'title_snapshot' => $title, 'activity_type' => $type,
            'sort_order' => $order, 'is_required' => true, 'completion_rule' => 'view', 'unlock_rule_snapshot' => 'none',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A published quiz version for a Quiz Activity (ADR-0032 D1; MariaDB checks the pair). */
    private function quizSnapshot(): array
    {
        $quiz = DB::table('core_assessment_quizzes')->insertGetId([
            'customer_id' => $this->customer, 'created_by' => $this->student->id, 'title' => 'Quiz', 'assessment_type' => 'exam', 'max_attempts' => 1,
            'show_result_mode' => 'immediately', 'status' => 'draft', 'registration_open' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = DB::table('core_assessment_quiz_versions')->insertGetId([
            'customer_id' => $this->customer, 'quiz_id' => $quiz, 'version_no' => 1, 'title' => 'Quiz', 'assessment_type' => 'exam',
            'max_attempts' => 1, 'show_result_mode' => 'immediately', 'total_points' => 1, 'question_count' => 1, 'sections_snapshot' => '[]',
            'published_by' => $this->student->id, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_assessment_quizzes')->where('id', $quiz)->update(['status' => 'published', 'current_version_id' => $version]);

        return ['assessment_quiz_id_snapshot' => $quiz, 'assessment_quiz_version_id_snapshot' => $version];
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

    /** @return list<string> */
    private function tabs(string $html): array
    {
        preg_match_all('/data-learn-tab="([a-z]+)"/', $html, $matches);

        return $matches[1];
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
