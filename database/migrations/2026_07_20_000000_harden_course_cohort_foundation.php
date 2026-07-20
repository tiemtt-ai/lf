<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $stats = ['backfilled' => 0, 'unresolved' => 0, 'conflicting' => 0];

        DB::table('core_course_cohorts')->orderBy('id')->eachById(function (object $cohort) use (&$stats): void {
            if ($cohort->product_id !== null && $cohort->version_id !== null) {
                return;
            }

            $bindings = DB::table('core_course_cohort_students as memberships')
                ->join('core_course_enrollments as enrollments', function ($join) use ($cohort): void {
                    $join->on('enrollments.id', '=', 'memberships.enrollment_id')
                        ->where('enrollments.customer_id', '=', $cohort->customer_id);
                })
                ->where('memberships.customer_id', $cohort->customer_id)
                ->where('memberships.cohort_id', $cohort->id)
                ->distinct()->get(['enrollments.product_id', 'enrollments.version_id']);

            if ($bindings->count() === 1) {
                $binding = $bindings->first();
                if (($cohort->product_id === null || (int) $cohort->product_id === (int) $binding->product_id)
                    && ($cohort->version_id === null || (int) $cohort->version_id === (int) $binding->version_id)) {
                    DB::table('core_course_cohorts')->where('id', $cohort->id)->update([
                        'product_id' => $binding->product_id,
                        'version_id' => $binding->version_id,
                        'updated_at' => now(),
                    ]);
                    $stats['backfilled']++;

                    return;
                }
            }

            $bindings->count() > 1 ? $stats['conflicting']++ : $stats['unresolved']++;
        });

        Log::info('Course Cohort binding backfill completed.', $stats);

        $duplicates = DB::table('core_course_cohorts')->whereNotNull('code')
            ->select('customer_id', 'code')
            ->groupBy('customer_id', 'code')->havingRaw('COUNT(*) > 1')->get()->count();
        if ($duplicates > 0) {
            throw new RuntimeException("Duplicate tenant-scoped Cohort codes detected ({$duplicates}); unique constraint was not added.");
        }

        Schema::table('core_course_cohorts', function (Blueprint $table): void {
            $table->unique(['customer_id', 'code'], 'uk_ccco_customer_code');
        });
    }

    public function down(): void
    {
        Schema::table('core_course_cohorts', function (Blueprint $table): void {
            $table->dropUnique('uk_ccco_customer_code');
        });
    }
};
