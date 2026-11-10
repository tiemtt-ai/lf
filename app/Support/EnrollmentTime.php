<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * An admin-typed enrollment moment. Enrollment windows are stored as app-timezone wall
 * clock and compared with now() in that zone, so a value sent with its own offset is
 * moved into the app timezone before any window is projected from it.
 */
final class EnrollmentTime
{
    /** Bounds that keep every projected window inside DATETIME and away from typos. */
    public const RULES = ['date', 'after_or_equal:2000-01-01', 'before:2100-01-01'];

    public static function parse(string $value): Carbon
    {
        return Carbon::parse($value, config('app.timezone'))->setTimezone(config('app.timezone'));
    }
}
