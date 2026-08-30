<?php

namespace App\Services;

use RuntimeException;

/**
 * Chuyen transcript segment thanh mot file WebVTT.
 *
 * LF-Media-Processing-Contract Amendment Record 2.21 § 6, Owner freeze 2026-08-30.
 *
 * Deterministic va thuan tuy: khong doc DB, khong ghi storage, khong chay model.
 * Cung mot dau vao luon cho cung mot chuoi byte — do la dieu kien de caption co
 * the tai lap tu mot transcript revision cu the.
 */
class TranscriptVttSerializer
{
    /**
     * @param  array<int, array{locator_value: string, text: string}>  $segments
     */
    public function serialize(array $segments): string
    {
        $this->assertWithinCueBudget($segments);

        $body = '';
        $previousEnd = null;
        foreach ($segments as $segment) {
            [$start, $end] = $this->timespan((string) ($segment['locator_value'] ?? ''));

            // Cung luat da cuong che o tang persist transcript. Kiem lai o day vi
            // serializer nhan mang segment tu caller, khong phai tu bang.
            if ($start >= $end || ($previousEnd !== null && $start < $previousEnd)) {
                throw new RuntimeException('caption_invalid');
            }
            $previousEnd = $end;

            $body .= $this->timestamp($start).' --> '.$this->timestamp($end)."\n"
                .$this->cueText((string) ($segment['text'] ?? ''))."\n\n";
        }

        // Khong BOM, newline LF. `WEBVTT` phai la byte dau tien cua file.
        $vtt = "WEBVTT\n\n".$body;
        $this->assertWithinByteBudget($vtt);

        return $vtt;
    }

    /**
     * `HH:MM:SS.mmm` dung tu millisecond NGUYEN.
     *
     * Khong di qua float: `19.000` giay bieu dien nhi phan khong chinh xac, va mot
     * cue lech mot phan nghin giay so voi transcript la mot citation sai ma khong
     * ai doc ra.
     *
     * Gio khong ep hai chu so: 100 gio van phai ghi dung thay vi tran ve `00`.
     */
    private function timestamp(int $milliseconds): string
    {
        return sprintf(
            '%02d:%02d:%02d.%03d',
            intdiv($milliseconds, 3600000),
            intdiv($milliseconds % 3600000, 60000),
            intdiv($milliseconds % 60000, 1000),
            $milliseconds % 1000,
        );
    }

    /** @return array{0: int, 1: int} */
    private function timespan(string $locatorValue): array
    {
        if (preg_match('/^(0|[1-9][0-9]*)-(0|[1-9][0-9]*)$/D', $locatorValue, $matches) !== 1) {
            throw new RuntimeException('caption_invalid');
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * Text cua cue: giu nguyen noi dung, chi chuan hoa line ending.
     *
     * Hai truong hop FAIL CA REVISION thay vi sinh VTT mo ho:
     *
     * - Mot dong chua `-->`: parser se doc dong do thanh mot cue timing moi. Text
     *   cua hoc lieu bien thanh cau truc file, va phan sau bi gan sai moc thoi
     *   gian. Escape se lam sai lech noi dung; bo qua se sinh file mo ho.
     * - Ky tu dieu khien ngoai TAB: NUL hoac ESC trong mot file text lam parser
     *   moi noi hanh xu mot kieu.
     *
     * Dong trong giua text cung ket thuc cue som, nen cung bi tu choi.
     */
    private function cueText(string $text): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $normalized) === 1) {
            throw new RuntimeException('caption_invalid');
        }
        foreach (explode("\n", $normalized) as $line) {
            if (str_contains($line, '-->')) {
                throw new RuntimeException('caption_invalid');
            }
        }
        if (str_contains($normalized, "\n\n") || trim($normalized) === '') {
            throw new RuntimeException('caption_invalid');
        }

        return $normalized;
    }

    /** @param array<int, mixed> $segments */
    private function assertWithinCueBudget(array $segments): void
    {
        if ($segments === []) {
            throw new RuntimeException('caption_invalid');
        }
        if (count($segments) > (int) config('media.processing.caption.max_cues', 10000)) {
            throw new RuntimeException('caption_too_large');
        }
    }

    private function assertWithinByteBudget(string $vtt): void
    {
        if (strlen($vtt) > (int) config('media.processing.caption.max_bytes', 1048576)) {
            throw new RuntimeException('caption_too_large');
        }
    }
}
