<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** What the learner reads about their own entry to a Session open now (MK 3.1). */
final class LearnerAttendanceEntry
{
    /** The recorded result, or null while nothing is recorded yet. `$row` is the learner's attendance row of the Session. */
    public static function label(?object $row): ?string
    {
        if (! $row || ($row->joined_at === null && $row->status === 'registered')) {
            return null;
        }
        // The entry is kept while the ticked content is not done yet (ADR-0022 Amendment 1.3, Đợt 3).
        if ($row->status === 'registered') {
            return __('lf.LF_liveclass_attendance_entry_pending', ['time' => Carbon::parse($row->joined_at)->format('H:i')]);
        }
        $status = __('lf.LF_course_cohort_attendance_status_'.$row->status);
        if ($row->joined_at === null || ! $row->attendance_mode) {
            return __('lf.LF_liveclass_attendance_entry_result_manual', ['status' => $status]);
        }

        return __('lf.LF_liveclass_attendance_entry_result', [
            'status' => $status,
            'time' => Carbon::parse($row->joined_at)->format('H:i'),
            'mode' => __('lf.LF_liveclass_attendance_entry_mode_'.$row->attendance_mode),
        ]);
    }
}
