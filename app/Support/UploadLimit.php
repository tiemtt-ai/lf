<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

class UploadLimit
{
    /** Policy: a video may be up to 1.5 GB, any other media up to 500 MB. */
    public const MAX_VIDEO_KILOBYTES = 1572864;

    public const MAX_OTHER_KILOBYTES = 512000;

    public static function effectiveKilobytes(
        ?int $productLimitKilobytes = null,
        ?string $uploadMaxFilesize = null,
        ?string $postMaxSize = null
    ): int {
        $limits = [];
        $productLimitKilobytes ??= min(
            (int) config('media.max_upload_kilobytes', self::MAX_OTHER_KILOBYTES),
            self::MAX_OTHER_KILOBYTES
        );

        if ($productLimitKilobytes > 0) {
            $limits[] = $productLimitKilobytes;
        }

        foreach ([
            $uploadMaxFilesize ?? ini_get('upload_max_filesize'),
            $postMaxSize ?? ini_get('post_max_size'),
        ] as $iniValue) {
            $kilobytes = self::parseIniSizeToKilobytes($iniValue);

            if ($kilobytes !== null && $kilobytes > 0) {
                $limits[] = $kilobytes;
            }
        }

        return $limits === [] ? 0 : min($limits);
    }

    /** The limit for a video upload (still lowered by PHP's own limits). */
    public static function effectiveVideoKilobytes(): int
    {
        return self::effectiveKilobytes(min(
            (int) config('media.video_max_upload_kilobytes', self::MAX_VIDEO_KILOBYTES),
            self::MAX_VIDEO_KILOBYTES
        ));
    }

    /** The limit that applies to a file whose type is only known once it arrives (e.g. the media library). */
    public static function effectiveKilobytesForFile(UploadedFile $file): int
    {
        $isVideo = str_starts_with((string) $file->getMimeType(), 'video/')
            || in_array(strtolower($file->getClientOriginalExtension()), ['mp4', 'webm', 'mov', 'avi', 'm4v', 'mkv'], true);

        return $isVideo ? self::effectiveVideoKilobytes() : self::effectiveKilobytes();
    }

    public static function parseIniSizeToKilobytes(string|false|null $value): ?int
    {
        if ($value === false || $value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmgtp]?)(?:b)?$/i', $value, $matches)) {
            return null;
        }

        $amount = (float) $matches[1];
        $unit = strtolower($matches[2] ?? '');

        if ($amount <= 0) {
            return null;
        }

        $bytes = match ($unit) {
            'k' => $amount * 1024,
            'm' => $amount * 1024 * 1024,
            'g' => $amount * 1024 * 1024 * 1024,
            't' => $amount * 1024 * 1024 * 1024 * 1024,
            'p' => $amount * 1024 * 1024 * 1024 * 1024 * 1024,
            default => $amount,
        };

        return (int) ceil($bytes / 1024);
    }

    public static function humanReadable(?int $kilobytes = null): string
    {
        $kilobytes ??= self::effectiveKilobytes();

        if ($kilobytes >= 1024 * 1024) {
            return self::formatNumber($kilobytes / (1024 * 1024)).' GB';
        }

        if ($kilobytes >= 1024) {
            return self::formatNumber($kilobytes / 1024).' MB';
        }

        return self::formatNumber($kilobytes).' KB';
    }

    public static function formatList(array $formats): string
    {
        return collect($formats)
            ->map(fn ($format): string => trim((string) $format))
            ->filter()
            ->map(fn (string $format): string => strtoupper($format))
            ->implode(', ');
    }

    private static function formatNumber(float|int $value): string
    {
        $formatted = number_format((float) $value, 1, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
