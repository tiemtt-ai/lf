<?php

namespace App\Console\Commands;

use App\Services\Assessment\AssessmentAttemptService;
use Illuminate\Console\Command;

/**
 * Ends open assessment attempts whose time is up (ADR-0025 Amendment 1.3, D10a). Without it an attempt the student
 * leaves stays "in progress" until they come back: graders never see it, statistics miss it and the registration is
 * never exhausted. Local database only.
 */
class AssessmentCloseOverdue extends Command
{
    protected $signature = 'assessment:close-overdue {--customer= : Limit to one tenant} {--limit=500 : Attempts per run}';

    protected $description = 'Time out assessment attempts past their deadline.';

    public function handle(AssessmentAttemptService $attempts): int
    {
        $customer = $this->option('customer');
        $limit = $this->option('limit');
        if (($customer !== null && (! ctype_digit((string) $customer) || (int) $customer < 1))
            || ! ctype_digit((string) $limit) || (int) $limit < 1) {
            $this->error('Invalid option.');

            return self::INVALID;
        }

        $closed = $attempts->closeOverdue($customer !== null ? (int) $customer : null, (int) $limit);
        $this->info("Timed out {$closed} attempt(s).");

        return self::SUCCESS;
    }
}
