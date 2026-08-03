<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class EnrollmentEligibilityPolicy
{
    public const NON_TERMINAL = ['pending', 'active', 'suspended'];

    public const TERMINAL = ['completed', 'expired', 'cancelled'];

    public function __construct(private readonly CourseEnrollmentLifecycleService $lifecycle) {}

    public function assertStudent(?object $student): void
    {
        if (! $student || $student->role !== 'student' || $student->status !== 'active') {
            throw ValidationException::withMessages([
                'student_id' => __('lf.LF_course_enrollment_validation_student'),
            ]);
        }
    }

    public function assertProduct(?object $product): void
    {
        if (! $product || $product->status !== 'active') {
            throw ValidationException::withMessages([
                'product_id' => __('lf.LF_course_enrollment_validation_product'),
            ]);
        }
    }

    public function timeWindows(int $customerId, int $productId, Carbon $enrolledAt, bool $lock): array
    {
        return $this->lifecycle->projectTimeWindows($customerId, $productId, $enrolledAt, $lock);
    }

    public function classifyHistory($history): array
    {
        $nonTerminal = $history->first(
            fn (object $row): bool => in_array($row->status, self::NON_TERMINAL, true)
        );
        if ($nonTerminal) {
            return ['status' => 'existing_non_terminal', 'previous_enrollment_id' => (int) $nonTerminal->id];
        }

        $terminal = $history->first(
            fn (object $row): bool => in_array($row->status, self::TERMINAL, true)
        );

        return $terminal
            ? ['status' => 'reenrollment_eligible', 'previous_enrollment_id' => (int) $terminal->id]
            : ['status' => 'creatable', 'previous_enrollment_id' => null];
    }
}
