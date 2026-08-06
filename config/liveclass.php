<?php

return [
    // Immutable deployment cutover used only to distinguish legacy no-origin
    // Sessions from Sessions created manually after Origin support shipped.
    'schedule_origin_rollout_at' => env('LIVECLASS_SCHEDULE_ORIGIN_ROLLOUT_AT', '2026-08-05 00:00:00'),
];
