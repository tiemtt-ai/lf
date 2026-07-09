<?php

namespace App\Support;

class UploadLimit
{
    public static function effectiveKilobytes(
        ?int $productLimitKilobytes = null,
        ?string $uploadMaxFilesize = null,
        ?string $postMaxSize = null
    ): int {
        $limits = [];
        $productLimitKilobytes ??= (int) config(
            'media.max_upload_kilobytes',
            102400
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
