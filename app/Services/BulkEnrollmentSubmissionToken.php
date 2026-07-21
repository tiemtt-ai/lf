<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BulkEnrollmentSubmissionToken
{
    private const TTL_MINUTES = 30;

    public function issue(int $customerId, int $adminId, array $payload): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $now = now();

        DB::transaction(function () use ($customerId, $adminId, $payload, $rawToken, $now): void {
            DB::table('core_course_enrollment_submissions')
                ->where('customer_id', $customerId)
                ->where('admin_id', $adminId)
                ->where('status', 'prepared')
                ->update([
                    'status' => 'invalidated',
                    'invalidated_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table('core_course_enrollment_submissions')->insert([
                'customer_id' => $customerId,
                'admin_id' => $adminId,
                'token_hash' => hash('sha256', $rawToken),
                'payload_hash' => app(BulkEnrollmentPayload::class)->hash($payload),
                'student_ids' => json_encode($payload['student_ids'], JSON_THROW_ON_ERROR),
                'product_ids' => json_encode($payload['product_ids'], JSON_THROW_ON_ERROR),
                'reenrollment_confirmations' => json_encode($payload['reenrollment_confirmations'], JSON_THROW_ON_ERROR),
                'configuration' => json_encode($payload['configuration'], JSON_THROW_ON_ERROR),
                'pair_count' => count($payload['student_ids']) * count($payload['product_ids']),
                'status' => 'prepared',
                'expires_at' => $now->copy()->addMinutes(self::TTL_MINUTES),
                'committed_at' => null,
                'invalidated_at' => null,
                'result' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, 3);

        return $rawToken;
    }

    public function invalidate(int $customerId, int $adminId, ?string $rawToken = null): void
    {
        $query = DB::table('core_course_enrollment_submissions')
            ->where('customer_id', $customerId)
            ->where('admin_id', $adminId)
            ->where('status', 'prepared');

        if ($rawToken) {
            $query->where('token_hash', hash('sha256', $rawToken));
        }

        $query->update([
            'status' => 'invalidated',
            'invalidated_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
