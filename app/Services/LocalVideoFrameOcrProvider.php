<?php

namespace App\Services;

use App\Contracts\MediaProcessingProvider;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LocalVideoFrameOcrProvider implements MediaProcessingProvider
{
    public function __construct(private readonly DocumentProcessRunner $runner) {}

    public function process(object $mediaFile, object $job): array
    {
        if ($job->job_type !== 'frame_ocr' || $mediaFile->file_type !== 'video') {
            throw new RuntimeException('unsupported_source');
        }
        if (! (bool) config('media.processing.frame_ocr.enabled', false)) {
            throw new RuntimeException('frame_ocr_disabled');
        }
        if (! in_array((string) $mediaFile->mime_type, (array) config('media.processing.frame_ocr.mime_types'), true)) {
            throw new RuntimeException('unsupported_source');
        }
        if ($mediaFile->duration_seconds === null || (int) $mediaFile->duration_seconds <= 0) {
            throw new RuntimeException('corrupt_source');
        }

        $locales = app(SpeechLanguageProfile::class)->fromProfile((string) $job->output_profile);
        $packs = collect($locales)->map(fn (string $locale) => config("media.processing.frame_ocr.languages.$locale"))
            ->filter(fn ($pack) => is_string($pack) && $pack !== '')->unique()->sort()->values();
        if ($packs->count() !== count($locales)) {
            throw new RuntimeException('locale_unavailable');
        }

        $ffmpeg = (string) config('media.processing.frame_ocr.ffmpeg_binary');
        $tesseract = (string) config('media.processing.frame_ocr.tesseract_binary');
        if (! is_executable($ffmpeg) || ! is_executable($tesseract)) {
            throw new RuntimeException('provider_unavailable');
        }

        $interval = max(1, (int) config('media.processing.frame_ocr.interval_seconds', 2));
        $scale = (string) config('media.processing.frame_ocr.scale', '1280:-2');
        $maximum = (int) config('media.processing.frame_ocr.max_frames', 3000);
        $expected = (int) ceil((int) $mediaFile->duration_seconds / $interval);
        if ($expected > $maximum) {
            throw new RuntimeException('frame_ocr_limit_exceeded');
        }

        $directory = sys_get_temp_dir().'/lf-frame-ocr-'.bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('provider_unavailable');
        }

        try {
            $source = $directory.'/source.'.preg_replace('/[^a-z0-9]/', '', strtolower((string) $mediaFile->extension));
            $this->copySource($mediaFile, $source);
            $this->runner->run([
                $ffmpeg, '-hide_banner', '-loglevel', 'error', '-i', $source,
                '-vf', "fps=1/$interval,scale=$scale", '-vsync', 'vfr',
                $directory.'/frame-%06d.png',
            ], (int) config('media.processing.frame_ocr.timeout_seconds', 1800));

            $frames = glob($directory.'/frame-*.png') ?: [];
            sort($frames, SORT_STRING);
            if (count($frames) > $maximum) {
                throw new RuntimeException('frame_ocr_limit_exceeded');
            }

            $units = [];
            foreach ($frames as $index => $frame) {
                [$width, $height] = getimagesize($frame) ?: [0, 0];
                if ($width <= 0 || $height <= 0) {
                    throw new RuntimeException('frame_ocr_invalid');
                }
                $tsv = $this->runner->run([
                    $tesseract, $frame, 'stdout', '-l', $packs->implode('+'), '--psm', '11', 'tsv',
                ], (int) config('media.processing.frame_ocr.ocr_timeout_seconds', 120));
                $start = $index * $interval * 1000;
                $end = min((int) $mediaFile->duration_seconds * 1000, $start + $interval * 1000);
                array_push($units, ...$this->parseTsv($tsv, $start, $end, $width, $height, $locales));
            }

            return ['units' => $this->mergeStableUnits($units), 'usage' => ['unit_type' => 'frame', 'units' => count($frames)]];
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function parseTsv(string $tsv, int $start, int $end, int $width, int $height, array $locales): array
    {
        $lines = [];
        foreach (array_slice(preg_split('/\R/u', $tsv) ?: [], 1) as $row) {
            $columns = str_getcsv($row, "\t");
            if (count($columns) < 12 || (int) $columns[0] !== 5 || trim($columns[11]) === '') {
                continue;
            }
            $key = implode(':', array_slice($columns, 1, 4));
            $lines[$key][] = $columns;
        }

        $units = [];
        foreach (array_values($lines) as $ordinal => $words) {
            $text = trim(implode(' ', array_column($words, 11)));
            if ($text === '' || ((bool) config('media.processing.frame_ocr.require_alphanumeric', true)
                && preg_match('/[\p{L}\p{N}]/u', $text) !== 1)) {
                continue;
            }
            $left = min(array_map(fn ($w) => (int) $w[6], $words));
            $top = min(array_map(fn ($w) => (int) $w[7], $words));
            $right = max(array_map(fn ($w) => (int) $w[6] + (int) $w[8], $words));
            $bottom = max(array_map(fn ($w) => (int) $w[7] + (int) $w[9], $words));
            $confidence = collect($words)->pluck(10)->map(fn ($value) => (float) $value)->filter(fn ($value) => $value >= 0)->avg();
            if ($confidence === null || $confidence < (float) config('media.processing.frame_ocr.min_confidence', 50)) {
                continue;
            }
            [$script, $detected] = $this->languageEvidence($text, $locales);
            $x = round($left / $width, 6);
            $y = round($top / $height, 6);
            $bboxWidth = min(round(($right - $left) / $width, 6), round(1 - $x, 6));
            $bboxHeight = min(round(($bottom - $top) / $height, 6), round(1 - $y, 6));
            // A box whose positive pixel extent collapses at DECIMAL(9,6)
            // precision is not persistable evidence. Skip that line instead
            // of failing the complete immutable revision at the DB CHECK.
            if ($bboxWidth <= 0 || $bboxHeight <= 0) {
                continue;
            }
            $units[] = [
                'locator_type' => 'timespan', 'locator_value' => "$start-$end",
                'reading_order' => $ordinal + 1, 'text' => $text,
                'script' => $script, 'detected_locale' => $detected,
                'confidence_score' => $confidence === null ? null : round($confidence, 2),
                // MariaDB stores DECIMAL(9,6). Round position first, then fit
                // width/height into the remaining edge so independent rounding
                // can never produce x+width=1.000001 and reject the revision.
                'bbox' => ['x' => $x, 'y' => $y, 'width' => $bboxWidth, 'height' => $bboxHeight],
                'frame_width' => $width, 'frame_height' => $height,
            ];
        }

        return $units;
    }

    /** @param array<int, array<string, mixed>> $units */
    private function mergeStableUnits(array $units): array
    {
        $merged = [];
        $active = [];
        foreach ($units as $unit) {
            [$start, $end] = array_map('intval', explode('-', $unit['locator_value']));
            $bbox = $unit['bbox'];
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', trim($unit['text'])))
                .'|'.implode(':', array_map(fn ($value) => number_format((float) $value, 2, '.', ''), $bbox));
            $index = $active[$key] ?? null;
            if ($index !== null) {
                [, $previousEnd] = array_map('intval', explode('-', $merged[$index]['locator_value']));
                if ($previousEnd === $start) {
                    $merged[$index]['locator_value'] = explode('-', $merged[$index]['locator_value'])[0]."-$end";
                    $merged[$index]['confidence_score'] = round(max(
                        (float) $merged[$index]['confidence_score'], (float) $unit['confidence_score']
                    ), 2);

                    continue;
                }
            }
            $active[$key] = array_key_last($merged) === null ? 0 : array_key_last($merged) + 1;
            $merged[] = $unit;
        }

        return $merged;
    }

    private function languageEvidence(string $text, array $locales): array
    {
        if (preg_match('/[\x{1100}-\x{11FF}\x{3130}-\x{318F}\x{AC00}-\x{D7A3}]/u', $text)) {
            return ['Hang', in_array('ko', $locales, true) ? 'ko' : null];
        }
        if (preg_match('/\p{Latin}/u', $text)) {
            $latin = array_values(array_intersect($locales, ['vi', 'en']));
            if (count($latin) === 1) {
                return ['Latn', $latin[0]];
            }
            if (in_array('vi', $latin, true) && preg_match('/[ăâđêôơưáàảãạấầẩẫậắằẳẵặéèẻẽẹếềểễệíìỉĩịóòỏõọốồổỗộớờởỡợúùủũụứừửữựýỳỷỹỵ]/iu', $text)) {
                return ['Latn', 'vi'];
            }

            return ['Latn', null];
        }

        return [null, null];
    }

    private function copySource(object $mediaFile, string $destination): void
    {
        $source = Storage::disk($mediaFile->storage_disk)->readStream($mediaFile->storage_key);
        $target = fopen($destination, 'wb');
        if (! is_resource($source) || $target === false) {
            throw new RuntimeException('source_unavailable');
        }
        try {
            if (stream_copy_to_stream($source, $target) === false) {
                throw new RuntimeException('source_unavailable');
            }
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    private function removeDirectory(string $directory): void
    {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
