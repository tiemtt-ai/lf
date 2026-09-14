<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Job-state recovery only; this does not schedule Media storage deletion.
Schedule::command('media:recover-document-processing')->everyMinute()->withoutOverlapping(2);
Schedule::command('media:recover-audio-processing')->everyMinute()->withoutOverlapping(2);

// Backstop for the MediaFileDeleted listener (ADR-0020 D5); local database only.
Schedule::command('ai:vision-reconcile-media-deletion')->everyFifteenMinutes()->withoutOverlapping(15);
