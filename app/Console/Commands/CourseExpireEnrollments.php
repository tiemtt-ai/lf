<?php

namespace App\Console\Commands;

use App\Services\CourseEnrollmentLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves Enrollments whose learning window has ended to `expired`, one tenant at a time.
 * Local database only; Cohort Membership is never touched.
 */
class CourseExpireEnrollments extends Command
{
    protected $signature = 'course:expire-enrollments {--customer= : Limit expiry to one tenant}';

    protected $description = 'Expire active or suspended Enrollments whose access and review windows have ended.';

    public function handle(CourseEnrollmentLifecycleService $lifecycle): int
    {
        $customer = $this->option('customer');
        if ($customer !== null && (! ctype_digit((string) $customer) || (int) $customer < 1)) {
            $this->error('Invalid customer.');

            return self::INVALID;
        }

        $now = now();
        $customerIds = DB::table('core_course_enrollments')
            ->whereIn('status', ['active', 'suspended'])
            ->where(fn ($query) => $query->where('review_ends_at', '<=', $now)
                ->orWhere(fn ($query) => $query->whereNull('review_ends_at')->where('access_ends_at', '<=', $now)))
            ->when($customer !== null, fn ($query) => $query->where('customer_id', (int) $customer))
            ->distinct()->orderBy('customer_id')->pluck('customer_id');

        $expired = 0;
        foreach ($customerIds as $customerId) {
            $expired += $lifecycle->expireEndedLearningCycles((int) $customerId, $now);
        }

        $this->info("Expired {$expired} enrollment(s) across {$customerIds->count()} tenant(s).");

        return self::SUCCESS;
    }
}
