<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the rows of a list screen into an .xlsx download. Callers build the rows from the same tenant-scoped
 * query as the list they export; this class only formats and sends them.
 */
final class XlsxDownload
{
    /** A list export stops here; a larger tenant narrows the filters first. */
    public const MAX_ROWS = 5000;

    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|float|null>>  $rows
     */
    public static function response(array $header, iterable $rows, string $fileBase, string $sheetName): Response
    {
        $sheet = [$header];
        foreach ($rows as $row) {
            $sheet[] = array_map(fn ($value) => $value === null ? '' : (string) $value, $row);
        }

        $filename = (Str::slug($fileBase) ?: 'export').'-'.now()->format('Ymd-His').'.xlsx';

        return response(SimpleXlsx::write($sheet, self::sheetName($sheetName)), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Date and time the way the lists show them; empty when unset. */
    public static function dateTime(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('d/m/Y H:i') : '';
    }

    public static function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('d/m/Y') : '';
    }

    /** 12.50 → 12.5, 10.00 → 10; empty when unset. */
    public static function number(mixed $value): string
    {
        return $value === null ? '' : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /** Excel allows 31 characters and none of : \ / ? * [ ]. */
    private static function sheetName(string $name): string
    {
        $name = trim(preg_replace('/[:\\\\\/?*\[\]]+/u', ' ', $name) ?? '');

        return mb_substr($name !== '' ? $name : 'Sheet1', 0, 31);
    }
}
