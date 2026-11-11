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

// Backstop for Step 7 Authoring Proposal erasure; local database only.
Schedule::command('ai:authoring-reconcile-erasure')->everyFifteenMinutes()->withoutOverlapping(15);

// Media → Knowledge sync (Knowledge Sync Contract): source of truth for create,
// stale/archive and delete; the Media events only shorten the delay.
Schedule::command('ai:knowledge-sync')->everyTenMinutes()->withoutOverlapping(30);

// Expiry is a system-owned Enrollment transition (LF-Core-Course, Enrollment lifecycle).
Schedule::command('course:expire-enrollments')->everyFiveMinutes()->withoutOverlapping(10);

// Time-out of attempts the student left open (ADR-0025 Amendment 1.3, D10a); local database only.
Schedule::command('assessment:close-overdue')->everyMinute()->withoutOverlapping(5);

// A live Session is over when its timetable says so (LF-Core-LiveClass § Bắt đầu và kết thúc buổi); local database only.
Schedule::command('liveclass:complete-overdue')->everyMinute()->withoutOverlapping(5);
