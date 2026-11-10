<?php

namespace Tests\Feature;

use App\Http\Controllers\LiveClassAttendanceCheckInController;
use App\Models\User;
use App\Services\LiveClassSessionLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** ADR-0002 § Attendance Check-In Amendment; LF-Core-LiveClass § Attendance, Điểm danh bằng mã QR. */
class LiveClassAttendanceCheckInTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://tenant-a.localhost';

    private const TZ = 'Asia/Ho_Chi_Minh';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://localhost',
            'app.base_domain' => 'localhost',
            'app.tenant_scheme' => 'https',
            'liveclass.attendance_enabled' => true,
            'liveclass.late_after_minutes' => 10,
        ]);
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:00:00', self::TZ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_board_offers_the_code_to_presenters_of_in_room_sessions_only(): void
    {
        $setup = $this->setupClass();
        $offline = $this->liveSession($setup, 'offline');
        $hybrid = $this->liveSession($setup, 'hybrid');
        $online = $this->liveSession($setup, 'online');
        $student = $this->learner($setup);

        $this->assertStringContainsString('attendance-code', $this->boardConfig($setup['teacher'], "/teacher/live-sessions/{$offline}/board"));
        $this->assertStringContainsString('attendance-code', $this->boardConfig($setup['teacher'], "/teacher/live-sessions/{$hybrid}/board"));
        $this->assertStringContainsString('"attendanceCodeUrl":null', $this->boardConfig($setup['teacher'], "/teacher/live-sessions/{$online}/board"));
        $this->assertStringContainsString('"attendanceCodeUrl":null', $this->boardConfig($student, "/my-courses/live-sessions/{$offline}/board"));
        $this->assertStringContainsString('attendance-code', $this->boardConfig($setup['admin'], "/admin/course-cohorts/{$setup['cohort']}/sessions/{$offline}/board"));

        $code = $this->actingAs($setup['teacher'])->getJson(self::HOST."/teacher/live-sessions/{$offline}/board/attendance-code")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('checked_in', 0)->assertJsonPath('learners', 1)->json();
        // A short link, so the QR code has fewer, bigger cells.
        $this->assertMatchesRegularExpression('#^'.preg_quote(self::HOST, '#')."/qr/{$offline}/[0-9a-z]+\\.[A-Za-z0-9_-]{14}$#", $code['url']);
        $this->assertLessThanOrEqual(60, strlen($code['url']));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $code['svg']);
        $this->assertTrue($code['refresh_in'] >= 1 && $code['refresh_in'] <= 30);
        $this->actingAs($setup['admin'])->getJson(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$offline}/board/attendance-code")->assertOk();

        $this->actingAs($setup['teacher'])->getJson(self::HOST."/teacher/live-sessions/{$online}/board/attendance-code")->assertNotFound();
        $this->actingAs($setup['otherTeacher'])->getJson(self::HOST."/teacher/live-sessions/{$offline}/board/attendance-code")->assertNotFound();

        config(['liveclass.attendance_enabled' => false]);
        $this->assertStringContainsString('"attendanceCodeUrl":null', $this->boardConfig($setup['teacher'], "/teacher/live-sessions/{$offline}/board"));
        $this->actingAs($setup['teacher'])->getJson(self::HOST."/teacher/live-sessions/{$offline}/board/attendance-code")->assertNotFound();
        $this->get($code['url'])->assertNotFound();
    }

    public function test_a_signed_in_learner_scans_and_is_recorded_present_in_the_room(): void
    {
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'hybrid');
        $student = $this->learner($setup);
        $url = $this->codeUrl($setup, $session);
        $page = self::HOST."/my-courses/live-sessions/{$session}/check-in";

        // The code leaves the address bar; the page confirms by itself.
        $this->actingAs($student)->get($url)->assertRedirect($page);
        $this->actingAs($student)->get($page)->assertOk()->assertSee('data-checkin-result="pending"', false)
            ->assertSee("/my-courses/live-sessions/{$session}/check-in", false);
        $this->actingAs($student)->post($page)->assertRedirect($page);
        $this->actingAs($student)->get($page)->assertOk()->assertSee('data-checkin-result="recorded"', false)
            ->assertSee(__('lf.LF_liveclass_attendance_checkin_recorded_present'))->assertSee('16:00');

        $row = DB::table('core_liveclass_attendances')->where('session_id', $session)->first();
        $this->assertSame(['present', 'offline', 'system', '2026-07-04 16:00:00', $setup['liveActivity'], $student->id],
            [$row->status, $row->attendance_mode, $row->attendance_source, (string) $row->joined_at, (int) $row->version_activity_id, (int) $row->user_id]);
        $this->assertNull($row->recorded_by);

        // A second scan changes nothing: the first one counts.
        Carbon::setTestNow(Carbon::now()->addMinutes(20));
        $this->actingAs($student)->get($this->codeUrl($setup, $session));
        $this->actingAs($student)->post($page)->assertRedirect($page);
        $this->actingAs($student)->get($page)->assertSee('data-checkin-result="already"', false);
        $this->assertSame(['present', '2026-07-04 16:00:00'], [
            DB::table('core_liveclass_attendances')->where('session_id', $session)->value('status'),
            (string) DB::table('core_liveclass_attendances')->where('session_id', $session)->value('joined_at'),
        ]);
        $this->actingAs($setup['teacher'])->getJson(self::HOST."/teacher/live-sessions/{$session}/board/attendance-code")
            ->assertJsonPath('checked_in', 1);
    }

    public function test_the_code_lasts_at_most_sixty_seconds_and_belongs_to_its_session(): void
    {
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'offline');
        $other = $this->liveSession($setup, 'offline');
        $student = $this->learner($setup);
        $url = $this->codeUrl($setup, $session);
        $code = (string) str($url)->afterLast('/');
        $page = self::HOST."/my-courses/live-sessions/{$session}/check-in";
        $pending = fn () => session(LiveClassAttendanceCheckInController::PENDING);

        [$window] = explode('.', $code);
        $issuedAt = CarbonImmutable::createFromTimestamp(((int) base_convert($window, 36, 10)) * 30);

        Carbon::setTestNow($issuedAt->addSeconds(64));
        $this->actingAs($student)->get($url)->assertRedirect($page);
        $this->assertNotNull($pending());

        session()->forget(LiveClassAttendanceCheckInController::PENDING);
        Carbon::setTestNow($issuedAt->addSeconds(66));
        $this->actingAs($student)->get($url)->assertRedirect($page)->assertSessionHas('lf_checkin_result', 'expired');
        $this->assertNull($pending());

        Carbon::setTestNow($issuedAt->addSeconds(10));
        $this->actingAs($student)->get(self::HOST."/qr/{$other}/{$code}")
            ->assertSessionHas('lf_checkin_result', 'expired');
        $tampered = substr($code, 0, -1).(str_ends_with($code, 'A') ? 'B' : 'A');
        $this->actingAs($student)->get(self::HOST."/qr/{$session}/{$tampered}")->assertSessionHas('lf_checkin_result', 'expired');
        $this->actingAs($student)->get(self::HOST."/qr/{$session}/1.x")->assertNotFound();
        $this->assertNull($pending());

        // Without a received code there is nothing to confirm.
        $this->actingAs($student)->post($page)->assertSessionHas('lf_checkin_result', 'expired');
        $this->assertSame(0, DB::table('core_liveclass_attendances')->count());
    }

    public function test_late_counts_from_the_later_of_planned_and_actual_start_and_the_window_is_enforced(): void
    {
        $setup = $this->setupClass();
        // Planned 16:00–17:30; the teacher started at 16:05.
        $session = $this->liveSession($setup, 'offline', actualStart: '2026-07-04 16:05:00');
        $early = $this->learner($setup);
        $late = $this->learner($setup);
        $onTime = $this->learner($setup);
        $tooEarly = $this->learner($setup);
        $tooLate = $this->learner($setup);

        $this->scanAt($setup, $session, $early, '2026-07-04 15:50:00');
        $this->scanAt($setup, $session, $onTime, '2026-07-04 16:14:00');
        $this->scanAt($setup, $session, $late, '2026-07-04 16:16:00');
        $this->scanAt($setup, $session, $tooEarly, '2026-07-04 15:44:00')->assertSee(__('lf.LF_liveclass_attendance_checkin_too_early'));
        $this->scanAt($setup, $session, $tooLate, '2026-07-04 17:31:00')->assertSee(__('lf.LF_liveclass_attendance_checkin_too_late'));

        $statuses = DB::table('core_liveclass_attendances')->where('session_id', $session)->pluck('status', 'user_id');
        $this->assertSame(['present', 'present', 'late'], [$statuses[$early->id], $statuses[$onTime->id], $statuses[$late->id]]);
        $this->assertFalse($statuses->has($tooEarly->id));
        $this->assertFalse($statuses->has($tooLate->id));
        $this->assertSame('2026-07-04 15:50:00', (string) DB::table('core_liveclass_attendances')->where('user_id', $early->id)->value('joined_at'));
    }

    public function test_a_learner_signs_in_after_scanning_and_keeps_the_scan_time(): void
    {
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'offline');
        $student = $this->learner($setup);
        $url = $this->codeUrl($setup, $session);
        $page = self::HOST."/my-courses/live-sessions/{$session}/check-in";
        $this->app['auth']->forgetGuards();

        $this->get($url)->assertRedirect($page);
        $this->get($page)->assertRedirect(self::HOST.'/login?experience=student');
        $this->assertSame($page, session(LiveClassAttendanceCheckInController::RETURN_TO));

        // Signing in takes a few minutes; the code has long expired.
        Carbon::setTestNow(Carbon::now()->addMinutes(3));
        $this->post(self::HOST.'/login?experience=student', ['email' => $student->email, 'password' => 'password123'])
            ->assertRedirect($page);
        $this->post($page)->assertRedirect($page);
        $this->assertSame('2026-07-04 16:00:00',
            (string) DB::table('core_liveclass_attendances')->where('session_id', $session)->value('joined_at'));

        // Without a pending check-in the student sign-in lands on "Khóa học của tôi".
        $this->post(self::HOST.'/logout');
        $this->post(self::HOST.'/login?experience=student', ['email' => $student->email, 'password' => 'password123'])
            ->assertRedirect(self::HOST.'/my-courses');
    }

    public function test_only_active_learners_of_the_class_are_recorded(): void
    {
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'offline');
        $cancelled = $this->learner($setup, enrollmentStatus: 'cancelled');
        $outsider = $this->user($setup['customer'], 'student');
        $teacher = $setup['teacher'];

        $this->scanAt($setup, $session, $cancelled, '2026-07-04 16:01:00')->assertSee(__('lf.LF_liveclass_attendance_checkin_not_allowed'));
        $this->scanAt($setup, $session, $outsider, '2026-07-04 16:01:00')->assertSee(__('lf.LF_liveclass_attendance_checkin_not_allowed'));
        $this->actingAs($teacher)->get($this->codeUrl($setup, $session))->assertRedirect();
        $this->actingAs($teacher)->get(self::HOST."/my-courses/live-sessions/{$session}/check-in")
            ->assertOk()->assertSee(__('lf.LF_liveclass_attendance_checkin_wrong_account'));
        $this->assertSame(0, DB::table('core_liveclass_attendances')->count());

        // An online Session has no QR check-in.
        $online = $this->liveSession($setup, 'online');
        $member = $this->learner($setup);
        $this->actingAs($member)->withSession([LiveClassAttendanceCheckInController::PENDING => [
            'customer_id' => $setup['customer'], 'session_id' => $online, 'at' => Carbon::now()->getTimestamp(),
        ]])->post(self::HOST."/my-courses/live-sessions/{$online}/check-in")->assertSessionHas('lf_checkin_result', 'not_offered');
        $this->assertSame(0, DB::table('core_liveclass_attendances')->count());
    }

    public function test_manual_attendance_wins_and_saving_the_tab_keeps_scans_it_does_not_change(): void
    {
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'offline');
        $scanned = $this->learner($setup);
        $corrected = $this->learner($setup);
        $markedByHand = $this->learner($setup);
        $cancelled = $this->learner($setup, enrollmentStatus: 'cancelled');
        $this->scanAt($setup, $session, $scanned, '2026-07-04 16:02:00');
        $this->scanAt($setup, $session, $corrected, '2026-07-04 16:03:00');
        $enrollment = fn (User $user): int => (int) DB::table('core_course_cohort_students')->where('student_id', $user->id)->value('enrollment_id');

        $this->actingAs($setup['admin'])->put(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/attendance", ['attendance' => [
            ['enrollment_id' => $enrollment($scanned), 'status' => 'present', 'attendance_mode' => 'offline', 'notes' => ''],
            ['enrollment_id' => $enrollment($corrected), 'status' => 'excused', 'attendance_mode' => 'offline', 'notes' => 'Doctor'],
            ['enrollment_id' => $enrollment($markedByHand), 'status' => 'absent', 'attendance_mode' => null, 'notes' => ''],
            ['enrollment_id' => $enrollment($cancelled), 'status' => 'present', 'attendance_mode' => null, 'notes' => ''],
        ]])->assertRedirect();

        $rows = DB::table('core_liveclass_attendances')->where('session_id', $session)->get()->keyBy('user_id');
        $this->assertSame(['present', 'system', '2026-07-04 16:02:00'],
            [$rows[$scanned->id]->status, $rows[$scanned->id]->attendance_source, (string) $rows[$scanned->id]->joined_at]);
        $this->assertSame(['excused', 'manual', 'Doctor', $setup['admin']->id],
            [$rows[$corrected->id]->status, $rows[$corrected->id]->attendance_source, $rows[$corrected->id]->notes, (int) $rows[$corrected->id]->recorded_by]);
        $this->assertSame(['absent', 'manual'], [$rows[$markedByHand->id]->status, $rows[$markedByHand->id]->attendance_source]);
        $this->assertFalse($rows->has($cancelled->id), 'DOC-CONFLICT-0011: a cancelled Enrollment is not recorded');

        // A later scan does not override what was set by hand.
        $this->scanAt($setup, $session, $markedByHand, '2026-07-04 16:20:00')->assertSee(__('lf.LF_liveclass_attendance_checkin_already'));
        $this->assertSame('absent', DB::table('core_liveclass_attendances')->where('user_id', $markedByHand->id)->value('status'));

        $this->actingAs($setup['admin'])->get(self::HOST."/admin/course-cohorts/{$setup['cohort']}?tab=attendance&session_id={$session}")
            ->assertOk()->assertSee(__('lf.LF_course_cohort_attendance_checked_in_qr', ['time' => '16:02']));
    }

    public function test_a_form_loaded_before_a_scan_does_not_overwrite_it(): void
    {
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'offline');
        $scannedLater = $this->learner($setup);
        $absent = $this->learner($setup);
        $cancelled = $this->learner($setup, enrollmentStatus: 'cancelled');
        $enrollment = fn (User $user): int => (int) DB::table('core_course_cohort_students')->where('student_id', $user->id)->value('enrollment_id');
        $untouched = ['loaded_status' => 'registered', 'loaded_mode' => '', 'loaded_notes' => ''];
        // The admin opened the tab while nobody had checked in; the scan happens before the save.
        $this->scanAt($setup, $session, $scannedLater, '2026-07-04 16:02:00');

        $response = $this->actingAs($setup['admin'])->put(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/attendance", ['attendance' => [
            ['enrollment_id' => $enrollment($scannedLater), 'status' => 'registered', 'attendance_mode' => '', 'notes' => ''] + $untouched,
            ['enrollment_id' => $enrollment($absent), 'status' => 'absent', 'attendance_mode' => '', 'notes' => ''] + $untouched,
            ['enrollment_id' => $enrollment($cancelled), 'status' => 'present', 'attendance_mode' => '', 'notes' => ''] + $untouched,
        ]])->assertRedirect();

        $rows = DB::table('core_liveclass_attendances')->where('session_id', $session)->get()->keyBy('user_id');
        $this->assertSame(['present', 'system'], [$rows[$scannedLater->id]->status, $rows[$scannedLater->id]->attendance_source]);
        $this->assertSame(['absent', 'manual'], [$rows[$absent->id]->status, $rows[$absent->id]->attendance_source]);
        $this->assertFalse($rows->has($cancelled->id));
        $response->assertSessionHas('success', __('lf.LF_course_cohort_attendance_saved').' '
            .__('lf.LF_course_cohort_attendance_skipped_inactive', ['count' => 1]));
    }

    public function test_an_unassigned_session_offers_no_code_and_records_no_scan(): void
    {
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'offline');
        $student = $this->learner($setup);
        $url = $this->codeUrl($setup, $session);
        // The teacher is taken off after the code was shown: no Attendance until one is assigned again.
        DB::table('core_liveclass_session_teachers')->where('session_id', $session)->delete();
        $board = "/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/board";

        $this->assertStringContainsString('"attendanceCodeUrl":null', $this->boardConfig($setup['admin'], $board));
        $this->actingAs($setup['admin'])->getJson(self::HOST.$board.'/attendance-code')->assertNotFound();
        $page = self::HOST."/my-courses/live-sessions/{$session}/check-in";
        $this->actingAs($student)->get($url);
        $this->actingAs($student)->post($page);

        $this->assertSame(0, DB::table('core_liveclass_attendances')->where('session_id', $session)->count());
    }

    public function test_a_join_activity_follows_attendance_both_ways(): void
    {
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'offline');
        $student = $this->learner($setup);
        $enrollment = (int) DB::table('core_course_cohort_students')->where('student_id', $student->id)->value('enrollment_id');
        $progress = fn (): ?string => DB::table('core_course_activity_progress')
            ->where('enrollment_id', $enrollment)->where('version_activity_id', $setup['liveActivity'])->value('status');
        $save = fn (string $status) => $this->actingAs($setup['admin'])->put(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/attendance",
            ['attendance' => [['enrollment_id' => $enrollment, 'status' => $status, 'attendance_mode' => 'offline', 'notes' => '']]])->assertRedirect();

        // ADR-0030 D3: the scan completes the Activity and its Lesson.
        $this->scanAt($setup, $session, $student, '2026-07-04 16:02:00');
        $this->assertSame('completed', $progress());
        $this->assertSame(['attended', 'completed'], [
            DB::table('core_course_activity_progress')->where('enrollment_id', $enrollment)->value('completion_rule'),
            DB::table('core_course_lesson_progress')->where('enrollment_id', $enrollment)->value('status'),
        ]);

        $save('absent');
        $this->assertSame('not_started', $progress());
        $this->assertSame('not_started', DB::table('core_course_lesson_progress')->where('enrollment_id', $enrollment)->value('status'));
        $save('late');
        $this->assertSame('completed', $progress());

        // A cancelled Session no longer counts.
        app(LiveClassSessionLifecycleService::class)->cancel($setup['customer'], $setup['cohort'], $session, 'Giáo viên ốm');
        $this->assertSame('not_started', $progress());
    }

    private function scanAt(array $setup, int $session, User $student, string $localTime)
    {
        Carbon::setTestNow(CarbonImmutable::parse($localTime, self::TZ));
        $url = $this->codeUrl($setup, $session);
        $page = self::HOST."/my-courses/live-sessions/{$session}/check-in";
        $this->actingAs($student)->get($url)->assertRedirect($page);
        $this->actingAs($student)->post($page)->assertRedirect($page);

        return $this->actingAs($student)->get($page)->assertOk();
    }

    private function codeUrl(array $setup, int $session): string
    {
        return $this->actingAs($setup['teacher'])
            ->getJson(self::HOST."/teacher/live-sessions/{$session}/board/attendance-code")->assertOk()->json('url');
    }

    private function boardConfig(User $user, string $path): string
    {
        $page = $this->actingAs($user)->get(self::HOST.$path)->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<script type="application/json" id="lf-wb-config">(.*?)</script>#s', $page, $match));

        return $match[1];
    }

    private function setupClass(): array
    {
        $customer = DB::table('saas_customers')->insertGetId([
            'name' => 'tenant-a', 'slug' => 'tenant-a', 'subdomain' => 'tenant-a', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = $this->user($customer, 'customer_admin');
        $teacher = $this->user($customer, 'teacher');
        $otherTeacher = $this->user($customer, 'teacher');
        $product = DB::table('core_course_products')->insertGetId([
            'customer_id' => $customer, 'product_code' => 'TENANT-A', 'product_type' => 'single_course',
            'title' => 'Check-in product', 'slug' => 'check-in', 'thumbnail_type' => 'image', 'price' => 0, 'currency' => 'VND',
            'enrollment_type' => 'paid', 'enrollment_count' => 0, 'is_certificate_enabled' => false, 'is_refundable' => false,
            'show_enrollment_count' => true, 'is_featured' => false, 'sort_order' => 0, 'visibility' => 'public',
            'status' => 'active', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $template = DB::table('core_course_templates')->insertGetId([
            'course_type' => 'scheduled', 'customer_id' => $customer, 'title' => 'Check-in Template',
            'estimated_minutes_per_lesson' => 0, 'lesson_count' => 0, 'working_revision' => 1,
            'status' => 'active', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = DB::table('core_course_template_versions')->insertGetId([
            'course_type_snapshot' => 'scheduled', 'customer_id' => $customer, 'template_id' => $template,
            'version_number' => 1, 'version_code' => 'V-'.$template, 'is_current' => true,
            'title_snapshot' => 'Check-in Version', 'estimated_minutes_per_lesson_snapshot' => 0, 'lesson_count_snapshot' => 1,
            'source_working_revision' => 1, 'status' => 'published', 'published_at' => now(), 'published_by' => $admin->id,
            'source_template_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cohort = DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $customer, 'product_id' => $product, 'version_id' => $version, 'name' => 'Check-in class',
            'code' => 'COH-'.uniqid(), 'status' => 'active', 'start_date' => '2026-07-01', 'end_date' => '2026-08-31',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $lesson = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $customer, 'template_version_id' => $version, 'source_template_lesson_id' => 91001,
            'title_snapshot' => 'Lesson one', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $liveActivity = DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $customer, 'template_version_id' => $version, 'version_lesson_id' => $lesson,
            'source_template_activity_id' => 92001, 'title_snapshot' => 'Live class', 'activity_type' => 'live_class',
            'sort_order' => 1, 'available_during_session' => true, 'completion_rule' => 'join',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('customer', 'admin', 'teacher', 'otherTeacher', 'product', 'version', 'cohort', 'lesson', 'liveActivity');
    }

    /** A live Session planned 16:00–17:30 local time, with the teacher assigned. */
    private function liveSession(array $setup, string $mode, ?string $actualStart = null): int
    {
        static $number = 0;
        $session = DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $setup['customer'], 'cohort_id' => $setup['cohort'], 'template_version_id' => $setup['version'],
            'session_type' => 'curriculum', 'version_lesson_id' => $setup['lesson'], 'version_activity_id' => $setup['liveActivity'],
            'title' => 'Session '.(++$number), 'session_no' => $number, 'delivery_mode' => $mode,
            'meeting_url_snapshot' => $mode === 'offline' ? null : 'https://meet.example.test/room',
            'room_name_snapshot' => $mode === 'online' ? null : 'Room A',
            'scheduled_start_at' => '2026-07-04 16:00:00', 'scheduled_end_at' => '2026-07-04 17:30:00', 'timezone' => self::TZ,
            'status' => 'live', 'actual_start_at' => $actualStart ?? '2026-07-04 16:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_liveclass_session_teachers')->insert([
            'customer_id' => $setup['customer'], 'session_id' => $session, 'teacher_id' => $setup['teacher']->id, 'role' => 'teacher',
            'assigned_from' => null, 'assigned_to' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $session;
    }

    private function learner(array $setup, string $enrollmentStatus = 'active'): User
    {
        $student = $this->user($setup['customer'], 'student');
        $enrollment = DB::table('core_course_enrollments')->insertGetId([
            'customer_id' => $setup['customer'], 'product_id' => $setup['product'], 'version_id' => $setup['version'],
            'student_id' => $student->id, 'source' => 'admin', 'enrolled_by' => $setup['admin']->id,
            'enrolled_at' => '2026-07-01 00:00:00', 'status' => $enrollmentStatus, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_cohort_students')->insert([
            'customer_id' => $setup['customer'], 'cohort_id' => $setup['cohort'], 'enrollment_id' => $enrollment,
            'product_id' => $setup['product'], 'student_id' => $student->id, 'assigned_by' => $setup['admin']->id,
            'joined_at' => '2026-07-01 00:00:00', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $student;
    }

    private function user(int $customer, string $role): User
    {
        return User::forceCreate([
            'customer_id' => $customer, 'name' => ucfirst($role).' '.uniqid(), 'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('password123'), 'role' => $role, 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }
}
