<?php

namespace App\Services;

use App\Exceptions\BulkEnrollmentAtomicException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkEnrollmentService
{
    private const NON_TERMINAL = ['pending', 'active', 'suspended'];

    private const TERMINAL = ['completed', 'expired', 'cancelled'];

    public function preflight(int $customerId, array $payload): array
    {
        return $this->classify($customerId, $payload, false);
    }

    public function commit(int $customerId, int $adminId, string $rawToken, array $payload): array
    {
        return DB::transaction(function () use ($customerId, $adminId, $rawToken, $payload): array {
            $submission = DB::table('core_course_enrollment_submissions')
                ->where('customer_id', $customerId)
                ->where('admin_id', $adminId)
                ->where('token_hash', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();

            if (! $submission || ! hash_equals($submission->payload_hash, app(BulkEnrollmentPayload::class)->hash($payload))) {
                throw ValidationException::withMessages(['submission_token' => __('lf.LF_bulk_enrollment_validation_token')]);
            }

            if ($submission->status === 'completed') {
                return json_decode($submission->result, true, flags: JSON_THROW_ON_ERROR);
            }

            if ($submission->status !== 'prepared' || $submission->invalidated_at || now()->isAfter($submission->expires_at)) {
                throw ValidationException::withMessages(['submission_token' => __('lf.LF_bulk_enrollment_validation_token')]);
            }

            if ((int) $submission->pair_count < 1 || (int) $submission->pair_count > 100
                || (int) $submission->pair_count !== count($payload['student_ids']) * count($payload['product_ids'])) {
                throw ValidationException::withMessages(['pair_count' => __('lf.LF_bulk_enrollment_validation_pair_limit')]);
            }

            $preflight = $this->classify($customerId, $payload, true);
            if (! $preflight['valid']) {
                throw new BulkEnrollmentAtomicException($preflight);
            }

            $versions = collect($preflight['products'])->keyBy('id');
            $confirmations = collect($payload['reenrollment_confirmations'])
                ->keyBy(fn (array $item): string => $item['student_id'].':'.$item['product_id']);
            $items = [];
            $productCounts = [];
            $now = now();

            foreach ($payload['student_ids'] as $studentId) {
                foreach ($payload['product_ids'] as $productId) {
                    $pairKey = $studentId.':'.$productId;
                    $product = $versions->get($productId);
                    $reenrolled = $confirmations->has($pairKey);
                    $supportsReview = $product['offering_type'] === 'self_paced_course'
                        && (int) $product['review_duration_days'] > 0;
                    $enrollmentId = DB::table('core_course_enrollments')->insertGetId([
                        'customer_id' => $customerId,
                        'product_id' => $productId,
                        'version_id' => $product['version_id'],
                        'student_id' => $studentId,
                        'source' => 'admin',
                        'source_id' => null,
                        'enrolled_by' => $adminId,
                        'enrolled_at' => $now,
                        'access_starts_at' => $payload['configuration']['access_starts_at'],
                        'access_ends_at' => $payload['configuration']['access_ends_at'],
                        'review_starts_at' => $supportsReview ? $payload['configuration']['review_starts_at'] : null,
                        'review_ends_at' => $supportsReview ? $payload['configuration']['review_ends_at'] : null,
                        'status' => 'active',
                        'notes' => $payload['configuration']['notes'],
                        'completed_at' => null,
                        'cancelled_at' => null,
                        'expired_at' => null,
                        'metadata' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $productCounts[$productId] = ($productCounts[$productId] ?? 0) + 1;
                    $items[] = [
                        'student_id' => $studentId,
                        'student_name' => $preflight['student_labels'][(string) $studentId],
                        'product_id' => $productId,
                        'product_title' => $product['title'],
                        'version_code' => $product['version_code'],
                        'enrollment_id' => $enrollmentId,
                        'status' => $reenrolled ? 'reenrolled' : 'created',
                    ];
                }
            }

            foreach ($productCounts as $productId => $count) {
                DB::table('core_course_products')->where('customer_id', $customerId)
                    ->where('id', $productId)->increment('enrollment_count', $count);
            }

            $result = [
                'context' => [
                    'submission_id' => (int) $submission->id,
                    'completed_at' => $now->toIso8601String(),
                    'completed_by_id' => $adminId,
                    'completed_by_name' => DB::table('users')->where('customer_id', $customerId)
                        ->where('id', $adminId)->value('name'),
                    'configuration' => $payload['configuration'],
                ],
                'summary' => [
                    'total' => count($items),
                    'created' => collect($items)->where('status', 'created')->count(),
                    'reenrolled' => collect($items)->where('status', 'reenrolled')->count(),
                    'skipped_existing' => 0,
                    're_enrollment_required' => 0,
                    'failed' => 0,
                ],
                'items' => $items,
            ];

            DB::table('core_course_enrollment_submissions')->where('id', $submission->id)->update([
                'status' => 'completed',
                'committed_at' => $now,
                'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);

            return $result;
        }, 3);
    }

    private function classify(int $customerId, array $payload, bool $lock): array
    {
        $studentsQuery = DB::table('users')->where('customer_id', $customerId)
            ->whereIn('id', $payload['student_ids'])->orderBy('id');
        $productsQuery = DB::table('core_course_products')->where('customer_id', $customerId)
            ->whereIn('id', $payload['product_ids'])->orderBy('id');
        if ($lock) {
            $studentsQuery->lockForUpdate();
            $productsQuery->lockForUpdate();
        }
        $students = $studentsQuery->get(['id', 'name', 'email', 'role', 'status'])->keyBy('id');
        $products = $productsQuery->get(['id', 'title', 'product_code', 'status', 'offering_type', 'review_duration_days', 'registration_starts_at', 'registration_ends_at'])->keyBy('id');

        $bindingsQuery = DB::table('core_course_product_items as items')
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'items.version_id')->where('versions.customer_id', $customerId);
            })->where('items.customer_id', $customerId)->whereIn('items.product_id', $payload['product_ids'])
            ->where('items.status', 'active')->orderBy('items.product_id')->orderBy('items.id');
        if ($lock) {
            $bindingsQuery->lockForUpdate();
        }
        $bindings = $bindingsQuery->get(['items.id as item_id', 'items.product_id', 'versions.id as version_id', 'versions.version_code', 'versions.status as version_status'])
            ->groupBy('product_id');

        $existingQuery = DB::table('core_course_enrollments')->where('customer_id', $customerId)
            ->whereIn('student_id', $payload['student_ids'])->whereIn('product_id', $payload['product_ids'])
            ->orderBy('student_id')->orderBy('product_id')->orderByDesc('id');
        if ($lock) {
            $existingQuery->lockForUpdate();
        }
        $existing = $existingQuery->get(['id', 'student_id', 'product_id', 'status'])
            ->groupBy(fn (object $row): string => $row->student_id.':'.$row->product_id);
        $confirmations = collect($payload['reenrollment_confirmations'])
            ->keyBy(fn (array $item): string => $item['student_id'].':'.$item['product_id']);

        $pairs = [];
        $valid = true;
        foreach ($payload['student_ids'] as $studentId) {
            foreach ($payload['product_ids'] as $productId) {
                $key = $studentId.':'.$productId;
                $student = $students->get($studentId);
                $product = $products->get($productId);
                $productBindings = $bindings->get($productId, collect());
                $history = $existing->get($key, collect());
                $nonTerminal = $history->first(fn (object $row): bool => in_array($row->status, self::NON_TERMINAL, true));
                $terminal = $history->first(fn (object $row): bool => in_array($row->status, self::TERMINAL, true));
                $status = 'creatable';
                $reason = null;
                $previousId = null;

                if (! $student || $student->role !== 'student' || $student->status !== 'active') {
                    $status = 'ineligible';
                    $reason = __('lf.LF_bulk_enrollment_failure_student');
                } elseif (! $product || $product->status !== 'active') {
                    $status = 'ineligible';
                    $reason = __('lf.LF_bulk_enrollment_failure_product');
                } elseif ($productBindings->count() !== 1 || $productBindings->first()->version_status !== 'published') {
                    $status = 'ineligible';
                    $reason = __('lf.LF_bulk_enrollment_failure_binding');
                } elseif ($nonTerminal) {
                    $status = 'existing_non_terminal';
                    $reason = __('lf.LF_bulk_enrollment_already_enrolled');
                    $previousId = $nonTerminal->id;
                } elseif ($terminal) {
                    $status = 'reenrollment_eligible';
                    $previousId = $terminal->id;
                    $confirmation = $confirmations->get($key);
                    if (! $confirmation || (int) $confirmation['previous_enrollment_id'] !== (int) $terminal->id) {
                        $reason = __('lf.LF_bulk_enrollment_confirmation_required');
                    }
                }

                if (in_array($status, ['ineligible', 'existing_non_terminal'], true)
                    || ($status === 'reenrollment_eligible' && $reason !== null)) {
                    $valid = false;
                }
                $pairs[] = ['student_id' => $studentId, 'product_id' => $productId, 'status' => $status,
                    'previous_enrollment_id' => $previousId, 'reason' => $reason,
                    'student_name' => $student?->name ?? '#'.$studentId,
                    'product_title' => $product?->title ?? '#'.$productId];
            }
        }

        $expectedConfirmationKeys = collect($pairs)
            ->where('status', 'reenrollment_eligible')
            ->map(fn (array $pair): string => $pair['student_id'].':'.$pair['product_id'])
            ->sort()->values();
        $providedConfirmationKeys = $confirmations->keys()->sort()->values();
        $confirmationError = $providedConfirmationKeys->diff($expectedConfirmationKeys)->isNotEmpty()
            ? __('lf.LF_bulk_enrollment_validation_confirmation')
            : null;
        if ($confirmationError) {
            $valid = false;
        }

        return [
            'valid' => $valid,
            'confirmation_error' => $confirmationError,
            'pair_count' => count($pairs),
            'pairs' => $pairs,
            'student_labels' => $students->mapWithKeys(fn (object $row): array => [(string) $row->id => $row->name])->all(),
            'products' => $products->map(function (object $product) use ($bindings): array {
                $binding = $bindings->get($product->id, collect())->first();

                return ['id' => $product->id, 'title' => $product->title, 'product_code' => $product->product_code,
                    'offering_type' => $product->offering_type, 'review_duration_days' => $product->review_duration_days,
                    'version_id' => $binding?->version_id, 'version_code' => $binding?->version_code];
            })->values()->all(),
        ];
    }
}
