<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * A date-based Lesson unlock time is stored in UTC (LF-Core-Course: `date_based`
 * compares the frozen UTC timestamp). The admin types and reads it in the app
 * timezone, so the form value is converted on the way in and out.
 */
final class LessonUnlockTime
{
    /** A `datetime-local` value typed in the app timezone, as the stored UTC string. */
    public static function fromLocalInput(string $value): string
    {
        return Carbon::parse($value, config('app.timezone'))->utc()->format('Y-m-d H:i:s');
    }

    /** A stored UTC value, shown in the app timezone. */
    public static function toLocal(string $value, string $format = 'Y-m-d\TH:i'): string
    {
        return Carbon::parse($value, 'UTC')->setTimezone(config('app.timezone'))->format($format);
    }
}
