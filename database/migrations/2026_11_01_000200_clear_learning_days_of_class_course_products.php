<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0022: learning and review days belong to self-paced courses only. Products whose bound
 * Version became scheduled or blended in the course type backfill keep NULL days, as the
 * Product contract requires. Enrollment snapshots are historical and stay untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $productIds = DB::table('core_course_product_items as items')
            ->join('core_course_template_versions as versions', 'versions.id', '=', 'items.version_id')
            ->where('items.status', 'active')
            ->whereColumn('versions.customer_id', 'items.customer_id')
            ->where('versions.course_type_snapshot', '!=', 'self_paced')
            ->pluck('items.product_id');

        DB::table('core_course_products')
            ->whereIn('id', $productIds)
            ->where(fn ($query) => $query->whereNotNull('access_duration_days')->orWhereNotNull('review_duration_days'))
            ->update(['access_duration_days' => null, 'review_duration_days' => null]);
    }

    public function down(): void
    {
        // The cleared values were inapplicable; they are not restored.
    }
};
