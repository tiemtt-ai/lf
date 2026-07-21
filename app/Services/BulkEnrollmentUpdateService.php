<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkEnrollmentUpdateService
{
    private const EDITABLE_STATUSES = ['pending', 'active', 'suspended'];

    public function update(int $customerId, array $ids, array $changes): int
    {
        $ids = collect($ids)->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        return DB::transaction(function () use ($customerId, $ids, $changes): int {
            $enrollments = DB::table('core_course_enrollments')->where('customer_id', $customerId)
                ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($enrollments->count() !== count($ids)
                || $enrollments->contains(fn (object $row): bool => ! in_array($row->status, self::EDITABLE_STATUSES, true))) {
                throw ValidationException::withMessages(['enrollment_ids' => __('lf.LF_course_enrollment_bulk_invalid_selection')]);
            }

            foreach ($enrollments as $enrollment) {
                $update = [];
                foreach ($changes as $field => $change) {
                    if ($change['action'] === 'set') {
                        $update[$field] = $change['value'];
                    } elseif ($change['action'] === 'clear') {
                        $update[$field] = null;
                    }
                }
                $final = fn (string $field) => array_key_exists($field, $update) ? $update[$field] : $enrollment->{$field};
                $this->assertWindow($final('access_starts_at'), $final('access_ends_at'), 'access_ends_at');
                $this->assertWindow($final('review_starts_at'), $final('review_ends_at'), 'review_ends_at');
                $update['updated_at'] = now();
                DB::table('core_course_enrollments')->where('customer_id', $customerId)
                    ->where('id', $enrollment->id)->update($update);
            }

            return $enrollments->count();
        }, 3);
    }

    private function assertWindow(mixed $start, mixed $end, string $field): void
    {
        if ($start && $end && Carbon::parse($end)->lt(Carbon::parse($start))) {
            throw ValidationException::withMessages([$field => __('lf.LF_course_enrollment_bulk_invalid_window')]);
        }
    }
}
