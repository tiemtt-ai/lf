<?php

namespace Tests\Unit;

use App\Services\TranscriptVttSerializer;
use RuntimeException;
use Tests\TestCase;

/**
 * LF-Media-Processing-Contract Amendment Record 2.21 § 6 — VTT Phase 1.
 */
class TranscriptVttSerializerTest extends TestCase
{
    private TranscriptVttSerializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serializer = new TranscriptVttSerializer;
    }

    public function test_one_segment_becomes_exactly_one_cue_in_order(): void
    {
        $vtt = $this->serializer->serialize([
            ['locator_value' => '0-10000', 'text' => 'mot'],
            ['locator_value' => '11000-19000', 'text' => 'hai'],
            ['locator_value' => '19000-27000', 'text' => 'ba'],
        ]);

        $this->assertSame("WEBVTT\n\n"
            ."00:00:00.000 --> 00:00:10.000\nmot\n\n"
            ."00:00:11.000 --> 00:00:19.000\nhai\n\n"
            ."00:00:19.000 --> 00:00:27.000\nba\n\n", $vtt);
        $this->assertSame(3, substr_count($vtt, ' --> '));
    }

    public function test_the_file_starts_with_webvtt_and_has_no_bom(): void
    {
        $vtt = $this->serializer->serialize([['locator_value' => '0-1000', 'text' => 'x']]);

        $this->assertStringStartsWith('WEBVTT', $vtt);
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $vtt);
        $this->assertStringNotContainsString("\r", $vtt, 'Newline phai la LF.');
    }

    /**
     * Timestamp phai dung tu millisecond NGUYEN. Di qua float thi `19.000` giay
     * bieu dien nhi phan khong chinh xac, va mot cue lech mot phan nghin giay so
     * voi transcript la citation sai ma khong ai doc ra.
     */
    public function test_timestamps_are_built_from_integer_milliseconds(): void
    {
        $vtt = $this->serializer->serialize([
            ['locator_value' => '3661001-7322002', 'text' => 'x'],
        ]);

        $this->assertStringContainsString('01:01:01.001 --> 02:02:02.002', $vtt);
    }

    public function test_an_hour_count_above_ninety_nine_is_not_truncated(): void
    {
        $vtt = $this->serializer->serialize([
            ['locator_value' => '360000000-360001000', 'text' => 'x'],
        ]);

        $this->assertStringContainsString('100:00:00.000', $vtt);
    }

    public function test_touching_cues_are_valid_and_overlap_is_refused(): void
    {
        $touching = $this->serializer->serialize([
            ['locator_value' => '0-1000', 'text' => 'a'],
            ['locator_value' => '1000-2000', 'text' => 'b'],
        ]);
        $this->assertStringContainsString("00:00:01.000 --> 00:00:02.000\nb", $touching);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caption_invalid');
        $this->serializer->serialize([
            ['locator_value' => '0-2000', 'text' => 'a'],
            ['locator_value' => '1000-3000', 'text' => 'b'],
        ]);
    }

    /**
     * Mot dong text chua `-->` se duoc parser doc thanh cue timing moi: text cua
     * hoc lieu bien thanh cau truc file, va phan sau bi gan sai moc thoi gian.
     */
    public function test_an_arrow_token_in_the_text_fails_the_whole_revision(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caption_invalid');
        $this->serializer->serialize([
            ['locator_value' => '0-1000', 'text' => "binh thuong\n00:00:05.000 --> 00:00:06.000"],
        ]);
    }

    public function test_a_control_character_fails_the_whole_revision(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caption_invalid');
        $this->serializer->serialize([
            ['locator_value' => '0-1000', 'text' => "co ky tu\x00null"],
        ]);
    }

    /** Dong trong ket thuc cue som, nen phan con lai se bi doc sai cau truc. */
    public function test_a_blank_line_inside_the_text_fails_the_whole_revision(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caption_invalid');
        $this->serializer->serialize([
            ['locator_value' => '0-1000', 'text' => "dong mot\n\ndong hai"],
        ]);
    }

    public function test_windows_and_mac_line_endings_are_normalised_not_rejected(): void
    {
        $vtt = $this->serializer->serialize([
            ['locator_value' => '0-1000', 'text' => "dong mot\r\ndong hai"],
        ]);

        $this->assertStringContainsString("dong mot\ndong hai", $vtt);
        $this->assertStringNotContainsString("\r", $vtt);
    }

    public function test_the_cue_budget_is_enforced(): void
    {
        config(['media.processing.caption.max_cues' => 2]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caption_too_large');
        $this->serializer->serialize([
            ['locator_value' => '0-1000', 'text' => 'a'],
            ['locator_value' => '1000-2000', 'text' => 'b'],
            ['locator_value' => '2000-3000', 'text' => 'c'],
        ]);
    }

    public function test_the_byte_budget_is_enforced(): void
    {
        config(['media.processing.caption.max_bytes' => 64]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caption_too_large');
        $this->serializer->serialize([
            ['locator_value' => '0-1000', 'text' => str_repeat('x', 200)],
        ]);
    }

    public function test_the_same_input_always_produces_the_same_bytes(): void
    {
        $segments = [
            ['locator_value' => '0-10000', 'text' => 'Kiến trúc Trợ lý AI'],
            ['locator_value' => '10000-20000', 'text' => '다음을 듣고 물음에 답하십시오'],
        ];

        $this->assertSame(
            hash('sha256', $this->serializer->serialize($segments)),
            hash('sha256', $this->serializer->serialize($segments)),
        );
    }

    public function test_an_empty_transcript_produces_no_caption(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caption_invalid');
        $this->serializer->serialize([]);
    }
}
