<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseEnrollmentLifecycleService
{
    private const SELF_PACED_OFFERING = 'self_paced_course';

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

    public function projectTimeWindows(int $customerId, int $productId, CarbonInterface $enrolledAt, bool $lock = false): array
    {
        $product = DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->where('id', $productId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first([
                'offering_type',
                'registration_starts_at', 'registration_ends_at',
                'access_duration_days', 'review_duration_days',
            ]);
        if (! $product) {
            throw ValidationException::withMessages(['product_id' => __('lf.LF_course_enrollment_validation_product')]);
        }

        $this->assertRegistrationWindow($product, $enrolledAt);

        return $this->projectFromOffering(
            $enrolledAt,
            $product->offering_type,
            $product->access_duration_days,
            $product->review_duration_days,
        );
    }

    public function reprojectEnrollment(object $enrollment, CarbonInterface $enrolledAt): array
    {
        $product = DB::table('core_course_products')
            ->where('customer_id', $enrollment->customer_id)
            ->where('id', $enrollment->product_id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first(['offering_type', 'registration_starts_at', 'registration_ends_at']);
        if (! $product) {
            throw ValidationException::withMessages(['enrolled_at' => __('lf.LF_course_enrollment_validation_product')]);
        }

        $studentIsEligible = DB::table('users')
            ->where('customer_id', $enrollment->customer_id)
            ->where('id', $enrollment->student_id)
            ->where('role', 'student')
            ->where('status', 'active')
            ->exists();
        if (! $studentIsEligible) {
            throw ValidationException::withMessages(['enrolled_at' => __('lf.LF_course_enrollment_validation_student')]);
        }

        $bindings = DB::table('core_course_product_items as items')
            ->join('core_course_template_versions as versions', function ($join) use ($enrollment): void {
                $join->on('versions.id', '=', 'items.version_id')
                    ->where('versions.customer_id', $enrollment->customer_id);
            })
            ->where('items.customer_id', $enrollment->customer_id)
            ->where('items.product_id', $enrollment->product_id)
            ->where('items.status', 'active')
            ->lockForUpdate()
            ->get(['versions.status']);
        if ($bindings->count() !== 1) {
            throw ValidationException::withMessages(['enrolled_at' => __('lf.LF_course_enrollment_validation_active_item')]);
        }
        if ($bindings->first()->status !== 'published') {
            throw ValidationException::withMessages(['enrolled_at' => __('lf.LF_course_enrollment_validation_published_version')]);
        }

        $this->assertRegistrationWindow($product, $enrolledAt, 'enrolled_at');

        if ($product->offering_type === self::SELF_PACED_OFFERING
            && $enrollment->access_duration_days === null) {
            throw ValidationException::withMessages([
                'enrolled_at' => __('lf.LF_course_enrollment_legacy_duration_missing'),
            ]);
        }

        return $this->projectFromOffering(
            $enrolledAt,
            $product->offering_type,
            $enrollment->access_duration_days,
            $enrollment->review_duration_days,
            'enrolled_at',
        );
    }

    private function projectFromOffering(
        CarbonInterface $enrolledAt,
        mixed $offeringType,
        mixed $accessDuration,
        mixed $reviewDuration,
        string $field = 'product_id',
    ): array {
        if ($offeringType !== self::SELF_PACED_OFFERING) {
            return [
                'access_starts_at' => null,
                'access_ends_at' => null,
                'review_starts_at' => null,
                'review_ends_at' => null,
                'access_duration_days' => null,
                'review_duration_days' => null,
            ];
        }

        return $this->projectFromDurations(
            $enrolledAt,
            $accessDuration,
            $reviewDuration,
            $field,
        );
    }

    private function assertRegistrationWindow(object $product, CarbonInterface $enrolledAt, string $field = 'product_id'): void
    {
        $registrationStart = $product->registration_starts_at ? Carbon::parse($product->registration_starts_at) : null;
        $registrationEnd = $product->registration_ends_at ? Carbon::parse($product->registration_ends_at) : null;
        if (($registrationStart === null) !== ($registrationEnd === null)
            || ($registrationStart && $registrationStart->greaterThanOrEqualTo($registrationEnd))) {
            throw ValidationException::withMessages([$field => __('lf.LF_course_enrollment_registration_invalid')]);
        }
        if ($registrationStart && $enrolledAt->lt($registrationStart)) {
            throw ValidationException::withMessages([$field => __('lf.LF_course_enrollment_registration_not_open', [
                'start' => $registrationStart->format('d/m/Y H:i'),
                'enrolled_at' => $enrolledAt->format('d/m/Y H:i'),
            ])]);
        }
        if ($registrationEnd && $enrolledAt->gt($registrationEnd)) {
            throw ValidationException::withMessages([$field => __('lf.LF_course_enrollment_registration_ended', [
                'end' => $registrationEnd->format('d/m/Y H:i'),
                'enrolled_at' => $enrolledAt->format('d/m/Y H:i'),
            ])]);
        }
    }

    private function projectFromDurations(CarbonInterface $enrolledAt, mixed $accessDuration, mixed $reviewDuration, string $field = 'product_id'): array
    {
        $accessDays = (int) $accessDuration;
        if ($accessDays < 1) {
            throw ValidationException::withMessages([$field => __('lf.LF_course_enrollment_access_duration_invalid')]);
        }
        $reviewDays = $reviewDuration === null ? null : (int) $reviewDuration;
        if ($reviewDays !== null && $reviewDays < 0) {
            throw ValidationException::withMessages([$field => __('lf.LF_course_enrollment_review_duration_invalid')]);
        }

        $accessStart = Carbon::instance($enrolledAt)->copy();
        $accessEnd = $accessStart->copy()->addDays($accessDays);
        $reviewStart = $reviewDays > 0 ? $accessEnd->copy() : null;
        $reviewEnd = $reviewStart?->copy()->addDays($reviewDays);

        return [
            'access_starts_at' => $accessStart,
            'access_ends_at' => $accessEnd,
            'review_starts_at' => $reviewStart,
            'review_ends_at' => $reviewEnd,
            'access_duration_days' => $accessDays,
            'review_duration_days' => $reviewDays,
        ];
    }

    public function allowsLearningAccessAt(object $enrollment, CarbonInterface $at): bool
    {
        if (! $enrollment->access_starts_at && ! $enrollment->access_ends_at) {
            return true;
        }

        $inAccess = $enrollment->access_starts_at && $enrollment->access_ends_at
            && $at->gte(Carbon::parse($enrollment->access_starts_at))
            && $at->lt(Carbon::parse($enrollment->access_ends_at));
        $inReview = $enrollment->review_starts_at && $enrollment->review_ends_at
            && $at->gte(Carbon::parse($enrollment->review_starts_at))
            && $at->lt(Carbon::parse($enrollment->review_ends_at));

        return $inAccess || $inReview;
    }

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
        if (! $product || ! $version || $this->learningWindowHasEnded($enrollment)) {
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
                || $this->learningWindowHasEnded($enrollment))) {
            throw ValidationException::withMessages(['enrollment_ids' => __('lf.LF_course_enrollment_lifecycle_activation_ineligible')]);
        }
    }

    private function learningWindowHasEnded(object $enrollment): bool
    {
        $learningWindowEndsAt = $enrollment->review_ends_at ?? $enrollment->access_ends_at;

        return $learningWindowEndsAt !== null && Carbon::parse($learningWindowEndsAt)->isPast();
    }
}
