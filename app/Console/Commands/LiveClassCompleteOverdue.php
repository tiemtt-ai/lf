<?php

namespace App\Console\Commands;

use App\Services\LiveClassSessionLifecycleService;
use Illuminate\Console\Command;

/**
 * Ends `live` Sessions whose scheduled end has passed (LF-Core-LiveClass § Bắt đầu và kết thúc buổi).
 * Local database only.
 */
class LiveClassCompleteOverdue extends Command
{
    protected $signature = 'liveclass:complete-overdue {--customer= : Limit the sweep to one tenant}';

    protected $description = 'Complete live Sessions that have passed their scheduled end time.';

    public function handle(LiveClassSessionLifecycleService $lifecycle): int
    {
        $customer = $this->option('customer');
        if ($customer !== null && (! ctype_digit((string) $customer) || (int) $customer < 1)) {
            $this->error('Invalid customer.');

            return self::INVALID;
        }

        $completed = $lifecycle->completeOverdue($customer !== null ? (int) $customer : null);
        $this->info("Completed {$completed} overdue session(s).");

        return self::SUCCESS;
    }
}
