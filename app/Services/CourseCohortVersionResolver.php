<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CourseCohortVersionResolver
{
    public function eligibleProducts(int $customerId): object
    {
        $lessonCounts = DB::table('core_course_template_version_lessons as lesson_counts')
            ->selectRaw('COUNT(*)')
            ->whereColumn('lesson_counts.customer_id', 'products.customer_id')
            ->whereColumn('lesson_counts.template_version_id', 'versions.id');
        $activityCounts = DB::table('core_course_template_version_activities as activity_counts')
            ->selectRaw('COUNT(*)')
            ->whereColumn('activity_counts.customer_id', 'products.customer_id')
            ->whereColumn('activity_counts.template_version_id', 'versions.id');

        return DB::table('core_course_products as products')
            ->join('core_course_product_items as items', function ($join) use ($customerId): void {
                $join->on('items.product_id', '=', 'products.id')
                    ->where('items.customer_id', '=', $customerId)
                    ->where('items.status', '=', 'active');
            })
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'items.version_id')
                    ->where('versions.customer_id', '=', $customerId)
                    ->where('versions.status', '=', 'published');
            })
            ->where('products.customer_id', $customerId)
            ->where('products.status', 'active')
            ->whereNotNull('items.version_id')
            ->where(function ($query): void {
                $query->whereNull('items.template_id')
                    ->orWhereColumn('items.template_id', 'versions.template_id');
            })
            ->select(
                'products.id', 'products.title', 'products.product_code',
                'versions.id as version_id', 'versions.version_code',
                'versions.version_number', 'versions.title_snapshot',
                'versions.status as version_status'
            )
            ->selectSub($lessonCounts, 'lesson_count')
            ->selectSub($activityCounts, 'activity_count')
            ->orderBy('products.title')->orderBy('products.id')
            ->get()
            ->groupBy('id')
            ->filter(fn ($items): bool => $items->count() === 1)
            ->map(fn ($items) => $items->first())
            ->values();
    }

    public function resolve(int $customerId, int $productId, bool $lock = false): ?object
    {
        $product = DB::table('core_course_products')
            ->where('customer_id', $customerId)
            ->where('id', $productId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first(['id', 'status', 'product_type']);

        if (! $product || $product->status !== 'active') {
            return null;
        }

        $items = DB::table('core_course_product_items as items')
            ->join('core_course_template_versions as versions', function ($join) use ($customerId): void {
                $join->on('versions.id', '=', 'items.version_id')
                    ->where('versions.customer_id', '=', $customerId)
                    ->where('versions.status', '=', 'published');
            })
            ->where('items.customer_id', $customerId)
            ->where('items.product_id', $productId)
            ->where('items.status', 'active')
            ->whereNotNull('items.version_id')
            ->where(function ($query): void {
                $query->whereNull('items.template_id')
                    ->orWhereColumn('items.template_id', 'versions.template_id');
            })
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->select(
                'items.id as product_item_id',
                'versions.id as version_id',
                'versions.version_code',
                'versions.version_number',
                'versions.title_snapshot',
                'versions.status as version_status'
            )
            ->limit(2)
            ->get();

        return $items->count() === 1 ? $items->first() : null;
    }
}
