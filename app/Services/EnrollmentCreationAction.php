<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrollmentCreationAction
{
    public function __construct(
        private readonly EnrollmentEligibilityPolicy $eligibility,
        private readonly ProductCourseVersionResolver $resolver,
    ) {}

    public function create(int $customerId, int $actorId, array $input): int
    {
        return DB::transaction(function () use ($customerId, $actorId, $input): int {
            $payload = [
                'student_ids' => [(int) $input['student_id']],
                'product_ids' => [(int) $input['product_id']],
                'reenrollment_confirmations' => [],
                'configuration' => [
                    'enrolled_at' => $input['enrolled_at'] ?? now()->format('Y-m-d H:i:s'),
                    'notes' => $input['notes'] ?? null,
                ],
            ];
            $prepared = $this->prepare($customerId, $payload, true, false);
            if (! $prepared['valid']) {
                $pair = $prepared['pairs'][0];
                throw ValidationException::withMessages([
                    $pair['status'] === 'existing_non_terminal' ? 'student_id' : 'product_id' => $pair['reason'],
                ]);
            }

            return $this->insertPrepared($customerId, $actorId, $prepared, $payload)[0]['enrollment_id'];
        }, 3);
    }

    public function prepare(
        int $customerId,
        array $payload,
        bool $lock,
        bool $requireReenrollmentConfirmation = true,
        ?Carbon $enrolledAt = null,
    ): array {
        $studentIds = collect($payload['student_ids'])->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $productIds = collect($payload['product_ids'])->map(fn ($id): int => (int) $id)->unique()->sort()->values();

        $studentsQuery = DB::table('users')->where('customer_id', $customerId)
            ->whereIn('id', $studentIds)->orderBy('id');
        $productsQuery = DB::table('core_course_products')->where('customer_id', $customerId)
            ->whereIn('id', $productIds)->orderBy('id');
        if ($lock) {
            $studentsQuery->lockForUpdate();
            $productsQuery->lockForUpdate();
        }
        $students = $studentsQuery->get(['id', 'name', 'email', 'role', 'status'])->keyBy('id');
        $products = $productsQuery->get([
            'id', 'title', 'product_code', 'status', 'offering_type',
            'access_duration_days', 'review_duration_days',
            'registration_starts_at', 'registration_ends_at',
        ])->keyBy('id');
        $bindings = $this->resolver->resolveMany($customerId, $productIds->all(), $lock);
        $enrolledAt ??= filled($payload['configuration']['enrolled_at'] ?? null)
            ? Carbon::parse($payload['configuration']['enrolled_at']) : now();

        $timePolicies = $productIds->mapWithKeys(function (int $productId) use ($customerId, $enrolledAt, $lock): array {
            try {
                return [$productId => ['windows' => $this->eligibility->timeWindows(
                    $customerId, $productId, $enrolledAt, $lock
                ), 'error' => null]];
            } catch (ValidationException $exception) {
                return [$productId => ['windows' => null, 'error' => collect($exception->errors())->flatten()->first()]];
            }
        });

        $existingQuery = DB::table('core_course_enrollments')->where('customer_id', $customerId)
            ->whereIn('student_id', $studentIds)->whereIn('product_id', $productIds)
            ->orderBy('student_id')->orderBy('product_id')->orderBy('id');
        if ($lock) {
            $existingQuery->lockForUpdate();
        }
        $existing = $existingQuery->get(['id', 'student_id', 'product_id', 'status'])
            ->groupBy(fn (object $row): string => $row->student_id.':'.$row->product_id);
        $confirmations = collect($payload['reenrollment_confirmations'] ?? [])
            ->keyBy(fn (array $item): string => $item['student_id'].':'.$item['product_id']);

        $pairs = [];
        $valid = true;
        foreach ($studentIds as $studentId) {
            foreach ($productIds as $productId) {
                $student = $students->get($studentId);
                $product = $products->get($productId);
                $bindingResult = $bindings[$productId] ?? ['binding' => null, 'error' => 'active_item'];
                $historyState = $this->eligibility->classifyHistory(
                    $existing->get($studentId.':'.$productId, collect())
                );
                $status = 'creatable';
                $reason = null;

                try {
                    $this->eligibility->assertStudent($student);
                    $this->eligibility->assertProduct($product);
                } catch (ValidationException $exception) {
                    $status = 'ineligible';
                    $reason = collect($exception->errors())->flatten()->first();
                }
                if ($status === 'creatable' && ($timePolicies->get($productId)['error'] ?? null)) {
                    $status = 'ineligible';
                    $reason = $timePolicies->get($productId)['error'];
                }
                if ($status === 'creatable' && ! $bindingResult['binding']) {
                    $status = 'ineligible';
                    $reason = $bindingResult['error'] === 'active_item'
                        ? __('lf.LF_course_enrollment_validation_active_item')
                        : __('lf.LF_course_enrollment_validation_published_version');
                }
                if ($status === 'creatable' && $historyState['status'] === 'existing_non_terminal') {
                    $status = 'existing_non_terminal';
                    $reason = __('lf.LF_bulk_enrollment_already_enrolled');
                } elseif ($status === 'creatable' && $historyState['status'] === 'reenrollment_eligible') {
                    $status = 'reenrollment_eligible';
                    if ($requireReenrollmentConfirmation) {
                        $confirmation = $confirmations->get($studentId.':'.$productId);
                        if (! $confirmation || (int) $confirmation['previous_enrollment_id'] !== $historyState['previous_enrollment_id']) {
                            $reason = __('lf.LF_bulk_enrollment_confirmation_required');
                        }
                    }
                }
                if ($status === 'ineligible' || $status === 'existing_non_terminal'
                    || ($status === 'reenrollment_eligible' && $reason !== null)) {
                    $valid = false;
                }
                $pairs[] = [
                    'student_id' => $studentId, 'product_id' => $productId, 'status' => $status,
                    'previous_enrollment_id' => $historyState['previous_enrollment_id'], 'reason' => $reason,
                    'student_name' => $student?->name ?? '#'.$studentId,
                    'product_title' => $product?->title ?? '#'.$productId,
                    'time_windows' => $timePolicies->get($productId)['windows'] ?? null,
                    'version_number' => $bindingResult['binding']?->version_number,
                    'version_code' => $bindingResult['binding']?->version_code,
                ];
            }
        }

        return [
            'valid' => $valid,
            'pair_count' => count($pairs),
            'pairs' => $pairs,
            'student_labels' => $students->mapWithKeys(fn (object $row): array => [(string) $row->id => $row->name])->all(),
            'products' => $products->map(function (object $product) use ($bindings, $timePolicies): array {
                $binding = $bindings[(int) $product->id]['binding'] ?? null;

                return [
                    'id' => (int) $product->id, 'title' => $product->title, 'product_code' => $product->product_code,
                    'offering_type' => $product->offering_type, 'review_duration_days' => $product->review_duration_days,
                    'time_windows' => $timePolicies->get((int) $product->id)['windows'] ?? null,
                    'version_id' => $binding?->version_id, 'version_number' => $binding?->version_number,
                    'version_code' => $binding?->version_code,
                ];
            })->values()->all(),
            'enrolled_at' => $enrolledAt,
        ];
    }

    public function insertPrepared(int $customerId, int $actorId, array $prepared, array $payload): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Enrollment insert requires an active transaction.');
        }
        if (! $prepared['valid']) {
            throw new \LogicException('Cannot insert an invalid Enrollment preparation.');
        }

        $products = collect($prepared['products'])->keyBy('id');
        $confirmations = collect($payload['reenrollment_confirmations'] ?? [])
            ->keyBy(fn (array $item): string => $item['student_id'].':'.$item['product_id']);
        $now = now();
        $items = [];
        $productCounts = [];
        foreach ($prepared['pairs'] as $pair) {
            $product = $products->get($pair['product_id']);
            $windows = $product['time_windows'];
            $enrollmentId = DB::table('core_course_enrollments')->insertGetId([
                'customer_id' => $customerId, 'product_id' => $pair['product_id'],
                'version_id' => $product['version_id'], 'student_id' => $pair['student_id'],
                'source' => 'admin', 'source_id' => null, 'enrolled_by' => $actorId,
                'enrolled_at' => $prepared['enrolled_at'],
                'access_duration_days' => $windows['access_duration_days'],
                'review_duration_days' => $windows['review_duration_days'],
                'access_starts_at' => $windows['access_starts_at'], 'access_ends_at' => $windows['access_ends_at'],
                'review_starts_at' => $windows['review_starts_at'], 'review_ends_at' => $windows['review_ends_at'],
                'status' => 'active', 'notes' => $payload['configuration']['notes'] ?? null,
                'completed_at' => null, 'cancelled_at' => null, 'expired_at' => null,
                'metadata' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $productCounts[$pair['product_id']] = ($productCounts[$pair['product_id']] ?? 0) + 1;
            $items[] = [
                'student_id' => $pair['student_id'], 'student_name' => $pair['student_name'],
                'product_id' => $pair['product_id'], 'product_title' => $product['title'],
                'version_code' => $product['version_code'], 'enrollment_id' => $enrollmentId,
                'status' => $confirmations->has($pair['student_id'].':'.$pair['product_id']) ? 'reenrolled' : 'created',
                'time_windows' => $windows,
            ];
        }
        foreach ($productCounts as $productId => $count) {
            DB::table('core_course_products')->where('customer_id', $customerId)
                ->where('id', $productId)->increment('enrollment_count', $count);
        }

        return $items;
    }
}
