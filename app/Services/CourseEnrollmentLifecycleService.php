<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseEnrollmentLifecycleService
{
    private const TRANSITIONS = [
        'pending' => ['active', 'cancelled'],
        'active' => ['suspended', 'cancelled'],
        'suspended' => ['active', 'cancelled'],
        'completed' => [], 'expired' => [], 'cancelled' => [],
    ];

    public function activate(int $customerId, int $id): void
    {
        $this->transition($customerId, $id, 'active', ['pending']);
    }

    public function suspend(int $customerId, int $id): void
    {
        $this->transition($customerId, $id, 'suspended', ['active']);
    }

    public function reactivate(int $customerId, int $id): void
    {
        $this->transition($customerId, $id, 'active', ['suspended']);
    }

    public function cancel(int $customerId, int $id): void
    {
        $this->transition($customerId, $id, 'cancelled', ['pending', 'active', 'suspended']);
    }

    private function transition(int $customerId, int $id, string $target, array $sources): void
    {
        DB::transaction(function () use ($customerId, $id, $target, $sources): void {
            $enrollment = DB::table('core_course_enrollments')->where('customer_id', $customerId)
                ->where('id', $id)->lockForUpdate()->first();
            abort_if(! $enrollment, 404);
            if (! in_array($enrollment->status, $sources, true)
                || ! in_array($target, self::TRANSITIONS[$enrollment->status] ?? [], true)) {
                throw ValidationException::withMessages(['lifecycle' => __('lf.LF_course_enrollment_lifecycle_invalid_transition')]);
            }
            if ($target === 'active') {
                $this->assertActivationEligible($customerId, $enrollment, $enrollment->status === 'pending');
            }

            DB::table('core_course_enrollments')->where('customer_id', $customerId)->where('id', $id)->update([
                'status' => $target,
                'cancelled_at' => $target === 'cancelled' ? now() : $enrollment->cancelled_at,
                'updated_at' => now(),
            ]);
        }, 3);
    }

    private function assertActivationEligible(int $customerId, object $enrollment, bool $requireActiveProduct): void
    {
        $product = DB::table('core_course_products')->where('customer_id', $customerId)
            ->where('id', $enrollment->product_id)
            ->when($requireActiveProduct, fn ($query) => $query->where('status', 'active'))->lockForUpdate()->first(['id']);
        $version = DB::table('core_course_template_versions')->where('customer_id', $customerId)
            ->where('id', $enrollment->version_id)->where('status', 'published')->exists();
        if (! $product || ! $version || ($enrollment->access_ends_at && Carbon::parse($enrollment->access_ends_at)->isPast())) {
            throw ValidationException::withMessages(['lifecycle' => __('lf.LF_course_enrollment_lifecycle_activation_ineligible')]);
        }
    }
}
