<?php

namespace Tests\Feature;

use App\Http\Controllers\LiveClassAttendanceCheckInController;
use App\Models\User;
use App\Services\CourseCohortMembershipHistory;
use App\Services\LearnerMyLearningService;
use App\Services\LiveClassAttendanceDevice;
use App\Services\LiveClassAttendancePolicies;
use App\Services\LiveClassAttendanceRecorder;
use App\Services\LiveClassSessionLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
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

    public function test_one_device_checks_in_one_learner_and_the_board_names_who_just_scanned(): void
    {
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'offline');
        $other = $this->liveSession($setup, 'offline');
        [$first, $second, $third] = [$this->learner($setup), $this->learner($setup), $this->learner($setup)];
        $page = self::HOST."/my-courses/live-sessions/{$session}/check-in";
        $scan = function (User $student, int $session) use ($setup): TestResponse {
            $this->actingAs($student)->get($this->codeUrl($setup, $session));

            return $this->actingAs($student)->post(self::HOST."/my-courses/live-sessions/{$session}/check-in");
        };

        // The first check-in gives the browser its device id.
        $device = $scan($first, $session)->assertSessionHas('lf_checkin_result', 'recorded')
            ->getCookie(LiveClassAttendanceDevice::COOKIE)?->getValue();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', (string) $device);

        // MK 2.4: the same device does not check in another learner in this Session, and says why.
        $this->withCookie(LiveClassAttendanceDevice::COOKIE, $device);
        $scan($second, $session)->assertSessionHas('lf_checkin_result', 'device_used');
        $this->actingAs($second)->get($page)->assertSee(__('lf.LF_liveclass_attendance_checkin_device_used'));
        $this->assertSame(1, DB::table('core_liveclass_attendances')->where('session_id', $session)->count());
        // Its own learner again, and another Session, are not refused.
        $scan($first, $session)->assertSessionHas('lf_checkin_result', 'already');
        $scan($second, $other)->assertSessionHas('lf_checkin_result', 'recorded');

        // Another device checks the second learner in.
        Carbon::setTestNow(Carbon::now()->addMinutes(1));
        $this->withCookie(LiveClassAttendanceDevice::COOKIE, str_repeat('b', 40));
        $scan($second, $session)->assertSessionHas('lf_checkin_result', 'recorded');

        // The presenter's board names who just scanned, newest first; a learner who has not scanned is not named.
        $board = $this->actingAs($setup['teacher'])->getJson(self::HOST."/teacher/live-sessions/{$session}/board/attendance-code")->assertOk();
        $this->assertSame([$second->name, $first->name], array_column($board->json('recent'), 'name'));
        $this->assertSame(['16:01', '16:00'], array_column($board->json('recent'), 'time'));
        $this->assertSame(__('lf.LF_course_cohort_attendance_status_present'), $board->json('recent.0.label'));
        $this->assertNotContains($third->name, array_column($board->json('recent'), 'name'));
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
        $lastLate = $this->learner($setup);
        $pastAbsent = $this->learner($setup);

        // The tenant default policy: opens 15 minutes early, late after 10, absent after 30.
        $this->scanAt($setup, $session, $early, '2026-07-04 15:50:00');
        $this->scanAt($setup, $session, $onTime, '2026-07-04 16:14:00');
        $this->scanAt($setup, $session, $late, '2026-07-04 16:16:00');
        $this->scanAt($setup, $session, $tooEarly, '2026-07-04 15:44:00')->assertSee(__('lf.LF_liveclass_attendance_checkin_too_early'));
        $this->scanAt($setup, $session, $lastLate, '2026-07-04 16:35:00');

        // Past the absent threshold (16:05 + 30) the board stops issuing the code and says so.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:35:01', self::TZ));
        $this->actingAs($setup['teacher'])->getJson(self::HOST."/teacher/live-sessions/{$session}/board/attendance-code")
            ->assertOk()->assertJson(['closed' => true, 'url' => null, 'svg' => null]);
        // A code shown just before the threshold and scanned just after it is refused; nothing is written.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:34:50', self::TZ));
        $url = $this->codeUrl($setup, $session);
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:35:20', self::TZ));
        $page = self::HOST."/my-courses/live-sessions/{$session}/check-in";
        $this->actingAs($pastAbsent)->get($url)->assertRedirect($page);
        $this->actingAs($pastAbsent)->post($page)->assertRedirect($page);
        $this->actingAs($pastAbsent)->get($page)->assertOk()->assertSee(__('lf.LF_liveclass_attendance_checkin_absent_closed'));

        $statuses = DB::table('core_liveclass_attendances')->where('session_id', $session)->pluck('status', 'user_id');
        $this->assertSame(['present', 'present', 'late', 'late'],
            [$statuses[$early->id], $statuses[$onTime->id], $statuses[$late->id], $statuses[$lastLate->id]]);
        $this->assertFalse($statuses->has($tooEarly->id));
        $this->assertFalse($statuses->has($pastAbsent->id));
        $this->assertSame('2026-07-04 15:50:00', (string) DB::table('core_liveclass_attendances')->where('user_id', $early->id)->value('joined_at'));
    }

    public function test_the_thresholds_come_from_the_policy_of_the_cohort(): void
    {
        $setup = $this->setupClass();
        $policies = app(LiveClassAttendancePolicies::class);
        $default = $policies->defaultFor($setup['customer']);
        // The Cohort's own policy: opens 5 minutes early, late at once, no absent threshold, counted from the planned start.
        $own = DB::table(LiveClassAttendancePolicies::TABLE)->insertGetId([
            'customer_id' => $setup['customer'], 'name' => 'Own', 'opens_before_minutes' => 5, 'late_after_minutes' => 0,
            'absent_after_minutes' => null, 'count_from_actual_start' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_cohorts')->where('id', $setup['cohort'])->update(['attendance_policy_id' => $own]);
        // Planned 16:00–17:30; the teacher started at 16:20, which this policy ignores.
        $session = $this->liveSession($setup, 'offline', actualStart: '2026-07-04 16:20:00');
        [$tooEarly, $early, $late, $veryLate, $afterEnd] = array_map(fn () => $this->learner($setup), range(1, 5));

        $this->scanAt($setup, $session, $tooEarly, '2026-07-04 15:54:00')->assertSee(__('lf.LF_liveclass_attendance_checkin_too_early'));
        $this->scanAt($setup, $session, $early, '2026-07-04 15:56:00');
        $this->scanAt($setup, $session, $late, '2026-07-04 16:01:00');
        // Without an absent threshold the code stays until the planned end and a late entry is only late.
        $this->scanAt($setup, $session, $veryLate, '2026-07-04 17:29:00');
        $this->scanAt($setup, $session, $afterEnd, '2026-07-04 17:31:00')->assertSee(__('lf.LF_liveclass_attendance_checkin_too_late'));

        $statuses = DB::table('core_liveclass_attendances')->where('session_id', $session)->pluck('status', 'user_id');
        $this->assertSame(['present', 'late', 'late'], [$statuses[$early->id], $statuses[$late->id], $statuses[$veryLate->id]]);
        $this->assertFalse($statuses->has($tooEarly->id));
        $this->assertFalse($statuses->has($afterEnd->id));

        // Editing the policy does not recalculate what is already recorded.
        DB::table(LiveClassAttendancePolicies::TABLE)->where('id', $own)->update(['late_after_minutes' => 240]);
        $this->assertSame('late', DB::table('core_liveclass_attendances')->where('user_id', $late->id)->value('status'));

        // With in-room recording set to "by hand" the board offers no code and a scan writes nothing.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:10:00', self::TZ));
        $url = $this->codeUrl($setup, $session);
        DB::table(LiveClassAttendancePolicies::TABLE)->where('id', $own)->update(['offline_recording' => 'manual']);
        $this->assertStringContainsString('"attendanceCodeUrl":null', $this->boardConfig($setup['teacher'], "/teacher/live-sessions/{$session}/board"));
        $this->actingAs($setup['teacher'])->getJson(self::HOST."/teacher/live-sessions/{$session}/board/attendance-code")->assertNotFound();
        $manual = $this->learner($setup);
        $page = self::HOST."/my-courses/live-sessions/{$session}/check-in";
        $this->actingAs($manual)->get($url)->assertRedirect($page);
        $this->actingAs($manual)->post($page)->assertRedirect($page);
        $this->assertDatabaseMissing('core_liveclass_attendances', ['session_id' => $session, 'user_id' => $manual->id]);

        // The tenant default is untouched and still applies to a Cohort without its own policy.
        $this->assertSame('system', DB::table(LiveClassAttendancePolicies::TABLE)->where('id', $default->id)->value('offline_recording'));
    }

    public function test_join_meeting_records_the_entry_before_the_teacher_starts_and_then_opens_the_meeting(): void
    {
        $setup = $this->setupClass();
        // Planned 16:00–17:30; the teacher has not pressed Start.
        $session = $this->liveSession($setup, 'online');
        DB::table('core_liveclass_sessions')->where('id', $session)->update(['status' => 'scheduled', 'actual_start_at' => null]);
        [$early, $late, $absent, $tooEarly, $byHand, $outsider] = array_map(fn () => $this->learner($setup), range(1, 6));
        DB::table('core_course_cohort_students')->where('student_id', $outsider->id)->update(['status' => 'removed', 'left_at' => now()]);
        $meeting = self::HOST."/my-courses/live-sessions/{$session}/meeting";
        $row = fn (User $student) => DB::table('core_liveclass_attendances')->where('session_id', $session)->where('user_id', $student->id)
            ->first(['status', 'attendance_mode', 'attendance_source', 'joined_at']);
        $enterAt = function (User $student, string $localTime) use ($meeting) {
            Carbon::setTestNow(CarbonImmutable::parse($localTime, self::TZ));

            return $this->actingAs($student)->post($meeting);
        };

        // Before the entry time opens (15:45) the card is not shown; a direct request opens the meeting and records nothing.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 15:44:00', self::TZ));
        $this->actingAs($tooEarly)->get(self::HOST.'/my-courses')->assertOk()->assertDontSee("live-sessions/{$session}/meeting", false);
        $enterAt($tooEarly, '2026-07-04 15:44:00')->assertRedirect('https://meet.example.test/room');
        $this->assertNull($row($tooEarly));

        // From 15:45 the learner page offers "Vào phòng họp" although the Session is not live, and no board yet.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 15:50:00', self::TZ));
        $this->actingAs($early)->get(self::HOST.'/my-courses')->assertOk()
            ->assertSee(__('lf.LF_liveclass_attendance_entry_upcoming_title'))
            ->assertSee("live-sessions/{$session}/meeting", false)
            ->assertDontSee("live-sessions/{$session}/board", false);
        $enterAt($early, '2026-07-04 15:50:00')->assertRedirect('https://meet.example.test/room');
        $this->assertEquals(['status' => 'present', 'attendance_mode' => 'online', 'attendance_source' => 'system',
            'joined_at' => '2026-07-04 15:50:00'], (array) $row($early));
        // Only the first entry counts; the page now shows the result.
        $enterAt($early, '2026-07-04 16:20:00')->assertRedirect('https://meet.example.test/room');
        $this->assertSame('2026-07-04 15:50:00', (string) $row($early)->joined_at);
        $this->actingAs($early)->get(self::HOST.'/my-courses')->assertOk()->assertSee(__('lf.LF_liveclass_attendance_entry_result', [
            'status' => __('lf.LF_course_cohort_attendance_status_present'), 'time' => '15:50',
            'mode' => __('lf.LF_liveclass_attendance_entry_mode_online'),
        ]));

        // Late after 10 minutes; past 30 the learner still enters and is recorded absent with the entry time, warned first.
        $enterAt($late, '2026-07-04 16:11:00');
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:31:00', self::TZ));
        $this->actingAs($absent)->get(self::HOST.'/my-courses')->assertOk()->assertSee(__('lf.LF_liveclass_attendance_entry_warning'));
        $enterAt($absent, '2026-07-04 16:31:00')->assertRedirect('https://meet.example.test/room');
        $this->assertSame(['late', 'absent'], [$row($late)->status, $row($absent)->status]);
        $this->assertSame('2026-07-04 16:31:00', (string) $row($absent)->joined_at);

        // A result set by hand is not replaced; someone who left the class reaches nothing.
        DB::table('core_liveclass_attendances')->insert([
            'customer_id' => $setup['customer'], 'session_id' => $session, 'user_id' => $byHand->id, 'status' => 'excused',
            'enrollment_id' => DB::table('core_course_enrollments')->where('student_id', $byHand->id)->value('id'),
            'attendance_source' => 'manual', 'recorded_by' => $setup['admin']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $enterAt($byHand, '2026-07-04 16:05:00')->assertRedirect('https://meet.example.test/room');
        $this->assertSame(['excused', null], [$row($byHand)->status, $row($byHand)->joined_at]);
        $enterAt($outsider, '2026-07-04 16:05:00')->assertNotFound();

        // After the planned end nothing is recorded any more; a cancelled Session takes no entry at all.
        $enterAt($tooEarly, '2026-07-04 17:31:00')->assertRedirect('https://meet.example.test/room');
        $this->assertNull($row($tooEarly));
        DB::table('core_liveclass_sessions')->where('id', $session)->update(['status' => 'cancelled']);
        $enterAt($tooEarly, '2026-07-04 16:05:00')->assertForbidden();

        // With online recording set to "by hand" the button opens the meeting and records nothing.
        $manualSession = $this->liveSession($setup, 'online');
        DB::table(LiveClassAttendancePolicies::TABLE)->where('customer_id', $setup['customer'])->update(['online_recording' => 'manual']);
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:05:00', self::TZ));
        $this->actingAs($tooEarly)->post(self::HOST."/my-courses/live-sessions/{$manualSession}/meeting")
            ->assertRedirect('https://meet.example.test/room');
        $this->assertDatabaseMissing('core_liveclass_attendances', ['session_id' => $manualSession]);
    }

    public function test_the_check_in_button_records_in_room_learners_from_the_planned_start(): void
    {
        $setup = $this->setupClass();
        // The teacher started at 16:20; the button always counts from the planned 16:00.
        $session = $this->liveSession($setup, 'offline', actualStart: '2026-07-04 16:20:00');
        DB::table('core_liveclass_sessions')->where('id', $session)->update(['attendance_tool' => 'button']);
        [$onTime, $late, $absent] = array_map(fn () => $this->learner($setup), range(1, 3));
        $attend = self::HOST."/my-courses/live-sessions/{$session}/attend";
        $press = function (User $student, string $localTime) use ($attend) {
            Carbon::setTestNow(CarbonImmutable::parse($localTime, self::TZ));

            return $this->actingAs($student)->from(self::HOST.'/my-courses')->post($attend)->assertRedirect(self::HOST.'/my-courses');
        };
        $row = fn (User $student) => DB::table('core_liveclass_attendances')->where('session_id', $session)->where('user_id', $student->id)
            ->first(['status', 'attendance_mode', 'attendance_source', 'joined_at']);

        // The board shows no QR code for this Session; the learner page shows the button instead of the QR hint.
        $this->assertStringContainsString('"attendanceCodeUrl":null', $this->boardConfig($setup['teacher'], "/teacher/live-sessions/{$session}/board"));
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:05:00', self::TZ));
        $this->actingAs($onTime)->get(self::HOST.'/my-courses')->assertOk()
            ->assertSee("live-sessions/{$session}/attend", false)
            ->assertDontSee(__('lf.LF_liveclass_whiteboard_learner_scan_qr'));

        $press($onTime, '2026-07-04 16:05:00');
        $press($late, '2026-07-04 16:15:00');
        $press($absent, '2026-07-04 16:40:00');
        $this->assertEquals(['status' => 'present', 'attendance_mode' => 'offline', 'attendance_source' => 'system',
            'joined_at' => '2026-07-04 16:05:00'], (array) $row($onTime));
        $this->assertSame(['late', 'absent'], [$row($late)->status, $row($absent)->status]);
        $this->assertSame('2026-07-04 16:40:00', (string) $row($absent)->joined_at);
        // A second press changes nothing.
        $press($late, '2026-07-04 16:01:00');
        $this->assertSame('2026-07-04 16:15:00', (string) $row($late)->joined_at);
        // Once recorded, the button is gone and the result line stays.
        $this->actingAs($late)->get(self::HOST.'/my-courses')->assertOk()
            ->assertDontSee("live-sessions/{$session}/attend", false)->assertSee('data-live-attendance', false);

        // MK 2.4: the device that pressed for one learner does not press for another in the same Session.
        [$owner, $borrower] = [$this->learner($setup), $this->learner($setup)];
        $this->withCookie(LiveClassAttendanceDevice::COOKIE, str_repeat('c', 40));
        $press($owner, '2026-07-04 16:05:00')->assertSessionHas('lf_attendance_entry.result', 'recorded');
        $press($borrower, '2026-07-04 16:06:00')->assertSessionHas('lf_attendance_entry.result', 'device_used');
        $this->assertNull($row($borrower));
        $this->withCookie(LiveClassAttendanceDevice::COOKIE, str_repeat('d', 40));

        // A Session whose tool is the board refuses the button.
        $boardSession = $this->liveSession($setup, 'offline');
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:05:00', self::TZ));
        $this->actingAs($onTime)->from(self::HOST.'/my-courses')->post(self::HOST."/my-courses/live-sessions/{$boardSession}/attend")
            ->assertSessionHas('lf_attendance_entry', fn (array $flash): bool => $flash['result'] === 'not_offered');
        $this->assertDatabaseMissing('core_liveclass_attendances', ['session_id' => $boardSession]);
    }

    public function test_opening_the_board_is_an_entry_only_in_an_online_session(): void
    {
        $setup = $this->setupClass();
        $online = $this->liveSession($setup, 'online');
        $offline = $this->liveSession($setup, 'offline');
        $hybrid = $this->liveSession($setup, 'hybrid');
        $student = $this->learner($setup);
        $row = fn (int $session) => DB::table('core_liveclass_attendances')->where('session_id', $session)->where('user_id', $student->id)
            ->first(['id', 'status', 'attendance_mode', 'attendance_source', 'joined_at']);

        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:12:00', self::TZ));
        foreach ([$online, $offline, $hybrid] as $session) {
            $this->actingAs($student)->get(self::HOST."/my-courses/live-sessions/{$session}/board")->assertOk();
        }
        $this->assertSame(['late', 'online', 'system', '2026-07-04 16:12:00'],
            [$row($online)->status, $row($online)->attendance_mode, $row($online)->attendance_source, (string) $row($online)->joined_at]);
        // Where learners may be in the room, watching the board on a laptop proves nothing: no entry.
        $this->assertNull($row($offline));
        $this->assertNull($row($hybrid));
        // The presenter opening the board is not an entry.
        $this->actingAs($setup['teacher'])->get(self::HOST."/teacher/live-sessions/{$online}/board")->assertOk();
        $this->assertSame(1, DB::table('core_liveclass_attendances')->where('session_id', $online)->count());

        // In the hybrid Session a remote learner enters through "Vào phòng họp". If they then scan the code in the
        // room, the time and the result stay, the mode becomes in-room, and the history keeps that system change.
        $this->actingAs($student)->post(self::HOST."/my-courses/live-sessions/{$hybrid}/meeting")->assertRedirect('https://meet.example.test/room');
        $this->assertSame(['late', 'online'], [$row($hybrid)->status, $row($hybrid)->attendance_mode]);
        $this->scanAt($setup, $hybrid, $student, '2026-07-04 16:20:00');
        $this->assertSame(['late', 'offline', '2026-07-04 16:12:00'],
            [$row($hybrid)->status, $row($hybrid)->attendance_mode, (string) $row($hybrid)->joined_at]);
        $this->assertDatabaseHas('core_liveclass_attendance_changes', [
            'attendance_id' => $row($hybrid)->id, 'old_status' => 'late', 'new_status' => 'late',
            'old_attendance_mode' => 'online', 'new_attendance_mode' => 'offline', 'change_source' => 'system', 'changed_by' => null,
        ]);
        // The first entries wrote no history row.
        $this->assertSame(1, DB::table('core_liveclass_attendance_changes')->count());
    }

    public function test_manual_attendance_keeps_a_history_and_a_result_cannot_be_taken_back(): void
    {
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'offline');
        $student = $this->learner($setup);
        $enrollment = (int) DB::table('core_course_enrollments')->where('student_id', $student->id)->value('id');
        $url = self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/attendance";
        $save = fn (string $status, string $loaded, string $notes = '') => $this->actingAs($setup['admin'])->put($url, ['attendance' => [[
            'enrollment_id' => $enrollment, 'status' => $status, 'attendance_mode' => 'offline', 'notes' => $notes,
            'loaded_status' => $loaded, 'loaded_mode' => $loaded === 'registered' ? '' : 'offline', 'loaded_notes' => '',
        ]]]);
        $history = fn () => DB::table('core_liveclass_attendance_changes')->where('session_id', $session)->orderBy('id')
            ->get(['old_status', 'new_status', 'new_attendance_mode', 'notes', 'change_source', 'changed_by'])
            ->map(fn (object $row): array => (array) $row)->all();

        // The first manual write is a history row too: there was no result before.
        $save('late', 'registered')->assertSessionHasNoErrors();
        $this->assertSame([['old_status' => null, 'new_status' => 'late', 'new_attendance_mode' => 'offline', 'notes' => null,
            'change_source' => 'manual', 'changed_by' => $setup['admin']->id]], $history());

        // A recorded result is not set back to "not recorded yet"; nothing changes.
        $save('registered', 'late')->assertSessionHasErrors(['attendance.0.status' => __('lf.LF_course_cohort_attendance_no_reset')]);
        $this->assertSame('late', DB::table('core_liveclass_attendances')->where('enrollment_id', $enrollment)->value('status'));
        $this->assertCount(1, $history());

        // A correction adds a row; saving the page without touching the row adds none.
        $save('excused', 'late', 'Có đơn xin nghỉ')->assertSessionHasNoErrors();
        $this->actingAs($setup['admin'])->put($url, ['attendance' => [[
            'enrollment_id' => $enrollment, 'status' => 'excused', 'attendance_mode' => 'offline', 'notes' => 'Có đơn xin nghỉ',
            'loaded_status' => 'excused', 'loaded_mode' => 'offline', 'loaded_notes' => 'Có đơn xin nghỉ',
        ]]])->assertSessionHasNoErrors();
        $this->assertSame(['late', 'excused', 'Có đơn xin nghỉ'],
            [$history()[1]['old_status'], $history()[1]['new_status'], $history()[1]['notes']]);
        $this->assertCount(2, $history());

        // The tab lists the history and no longer offers "not recorded yet" for a learner with a result.
        $page = $this->actingAs($setup['admin'])->get(self::HOST."/admin/course-cohorts/{$setup['cohort']}?tab=attendance&session_id={$session}")
            ->assertOk()->assertSee(trans_choice('lf.LF_course_cohort_attendance_history', 2, ['count' => 2]))
            ->assertSee(__('lf.LF_course_cohort_attendance_source_manual'));
        $page->assertDontSee('<option value="registered"', false);
    }

    public function test_every_teacher_of_the_cohort_records_attendance_until_the_edit_window_closes(): void
    {
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'online');
        $student = $this->learner($setup);
        $enrollment = (int) DB::table('core_course_enrollments')->where('student_id', $student->id)->value('id');
        // `otherTeacher` belongs to the Cohort but does not teach this Session; `stranger` is not in the Cohort.
        $stranger = $this->user($setup['customer'], 'teacher');
        foreach ([$setup['teacher'], $setup['otherTeacher']] as $teacher) {
            DB::table('core_course_cohort_teachers')->insert([
                'customer_id' => $setup['customer'], 'cohort_id' => $setup['cohort'], 'teacher_id' => $teacher->id, 'role' => 'teacher',
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $page = self::HOST."/teacher/cohorts/{$setup['cohort']}/attendance";
        $saveUrl = self::HOST."/teacher/cohorts/{$setup['cohort']}/sessions/{$session}/attendance";
        $payload = fn (string $status, string $loaded = 'registered') => ['attendance' => [[
            'enrollment_id' => $enrollment, 'status' => $status, 'attendance_mode' => 'online', 'notes' => '',
            'loaded_status' => $loaded, 'loaded_mode' => $loaded === 'registered' ? '' : 'online', 'loaded_notes' => '',
        ]]];
        $status = fn () => DB::table('core_liveclass_attendances')->where('enrollment_id', $enrollment)->value('status');

        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:30:00', self::TZ));
        $this->actingAs($stranger)->get($page)->assertNotFound();
        $this->actingAs($stranger)->put($saveUrl, $payload('present'))->assertNotFound();
        $this->actingAs($setup['admin'])->get($page)->assertForbidden();
        $this->actingAs($setup['otherTeacher'])->get($page)->assertOk()
            ->assertSee($student->name)->assertSee(__('lf.LF_course_cohort_attendance_save'))
            ->assertSee(LiveClassAttendancePolicies::DEFAULT_NAME)
            // The whole policy can be read in a popup, and the menu groups the teacher's two class pages.
            ->assertSee('data-attendance-policy-dialog', false)
            ->assertSeeText(__('lf.LF_teacher_attendance_rules_open'))
            ->assertSeeText(__('lf.LF_teacher_attendance_rules_auto_absent'))
            ->assertSeeText(__('lf.LF_teacher_attendance_rules_offline_system'))
            ->assertSeeText(__('lf.LF_teacher_attendance_rules_edit_value', ['value' => trans_choice('lf.LF_attendance_policy_days', 7, ['count' => 7])]))
            ->assertSee('<a class="admin-nav__child" href="'.self::HOST.'/teacher/cohorts"', false)
            ->assertSee('<a class="admin-nav__child" href="'.self::HOST.'/teacher/live-sessions"', false);

        $this->actingAs($setup['otherTeacher'])->put($saveUrl, $payload('present'))
            ->assertRedirect($page.'?session_id='.$session)->assertSessionHas('success');
        $this->assertSame('present', $status());
        $this->assertDatabaseHas('core_liveclass_attendances', ['enrollment_id' => $enrollment, 'attendance_source' => 'manual',
            'recorded_by' => $setup['otherTeacher']->id]);
        $this->assertDatabaseHas('core_liveclass_attendance_changes', ['enrollment_id' => $enrollment, 'old_status' => null,
            'new_status' => 'present', 'changed_by' => $setup['otherTeacher']->id]);

        // The window is 7 × 24 hours from the planned end (17:30): open one minute before it closes, shut one minute after.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-11 17:29:00', self::TZ));
        $this->actingAs($setup['teacher'])->put($saveUrl, $payload('late', 'present'))->assertSessionHasNoErrors();
        $this->assertSame('late', $status());
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-11 17:31:00', self::TZ));
        $this->actingAs($setup['teacher'])->put($saveUrl, $payload('absent', 'late'))->assertStatus(422);
        $this->assertSame('late', $status());
        $this->actingAs($setup['teacher'])->get($page.'?session_id='.$session)->assertOk()
            ->assertSee(__('lf.LF_teacher_attendance_closed', ['time' => '11/07/2026 17:30']))
            ->assertDontSee(__('lf.LF_course_cohort_attendance_save'));
        // The administrator has no limit.
        $this->actingAs($setup['admin'])->put(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/attendance",
            $payload('absent', 'late'))->assertSessionHasNoErrors();
        $this->assertSame('absent', $status());

        // A Session that has not started takes no attendance; a teacher of another tenant reaches nothing.
        $future = $this->liveSession($setup, 'online');
        DB::table('core_liveclass_sessions')->where('id', $future)->update([
            'status' => 'scheduled', 'actual_start_at' => null,
            'scheduled_start_at' => '2026-07-20 16:00:00', 'scheduled_end_at' => '2026-07-20 17:30:00',
        ]);
        $this->actingAs($setup['teacher'])->put(self::HOST."/teacher/cohorts/{$setup['cohort']}/sessions/{$future}/attendance", $payload('present'))
            ->assertStatus(422);
        config(['liveclass.attendance_enabled' => false]);
        $this->actingAs($setup['teacher'])->get($page)->assertNotFound();
    }

    public function test_a_completed_class_is_still_corrected_by_hand_and_an_archived_one_only_reads(): void
    {
        // ADR-0022 Amendment 1.4, addendum 2026-11-19 (MK 1.4, 2.5).
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'offline');
        DB::table('core_liveclass_sessions')->where('id', $session)->update(['status' => 'completed', 'actual_end_at' => '2026-07-04 17:30:00']);
        $student = $this->learner($setup);
        $enrollment = (int) DB::table('core_course_enrollments')->where('student_id', $student->id)->value('id');
        DB::table('core_course_cohort_teachers')->insert([
            'customer_id' => $setup['customer'], 'cohort_id' => $setup['cohort'], 'teacher_id' => $setup['teacher']->id, 'role' => 'teacher',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('core_course_cohorts')->where('id', $setup['cohort'])->update(['status' => 'completed']);
        $adminPage = self::HOST."/admin/course-cohorts/{$setup['cohort']}?tab=attendance&session_id={$session}";
        $adminSave = self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/attendance";
        $teacherPage = self::HOST."/teacher/cohorts/{$setup['cohort']}/attendance?session_id={$session}";
        $teacherSave = self::HOST."/teacher/cohorts/{$setup['cohort']}/sessions/{$session}/attendance";
        $payload = fn (string $status, string $loaded) => ['attendance' => [[
            'enrollment_id' => $enrollment, 'status' => $status, 'attendance_mode' => 'offline', 'notes' => '',
            'loaded_status' => $loaded, 'loaded_mode' => $loaded === 'registered' ? '' : 'offline', 'loaded_notes' => '',
        ]]];
        $status = fn () => DB::table('core_liveclass_attendances')->where('enrollment_id', $enrollment)->value('status');
        $liveDone = fn () => DB::table('core_course_activity_progress')->where('enrollment_id', $enrollment)
            ->where('version_activity_id', $setup['liveActivity'])->value('status');

        // The teacher records within the edit window; the page says what stays closed.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-05 09:00:00', self::TZ));
        $this->actingAs($setup['teacher'])->get($teacherPage)->assertOk()
            ->assertSee(__('lf.LF_course_cohort_attendance_save'))
            ->assertSeeText(__('lf.LF_attendance_completed_class_note'))
            ->assertDontSeeText(__('lf.LF_teacher_attendance_closed_cohort'));
        $this->actingAs($setup['teacher'])->put($teacherSave, $payload('absent', 'registered'))->assertSessionHasNoErrors();
        $this->assertSame('absent', $status());
        $this->assertNotSame('completed', $liveDone());

        // After the window the teacher only reads; the administrator corrects without a limit, and progress follows.
        Carbon::setTestNow(CarbonImmutable::parse('2026-08-30 09:00:00', self::TZ));
        $this->actingAs($setup['teacher'])->put($teacherSave, $payload('present', 'absent'))->assertStatus(422);
        $this->actingAs($setup['admin'])->get($adminPage)->assertOk()
            ->assertSee(__('lf.LF_course_cohort_attendance_save'))
            ->assertSeeText(__('lf.LF_attendance_completed_class_note'))
            ->assertDontSeeText(__('lf.LF_course_cohort_attendance_read_only'));
        $this->actingAs($setup['admin'])->put($adminSave, $payload('present', 'absent'))->assertSessionHasNoErrors();
        $this->assertSame('present', $status());
        $this->assertSame('completed', $liveDone());
        $this->assertSame(2, DB::table('core_liveclass_attendance_changes')->where('enrollment_id', $enrollment)->count());

        // An archived class only reads, for both.
        DB::table('core_course_cohorts')->where('id', $setup['cohort'])->update(['status' => 'archived']);
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-05 09:00:00', self::TZ));
        $this->actingAs($setup['teacher'])->put($teacherSave, $payload('late', 'present'))->assertStatus(422);
        $this->actingAs($setup['teacher'])->get($teacherPage)->assertOk()
            ->assertSeeText(__('lf.LF_teacher_attendance_closed_cohort'))
            ->assertDontSee(__('lf.LF_course_cohort_attendance_save'));
        $this->actingAs($setup['admin'])->put($adminSave, $payload('late', 'present'))->assertStatus(422);
        $this->actingAs($setup['admin'])->get($adminPage)->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_attendance_read_only'))
            ->assertDontSeeText(__('lf.LF_attendance_completed_class_note'));
        $this->assertSame('present', $status());
    }

    public function test_completing_a_class_cancels_sessions_not_held_and_ends_running_ones(): void
    {
        // ADR-0022 Amendment 1.4, "Dọn buổi khi hoàn thành lớp". The admin completes the class at 16:30 on 4 July.
        config(['liveclass.auto_absent_from' => '2026-07-01 00:00:00']);
        $setup = $this->setupClass();
        $student = $this->learner($setup);
        $at = fn (string $start, string $end, string $status, ?string $actualStart = null) => fn (int $id) => DB::table('core_liveclass_sessions')
            ->where('id', $id)->update(['scheduled_start_at' => $start, 'scheduled_end_at' => $end, 'status' => $status, 'actual_start_at' => $actualStart]);
        $running = $this->liveSession($setup, 'offline');
        $overdueLive = $this->liveSession($setup, 'offline');
        $at('2026-07-03 16:00:00', '2026-07-03 17:30:00', 'live', '2026-07-03 16:00:00')($overdueLive);
        $future = $this->liveSession($setup, 'online');
        $at('2026-07-10 16:00:00', '2026-07-10 17:30:00', 'scheduled')($future);
        $notStarted = $this->liveSession($setup, 'offline');
        $at('2026-07-04 14:00:00', '2026-07-04 15:30:00', 'scheduled')($notStarted);
        [, $selfNow] = $this->selfStudySession($setup, 'Tự học đang trong giờ');
        [, $selfPast] = $this->selfStudySession($setup, 'Tự học đã qua');
        $at('2026-07-02 16:00:00', '2026-07-02 17:30:00', 'scheduled')($selfPast);
        [, $selfFuture] = $this->selfStudySession($setup, 'Tự học sắp tới');
        $at('2026-07-08 16:00:00', '2026-07-08 17:30:00', 'scheduled')($selfFuture);
        $row = fn (int $id): object => DB::table('core_liveclass_sessions')->where('id', $id)
            ->first(['status', 'actual_end_at', 'cancellation_reason', 'superseded_by_session_id']);
        $page = self::HOST."/admin/course-cohorts/{$setup['cohort']}";

        // The confirmation says what will happen to the Sessions.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:30:00', self::TZ));
        $this->actingAs($setup['admin'])->get($page)->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_complete_warning_sessions', ['count' => 2]))
            ->assertSeeText(__('lf.LF_course_cohort_complete_warning_sessions_running', ['count' => 4]));

        $this->actingAs($setup['admin'])->post($page.'/complete')->assertRedirect($page);
        $this->assertSame('completed', DB::table('core_course_cohorts')->where('id', $setup['cohort'])->value('status'));

        // Not held: cancelled with the reason, no lesson moves.
        foreach ([$future, $selfFuture] as $id) {
            $this->assertSame(['cancelled', __('lf.LF_course_cohort_complete_session_cancel_reason'), null],
                [$row($id)->status, $row($id)->cancellation_reason, $row($id)->superseded_by_session_id]);
        }
        // Running: ended now, or at the scheduled end when that has passed.
        $this->assertSame(['completed', '2026-07-04 16:30:00'], [$row($running)->status, (string) $row($running)->actual_end_at]);
        $this->assertSame(['completed', '2026-07-04 16:30:00'], [$row($selfNow)->status, (string) $row($selfNow)->actual_end_at]);
        $this->assertSame(['completed', '2026-07-03 17:30:00'], [$row($overdueLive)->status, (string) $row($overdueLive)->actual_end_at]);
        $this->assertSame(['completed', '2026-07-02 17:30:00'], [$row($selfPast)->status, (string) $row($selfPast)->actual_end_at]);
        // Past its start but never started: left for the hand and for automatic absence.
        $this->assertSame('scheduled', $row($notStarted)->status);

        // Automatic absence closes the Sessions that took place, never the cancelled ones.
        app(LiveClassAttendanceRecorder::class)->markAbsent($setup['customer'], CarbonImmutable::parse('2026-07-10 20:00:00', self::TZ));
        $result = fn (int $id): ?string => DB::table('core_liveclass_attendances')->where('session_id', $id)
            ->where('user_id', $student->id)->value('status');
        $this->assertSame('absent', $result($notStarted));
        $this->assertSame('absent', $result($running));
        $this->assertNull($result($future));
        $this->assertNull($result($selfFuture));
    }

    public function test_a_class_ends_by_itself_after_its_end_date(): void
    {
        // ADR-0022 Amendment 1.4, "Lớp tự kết thúc theo ngày": end date 31 August 2026, application timezone.
        $setup = $this->setupClass();
        $lastSession = $this->liveSession($setup, 'online');
        DB::table('core_liveclass_sessions')->where('id', $lastSession)->update([
            'status' => 'scheduled', 'actual_start_at' => null,
            'scheduled_start_at' => '2026-08-31 16:00:00', 'scheduled_end_at' => '2026-08-31 17:30:00',
        ]);
        $legacy = DB::table('core_course_cohorts')->insertGetId([
            'customer_id' => $setup['customer'], 'product_id' => $setup['product'], 'version_id' => $setup['version'],
            'name' => 'Legacy class', 'code' => 'COH-LEGACY', 'status' => 'active', 'start_date' => null, 'end_date' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $status = fn (int $cohort): string => (string) DB::table('core_course_cohorts')->where('id', $cohort)->value('status');

        Carbon::setTestNow(CarbonImmutable::parse('2026-08-31 23:59:00', self::TZ));
        $this->artisan('course:complete-ended-cohorts')->expectsOutput('Completed 0 ended class(es).')->assertSuccessful();
        $this->assertSame('active', $status($setup['cohort']));

        Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 00:00:30', self::TZ));
        $this->artisan('course:complete-ended-cohorts')->expectsOutput('Completed 1 ended class(es).')->assertSuccessful();
        $this->assertSame('completed', $status($setup['cohort']));
        // Every Session lies inside the class period, so by then none is still to come: one nobody started stays
        // for the hand and for automatic absence, as when the button is pressed.
        $this->assertSame('scheduled', DB::table('core_liveclass_sessions')->where('id', $lastSession)->value('status'));
        // A class without an end date is left to an administrator; a second run finds nothing.
        $this->assertSame('active', $status($legacy));
        $this->artisan('course:complete-ended-cohorts')->expectsOutput('Completed 0 ended class(es).')->assertSuccessful();
    }

    public function test_a_late_start_reevaluates_the_online_entries_the_system_recorded(): void
    {
        $setup = $this->setupClass();
        // Planned 16:00–17:30, not started yet.
        $session = $this->liveSession($setup, 'online');
        DB::table('core_liveclass_sessions')->where('id', $session)->update(['status' => 'scheduled', 'actual_start_at' => null]);
        [$onTime, $late, $absent, $byHand] = array_map(fn () => $this->learner($setup), range(1, 4));
        $enterAt = function (User $student, string $localTime) use ($session): void {
            Carbon::setTestNow(CarbonImmutable::parse($localTime, self::TZ));
            $this->actingAs($student)->post(self::HOST."/my-courses/live-sessions/{$session}/meeting")->assertRedirect();
        };
        $status = fn (User $student) => DB::table('core_liveclass_attendances')->where('session_id', $session)
            ->where('user_id', $student->id)->value('status');
        $enterAt($onTime, '2026-07-04 16:05:00');
        $enterAt($late, '2026-07-04 16:12:00');
        $enterAt($absent, '2026-07-04 16:35:00');
        DB::table('core_liveclass_attendances')->insert([
            'customer_id' => $setup['customer'], 'session_id' => $session, 'user_id' => $byHand->id, 'status' => 'late',
            'enrollment_id' => DB::table('core_course_enrollments')->where('student_id', $byHand->id)->value('id'),
            'attendance_mode' => 'online', 'attendance_source' => 'manual', 'recorded_by' => $setup['admin']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(['present', 'late', 'absent'], [$status($onTime), $status($late), $status($absent)]);

        // The teacher presses Start at 16:40: everyone who entered before that is on time. The manual result stays.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:40:00', self::TZ));
        $this->actingAs($setup['teacher'])->post(self::HOST."/teacher/live-sessions/{$session}/start")->assertSessionHasNoErrors();
        $this->assertSame('live', DB::table('core_liveclass_sessions')->where('id', $session)->value('status'));
        $this->assertSame(['present', 'present', 'present', 'late'], [$status($onTime), $status($late), $status($absent), $status($byHand)]);
        $this->assertSame(2, DB::table('core_liveclass_attendance_changes')->where('session_id', $session)
            ->where('change_source', 'system')->whereNull('changed_by')->where('new_status', 'present')->count());

        // A policy that counts from the planned start changes nothing on a late start.
        $fixed = $this->liveSession($setup, 'online');
        DB::table('core_liveclass_sessions')->where('id', $fixed)->update(['status' => 'scheduled', 'actual_start_at' => null]);
        DB::table(LiveClassAttendancePolicies::TABLE)->where('customer_id', $setup['customer'])->update(['count_from_actual_start' => 0]);
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:12:00', self::TZ));
        $this->actingAs($late)->post(self::HOST."/my-courses/live-sessions/{$fixed}/meeting")->assertRedirect();
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:40:00', self::TZ));
        $this->actingAs($setup['teacher'])->post(self::HOST."/teacher/live-sessions/{$fixed}/start")->assertSessionHasNoErrors();
        $this->assertSame('late', DB::table('core_liveclass_attendances')->where('session_id', $fixed)->where('user_id', $late->id)->value('status'));
    }

    public function test_learners_without_a_result_are_marked_absent_after_the_session(): void
    {
        config(['liveclass.auto_absent_from' => '2026-07-01 00:00:00']);
        $setup = $this->setupClass();
        // Planned 16:00–17:30; the default policy waits 30 minutes after the end.
        $session = $this->liveSession($setup, 'online');
        $present = $this->learner($setup);
        $silent = $this->learner($setup);
        $joinedLater = $this->learner($setup);
        $inactive = $this->learner($setup, 'cancelled');
        $left = $this->learner($setup);
        DB::table('core_course_cohort_students')->where('student_id', $joinedLater->id)->update(['joined_at' => '2026-07-04 18:00:00']);
        DB::table('core_course_cohort_students')->where('student_id', $left->id)->update(['status' => 'removed', 'left_at' => '2026-07-03 00:00:00']);
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:05:00', self::TZ));
        $this->actingAs($present)->post(self::HOST."/my-courses/live-sessions/{$session}/meeting")->assertRedirect();
        $rows = fn () => DB::table('core_liveclass_attendances')->where('session_id', $session)->orderBy('user_id')
            ->get(['user_id', 'status', 'attendance_source', 'joined_at', 'attendance_mode'])->keyBy('user_id');

        // One minute before the delay ends nothing is written.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 17:59:00', self::TZ));
        $this->artisan('liveclass:mark-absent')->assertSuccessful();
        $this->assertCount(1, $rows());

        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 18:00:00', self::TZ));
        $this->artisan('liveclass:mark-absent')->expectsOutput('Marked 1 learner(s) absent.')->assertSuccessful();
        $this->assertSame([$present->id, $silent->id], $rows()->keys()->all());
        $this->assertEquals(['user_id' => $silent->id, 'status' => 'absent', 'attendance_source' => 'system', 'joined_at' => null,
            'attendance_mode' => null], (array) $rows()[$silent->id]);
        $this->assertSame('present', $rows()[$present->id]->status);
        // The first write of the system is not a history row, and a second sweep writes nothing more.
        $this->assertSame(0, DB::table('core_liveclass_attendance_changes')->count());
        $this->artisan('liveclass:mark-absent')->expectsOutput('Marked 0 learner(s) absent.');

        // The teacher can still correct it; a manual result is not touched by a later sweep.
        $this->actingAs($setup['admin'])->put(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/attendance", [
            'attendance' => [['enrollment_id' => DB::table('core_course_enrollments')->where('student_id', $silent->id)->value('id'),
                'status' => 'excused', 'attendance_mode' => '', 'notes' => '', 'loaded_status' => 'absent', 'loaded_mode' => '', 'loaded_notes' => '']],
        ])->assertSessionHasNoErrors();
        $this->artisan('liveclass:mark-absent');
        $this->assertSame('excused', $rows()[$silent->id]->status);

        // Skipped: a Session before the cutover, a cancelled one, one left to the teacher by the policy, attendance off.
        $before = $this->liveSession($setup, 'online');
        $cancelled = $this->liveSession($setup, 'online');
        DB::table('core_liveclass_sessions')->where('id', $before)->update([
            'scheduled_start_at' => '2026-06-30 16:00:00', 'scheduled_end_at' => '2026-06-30 17:30:00', 'status' => 'completed']);
        DB::table('core_liveclass_sessions')->where('id', $cancelled)->update(['status' => 'cancelled']);
        $this->artisan('liveclass:mark-absent')->expectsOutput('Marked 0 learner(s) absent.');
        $byHand = $this->liveSession($setup, 'online');
        DB::table(LiveClassAttendancePolicies::TABLE)->where('customer_id', $setup['customer'])->update(['online_recording' => 'manual']);
        $this->artisan('liveclass:mark-absent')->expectsOutput('Marked 0 learner(s) absent.');
        DB::table(LiveClassAttendancePolicies::TABLE)->where('customer_id', $setup['customer'])->update(['online_recording' => 'system']);
        config(['liveclass.attendance_enabled' => false]);
        $this->artisan('liveclass:mark-absent')->expectsOutput('Marked 0 learner(s) absent.');
        config(['liveclass.attendance_enabled' => true]);
        // With the policy back on "by the system" the Session it had skipped is caught up.
        $this->artisan('liveclass:mark-absent')->expectsOutput('Marked 2 learner(s) absent.');
        $this->assertSame(2, DB::table('core_liveclass_attendances')->where('session_id', $byHand)->where('status', 'absent')->count());
        $this->assertDatabaseMissing('core_liveclass_attendances', ['session_id' => $before]);
        $this->assertDatabaseMissing('core_liveclass_attendances', ['session_id' => $cancelled]);
    }

    public function test_the_teachers_of_a_session_change_its_operational_part_and_each_change_is_audited(): void
    {
        $setup = $this->setupClass();
        // A hybrid Session tomorrow, 16:00–17:30, not started.
        $session = $this->liveSession($setup, 'hybrid');
        DB::table('core_liveclass_sessions')->where('id', $session)->update([
            'status' => 'scheduled', 'actual_start_at' => null,
            'scheduled_start_at' => '2026-07-05 16:00:00', 'scheduled_end_at' => '2026-07-05 17:30:00',
        ]);
        $url = self::HOST."/teacher/live-sessions/{$session}";
        $row = fn () => (array) DB::table('core_liveclass_sessions')->where('id', $session)->first([
            'meeting_url_snapshot', 'room_name_snapshot', 'address_snapshot', 'attendance_tool', 'delivery_mode', 'scheduled_start_at',
        ]);

        $this->actingAs($setup['teacher'])->get(self::HOST.'/teacher/live-sessions')->assertOk()->assertSee('data-session-edit', false);
        // A teacher who does not teach this Session reaches nothing, whatever they send.
        $this->actingAs($setup['otherTeacher'])->put($url, ['room_name' => 'Hijacked'])->assertNotFound();

        // The four fields change; the delivery mode and the time, sent anyway, do not.
        $this->actingAs($setup['teacher'])->put($url, [
            'meeting_url' => 'https://zoom.example.test/j/123', 'room_name' => 'Phòng 302', 'address' => 'Tầng 3', 'attendance_tool' => 'button',
            'delivery_mode' => 'online', 'scheduled_start_at' => '2026-07-06 16:00:00',
        ])->assertRedirect(self::HOST.'/teacher/live-sessions')->assertSessionHas('success', __('lf.LF_liveclass_session_operations_saved'));
        $this->assertSame([
            'meeting_url_snapshot' => 'https://zoom.example.test/j/123', 'room_name_snapshot' => 'Phòng 302', 'address_snapshot' => 'Tầng 3',
            'attendance_tool' => 'button', 'delivery_mode' => 'hybrid', 'scheduled_start_at' => '2026-07-05 16:00:00',
        ], $row());
        $audit = DB::table('saas_audit_logs')->where('action', 'liveclass_session.operations_updated')->first();
        $this->assertSame([$setup['customer'], $setup['teacher']->id], [(int) $audit->customer_id, (int) $audit->actor_id]);
        $this->assertSame('Room A', json_decode($audit->before, true)['room_name_snapshot']);
        $this->assertSame(['session_id' => $session, 'room_name_snapshot' => 'Phòng 302'],
            array_intersect_key(json_decode($audit->after, true), ['session_id' => 1, 'room_name_snapshot' => 1]));

        // Saving the same values writes nothing and no audit row; a bad link or an empty room is refused.
        $this->actingAs($setup['teacher'])->put($url, [
            'meeting_url' => 'https://zoom.example.test/j/123', 'room_name' => 'Phòng 302', 'address' => 'Tầng 3', 'attendance_tool' => 'button',
        ])->assertSessionHas('success', __('lf.LF_liveclass_session_operations_unchanged'));
        $this->assertSame(1, DB::table('saas_audit_logs')->where('action', 'liveclass_session.operations_updated')->count());
        $this->actingAs($setup['teacher'])->put($url, ['meeting_url' => 'javascript:alert(1)', 'room_name' => ''])
            ->assertSessionHasErrorsIn('sessionOperations', ['meeting_url', 'room_name']);
        $this->assertSame('Phòng 302', $row()['room_name_snapshot']);

        // Once the Session is live only the meeting link still changes, for the teacher and for the administrator.
        DB::table('core_liveclass_sessions')->where('id', $session)->update([
            'status' => 'live', 'actual_start_at' => '2026-07-04 16:00:00',
            'scheduled_start_at' => '2026-07-04 16:00:00', 'scheduled_end_at' => '2026-07-04 17:30:00',
        ]);
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:30:00', self::TZ));
        $this->actingAs($setup['teacher'])->put($url, ['meeting_url' => 'https://meet.example.test/new', 'room_name' => 'Phòng khác', 'attendance_tool' => 'board'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['https://meet.example.test/new', 'Phòng 302', 'button'],
            [$row()['meeting_url_snapshot'], $row()['room_name_snapshot'], $row()['attendance_tool']]);
        $this->actingAs($setup['admin'])->get(self::HOST."/admin/course-cohorts/{$setup['cohort']}?tab=sessions")->assertOk()
            ->assertSee(__('lf.LF_liveclass_session_operations_link_edit'));
        $this->actingAs($setup['admin'])->put(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/meeting-link",
            ['meeting_url' => 'https://meet.example.test/admin', 'room_name' => 'Phòng admin'])->assertSessionHasNoErrors();
        $this->assertSame(['https://meet.example.test/admin', 'Phòng 302'], [$row()['meeting_url_snapshot'], $row()['room_name_snapshot']]);
        $this->assertSame(3, DB::table('saas_audit_logs')->where('action', 'liveclass_session.operations_updated')->count());

        // After the planned end nothing changes any more.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 17:31:00', self::TZ));
        $this->actingAs($setup['teacher'])->put($url, ['meeting_url' => 'https://meet.example.test/late'])->assertStatus(422);
        $this->actingAs($setup['admin'])->put(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/meeting-link",
            ['meeting_url' => 'https://meet.example.test/late'])->assertStatus(422);
        $this->assertSame('https://meet.example.test/admin', $row()['meeting_url_snapshot']);
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

    public function test_ticked_content_decides_attendance_and_make_up(): void
    {
        // ADR-0022 Amendment 1.3, Đợt 3 (MK 3.4, 3.5). Planned 16:00–17:30; late after 16:10, absent after 16:30.
        config(['liveclass.auto_absent_from' => '2026-07-01 00:00:00']);
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'online');
        $inClass = $this->tickedActivity($setup, 'Bài tập trên lớp', 'during');
        $homework = $this->tickedActivity($setup, 'Bài tập về nhà', 'after');
        [$onTime, $late, $madeUp, $tooLate, $silent, $waiting, $byHand] = array_map(fn () => $this->learner($setup), range(1, 7));
        $enrollment = fn (User $student): int => (int) DB::table('core_course_cohort_students')->where('student_id', $student->id)->value('enrollment_id');
        $at = fn (string $localTime) => Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 '.$localTime, self::TZ));
        $enter = function (User $student, string $localTime) use ($session, $at): void {
            $at($localTime);
            $this->actingAs($student)->post(self::HOST."/my-courses/live-sessions/{$session}/meeting")->assertRedirect();
        };
        $open = function (User $student, int $activity, string $localTime) use ($enrollment, $at) {
            $at($localTime);

            return $this->actingAs($student)->postJson(self::HOST.'/my-courses/'.$enrollment($student).'/activities/'.$activity.'/progress', ['event' => 'opened']);
        };
        $row = fn (User $student): ?object => DB::table('core_liveclass_attendances')->where('session_id', $session)->where('user_id', $student->id)
            ->first(['status', 'attendance_source', 'attendance_mode', 'joined_at']);
        $result = fn (User $student): array => [$row($student)->status, $row($student)->attendance_source, (string) $row($student)->joined_at];

        // Entered but the ticked content is not done: the entry is kept and the result waits.
        $enter($onTime, '16:02:00');
        $this->assertSame(['registered', 'system', '2026-07-04 16:02:00'], $result($onTime));
        $this->actingAs($onTime)->get(self::HOST.'/my-courses')->assertOk()
            ->assertSeeText(__('lf.LF_liveclass_attendance_entry_pending', ['time' => '16:02']));
        $this->assertNull(DB::table('core_course_activity_progress')->where('enrollment_id', $enrollment($onTime))
            ->where('version_activity_id', $setup['liveActivity'])->where('status', 'completed')->value('id'));
        // Done in time: the result is the one of the entry time, and the live class completes.
        $open($onTime, $inClass, '16:40:00')->assertOk();
        $this->assertSame(['present', 'system', '2026-07-04 16:02:00'], $result($onTime));
        $this->assertSame('completed', DB::table('core_course_activity_progress')->where('enrollment_id', $enrollment($onTime))
            ->where('version_activity_id', $setup['liveActivity'])->value('status'));

        // The content first, the entry after: no waiting. A late entry stays late.
        $open($late, $inClass, '16:05:00')->assertOk();
        $enter($late, '16:15:00');
        $this->assertSame(['late', 'system', '2026-07-04 16:15:00'], $result($late));

        // "Sau" content opens at the planned end, so nothing is made up before it.
        $open($madeUp, $homework, '17:00:00')->assertForbidden();

        // Entered in time, content not done by the end, homework done after: present by make-up, entry kept.
        $enter($madeUp, '16:02:00');
        $open($madeUp, $inClass, '17:40:00')->assertOk();
        $this->assertSame('registered', $row($madeUp)->status);
        $open($madeUp, $homework, '17:45:00')->assertOk();
        $this->assertSame(['present', 'makeup', '2026-07-04 16:02:00'], $result($madeUp));
        $this->assertSame('online', $row($madeUp)->attendance_mode);
        $this->assertDatabaseHas('core_liveclass_attendance_changes', [
            'session_id' => $session, 'enrollment_id' => $enrollment($madeUp), 'old_status' => 'registered', 'new_status' => 'present',
            'change_source' => 'system', 'changed_by' => null,
        ]);

        // Past the absent threshold the entry is absent at once; the homework still makes it up.
        $enter($tooLate, '16:45:00');
        $this->assertSame('absent', $row($tooLate)->status);
        $open($tooLate, $homework, '17:50:00')->assertOk();
        $this->assertSame(['present', 'makeup', '2026-07-04 16:45:00'], $result($tooLate));

        // A result set by hand is final.
        $at('17:55:00');
        $this->actingAs($setup['admin'])->put(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/attendance", [
            'attendance' => [['enrollment_id' => $enrollment($byHand), 'status' => 'absent', 'attendance_mode' => '', 'notes' => '']],
        ])->assertSessionHasNoErrors();
        $open($byHand, $homework, '17:56:00')->assertOk();
        $this->assertSame(['absent', 'manual'], [$row($byHand)->status, $row($byHand)->attendance_source]);

        // Automatic absence takes the waiting entry too and keeps its entry time; both can still be made up.
        $enter($waiting, '16:03:00');
        $at('18:00:00');
        $this->artisan('liveclass:mark-absent')->expectsOutput('Marked 2 learner(s) absent.')->assertSuccessful();
        $this->assertSame(['absent', 'system', '2026-07-04 16:03:00'], $result($waiting));
        $this->assertSame(['absent', 'system', ''], $result($silent));
        $open($silent, $homework, '18:10:00')->assertOk();
        $this->assertSame(['present', 'makeup', ''], $result($silent));
        $this->assertDatabaseHas('core_liveclass_attendance_changes', [
            'session_id' => $session, 'enrollment_id' => $enrollment($silent), 'old_status' => 'absent', 'new_status' => 'present',
        ]);

        // The class page and the roster name the make-up.
        $this->actingAs($silent)->get(self::HOST.'/my-courses/classes/'.$setup['cohort'])->assertOk()
            ->assertSeeText('✓ '.__('lf.LF_my_learning_board_chip_makeup'));
        $this->actingAs($setup['admin'])->get(self::HOST."/admin/course-cohorts/{$setup['cohort']}?tab=attendance&session_id={$session}")->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_attendance_source_makeup'));
    }

    public function test_make_up_follows_the_policy_of_the_class(): void
    {
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'online');
        $homework = $this->tickedActivity($setup, 'Bài tập về nhà', 'after');
        [$off, $inTime, $overdue, $joinedLater, $ended] = array_map(fn () => $this->learner($setup), range(1, 5));
        $enrollment = fn (User $student): int => (int) DB::table('core_course_cohort_students')->where('student_id', $student->id)->value('enrollment_id');
        $open = function (User $student, string $local) use ($enrollment, $homework) {
            Carbon::setTestNow(CarbonImmutable::parse($local, self::TZ));

            return $this->actingAs($student)->postJson(self::HOST.'/my-courses/'.$enrollment($student).'/activities/'.$homework.'/progress', ['event' => 'opened']);
        };
        $status = fn (User $student): ?string => DB::table('core_liveclass_attendances')->where('session_id', $session)
            ->where('user_id', $student->id)->value('status');
        $policy = fn (array $values) => DB::table(LiveClassAttendancePolicies::TABLE)->where('customer_id', $setup['customer'])->update($values);

        // Make-up switched off: the content is learned, the Session gets no result from it.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 17:40:00', self::TZ));
        app(LiveClassAttendancePolicies::class)->forCohort($setup['customer'], $setup['cohort']);
        $policy(['makeup_enabled' => false]);
        $open($off, '2026-07-04 17:40:00')->assertOk();
        $this->assertNull($status($off));

        // Two days: until 23:59 of 6 July, in the Session's timezone.
        $policy(['makeup_enabled' => true, 'makeup_within_days' => 2]);
        $open($inTime, '2026-07-06 23:59:00')->assertOk();
        $this->assertSame('present', $status($inTime));
        $open($overdue, '2026-07-07 00:00:01')->assertOk();
        $this->assertNull($status($overdue));

        // A Session that ended before the learner joined the class has no make-up; nor has a class that ended.
        $policy(['makeup_within_days' => null]);
        DB::table('core_course_cohort_students')->where('student_id', $joinedLater->id)->update(['joined_at' => '2026-07-05 00:00:00']);
        $open($joinedLater, '2026-07-07 09:00:00')->assertOk();
        $this->assertNull($status($joinedLater));
        DB::table('core_course_cohorts')->where('id', $setup['cohort'])->update(['status' => 'completed']);
        $open($ended, '2026-07-07 09:05:00');
        $this->assertNull($status($ended));
    }

    public function test_a_self_study_session_records_attendance_by_its_ticked_content(): void
    {
        // ADR-0022 Amendment 1.3, Đợt 4, Bước 3 (MK 3.4). The session runs 16:00–17:30; nobody teaches it.
        config(['liveclass.auto_absent_from' => '2026-07-01 00:00:00']);
        $setup = $this->setupClass();
        [$lesson, $session] = $this->selfStudySession($setup, 'Ôn tập');
        $reading = $this->selfStudyActivity($setup, $lesson, 'Bài đọc', true);
        $exercise = $this->selfStudyActivity($setup, $lesson, 'Bài tập', true);
        $extra = $this->selfStudyActivity($setup, $lesson, 'Đọc thêm', false);
        [$inTime, $madeUp, $silent, $excused, $partial] = array_map(fn () => $this->learner($setup), range(1, 5));
        $enrollment = fn (User $student): int => (int) DB::table('core_course_cohort_students')->where('student_id', $student->id)->value('enrollment_id');
        $at = fn (string $localTime) => Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 '.$localTime, self::TZ));
        $open = function (User $student, int $activity, string $localTime) use ($enrollment, $at) {
            $at($localTime);

            return $this->actingAs($student)->postJson(self::HOST.'/my-courses/'.$enrollment($student).'/activities/'.$activity.'/progress', ['event' => 'opened']);
        };
        $row = fn (User $student): ?object => DB::table('core_liveclass_attendances')->where('session_id', $session)->where('user_id', $student->id)
            ->first(['status', 'attendance_source', 'attendance_mode', 'joined_at', 'version_activity_id']);
        $result = fn (User $student): array => [$row($student)->status, $row($student)->attendance_source, (string) $row($student)->joined_at];

        // The content opens when the session starts, not before.
        $open($inTime, $reading, '15:59:00')->assertForbidden();

        // During the session the learner page shows it, with the reminder instead of a button.
        $at('16:05:00');
        $this->actingAs($inTime)->get(self::HOST.'/my-courses')->assertOk()
            ->assertSeeText(__('lf.LF_liveclass_self_study_now_title'))
            ->assertSeeText(__('lf.LF_liveclass_self_study_content_hint', ['time' => '17:30']))
            ->assertDontSee("live-sessions/{$session}/attend", false);
        $this->actingAs($inTime)->from(self::HOST.'/my-courses')->post(self::HOST."/my-courses/live-sessions/{$session}/attend")
            ->assertSessionHas('lf_attendance_entry', fn (array $flash): bool => $flash['result'] === 'not_offered');

        // Every ticked Activity done before the end: present, at the moment the last one was done. No late.
        $open($inTime, $reading, '16:10:00')->assertOk();
        $this->assertNull($row($inTime));
        $open($inTime, $exercise, '17:20:00')->assertOk();
        $this->assertSame(['present', 'system', '2026-07-04 17:20:00'], $result($inTime));
        $this->assertSame([null, null], [$row($inTime)->attendance_mode, $row($inTime)->version_activity_id]);
        $this->assertSame(0, DB::table('core_liveclass_attendance_changes')->count());

        // The last one done after the end: present by make-up.
        $open($madeUp, $reading, '16:30:00')->assertOk();
        $open($madeUp, $exercise, '17:45:00')->assertOk();
        $this->assertSame(['present', 'makeup', ''], $result($madeUp));
        $this->assertDatabaseHas('core_liveclass_attendance_changes', [
            'session_id' => $session, 'enrollment_id' => $enrollment($madeUp), 'new_status' => 'present', 'change_source' => 'system',
        ]);

        // An Activity that is not ticked decides nothing.
        $open($partial, $reading, '16:20:00')->assertOk();
        $open($partial, $extra, '16:25:00')->assertOk();
        $this->assertNull($row($partial));

        // A result set by hand is final; a self-study session takes one although it has no teacher.
        $at('17:50:00');
        $this->actingAs($setup['admin'])->put(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/attendance", [
            'attendance' => [['enrollment_id' => $enrollment($excused), 'status' => 'excused', 'attendance_mode' => '', 'notes' => 'Có đơn']],
        ])->assertSessionHasNoErrors();
        $open($excused, $reading, '17:52:00')->assertOk();
        $open($excused, $exercise, '17:53:00')->assertOk();
        $this->assertSame(['excused', 'manual'], [$row($excused)->status, $row($excused)->attendance_source]);

        // Automatic absence needs no teacher on a self-study session; the absence can still be made up.
        $at('18:00:00');
        $this->artisan('liveclass:mark-absent')->expectsOutput('Marked 2 learner(s) absent.')->assertSuccessful();
        $this->assertSame(['absent', 'system', ''], $result($silent));
        $this->assertSame('absent', $row($partial)->status);
        $open($silent, $reading, '18:05:00')->assertOk();
        $open($silent, $exercise, '18:06:00')->assertOk();
        $this->assertSame(['present', 'makeup', ''], $result($silent));

        $this->actingAs($setup['admin'])->get(self::HOST."/admin/course-cohorts/{$setup['cohort']}?tab=attendance&session_id={$session}")->assertOk()
            ->assertSeeText(__('lf.LF_course_cohort_attendance_checked_in_self_study', ['time' => '17:20']))
            ->assertSeeText(__('lf.LF_course_cohort_attendance_source_makeup'));
    }

    public function test_a_self_study_session_without_ticked_content_is_checked_in_by_its_button(): void
    {
        // MK 3.4: with nothing ticked, the learner presses "Điểm danh" during the session's hours.
        $setup = $this->setupClass();
        [$lesson, $session] = $this->selfStudySession($setup, 'Tự luyện');
        $this->selfStudyActivity($setup, $lesson, 'Đọc thêm', false);
        [$early, $onTime, $late] = array_map(fn () => $this->learner($setup), range(1, 3));
        $attend = self::HOST."/my-courses/live-sessions/{$session}/attend";
        $press = function (User $student, string $localTime) use ($attend) {
            Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 '.$localTime, self::TZ));

            return $this->actingAs($student)->from(self::HOST.'/my-courses')->post($attend);
        };
        $row = fn (User $student): ?object => DB::table('core_liveclass_attendances')->where('session_id', $session)->where('user_id', $student->id)
            ->first(['status', 'attendance_source', 'attendance_mode', 'joined_at']);

        // Not listed before its hours; the server refuses a press made then.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 15:50:00', self::TZ));
        $this->actingAs($early)->get(self::HOST.'/my-courses')->assertOk()->assertDontSee("live-sessions/{$session}/attend", false);
        $press($early, '15:50:00')->assertSessionHas('lf_attendance_entry', fn (array $flash): bool => $flash['result'] === 'too_early');
        $this->assertNull($row($early));

        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 16:40:00', self::TZ));
        $this->actingAs($onTime)->get(self::HOST.'/my-courses')->assertOk()
            ->assertSeeText(__('lf.LF_liveclass_self_study_now_title'))
            ->assertSee("live-sessions/{$session}/attend", false);
        // Any moment inside the hours is present: there is no late.
        $press($onTime, '16:40:00')->assertRedirect(self::HOST.'/my-courses');
        $this->assertEquals(['status' => 'present', 'attendance_source' => 'system', 'attendance_mode' => null,
            'joined_at' => '2026-07-04 16:40:00'], (array) $row($onTime));
        $press($onTime, '16:50:00');
        $this->assertSame('2026-07-04 16:40:00', (string) $row($onTime)->joined_at);
        $this->actingAs($onTime)->get(self::HOST.'/my-courses')->assertOk()
            ->assertDontSee("live-sessions/{$session}/attend", false)->assertSee('data-live-attendance', false);

        $press($late, '17:31:00')->assertSessionHas('lf_attendance_entry', fn (array $flash): bool => $flash['result'] === 'too_late');
        $this->assertNull($row($late));
    }

    public function test_a_session_outside_the_learners_time_in_the_class_takes_no_attendance(): void
    {
        // ADR-0034. The session runs 16:00–17:30 on 4 July.
        config(['liveclass.auto_absent_from' => '2026-07-01 00:00:00']);
        $setup = $this->setupClass();
        $session = $this->liveSession($setup, 'online');
        [$member, $joinedLater, $away] = array_map(fn () => $this->learner($setup), range(1, 3));
        $seat = fn (User $student): object => DB::table('core_course_cohort_students')->where('student_id', $student->id)->first();
        $enrollment = fn (User $student): int => (int) $seat($student)->enrollment_id;
        // One joined the class the day after; one was out of it from the morning to the evening of that day.
        DB::table('core_course_cohort_students')->where('student_id', $joinedLater->id)->update(['joined_at' => '2026-07-05 08:00:00']);
        CourseCohortMembershipHistory::record($seat($away), 'rejoined', $setup['admin']->id,
            ['absent_from' => '2026-07-04 10:00:00', 'absent_to' => '2026-07-04 20:00:00']);
        // A result recorded before the join date was moved stays in the table.
        DB::table('core_liveclass_attendances')->insert([
            'customer_id' => $setup['customer'], 'session_id' => $session, 'enrollment_id' => $enrollment($joinedLater),
            'user_id' => $joinedLater->id, 'version_activity_id' => $setup['liveActivity'], 'status' => 'absent',
            'attendance_source' => 'system', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $status = fn (User $student): ?string => DB::table('core_liveclass_attendances')->where('session_id', $session)
            ->where('user_id', $student->id)->value('status');

        // Nobody records them by hand, and the roster says why.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 17:40:00', self::TZ));
        $this->actingAs($setup['admin'])->put(self::HOST."/admin/course-cohorts/{$setup['cohort']}/sessions/{$session}/attendance", [
            'attendance' => [
                ['enrollment_id' => $enrollment($member), 'status' => 'present', 'attendance_mode' => 'online', 'notes' => ''],
                ['enrollment_id' => $enrollment($joinedLater), 'status' => 'present', 'attendance_mode' => 'online', 'notes' => ''],
                ['enrollment_id' => $enrollment($away), 'status' => 'present', 'attendance_mode' => 'online', 'notes' => ''],
            ],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['present', 'absent', null], [$status($member), $status($joinedLater), $status($away)]);
        $roster = $this->actingAs($setup['admin'])->get(self::HOST."/admin/course-cohorts/{$setup['cohort']}?tab=attendance&session_id={$session}")->assertOk()
            ->assertSee('data-attendance-outside="before_join"', false)
            ->assertSee('data-attendance-outside="not_member"', false)->getContent();
        $this->assertSame(1, substr_count($roster, 'name="attendance[0][status]"') + substr_count($roster, 'name="attendance[1][status]"') + substr_count($roster, 'name="attendance[2][status]"'));

        // The system marks neither of them absent.
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-04 18:00:00', self::TZ));
        $this->artisan('liveclass:mark-absent')->expectsOutput('Marked 0 learner(s) absent.')->assertSuccessful();
        $this->assertNull($status($away));

        // The learner sees why instead of a result, and the session does not count toward the attendance rate.
        DB::table('core_liveclass_sessions')->where('id', $session)->update(['status' => 'completed']);
        $this->actingAs($joinedLater)->get(self::HOST.'/my-courses/classes/'.$setup['cohort'])->assertOk()
            ->assertSee('data-learn-attendance="before_join"', false)->assertSeeText(__('lf.LF_my_learning_attendance_before_join'));
        $this->actingAs($away)->get(self::HOST.'/my-courses/classes/'.$setup['cohort'])->assertOk()
            ->assertSee('data-learn-attendance="not_member"', false)->assertSeeText(__('lf.LF_my_learning_attendance_not_member'));
        $learning = app(LearnerMyLearningService::class);
        $this->assertSame([0, 0], [
            $learning->cohort($setup['customer'], $joinedLater->id, $setup['cohort'])->session_held,
            $learning->cohort($setup['customer'], $away->id, $setup['cohort'])->session_held,
        ]);
        $inside = $learning->cohort($setup['customer'], $member->id, $setup['cohort']);
        $this->assertSame([1, 1, 100], [$inside->session_held, $inside->session_attended, $inside->attendance_rate]);

        // Moving the join date back brings the stored result back.
        DB::table('core_course_cohort_students')->where('student_id', $joinedLater->id)->update(['joined_at' => '2026-07-01 00:00:00']);
        $this->actingAs($joinedLater)->get(self::HOST.'/my-courses/classes/'.$setup['cohort'])->assertOk()
            ->assertSee('data-learn-attendance="absent"', false);
    }

    /**
     * A self-study lesson of the class with its Session, planned 16:00–17:30 local time. Nobody teaches it.
     *
     * @return array{0: int, 1: int} the Version Lesson and the Session
     */
    private function selfStudySession(array $setup, string $title): array
    {
        static $source = 94000;
        $lesson = DB::table('core_course_template_version_lessons')->insertGetId([
            'customer_id' => $setup['customer'], 'template_version_id' => $setup['version'], 'source_template_lesson_id' => ++$source,
            'title_snapshot' => $title, 'lesson_type' => 'self_study', 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $session = DB::table('core_liveclass_sessions')->insertGetId([
            'customer_id' => $setup['customer'], 'cohort_id' => $setup['cohort'], 'template_version_id' => $setup['version'],
            'session_type' => 'self_study', 'version_lesson_id' => $lesson, 'version_activity_id' => null,
            'title' => $title, 'session_no' => 50 + ($source - 94000), 'delivery_mode' => null,
            'scheduled_start_at' => '2026-07-04 16:00:00', 'scheduled_end_at' => '2026-07-04 17:30:00', 'timezone' => self::TZ,
            'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [(int) $lesson, (int) $session];
    }

    /** A document of a self-study lesson, ticked "Tính vào điểm danh" or not. */
    private function selfStudyActivity(array $setup, int $lesson, string $title, bool $ticked): int
    {
        static $source = 95000;

        return DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $setup['customer'], 'template_version_id' => $setup['version'], 'version_lesson_id' => $lesson,
            'source_template_activity_id' => ++$source, 'title_snapshot' => $title, 'activity_type' => 'document', 'sort_order' => $source - 95000,
            'available_anytime' => true, 'is_required' => true, 'counts_for_attendance' => $ticked,
            'completion_rule' => 'view', 'unlock_rule_snapshot' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A document of the class Lesson ticked "Tính vào điểm danh" in the given part (`during` or `after`). */
    private function tickedActivity(array $setup, string $title, string $phase): int
    {
        static $source = 93000;

        return DB::table('core_course_template_version_activities')->insertGetId([
            'customer_id' => $setup['customer'], 'template_version_id' => $setup['version'], 'version_lesson_id' => $setup['lesson'],
            'source_template_activity_id' => ++$source, 'title_snapshot' => $title, 'activity_type' => 'document', 'sort_order' => 2,
            'available_anytime' => false, 'available_'.$phase.'_session' => true, 'is_required' => true, 'counts_for_attendance' => true,
            'completion_rule' => 'view', 'unlock_rule_snapshot' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);
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
