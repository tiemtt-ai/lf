<?php

return [
    // Immutable deployment cutover used only to distinguish legacy no-origin
    // Sessions from Sessions created manually after Origin support shipped.
    'schedule_origin_rollout_at' => env('LIVECLASS_SCHEDULE_ORIGIN_ROLLOUT_AT', '2026-08-05 00:00:00'),

    // The flags gate the server-side write endpoints and the Cohort tab state
    // together, so closing or re-opening one is a single configuration change
    // rather than a code revert. Existing rows are never deleted — only made
    // unreachable while a flag is off.
    //
    // Attendance was rebuilt (ADR-0002 § Attendance Check-In Amendment and
    // § Attendance Policy And Recording Amendment) and is on by default since
    // 2026-11-17 (Owner decision 11). Recording stays closed: its rows still
    // diverge from their database documentation (DOC-CONFLICT-0006).
    'attendance_enabled' => env('LIVECLASS_ATTENDANCE_ENABLED', true),
    'recording_enabled' => env('LIVECLASS_RECORDING_ENABLED', false),

    // Automatic absence (ADR-0002 § Attendance Policy And Recording Amendment, review addition 7): Sessions whose
    // planned end is before this UTC moment are never marked absent automatically, so turning the feature on does
    // not invent results for the time before it existed. Immutable deployment cutover; empty turns the sweep off.
    'auto_absent_from' => env('LIVECLASS_AUTO_ABSENT_FROM', '2026-11-18 00:00:00'),
];
