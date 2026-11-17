<?php

namespace App\Console\Commands;

use App\Services\LiveClassAttendanceRecorder;
use Illuminate\Console\Command;

/**
 * Marks learners without a result absent after a Session (LF-Core-LiveClass § Tự ghi vắng). Local database only.
 */
class LiveClassMarkAbsent extends Command
{
    protected $signature = 'liveclass:mark-absent {--customer= : Limit the sweep to one tenant}';

    protected $description = 'Mark learners without an attendance result absent once the delay after a Session has passed.';

    public function handle(LiveClassAttendanceRecorder $recorder): int
    {
        $customer = $this->option('customer');
        if ($customer !== null && (! ctype_digit((string) $customer) || (int) $customer < 1)) {
            $this->error('Invalid customer.');

            return self::INVALID;
        }

        $written = $recorder->markAbsent($customer !== null ? (int) $customer : null);
        $this->info("Marked {$written} learner(s) absent.");

        return self::SUCCESS;
    }
}
