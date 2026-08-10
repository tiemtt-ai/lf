<?php

namespace Tests\Unit;

use App\Services\LiveClassSessionPolicy;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Session lưu `scheduled_*` bằng giờ địa phương diễn giải theo cột `timezone`
 * của chính dòng đó (core_liveclass_sessions.md § Canonical Time Convention).
 *
 * Các test dưới đây cố tình đặt Session ở múi giờ khác `config('app.timezone')`
 * để chứng minh policy so sánh instant đã chuẩn hóa chứ không so chuỗi thô.
 * Với múi giờ mặc định, so chuỗi và so instant cho cùng kết quả — đó là lý do
 * bộ test hiện có không phát hiện được lớp lỗi này.
 */
class LiveClassSessionPolicyTest extends TestCase
{
    private LiveClassSessionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        $this->policy = new LiveClassSessionPolicy;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_can_edit_locks_a_session_that_already_started_in_its_own_timezone(): void
    {
        // Tokyo 09:00 = 00:00 UTC. "Bây giờ" là 08:00 giờ VN = 01:00 UTC,
        // tức buổi học đã bắt đầu được một tiếng trong thực tế.
        // So chuỗi thô sẽ thấy '09:00:00' > '08:00:00' và kết luận sai là chưa bắt đầu.
        $session = $this->sessionRow('2026-08-03 09:00:00', '2026-08-03 11:00:00', 'Asia/Tokyo');
        $now = Carbon::parse('2026-08-03 08:00:00', 'Asia/Ho_Chi_Minh');

        $this->assertFalse(
            $this->policy->canEdit($session, false, $now),
            'Session đã bắt đầu theo instant thật thì không được phép sửa.'
        );
    }

    public function test_can_edit_keeps_a_session_editable_until_its_real_start_instant(): void
    {
        // Tokyo 09:00 = 00:00 UTC. "Bây giờ" là 06:00 giờ VN = 23:00 UTC ngày trước,
        // buổi học chưa bắt đầu.
        $session = $this->sessionRow('2026-08-03 09:00:00', '2026-08-03 11:00:00', 'Asia/Tokyo');
        $now = Carbon::parse('2026-08-03 06:00:00', 'Asia/Ho_Chi_Minh');

        $this->assertTrue($this->policy->canEdit($session, false, $now));
    }

    public function test_can_record_attendance_follows_the_real_start_instant(): void
    {
        $session = $this->sessionRow('2026-08-03 09:00:00', '2026-08-03 11:00:00', 'Asia/Tokyo');

        $this->assertTrue(
            $this->policy->canRecordAttendance($session, Carbon::parse('2026-08-03 08:00:00', 'Asia/Ho_Chi_Minh')),
            'Buổi học đã bắt đầu theo instant thật thì được điểm danh.'
        );
        $this->assertFalse(
            $this->policy->canRecordAttendance($session, Carbon::parse('2026-08-03 06:00:00', 'Asia/Ho_Chi_Minh')),
            'Buổi học chưa bắt đầu theo instant thật thì chưa được điểm danh.'
        );
    }

    public function test_can_create_recording_follows_the_real_end_instant(): void
    {
        // Tokyo 11:00 = 02:00 UTC.
        $session = $this->sessionRow('2026-08-03 09:00:00', '2026-08-03 11:00:00', 'Asia/Tokyo');

        $this->assertFalse(
            $this->policy->canCreateRecording($session, Carbon::parse('2026-08-03 08:00:00', 'Asia/Ho_Chi_Minh')),
            'Chưa kết thúc theo instant thật (01:00 UTC < 02:00 UTC) thì chưa được tạo bản ghi.'
        );
        $this->assertTrue(
            $this->policy->canCreateRecording($session, Carbon::parse('2026-08-03 10:00:00', 'Asia/Ho_Chi_Minh')),
            'Đã kết thúc theo instant thật (03:00 UTC > 02:00 UTC) thì được tạo bản ghi.'
        );
    }

    public function test_default_timezone_behaviour_is_unchanged(): void
    {
        $session = $this->sessionRow('2026-08-03 19:00:00', '2026-08-03 21:00:00', 'Asia/Ho_Chi_Minh');

        $this->assertTrue($this->policy->canEdit($session, false, Carbon::parse('2026-08-03 18:00:00')));
        $this->assertFalse($this->policy->canEdit($session, false, Carbon::parse('2026-08-03 20:00:00')));
        $this->assertFalse($this->policy->canEdit($session, true, Carbon::parse('2026-08-03 18:00:00')));
        $this->assertTrue($this->policy->canRecordAttendance($session, Carbon::parse('2026-08-03 19:30:00')));
        $this->assertTrue($this->policy->canCreateRecording($session, Carbon::parse('2026-08-03 21:30:00')));
        $this->assertFalse($this->policy->canCreateRecording($session, Carbon::parse('2026-08-03 20:30:00')));
    }

    public function test_a_started_session_is_not_editable_even_without_actual_start(): void
    {
        $session = $this->sessionRow('2026-08-03 09:00:00', '2026-08-03 11:00:00', 'Asia/Tokyo');
        $session->actual_start_at = '2026-08-03 09:05:00';

        $this->assertFalse(
            $this->policy->canEdit($session, false, Carbon::parse('2026-08-03 06:00:00', 'Asia/Ho_Chi_Minh'))
        );
    }

    private function sessionRow(string $start, string $end, string $timezone, string $status = 'scheduled'): object
    {
        return (object) [
            'id' => 1,
            'status' => $status,
            'timezone' => $timezone,
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $end,
            'actual_start_at' => null,
            'actual_end_at' => null,
        ];
    }
}
