<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class LearningMasteryProfileProjector
{
    public function __construct(private readonly LearningRuntimeAccess $access) {}

    public function project(int $calculationId): object
    {
        $customerId = $this->access->tenantId();

        return DB::transaction(function () use ($calculationId, $customerId): object {
            $calculation = DB::table('core_learning_mastery_calculations')
                ->where('id', $calculationId)
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first();

            if ($calculation === null) {
                throw new RecordsNotFoundException('Learning calculation not found in tenant.');
            }

            $identity = [
                'customer_id' => $customerId,
                'user_id' => $calculation->user_id,
                'node_definition_id' => $calculation->node_definition_id,
                'basis_framework_version_id' => $calculation->basis_framework_version_id,
            ];
            $profile = DB::table('core_learning_mastery_profiles')
                ->where($identity)
                ->lockForUpdate()
                ->first();

            if ($profile !== null && ! $this->succeeds($calculation, $profile, $customerId)) {
                return $profile;
            }

            // Application timezone, matching every other writer in LearnForge.
            // projected_at is a stored DATETIME(6), so writing it in UTC while
            // calculated_at arrives as wall-clock would put two conventions in
            // one row of an otherwise consistent database.
            $now = Carbon::now()->format('Y-m-d H:i:s.u');
            $projection = [
                'framework_id' => $calculation->framework_id,
                'current_calculation_id' => $calculation->id,
                'mastery_level_key' => $calculation->mastery_level_key,
                'mastery_score' => $calculation->mastery_score,
                'mastery_status' => $calculation->mastery_status_result,
                'calculated_at' => $calculation->calculated_at,
                'reassessment_due_at' => $calculation->reassessment_due_at,
                'projected_at' => $now,
                'updated_at' => $now,
            ];

            if ($profile === null) {
                DB::table('core_learning_mastery_profiles')->insert([
                    ...$identity,
                    ...$projection,
                    'created_at' => $now,
                ]);
            } else {
                DB::table('core_learning_mastery_profiles')
                    ->where('id', $profile->id)
                    ->where('customer_id', $customerId)
                    ->update($projection);
            }

            return DB::table('core_learning_mastery_profiles')->where($identity)->firstOrFail();
        }, 3);
    }

    private function succeeds(object $calculation, object $profile, int $customerId): bool
    {
        if ((int) $profile->current_calculation_id === (int) $calculation->id) {
            return false;
        }

        $current = DB::table('core_learning_mastery_calculations')
            ->where('id', $profile->current_calculation_id)
            ->where('customer_id', $customerId)
            ->first();

        if ($current === null) {
            throw new RecordsNotFoundException('Current Learning calculation is missing.');
        }

        return [$this->orderingTime($calculation->calculated_at), (int) $calculation->id]
            > [$this->orderingTime($current->calculated_at), (int) $current->id];
    }

    /**
     * Both operands are stored wall-clock in the application timezone, so they
     * are normalized in that zone rather than UTC. The explicit conversion still
     * matters: it keeps a timezone-bearing value, should a cast ever produce
     * one, from being compared against a naive one.
     */
    private function orderingTime(mixed $value): string
    {
        return CarbonImmutable::parse($value, config('app.timezone'))
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d H:i:s.u');
    }
}
