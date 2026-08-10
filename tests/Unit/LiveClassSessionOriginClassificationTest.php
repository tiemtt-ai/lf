<?php

namespace Tests\Unit;

use App\Services\LiveClassSessionOriginService;
use Tests\TestCase;

/**
 * ADR-0002 § Schedule-To-Session Origin Amendment định nghĩa bốn nhãn quan hệ và
 * cấm suy ra chúng từ Schedule hiện tại (vốn có thể đã thay đổi) hoặc từ sự
 * trùng khớp thời gian. Các test dưới đây khóa đúng hai điều đó.
 */
class LiveClassSessionOriginClassificationTest extends TestCase
{
    private LiveClassSessionOriginService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['liveclass.schedule_origin_rollout_at' => '2026-08-05 00:00:00']);
        $this->service = new LiveClassSessionOriginService;
    }

    public function test_origin_backed_session_matching_its_snapshot_is_on_schedule(): void
    {
        $this->assertSame('on_schedule', $this->service->classify($this->sessionRow()));
    }

    public function test_origin_backed_session_with_a_moved_interval_is_rescheduled(): void
    {
        $this->assertSame('rescheduled', $this->service->classify($this->sessionRow([
            'scheduled_start_at' => '2026-08-10 20:00:00',
            'scheduled_end_at' => '2026-08-10 22:00:00',
        ])));
    }

    public function test_classification_compares_normalized_instants_not_wall_clock(): void
    {
        // Cùng một instant, biểu diễn ở hai múi giờ khác nhau: 19:00 Asia/Ho_Chi_Minh
        // = 21:00 Asia/Tokyo = 12:00 UTC. Session vẫn đúng lịch.
        $this->assertSame('on_schedule', $this->service->classify($this->sessionRow([
            'timezone' => 'Asia/Tokyo',
            'scheduled_start_at' => '2026-08-10 21:00:00',
            'scheduled_end_at' => '2026-08-10 23:00:00',
        ])));
    }

    public function test_manual_session_created_after_the_cutover_is_off_schedule(): void
    {
        $this->assertSame('off_schedule', $this->service->classify($this->sessionRow([
            'schedule_origin_id' => null,
            'created_at' => '2026-08-06 08:00:00',
        ])));
    }

    public function test_legacy_session_created_before_the_cutover_is_source_unknown(): void
    {
        $this->assertSame('source_unknown', $this->service->classify($this->sessionRow([
            'schedule_origin_id' => null,
            'created_at' => '2026-08-04 23:59:59',
        ])));
    }

    public function test_the_cutover_instant_itself_counts_as_manual(): void
    {
        $this->assertSame('off_schedule', $this->service->classify($this->sessionRow([
            'schedule_origin_id' => null,
            'created_at' => '2026-08-05 00:00:00',
        ])));
    }

    private function sessionRow(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 1,
            'schedule_origin_id' => 77,
            'timezone' => 'Asia/Ho_Chi_Minh',
            'scheduled_start_at' => '2026-08-10 19:00:00',
            'scheduled_end_at' => '2026-08-10 21:00:00',
            // 19:00 Asia/Ho_Chi_Minh = 12:00 UTC
            'source_start_at' => '2026-08-10 12:00:00',
            'source_end_at' => '2026-08-10 14:00:00',
            'created_at' => '2026-08-06 10:00:00',
        ], $overrides);
    }
}
