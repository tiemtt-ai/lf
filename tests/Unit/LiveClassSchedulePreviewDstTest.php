<?php

namespace Tests\Unit;

use App\Services\LiveClassSchedulePreviewService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * `LF-LiveClass-Schedule-Session-Origin-Architecture-Review.md` § Required
 * Implementation Test Matrix, item 3 requires preview to be "timezone/DST-correct".
 *
 * Every other Schedule test uses `Asia/Ho_Chi_Minh`, which has no DST and so
 * cannot demonstrate the property at all. These use `Europe/Berlin`, where the
 * clock jumps forward on 2026-03-29 and back on 2026-10-25.
 */
class LiveClassSchedulePreviewDstTest extends TestCase
{
    private LiveClassSchedulePreviewService $preview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preview = new LiveClassSchedulePreviewService;
    }

    public function test_local_wall_clock_time_is_preserved_across_the_spring_forward_boundary(): void
    {
        // Sundays either side of the 2026-03-29 spring-forward transition.
        $occurrences = $this->preview->calculate(
            '2026-03-22', '2026-04-05', 'Europe/Berlin',
            [['id' => 1, 'weekday' => 7, 'start_time' => '10:00', 'end_time' => '12:00', 'sort_order' => 1]]
        );

        $this->assertSame(['2026-03-22', '2026-03-29', '2026-04-05'], $occurrences->pluck('date')->all());

        // The rule is "10:00 local", so the wall clock stays 10:00 on every
        // date even though the UTC offset changes from +01:00 to +02:00.
        foreach ($occurrences as $occurrence) {
            $this->assertSame('10:00', $occurrence['start_time']);
        }
        $this->assertSame(
            ['2026-03-22T10:00:00+01:00', '2026-03-29T10:00:00+02:00', '2026-04-05T10:00:00+02:00'],
            $occurrences->pluck('starts_at')->all()
        );

        // Which means consecutive occurrences are 167 hours apart, not 168:
        // the absolute interval really does shrink by the DST hour.
        $first = CarbonImmutable::parse($occurrences[0]['starts_at']);
        $second = CarbonImmutable::parse($occurrences[1]['starts_at']);
        $this->assertSame(167, (int) $first->diffInHours($second));
    }

    public function test_local_wall_clock_time_is_preserved_across_the_autumn_back_boundary(): void
    {
        $occurrences = $this->preview->calculate(
            '2026-10-18', '2026-11-01', 'Europe/Berlin',
            [['id' => 1, 'weekday' => 7, 'start_time' => '10:00', 'end_time' => '12:00', 'sort_order' => 1]]
        );

        $this->assertSame(
            ['2026-10-18T10:00:00+02:00', '2026-10-25T10:00:00+01:00', '2026-11-01T10:00:00+01:00'],
            $occurrences->pluck('starts_at')->all()
        );

        $first = CarbonImmutable::parse($occurrences[0]['starts_at']);
        $second = CarbonImmutable::parse($occurrences[1]['starts_at']);
        $this->assertSame(169, (int) $first->diffInHours($second));
    }

    public function test_a_slot_inside_the_skipped_hour_still_yields_one_occurrence(): void
    {
        // 02:30 does not exist on 2026-03-29 in Europe/Berlin. Preview must not
        // drop or duplicate the date; PHP normalises into the post-jump offset.
        $occurrences = $this->preview->calculate(
            '2026-03-29', '2026-03-29', 'Europe/Berlin',
            [['id' => 1, 'weekday' => 7, 'start_time' => '02:30', 'end_time' => '03:30', 'sort_order' => 1]]
        );

        $this->assertCount(1, $occurrences);
        $this->assertSame('2026-03-29', $occurrences[0]['date']);
        $this->assertSame('+02:00', CarbonImmutable::parse($occurrences[0]['starts_at'])->format('P'));
    }

    public function test_derived_status_uses_the_schedule_timezone_not_the_server_timezone(): void
    {
        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        // 2026-08-02 00:30 in Ho Chi Minh (+07:00) is still 2026-08-01 in Berlin.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-02 00:30:00', 'Asia/Ho_Chi_Minh'));

        $this->assertSame(
            'upcoming',
            $this->preview->derivedStatus('2026-08-02', '2026-08-31', 'Europe/Berlin'),
            'Trạng thái phải suy ra theo timezone của Schedule, không theo giờ server.'
        );
        $this->assertSame(
            'current',
            $this->preview->derivedStatus('2026-08-02', '2026-08-31', 'Asia/Ho_Chi_Minh')
        );

        CarbonImmutable::setTestNow();
    }
}
