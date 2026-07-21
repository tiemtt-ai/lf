<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseEnrollmentLifecycleService
{
    private const BULK_ACTIONS = [
        'suspend' => ['target' => 'suspended', 'sources' => ['active']],
        'reactivate' => ['target' => 'active', 'sources' => ['suspended']],
        'cancel' => ['target' => 'cancelled', 'sources' => ['pending', 'active', 'suspended']],
    ];

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

    public function bulkTransition(int $customerId, array $ids, string $action): int
    {
        $transition = self::BULK_ACTIONS[$action] ?? null;
        if (! $transition) {
            throw ValidationException::withMessages(['action' => __('lf.LF_course_enrollment_bulk_lifecycle_invalid_action')]);
        }

        $ids = collect($ids)->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        return DB::transaction(function () use ($customerId, $ids, $transition): int {
            $enrollments = DB::table('core_course_enrollments')->where('customer_id', $customerId)
                ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($enrollments->count() !== count($ids)
                || $enrollments->contains(fn (object $row): bool => ! in_array($row->status, $transition['sources'], true)
                    || ! in_array($transition['target'], self::TRANSITIONS[$row->status] ?? [], true))) {
                throw ValidationException::withMessages(['enrollment_ids' => __('lf.LF_course_enrollment_bulk_lifecycle_stale')]);
            }

            if ($transition['target'] === 'active') {
                $this->assertBulkReactivationEligible($customerId, $enrollments);
            }

            $now = now();
            foreach ($enrollments as $enrollment) {
                DB::table('core_course_enrollments')->where('customer_id', $customerId)
                    ->where('id', $enrollment->id)->update([
                        'status' => $transition['target'],
                        'cancelled_at' => $transition['target'] === 'cancelled' ? $now : $enrollment->cancelled_at,
                        'updated_at' => $now,
                    ]);
            }

            return $enrollments->count();
        }, 3);
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

    private function assertBulkReactivationEligible(int $customerId, $enrollments): void
    {
        $productIds = $enrollments->pluck('product_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $products = DB::table('core_course_products')->where('customer_id', $customerId)
            ->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get(['id'])->keyBy('id');
        $versionIds = $enrollments->pluck('version_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $publishedVersionIds = DB::table('core_course_template_versions')->where('customer_id', $customerId)
            ->whereIn('id', $versionIds)->where('status', 'published')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($products->count() !== $productIds->count()
            || $enrollments->contains(fn (object $enrollment): bool => ! in_array((int) $enrollment->version_id, $publishedVersionIds, true)
                || ($enrollment->access_ends_at && Carbon::parse($enrollment->access_ends_at)->isPast()))) {
            throw ValidationException::withMessages(['enrollment_ids' => __('lf.LF_course_enrollment_lifecycle_activation_ineligible')]);
        }
    }
}
