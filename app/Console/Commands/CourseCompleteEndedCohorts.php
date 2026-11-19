<?php

namespace App\Console\Commands;

use App\Services\CourseCohortLifecycleService;
use Illuminate\Console\Command;

/**
 * Completes active classes whose end date has passed (ADR-0022 Amendment 1.4, "Lớp tự kết thúc theo ngày").
 * Local database only.
 */
class CourseCompleteEndedCohorts extends Command
{
    protected $signature = 'course:complete-ended-cohorts';

    protected $description = 'Complete active classes after 23:59 of their end date.';

    public function handle(CourseCohortLifecycleService $lifecycle): int
    {
        $completed = $lifecycle->completeEnded();
        $this->info("Completed {$completed} ended class(es).");

        return self::SUCCESS;
    }
}
