<?php

return [
    // Immutable deployment cutover used only to distinguish legacy no-origin
    // Sessions from Sessions created manually after Origin support shipped.
    'schedule_origin_rollout_at' => env('LIVECLASS_SCHEDULE_ORIGIN_ROLLOUT_AT', '2026-08-05 00:00:00'),

    // Attendance and Recording are unfinished features whose write endpoints are
    // nevertheless reachable by customer_admin. Attendance additionally accepts
    // Enrollments that are no longer active, which writes learning-cycle
    // evidence against a closed cycle (DOC-CONFLICT-0011); Recording writes rows
    // whose physical contract still diverges from its database documentation
    // (DOC-CONFLICT-0006).
    //
    // Both stay closed until each feature has been designed, reviewed under
    // DOC-CONFLICT-0008 and rebuilt. The flags gate the server-side write
    // endpoints and the Cohort tab state together, so re-opening one is a single
    // configuration change rather than a code revert. Existing rows are never
    // deleted — only made unreachable while the flag is off.
    'attendance_enabled' => env('LIVECLASS_ATTENDANCE_ENABLED', false),
    'recording_enabled' => env('LIVECLASS_RECORDING_ENABLED', false),
];
