<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductCourseVersionResolver
{
    public function resolveMany(int $customerId, array $productIds, bool $lock = false): array
    {
        $productIds = collect($productIds)->map(fn ($id): int => (int) $id)
            ->unique()->sort()->values();

        $itemsQuery = DB::table('core_course_product_items')
            ->where('customer_id', $customerId)
            ->whereIn('product_id', $productIds)
            ->where('status', 'active')
            ->orderBy('product_id')->orderBy('id');
        if ($lock) {
            $itemsQuery->lockForUpdate();
        }
        $items = $itemsQuery->get(['id', 'customer_id', 'product_id', 'template_id', 'version_id'])
            ->groupBy('product_id');

        $versionIds = $items->flatten()->pluck('version_id')->filter()
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $versionsQuery = DB::table('core_course_template_versions')
            ->where('customer_id', $customerId)
            ->whereIn('id', $versionIds)
            ->orderBy('id');
        if ($lock) {
            $versionsQuery->lockForUpdate();
        }
        $versions = $versionsQuery->get([
            'id', 'customer_id', 'template_id', 'version_number', 'version_code', 'status',
        ])->keyBy('id');

        return $productIds->mapWithKeys(function (int $productId) use ($items, $versions): array {
            $activeItems = $items->get($productId, collect());
            if ($activeItems->count() !== 1) {
                return [$productId => ['binding' => null, 'error' => 'active_item']];
            }

            $item = $activeItems->first();
            $version = $item->version_id ? $versions->get((int) $item->version_id) : null;
            if (! $version || $item->template_id === null
                || (int) $item->template_id !== (int) $version->template_id) {
                return [$productId => ['binding' => null, 'error' => 'binding']];
            }
            if ($version->status !== 'published') {
                return [$productId => ['binding' => null, 'error' => 'published_version']];
            }

            return [$productId => ['binding' => (object) [
                'item_id' => (int) $item->id,
                'product_id' => $productId,
                'template_id' => (int) $item->template_id,
                'version_id' => (int) $version->id,
                'version_number' => (int) $version->version_number,
                'version_code' => $version->version_code,
            ], 'error' => null]];
        })->all();
    }

    public function resolve(int $customerId, int $productId, bool $lock = false): object
    {
        $result = $this->resolveMany($customerId, [$productId], $lock)[$productId];
        if ($result['binding']) {
            return $result['binding'];
        }

        $message = match ($result['error']) {
            'active_item' => __('lf.LF_course_enrollment_validation_active_item'),
            default => __('lf.LF_course_enrollment_validation_published_version'),
        };
        throw ValidationException::withMessages(['product_id' => $message]);
    }
}
