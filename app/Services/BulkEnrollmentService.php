<?php

namespace App\Services;

use App\Exceptions\BulkEnrollmentAtomicException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkEnrollmentService
{
    public function __construct(private readonly EnrollmentCreationAction $creation) {}

    public function preflight(int $customerId, array $payload): array
    {
        return $this->withConfirmationContract(
            $this->creation->prepare($customerId, $payload, false, true),
            $payload
        );
    }

    public function commit(int $customerId, int $adminId, string $rawToken, array $payload): array
    {
        return DB::transaction(function () use ($customerId, $adminId, $rawToken, $payload): array {
            $submission = DB::table('core_course_enrollment_submissions')
                ->where('customer_id', $customerId)->where('admin_id', $adminId)
                ->where('token_hash', hash('sha256', $rawToken))->lockForUpdate()->first();

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

            $enrolledAt = filled($payload['configuration']['enrolled_at'] ?? null)
                ? Carbon::parse($payload['configuration']['enrolled_at']) : now();
            $prepared = $this->withConfirmationContract(
                $this->creation->prepare($customerId, $payload, true, true, $enrolledAt),
                $payload
            );
            if (! $prepared['valid']) {
                throw new BulkEnrollmentAtomicException($prepared);
            }

            $items = $this->creation->insertPrepared($customerId, $adminId, $prepared, $payload);
            $now = now();
            $result = [
                'context' => [
                    'submission_id' => (int) $submission->id,
                    'completed_at' => $now->toIso8601String(), 'completed_by_id' => $adminId,
                    'completed_by_name' => DB::table('users')->where('customer_id', $customerId)
                        ->where('id', $adminId)->value('name'),
                    'configuration' => $payload['configuration'],
                ],
                'summary' => [
                    'total' => count($items),
                    'created' => collect($items)->where('status', 'created')->count(),
                    'reenrolled' => collect($items)->where('status', 'reenrolled')->count(),
                    'skipped_existing' => 0, 're_enrollment_required' => 0, 'failed' => 0,
                ],
                'items' => $items,
            ];
            DB::table('core_course_enrollment_submissions')->where('id', $submission->id)->update([
                'status' => 'completed', 'committed_at' => $now,
                'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);

            return $result;
        }, 3);
    }

    private function withConfirmationContract(array $prepared, array $payload): array
    {
        $confirmations = collect($payload['reenrollment_confirmations'] ?? [])
            ->keyBy(fn (array $item): string => $item['student_id'].':'.$item['product_id']);
        $expected = collect($prepared['pairs'])->where('status', 'reenrollment_eligible')
            ->map(fn (array $pair): string => $pair['student_id'].':'.$pair['product_id'])->sort()->values();
        $provided = $confirmations->keys()->sort()->values();
        $prepared['confirmation_error'] = $provided->diff($expected)->isNotEmpty()
            ? __('lf.LF_bulk_enrollment_validation_confirmation') : null;
        if ($prepared['confirmation_error']) {
            $prepared['valid'] = false;
        }

        return $prepared;
    }
}
