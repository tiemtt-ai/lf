<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class SequentialCodeGenerator
{
    public static function next(
        int $customerId,
        string $table,
        string $column,
        string $prefix
    ): string {
        $date = now()->format('Ymd');
        $pattern = $prefix.'-'.$date.'-';

        $highestSequence = DB::table($table)
            ->where('customer_id', $customerId)
            ->where($column, 'like', $pattern.'%')
            ->pluck($column)
            ->map(function ($code) use ($pattern): int {
                $code = (string) $code;

                if (! str_starts_with($code, $pattern)) {
                    return 0;
                }

                $sequence = substr($code, strlen($pattern));

                return preg_match('/^\d+$/', $sequence) === 1
                    ? (int) $sequence
                    : 0;
            })
            ->max() ?? 0;

        return sprintf('%s%03d', $pattern, $highestSequence + 1);
    }
}
